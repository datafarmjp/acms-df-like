<?php
require dirname(__DIR__, 2) . '/_shared/tests/support/isolated-cms.php';

use Acms\Plugins\DF_Like\Services\LikeRepository;

$target = ['blog_id' => 1, 'entry_id' => 1, 'object_type' => 'entry', 'object_id' => '1', 'user_id' => null];
$visitor = hash('sha256', 'fixture-visitor');
$ip = hash('sha256', 'fixture-ip');
$ua = hash('sha256', 'fixture-ua');
$_SERVER['HTTP_REFERER'] = 'https://example.test/fixture';
$result = LikeRepository::toggle($target, $visitor, $ip, $ua);
testCheck($result['liked'] && $result['action'] === 'like' && $result['count'] === 1, 'new like is stored');
testCheck((int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn() === 1, 'one history row');
testCheck($testPdo->query('SELECT log_referer FROM acms_df_like_log')->fetchColumn() === $_SERVER['HTTP_REFERER'], 'referer remains in history');
$result = LikeRepository::toggle($target, $visitor, $ip, $ua);
testCheck($result['action'] === 'cooldown' && $result['count'] === 1, 'repeated click is suppressed');
$testPdo->exec('UPDATE acms_df_like_log SET log_created_at = DATE_SUB(NOW(), INTERVAL 5 SECOND)');
$result = LikeRepository::toggle($target, $visitor, $ip, $ua);
testCheck(!$result['liked'] && $result['action'] === 'unlike' && $result['count'] === 0, 'unlike is stored');
$logs = (int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn();
// Simulate a real SQL failure while DB debug is off; prior affected_rows may be stale.
$testPdo->exec('RENAME TABLE acms_df_like TO acms_df_like_unavailable');
try {
    $target['object_id'] = '2';
    LikeRepository::toggle($target, $visitor, $ip, $ua);
    throw new RuntimeException('SQL failure was reported as success');
} catch (RuntimeException $e) {
    testCheck(str_contains($e->getMessage(), '保存できません'), 'failed insert aborts toggle');
} finally {
    $testPdo->exec('RENAME TABLE acms_df_like_unavailable TO acms_df_like');
}
testCheck((int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn() === $logs, 'failed insert creates no history');
for ($i = 0; $i < 9; $i++) {
    $target['object_id'] = (string)(10 + $i);
    LikeRepository::toggle($target, $visitor, $ip, $ua);
}
try {
    $target['object_id'] = '100';
    LikeRepository::toggle($target, $visitor, $ip, $ua);
    throw new RuntimeException('Rate limit was bypassed');
} catch (RuntimeException $e) {
    testCheck(str_contains($e->getMessage(), '短時間にいいね操作が集中'), 'rate limit is preserved');
}
