<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeDeleteLogs extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => 'いいね履歴を削除する権限がありません。']);
            }
            if ((string)$this->Post->get('confirm') !== '1') {
                Common::responseJson(['status' => 'failure', 'message' => '削除確認が必要です。']);
            }

            $filters = LikeRepository::filtersFromInput(
                (string)$this->Post->get('start_date'),
                (string)$this->Post->get('end_date'),
                LikeRepository::analyticsScope(BID),
                BID
            );
            if (!$filters['start_date'] && !$filters['end_date']) {
                Common::responseJson(['status' => 'failure', 'message' => '削除する期間を指定してください。']);
            }

            Common::responseJson([
                'status' => 'success',
                'deleted_count' => LikeRepository::deleteLogs($filters),
            ]);
        } catch (\Throwable $e) {
            Common::responseJson(['status' => 'failure', 'message' => $e->getMessage()]);
        }
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
