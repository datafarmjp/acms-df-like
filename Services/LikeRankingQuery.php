<?php

namespace Acms\Plugins\DF_Like\Services;

class LikeRankingQuery
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function rows(int $blogId, $limit = 5, string $period = 'all', string $start = '', string $end = ''): array
    {
        $limit = self::limit($limit);
        [$startDate, $endDate] = self::dateRange($period, $start, $end);
        $periodMode = $startDate !== '' || $endDate !== '';
        $filters = LikeRepository::filtersFromInput($startDate, $endDate, LikeRepository::analyticsScope($blogId), $blogId);

        return LikeAnalyticsService::publicRankingRows($filters, $limit, $periodMode);
    }

    private static function limit($value): int
    {
        $limit = (int)$value > 0 ? (int)$value : 5;
        return max(1, min(50, $limit));
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function dateRange(string $period, string $start, string $end): array
    {
        $startDate = self::date($start);
        $endDate = self::date($end);
        if ($startDate !== '' || $endDate !== '') {
            return [$startDate, $endDate];
        }

        $today = date('Y-m-d');
        switch (self::period($period)) {
            case 'today':
                return [$today, $today];

            case '7d':
                return [date('Y-m-d', strtotime('-6 days')), $today];

            case '30d':
                return [date('Y-m-d', strtotime('-29 days')), $today];

            case 'all':
            default:
                return ['', ''];
        }
    }

    private static function period(string $period): string
    {
        $period = strtolower(trim($period));
        return in_array($period, ['all', 'today', '7d', '30d'], true) ? $period : 'all';
    }

    private static function date(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return '';
        }

        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) ? $value : '';
    }
}
