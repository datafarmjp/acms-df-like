<?php

namespace Acms\Plugins\DF_Like\Services;

class LazyLikeAnalyticsHtml
{
    private $html;

    public function __toString(): string
    {
        if ($this->html === null) {
            try {
                $this->html = LikeAnalyticsRenderer::renderHtml();
            } catch (\Throwable $e) {
                $this->html = '';
            }
        }
        return $this->html;
    }
}
