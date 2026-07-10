<?php

namespace Acms\Plugins\DF_Like\Modules\Get\V2;

use Acms\Modules\Get\V2\Base;
use Acms\Plugins\DF_Like\Services\LikeAnalyticsRenderer;

class DFLikeAnalytics extends Base
{
    public function get(): array
    {
        return [
            'html' => LikeAnalyticsRenderer::renderHtml(),
        ];
    }
}
