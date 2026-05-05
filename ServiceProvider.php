<?php

namespace Acms\Plugins\DF_Like;

use ACMS_App;
use Acms\Plugins\DF_Like\Bootstrap;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
use Acms\Plugins\DF_Like\Services\LikeSchemaService;
use Acms\Services\Common\HookFactory;

class ServiceProvider extends ACMS_App
{
    const VERSION = '0.7.30';

    private static $postWrappers = [
        'DFLikeToggle.php',
        'DFLikeStatus.php',
        'DFLikeAdminData.php',
        'DFLikeCsvDownload.php',
        'DFLikeDeleteLogs.php',
        'DFLikeDeleteLike.php',
        'DFLikeEntryCounts.php',
        'DFLikeFormOptions.php',
        'DFLikeImportCsv.php',
        'DFLikeRebuildCurrentLikes.php',
        'DFLikeDeleteErrors.php',
    ];

    private static $getWrappers = [
        'DFLike.php',
        'DFLikeAnalytics.php',
        'DFLike_Analytics.php',
    ];

    private $adminTemplateMarker = 'DF_Like managed admin app template';
    private $postWrapperMarker = 'DF_Like managed POST wrapper';
    private $getWrapperMarker = 'DF_Like managed GET wrapper';
    private $entryIndexAssetMarker = 'DF_Like managed entry index asset';
    private $minAdminTemplateBytes = 4096;
    private $minWrapperBytes = 300;

    public $version = self::VERSION;
    public $name = 'DFいいね';
    public $author = '株式会社データファーム';
    public $module = true;
    public $menu = 'df-like';
    public $desc = 'エントリーにいいねボタンを追加し、管理画面で履歴と簡易解析を確認できます。';

    public function init()
    {
        $this->boot(false);
        $this->attachHook();
    }

    public function checkRequirements()
    {
        return true;
    }

    public function install()
    {
        $this->boot(true);
    }

    public function uninstall()
    {
    }

    public function update()
    {
        $this->boot(true);
        return true;
    }

    public function activate()
    {
        $this->boot(true);
        return true;
    }

    public function deactivate()
    {
        return true;
    }

    public static function registerAutoloader()
    {
        require_once __DIR__ . '/Bootstrap.php';
        Bootstrap::registerAutoloader();
    }

    private function boot($withTables)
    {
        self::registerAutoloader();
        $this->syncManagedFiles();
        if ($withTables) {
            $this->createTables();
        }
    }

    private function syncManagedFiles()
    {
        $this->syncAdminTemplate();
        $this->syncEntryIndexAsset();
        $this->syncGetWrappers();
        $this->syncPostWrappers();
    }

    private function attachHook()
    {
        if (!class_exists('\Acms\Services\Common\HookFactory') || !class_exists('\Acms\Plugins\DF_Like\Hook')) {
            return;
        }
        HookFactory::singleton()->attach('DF_Like', new Hook());
    }

    private function syncAdminTemplate()
    {
        $source = PLUGIN_LIB_DIR . 'DF_Like/template/admin/app/df-like.html';
        $themesDir = defined('THEMES_DIR') ? THEMES_DIR : 'themes/';
        $dest = SCRIPT_DIR . ltrim($themesDir, '/') . 'system/admin/app/df-like.html';

        if (!$this->isSafeManagedSource($source, $this->adminTemplateMarker, '', $this->minAdminTemplateBytes, '管理画面テンプレート同期をスキップしました')) {
            return;
        }

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir)) {
            return;
        }

        if (is_file($dest)) {
            if (!is_writable($dest)) {
                $this->logSyncSkipped('管理画面テンプレート同期をスキップしました', $source, $dest, 'destination_not_writable');
                return;
            }
            $content = (string)@file_get_contents($dest);
            if ($content !== '' && strpos($content, $this->adminTemplateMarker) === false) {
                $this->logSyncSkipped('管理画面テンプレート同期をスキップしました', $source, $dest, 'destination_not_managed', strlen($content));
                return;
            }
        } elseif (!is_writable($dir)) {
            $this->logSyncSkipped('管理画面テンプレート同期をスキップしました', $source, $dest, 'destination_dir_not_writable');
            return;
        }

        if (!@copy($source, $dest)) {
            $this->logSyncSkipped('管理画面テンプレート同期に失敗しました', $source, $dest, 'copy_failed', @filesize($source));
        }
    }

    private function syncEntryIndexAsset()
    {
        $themesDir = defined('THEMES_DIR') ? THEMES_DIR : 'themes/';
        $dest = SCRIPT_DIR . ltrim($themesDir, '/') . 'system/admin/entry/index/v2.html';

        if (!is_file($dest) || !is_writable($dest)) {
            return;
        }

        $content = (string)@file_get_contents($dest);
        if ($content === '') {
            return;
        }

        $block = $this->entryIndexAssetBlock();
        if (strpos($block, $this->entryIndexAssetMarker) === false || strpos($block, 'df-like-entry-index.js') === false) {
            $this->logSyncSkipped('エントリー一覧アセット同期をスキップしました', '', $dest, 'invalid_asset_block', strlen($block));
            return;
        }
        if (strpos($content, $this->entryIndexAssetMarker) !== false) {
            $next = preg_replace(
                '/\n?<!-- ' . preg_quote($this->entryIndexAssetMarker, '/') . ' -->.*?<!-- \/' . preg_quote($this->entryIndexAssetMarker, '/') . ' -->\n?/s',
                "\n" . $block . "\n",
                $content,
                1
            );
        } elseif (preg_match('/\n@endsection\s*$/', $content)) {
            $next = preg_replace('/\n@endsection\s*$/', "\n" . $block . "\n@endsection\n", $content, 1);
        } else {
            $next = rtrim($content) . "\n\n" . $block . "\n";
        }

        if (is_string($next) && $next !== $content) {
            if (@file_put_contents($dest, $next) === false) {
                $this->logSyncSkipped('エントリー一覧アセット同期に失敗しました', '', $dest, 'write_failed', strlen($next));
            }
        }
    }

    private function entryIndexAssetBlock()
    {
        return '<!-- ' . $this->entryIndexAssetMarker . ' -->' . "\n"
            . '<script src="/extension/plugins/DF_Like/assets/df-like-entry-index.js?v=' . self::VERSION . '"></script>' . "\n"
            . '<!-- /' . $this->entryIndexAssetMarker . ' -->';
    }

    private function syncPostWrappers()
    {
        $sourceDir = PLUGIN_LIB_DIR . 'DF_Like/template/post/';
        $destDir = SCRIPT_DIR . 'extension/acms/POST/';

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        if (!is_dir($destDir) || !is_writable($destDir)) {
            return;
        }

        foreach (self::$postWrappers as $file) {
            $source = $sourceDir . $file;
            $dest = $destDir . $file;
            $className = basename($file, '.php');
            if (!$this->isSafeManagedSource($source, $this->postWrapperMarker, $className, $this->minWrapperBytes, 'POSTラッパー同期をスキップしました', $dest)) {
                continue;
            }
            if (is_file($dest)) {
                if (!is_writable($dest)) {
                    $this->logSyncSkipped('POSTラッパー同期をスキップしました', $source, $dest, 'destination_not_writable');
                    continue;
                }
                $content = (string)@file_get_contents($dest);
                if ($content !== '' && !$this->isManagedPostWrapper($content)) {
                    $this->logSyncSkipped('POSTラッパー同期をスキップしました', $source, $dest, 'destination_not_managed', strlen($content));
                    continue;
                }
            }
            if (!@copy($source, $dest)) {
                $this->logSyncSkipped('POSTラッパー同期に失敗しました', $source, $dest, 'copy_failed', @filesize($source));
            }
        }
    }

    private function syncGetWrappers()
    {
        $sourceDir = PLUGIN_LIB_DIR . 'DF_Like/template/get/';
        $destDir = SCRIPT_DIR . 'extension/acms/GET/';

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        if (!is_dir($destDir) || !is_writable($destDir)) {
            return;
        }

        foreach (self::$getWrappers as $file) {
            $source = $sourceDir . $file;
            $dest = $destDir . $file;
            $className = basename($file, '.php');
            if (!$this->isSafeManagedSource($source, $this->getWrapperMarker, $className, $this->minWrapperBytes, 'GETラッパー同期をスキップしました', $dest)) {
                continue;
            }
            if (is_file($dest)) {
                if (!is_writable($dest)) {
                    $this->logSyncSkipped('GETラッパー同期をスキップしました', $source, $dest, 'destination_not_writable');
                    continue;
                }
                $content = (string)@file_get_contents($dest);
                if ($content !== '' && !$this->isManagedGetWrapper($content)) {
                    $this->logSyncSkipped('GETラッパー同期をスキップしました', $source, $dest, 'destination_not_managed', strlen($content));
                    continue;
                }
            }
            if (!@copy($source, $dest)) {
                $this->logSyncSkipped('GETラッパー同期に失敗しました', $source, $dest, 'copy_failed', @filesize($source));
            }
        }
    }

    private function createTables()
    {
        LikeSchemaService::ensure();
    }

    private function isManagedPostWrapper($content)
    {
        if (strpos($content, $this->postWrapperMarker) !== false) {
            return true;
        }
        return strpos($content, 'Acms\\Plugins\\DF_Like') !== false;
    }

    private function isManagedGetWrapper($content)
    {
        if (strpos($content, $this->getWrapperMarker) !== false) {
            return true;
        }
        return strpos($content, 'Acms\\Plugins\\DF_Like') !== false;
    }

    private function isSafeManagedSource($source, $marker, $className, $minBytes, $message, $dest = '')
    {
        if (!is_file($source)) {
            $this->logSyncSkipped($message, $source, $dest, 'source_missing');
            return false;
        }

        $size = @filesize($source);
        if ($size === false || $size < $minBytes) {
            $this->logSyncSkipped($message, $source, $dest, 'source_too_short', $size === false ? 0 : (int)$size);
            return false;
        }

        $content = (string)@file_get_contents($source);
        if ($content === '' || strpos($content, $marker) === false) {
            $this->logSyncSkipped($message, $source, $dest, 'source_marker_missing', strlen($content));
            return false;
        }

        if ($className !== '' && strpos($content, 'class ' . $className) === false) {
            $this->logSyncSkipped($message, $source, $dest, 'source_class_missing', strlen($content));
            return false;
        }

        return true;
    }

    private function logSyncSkipped($message, $source, $dest, $reason, $size = null)
    {
        try {
            LikeErrorLogger::log('sync', $message, [
                'source' => $source,
                'destination' => $dest,
                'reason' => $reason,
                'size' => $size,
            ]);
        } catch (\Throwable $e) {
        }
    }
}
