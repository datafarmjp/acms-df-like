<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeBlogContext;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
use Acms\Plugins\DF_Like\Services\LikeNotification;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeToggle extends ACMS_POST
{
    public $isCacheDelete = false;
    protected $isCSRF = false;

    public function post()
    {
        try {
            $objectType = $this->choice((string)$this->Post->get('object_type'), ['entry'], 'entry');
            $entryId = max(0, (int)$this->Post->get('entry_id'));
            $blogId = LikeBlogContext::entryBlogId($entryId, max(0, (int)$this->Post->get('blog_id')));
            $objectId = $entryId > 0 ? (string)$entryId : trim((string)$this->Post->get('object_id'));
            if ($objectId === '') {
                Common::responseJson(['status' => 'failure', 'message' => 'いいね対象が指定されていません。']);
            }
            if (!LikeBlogContext::isAppEnabled($blogId)) {
                Common::responseJson(['status' => 'failure', 'message' => 'このブログではいいね拡張アプリが有効ではありません。']);
            }

            $visitorId = LikeRepository::issueVisitorId();
            $visitorHash = hash('sha256', $visitorId);
            $ipHash = hash('sha256', $this->clientIp());
            $uaHash = hash('sha256', isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '');
            $userId = defined('SUID') && (int)SUID > 0 ? (int)SUID : null;

            $result = LikeRepository::toggle([
                'blog_id' => $blogId,
                'entry_id' => $entryId,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'user_id' => $userId,
            ], $visitorHash, $ipHash, $uaHash);

            if (($result['action'] ?? '') === 'like') {
                $notification = LikeNotification::sendOnLike([
                    'blog_id' => $blogId,
                    'entry_id' => $entryId,
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'count' => (int)($result['count'] ?? 0),
                    'referer' => isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '',
                ]);
                if ($notification) {
                    $notificationContext = (array)($notification['notification_context'] ?? []);
                    $result = array_merge($result, $notification);
                    unset($result['notification_context']);
                    if (($notification['notification_status'] ?? '') !== 'success') {
                        LikeErrorLogger::log('notification', (string)($notification['notification_message'] ?? '通知に失敗しました。'), [
                            'blog_id' => $blogId,
                            'entry_id' => $entryId,
                            'object_type' => $objectType,
                            'object_id' => $objectId,
                        ] + $notificationContext);
                    }
                }
            }

            Common::responseJson(array_merge(['status' => 'success'], $result));
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), '短時間にいいね操作が集中') === false) {
                LikeErrorLogger::log('toggle', $e->getMessage(), [
                    'blog_id' => LikeBlogContext::entryBlogId(max(0, (int)$this->Post->get('entry_id')), max(0, (int)$this->Post->get('blog_id'))),
                    'entry_id' => max(0, (int)$this->Post->get('entry_id')),
                    'object_type' => (string)$this->Post->get('object_type'),
                    'object_id' => (string)$this->Post->get('object_id'),
                ]);
            }
            Common::responseJson(['status' => 'failure', 'message' => $e->getMessage()]);
        }
    }

    private function choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function clientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    }
}
