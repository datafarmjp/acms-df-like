<?php
namespace Acms\Plugins\DF_Like\Services {
    // Never send mail/webhooks from a regression test.
    class LikeNotification {
        public static int $calls = 0;
        public static function sendOnLike(array $context): array { self::$calls++; return []; }
    }
}
namespace {
    require dirname(__DIR__, 2) . '/_shared/tests/support/isolated-cms.php';
    $mode = $argv[1] ?? 'success';
    $fail = in_array($mode, ['failure', 'exception'], true);
    if ($mode === 'exception') {
        \DB::swap(new class(\DB::getInstance()) {
            public function __construct(private object $engine) {}
            public function __call($method, $args) { return $this->engine->$method(...$args); }
            public function query($sql, $type = 'row', ...$args) {
                $text = is_array($sql) ? $sql['sql'] : (string)$sql;
                if (preg_match('/INSERT(?: IGNORE)? INTO `?acms_df_like`?\\s*\\(/i', $text)) {
                    throw new \RuntimeException('fixture insert exception');
                }
                return $this->engine->query($sql, $type, ...$args);
            }
        });
    }
    $testPdo->exec("INSERT INTO acms_entry (entry_id, entry_blog_id, entry_status) VALUES (1,1,'open')");
    $testPdo->prepare('INSERT INTO acms_app (app_blog_id, app_name, app_status) VALUES (1,?,?)')->execute(['Acms\\Plugins\\DF_Like\\ServiceProvider','on']);
    \Acms\Plugins\DF_Like\Services\LikeRepository::ensureTables();
    if ($mode === 'failure') {
        $testPdo->exec("CREATE TRIGGER fixture_failure BEFORE INSERT ON acms_df_like FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    }
    \Acms\Services\Facades\Common::swap(new class($testPdo, $fail) {
        public function __construct(private \PDO $pdo, private bool $fail) {}
        public function responseJson($payload, ...$args): never {
            testCheck($payload['status'] === ($this->fail ? 'failure' : 'success'), 'actual like POST returns expected status');
            testCheck(\Acms\Plugins\DF_Like\Services\LikeNotification::$calls === ($this->fail ? 0 : 1), 'notification only follows successful insert');
            testCheck((int)$this->pdo->query('SELECT COUNT(*) FROM acms_df_like_log')->fetchColumn() === ($this->fail ? 0 : 1), 'POST writes history only on success');
            exit(0);
        }
    });
    $post = new \Acms\Plugins\DF_Like\POST\DFLikeToggle();
    $post->Post = new \Field_Validation();
    $post->Post->set('entry_id', '1');
    $post->Post->set('blog_id', '1');
    $post->post();
    throw new \RuntimeException('POST did not return JSON');
}
