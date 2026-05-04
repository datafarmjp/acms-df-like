<?php

namespace Acms\Plugins\DF_Like;

class Bootstrap
{
    public static function registerAutoloader()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(function ($class) {
            $prefix = 'Acms\\Plugins\\DF_Like\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}
