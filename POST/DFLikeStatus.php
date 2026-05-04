<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeBlogContext;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeStatus extends ACMS_POST
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
                return;
            }
            if (!LikeBlogContext::isAppEnabled($blogId)) {
                Common::responseJson(['status' => 'failure', 'message' => 'このブログではいいね拡張アプリが有効ではありません。']);
                return;
            }

            $visitorHash = LikeRepository::visitorHashFromCookie();
            Common::responseJson([
                'status' => 'success',
                'liked' => $visitorHash !== '' ? LikeRepository::liked($objectType, $objectId, $visitorHash) : false,
                'count' => LikeRepository::count($objectType, $objectId),
            ]);
        } catch (\Throwable $e) {
            Common::responseJson(['status' => 'failure', 'message' => $e->getMessage()]);
        }
    }

    private function choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
