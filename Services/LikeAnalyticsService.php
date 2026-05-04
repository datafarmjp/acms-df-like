<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeAnalyticsService
{
    public static function adminData(int $limit = 50, ?array $filters = null, int $historyPage = 1, int $historyPerPage = 50): array
    {
        LikeRepository::ensureTables();
        $limit = max(1, min(200, $limit));
        $historyPerPage = max(1, min(200, $historyPerPage));
        $historyPage = max(1, $historyPage);
        $filters = $filters ?: LikeRepository::filtersFromInput('', '');
        [$logWhere, $logParams] = self::logFilterWhere($filters, 'log');
        [$likeWhere, $likeParams] = self::likeFilterWhere($filters, 'liked');
        $hasPeriod = $filters['start_date'] !== '' || $filters['end_date'] !== '';

        $summary = \DB::query([
            'sql' => 'SELECT
                COUNT(*) AS active_likes,
                COUNT(DISTINCT like_object_type, like_object_id) AS liked_targets
                FROM `' . LikeRepository::table('df_like') . '` AS liked' . $likeWhere,
            'params' => $likeParams,
        ], 'row') ?: [];

        $events = \DB::query([
            'sql' => 'SELECT
                COUNT(*) AS total_events,
                SUM(CASE WHEN log_action = \'like\' THEN 1 ELSE 0 END) AS like_events,
                SUM(CASE WHEN log_action = \'unlike\' THEN 1 ELSE 0 END) AS unlike_events,
                SUM(CASE WHEN log_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS events_7d,
                SUM(CASE WHEN log_action = \'like\' AND log_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS likes_7d
                FROM `' . LikeRepository::table('df_like_log') . '` AS log' . $logWhere,
            'params' => $logParams,
        ], 'row') ?: [];

        $ranking = self::rankingRows($filters, $limit, $hasPeriod);
        [$historyWhere, $historyParams] = self::historyKeywordWhere($logWhere, $logParams, $filters);
        $historyTotal = (int)\DB::query([
            'sql' => 'SELECT COUNT(*) FROM `' . LikeRepository::table('df_like_log') . '` AS log
                LEFT JOIN `' . LikeRepository::table('entry') . '` AS entry ON entry.entry_id = log.log_entry_id
                ' . $historyWhere,
            'params' => $historyParams,
        ], 'one');
        $historyTotalPages = max(1, (int)ceil($historyTotal / $historyPerPage));
        $historyPage = min($historyPage, $historyTotalPages);
        $historyOffset = ($historyPage - 1) * $historyPerPage;

        $history = \DB::query([
            'sql' => 'SELECT log.log_id, log.log_blog_id, log.log_entry_id, log.log_object_type, log.log_object_id, log.log_action, log.log_user_id, log.log_visitor_hash, log.log_referer, log.log_created_at, entry.entry_title
                FROM `' . LikeRepository::table('df_like_log') . '` AS log
                LEFT JOIN `' . LikeRepository::table('entry') . '` AS entry ON entry.entry_id = log.log_entry_id
                ' . $historyWhere . '
                ORDER BY log_created_at DESC, log_id DESC
                LIMIT ' . $historyPerPage . ' OFFSET ' . $historyOffset,
            'params' => $historyParams,
        ], 'all') ?: [];

        [$dailyWhere, $dailyParams] = $logWhere
            ? [$hasPeriod ? $logWhere : $logWhere . ' AND log.log_created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)', $logParams]
            : ['WHERE log.log_created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)', []];
        $daily = \DB::query([
            'sql' => 'SELECT DATE(log.log_created_at) AS date,
                SUM(CASE WHEN log_action = \'like\' THEN 1 ELSE 0 END) AS likes,
                SUM(CASE WHEN log_action = \'unlike\' THEN 1 ELSE 0 END) AS unlikes,
                COUNT(*) AS total
                FROM `' . LikeRepository::table('df_like_log') . '` AS log
                ' . $dailyWhere . '
                GROUP BY DATE(log.log_created_at)
                ORDER BY date ASC',
            'params' => $dailyParams,
        ], 'all') ?: [];

        return [
            'summary' => [
                'active_likes' => (int)($summary['active_likes'] ?? 0),
                'liked_targets' => (int)($summary['liked_targets'] ?? 0),
                'total_events' => (int)($events['total_events'] ?? 0),
                'like_events' => (int)($events['like_events'] ?? 0),
                'unlike_events' => (int)($events['unlike_events'] ?? 0),
                'events_7d' => (int)($events['events_7d'] ?? 0),
                'likes_7d' => (int)($events['likes_7d'] ?? 0),
            ],
            'ranking' => array_map([self::class, 'normalizeRow'], $ranking),
            'history' => array_map([self::class, 'normalizeRow'], $history),
            'history_total' => $historyTotal,
            'history_page' => $historyPage,
            'history_per_page' => $historyPerPage,
            'history_total_pages' => $historyTotalPages,
            'daily' => array_map([self::class, 'normalizeRow'], $daily),
            'errors' => array_map([self::class, 'normalizeRow'], LikeErrorLogger::recent(20, $filters['blog_ids'] ?? [])),
            'filters' => $filters,
        ];
    }

    public static function csvRows(string $type, array $filters): array
    {
        if ($type === 'history') {
            [$where, $params] = self::logFilterWhere($filters, 'log');
            [$where, $params] = self::historyKeywordWhere($where, $params, $filters);
            $rows = \DB::query([
                'sql' => 'SELECT log.log_created_at AS created_at, log.log_action AS action, log.log_blog_id AS blog_id, log.log_entry_id AS entry_id, entry.entry_title AS entry_title, log.log_object_type AS object_type, log.log_object_id AS object_id, log.log_user_id AS user_id, log.log_referer AS referer
                    FROM `' . LikeRepository::table('df_like_log') . '` AS log
                    LEFT JOIN `' . LikeRepository::table('entry') . '` AS entry ON entry.entry_id = log.log_entry_id
                    ' . $where . '
                    ORDER BY log.log_created_at DESC, log.log_id DESC
                    LIMIT 5000',
                'params' => $params,
            ], 'all') ?: [];
            return array_map([self::class, 'csvNormalize'], $rows);
        }

        return array_map([self::class, 'csvNormalize'], self::rankingRows($filters, 5000, $filters['start_date'] !== '' || $filters['end_date'] !== ''));
    }

    public static function deleteLogs(array $filters): int
    {
        LikeRepository::ensureTables();
        [$where, $params] = self::logFilterWhere($filters, 'log');
        if ($where === '') {
            return 0;
        }
        $count = (int)\DB::query([
            'sql' => 'SELECT COUNT(*) FROM `' . LikeRepository::table('df_like_log') . '` AS log ' . $where,
            'params' => $params,
        ], 'one');
        \DB::query([
            'sql' => 'DELETE log FROM `' . LikeRepository::table('df_like_log') . '` AS log ' . $where,
            'params' => $params,
        ], 'exec');
        return $count;
    }

    private static function rankingRows(array $filters, int $limit, bool $periodMode): array
    {
        if ($periodMode) {
            [$where, $params] = self::logFilterWhere($filters, 'log');
            [$where, $params] = self::rankingKeywordWhere($where, $params, $filters, 'log', 'entry', true);
            return \DB::query([
                'sql' => 'SELECT log.log_blog_id AS like_blog_id, log.log_entry_id AS like_entry_id, log.log_object_type AS like_object_type, log.log_object_id AS like_object_id, entry.entry_title,
                        SUM(CASE WHEN log.log_action = \'like\' THEN 1 WHEN log.log_action = \'unlike\' THEN -1 ELSE 0 END) AS count
                    FROM `' . LikeRepository::table('df_like_log') . '` AS log
                    LEFT JOIN `' . LikeRepository::table('entry') . '` AS entry ON entry.entry_id = log.log_entry_id
                    ' . $where . '
                    GROUP BY log.log_blog_id, log.log_entry_id, log.log_object_type, log.log_object_id, entry.entry_title
                    HAVING count > 0
                    ORDER BY count DESC, log.log_entry_id DESC
                    LIMIT ' . $limit,
                'params' => $params,
            ], 'all') ?: [];
        }

        [$where, $params] = self::likeFilterWhere($filters, 'liked');
        [$where, $params] = self::rankingKeywordWhere($where, $params, $filters, 'liked', 'entry', false);
        return \DB::query([
            'sql' => 'SELECT liked.like_blog_id, liked.like_entry_id, liked.like_object_type, liked.like_object_id, entry.entry_title, COUNT(*) AS count
                FROM `' . LikeRepository::table('df_like') . '` AS liked
                LEFT JOIN `' . LikeRepository::table('entry') . '` AS entry ON entry.entry_id = liked.like_entry_id
                ' . $where . '
                GROUP BY liked.like_blog_id, liked.like_entry_id, liked.like_object_type, liked.like_object_id, entry.entry_title
                ORDER BY count DESC, liked.like_entry_id DESC
                LIMIT ' . $limit,
            'params' => $params,
        ], 'all') ?: [];
    }

    public static function blogFilterWhere(array $filters, string $alias, string $column): array
    {
        $blogIds = array_values(array_filter(array_map('intval', $filters['blog_ids'] ?? [])));
        if (!$blogIds) {
            return ['', []];
        }
        $placeholders = [];
        $params = [];
        foreach ($blogIds as $index => $id) {
            $key = 'blog_scope_' . $column . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        return [$alias . '.' . $column . ' IN (' . implode(',', $placeholders) . ')', $params];
    }

    private static function logFilterWhere(array $filters, string $alias): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['start_date'])) {
            $where[] = $alias . '.log_created_at >= :start_date';
            $params['start_date'] = $filters['start_date'] . ' 00:00:00';
        }
        if (!empty($filters['end_date'])) {
            $where[] = $alias . '.log_created_at <= :end_date';
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }
        [$blogWhere, $blogParams] = self::blogFilterWhere($filters, $alias, 'log_blog_id');
        if ($blogWhere !== '') {
            $where[] = $blogWhere;
            $params = array_merge($params, $blogParams);
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private static function likeFilterWhere(array $filters, string $alias): array
    {
        [$blogWhere, $params] = self::blogFilterWhere($filters, $alias, 'like_blog_id');
        return [$blogWhere ? ' WHERE ' . $blogWhere : '', $params];
    }

    private static function historyKeywordWhere(string $where, array $params, array $filters): array
    {
        $keyword = self::keyword($filters);
        if ($keyword === '') {
            return [$where, $params];
        }
        $keys = self::keywordParams($params, 'keyword_history', $keyword, 6);
        $condition = '(entry.entry_title LIKE :' . $keys[0] .
            ' OR CAST(log.log_entry_id AS CHAR) LIKE :' . $keys[1] .
            ' OR CAST(log.log_object_id AS CHAR) LIKE :' . $keys[2] .
            ' OR CONCAT(log.log_object_type, \':\', log.log_object_id) LIKE :' . $keys[3] .
            ' OR log.log_referer LIKE :' . $keys[4] .
            ' OR log.log_visitor_hash LIKE :' . $keys[5] . ')';
        return [self::appendWhere($where, $condition), $params];
    }

    private static function rankingKeywordWhere(string $where, array $params, array $filters, string $alias, string $entryAlias, bool $isLog): array
    {
        $keyword = self::keyword($filters);
        if ($keyword === '') {
            return [$where, $params];
        }
        $keys = self::keywordParams($params, 'keyword_ranking_' . $alias, $keyword, 4);
        $entryColumn = $isLog ? $alias . '.log_entry_id' : $alias . '.like_entry_id';
        $objectTypeColumn = $isLog ? $alias . '.log_object_type' : $alias . '.like_object_type';
        $objectIdColumn = $isLog ? $alias . '.log_object_id' : $alias . '.like_object_id';
        $condition = '(' . $entryAlias . '.entry_title LIKE :' . $keys[0] .
            ' OR CAST(' . $entryColumn . ' AS CHAR) LIKE :' . $keys[1] .
            ' OR CAST(' . $objectIdColumn . ' AS CHAR) LIKE :' . $keys[2] .
            ' OR CONCAT(' . $objectTypeColumn . ', \':\', ' . $objectIdColumn . ') LIKE :' . $keys[3] . ')';
        return [self::appendWhere($where, $condition), $params];
    }

    private static function keywordParams(array &$params, string $prefix, string $keyword, int $count): array
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $prefix . '_' . $i;
            $params[$key] = '%' . $keyword . '%';
            $keys[] = $key;
        }
        return $keys;
    }

    private static function appendWhere(string $where, string $condition): string
    {
        return $where === '' ? ' WHERE ' . $condition : $where . ' AND ' . $condition;
    }

    private static function keyword(array $filters): string
    {
        return trim((string)($filters['keyword'] ?? ''));
    }

    private static function csvNormalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = '';
            }
        }
        return $row;
    }

    private static function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_numeric($value) && preg_match('/(^|_)(id|count|likes|unlikes|total|events|7d)$/', $key)) {
                $row[$key] = (int)$value;
            }
        }
        if (isset($row['log_visitor_hash'])) {
            $row['visitor'] = substr((string)$row['log_visitor_hash'], 0, 10);
            unset($row['log_visitor_hash']);
        }
        $entryId = (int)($row['log_entry_id'] ?? $row['like_entry_id'] ?? $row['entry_id'] ?? 0);
        $blogId = (int)($row['log_blog_id'] ?? $row['like_blog_id'] ?? $row['blog_id'] ?? 0);
        $row['entry_url'] = self::entryUrl($blogId, $entryId);
        return $row;
    }

    private static function entryUrl(int $blogId, int $entryId): string
    {
        if ($blogId <= 0 || $entryId <= 0 || !function_exists('acmsLink')) {
            return '';
        }
        return (string)acmsLink(['bid' => $blogId, 'eid' => $entryId]);
    }
}
