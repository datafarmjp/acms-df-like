<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeDeleteLike extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => 'いいねを削除する権限がありません。']);
            }
            if ((string)$this->Post->get('confirm') !== '1') {
                Common::responseJson(['status' => 'failure', 'message' => '削除確認が必要です。']);
            }

            Common::responseJson(array_merge(
                ['status' => 'success'],
                LikeRepository::deleteLikeByLogId(
                    (int)$this->Post->get('log_id'),
                    LikeRepository::filtersFromInput('', '', LikeRepository::analyticsScope(BID), BID)
                )
            ));
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
