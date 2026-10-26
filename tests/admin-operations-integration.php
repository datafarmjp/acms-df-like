<?php
foreach (['BID' => 1, 'SUID' => 1, 'SBID' => 1] as $name => $value) {
    if (!defined($name)) { define($name, $value); }
}
require dirname(__DIR__,2).'/_shared/tests/support/stage2-cms.php';
use Acms\Plugins\DF_Like\Services\LikeRepository as Likes;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
$testPdo->exec("INSERT INTO acms_entry (entry_id,entry_blog_id,entry_status) VALUES (1,1,'open'),(2,1,'open')");
Likes::ensureTables();LikeErrorLogger::recent();
$rows=[['entry_id'=>'1','visitor_key'=>'stage2-a','action'=>'like','created_at'=>'2026-01-01 01:00:00'],['entry_id'=>'2','visitor_key'=>'stage2-b','action'=>'like','created_at'=>'2026-01-01 01:00:00']];
$tables=['df_like','df_like_log','df_like_error_log'];
$before=stage2Counts($tables);$result=Likes::importRows($rows,true);
stage2Check($result['failed']===0 && stage2Counts($tables)===$before,'valid CSV dry run writes no data');
$result=Likes::importRows($rows,false);
stage2Check($result['imported_logs']===2 && $result['imported_likes']===2,'CSV imports two rows');
$before=stage2Counts($tables);$result=Likes::importRows($rows,false);
stage2Check($result['failed']===0 && stage2Counts($tables)===$before,'same CSV does not duplicate');
$filters=Likes::filtersFromInput('','','current',1);
$testPdo->exec('DELETE FROM acms_df_like');Likes::rebuildCurrentLikesFromLogs($filters);
stage2Check(Likes::count('entry','1')===1 && Likes::count('entry','2')===1,'rebuild restores active likes from history');
$id=(int)$testPdo->query('SELECT log_id FROM acms_df_like_log ORDER BY log_id LIMIT 1')->fetchColumn();
Likes::deleteLikeByLogId($id,$filters);
stage2Check(stage2Counts(['df_like','df_like_log'])===['df_like'=>1,'df_like_log'=>1],'individual deletion removes matching current/history row');
$bad=[['entry_id'=>'bad']];$before=stage2Counts($tables);$result=Likes::importRows($bad,true);
stage2Check($result['failed']===1 && count($result['errors'])===1,'invalid dry-run row is reported');
stage2Check(stage2Counts($tables)===$before,'invalid CSV dry run also writes no data including diagnostics');
$result=Likes::importRows([$rows[0],$bad[0]],false);
stage2Check($result['processed']===2 && $result['failed']===1 && $result['imported_logs']===1,'mixed import records per-row success/failure');
stage2Check(stage2Counts($tables)['df_like_error_log']===$before['df_like_error_log']+1,'real invalid import still records diagnostics');
stage2Finish();
