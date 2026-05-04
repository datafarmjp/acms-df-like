<?php
/**
 * DF_Like managed POST wrapper
 */

namespace {
    $dfLikeBootstrapPaths = [];
    if (defined('PLUGIN_LIB_DIR')) {
        $dfLikeBootstrapPaths[] = rtrim(PLUGIN_LIB_DIR, '/\\') . '/DF_Like/Bootstrap.php';
    }
    $dfLikeBootstrapPaths[] = dirname(__DIR__, 2) . '/Bootstrap.php';
    $dfLikeBootstrapPaths[] = dirname(__DIR__, 3) . '/extension/plugins/DF_Like/Bootstrap.php';

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

namespace Acms\Custom\POST {
    class DFLikeAdminData extends \Acms\Plugins\DF_Like\POST\DFLikeAdminData
    {
    }
}
