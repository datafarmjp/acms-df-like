<?php

/** Actual POST/repository control flow with DB doubles; no CMS, SQL or mail. */
namespace Acms\Plugins\DF_Like\Services {
    class LikeNotification
    {
        public static $calls = 0;
        public static function sendOnLike(array $context): array
        {
            self::$calls++;
            return [];
        }
    }
    class LikeErrorLogger
    {
        public static $calls = 0;
        public static function log(string $type, string $message, array $context = []): void
        {
            self::$calls++;
        }
    }
}

namespace Acms\Services\Facades {
    class Common
    {
        public static $response;
        public static function responseJson(array $response): void
        {
            self::$response = $response;
        }
    }
}

namespace {
    use Acms\Plugins\DF_Like\Bootstrap;
    use Acms\Plugins\DF_Like\POST\DFLikeToggle;
    use Acms\Plugins\DF_Like\Services\LikeRepository;
    use Acms\Plugins\DF_Like\Services\LikeNotification;
    use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
    use Acms\Services\Facades\Common;

    class ACMS_POST { public $Post; }
    class TestPostFields
    {
        public function get(string $key): string
        {
            return ['entry_id' => '1', 'blog_id' => '1', 'object_type' => 'entry'][$key] ?? '';
        }
    }
    class DB
    {
        public static $mode;
        public static $insertParams;
        public static $insertAttempts;
        public static $logs;
        public static $rows;
        public static function query(array $query, string $mode)
        {
            $sql = preg_replace('/\s+/', ' ', trim($query['sql']));
            if (strpos($sql, 'SELECT app_status') === 0) { return 'on'; }
            if (strpos($sql, 'SELECT like_id') === 0) { return false; }
            if (strpos($sql, 'SELECT COUNT(*) FROM `fixture_df_like_log`') === 0) { return 0; }
            if (strpos($sql, 'SELECT COUNT(*) FROM `fixture_df_like`') === 0) { return self::$rows; }
            if (strpos($sql, 'INSERT IGNORE INTO `fixture_df_like`') === 0) {
                self::$insertAttempts++;
                self::$insertParams = $query['params'];
                if (self::$mode === 'false') { return false; }
                if (self::$mode === 'exception') { throw new RuntimeException('fixture insert exception'); }
                self::$rows++;
                return true;
            }
            if (strpos($sql, 'INSERT INTO `fixture_df_like_log`') === 0) {
                self::$logs[] = $query['params'];
                return true;
            }
            throw new RuntimeException('Unexpected fixture SQL: ' . $sql);
        }
        public static function affected_rows(): int
        {
            // Stale affected_rows must not turn a false INSERT into success.
            return self::$mode === 'false' ? 7 : 1;
        }
    }

    define('DB_PREFIX', 'fixture_');
    require_once dirname(__DIR__) . '/Bootstrap.php';
    Bootstrap::registerAutoloader();
    $ready = new ReflectionProperty(LikeRepository::class, 'tablesReady');
    $ready->setAccessible(true);
    $ready->setValue(null, true);
    $_COOKIE['df_like_visitor'] = str_repeat('a', 32);
    $_SERVER = ['REMOTE_ADDR' => '198.51.100.8', 'HTTP_USER_AGENT' => 'fixture', 'HTTP_REFERER' => 'https://example.test/fixture'];
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) { throw new RuntimeException($message); }
        $checks++;
    };
    foreach (['success', 'false', 'exception'] as $mode) {
        DB::$mode = $mode;
        DB::$logs = [];
        DB::$rows = DB::$insertAttempts = 0;
        DB::$insertParams = null;
        LikeNotification::$calls = LikeErrorLogger::$calls = 0;
        Common::$response = null;
        $post = new DFLikeToggle();
        $post->Post = new TestPostFields();
        $post->post();
        $success = $mode === 'success';
        $check(DB::$insertAttempts === 1, $mode . ': actual insert attempted');
        $check(Common::$response['status'] === ($success ? 'success' : 'failure'), $mode . ': response status');
        $check(LikeNotification::$calls === ($success ? 1 : 0), $mode . ': notification follows success only');
        $check(count(DB::$logs) === ($success ? 1 : 0), $mode . ': history follows success only');
        $check(LikeErrorLogger::$calls === ($success ? 0 : 1), $mode . ': failure diagnostic');
        $check(is_array(DB::$insertParams) && !array_key_exists('referer', DB::$insertParams), $mode . ': current-like SQL has no unused referer parameter');
        if ($success) {
            $check(DB::$logs[0]['referer'] === $_SERVER['HTTP_REFERER'], 'referer preserved in successful history');
        }
    }
    echo 'PASS: ' . $checks . " POST insert-failure/notification checks (DB doubles; no integration)\n";
}
