<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeAdminData extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => 'いいね解析を確認する権限がありません。']);
            }

            $filters = LikeRepository::filtersFromInput(
                (string)$this->Post->get('start_date'),
                (string)$this->Post->get('end_date'),
                LikeRepository::analyticsScope(BID),
                BID,
                (string)$this->Post->get('keyword')
            );

            Common::responseJson([
                'status' => 'success',
                'data' => LikeRepository::adminData(
                    (int)$this->Post->get('limit') ?: 50,
                    $filters,
                    (int)$this->Post->get('history_page') ?: 1,
                    (int)$this->Post->get('history_per_page') ?: 50
                ),
                'hook_enabled' => defined('HOOK_ENABLE') && (bool)HOOK_ENABLE,
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
