<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeBlogContext
{
    public static function entryBlogId(int $entryId, int $fallbackBlogId = 0): int
    {
        if ($entryId > 0 && class_exists('\ACMS_RAM') && method_exists('\ACMS_RAM', 'entryBlog')) {
            $blogId = (int)\ACMS_RAM::entryBlog($entryId);
            if ($blogId > 0) {
                return $blogId;
            }
        }
        if ($fallbackBlogId > 0) {
            return $fallbackBlogId;
        }
        return defined('BID') ? (int)BID : 0;
    }

    public static function isAppEnabled(int $blogId): bool
    {
        if ($blogId <= 0) {
            return false;
        }
        try {
            $status = \DB::query([
                'sql' => 'SELECT app_status FROM `' . LikeRepository::table('app') . '`
                    WHERE app_name = :name
                    AND app_blog_id = :bid
                    LIMIT 1',
                'params' => [
                    'name' => 'Acms\\Plugins\\DF_Like\\ServiceProvider',
                    'bid' => $blogId,
                ],
            ], 'one');
            return (string)$status === 'on';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function configValue(string $key, string $fallback, int $blogId): string
    {
        $value = self::storedConfigValue($key, $blogId);
        if ($value !== null && trim($value) !== '') {
            return $value;
        }
        return $fallback;
    }

    public static function storedConfigValue(string $key, int $blogId): ?string
    {
        if ($blogId <= 0) {
            return null;
        }
        try {
            $value = \DB::query([
                'sql' => 'SELECT config_value FROM `' . LikeRepository::table('config') . '`
                    WHERE config_blog_id = :bid
                    AND config_key = :key
                    LIMIT 1',
                'params' => [
                    'bid' => $blogId,
                    'key' => $key,
                ],
            ], 'one');
            return $value === false || $value === null ? null : (string)$value;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
