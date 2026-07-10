<?php

namespace Acms\Plugins\DF_Like;

use Acms\Plugins\DF_Like\Services\LikeButtonRenderer;
use Acms\Plugins\DF_Like\Services\LikeBlogContext;
use Acms\Plugins\DF_Like\Services\LazyLikeAnalyticsHtml;

class Hook
{
    private static $inserted = [];

    /**
     * @param string $res
     * @param \ACMS_GET $thisModule
     * @return void
     */
    public function afterGetFire(&$res, $thisModule)
    {
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

        $buttons = $this->autoInsertButtons($entryId, $blogId);
        if ($buttons['top'] === '' && $buttons['bottom'] === '') {
            return;
        }

        $res = $buttons['top'] . $res . $buttons['bottom'];
    }

    /**
     * @param array<string,mixed> $response
     * @param object $thisModule
     * @return void
     */
    public function afterV2GetFire(&$response, $thisModule)
    {
        if (!is_a($thisModule, 'Acms\Modules\Get\V2\Entry\Body')) {
            return;
        }
        if ((defined('ADMIN') && ADMIN)) {
            return;
        }
        if (!is_array($response)) {
            return;
        }

        $response['dfLikeAnalytics'] = new LazyLikeAnalyticsHtml();

        if (empty($response['items']) || !is_array($response['items'])) {
            return;
        }

        foreach ($response['items'] as &$entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryId = (int)($entry['eid'] ?? 0);
            if ($entryId <= 0) {
                continue;
            }
            $blogId = LikeBlogContext::entryBlogId($entryId, (int)($entry['bid'] ?? (defined('BID') ? (int)BID : 0)));
            if (!LikeBlogContext::isAppEnabled($blogId)) {
                continue;
            }

            $entry['dfLikeButton'] = LikeButtonRenderer::renderDefault($entryId, $blogId, 'left', 'manual');

            if (!$this->isAutoInsertEnabled($blogId)) {
                continue;
            }

            $buttons = $this->autoInsertButtons($entryId, $blogId);
            if ($buttons['top'] === '' && $buttons['bottom'] === '') {
                continue;
            }
            $entry['body'] = $buttons['top'] . (string)($entry['body'] ?? '') . $buttons['bottom'];
        }
        unset($entry);
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

    /**
     * @return array{top:string,bottom:string}
     */
    private function autoInsertButtons(int $entryId, int $blogId): array
    {
        $top = $this->autoInsertPlacement('top', $blogId);
        $bottom = $this->autoInsertPlacement('bottom', $blogId);
        $topKey = $blogId . ':' . $entryId . ':top';
        $bottomKey = $blogId . ':' . $entryId . ':bottom';

        $topButton = $top !== 'none' && !isset(self::$inserted[$topKey])
            ? LikeButtonRenderer::renderDefault($entryId, $blogId, $top, 'auto')
            : '';
        $bottomButton = $bottom !== 'none' && !isset(self::$inserted[$bottomKey])
            ? LikeButtonRenderer::renderDefault($entryId, $blogId, $bottom, 'auto')
            : '';

        if ($topButton !== '') {
            self::$inserted[$topKey] = true;
        }
        if ($bottomButton !== '') {
            self::$inserted[$bottomKey] = true;
        }

        return [
            'top' => $topButton,
            'bottom' => $bottomButton,
        ];
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
