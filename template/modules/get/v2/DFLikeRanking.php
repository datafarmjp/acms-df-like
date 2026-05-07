<?php
/**
 * DF_Like managed V2 GET wrapper
 */

namespace {
    $dfLikeBootstrapPaths = [];
    if (defined('PLUGIN_LIB_DIR')) {
        $dfLikeBootstrapPaths[] = rtrim(PLUGIN_LIB_DIR, '/\\') . '/DF_Like/Bootstrap.php';
    }
    $dfLikeBootstrapPaths[] = dirname(__DIR__, 4) . '/Bootstrap.php';
    $dfLikeBootstrapPaths[] = dirname(__DIR__, 5) . '/extension/plugins/DF_Like/Bootstrap.php';

    foreach (array_unique($dfLikeBootstrapPaths) as $dfLikeBootstrapPath) {
        if (is_file($dfLikeBootstrapPath)) {
            require_once $dfLikeBootstrapPath;
            break;
        }
    }
    if (class_exists('\Acms\Plugins\DF_Like\Bootstrap')) {
        \Acms\Plugins\DF_Like\Bootstrap::registerAutoloader();
    }
}

namespace Acms\Custom\Modules\Get\V2 {
    class DFLikeRanking extends \Acms\Plugins\DF_Like\Modules\Get\V2\DFLikeRanking
    {
    }
}
