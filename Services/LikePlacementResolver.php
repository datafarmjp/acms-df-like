<?php

namespace Acms\Plugins\DF_Like\Services;

class LikePlacementResolver
{
    /** @var array<int,array{defaults:array{top:string,bottom:string},categories:array}> */
    private static array $settings = [];

    /** @var array<int,array<int,list<int>>> */
    private static array $categoryPaths = [];

    /**
     * Resolve automatic placement from the entry's main category, nearest parent,
     * and finally its blog. The two positions inherit independently.
     *
     * @return array{top:string,bottom:string}
     */
    public static function resolve(int $entryId, int $blogId): array
    {
        $blogId = LikeBlogContext::entryBlogId($entryId, $blogId);
        $settings = self::settings($blogId);
        $placements = $settings['defaults'];
        if (!$settings['categories'] || $entryId <= 0 || !class_exists('\ACMS_RAM')) {
            return $placements;
        }

        try {
            $categoryId = (int)\ACMS_RAM::entryCategory($entryId);
            $path = self::categoryPath($categoryId, $blogId);
        } catch (\Throwable $e) {
            return $placements;
        }

        foreach (['top', 'bottom'] as $position) {
            foreach ($path as $id) {
                $value = $settings['categories'][$id][$position] ?? 'inherit';
                if ($value !== 'inherit') {
                    $placements[$position] = $value;
                    break;
                }
            }
        }
        return $placements;
    }

    private static function settings(int $blogId): array
    {
        if (isset(self::$settings[$blogId])) {
            return self::$settings[$blogId];
        }

        $legacy = LikeBlogContext::storedConfigValue('df_like_auto_insert_position', $blogId) ?: 'after';
        $defaults = [];
        foreach (['top', 'bottom'] as $position) {
            $value = LikeBlogContext::storedConfigValue('df_like_auto_insert_' . $position, $blogId) ?? '';
            if ($value !== '') {
                $defaults[$position] = in_array($value, ['none', 'left', 'center', 'right'], true)
                    ? $value
                    : ($position === 'top' ? 'none' : 'left');
            } else {
                $defaults[$position] = $position === 'top'
                    ? (in_array($legacy, ['before', 'both'], true) ? 'left' : 'none')
                    : ($legacy === 'before' ? 'none' : 'left');
            }
        }

        $raw = LikeBlogContext::storedConfigValue('df_like_category_placements', $blogId) ?? '';
        $decoded = json_decode($raw);
        $categories = [];
        if ($decoded instanceof \stdClass) {
            foreach (get_object_vars($decoded) as $categoryId => $row) {
                if (!preg_match('/^[1-9][0-9]*$/', (string)$categoryId) || !$row instanceof \stdClass) {
                    continue;
                }
                $id = filter_var($categoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    continue;
                }
                foreach (['top', 'bottom'] as $position) {
                    $value = $row->{$position} ?? 'inherit';
                    $categories[$id][$position] = in_array($value, ['inherit', 'none', 'left', 'center', 'right'], true)
                        ? $value
                        : 'inherit';
                }
            }
        }

        return self::$settings[$blogId] = ['defaults' => $defaults, 'categories' => $categories];
    }

    /**
     * Only categories available to the entry blog participate. Ancestor blogs
     * may share global categories; their placement settings remain blog-local.
     * An unavailable parent ends inheritance, while a cycle invalidates it.
     *
     * @return list<int>
     */
    private static function categoryPath(int $categoryId, int $blogId): array
    {
        if ($categoryId <= 0 || $blogId <= 0) {
            return [];
        }
        if (isset(self::$categoryPaths[$blogId][$categoryId])) {
            return self::$categoryPaths[$blogId][$categoryId];
        }

        $path = [];
        $seen = [];
        $id = $categoryId;
        while ($id > 0) {
            if (isset($seen[$id])) {
                return self::$categoryPaths[$blogId][$categoryId] = [];
            }
            if (!self::categoryAvailable($id, $blogId)) {
                break;
            }
            $seen[$id] = true;
            $path[] = $id;
            $id = (int)\ACMS_RAM::categoryParent($id);
            if ($id < 0) {
                return self::$categoryPaths[$blogId][$categoryId] = [];
            }
        }
        return self::$categoryPaths[$blogId][$categoryId] = $path;
    }

    private static function categoryAvailable(int $categoryId, int $blogId): bool
    {
        $ownerId = (int)\ACMS_RAM::categoryBlog($categoryId);
        if ($ownerId === $blogId) {
            return true;
        }
        if ($ownerId <= 0 || \ACMS_RAM::categoryScope($categoryId) !== 'global') {
            return false;
        }
        $left = (int)\ACMS_RAM::blogLeft($blogId);
        $right = (int)\ACMS_RAM::blogRight($blogId);
        $ownerLeft = (int)\ACMS_RAM::blogLeft($ownerId);
        $ownerRight = (int)\ACMS_RAM::blogRight($ownerId);
        return $left > 0 && $right > $left && $ownerLeft > 0
            && $ownerLeft < $left && $ownerRight > $right;
    }
}
