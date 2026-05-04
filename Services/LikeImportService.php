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
            try {
                $data = self::normalizeImportRow($row, $line, $importedAt);
                $logExists = self::importLogExists($data);
                $active = self::activeLikeExists($data);

                // CSV import repairs the current like state, but never sends notification mail.
                // Mail notification is intentionally limited to front-end like actions.
                if ($data['action'] === 'like' && !$active) {
                    $created = false;
                    if (!$dryRun) {
                        $created = self::insertImportLike($data, $line);
                    }
                    if ($dryRun || $created) {
                        $summary['imported_likes']++;
                    } else {
                        $summary['skipped']++;
                    }
                } elseif ($data['action'] === 'unlike' && $active) {
                    if (!$dryRun) {
                        self::deleteImportLike($data);
                    }
                    $summary['removed_likes']++;
                }

                if ($logExists) {
                    $summary['skipped']++;
                    continue;
                }
                if (!$dryRun) {
                    self::insertImportLog($data);
                }
                $summary['imported_logs']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                LikeErrorLogger::log('import', $e->getMessage(), [
                    'line' => $line,
                    'entry_id' => isset($row['entry_id']) ? (int)$row['entry_id'] : 0,
                    'action' => (string)($row['action'] ?? ''),
                ]);
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

    private static function normalizeImportRow(array $row, int $line = 0, string $importedAt = ''): array
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
        $blogId = (int)self::normalizeCell($row['blog_id'] ?? '');
        if ($blogId <= 0 && class_exists('\ACMS_RAM') && method_exists('\ACMS_RAM', 'entryBlog')) {
            $blogId = (int)\ACMS_RAM::entryBlog($entryId);
        }
        if ($blogId <= 0 && defined('BID')) {
            $blogId = (int)BID;
        }
        if ($blogId <= 0) {
            throw new \RuntimeException('blog_id を補完できません。');
        }
        $objectType = self::normalizeCell($row['object_type'] ?? 'entry');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $objectType)) {
            throw new \RuntimeException('object_type が不正です。');
        }
        $objectId = self::normalizeCell($row['object_id'] ?? '');
        if ($objectId === '') {
            $objectId = (string)$entryId;
        }
        $userId = self::normalizeCell($row['user_id'] ?? '');

        return [
            'blog_id' => $blogId,
            'entry_id' => $entryId,
            'object_type' => $objectType,
            'object_id' => mb_substr($objectId, 0, 64),
            'action' => $action,
            'user_id' => $userId !== '' ? (int)$userId : 0,
            'visitor_hash' => hash('sha256', $visitorKey),
            'referer' => self::normalizeCell($row['referer'] ?? ''),
            'created_at' => $createdAt,
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

    private static function importLogExists(array $data): bool
    {
        return (bool)\DB::query([
            'sql' => 'SELECT log_id FROM `' . LikeRepository::table('df_like_log') . '`
                WHERE log_object_type = :object_type
                AND log_object_id = :object_id
                AND log_visitor_hash = :visitor_hash
                AND log_action = :action
                AND log_created_at = :created_at
                LIMIT 1',
            'params' => [
                'object_type' => $data['object_type'],
                'object_id' => $data['object_id'],
                'visitor_hash' => $data['visitor_hash'],
                'action' => $data['action'],
                'created_at' => $data['created_at'],
            ],
        ], 'one');
    }

    private static function activeLikeExists(array $data): bool
    {
        return (bool)\DB::query([
            'sql' => 'SELECT like_id FROM `' . LikeRepository::table('df_like') . '`
                WHERE like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash
                LIMIT 1',
            'params' => [
                'object_type' => $data['object_type'],
                'object_id' => $data['object_id'],
                'visitor_hash' => $data['visitor_hash'],
            ],
        ], 'one');
    }

    private static function insertImportLog(array $data): void
    {
        \DB::query([
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
    }

    private static function insertImportLike(array $data, int $line): bool
    {
        try {
            return LikeRepository::insertCurrentLike(array_merge($data, [
                'ip_hash' => '',
                'user_agent_hash' => '',
            ]));
        } catch (\Throwable $e) {
            LikeErrorLogger::log('import', $e->getMessage(), [
                'line' => $line,
                'blog_id' => (int)$data['blog_id'],
                'entry_id' => (int)$data['entry_id'],
                'object_type' => (string)$data['object_type'],
                'object_id' => (string)$data['object_id'],
                'visitor_hash' => substr((string)$data['visitor_hash'], 0, 10),
            ]);
            throw $e;
        }
    }

    private static function deleteImportLike(array $data): void
    {
        \DB::query([
            'sql' => 'DELETE FROM `' . LikeRepository::table('df_like') . '`
                WHERE like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash',
            'params' => [
                'object_type' => $data['object_type'],
                'object_id' => $data['object_id'],
                'visitor_hash' => $data['visitor_hash'],
            ],
        ], 'exec');
    }

}
