<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
use Acms\Plugins\DF_Like\Services\LikeNotification;
use Acms\Services\Facades\Common;

class DFLikeNotificationTest extends ACMS_POST
{
    public $isCacheDelete = false;

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => '通知テストを実行する権限がありません。']);
            }

            $blogId = defined('BID') ? (int)BID : 0;
            $formId = max(0, (int)$this->Post->get('form_id'));
            if ($formId <= 0) {
                Common::responseJson(['status' => 'failure', 'message' => '通知フォームを選択してください。']);
            }

            $notification = LikeNotification::sendTest($blogId, $formId);
            $context = (array)($notification['notification_context'] ?? []);
            if (($notification['notification_status'] ?? '') !== 'success') {
                LikeErrorLogger::log('notification', (string)($notification['notification_message'] ?? '通知テストに失敗しました。'), [
                    'blog_id' => $blogId,
                    'entry_id' => 0,
                    'object_type' => 'entry',
                    'object_id' => 'test',
                    'test' => true,
                ] + $context);
            }

            Common::responseJson([
                'status' => ($notification['notification_status'] ?? '') === 'success' ? 'success' : 'failure',
                'message' => (string)($notification['notification_message'] ?? ''),
                'notification_status' => (string)($notification['notification_status'] ?? ''),
                'diagnostics' => $context,
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
