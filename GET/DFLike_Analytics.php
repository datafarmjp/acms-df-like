<?php

namespace Acms\Plugins\DF_Like\GET;

use ACMS_GET;
use Acms\Plugins\DF_Like\Services\LikeAnalyticsRenderer;

class DFLike_Analytics extends ACMS_GET
{
    public function get()
    {
        return LikeAnalyticsRenderer::renderHtml();
    }
}
