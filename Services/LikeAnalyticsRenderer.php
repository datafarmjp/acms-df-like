<?php

namespace Acms\Plugins\DF_Like\Services;

use Acms\Plugins\DF_Like\ServiceProvider;

class LikeAnalyticsRenderer
{
    public static function renderHtml(): string
    {
        $renderer = new self();
        return $renderer->render();
    }

    private function render(): string
    {
        $blogId = defined('BID') ? (int)BID : 0;
        $filters = LikeRepository::filtersFromInput(
            '',
            '',
            LikeRepository::analyticsScope($blogId),
            $blogId
        );
        $data = LikeRepository::adminData(10, $filters, 1, 20);

        return $this->assetTag()
            . '<section class="df-like-analytics">'
            . '<h2 id="df-like-analytics" class="df-like-analytics__title">いいね解析</h2>'
            . $this->summary($data['summary'] ?? [])
            . $this->daily($data['daily'] ?? [])
            . $this->ranking($data['ranking'] ?? [])
            . $this->history($data['history'] ?? [])
            . '</section>';
    }

    private function assetTag(): string
    {
        static $loaded = false;
        if ($loaded) {
            return '';
        }
        $loaded = true;

        return '<link rel="stylesheet" href="/extension/plugins/DF_Like/assets/df-like.css?v='
            . $this->escape(ServiceProvider::VERSION)
            . '">';
    }

    private function summary(array $summary): string
    {
        $items = [
            '現在のいいね' => $summary['active_likes'] ?? 0,
            '対象数' => $summary['liked_targets'] ?? 0,
            '7日間のいいね' => $summary['likes_7d'] ?? 0,
            '期間内イベント' => $summary['total_events'] ?? 0,
        ];

        $html = '<section class="df-like-analytics__section df-like-analytics__summary">'
            . '<h3 id="df-like-analytics-summary" class="df-like-analytics__heading">サマリー</h3>'
            . '<dl class="df-like-analytics__summary-list">';
        foreach ($items as $label => $value) {
            $html .= '<div class="df-like-analytics__summary-item">'
                . '<dt>' . $this->escape($label) . '</dt>'
                . '<dd>' . $this->escape((string)(int)$value) . '</dd>'
                . '</div>';
        }
        return $html . '</dl></section>';
    }

    private function daily(array $daily): string
    {
        $html = '<section class="df-like-analytics__section df-like-analytics__daily">'
            . '<h3 id="df-like-analytics-daily" class="df-like-analytics__heading">日別推移</h3>';
        if (!$daily) {
            return $html . '<p class="df-like-analytics__empty">日別データはまだありません。</p></section>';
        }

        $html .= '<table class="df-like-analytics__table">'
            . '<thead><tr><th>日付</th><th>いいね追加</th><th>イベント数</th></tr></thead><tbody>';
        foreach ($daily as $row) {
            $html .= '<tr>'
                . '<td>' . $this->escape($row['date'] ?? '') . '</td>'
                . '<td>' . $this->escape((string)(int)($row['likes'] ?? 0)) . '</td>'
                . '<td>' . $this->escape((string)(int)($row['total'] ?? 0)) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></section>';
    }

    private function ranking(array $ranking): string
    {
        $html = '<section class="df-like-analytics__section df-like-analytics__ranking">'
            . '<h3 id="df-like-analytics-ranking" class="df-like-analytics__heading">人気のエントリー</h3>';
        if (!$ranking) {
            return $html . '<p class="df-like-analytics__empty">いいねされたエントリーはまだありません。</p></section>';
        }

        $html .= '<table class="df-like-analytics__table">'
            . '<thead><tr><th>順位</th><th>記事</th><th>いいね数</th></tr></thead><tbody>';
        $rank = 0;
        $previousCount = null;
        foreach ($ranking as $index => $row) {
            $count = (int)($row['count'] ?? 0);
            if ($previousCount === null || $count !== $previousCount) {
                $rank = $index + 1;
            }
            $previousCount = $count;
            $html .= '<tr>'
                . '<td>' . $this->escape((string)$rank) . '</td>'
                . '<td>' . $this->entryLink($row) . '</td>'
                . '<td>' . $this->escape((string)$count) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></section>';
    }

    private function history(array $history): string
    {
        $html = '<section class="df-like-analytics__section df-like-analytics__history">'
            . '<h3 id="df-like-analytics-history" class="df-like-analytics__heading">いいね履歴</h3>';
        if (!$history) {
            return $html . '<p class="df-like-analytics__empty">履歴はまだありません。</p></section>';
        }

        $html .= '<table class="df-like-analytics__table">'
            . '<thead><tr><th>日時</th><th>操作</th><th>記事</th></tr></thead><tbody>';
        foreach ($history as $row) {
            $html .= '<tr>'
                . '<td>' . $this->escape($row['log_created_at'] ?? '') . '</td>'
                . '<td>' . $this->escape(($row['log_action'] ?? '') === 'unlike' ? '解除' : 'いいね') . '</td>'
                . '<td>' . $this->entryLink($row) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></section>';
    }

    private function entryLink(array $row): string
    {
        $title = (string)($row['entry_title'] ?? '');
        $entryId = (int)($row['like_entry_id'] ?? $row['log_entry_id'] ?? 0);
        if ($title === '') {
            $title = $entryId > 0 ? 'entry:' . $entryId : '-';
        }

        $url = (string)($row['entry_url'] ?? '');
        if ($url !== '') {
            return '<a href="' . $this->escape($url) . '" target="_blank" rel="noopener noreferrer">' . $this->escape($title) . '</a>';
        }
        return $this->escape($title);
    }

    private function escape(string $value): string
    {
        $charset = defined('APP_CHARSET') ? APP_CHARSET : 'UTF-8';
        return htmlspecialchars($value, ENT_QUOTES, $charset);
    }
}
