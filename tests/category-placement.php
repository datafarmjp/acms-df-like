<?php

/**
 * Standalone category placement and hook regression tests. No CMS database,
 * cookies, likes, mail, or webhooks are read or written.
 * Run: php extension/plugins/DF_Like/tests/category-placement.php
 */

namespace Acms\Plugins\DF_Like\Services {
    class LikeButtonRenderer
    {
        public static function renderDefault(int $entryId, int $blogId, string $align = 'left', string $source = 'auto'): string
        {
            return "[{$source}:{$blogId}:{$entryId}:{$align}]";
        }
    }
    class LazyLikeAnalyticsHtml {}
}

namespace Acms\Modules\Get\V2\Entry {
    class Body {}
}

namespace Acms\Services\Facades {
    class Common
    {
        public static function responseJson(array $payload): array { return $payload; }
    }
}

namespace {
    use Acms\Plugins\DF_Like\Hook;
    use Acms\Plugins\DF_Like\POST\DFLikeCategoryOptions;
    use Acms\Plugins\DF_Like\Services\LikePlacementResolver;

    define('DB_PREFIX', 'fixture_');
    define('BID', 1);
    define('ADMIN', false);

    class ACMS_GET { public int $eid = 0; }
    class ACMS_GET_Entry_Body extends ACMS_GET {}
    class ACMS_POST {}
    class ACMS_RAM
    {
        public static array $entries = [];
        public static array $categories = [];
        public static array $blogs = [1 => [3, 8], 2 => [10, 11], 3 => [1, 12], 4 => [4, 5]];
        public static function entryBlog(int $id): int { return self::$entries[$id]['blog'] ?? 0; }
        public static function entryCategory(int $id): ?int { return self::$entries[$id]['category'] ?? null; }
        public static function categoryBlog(int $id): int { return self::$categories[$id]['blog'] ?? 0; }
        public static function categoryParent(int $id): int { return self::$categories[$id]['parent'] ?? 0; }
        public static function categoryScope(int $id): string { return self::$categories[$id]['scope'] ?? 'local'; }
        public static function blogLeft(int $id): int { return self::$blogs[$id][0] ?? 0; }
        public static function blogRight(int $id): int { return self::$blogs[$id][1] ?? 0; }
    }
    class DB
    {
        public static array $config = [];
        public static array $enabled = [];
        public static array $reads = [];
        public static bool $failCategories = false;

        public static function query(array $query, string $mode = 'row')
        {
            $sql = $query['sql'];
            $blogId = (int)$query['params']['bid'];
            if (strpos($sql, '`fixture_config`') !== false) {
                $key = $query['params']['key'];
                self::$reads[$blogId][$key] = (self::$reads[$blogId][$key] ?? 0) + 1;
                return self::$config[$blogId][$key] ?? false;
            }
            if (strpos($sql, '`fixture_app`') !== false) {
                return !empty(self::$enabled[$blogId]) ? 'on' : 'off';
            }
            if (strpos($sql, '`fixture_category`') !== false) {
                check(strpos($sql, 'category_blog_id = :bid') !== false && strpos($sql, 'category_scope = :scope') !== false, 'category API includes own and eligible shared categories');
                check(strpos($sql, 'ORDER BY blog_left ASC, category_left ASC') !== false, 'category API query keeps tree order');
                check(strpos($sql, 'category_status') === false, 'category API also lists closed categories');
                if (self::$failCategories) {
                    return false;
                }
                $rows = [];
                foreach (ACMS_RAM::$categories as $id => $row) {
                    $shared = ($row['scope'] ?? 'local') === 'global'
                        && ACMS_RAM::blogLeft($row['blog']) < ($query['params']['left'] ?? 0)
                        && ACMS_RAM::blogRight($row['blog']) > ($query['params']['right'] ?? PHP_INT_MAX);
                    if ($row['blog'] === $blogId || $shared) {
                        $rows[] = ['category_id' => (string)$id, 'category_name' => $row['name'] ?? "Category {$id}", 'category_parent' => (string)$row['parent']];
                    }
                }
                return $rows;
            }
            throw new \RuntimeException('Unexpected test query: ' . $sql);
        }
    }

    $admin = true;
    $role = false;
    $configPermission = false;
    function sessionWithAdministration(int $blogId): bool { return $blogId === BID && $GLOBALS['admin']; }
    function roleAvailableUser(): bool { return $GLOBALS['role']; }
    function roleAuthorization(string $permission, int $blogId): bool
    {
        return $permission === 'config_edit' && $blogId === BID && $GLOBALS['configPermission'];
    }

    require dirname(__DIR__) . '/Services/LikeRepository.php';
    require dirname(__DIR__) . '/Services/LikeBlogContext.php';
    require dirname(__DIR__) . '/Services/LikePlacementResolver.php';
    require dirname(__DIR__) . '/Hook.php';
    require dirname(__DIR__) . '/POST/DFLikeCategoryOptions.php';

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
        $GLOBALS['checks']++;
    }
    function same($expected, $actual, string $message): void
    {
        check($expected === $actual, $message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    function resetCaches(): void
    {
        foreach ([[LikePlacementResolver::class, 'settings'], [LikePlacementResolver::class, 'categoryPaths'], [Hook::class, 'inserted']] as [$class, $name]) {
            $property = new \ReflectionProperty($class, $name);
            $property->setValue(null, []);
        }
        DB::$reads = [];
    }
    function mapConfig(array $map): void
    {
        DB::$config[1]['df_like_category_placements'] = json_encode((object)$map);
        resetCaches();
    }
    function placements(int $entryId, string $top, string $bottom, string $message, int $blogId = 1): void
    {
        same(['top' => $top, 'bottom' => $bottom], LikePlacementResolver::resolve($entryId, $blogId), $message);
    }

    ACMS_RAM::$categories = [
        1 => ['blog' => 1, 'parent' => 0, 'name' => '<News & Events>'],
        2 => ['blog' => 1, 'parent' => 1, 'status' => 'close'],
        3 => ['blog' => 1, 'parent' => 2],
        4 => ['blog' => 1, 'parent' => 0],
        10 => ['blog' => 2, 'parent' => 0],
        14 => ['blog' => 1, 'parent' => 10],
        15 => ['blog' => 1, 'parent' => 999],
        16 => ['blog' => 1, 'parent' => 17],
        17 => ['blog' => 1, 'parent' => 16],
        20 => ['blog' => 3, 'parent' => 0, 'scope' => 'global'],
        21 => ['blog' => 3, 'parent' => 20, 'scope' => 'global'],
        22 => ['blog' => 3, 'parent' => 0, 'scope' => 'local'],
        23 => ['blog' => 3, 'parent' => 22, 'scope' => 'global'],
        24 => ['blog' => 2, 'parent' => 0, 'scope' => 'global'],
        25 => ['blog' => 4, 'parent' => 0, 'scope' => 'global'],
    ];
    ACMS_RAM::$entries = [
        101 => ['blog' => 1, 'category' => 3],
        102 => ['blog' => 1, 'category' => 2],
        103 => ['blog' => 1, 'category' => 1],
        104 => ['blog' => 1, 'category' => 4],
        105 => ['blog' => 1, 'category' => null],
        106 => ['blog' => 1, 'category' => 10],
        107 => ['blog' => 1, 'category' => 999],
        114 => ['blog' => 1, 'category' => 14],
        115 => ['blog' => 1, 'category' => 15],
        116 => ['blog' => 1, 'category' => 16],
        121 => ['blog' => 1, 'category' => 21],
        122 => ['blog' => 1, 'category' => 22],
        123 => ['blog' => 1, 'category' => 23],
        124 => ['blog' => 1, 'category' => 24],
        125 => ['blog' => 1, 'category' => 25],
        203 => ['blog' => 2, 'category' => 10],
    ];
    DB::$config = [
        1 => ['df_like_auto_insert' => 'enabled', 'df_like_auto_insert_top' => 'none', 'df_like_auto_insert_bottom' => 'left'],
        2 => ['df_like_auto_insert' => 'enabled', 'df_like_auto_insert_top' => 'left', 'df_like_auto_insert_bottom' => 'center', 'df_like_category_placements' => '{"10":{"top":"right","bottom":"none"}}'],
    ];
    DB::$enabled = [1 => true, 2 => true];
    $overrides = [
        1 => ['top' => 'center', 'bottom' => 'right'],
        2 => ['top' => 'inherit', 'bottom' => 'none'],
        3 => ['top' => 'right', 'bottom' => 'inherit'],
        10 => ['top' => 'center', 'bottom' => 'center'],
        14 => ['top' => 'right', 'bottom' => 'right'],
        15 => ['top' => 'right', 'bottom' => 'right'],
        16 => ['top' => 'right', 'bottom' => 'right'],
        20 => ['top' => 'center', 'bottom' => 'right'],
        21 => ['top' => 'inherit', 'bottom' => 'none'],
        22 => ['top' => 'right', 'bottom' => 'right'],
        23 => ['top' => 'right', 'bottom' => 'inherit'],
        24 => ['top' => 'right', 'bottom' => 'right'],
        25 => ['top' => 'right', 'bottom' => 'right'],
    ];
    mapConfig($overrides);
    placements(103, 'center', 'right', 'direct category overrides both positions');
    placements(102, 'center', 'none', 'parent top is inherited while explicit none hides bottom');
    placements(101, 'right', 'none', 'child top overrides while nearest parent bottom wins');
    placements(104, 'none', 'left', 'unconfigured category uses blog defaults');
    placements(105, 'none', 'left', 'uncategorized entry uses blog defaults');
    placements(106, 'none', 'left', 'foreign main category cannot override current blog');
    placements(107, 'none', 'left', 'deleted category uses blog defaults');
    placements(114, 'right', 'right', 'unavailable parent stops inheritance and retains eligible overrides');
    placements(115, 'right', 'right', 'missing parent retains eligible direct overrides');
    placements(116, 'none', 'left', 'cyclic hierarchy uses blog defaults');
    placements(121, 'center', 'none', 'ancestor global categories inherit with entry-blog settings');
    placements(122, 'none', 'left', 'ancestor local category cannot override child-blog placements');
    placements(123, 'right', 'left', 'shared category under local parent keeps own override and falls back for inherited position');
    placements(124, 'none', 'left', 'sibling global category is not available');
    placements(125, 'none', 'left', 'descendant global category is not available');
    placements(203, 'right', 'none', 'entry blog overrides caller blog context');
    same(1, DB::$reads[1]['df_like_category_placements'], 'category settings are read once per blog per request');
    same(1, DB::$reads[2]['df_like_category_placements'], 'blog caches remain separate');

    mapConfig([1 => ['top' => 'right', 'bottom' => 'center'], 2 => ['top' => 'left', 'bottom' => 'inherit'], 3 => ['top' => 'inherit', 'bottom' => 'inherit']]);
    placements(101, 'left', 'center', 'each side independently finds its nearest configured ancestor');
    mapConfig([1 => ['top' => 'center', 'bottom' => 'right'], 3 => ['top' => 'invalid', 'bottom' => ['none']]]);
    placements(101, 'center', 'right', 'invalid position values inherit safely');

    foreach (['{broken', '[]', 'null', '"text"', '{"0":{"top":"right"},"-1":{"bottom":"none"},"03":{"top":"right"},"3":[],"999999999999999999999999999999":{"top":"right"}}'] as $raw) {
        DB::$config[1]['df_like_category_placements'] = $raw;
        resetCaches();
        placements(101, 'none', 'left', 'malformed category configuration uses blog defaults');
    }

    $original = DB::$config[1];
    unset(DB::$config[1]['df_like_auto_insert_top'], DB::$config[1]['df_like_auto_insert_bottom'], DB::$config[1]['df_like_category_placements']);
    foreach (['before' => ['left', 'none'], 'after' => ['none', 'left'], 'both' => ['left', 'left'], 'invalid' => ['none', 'left']] as $legacy => [$top, $bottom]) {
        DB::$config[1]['df_like_auto_insert_position'] = $legacy;
        resetCaches();
        placements(101, $top, $bottom, 'legacy placement remains compatible: ' . $legacy);
    }
    unset(DB::$config[1]['df_like_auto_insert_position']);
    resetCaches();
    placements(101, 'none', 'left', 'missing placement preserves the original bottom-left default');
    DB::$config[1] = $original;
    mapConfig($overrides);

    $hook = new Hook();
    $v1 = new ACMS_GET_Entry_Body();
    $v1->eid = 101;
    $body = 'V1 BODY';
    $hook->afterGetFire($body, $v1);
    same('[auto:1:101:right]V1 BODY', $body, 'V1 hook applies category placement');
    $repeat = 'SECOND BODY';
    $hook->afterGetFire($repeat, $v1);
    same('SECOND BODY', $repeat, 'V1 hook prevents duplicate automatic insertion');
    $v2 = new \Acms\Modules\Get\V2\Entry\Body();
    $response = ['items' => [
        ['eid' => 102, 'bid' => 1, 'body' => 'CHILD'],
        ['eid' => 103, 'bid' => 1, 'body' => 'ROOT'],
        ['eid' => 203, 'bid' => 1, 'body' => 'OTHER BLOG'],
        ['eid' => 101, 'bid' => 1, 'body' => 'ALREADY INSERTED'],
    ]];
    $hook->afterV2GetFire($response, $v2);
    same('[auto:1:102:center]CHILD', $response['items'][0]['body'], 'V2 child placement matches V1 semantics');
    same('[auto:1:103:center]ROOT[auto:1:103:right]', $response['items'][1]['body'], 'V2 supports different top and bottom alignment');
    same('[auto:2:203:right]OTHER BLOG', $response['items'][2]['body'], 'V2 resolves settings from each entry blog');
    same('ALREADY INSERTED', $response['items'][3]['body'], 'V1 and V2 share duplicate prevention');
    same('[manual:1:101:left]', $response['items'][3]['dfLikeButton'], 'manual button remains available after automatic insertion');
    $hook->afterV2GetFire($response, $v2);
    same('[auto:1:103:center]ROOT[auto:1:103:right]', $response['items'][1]['body'], 'repeated V2 hooks do not duplicate buttons');

    mapConfig([1 => ['top' => 'none', 'bottom' => 'none']]);
    $response = ['items' => [['eid' => 103, 'body' => 'HIDDEN']]];
    $hook->afterV2GetFire($response, $v2);
    same('HIDDEN', $response['items'][0]['body'], 'category can hide both automatic buttons');
    same('[manual:1:103:left]', $response['items'][0]['dfLikeButton'], 'category hiding does not affect manual tags');

    mapConfig($overrides);
    DB::$config[1]['df_like_auto_insert'] = 'disabled';
    $body = 'AUTO OFF';
    $hook->afterGetFire($body, $v1);
    same('AUTO OFF', $body, 'blog automatic insertion toggle remains the V1 master switch');
    $response = ['items' => [['eid' => 103, 'body' => 'AUTO OFF']]];
    $hook->afterV2GetFire($response, $v2);
    same('AUTO OFF', $response['items'][0]['body'], 'blog automatic insertion toggle remains the V2 master switch');
    same('[manual:1:103:left]', $response['items'][0]['dfLikeButton'], 'manual tags remain available with auto insertion disabled');
    DB::$enabled[1] = false;
    $response = ['items' => [['eid' => 103, 'body' => 'APP OFF']]];
    $hook->afterV2GetFire($response, $v2);
    same('APP OFF', $response['items'][0]['body'], 'disabled blog app suppresses automatic insertion');
    check(!isset($response['items'][0]['dfLikeButton']), 'disabled blog app does not expose a manual button');

    $post = new DFLikeCategoryOptions();
    $result = $post->post();
    same('success', $result['status'], 'category options are accessible to administrators');
    check(!in_array(10, array_column($result['categories'], 'id'), true), 'category options exclude other blogs');
    check(in_array(2, array_column($result['categories'], 'id'), true), 'category options include closed categories');
    check(in_array(21, array_column($result['categories'], 'id'), true), 'category options include ancestor global categories');
    foreach ([22, 24, 25] as $excludedId) {
        check(!in_array($excludedId, array_column($result['categories'], 'id'), true), 'category options exclude unavailable category ' . $excludedId);
    }
    same(['id' => 1, 'name' => '<News & Events>', 'parent_id' => 0], $result['categories'][0], 'category API preserves names and returns typed IDs');
    $admin = false;
    same('failure', $post->post()['status'], 'category options reject unauthorized callers');
    $role = true;
    same('failure', $post->post()['status'], 'role user without config permission is rejected');
    $configPermission = true;
    same('success', $post->post()['status'], 'role user with config edit permission is accepted');
    DB::$failCategories = true;
    same('failure', $post->post()['status'], 'category query failure returns a useful failure response');

    echo 'PASS: ' . $checks . " category placement, legacy, hook, and API checks.\n";
}
