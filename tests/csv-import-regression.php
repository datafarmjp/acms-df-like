<?php
/** Pure regression harness: no CMS, filesystem writes, network, or real DB. */
namespace Acms\Plugins\DF_Like\Services {
    function date(string $format): string { return \DB::$clock; }
    class LikeRepository {
        public static function ensureTables(): void {}
        public static function table(string $name): string { return $name; }
        public static function insertCurrentLike(array $data): bool {
            if (\DB::$failure === 'like_false') { return false; }
            if (\DB::$failure === 'like_exception') { throw new \RuntimeException('fixture like exception'); }
            \DB::$likes[] = $data;
            return true;
        }
    }
    class LikeErrorLogger {
        public static array $events = [];
        public static function log(...$event): void {
            if (\DB::$snapshot !== null) { throw new \RuntimeException('diagnostic was written before rollback'); }
            self::$events[] = $event;
        }
    }
}
namespace {
    define('BID', 1);
    function dsn(): array { return []; }
    function sessionWithAdministration(int $bid): bool { return in_array($bid, DB::$adminBlogs, true); }
    function roleAvailableUser(): bool { return DB::$role; }
    function roleAuthorization(string $action, int $bid): bool { return $action === 'config_edit' && in_array($bid, DB::$roleBlogs, true); }
    class DB {
        public static array $entries = [11 => 1, 22 => 2];
        public static array $adminBlogs = [1];
        public static array $roleBlogs = [];
        public static bool $role = false;
        public static string $clock = '2026-09-29 01:00:00';
        public static string $failure = '';
        public static array $likes = [];
        public static array $logs = [];
        public static ?array $snapshot = null;
        public static int $begins = 0;
        public static function singleton(array $dsn): self { return new self(); }
        public function beginTransaction(): void { self::$begins++; self::$snapshot = [self::$likes, self::$logs]; }
        public function commit(): void {
            if (self::$failure === 'commit_exception') { throw new \RuntimeException('fixture commit exception'); }
            self::$snapshot = null;
        }
        public function rollBack(): void { [self::$likes, self::$logs] = self::$snapshot; self::$snapshot = null; }
        public static function query(array $q, string $mode) {
            $sql = $q['sql']; $p = $q['params'];
            if (str_contains($sql, 'SELECT entry_blog_id')) {
                return isset(self::$entries[$p['entry_id']]) ? ['entry_blog_id' => self::$entries[$p['entry_id']]] : false;
            }
            if (str_contains($sql, 'SELECT COUNT(*)')) {
                if (self::$failure === 'read_false') { return false; }
                $isLog = str_contains($sql, '`df_like_log`');
                $rows = $isLog ? self::$logs : self::$likes;
                $count = 0;
                foreach ($rows as $row) {
                    if ($row['object_type'] !== $p['object_type'] || $row['object_id'] !== $p['object_id']) { continue; }
                    if (str_contains($sql, '<>')) {
                        if ($row['blog_id'] !== $p['blog_id'] || $row['entry_id'] !== $p['entry_id']) { $count++; }
                        continue;
                    }
                    $matches = true;
                    foreach ($p as $key => $value) { if ($row[$key] !== $value) { $matches = false; } }
                    if ($matches) { $count++; }
                }
                return $count;
            }
            if (str_contains($sql, 'INSERT INTO `df_like_log`')) {
                if (self::$failure === 'log_false') { return false; }
                if (self::$failure === 'log_exception') { throw new \RuntimeException('fixture log exception'); }
                self::$logs[] = $p;
                return true;
            }
            if (str_contains($sql, 'DELETE FROM `df_like`')) {
                if (self::$failure === 'delete_false') { return false; }
                self::$likes = array_values(array_filter(self::$likes, static function ($row) use ($p) {
                    foreach ($p as $key => $value) { if ($row[$key] !== $value) { return true; } }
                    return false;
                }));
                return true;
            }
            throw new \RuntimeException('Unexpected SQL: ' . $sql);
        }
    }
    require dirname(__DIR__) . '/Services/LikeImportService.php';
    use Acms\Plugins\DF_Like\Services\LikeImportService as Import;
    use Acms\Plugins\DF_Like\Services\LikeErrorLogger as Logger;
    $checks = 0;
    function check(bool $ok, string $name): void {
        global $checks;
        if (!$ok) { throw new \RuntimeException($name); }
        $checks++;
        echo 'PASS ' . $name . "\n";
    }
    function state(): array { return [DB::$likes, DB::$logs]; }
    function failedWithoutChanges(array $row, string $name, bool $dryRun = false): void {
        $before = state();
        $result = Import::importRows([2 => $row], $dryRun);
        check($result['failed'] === 1 && $result['imported_logs'] === 0 && $result['imported_likes'] === 0 && $result['removed_likes'] === 0 && state() === $before, $name);
    }
    $base = ['entry_id' => '11', 'visitor_key' => 'visitor-1'];
    $before = [state(), count(Logger::$events), DB::$begins];
    $result = Import::importRows([2 => $base], true);
    check($result['imported_logs'] === 1 && $result['imported_likes'] === 1 && [state(), count(Logger::$events), DB::$begins] === $before, 'valid dry run makes no writes or transactions');
    failedWithoutChanges(['entry_id' => '999'], 'nonexistent entry is rejected', true);
    check(count(Logger::$events) === 0, 'invalid dry run writes no diagnostics');
    failedWithoutChanges(['entry_id' => '22'], 'foreign blog requires authorization even when blog_id is omitted');
    failedWithoutChanges(['entry_id' => '22', 'blog_id' => '1'], 'forged current blog cannot authorize a foreign entry');
    failedWithoutChanges($base + ['blog_id' => '2'], 'explicit mismatched blog is rejected');
    failedWithoutChanges($base + ['object_id' => '22'], 'entry object_id cannot target another entry');
    failedWithoutChanges($base + ['object_type' => 'ENTRY', 'object_id' => '22'], 'case variation cannot bypass entry identity');
    $result = Import::importRows([17 => ['entry_id' => '11']], false);
    check($result['imported_logs'] === 1 && $result['imported_likes'] === 1, 'omitted visitor key is generated from the CSV line');
    DB::$clock = '2026-09-30 03:00:00';
    $before = state();
    $result = Import::importRows([17 => ['entry_id' => '11']], false);
    check($result['skipped'] === 1 && state() === $before, 'omitting both visitor_key and created_at remains idempotent');
    DB::$likes = DB::$logs = [];
    $result = Import::importRows([2 => $base], false);
    check($result['failed'] === 0 && $result['imported_logs'] === 1 && count(DB::$likes) === 1, 'authorized row stores current like and history');
    DB::$clock = '2026-10-01 03:00:00';
    $before = state();
    $result = Import::importRows([2 => $base], false);
    check($result['skipped'] === 1 && $result['imported_logs'] === 0 && state() === $before, 'retry without created_at remains idempotent when clock changes');
    DB::$likes = [];
    $result = Import::importRows([2 => $base], false);
    check($result['imported_likes'] === 1 && $result['imported_logs'] === 0 && count(DB::$logs) === 1, 'duplicate history still repairs missing current like');
    $result = Import::importRows([2 => $base + ['created_at' => '2026-09-01 01:00:00']], false);
    check($result['imported_logs'] === 1, 'explicit distinct timestamp keeps a separate historical event');
    $before = state();
    Import::importRows([2 => $base + ['created_at' => '2026-09-01 01:00:00']], false);
    check(state() === $before, 'explicit timestamp retry is idempotent');
    $unlike = $base + ['action' => 'unlike'];
    foreach (['delete_false', 'log_false', 'log_exception', 'commit_exception'] as $failure) {
        DB::$failure = $failure;
        failedWithoutChanges($unlike, 'unlike rollback and success counts for ' . $failure);
    }
    DB::$failure = '';
    $result = Import::importRows([2 => $unlike], false);
    check($result['removed_likes'] === 1 && $result['imported_logs'] === 1 && count(DB::$likes) === 0, 'successful unlike removes current state and writes history');
    DB::$clock = '2026-10-02 03:00:00';
    $before = state();
    Import::importRows([2 => $unlike], false);
    check(state() === $before, 'timestamp-less unlike retry does not duplicate history');
    foreach (['like_false', 'like_exception', 'log_false', 'log_exception', 'read_false', 'commit_exception'] as $failure) {
        DB::$failure = $failure;
        failedWithoutChanges(['entry_id' => '11', 'visitor_key' => $failure], 'like rollback and success counts for ' . $failure);
    }
    DB::$failure = '';
    $custom = ['entry_id' => '11', 'visitor_key' => 'custom', 'object_type' => 'product', 'object_id' => 'sku-123'];
    $result = Import::importRows([2 => $custom], false);
    check($result['failed'] === 0 && $result['imported_logs'] === 1, 'custom non-entry object type stays supported');
    DB::$adminBlogs = [1, 2];
    failedWithoutChanges(array_replace($custom, ['entry_id' => '22']), 'custom target cannot be reassigned to a different owning entry');
    $result = Import::importRows([2 => ['entry_id' => '22', 'visitor_key' => 'allowed-admin']], false);
    check($result['failed'] === 0 && $result['imported_likes'] === 1, 'authorized administrator can import another blog');
    DB::$adminBlogs = [];
    DB::$role = true;
    DB::$roleBlogs = [2];
    $result = Import::importRows([2 => ['entry_id' => '22', 'visitor_key' => 'allowed-role']], false);
    check($result['failed'] === 0 && $result['imported_likes'] === 1, 'config_edit role is checked for the target blog');
    failedWithoutChanges(['entry_id' => '11', 'visitor_key' => 'denied-role'], 'role permission on another blog is insufficient');
    DB::$adminBlogs = [1];
    $result = Import::importRows([2 => ['entry_id' => '999'], 3 => ['entry_id' => '11', 'visitor_key' => 'after-failure']], false);
    check($result['failed'] === 1 && $result['imported_logs'] === 1 && $result['imported_likes'] === 1 && DB::$snapshot === null, 'mixed CSV commits only the valid row and leaves no open transaction');
    echo "PASS {$checks} CSV import regression checks\n";
}
