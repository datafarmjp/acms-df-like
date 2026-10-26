# いいね登録の回帰テスト

## DBを使わない単体テスト

```sh
PHP_BIN=/path/to/php NODE_BIN=/path/to/node tests/run-unit-tests.sh
```

IP・プロキシ判定、登録SQL失敗時の応答/通知抑止、カテゴリ別配置、CSV権限/行単位保存、動的ボタン同期を、CMS・実DB・外部通知を使わず検証します。以下の統合テストは別の実行環境と明示的なテスト用DBの準備が必要です。

DDEV（PHP 8.4 / MariaDB 11.8）のプロジェクトルートから実行します。

```sh
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/toggle-integration.php
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/post-integration.php
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/post-integration.php failure
```

登録・解除・クールダウン・回数制限・履歴へのreferer保存、DB障害時の中断を確認します。POSTテストでは通知サービスを記録用スタブに置き換え、成功時だけ呼ばれることを確認します。実際のメール・Webhookは送信しません。

開発用の隣接ディレクトリ `_shared/tests/support/isolated-cms.php` が必要です。既存DBからテーブル定義だけを複製した一時DBを使用し、終了時に削除します。既存の `LikeButtonRendererTest.php` は実サイトのフィクスチャに依存するため、このテスト群とは別です。

## 第1段階の追加確認（2026-09-07）

例外時のPOST確認と、既存レンダラーテストを分離DBで実行できます。

```sh
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/post-integration.php exception
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/LikeButtonRendererTest.php
```

`LikeButtonRendererTest.php`は環境変数がある場合だけ合成フィクスチャを利用します。従来の実サイト読み取りモードは維持していますが、今回の検証では利用しません。

HTTP・ブラウザ・ローカルSMTPの手順は`../../_shared/tests/support/README.md`を参照してください。`http-notification-probe.php <root>`は起動済みの分離HTTP環境専用です。実際の通知サービスとMailerを通し、成功時の1通受信、連打・解除・SQL失敗時の追加送信なしを確認します。通常の一括テストには含めません。

## 第2段階

`admin-operations-integration.php`：実DBで個別削除・履歴再構築・CSVドライラン・取り込み・再取り込み・不正行混在を検証。未使用パラメータ等の第1段階テストも維持。

固定コピーと分離環境の準備、ZIP更新試験、後片付けは [共通手順](../../_shared/tests/support/STAGE2.md) を参照。通常テストは共通実行器の `--plugin=DF_Like` で実行する。HTTP試験は別コマンド。失敗・未検証を残したまま検証完了にしない。

## 2026-09-07 修正後の再検証

固定スナップショットではなく、開発コードを指定して実行します。

    ddev exec env DF_PLUGIN_TEST_DDEV=1 DF_STAGE2_CODE_ROOT=/var/www/html/web/extension/plugins php web/extension/plugins/DF_Like/tests/admin-operations-integration.php

結果・未検証事項は [修正報告](../../_shared/reports/stability-fixes-2026-09-07.md) を参照。

## カテゴリ別の自動表示位置（0.8.0）

CMSを起動しない回帰確認と、実際のConfig保存・テンプレート描画・RAM・カテゴリ取得APIを使う分離DB確認です。

```sh
php tests/category-placement.php
# DDEVプロジェクトルートから:
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/category-placement-integration.php
```

主カテゴリ優先、本文上下の個別継承、非表示、未設定、旧設定互換、通常/Twigの自動挿入、マスタースイッチ、重複防止、手動設置維持、親ブログのグローバルカテゴリ、ブログ間の分離、JSON保存・エスケープ・設定削除を確認します。

## FAQなどで後から挿入するボタン（0.8.1）

```sh
node tests/dynamic-buttons.js
```

初回表示、htmxでの追加・置換、同一記事の複数ボタン、別記事、スクリプト再実行時の重複防止、取得失敗後の再試行、状態取得とクリックの応答順序、通信中のボタン追加・削除を確認します。実際の通信や通知は行いません。保存処理は上記の `toggle-integration.php` と `post-integration.php` で確認します。


## CSV監査の回帰確認（2026-09-29）

```sh
php tests/csv-import-regression.php
# DDEVプロジェクトルートから:
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/csv-import-integration.php local
ddev exec env DF_PLUGIN_TEST_DDEV=1 php web/extension/plugins/DF_Like/tests/csv-import-integration.php global
```

単体テストは実サービスと模擬DBで対象ブログ権限、独自object_type、日時省略/指定の再取り込み、保存false/例外/commit失敗と行単位ロールバックを確認します。統合テストは `isolated-cms.php` の一時DBだけを使い、CMS標準のローカル/グローバル管理者権限、実SQLトリガーによるINSERT/DELETE失敗、例外時の原子性と成功件数、ドライランの無書込を確認します。外部通知・API・本番データへの書込はありません。
