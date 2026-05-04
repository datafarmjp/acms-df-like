<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeEntryCounts extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => 'いいね数を確認する権限がありません。']);
            }

            Common::responseJson([
                'status' => 'success',
                'counts' => LikeRepository::entryCounts($this->Post->getArray('entry_ids')),
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
            return function_exists('roleAuthorization') && (roleAuthorization('entry_view', BID) || roleAuthorization('config_edit', BID));
        }
        return false;
    }
}
