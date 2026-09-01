# DF_Like Release

DF_Like のリリース手順です。共通方針は `../_shared/DF_EXTENSION_APP_RELEASE_GUIDE.md` を参照し、このファイルでは DF_Like 固有の確認点をまとめます。

## 前提

- GitHub Releases を最新版配布の正とします。
- 配布ZIP名は `DF_Like-vX.Y.Z.zip` です。
- `ServiceProvider::VERSION`、管理画面/フロントのアセットクエリ、`CHANGELOG.md` のバージョンを揃えます。
- `CHANGELOG.md` の各バージョンには `<a id="vX-Y-Z"></a>` 形式のアンカーを置きます。
- GitHub Release本文は、前回公開版から今回公開版までの `CHANGELOG.md` をまとめます。

## リリース前チェック

```bash
git status --short
tools/release-check.sh X.Y.Z
```

`tools/release-check.sh` は次を確認します。

- バージョン表記の整合
- CHANGELOGアンカー
- 管理画面/フロント/エントリー一覧アセットのクエリ
- `node --check assets/*.js`
- 全PHPファイルの `php -l`
- `bash -n tools/*.sh`
- `git diff --check`
- `tools/release-json.php` の生成確認
- 管理画面テンプレートがInjectTemplate前提の構成になっていること

## 手動スモークテスト

- 管理画面 `/admin/app_df-like/` が表示される。
- 設定を保存できる。
- いいね追加/解除ができ、履歴に残る。
- いいね後メッセージと吹き出しアクセントが表示される。
- 管理画面プレビューで吹き出しを確認できる。
- 外部解析表示と人気ランキングの設置方法モーダルが開閉でき、コピーできる。
- 通知テスト送信で診断情報が表示される。
- エントリー一覧V2でいいね数列が表示される。
- Twig/V2を変更した場合は、該当テンプレートで表示確認する。

## コミット

```bash
git add .
git commit -m "Release X.Y.Z"
git push
```

## GitHub Release

```bash
tools/release.sh X.Y.Z
```

`tools/release.sh` は次を行います。

- clean worktree確認
- `tools/release-check.sh X.Y.Z`
- 配布ZIP作成
- tag作成/送信
- GitHub Release作成/更新
- ZIP添付
- 任意でrelease JSON同期と告知エントリー作成

## 配布ZIP

ZIPには実行に必要なファイルを含め、次を除外します。

- `.git/`
- `tests/`
- `DEVELOPMENT_SUMMARY.md`
- `.DS_Store`

`NEXT.md`、`RELEASE.md`、`CHECKLIST.md` は利用者にも運用方針が見えるよう同梱してよいものとして扱います。

## 失敗時

- 公開後に問題が見つかった場合、原則として同じタグを作り直さず次のパッチバージョンを出します。
- DBや通知に関わる不具合は、復旧/確認手順を `CHANGELOG.md` またはGitHub Release本文へ明記します。
- 秘密情報が混入した場合は、公開停止とキー無効化を優先します。
