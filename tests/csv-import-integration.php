<?php
/** Real CMS permissions and transactional SQL against an automatically dropped fixture DB. */
use Acms\Plugins\DF_Like\Services\LikeRepository as Likes;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;

$globalAdmin = ($argv[1] ?? 'local') === 'global';
foreach (['BID' => 1, 'SUID' => 1, 'SBID' => 1] as $name => $value) {
    if (!defined($name)) { define($name, $value); }
}
$beforeCmsBoot = static function (PDO $pdo) use ($globalAdmin): void {
    $pdo->exec("INSERT INTO acms_blog (blog_id,blog_parent,blog_left,blog_right,blog_status,blog_name,blog_domain) VALUES
        (1,0,1,4,'open','Root','localhost'),(2,1,2,3,'open','Child','localhost')");
    $pdo->prepare("INSERT INTO acms_user (user_id,user_blog_id,user_auth,user_status,user_global_auth) VALUES (1,1,'administrator','open',?)")
        ->execute([$globalAdmin ? 'on' : 'off']);
    $pdo->exec("INSERT INTO acms_entry (entry_id,entry_blog_id,entry_status) VALUES (11,1,'open'),(22,2,'open')");
};
require dirname(__DIR__, 2) . '/_shared/tests/support/isolated-cms.php';

Likes::ensureTables();
LikeErrorLogger::recent();
function importState(bool $diagnostics = false): array {
    global $testPdo;
    $state = [];
    foreach ($diagnostics ? ['df_like', 'df_like_log', 'df_like_error_log'] : ['df_like', 'df_like_log'] as $table) {
        $state[$table] = $testPdo->query('SELECT * FROM acms_' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $state;
}
function importFailure(array $row, string $message, bool $dryRun = false): void {
    $before = importState($dryRun);
    $result = Likes::importRows([2 => $row], $dryRun);
    testCheck($result['failed'] === 1 && $result['imported_logs'] === 0 && $result['imported_likes'] === 0
        && $result['removed_likes'] === 0 && importState($dryRun) === $before, $message);
    testCheck(DB::transactionLevel() === 0, $message . ' closes transaction');
}
$base = ['entry_id' => '11', 'visitor_key' => 'integration-visitor'];
testCheck(sessionWithAdministration(1) && sessionWithAdministration(2) === $globalAdmin, 'native CMS limits administration to the configured blog scope');
$before = importState(true);
$result = Likes::importRows([2 => $base], true);
testCheck($result['failed'] === 0 && $result['imported_logs'] === 1 && importState(true) === $before, 'dry run writes no domain data or diagnostics');
importFailure(['entry_id' => '999'], 'nonexistent entry is rejected without writes', true);
importFailure(['entry_id' => '22', 'blog_id' => '1'], 'forged blog is rejected without writes');
importFailure($base + ['object_id' => '22'], 'entry identity cannot redirect writes to another entry');
$result = Likes::importRows([2 => ['entry_id' => '22', 'visitor_key' => 'child']], false);
testCheck($result['failed'] === ($globalAdmin ? 0 : 1) && $result['imported_likes'] === ($globalAdmin ? 1 : 0), 'child-blog import follows real CMS administrator scope');
$result = Likes::importRows([2 => $base], false);
testCheck($result['failed'] === 0 && $result['imported_likes'] === 1 && $result['imported_logs'] === 1, 'current like and log commit together');
// Make the first imported timestamp differ from this retry without sleeping.
$testPdo->exec("UPDATE acms_df_like_log SET log_created_at='2026-01-01 00:00:00' WHERE log_entry_id=11");
$before = importState();
$result = Likes::importRows([2 => $base], false);
testCheck($result['skipped'] === 1 && $result['imported_logs'] === 0 && importState() === $before, 'timestamp-less retry does not add a log');
$testPdo->exec('DELETE FROM acms_df_like WHERE like_entry_id=11');
$result = Likes::importRows([2 => $base], false);
testCheck($result['imported_likes'] === 1 && $result['imported_logs'] === 0, 'duplicate history repairs missing current like');
$dated = $base + ['created_at' => '2026-02-01 01:00:00'];
$result = Likes::importRows([2 => $dated], false);
testCheck($result['imported_logs'] === 1, 'explicit distinct timestamps preserve separate events');
$before = importState();
$result = Likes::importRows([2 => $dated], false);
testCheck($result['skipped'] === 1 && importState() === $before, 'explicit timestamp retry is idempotent');
$custom = ['entry_id' => '11', 'visitor_key' => 'custom', 'object_type' => 'product', 'object_id' => 'sku-123'];
$result = Likes::importRows([2 => $custom], false);
testCheck($result['failed'] === 0 && $result['imported_likes'] === 1, 'custom object type is still supported');
if ($globalAdmin) {
    importFailure(array_replace($custom, ['entry_id' => '22']), 'custom object cannot impersonate another entry target');
}
// PdoEngine returns false for SQL failures with DEBUG_MODE=0. These real triggers
// exercise that behavior and prove both tables roll back for like and unlike.
foreach (['log' => ['acms_df_like_log', 'INSERT'], 'delete' => ['acms_df_like', 'DELETE'], 'like' => ['acms_df_like', 'INSERT']] as $case => [$table, $action]) {
    $testPdo->exec("CREATE TRIGGER csv_import_failure BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture CSV failure'");
    try {
        if ($case === 'log') {
            importFailure(['entry_id' => '11', 'visitor_key' => 'log-insert-failure'], 'history INSERT false rolls back new current like');
            importFailure($base + ['action' => 'unlike'], 'history INSERT false restores deleted current like');
        } elseif ($case === 'delete') {
            importFailure($base + ['action' => 'unlike'], 'DELETE false creates no unlike history');
        } else {
            importFailure(['entry_id' => '11', 'visitor_key' => 'like-insert-failure'], 'current INSERT false creates no history');
        }
    } finally {
        $testPdo->exec('DROP TRIGGER csv_import_failure');
    }
}
$engine = DB::getInstance();
DB::swap(new class($engine) {
    public function __construct(private object $engine) {}
    public function __call($method, $args) { return $this->engine->$method(...$args); }
    public function singleton($dsn) { return $this; }
    public function query($query, $mode = 'row', ...$args) {
        $sql = is_array($query) ? $query['sql'] : (string)$query;
        if (str_contains($sql, 'INSERT INTO `acms_df_like_log`')) { throw new RuntimeException('fixture log exception'); }
        return $this->engine->query($query, $mode, ...$args);
    }
});
try {
    importFailure(['entry_id' => '11', 'visitor_key' => 'log-throw-failure'], 'history exception rolls back new current like');
    importFailure($base + ['action' => 'unlike'], 'history exception restores deleted current like');
} finally {
    DB::swap($engine);
}
$result = Likes::importRows([2 => ['entry_id' => '999'], 3 => ['entry_id' => '11', 'visitor_key' => 'after-failure']], false);
testCheck($result['failed'] === 1 && $result['imported_likes'] === 1 && $result['imported_logs'] === 1, 'valid row commits after an invalid row');
$result = Likes::importRows([2 => $base + ['action' => 'unlike']], false);
testCheck($result['removed_likes'] === 1 && $result['imported_logs'] === 1, 'successful unlike commits both changes');
$before = importState();
$result = Likes::importRows([2 => $base + ['action' => 'unlike']], false);
testCheck($result['skipped'] === 1 && importState() === $before, 'unlike retry is idempotent');
testCheck(DB::transactionLevel() === 0, 'all transactions are closed');
echo 'PASS CSV import integration (' . ($globalAdmin ? 'global' : 'local') . ")\n";
