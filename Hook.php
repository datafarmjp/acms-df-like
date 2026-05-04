<?php

namespace Acms\Plugins\DF_Like;

use Acms\Plugins\DF_Like\Services\LikeButtonRenderer;
use Acms\Plugins\DF_Like\Services\LikeBlogContext;

class Hook
{
    /**
     * @param string $res
     * @param \ACMS_GET $thisModule
     * @return void
     */
    public function afterGetFire(&$res, $thisModule)
    {
        static $inserted = [];

        if (!$thisModule instanceof \ACMS_GET || !is_a($thisModule, 'ACMS_GET_Entry_Body')) {
            return;
        }
        if ((defined('ADMIN') && ADMIN)) {
            return;
        }
        $entryId = $this->entryId($thisModule);
        if ($entryId <= 0) {
            return;
        }
        $blogId = LikeBlogContext::entryBlogId($entryId, defined('BID') ? (int)BID : 0);
        if (!LikeBlogContext::isAppEnabled($blogId) || !$this->isAutoInsertEnabled($blogId)) {
            return;
        }
        $top = $this->autoInsertPlacement('top', $blogId);
        $bottom = $this->autoInsertPlacement('bottom', $blogId);
        $topKey = $blogId . ':' . $entryId . ':top';
        $bottomKey = $blogId . ':' . $entryId . ':bottom';
        $topButton = $top !== 'none' && !isset($inserted[$topKey])
            ? LikeButtonRenderer::renderDefault($entryId, $blogId, $top, 'auto')
            : '';
        $bottomButton = $bottom !== 'none' && !isset($inserted[$bottomKey])
            ? LikeButtonRenderer::renderDefault($entryId, $blogId, $bottom, 'auto')
            : '';
        if ($topButton === '' && $bottomButton === '') {
            return;
        }

        if ($topButton !== '') {
            $inserted[$topKey] = true;
        }
        if ($bottomButton !== '') {
            $inserted[$bottomKey] = true;
        }
        $res = $topButton . $res . $bottomButton;
    }

    private function entryId($thisModule): int
    {
        if (defined('EID') && (int)EID > 0) {
            return (int)EID;
        }
        if ($thisModule instanceof \ACMS_GET && isset($thisModule->eid) && (int)$thisModule->eid > 0) {
            return (int)$thisModule->eid;
        }
        return 0;
    }

    private function isAutoInsertEnabled(int $blogId): bool
    {
        return $this->configValue('df_like_auto_insert', $blogId) === 'enabled';
    }

    private function autoInsertPlacement(string $position, int $blogId): string
    {
        $value = $this->configValue('df_like_auto_insert_' . $position, $blogId);
        if ($value !== '') {
            return $this->placementChoice($value, $position === 'top' ? 'none' : 'left');
        }

        $legacy = $this->configValue('df_like_auto_insert_position', $blogId) ?: 'after';
        if ($position === 'top') {
            return in_array($legacy, ['before', 'both'], true) ? 'left' : 'none';
        }
        if ($legacy === 'before') {
            return 'none';
        }
        return in_array($legacy, ['after', 'both'], true) ? 'left' : 'left';
    }

    private function placementChoice(string $value, string $fallback): string
    {
        return in_array($value, ['none', 'left', 'center', 'right'], true) ? $value : $fallback;
    }

    private function configValue(string $key, int $blogId): string
    {
        return LikeBlogContext::storedConfigValue($key, $blogId) ?? '';
    }
}
