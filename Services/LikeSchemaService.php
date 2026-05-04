<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeSchemaService
{
    public static function ensure(): void
    {
        $tables = self::tables();

        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$tables['likes']}` (
                `like_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `like_blog_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `like_entry_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `like_object_type` VARCHAR(32) NOT NULL DEFAULT 'entry',
                `like_object_id` VARCHAR(64) NOT NULL DEFAULT '',
                `like_user_id` INT UNSIGNED DEFAULT NULL,
                `like_visitor_hash` CHAR(64) NOT NULL,
                `like_ip_hash` CHAR(64) NOT NULL DEFAULT '',
                `like_user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
                `like_created_at` DATETIME NOT NULL,
                `like_updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`like_id`),
                UNIQUE KEY `df_like_target_visitor` (`like_object_type`, `like_object_id`, `like_visitor_hash`),
                KEY `df_like_entry` (`like_blog_id`, `like_entry_id`),
                KEY `df_like_created` (`like_created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$tables['logs']}` (
                `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `log_blog_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `log_entry_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `log_object_type` VARCHAR(32) NOT NULL DEFAULT 'entry',
                `log_object_id` VARCHAR(64) NOT NULL DEFAULT '',
                `log_action` VARCHAR(16) NOT NULL DEFAULT 'like',
                `log_user_id` INT UNSIGNED DEFAULT NULL,
                `log_visitor_hash` CHAR(64) NOT NULL,
                `log_ip_hash` CHAR(64) NOT NULL DEFAULT '',
                `log_user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
                `log_referer` TEXT,
                `log_created_at` DATETIME NOT NULL,
                PRIMARY KEY (`log_id`),
                KEY `df_like_log_target` (`log_object_type`, `log_object_id`, `log_created_at`),
                KEY `df_like_log_entry` (`log_blog_id`, `log_entry_id`),
                KEY `df_like_log_action` (`log_action`, `log_created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$tables['errors']}` (
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
        ];

        foreach ($queries as $sql) {
            \DB::query(['sql' => $sql, 'params' => []], 'exec');
        }

        self::repair();
    }

    public static function repair(): void
    {
        $tables = self::tables();
        foreach (self::columns($tables['likes'], $tables['logs']) as $column) {
            self::ensureColumn($column['table'], $column['name'], $column['definition']);
        }

        self::runRepairSql("ALTER TABLE `{$tables['likes']}` MODIFY `like_user_id` INT UNSIGNED DEFAULT NULL");
        self::runRepairSql("ALTER TABLE `{$tables['logs']}` MODIFY `log_user_id` INT UNSIGNED DEFAULT NULL");
    }

    private static function tables(): array
    {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : (dsn()['prefix'] ?? '');
        return [
            'likes' => $prefix . 'df_like',
            'logs' => $prefix . 'df_like_log',
            'errors' => $prefix . 'df_like_error_log',
        ];
    }

    private static function columns(string $likes, string $logs): array
    {
        return [
            ['table' => $likes, 'name' => 'like_blog_id', 'definition' => '`like_blog_id` INT UNSIGNED NOT NULL DEFAULT 0'],
            ['table' => $likes, 'name' => 'like_entry_id', 'definition' => '`like_entry_id` INT UNSIGNED NOT NULL DEFAULT 0'],
            ['table' => $likes, 'name' => 'like_object_type', 'definition' => "`like_object_type` VARCHAR(32) NOT NULL DEFAULT 'entry'"],
            ['table' => $likes, 'name' => 'like_object_id', 'definition' => "`like_object_id` VARCHAR(64) NOT NULL DEFAULT ''"],
            ['table' => $likes, 'name' => 'like_user_id', 'definition' => '`like_user_id` INT UNSIGNED DEFAULT NULL'],
            ['table' => $likes, 'name' => 'like_visitor_hash', 'definition' => '`like_visitor_hash` CHAR(64) NOT NULL'],
            ['table' => $likes, 'name' => 'like_ip_hash', 'definition' => "`like_ip_hash` CHAR(64) NOT NULL DEFAULT ''"],
            ['table' => $likes, 'name' => 'like_user_agent_hash', 'definition' => "`like_user_agent_hash` CHAR(64) NOT NULL DEFAULT ''"],
            ['table' => $likes, 'name' => 'like_created_at', 'definition' => '`like_created_at` DATETIME NOT NULL'],
            ['table' => $likes, 'name' => 'like_updated_at', 'definition' => '`like_updated_at` DATETIME NOT NULL'],
            ['table' => $logs, 'name' => 'log_blog_id', 'definition' => '`log_blog_id` INT UNSIGNED NOT NULL DEFAULT 0'],
            ['table' => $logs, 'name' => 'log_entry_id', 'definition' => '`log_entry_id` INT UNSIGNED NOT NULL DEFAULT 0'],
            ['table' => $logs, 'name' => 'log_object_type', 'definition' => "`log_object_type` VARCHAR(32) NOT NULL DEFAULT 'entry'"],
            ['table' => $logs, 'name' => 'log_object_id', 'definition' => "`log_object_id` VARCHAR(64) NOT NULL DEFAULT ''"],
            ['table' => $logs, 'name' => 'log_action', 'definition' => "`log_action` VARCHAR(16) NOT NULL DEFAULT 'like'"],
            ['table' => $logs, 'name' => 'log_user_id', 'definition' => '`log_user_id` INT UNSIGNED DEFAULT NULL'],
            ['table' => $logs, 'name' => 'log_visitor_hash', 'definition' => '`log_visitor_hash` CHAR(64) NOT NULL'],
            ['table' => $logs, 'name' => 'log_ip_hash', 'definition' => "`log_ip_hash` CHAR(64) NOT NULL DEFAULT ''"],
            ['table' => $logs, 'name' => 'log_user_agent_hash', 'definition' => "`log_user_agent_hash` CHAR(64) NOT NULL DEFAULT ''"],
            ['table' => $logs, 'name' => 'log_referer', 'definition' => '`log_referer` TEXT'],
            ['table' => $logs, 'name' => 'log_created_at', 'definition' => '`log_created_at` DATETIME NOT NULL'],
        ];
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $exists = \DB::query([
                'sql' => 'SHOW COLUMNS FROM `' . $table . '` LIKE :column',
                'params' => ['column' => $column],
            ], 'row');
            if ($exists) {
                return;
            }
            self::runRepairSql('ALTER TABLE `' . $table . '` ADD COLUMN ' . $definition);
        } catch (\Throwable $e) {
            LikeErrorLogger::log('schema', $e->getMessage(), [
                'table' => $table,
                'column' => $column,
            ]);
        }
    }

    private static function runRepairSql(string $sql): void
    {
        try {
            $result = \DB::query(['sql' => $sql, 'params' => []], 'exec');
            if ($result === false) {
                LikeErrorLogger::log('schema', self::dbErrorMessage('テーブル補修に失敗しました。'), [
                    'sql' => $sql,
                ]);
            }
        } catch (\Throwable $e) {
            LikeErrorLogger::log('schema', $e->getMessage(), [
                'sql' => $sql,
            ]);
        }
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
}
