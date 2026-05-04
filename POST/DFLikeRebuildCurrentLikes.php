<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeRebuildCurrentLikes extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => '現在いいねを再構築する権限がありません。']);
            }
            if ((string)$this->Post->get('confirm') !== '1') {
                Common::responseJson(['status' => 'failure', 'message' => '再構築確認が必要です。']);
            }

            $filters = LikeRepository::filtersFromInput(
                (string)$this->Post->get('start_date'),
                (string)$this->Post->get('end_date'),
                LikeRepository::analyticsScope(BID),
                BID
            );

            Common::responseJson(array_merge(
                ['status' => 'success'],
                LikeRepository::rebuildCurrentLikesFromLogs($filters)
            ));
        } catch (\Throwable $e) {
            LikeErrorLogger::log('rebuild', $e->getMessage(), [
                'blog_id' => defined('BID') ? BID : 0,
            ]);
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
