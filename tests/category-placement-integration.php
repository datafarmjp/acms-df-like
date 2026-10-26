<?php

/**
 * Real CMS Config/RAM/DB regression, using a disposable database with no copied
 * application data. Run from the DDEV project root:
 * ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/category-placement-integration.php
 */

use Acms\Plugins\DF_Like\POST\DFLikeCategoryOptions;
use Acms\Plugins\DF_Like\Services\LikeBlogContext;
use Acms\Plugins\DF_Like\Services\LikePlacementResolver;
use Acms\Services\Facades\Common;

$beforeCmsBoot = static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO acms_blog (blog_id,blog_parent,blog_left,blog_right,blog_status,blog_name,blog_domain) VALUES
        (3,0,1,12,'open','Root','localhost'),
        (1,3,3,8,'open','Current','localhost'),
        (2,3,10,11,'open','Sibling','localhost'),
        (4,1,4,5,'open','Descendant','localhost')");
    $pdo->exec("INSERT INTO acms_user (user_id,user_blog_id,user_auth,user_status) VALUES (1,3,'administrator','open')");
    $pdo->exec("INSERT INTO acms_category (category_id,category_blog_id,category_parent,category_left,category_right,category_sort,category_scope,category_status,category_name) VALUES
        (1,1,0,1,4,1,'local','open','Own root'),
        (2,1,1,2,3,1,'local','close','Own closed child'),
        (20,3,0,1,4,1,'global','open','Shared root'),
        (21,3,20,2,3,1,'global','close','Shared closed child'),
        (22,3,0,5,8,2,'local','open','Private ancestor'),
        (23,3,22,6,7,1,'global','open','<Shared & child>'),
        (24,2,0,1,2,1,'global','open','Sibling category'),
        (25,4,0,1,2,1,'global','open','Descendant category')");
    $pdo->exec("INSERT INTO acms_entry (entry_id,entry_blog_id,entry_category_id,entry_status) VALUES
        (101,1,2,'open'),(102,1,21,'open'),(103,1,23,'open'),(104,1,22,'open'),
        (105,1,24,'open'),(106,1,25,'open'),(107,1,NULL,'open'),(203,2,21,'open')");
};
foreach (['BID' => 1, 'SUID' => 1, 'SBID' => 3] as $name => $value) {
    if (!defined($name)) { define($name, $value); }
}
require dirname(__DIR__, 2) . '/_shared/tests/support/isolated-cms.php';

$placements = json_encode((object)[
    1 => ['top' => 'center', 'bottom' => 'right'],
    2 => ['top' => 'inherit', 'bottom' => 'none'],
    20 => ['top' => 'right', 'bottom' => 'center'],
    21 => ['top' => 'inherit', 'bottom' => 'none'],
    22 => ['top' => 'center', 'bottom' => 'none'],
    23 => ['top' => 'left', 'bottom' => 'inherit', 'fixture_note' => '</textarea><b> & "quoted" \'single\''],
    24 => ['top' => 'right', 'bottom' => 'none'],
    25 => ['top' => 'right', 'bottom' => 'none'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$field = new Field();
foreach (['df_like_auto_insert_top' => 'none', 'df_like_auto_insert_bottom' => 'left', 'df_like_category_placements' => $placements] as $key => $value) {
    $field->set($key, $value);
}
Config::saveConfig($field, 1);
$sibling = new Field();
$sibling->set('df_like_auto_insert_position', 'before');
$sibling->set('df_like_category_placements', '{"21":{"top":"center","bottom":"inherit"}}');
Config::saveConfig($sibling, 2);

testCheck(LikeBlogContext::storedConfigValue('df_like_category_placements', 1) === $placements, 'native Config saves category JSON verbatim in the current blog');
$savedPlacements = Config::loadBlogConfig(1)->get('df_like_category_placements');
testCheck($savedPlacements === $placements, 'admin config loading returns saved category overrides');

// Render the real admin textarea with the CMS corrector. This catches incorrect
// variable/corrector syntax that otherwise silently reloads saved JSON as blank.
$adminTemplate = file_get_contents(dirname(__DIR__) . '/template/admin/app/df-like.html');
$textareaCount = preg_match_all('/<textarea\b[^>]*\bname="df_like_category_placements"[^>]*>.*?<\/textarea>/s', $adminTemplate, $textareas);
testCheck($textareaCount === 1, 'admin template has exactly one category placement storage field');
$textarea = (new Template($textareas[0][0], new ACMS_Corrector()))->render(['df_like_category_placements' => $savedPlacements]);
testCheck(preg_match('/<textarea\b[^>]*>(.*?)<\/textarea>/s', $textarea, $rendered) === 1, 'native template renders the category placement textarea');
$decodedPlacements = html_entity_decode($rendered[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
testCheck($decodedPlacements === $savedPlacements, 'saved JSON survives actual admin template and corrector rendering without becoming blank or double-escaped');
testCheck(strpos($rendered[1], '<') === false && substr_count($textarea, '</textarea>') === 1, 'stored angle characters cannot close the hidden textarea or inject markup');

testCheck(ACMS_RAM::entryCategory(101) === 2 && ACMS_RAM::categoryParent(2) === 1, 'real RAM resolves main category and parent');
foreach ([
    101 => ['top' => 'center', 'bottom' => 'none'],
    102 => ['top' => 'right', 'bottom' => 'none'],
    103 => ['top' => 'left', 'bottom' => 'left'],
    104 => ['top' => 'none', 'bottom' => 'left'],
    105 => ['top' => 'none', 'bottom' => 'left'],
    106 => ['top' => 'none', 'bottom' => 'left'],
    107 => ['top' => 'none', 'bottom' => 'left'],
    203 => ['top' => 'center', 'bottom' => 'none'],
] as $entryId => $expected) {
    $actual = LikePlacementResolver::resolve($entryId, 1);
    testCheck($actual === $expected, 'real category/blog hierarchy resolves entry ' . $entryId . ': ' . json_encode($actual));
}

testCheck(sessionWithAdministration(BID), 'fixture administrator has real CMS configuration access');
Common::swap(new class {
    public function responseJson(array $payload): array { return $payload; }
});
$response = (new DFLikeCategoryOptions())->post();
testCheck($response['status'] === 'success', 'real category API query succeeds');
$ids = array_column($response['categories'], 'id');
testCheck($ids === [20, 21, 23, 1, 2], 'API lists own and shared categories in blog/tree order and excludes unavailable ones');
$byId = array_column($response['categories'], null, 'id');
testCheck($byId[23]['name'] === '<Shared & child>' && $byId[23]['parent_id'] === 22, 'API preserves category names and parent IDs for safe frontend rendering');

// Removing every category row stores an empty object without disturbing another
// blog's category choices, then restores the original blog placement behavior.
$field = new Field();
$field->set('df_like_category_placements', '{}');
Config::saveConfig($field, 1);
foreach (['settings', 'categoryPaths'] as $name) {
    (new ReflectionProperty(LikePlacementResolver::class, $name))->setValue(null, []);
}
testCheck(LikePlacementResolver::resolve(101, 1) === ['top' => 'none', 'bottom' => 'left'], 'deleting overrides restores blog defaults after a new request');
testCheck(LikePlacementResolver::resolve(203, 1) === ['top' => 'center', 'bottom' => 'none'], 'saving one blog never changes another blog category placement');
echo "PASS: category placement integration completed with native Config, RAM, and DB.\n";
