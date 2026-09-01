# DF_Like Checklist

共通チェックリストは `../_shared/DF_EXTENSION_APP_CHECKLIST.md` を参照します。このファイルでは DF_Like の現状棚卸しと、リリース前に毎回見る項目をまとめます。

## 共通方針への適合状況

### 公開・配布

- [x] GitHub Releases を最新版配布の正としている。
- [x] 配布ZIP名が `DF_Like-vX.Y.Z.zip` 形式。
- [x] `tools/release.sh` がある。
- [x] `tools/release-json.php` が前回公開版から今回版までの本文を生成できる。
- [x] `tools/release-check.sh` でリリース前確認を実行できる。
- [x] `CHANGELOG.md` に固定アンカーがある。

### 管理画面

- [x] 管理画面本体を `admin-main` の `InjectTemplate` で差し込んでいる。
- [x] 管理画面テンプレート全体が `app_df-like` 条件で囲まれている。
- [x] パンくずを `admin-topicpath` で表示している。
- [x] 旧 `themes/system/admin/app/df-like.html` は管理マーカー付きのみ自動退避する。
- [x] systemテーマへ管理画面テンプレートをコピーしない。
- [x] エントリー一覧V2の追加JSも `InjectTemplate` で読み込む。

### 最新版通知

- [x] GitHub Releases APIで最新版を確認する。
- [x] draft/prereleaseを表示しない。
- [x] API失敗時だけlocalStorageをフォールバックにする。
- [x] 閉じたバージョンをlocalStorageに記録する。
- [x] CHANGELOG該当箇所へのリンクを表示する。
- [x] 左メニューに青ドットを表示する。

### 相談導線

- [x] 管理画面下部に薄い緑背景の相談導線がある。
- [x] Jicoo widget/iframeを管理画面へ埋め込んでいない。
- [x] READMEに `サポートとカスタマイズ` セクションがある。
- [x] 個別導入、環境調査、カスタマイズ、保守は有償対応として線引きしている。

### 残タスク

- [ ] 公開POSTとレート制限の堅牢化方針を実装する。
- [ ] アンインストール/無効化時の後片付け方針を決める。
- [ ] MAMP DB依存が薄い回帰テスト構成を整える。
- [ ] 通知メール未着時にプラグインで判定できる範囲と外部配送確認の境界をREADMEへ整理する。

## リリース前チェック

- [ ] `NEXT.md` の `Now` / `Done, Move To CHANGELOG` を確認した。
- [ ] `ServiceProvider::VERSION` を更新した。
- [ ] 管理画面/フロント/エントリー一覧アセットのクエリを更新した。
- [ ] `CHANGELOG.md` に `<a id="vX-Y-Z"></a>` とバージョン項目を追加した。
- [ ] READMEの仕様説明が今回変更と矛盾していない。
- [ ] `tools/release-check.sh X.Y.Z` が通る。
- [ ] 管理画面でスモークテストした。
- [ ] 公開ページでいいね追加/解除を確認した。
- [ ] 通知に触れた場合は通知テスト送信を確認した。
- [ ] Twig/V2に触れた場合はTwig側の表示を確認した。
- [ ] `git status --short` で意図しないファイルが混ざっていない。

## 変更種別ごとの追加確認

### JavaScript分離

- [ ] 新JSを管理テンプレートで読み込んでいる。
- [ ] 新JSを `DEVELOPMENT_SUMMARY.md` の `node --check` 例に追加した。
- [ ] `tools/release-check.sh` の `assets/*.js` 確認に含まれる。
- [ ] メインJSは詳細処理ではなく初期化と橋渡しだけを担当している。

### 管理画面

- [ ] `admin-main` 差し込みが対象画面だけに出る。
- [ ] `admin-topicpath` の表示名が左メニューと揃っている。
- [ ] systemテーマ配下へ新規ファイルを書き込んでいない。

### 配布ZIP

- [ ] `.git/` が含まれない。
- [ ] `tests/` が含まれない。
- [ ] `DEVELOPMENT_SUMMARY.md` が含まれない。
- [ ] `.DS_Store` が含まれない。
