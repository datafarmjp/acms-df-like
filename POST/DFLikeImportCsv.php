<?php

namespace Acms\Plugins\DF_Like\POST;

use ACMS_POST;
use Acms\Plugins\DF_Like\Services\LikeErrorLogger;
use Acms\Plugins\DF_Like\Services\LikeRepository;
use Acms\Services\Facades\Common;

class DFLikeImportCsv extends ACMS_POST
{
    public $isCacheDelete = false;
    private $required = ['entry_id'];

    public function post()
    {
        try {
            if (!$this->canUseAdminPost()) {
                Common::responseJson(['status' => 'failure', 'message' => 'いいね履歴をインポートする権限がありません。']);
            }
            $file = $_FILES['df_like_import_csv'] ?? null;
            if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                Common::responseJson(['status' => 'failure', 'message' => 'CSVファイルを指定してください。']);
            }
            $rows = $this->csvRows((string)$file['tmp_name']);
            $dryRun = (string)$this->Post->get('dry_run') === '1';
            Common::responseJson(LikeRepository::importRows($rows, $dryRun));
        } catch (\Throwable $e) {
            LikeErrorLogger::log('import', $e->getMessage(), [
                'blog_id' => defined('BID') ? (int)BID : 0,
            ]);
            Common::responseJson(['status' => 'failure', 'message' => $e->getMessage()]);
        }
    }

    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('CSVファイルを開けませんでした。');
        }
        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            fclose($handle);
            throw new \RuntimeException('CSVヘッダーを読み込めませんでした。');
        }
        $headers = array_map(function ($header) {
            return trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$header));
        }, $headers);
        foreach ($this->required as $key) {
            if (!in_array($key, $headers, true)) {
                fclose($handle);
                throw new \RuntimeException('必須列 ' . $key . ' がありません。');
            }
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if ($values === [null] || $values === []) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $key) {
                $row[$key] = isset($values[$index]) ? trim((string)$values[$index]) : '';
            }
            $rows[$line] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function canUseAdminPost(): bool
    {
        if (function_exists('sessionWithAdministration') && sessionWithAdministration(BID)) {
            return true;
        }
        if (function_exists('roleAvailableUser') && roleAvailableUser()) {
            return function_exists('roleAuthorization') && roleAuthorization('config_edit', BID);
        }
        return false;
    }
}
