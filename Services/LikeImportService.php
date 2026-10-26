<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeImportService
{
    public static function importRows(array $rows, bool $dryRun): array
    {
        LikeRepository::ensureTables();
        $summary = [
            'status' => 'success',
            'dry_run' => $dryRun,
            'processed' => 0,
            'imported_logs' => 0,
            'imported_likes' => 0,
            'removed_likes' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        $importedAt = date('Y-m-d H:i:s');

        foreach ($rows as $line => $row) {
            $summary['processed']++;
            $db = null;
            $transactionStarted = false;
            $counts = ['imported_logs' => 0, 'imported_likes' => 0, 'removed_likes' => 0, 'skipped' => 0];
            try {
                if (!$dryRun) {
                    $db = \DB::singleton(dsn());
                    $db->beginTransaction();
                    $transactionStarted = true;
                }
                // Lock the real entry until commit: concurrent imports for this entry
                // serialize, and its blog cannot change between authorization and write.
                $data = self::normalizeImportRow($row, $line, $importedAt, !$dryRun);
                self::assertTargetOwnership($data);
                $logExists = self::importLogExists($data);
                $active = self::activeLikeExists($data);

                // CSV import repairs the current like state, but never sends notification mail.
                // Mail notification is intentionally limited to front-end like actions.
                if ($data['action'] === 'like' && !$active) {
                    $created = false;
                    if (!$dryRun) {
                        $created = self::insertImportLike($data);
                    }
                    if ($dryRun || $created) {
                        $counts['imported_likes']++;
                    } else {
                        $counts['skipped']++;
                    }
                } elseif ($data['action'] === 'unlike' && $active) {
                    if (!$dryRun) {
                        self::deleteImportLike($data);
                    }
                    $counts['removed_likes']++;
                }

                if ($logExists) {
                    $counts['skipped']++;
                } else {
                    if (!$dryRun) {
                        self::insertImportLog($data);
                    }
                    $counts['imported_logs']++;
                }
                if ($transactionStarted) {
                    $db->commit();
                    $transactionStarted = false;
                }
                foreach ($counts as $key => $count) {
                    $summary[$key] += $count;
                }
            } catch (\Throwable $e) {
                if ($transactionStarted) {
                    $db->rollBack();
                }
                $summary['failed']++;
                if (!$dryRun) {
                    LikeErrorLogger::log('import', $e->getMessage(), [
                        'line' => $line,
                        'entry_id' => isset($row['entry_id']) ? (int)$row['entry_id'] : 0,
                        'action' => (string)($row['action'] ?? ''),
                    ]);
                }
                if (count($summary['errors']) < 100) {
                    $summary['errors'][] = [
                        'line' => $line,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return $summary;
    }

    private static function normalizeImportRow(array $row, int $line = 0, string $importedAt = '', bool $lockEntry = false): array
    {
        $entryId = (int)self::normalizeCell($row['entry_id'] ?? '');
        if ($entryId <= 0) {
            throw new \RuntimeException('entry_id が不正です。');
        }
        $visitorKey = self::normalizeCell($row['visitor_key'] ?? '');
        if ($visitorKey === '') {
            $visitorKey = 'import:' . $entryId . ':' . $line;
        }
        $action = strtolower(self::normalizeCell($row['action'] ?? ''));
        if ($action === '') {
            $action = 'like';
        }
        if (!in_array($action, ['like', 'unlike'], true)) {
            throw new \RuntimeException('action は like または unlike を指定してください。');
        }
        $createdAtRaw = self::normalizeCell($row['created_at'] ?? '');
        $createdAt = self::normalizeDateTime($createdAtRaw);
        if ($createdAt === '' && $createdAtRaw !== '') {
            throw new \RuntimeException('created_at が不正です。');
        }
        if ($createdAt === '') {
            $createdAt = $importedAt !== '' ? $importedAt : date('Y-m-d H:i:s');
        }
        $entry = \DB::query([
            'sql' => 'SELECT entry_blog_id FROM `' . LikeRepository::table('entry') . '`
                WHERE entry_id = :entry_id' . ($lockEntry ? ' FOR UPDATE' : ''),
            'params' => ['entry_id' => $entryId],
        ], 'row');
        if (!is_array($entry) || (int)($entry['entry_blog_id'] ?? 0) <= 0) {
            throw new \RuntimeException('entry_id の記事が存在しないか、取得できません。');
        }
        $blogId = (int)$entry['entry_blog_id'];
        $specifiedBlog = self::normalizeCell($row['blog_id'] ?? '');
        if ($specifiedBlog !== '' && (!ctype_digit($specifiedBlog) || (int)$specifiedBlog !== $blogId)) {
            throw new \RuntimeException('blog_id が記事の所属ブログと一致しません。');
        }
        if (!self::canImportBlog($blogId)) {
            throw new \RuntimeException('対象ブログのいいね履歴をインポートする権限がありません。');
        }
        $objectType = self::normalizeCell($row['object_type'] ?? 'entry');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $objectType)) {
            throw new \RuntimeException('object_type が不正です。');
        }
        $objectId = self::normalizeCell($row['object_id'] ?? '');
        if ($objectId === '') {
            $objectId = (string)$entryId;
        }
        // Entry targets use the same canonical ID as the front-end endpoint. Custom
        // object types remain supported but cannot impersonate an existing target.
        if (strcasecmp($objectType, 'entry') === 0) {
            $objectType = 'entry';
            if ($objectId !== (string)$entryId) {
                throw new \RuntimeException('entry の object_id は entry_id と一致させてください。');
            }
        }
        if (mb_strlen($objectId) > 64) {
            throw new \RuntimeException('object_id は64文字以内で指定してください。');
        }
        $userId = self::normalizeCell($row['user_id'] ?? '');

        return [
            'blog_id' => $blogId,
            'entry_id' => $entryId,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action' => $action,
            'user_id' => $userId !== '' ? (int)$userId : 0,
            'visitor_hash' => hash('sha256', $visitorKey),
            'referer' => self::normalizeCell($row['referer'] ?? ''),
            'created_at' => $createdAt,
            'created_at_supplied' => $createdAtRaw !== '',
        ];
    }

    private static function normalizeDateTime(string $value): string
    {
        $value = self::normalizeCell(str_replace('T', ' ', $value));
        $value = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', $value);
        $value = self::normalizeCell($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        if (!preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2}) (\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $value, $matches)) {
            return '';
        }
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        $hour = (int)$matches[4];
        $minute = (int)$matches[5];
        $second = isset($matches[6]) && $matches[6] !== '' ? (int)$matches[6] : 0;
        if (!checkdate($month, $day, $year)) {
            return '';
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            return '';
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    private static function normalizeCell($value): string
    {
        $value = (string)$value;
        $value = str_replace(["\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"], '', $value);
        $value = str_replace(["\xC2\xA0", "\xE3\x80\x80"], ' ', $value);
        $value = preg_replace('/^[\s\x{00A0}\x{FEFF}\x{200B}-\x{200D}\x{3000}]+|[\s\x{00A0}\x{FEFF}\x{200B}-\x{200D}\x{3000}]+$/u', '', $value);
        return $value === null ? '' : $value;
    }

    private static function canImportBlog(int $blogId): bool
    {
        if (function_exists('sessionWithAdministration') && \sessionWithAdministration($blogId)) {
            return true;
        }
        return function_exists('roleAvailableUser') && \roleAvailableUser()
            && function_exists('roleAuthorization') && \roleAuthorization('config_edit', $blogId);
    }

    private static function assertTargetOwnership(array $data): void
    {
        // Target keys are globally unique in df_like, including custom object types.
        // A forged entry_id must not grant access to another entry's existing target.
        foreach (['df_like' => 'like', 'df_like_log' => 'log'] as $table => $prefix) {
            if (self::countRows([
                'sql' => 'SELECT COUNT(*) FROM `' . LikeRepository::table($table) . '`
                    WHERE ' . $prefix . '_object_type = :object_type
                    AND ' . $prefix . '_object_id = :object_id
                    AND (' . $prefix . '_blog_id <> :blog_id OR ' . $prefix . '_entry_id <> :entry_id)',
                'params' => [
                    'object_type' => $data['object_type'],
                    'object_id' => $data['object_id'],
                    'blog_id' => $data['blog_id'],
                    'entry_id' => $data['entry_id'],
                ],
            ]) > 0) {
                throw new \RuntimeException('object_type / object_id が別の記事・ブログの履歴と重複しています。');
            }
        }
    }

    private static function countRows(array $query): int
    {
        $count = \DB::query($query, 'one');
        if ($count === false || $count === null) {
            throw new \RuntimeException('既存のいいね・履歴を確認できませんでした。');
        }
        return (int)$count;
    }

    private static function importLogExists(array $data): bool
    {
        $params = [
            'blog_id' => $data['blog_id'],
            'entry_id' => $data['entry_id'],
            'object_type' => $data['object_type'],
            'object_id' => $data['object_id'],
            'visitor_hash' => $data['visitor_hash'],
            'action' => $data['action'],
        ];
        $dateCondition = '';
        if ($data['created_at_supplied']) {
            $dateCondition = ' AND log_created_at = :created_at';
            $params['created_at'] = $data['created_at'];
        }
        // Without a supplied date, target + visitor + action identifies the event.
        // Using the import clock here would create a new history row on every retry.
        return self::countRows([
            'sql' => 'SELECT COUNT(*) FROM `' . LikeRepository::table('df_like_log') . '`
                WHERE log_blog_id = :blog_id AND log_entry_id = :entry_id
                AND log_object_type = :object_type
                AND log_object_id = :object_id
                AND log_visitor_hash = :visitor_hash
                AND log_action = :action' . $dateCondition,
            'params' => $params,
        ]) > 0;
    }

    private static function activeLikeExists(array $data): bool
    {
        return self::countRows([
            'sql' => 'SELECT COUNT(*) FROM `' . LikeRepository::table('df_like') . '`
                WHERE like_blog_id = :blog_id AND like_entry_id = :entry_id
                AND like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash',
            'params' => [
                'blog_id' => $data['blog_id'],
                'entry_id' => $data['entry_id'],
                'object_type' => $data['object_type'],
                'object_id' => $data['object_id'],
                'visitor_hash' => $data['visitor_hash'],
            ],
        ]) > 0;
    }

    private static function insertImportLog(array $data): void
    {
        unset($data['created_at_supplied']);
        $result = \DB::query([
            'sql' => 'INSERT INTO `' . LikeRepository::table('df_like_log') . '` (
                log_blog_id, log_entry_id, log_object_type, log_object_id, log_action, log_user_id,
                log_visitor_hash, log_ip_hash, log_user_agent_hash, log_referer, log_created_at
            ) VALUES (
                :blog_id, :entry_id, :object_type, :object_id, :action, :user_id,
                :visitor_hash, :ip_hash, :user_agent_hash, :referer, :created_at
            )',
            'params' => array_merge($data, [
                'ip_hash' => '',
                'user_agent_hash' => '',
            ]),
        ], 'exec');
        if ($result === false) {
            throw new \RuntimeException('いいね履歴を保存できませんでした。');
        }
    }

    private static function insertImportLike(array $data): bool
    {
        $created = LikeRepository::insertCurrentLike(array_merge($data, [
            'ip_hash' => '',
            'user_agent_hash' => '',
        ]));
        // The repository returns false when another writer already inserted it.
        // Recheck the authorized scope before treating that race as a safe skip.
        if (!$created && !self::activeLikeExists($data)) {
            throw new \RuntimeException('現在いいねを作成できませんでした。');
        }
        return $created;
    }

    private static function deleteImportLike(array $data): void
    {
        $result = \DB::query([
            'sql' => 'DELETE FROM `' . LikeRepository::table('df_like') . '`
                WHERE like_blog_id = :blog_id AND like_entry_id = :entry_id
                AND like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash',
            'params' => [
                'blog_id' => $data['blog_id'],
                'entry_id' => $data['entry_id'],
                'object_type' => $data['object_type'],
                'object_id' => $data['object_id'],
                'visitor_hash' => $data['visitor_hash'],
            ],
        ], 'exec');
        if ($result === false) {
            throw new \RuntimeException('現在いいねを削除できませんでした。');
        }
    }
}
