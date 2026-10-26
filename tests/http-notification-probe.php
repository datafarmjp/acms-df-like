<?php
if (PHP_SAPI !== 'cli' || getenv('DF_PLUGIN_TEST_DDEV') !== '1') { throw new RuntimeException('DDEV CLI only'); }
$httpRoot=$argv[1]??'';
if (!preg_match('@^/tmp/(df_plugin_test_[a-f0-9]{12})-[0-9]+$@',$httpRoot,$match) || !is_file($httpRoot.'/router.php')) { throw new RuntimeException('Unknown HTTP fixture'); }
if (($argv[2]??'')==='smtp') {
    $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$server) { throw new RuntimeException($error); }
    file_put_contents($httpRoot.'/smtp-port',substr(strrchr(stream_socket_get_name($server,false),':'),1));
    while ($client=@stream_socket_accept($server,20)) {
        fwrite($client,"220 fixture ESMTP\r\n"); $data=false; $body='';
        while (($line=fgets($client))!==false) {
            if ($data) {
                if (rtrim($line)==='.') { file_put_contents($httpRoot.'/mail-'.bin2hex(random_bytes(4)).'.eml',$body); $data=false; fwrite($client,"250 stored\r\n"); }
                else { $body.=$line; }
            } elseif (preg_match('/^(EHLO|HELO)/',$line)) { fwrite($client,"250 fixture\r\n"); }
            elseif (str_starts_with($line,'DATA')) { $data=true; $body=''; fwrite($client,"354 data\r\n"); }
            elseif (str_starts_with($line,'QUIT')) { fwrite($client,"221 bye\r\n"); break; }
            else { fwrite($client,"250 OK\r\n"); }
        }
        fclose($client);
    }
    exit;
}
putenv('DF_PLUGIN_TEST_DATABASE='.$match[1]);
require dirname(__DIR__,2).'/_shared/tests/support/isolated-cms.php';
$process=proc_open([PHP_BINARY,__FILE__,$httpRoot,'smtp'],[0=>['pipe','r'],1=>['file',$httpRoot.'/smtp.log','a'],2=>['file',$httpRoot.'/smtp.log','a']],$pipes);
if (!is_resource($process)) { throw new RuntimeException('SMTP receiver failed'); }
fclose($pipes[0]);
register_shutdown_function(static function () use ($process) { proc_terminate($process); proc_close($process); });
$deadline=microtime(true)+5;
while (!is_file($httpRoot.'/smtp-port') && microtime(true)<$deadline) { usleep(10000); }
$port=(int)@file_get_contents($httpRoot.'/smtp-port');
testCheck($port>0,'local SMTP receiver started');
$mail=new Field();
foreach (['AdminTo'=>'fixture@example.test','AdminFrom'=>'sender@example.test','AdminSubject'=>'DF isolated like notification','AdminBody'=>'Entry: {entry_id}, count: {like_count}','AdminFormSend'=>'yes'] as $key=>$value) { $mail->set($key,$value); }
$form=new Field(); $form->addChild('mail',$mail);
$testPdo->prepare("INSERT INTO acms_form (form_id,form_blog_id,form_code,form_name,form_scope,form_data) VALUES (1,1,'fixture','Fixture notification','local',?)")->execute([serialize($form)]);
foreach (['df_like_notify_enabled'=>'enabled','df_like_notify_form_id'=>'1','mail_smtp-host'=>'127.0.0.1','mail_smtp-port'=>(string)$port] as $key=>$value) {
    $testPdo->prepare('DELETE FROM acms_config WHERE config_blog_id=1 AND config_key=?')->execute([$key]);
    $testPdo->prepare('INSERT INTO acms_config (config_blog_id,config_key,config_value) VALUES (1,?,?)')->execute([$key,$value]);
}
$ch=curl_init('http://ddev-custom-app-web.orb.local:8099/');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
$html=curl_exec($ch);
preg_match('/name="csrf-token" content="([^"]+)"/',$html,$m);
testCheck(!empty($m[1]),'HTTP response provides CSRF token');
$data=['ACMS_POST_DFLikeToggle'=>'1','entry_id'=>'3','object_type'=>'entry','object_id'=>'3','blog_id'=>'1','formToken'=>$m[1]];
$send = static function () use ($ch,$data): array {
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $body=curl_exec($ch); $result=json_decode((string)$body,true);
    if (!is_array($result)) { throw new RuntimeException('Non-JSON response: '.substr((string)$body,0,300)); }
    return $result;
};
$before=count(glob($httpRoot.'/mail-*.eml'));
$rows=(int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like')->fetchColumn();
$logs=(int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn();
$result=$send();
testCheck(($result['status']??'')==='success' && ($result['liked']??false),'HTTP like succeeds');
testCheck(count(glob($httpRoot.'/mail-*.eml'))===$before+1,'one actual SMTP notification received');
testCheck((int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like')->fetchColumn()===$rows+1 && (int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn()===$logs+1,'HTTP like adds one current row and one history row');
$result=$send();
testCheck(count(glob($httpRoot.'/mail-*.eml'))===$before+1,'cooldown sends no additional notification');
$testPdo->exec('UPDATE acms_df_like_log SET log_created_at=DATE_SUB(NOW(),INTERVAL 5 SECOND)');
$result=$send();
testCheck(($result['status']??'')==='success' && !($result['liked']??true),'HTTP unlike succeeds');
testCheck(count(glob($httpRoot.'/mail-*.eml'))===$before+1,'unlike sends no notification');
$testPdo->exec('UPDATE acms_df_like_log SET log_created_at=DATE_SUB(NOW(),INTERVAL 5 SECOND)');
$testPdo->exec("CREATE TRIGGER fixture_http_failure BEFORE INSERT ON acms_df_like FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HTTP fixture failure'");
try {
    $rows=(int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like')->fetchColumn();
    $logs=(int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn();
    $result=$send();
    testCheck(($result['status']??'')==='failure','HTTP failed insert reports failure');
    testCheck(count(glob($httpRoot.'/mail-*.eml'))===$before+1,'failed insert sends no notification');
    testCheck((int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like')->fetchColumn()===$rows && (int)$testPdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn()===$logs,'failed HTTP insert adds no current/history rows');
} finally { $testPdo->exec('DROP TRIGGER fixture_http_failure'); }
