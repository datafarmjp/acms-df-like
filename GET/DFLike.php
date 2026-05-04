<?php

namespace Acms\Plugins\DF_Like\GET;

use ACMS_GET;
use Acms\Plugins\DF_Like\Services\LikeButtonRenderer;

class DFLike extends ACMS_GET
{
    public function get()
    {
        $entryId = $this->entryId();
        $blogId = \Acms\Plugins\DF_Like\Services\LikeBlogContext::entryBlogId($entryId, defined('BID') ? (int)BID : 0);
        $tpl = trim((string)$this->tpl);

        if ($tpl === '') {
            return LikeButtonRenderer::renderDefault($entryId, $blogId, 'left', 'manual');
        }

        return LikeButtonRenderer::render($entryId, $blogId, $tpl, 'left', 'manual');
    }

    private function entryId(): int
    {
        if (isset($this->eid) && (int)$this->eid > 0) {
            return (int)$this->eid;
        }
        if (defined('EID') && (int)EID > 0) {
            return (int)EID;
        }
        if ($this->Get && (int)$this->Get->get('eid') > 0) {
            return (int)$this->Get->get('eid');
        }
        if (isset($_GET['eid'])) {
            return (int)$_GET['eid'];
        }
        return 0;
    }
}
