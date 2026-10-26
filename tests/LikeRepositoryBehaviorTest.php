<?php

/**
 * Characterization of the unchanged repository using an in-memory DB double.
 * No CMS, real SQL execution, database, network, or notification is involved.
 * Interleaving checks illustrate control flow, not actual MySQL concurrency.
 */

use Acms\Plugins\DF_Like\Bootstrap;
use Acms\Plugins\DF_Like\Services\LikeRepository;

define('DB_PREFIX', 'test_');
require_once dirname(__DIR__) . '/Bootstrap.php';
Bootstrap::registerAutoloader();

class DB
{
    public static $likes = [];
    public static $logs = [];
    public static $now = 1000;
    public static $rateChecks = 0;
    public static $afterRateCount = null;
    private static $affected = 0;

    public static function reset(): void
    {
        self::$likes = [];
        self::$logs = [];
        self::$now = 1000;
        self::$rateChecks = 0;
        self::$afterRateCount = null;
    }

    public static function query(array $query, string $mode)
    {
        $sql = preg_replace('/\s+/', ' ', trim($query['sql']));
        $p = $query['params'];
        self::$affected = 0;
        if (strpos($sql, 'SELECT COUNT(*) FROM `test_df_like_log`') === 0) {
            if (isset($p['ip_hash'])) {
                if (strpos($sql, "log_action = 'like'") === false || strpos($sql, 'INTERVAL 10 MINUTE') === false) {
                    throw new RuntimeException('Unexpected rate SQL: ' . $sql);
                }
                self::$rateChecks++;
                $count = count(array_filter(self::$logs, function ($log) use ($p) {
                    return $log['ip_hash'] === $p['ip_hash'] && $log['user_agent_hash'] === $p['user_agent_hash']
                        && $log['action'] === 'like' && $log['test_time'] >= self::$now - 600;
                }));
                if (self::$afterRateCount) {
                    $hook = self::$afterRateCount;
                    self::$afterRateCount = null;
                    $hook();
                }
                return $count;
            }
            if (strpos($sql, 'INTERVAL 3 SECOND') === false) {
                throw new RuntimeException('Unexpected cooldown SQL: ' . $sql);
            }
            return count(array_filter(self::$logs, function ($log) use ($p) {
                return $log['object_type'] === $p['object_type'] && $log['object_id'] === $p['object_id']
                    && $log['visitor_hash'] === $p['visitor_hash'] && $log['test_time'] >= self::$now - 3;
            }));
        }
        if (strpos($sql, 'SELECT like_id FROM `test_df_like`') === 0) {
            return isset(self::$likes[self::key($p['type'], $p['id'], $p['visitor'])]) ? 1 : false;
        }
        if (strpos($sql, 'SELECT COUNT(*) FROM `test_df_like`') === 0) {
            return count(array_filter(self::$likes, function ($row) use ($p) {
                return $row['object_type'] === $p['type'] && $row['object_id'] === $p['id'];
            }));
        }
        if (strpos($sql, 'INSERT IGNORE INTO `test_df_like`') === 0) {
            $key = self::key($p['object_type'], $p['object_id'], $p['visitor_hash']);
            if (!isset(self::$likes[$key])) {
                self::$likes[$key] = $p;
                self::$affected = 1;
            }
            return true;
        }
        if (strpos($sql, 'DELETE FROM `test_df_like`') === 0) {
            $key = self::key($p['object_type'], $p['object_id'], $p['visitor_hash']);
            self::$affected = isset(self::$likes[$key]) ? 1 : 0;
            unset(self::$likes[$key]);
            return true;
        }
        if (strpos($sql, 'INSERT INTO `test_df_like_log`') === 0) {
            self::$logs[] = $p + ['test_time' => self::$now];
            self::$affected = 1;
            return true;
        }
        throw new RuntimeException('Unexpected DB call (' . $mode . '): ' . $sql);
    }

    public static function affected_rows(): int
    {
        return self::$affected;
    }

    private static function key(string $type, string $id, string $visitor): string
    {
        return $type . "\0" . $id . "\0" . $visitor;
    }
}

// Skip schema setup entirely: tests must never attempt a real database access.
$ready = new ReflectionProperty(LikeRepository::class, 'tablesReady');
$ready->setAccessible(true);
$ready->setValue(null, true);
$checks = 0;
function behavior_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function toggle_fixture(string $target, string $visitor = 'visitor-a', string $ua = 'ua-a', string $ip = 'ip-a', int $blog = 1): array
{
    return LikeRepository::toggle([
        'blog_id' => $blog, 'entry_id' => (int)$target, 'object_type' => 'entry',
        'object_id' => $target, 'user_id' => null,
    ], hash('sha256', $visitor), hash('sha256', $ip), hash('sha256', $ua));
}
function expect_limited(callable $operation, string $name): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        behavior_check(strpos($e->getMessage(), '短時間にいいね操作が集中') !== false, $name . ': wrong exception');
        return;
    }
    throw new RuntimeException($name . ': expected rate limit');
}

// Caller-controlled valid visitor cookies are retained; invalid ones reissued.
$_COOKIE['df_like_visitor'] = str_repeat('a', 32);
behavior_check(LikeRepository::issueVisitorId() === str_repeat('a', 32), 'valid cookie retained');
behavior_check(LikeRepository::visitorHashFromCookie() === hash('sha256', str_repeat('a', 32)), 'visitor hash');
$_COOKIE['df_like_visitor'] = 'invalid';
$issued = LikeRepository::issueVisitorId();
behavior_check((bool)preg_match('/^[a-f0-9]{32}$/', $issued), 'invalid cookie reissued');
behavior_check($_COOKIE['df_like_visitor'] === $issued, 'issued cookie cached for current request');

DB::reset();
for ($i = 1; $i <= 10; $i++) {
    behavior_check(toggle_fixture((string)$i)['action'] === 'like', 'first ten likes permitted');
}
expect_limited(function () { toggle_fixture('11'); }, 'eleventh like');
expect_limited(function () { toggle_fixture('1', 'new-cookie'); }, 'new cookie does not reset IP+UA bucket');
expect_limited(function () { toggle_fixture('12', 'visitor-a', 'ua-a', 'ip-a', 2); }, 'bucket spans blogs');
behavior_check(toggle_fixture('1', 'new-cookie', 'changed-ua')['action'] === 'like', 'changed UA creates separate bucket');
behavior_check(toggle_fixture('1', 'another-cookie', 'ua-a', 'changed-ip')['action'] === 'like', 'changed IP creates separate bucket');
$before = DB::$rateChecks;
DB::$now += 4;
behavior_check(toggle_fixture('1')['action'] === 'unlike', 'unlike allowed at limit after cooldown');
behavior_check(DB::$rateChecks === $before, 'unlike does not query rate bucket');
DB::$now += 4;
expect_limited(function () { toggle_fixture('1'); }, 'unlike does not refund like history');
DB::$now = 1600;
expect_limited(function () { toggle_fixture('13'); }, 'inclusive ten-minute boundary');
DB::$now = 1601;
behavior_check(toggle_fixture('13')['action'] === 'like', 'old likes expire from window');

DB::reset();
behavior_check(toggle_fixture('1')['action'] === 'like', 'initial like');
behavior_check(toggle_fixture('1')['action'] === 'cooldown', 'immediate unlike suppressed');
behavior_check(toggle_fixture('1', 'new-cookie')['action'] === 'like', 'new cookie bypasses target cooldown and uniqueness');
behavior_check(count(DB::$likes) === 2, 'same target distinct visitor rows');
DB::$now += 3;
behavior_check(toggle_fixture('1')['action'] === 'cooldown', 'inclusive three-second boundary');
DB::$now += 1;
behavior_check(toggle_fixture('1')['action'] === 'unlike', 'unlike after cooldown');
DB::$now += 4;
behavior_check(toggle_fixture('1')['action'] === 'like', 're-like after cooldown');
behavior_check(count(array_filter(DB::$logs, function ($log) { return $log['action'] === 'like'; })) === 3, 're-like adds to like history');
behavior_check(!LikeRepository::isRateLimited('', hash('sha256', 'ua-a')), 'empty hash bypass exists for direct callers');
behavior_check(!LikeRepository::isRateLimited(hash('sha256', 'ip-a'), ''), 'empty UA hash bypass exists for direct callers');
behavior_check(hash('sha256', '') !== '', 'public POST hashes missing raw values, producing a nonempty key');

// Deterministic DB-double interleaving: both rate reads see nine before either
// outer insert occurs. This is evidence of missing atomic reservation only.
DB::reset();
for ($i = 1; $i <= 9; $i++) {
    toggle_fixture((string)$i);
}
DB::$afterRateCount = function () {
    behavior_check(toggle_fixture('22')['action'] === 'like', 'interleaved like');
};
behavior_check(toggle_fixture('21')['action'] === 'like', 'outer like uses earlier count');
behavior_check(count(DB::$logs) === 11, 'modeled interleaving exceeds ten');

DB::reset();
DB::$afterRateCount = function () { toggle_fixture('1'); };
behavior_check(toggle_fixture('1')['action'] === 'none', 'duplicate insert becomes none');
behavior_check(count(DB::$likes) === 1 && count(DB::$logs) === 1, 'modeled unique key prevents duplicate same visitor/target');

echo 'OK: unchanged LikeRepository behavior (' . $checks . " checks; DB double, no integration)\n";
