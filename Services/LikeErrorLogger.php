<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeErrorLogger
{
    public static function log(string $type, string $message, array $context = []): void
    {
        try {
            self::ensureTable();
            $message = self::shorten($message, 255);
            $blogId = isset($context['blog_id']) ? (int)$context['blog_id'] : (defined('BID') ? (int)BID : 0);
            $entryId = isset($context['entry_id']) ? (int)$context['entry_id'] : 0;
            unset($context['ip'], $context['user_agent']);
            \DB::query([
                'sql' => 'INSERT INTO `' . LikeRepository::table('df_like_error_log') . '` (
                    error_type, error_blog_id, error_entry_id, error_message, error_context, error_created_at
                ) VALUES (
                    :type, :blog_id, :entry_id, :message, :context, :created_at
                )',
                'params' => [
                    'type' => self::shorten($type, 32),
                    'blog_id' => $blogId,
                    'entry_id' => $entryId,
                    'message' => $message,
                    'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ], 'exec');
        } catch (\Throwable $e) {
        }
    }

    public static function recent(int $limit = 20, array $blogIds = []): array
    {
        try {
            self::ensureTable();
            $limit = max(1, min(100, $limit));
            [$where, $params] = self::blogWhere($blogIds);
            return \DB::query([
                'sql' => 'SELECT error_id, error_type, error_blog_id, error_entry_id, error_message, error_context, error_created_at
                    FROM `' . LikeRepository::table('df_like_error_log') . '`
                    ' . $where . '
                    ORDER BY error_created_at DESC, error_id DESC
                    LIMIT ' . $limit,
                'params' => $params,
            ], 'all') ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function deleteByBlogIds(array $blogIds): int
    {
        try {
            self::ensureTable();
            [$where, $params] = self::blogWhere($blogIds);
            if ($where === '') {
                return 0;
            }
            $count = (int)\DB::query([
                'sql' => 'SELECT COUNT(*) FROM `' . LikeRepository::table('df_like_error_log') . '` ' . $where,
                'params' => $params,
            ], 'one');
            \DB::query([
                'sql' => 'DELETE FROM `' . LikeRepository::table('df_like_error_log') . '` ' . $where,
                'params' => $params,
            ], 'exec');
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function ensureTable(): void
    {
        $table = LikeRepository::table('df_like_error_log');
        \DB::query([
            'sql' => "CREATE TABLE IF NOT EXISTS `{$table}` (
                `error_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `error_type` VARCHAR(32) NOT NULL DEFAULT '',
                `error_blog_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `error_entry_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `error_message` VARCHAR(255) NOT NULL DEFAULT '',
                `error_context` MEDIUMTEXT,
                `error_created_at` DATETIME NOT NULL,
                PRIMARY KEY (`error_id`),
                KEY `df_like_error_created` (`error_created_at`),
                KEY `df_like_error_type` (`error_type`, `error_created_at`),
                KEY `df_like_error_entry` (`error_blog_id`, `error_entry_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'params' => [],
        ], 'exec');
    }

    private static function shorten(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        return substr($value, 0, $length);
    }

    private static function blogWhere(array $blogIds): array
    {
        $blogIds = array_values(array_filter(array_map('intval', $blogIds)));
        if (!$blogIds) {
            return ['', []];
        }

        $placeholders = [];
        $params = [];
        foreach ($blogIds as $index => $blogId) {
            $key = 'error_blog_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $blogId;
        }

        return ['WHERE error_blog_id IN (' . implode(',', $placeholders) . ')', $params];
    }
}
