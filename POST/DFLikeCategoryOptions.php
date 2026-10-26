<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeCategoryOptions extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            $blogId = defined('BID') ? (int)BID : 0;
            if ($blogId <= 0 || !$this->canUseAdminPost($blogId)) {
                return Common::responseJson(['status' => 'failure', 'message' => 'カテゴリー一覧を取得する権限がありません。']);
            }
            // Match the CMS category selector: own categories plus categories
            // shared globally by ancestor blogs, including closed categories.
            $left = (int)\ACMS_RAM::blogLeft($blogId);
            $right = (int)\ACMS_RAM::blogRight($blogId);
            $where = 'category_blog_id = :bid';
            $params = ['bid' => $blogId];
            if ($left > 0 && $right > $left) {
                $where = '(' . $where . ' OR (category_scope = :scope AND blog_left > 0 AND blog_left < :left AND blog_right > :right))';
                $params += ['scope' => 'global', 'left' => $left, 'right' => $right];
            }
            $rows = \DB::query([
                'sql' => 'SELECT category_id, category_name, category_parent
                    FROM `' . LikeRepository::table('category') . '`
                    LEFT JOIN `' . LikeRepository::table('blog') . '` ON blog_id = category_blog_id
                    WHERE ' . $where . '
                    ORDER BY blog_left ASC, category_left ASC, category_sort ASC, category_id ASC',
                'params' => $params,
            ], 'all');
            if (!is_array($rows)) {
                throw new \RuntimeException('カテゴリー一覧を取得できませんでした。');
            }
            return Common::responseJson([
                'status' => 'success',
                'categories' => array_map(static function (array $row): array {
                    return [
                        'id' => (int)$row['category_id'],
                        'name' => (string)$row['category_name'],
                        'parent_id' => (int)$row['category_parent'],
                    ];
                }, $rows),
            ]);
        } catch (\Throwable $e) {
            return Common::responseJson(['status' => 'failure', 'message' => $e->getMessage()]);
        }
    }

    private function canUseAdminPost(int $blogId): bool
    {
        if (function_exists('sessionWithAdministration') && sessionWithAdministration($blogId)) {
            return true;
        }
        if (function_exists('roleAvailableUser') && roleAvailableUser()) {
            return function_exists('roleAuthorization') && roleAuthorization('config_edit', $blogId);
        }
        return false;
    }
}
