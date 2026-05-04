<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeRepository;

class DFLikeCsvDownload extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        if (!$this->canUseAdminPost()) {
            $this->sendCsv('df-like-error.csv', [['error' => 'いいね解析をダウンロードする権限がありません。']]);
        }

        $type = (string)$this->Post->get('type');
        $filters = LikeRepository::filtersFromInput(
            (string)$this->Post->get('start_date'),
            (string)$this->Post->get('end_date'),
            LikeRepository::analyticsScope(BID),
            BID,
            (string)$this->Post->get('keyword')
        );
        $rows = LikeRepository::csvRows($type === 'history' ? 'history' : 'ranking', $filters);
        $this->sendCsv('df-like-' . ($type === 'history' ? 'history' : 'ranking') . '-' . date('YmdHis') . '.csv', $rows);
    }

    private function sendCsv(string $filename, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');
        if ($fp && isset($rows[0])) {
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
        }
        exit;
    }

    private function canUseAdminPost(): bool
    {
        if (function_exists('sessionWithAdministration') && sessionWithAdministration(BID)) {
            return true;
        }
        if (function_exists('roleAvailableUser') && roleAvailableUser()) {
            return function_exists('roleAuthorization') && roleAuthorization('config_edit', BID);
        }
        return false;
    }
}
