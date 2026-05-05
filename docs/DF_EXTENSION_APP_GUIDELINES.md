# データファーム製 a-blog cms 拡張アプリ公開ガイドライン

このガイドラインは、株式会社データファームが公開する a-blog cms 拡張アプリの共通ルールです。

新しく公開する拡張アプリは、この文書を参照し、最新版通知、相談導線、README、リリース手順を同じ思想で揃えます。

## 基本方針

- 実用品として公開し、利用者の管理画面体験を邪魔しない。
- GitHub Releases を最新版配布の正とする。
- 配布ZIPは、展開後すぐ `extension/plugins/{PLUGIN_DIR}/` に置ける構成にする。
- 管理画面内のデータファーム導線は控えめにし、外部iframeや予約ウィジェットは直接埋め込まない。
- 個別導入、環境調査、カスタマイズ、保守は有償対応として線引きする。

## 必須の共通要素

### 1. 最新版通知

管理画面上部に、GitHub Releases の最新版を知らせる一行通知を置きます。

表示条件:

- `https://api.github.com/repos/{OWNER}/{REPO}/releases/latest` を確認する。
- `tag_name` が現在バージョンより新しい場合だけ表示する。
- `draft` または `prerelease` のReleaseは表示しない。
- GitHub API取得に失敗した場合は何も表示しない。
- 確認結果は `localStorage` に6時間程度キャッシュする。
- 閉じられたバージョンは `localStorage` に記録し、同じバージョンでは再表示しない。

表示文言:

```text
{APP_NAME} vX.Y.Z が公開されています。最新版をダウンロード
```

リンク先:

- Release asset に `{PLUGIN_DIR}-vX.Y.Z.zip` があれば、そのZIPへリンクする。
- ZIP asset が見つからない場合はReleaseページへリンクする。

管理画面のHTMLは、a-blog cms のクローズ付きお知らせスタイルに寄せます。

```html
<div role="alert" class="acms-admin-alert acms-admin-alert-info acms-admin-alert-icon {PLUGIN_SLUG}-update-notice js-{PLUGIN_SLUG}-update-notice" hidden>
  <span class="acms-admin-icon acms-admin-icon-news acms-admin-alert-icon-before" aria-hidden="true"></span>
  <button type="button" class="js-acms-alert-close acms-admin-alert-icon-after js-{PLUGIN_SLUG}-update-close">×</button>
  <span class="js-{PLUGIN_SLUG}-update-message"></span>
</div>
```

### 2. 開発・カスタマイズ相談導線

管理画面の最下部に、データファームへの相談導線を置きます。

文言:

```text
この拡張アプリは、データファームが a-blog cms の運用改善に取り組む中で開発した実用品です。

「このサイトの運用に合わせて、同じような機能がほしい」
「管理画面の面倒な作業を減らしたい」
「小さな業務改善を拡張アプリとして形にしたい」

そんなご相談も承っています。
内容がまだ固まっていない段階でも、お気軽にご相談ください。
```

ボタン:

```text
オンラインミーティングで相談する
https://www.jicoo.com/event_types/9KVr0WMdvpEl

お問い合わせフォームから相談する
https://datafarm.jp/contact
```

表示ルール:

- 背景色は薄い緑にする。
- 既存のa-blog cms管理画面ボタンスタイルを使う。
- 外部リンクには `target="_blank"` と `rel="noopener"` を付ける。
- Jicoo widget や iframe は管理画面には入れない。

### 3. READMEの相談導線

READMEの後半に `サポートとカスタマイズ` セクションを置きます。

```md
## サポートとカスタマイズ

この拡張アプリは、データファームが a-blog cms の運用改善に取り組む中で開発した実用品です。

「このサイトの運用に合わせて、同じような機能がほしい」
「管理画面の面倒な作業を減らしたい」
「小さな業務改善を拡張アプリとして形にしたい」

そんなご相談も承っています。内容がまだ固まっていない段階でも、お気軽にご相談ください。

- [オンラインミーティングで相談する](https://www.jicoo.com/event_types/9KVr0WMdvpEl)
- [お問い合わせフォームから相談する](https://datafarm.jp/contact)

MIT License の範囲で自由に利用・改変できますが、個別サイトへの導入支援、表示調整、機能追加、保守対応は有償で承ります。

不具合報告や改善提案は GitHub Issues へお寄せください。
```

### 4. バージョン管理

- `ServiceProvider::VERSION` を拡張アプリの現在バージョンの正とする。
- 管理画面・フロントアセットのクエリも同じバージョンに揃える。
- `CHANGELOG.md` に同じバージョンの項目を追加する。
- Git tag は `vX.Y.Z` とする。
- GitHub Release title は `{APP_NAME} vX.Y.Z` とする。
- 配布ZIP名は `{PLUGIN_DIR}-vX.Y.Z.zip` とする。

## リリース手順

各拡張アプリには `tools/release.sh` を同梱し、リリース時は次の流れを標準とします。

```bash
git status
# 必要な変更をcommit
git push
tools/release.sh X.Y.Z
```

`tools/release.sh` は次を行います。

- clean worktree確認
- `ServiceProvider::VERSION` の一致確認
- 配布ZIP作成
- `vX.Y.Z` tag作成
- tag push
- GitHub Release作成または更新
- ZIP添付

## 新規拡張アプリで必ず確認すること

- このガイドラインをREADMEまたは開発メモから参照できる。
- 管理画面に最新版通知がある。
- 管理画面に相談導線がある。
- READMEに相談導線がある。
- `tools/release.sh` がある。
- `CHANGELOG.md` がある。
- 配布ZIPから `.git/`, `tests/`, 開発メモ、`.DS_Store` が除外される。
- a-blog cms 本体や同期先生成物を独立リポジトリに混ぜない。
