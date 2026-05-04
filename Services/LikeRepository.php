<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeRepository
{
    public static function count(string $objectType, string $objectId): int
    {
        self::ensureTables();
        $sql = 'SELECT COUNT(*) FROM `' . self::table('df_like') . '` WHERE like_object_type = :type AND like_object_id = :id';
        return (int)\DB::query(['sql' => $sql, 'params' => ['type' => $objectType, 'id' => $objectId]], 'one');
    }

    public static function liked(string $objectType, string $objectId, string $visitorHash): bool
    {
        if ($visitorHash === '') {
            return false;
        }
        self::ensureTables();
        $sql = 'SELECT like_id FROM `' . self::table('df_like') . '` WHERE like_object_type = :type AND like_object_id = :id AND like_visitor_hash = :visitor LIMIT 1';
        return (bool)\DB::query([
            'sql' => $sql,
            'params' => ['type' => $objectType, 'id' => $objectId, 'visitor' => $visitorHash],
        ], 'one');
    }

    public static function toggle(array $target, string $visitorHash, string $ipHash, string $userAgentHash): array
    {
        self::ensureTables();
        $liked = self::liked($target['object_type'], $target['object_id'], $visitorHash);
        if (!$liked && self::isRateLimited($ipHash, $userAgentHash)) {
            throw new \RuntimeException('短時間にいいね操作が集中しています。少し時間をおいてからお試しください。');
        }
        $now = date('Y-m-d H:i:s');
        $action = $liked ? 'unlike' : 'like';
        $changed = false;
        $params = [
            'blog_id' => $target['blog_id'],
            'entry_id' => $target['entry_id'],
            'object_type' => $target['object_type'],
            'object_id' => $target['object_id'],
            'user_id' => $target['user_id'],
            'visitor_hash' => $visitorHash,
            'ip_hash' => $ipHash,
            'user_agent_hash' => $userAgentHash,
            'referer' => isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '',
            'now' => $now,
        ];

        if ($liked) {
            \DB::query([
                'sql' => 'DELETE FROM `' . self::table('df_like') . '` WHERE like_object_type = :object_type AND like_object_id = :object_id AND like_visitor_hash = :visitor_hash',
                'params' => [
                    'object_type' => $params['object_type'],
                    'object_id' => $params['object_id'],
                    'visitor_hash' => $params['visitor_hash'],
                ],
            ], 'exec');
            $changed = self::rowCount() > 0;
        } else {
            \DB::query([
                'sql' => 'INSERT IGNORE INTO `' . self::table('df_like') . '` (
                    like_blog_id, like_entry_id, like_object_type, like_object_id, like_user_id,
                    like_visitor_hash, like_ip_hash, like_user_agent_hash, like_created_at, like_updated_at
                ) VALUES (
                    :blog_id, :entry_id, :object_type, :object_id, :user_id,
                    :visitor_hash, :ip_hash, :user_agent_hash, :now, :now
                )',
                'params' => $params,
            ], 'exec');
            $changed = self::rowCount() > 0;
        }

        if ($changed) {
            \DB::query([
                'sql' => 'INSERT INTO `' . self::table('df_like_log') . '` (
                    log_blog_id, log_entry_id, log_object_type, log_object_id, log_action, log_user_id,
                    log_visitor_hash, log_ip_hash, log_user_agent_hash, log_referer, log_created_at
                ) VALUES (
                    :blog_id, :entry_id, :object_type, :object_id, :action, :user_id,
                    :visitor_hash, :ip_hash, :user_agent_hash, :referer, :now
                )',
                'params' => array_merge($params, ['action' => $action]),
            ], 'exec');
        } else {
            $action = 'none';
        }

        return [
            'liked' => $changed ? !$liked : self::liked($target['object_type'], $target['object_id'], $visitorHash),
            'action' => $action,
            'count' => self::count($target['object_type'], $target['object_id']),
        ];
    }

    public static function adminData(int $limit = 50, ?array $filters = null, int $historyPage = 1, int $historyPerPage = 50): array
    {
        return LikeAnalyticsService::adminData($limit, $filters, $historyPage, $historyPerPage);
    }

    public static function filtersFromInput(string $startDate, string $endDate, string $blogScope = '', ?int $blogId = null, string $keyword = ''): array
    {
        $startDate = self::normalizeDate($startDate);
        $endDate = self::normalizeDate($endDate);
        if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $blogScope = in_array($blogScope, ['current_with_descendants', 'current'], true) ? $blogScope : 'current_with_descendants';
        $blogId = $blogId ?: (defined('BID') ? (int)BID : 0);
        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'keyword' => self::normalizeKeyword($keyword),
            'blog_scope' => $blogScope,
            'blog_id' => $blogId,
            'blog_ids' => self::blogIdsForScope($blogScope, $blogId),
        ];
    }

    private static function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($keyword, 0, 100);
        }
        return substr($keyword, 0, 100);
    }

    public static function analyticsScope(?int $blogId = null): string
    {
        $blogId = $blogId ?: (defined('BID') ? (int)BID : 0);
        $value = self::storedConfigValue('df_like_analytics_scope', $blogId);
        if ($value === null || trim($value) === '') {
            $value = function_exists('config') ? (string)config('df_like_analytics_scope') : '';
        }
        return in_array($value, ['current_with_descendants', 'current'], true) ? $value : 'current_with_descendants';
    }

    public static function csvRows(string $type, array $filters): array
    {
        return LikeAnalyticsService::csvRows($type, $filters);
    }

    public static function deleteLogs(array $filters): int
    {
        return LikeAnalyticsService::deleteLogs($filters);
    }

    public static function deleteLikeByLogId(int $logId, ?array $filters = null): array
    {
        self::ensureTables();
        if ($logId <= 0) {
            throw new \RuntimeException('削除するいいね履歴が指定されていません。');
        }
        [$blogWhere, $blogParams] = self::blogFilterWhere($filters ?: [], 'log', 'log_blog_id');

        $log = \DB::query([
            'sql' => 'SELECT log_id, log_object_type, log_object_id, log_action, log_visitor_hash
                FROM `' . self::table('df_like_log') . '` AS log
                WHERE log_id = :log_id' . ($blogWhere ? ' AND ' . $blogWhere : '') . '
                LIMIT 1',
            'params' => array_merge(['log_id' => $logId], $blogParams),
        ], 'row') ?: [];
        if (!$log) {
            throw new \RuntimeException('削除するいいね履歴が見つかりません。');
        }
        if ((string)$log['log_action'] !== 'like') {
            throw new \RuntimeException('解除履歴からはいいねを削除できません。');
        }

        $params = [
            'object_type' => (string)$log['log_object_type'],
            'object_id' => (string)$log['log_object_id'],
            'visitor_hash' => (string)$log['log_visitor_hash'],
        ];
        $deletedLike = (int)\DB::query([
            'sql' => 'SELECT COUNT(*) FROM `' . self::table('df_like') . '`
                WHERE like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash',
            'params' => $params,
        ], 'one');
        \DB::query([
            'sql' => 'DELETE FROM `' . self::table('df_like') . '`
                WHERE like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash',
            'params' => $params,
        ], 'exec');

        \DB::query([
            'sql' => 'DELETE FROM `' . self::table('df_like_log') . '` WHERE log_id = :log_id',
            'params' => ['log_id' => $logId],
        ], 'exec');

        return [
            'deleted_like' => $deletedLike,
            'deleted_log' => 1,
            'count' => self::count($params['object_type'], $params['object_id']),
        ];
    }

    public static function rebuildCurrentLikesFromLogs(array $filters): array
    {
        self::ensureTables();
        self::repairTables();
        [$blogWhere, $blogParams] = self::blogFilterWhere($filters, 'log', 'log_blog_id');
        $where = $blogWhere ? 'WHERE ' . $blogWhere : '';
        $rows = \DB::query([
            'sql' => 'SELECT log_blog_id, log_entry_id, log_object_type, log_object_id, log_action,
                    log_user_id, log_visitor_hash, log_ip_hash, log_user_agent_hash, log_created_at
                FROM `' . self::table('df_like_log') . '` AS log
                ' . $where . '
                ORDER BY log_object_type ASC, log_object_id ASC, log_visitor_hash ASC,
                    log_created_at DESC, log_id DESC',
            'params' => $blogParams,
        ], 'all') ?: [];

        $seen = [];
        $summary = [
            'processed' => 0,
            'created' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $row) {
            $key = (string)$row['log_object_type'] . "\0" . (string)$row['log_object_id'] . "\0" . (string)$row['log_visitor_hash'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $summary['processed']++;

            try {
                $params = [
                    'blog_id' => (int)$row['log_blog_id'],
                    'entry_id' => (int)$row['log_entry_id'],
                    'object_type' => (string)$row['log_object_type'],
                    'object_id' => (string)$row['log_object_id'],
                    'user_id' => isset($row['log_user_id']) && $row['log_user_id'] !== null && $row['log_user_id'] !== '' ? (int)$row['log_user_id'] : 0,
                    'visitor_hash' => (string)$row['log_visitor_hash'],
                    'ip_hash' => (string)($row['log_ip_hash'] ?? ''),
                    'user_agent_hash' => (string)($row['log_user_agent_hash'] ?? ''),
                    'created_at' => (string)$row['log_created_at'],
                ];
                $active = self::activeLikeExists($params['object_type'], $params['object_id'], $params['visitor_hash']);

                if ((string)$row['log_action'] === 'like') {
                    if ($active) {
                        $summary['skipped']++;
                        continue;
                    }
                    if (self::insertCurrentLike($params)) {
                        $summary['created']++;
                    } else {
                        $summary['skipped']++;
                    }
                    continue;
                }

                if ((string)$row['log_action'] === 'unlike' && $active) {
                    \DB::query([
                        'sql' => 'DELETE FROM `' . self::table('df_like') . '`
                            WHERE like_object_type = :object_type
                            AND like_object_id = :object_id
                            AND like_visitor_hash = :visitor_hash',
                        'params' => [
                            'object_type' => $params['object_type'],
                            'object_id' => $params['object_id'],
                            'visitor_hash' => $params['visitor_hash'],
                        ],
                    ], 'exec');
                    self::rowCount() > 0 ? $summary['deleted']++ : $summary['skipped']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                LikeErrorLogger::log('rebuild', $e->getMessage(), [
                    'blog_id' => (int)($row['log_blog_id'] ?? 0),
                    'entry_id' => (int)($row['log_entry_id'] ?? 0),
                    'object_type' => (string)($row['log_object_type'] ?? ''),
                    'object_id' => (string)($row['log_object_id'] ?? ''),
                ]);
                if (count($summary['errors']) < 100) {
                    $summary['errors'][] = [
                        'entry_id' => (int)($row['log_entry_id'] ?? 0),
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return $summary;
    }

    public static function importRows(array $rows, bool $dryRun): array
    {
        return LikeImportService::importRows($rows, $dryRun);
    }

    public static function entryCounts(array $entryIds): array
    {
        self::ensureTables();
        $ids = [];
        foreach ($entryIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = 'eid' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $rows = \DB::query([
            'sql' => 'SELECT like_entry_id, COUNT(*) AS count
                FROM `' . self::table('df_like') . '`
                WHERE like_entry_id IN (' . implode(',', $placeholders) . ')
                GROUP BY like_entry_id',
            'params' => $params,
        ], 'all') ?: [];
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = 0;
        }
        foreach ($rows as $row) {
            $counts[(int)$row['like_entry_id']] = (int)$row['count'];
        }
        return $counts;
    }

    public static function isRateLimited(string $ipHash, string $userAgentHash): bool
    {
        if ($ipHash === '' || $userAgentHash === '') {
            return false;
        }
        $count = (int)\DB::query([
            'sql' => 'SELECT COUNT(*) FROM `' . self::table('df_like_log') . '`
                WHERE log_ip_hash = :ip_hash
                AND log_user_agent_hash = :user_agent_hash
                AND log_action = \'like\'
                AND log_created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
            'params' => [
                'ip_hash' => $ipHash,
                'user_agent_hash' => $userAgentHash,
            ],
        ], 'one');
        return $count >= 10;
    }

    public static function ensureTables(): void
    {
        $likeTable = self::table('df_like');
        $sql = 'SHOW TABLES LIKE :table';
        if (\DB::query(['sql' => $sql, 'params' => ['table' => $likeTable]], 'one')) {
            self::repairTables();
            LikeErrorLogger::ensureTable();
            return;
        }
        $provider = new \Acms\Plugins\DF_Like\ServiceProvider();
        $provider->install();
    }

    public static function visitorHashFromCookie(): string
    {
        $id = isset($_COOKIE['df_like_visitor']) ? (string)$_COOKIE['df_like_visitor'] : '';
        return $id !== '' ? hash('sha256', $id) : '';
    }

    public static function issueVisitorId(): string
    {
        $id = isset($_COOKIE['df_like_visitor']) ? (string)$_COOKIE['df_like_visitor'] : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            @setcookie('df_like_visitor', $id, time() + 60 * 60 * 24 * 365 * 2, '/', '', false, true);
            $_COOKIE['df_like_visitor'] = $id;
        }
        return $id;
    }

    public static function table(string $name): string
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : (dsn()['prefix'] ?? '');
        return $prefix . $name;
    }

    private static function rowCount(): int
    {
        try {
            return (int)\DB::affected_rows();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function repairTables(): void
    {
        LikeSchemaService::repair();
    }

    private static function activeLikeExists(string $objectType, string $objectId, string $visitorHash): bool
    {
        return (bool)\DB::query([
            'sql' => 'SELECT like_id FROM `' . self::table('df_like') . '`
                WHERE like_object_type = :object_type
                AND like_object_id = :object_id
                AND like_visitor_hash = :visitor_hash
                LIMIT 1',
            'params' => [
                'object_type' => $objectType,
                'object_id' => $objectId,
                'visitor_hash' => $visitorHash,
            ],
        ], 'one');
    }

    public static function insertCurrentLike(array $params): bool
    {
        $objectType = (string)$params['object_type'];
        $objectId = (string)$params['object_id'];
        $visitorHash = (string)$params['visitor_hash'];
        if (self::activeLikeExists($objectType, $objectId, $visitorHash)) {
            return false;
        }

        $insertParams = [
            'blog_id' => (int)($params['blog_id'] ?? 0),
            'entry_id' => (int)($params['entry_id'] ?? 0),
            'object_type' => $objectType,
            'object_id' => $objectId,
            'user_id' => isset($params['user_id']) && $params['user_id'] !== null && $params['user_id'] !== '' ? (int)$params['user_id'] : 0,
            'visitor_hash' => $visitorHash,
            'ip_hash' => (string)($params['ip_hash'] ?? ''),
            'user_agent_hash' => (string)($params['user_agent_hash'] ?? ''),
            'created_at' => (string)($params['created_at'] ?? date('Y-m-d H:i:s')),
        ];

        $result = \DB::query([
            'sql' => 'INSERT INTO `' . self::table('df_like') . '` (
                like_blog_id, like_entry_id, like_object_type, like_object_id, like_user_id,
                like_visitor_hash, like_ip_hash, like_user_agent_hash, like_created_at, like_updated_at
            ) VALUES (
                :blog_id, :entry_id, :object_type, :object_id, :user_id,
                :visitor_hash, :ip_hash, :user_agent_hash, :created_at, :created_at
            )',
            'params' => $insertParams,
        ], 'exec');
        $insertError = self::dbErrorMessage('');
        $created = self::rowCount() > 0;
        if (self::activeLikeExists($objectType, $objectId, $visitorHash)) {
            return $created;
        }

        throw new \RuntimeException(self::currentLikeInsertErrorMessage($result, $insertParams, $insertError));
    }

    private static function currentLikeInsertErrorMessage($result, array $params, string $insertError): string
    {
        $message = '現在いいねを作成できませんでした。';
        $dbMessage = $insertError;
        if ($dbMessage !== '') {
            $message .= ' DB: ' . $dbMessage;
        } elseif ($result === false) {
            $message .= ' DBクエリが失敗しました。';
        }
        $message .= ' entry_id=' . (int)($params['entry_id'] ?? 0)
            . ' object=' . (string)($params['object_type'] ?? '') . ':' . (string)($params['object_id'] ?? '')
            . ' visitor=' . substr((string)($params['visitor_hash'] ?? ''), 0, 10);
        return $message;
    }

    private static function dbErrorMessage(string $prefix): string
    {
        try {
            $info = \DB::errorInfo();
            $code = (string)\DB::errorCode();
            $parts = [];
            if ($prefix !== '') {
                $parts[] = $prefix;
            }
            if ($code !== '' && $code !== '00000') {
                $parts[] = 'code=' . $code;
            }
            if (is_array($info)) {
                foreach ($info as $part) {
                    $part = trim((string)$part);
                    if ($part !== '' && $part !== '00000') {
                        $parts[] = $part;
                    }
                }
            }
            return implode(' ', array_unique($parts));
        } catch (\Throwable $e) {
            return $prefix;
        }
    }

    private static function blogFilterWhere(array $filters, string $alias, string $column): array
    {
        $blogIds = array_values(array_filter(array_map('intval', $filters['blog_ids'] ?? [])));
        if (!$blogIds) {
            return ['', []];
        }
        $placeholders = [];
        $params = [];
        foreach ($blogIds as $index => $id) {
            $key = 'blog_scope_' . $column . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        return [$alias . '.' . $column . ' IN (' . implode(',', $placeholders) . ')', $params];
    }

    private static function blogIdsForScope(string $scope, int $blogId): array
    {
        if ($blogId <= 0) {
            return [];
        }
        if ($scope === 'current') {
            return [$blogId];
        }
        $left = class_exists('\ACMS_RAM') && method_exists('\ACMS_RAM', 'blogLeft') ? (int)\ACMS_RAM::blogLeft($blogId) : 0;
        $right = class_exists('\ACMS_RAM') && method_exists('\ACMS_RAM', 'blogRight') ? (int)\ACMS_RAM::blogRight($blogId) : 0;
        if ($left <= 0 || $right <= 0) {
            return [$blogId];
        }
        $rows = \DB::query([
            'sql' => 'SELECT blog_id FROM `' . self::table('blog') . '`
                WHERE blog_left >= :left
                AND blog_right <= :right
                ORDER BY blog_left ASC',
            'params' => [
                'left' => $left,
                'right' => $right,
            ],
        ], 'all') ?: [];
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['blog_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids ?: [$blogId];
    }

    private static function storedConfigValue(string $key, int $blogId): ?string
    {
        if ($blogId <= 0) {
            return null;
        }
        try {
            $table = '`' . (defined('DB_PREFIX') ? DB_PREFIX : '') . 'config`';
            $value = \DB::query([
                'sql' => "SELECT config_value FROM {$table} WHERE config_blog_id = :bid AND config_key = :key LIMIT 1",
                'params' => [
                    'bid' => $blogId,
                    'key' => $key,
                ],
            ], 'one');
            return $value === false || $value === null ? null : (string)$value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

}
