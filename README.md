# DF_Like

a-blog cms のエントリーに「いいね」ボタンを追加し、管理画面で履歴・解析・通知・移行補助を扱える拡張アプリです。

## ダウンロード

最新版は [GitHub Releases](https://github.com/datafarmjp/acms-df-like/releases/latest) からダウンロードできます。

各バージョンの変更内容は、GitHub Release本文または [CHANGELOG.md](./CHANGELOG.md) を確認してください。

## インストール

このリポジトリは、a-blog cms のプラグインディレクトリ直下に置く前提です。

```text
extension/plugins/DF_Like/
```

配置後、a-blog cms の拡張アプリ管理から `DFいいね` をインストール・有効化します。

有効化時に、次の管理ファイルをプラグイン内テンプレートから同期します。

- `extension/acms/GET/DFLike.php`
- `extension/acms/GET/DFLikeAnalytics.php`
- `extension/acms/GET/DFLike_Analytics.php`（互換用）
- `extension/acms/POST/DFLike*.php`

管理画面は `themes/system/admin/app/df-like.html` へコピーせず、a-blog cms の `InjectTemplate` でプラグイン内テンプレートを差し込みます。旧バージョンで作成された `themes/system/admin/app/df-like.html` は、ファイル内に `DF_Like managed admin app template` がある場合だけ自動で同じディレクトリへ退避されます。退避後は最新のInjectTemplate管理画面が表示されます。管理マーカーがないファイルはユーザー編集の可能性があるため自動退避しません。

また、エントリー一覧V2へ「いいね数」列を追加するため、`themes/system/admin/entry/index/v2.html` に管理マーカー付きで `df-like-entry-index.js` の読み込みを追記します。既にDFいいねの管理マーカーがある場合は、そのブロックだけを更新します。

これらの同期先ファイルや追記ブロックは生成物として扱い、独立リポジトリでは同期元の `template/` 配下とプラグイン本体を管理します。
同期先を直接編集しても、DFいいねの更新時に管理マーカー付きブロックはプラグイン内の同期元で上書きされます。

### バージョン管理

配布時は、`ServiceProvider::$version`、管理画面/フロントのアセットクエリ、`CHANGELOG.md` のバージョンを揃えます。

`CHANGELOG.md` の各バージョン見出しの直前には、`<a id="v0-7-36"></a>` 形式の固定アンカーを置きます。リリーススクリプトはこのアンカーを使って、タグ時点のCHANGELOG該当箇所へリンクします。

バグ修正はパッチバージョン、機能追加はマイナーバージョン、互換性を壊す変更はメジャーバージョンとして扱います。

データファーム製 a-blog cms 拡張アプリの共通公開ルールは、プラグイン共通ドキュメント `../_shared/DF_EXTENSION_APP_GUIDELINES.md` を参照してください。

### 文字コード

ボタン文言に絵文字を使う場合は、a-blog cms のConfig保存先テーブル/カラムが `utf8mb4` である必要があります。

MySQL の `utf8` は4バイト文字を保存できないため、Config保存先が `utf8mb4` ではない環境では、絵文字が `?` として保存されることがあります。すでに `?` になった値は復元できないため、DB文字コードを確認・変更した後に、管理画面から文言を再保存してください。

## 使い方

拡張アプリ管理で `DFいいね` をインストール・有効化すると、管理画面は `管理ページ > 拡張アプリ > DFいいね解析` から確認できます。

表示方法は「記事詳細への自動表示」と「手動設置」のどちらも使えます。両方を併用した場合も、手動設置したボタンは消えません。近い場所に並びすぎる場合は、管理画面の「本文上の表示」「本文下の表示」を `非表示` にして調整してください。

## 記事詳細への自動表示

管理画面の「表示設定」で `Entry_Bodyにいいねボタンを自動表示する` をONにすると、`Entry_Body` モジュールの本文上・本文下へいいねボタンを自動挿入できます。

自動表示には環境設定の `HOOK_ENABLE` が `1` である必要があります。`HOOK_ENABLE` が無効な環境では、設定は保存されますが自動挿入は実行されません。

環境によってプラグイン側のHook登録だけで自動表示が動かない場合は、本体側の `extension/acms/Hook.php` に次のブリッジを追加してください。

```php
public function init()
{
    if (!class_exists('\Acms\Plugins\DF_Like\Hook') || !class_exists('\Acms\Services\Common\HookFactory')) {
        return;
    }
    \Acms\Services\Common\HookFactory::singleton()->attach('DF_Like_CustomBridge', new \Acms\Plugins\DF_Like\Hook());
}

public function afterGetFire(&$res, $thisModule)
{
    if (!class_exists('\Acms\Plugins\DF_Like\Hook')) {
        return;
    }

    static $dfLikeHook = null;
    if ($dfLikeHook === null) {
        $dfLikeHook = new \Acms\Plugins\DF_Like\Hook();
    }
    $dfLikeHook->afterGetFire($res, $thisModule);
}
```

自動表示位置は、本文上・本文下それぞれで次を選べます。

- `非表示`
- `左`
- `中央`
- `右`

Twigテンプレートで `module('V2_Entry_Body')` を使う場合も、自動表示設定が有効であれば `entry.body` の前後へ同じ設定でいいねボタンを追加します。Twig側では通常どおり `{{ entry.body|raw }}` を出力してください。

## 手動設置

任意の場所へいいねボタンを表示したい場合は、利用しているテンプレート形式に合わせて次の記述を配置します。自動表示ONでも、手動設置したボタンは表示されます。

通常テンプレートでは、`Entry_Body` の `entry:loop` 内に次のタグを配置します。

```html
<!-- BEGIN_MODULE DFLike ctx="eid/{eid}" --><!-- END_MODULE DFLike -->
```

`ctx="eid/{eid}"` を付けることで、その行のエントリーIDを基準にいいねボタンを表示します。通常の記事詳細で `EID` が取れる場合は従来の `<!-- BEGIN_MODULE DFLike --><!-- END_MODULE DFLike -->` でも動作しますが、標準形は `ctx` 付きです。

Twigテンプレートでは、`V2_Entry_Body` のエントリーループ内に次の記述を配置します。

```twig
{{ entry.dfLikeButton|raw }}
```

`entry.dfLikeButton` は自動表示設定とは独立して用意されます。管理画面で自動表示をOFFにしていても、Twig側でこの記述を置けばいいねボタンを表示できます。

## ボタン表示設定

管理画面から、手動設置・自動表示の両方に共通する見た目を変更できます。

- いいね前の文言
- いいね済みの文言
- マーク: ハート、星、拍手、グッド、なし
- いいね数の表示/非表示
- いいね後メッセージ
- 吹き出しアクセント
- ボタン色
- 角丸

いいね数は表示設定でも、0件のときは数字部分を出さず、1件以上で表示します。

`いいね後メッセージ` を設定すると、いいね追加が成功した時だけ、ボタン付近に短い吹き出しを表示できます。空欄の場合は表示しません。

`吹き出しアクセント` では、吹き出しに添える小さな絵文字装飾を選べます。選択肢は `なし`、`花と緑`、`クラッカー`、`スター`、`ハート`、`感謝のフェイスマーク` です。画面全体の演出ではなく、吹き出し内や周辺に控えめに時間差でふわっと表示します。

エントリーごとに文言を変えたい場合は、エントリー編集画面に追加される `いいね後メッセージ` 欄、またはカスタムフィールド `df_like_thanks_message` にメッセージを入力してください。エントリー側の値がある場合は、管理画面の共通メッセージより優先されます。メッセージはHTMLとして解釈せず、テキストとして表示します。

独自のボタンHTMLを使う場合は、既存の `.js-df-like-button` に `{thanksMessage}` と `{thanksAccent}` を属性として追加すると同じ吹き出し表示を利用できます。

```html
data-thanks-message="{thanksMessage}"
data-thanks-accent="{thanksAccent}"
```

## 解析と履歴

管理画面では、現在のいいね数、人気のエントリー、いいね履歴、日別イベントを確認できます。

期間フィルター、エントリータイトル付きのランキング/履歴、ランキングCSV、履歴CSV、期間指定の履歴削除が使えます。履歴削除は `acms_df_like_log` のみを対象にし、現在のいいね状態は維持します。

解析範囲は次から選べます。

- `現在のブログ＋下層ブログ`
- `現在のブログのみ`

子ブログの管理画面から親ブログのログ・ランキング・履歴・CSV・削除対象は見えない設計です。

エントリー一覧には「いいね数」列を追加します。件数取得に失敗しても一覧本体は壊れないようにしています。

「いいね数」列が表示されない場合は、エントリー一覧画面で `/extension/plugins/DF_Like/assets/df-like-entry-index.js` が読み込まれているか確認してください。読み込まれていない場合は、拡張アプリの有効化/更新処理を再実行するか、`themes/system/admin/entry/index/v2.html` にDFいいねの管理マーカー付きscriptが追記されているか確認してください。

管理画面が白画面になり、PHPエラーログに `Class "Acms\Plugins\DF_Like\..." not found` が出る場合は、DFいいね本体と `extension/acms/GET` / `extension/acms/POST` のラッパーが同じバージョンで配置されているか確認し、`0.7.6` 以降へ更新してください。`0.7.6` 以降は、軽量な `Bootstrap.php` 経由でDFいいね自身のクラスを読み込む保険を持っています。

管理画面が白画面でエラーログに決定的な情報がない場合は、`extension/plugins/DF_Like/template/admin/app/df-like.html` が存在するか、`ServiceProvider` の `InjectTemplate` 登録が動いているか確認してください。`0.7.34` 以降は、管理画面テンプレートを `themes/system/admin/app/df-like.html` へコピーしません。

ログイン中はいいねできるのに、ログインしていない訪問者だけいいねできない場合は、`0.7.11` 以降へ更新してください。`0.7.11` 以降は公開用POSTの `formToken` / CSRF セッション依存を外し、匿名訪問者でもいいね操作と状態取得ができるようにしています。

## 外部解析表示

公開ページに次のタグを置くと、非ログイン状態でも読み取り専用のいいね解析を表示できます。

これは管理画面そのものを公開する機能ではなく、管理画面で使っている集計データを公開ページ用に安全寄りの項目だけで表示するGETモジュールです。

通常テンプレートでは次のタグを配置します。

```html
<!-- BEGIN_MODULE DFLikeAnalytics --><!-- END_MODULE DFLikeAnalytics -->
```

Twigテンプレートでは、`module('V2_Entry_Body')` の戻り値に追加される `dfLikeAnalytics` を出力します。

```twig
{{ entryBody.dfLikeAnalytics|raw }}
```

以前案内していた `DFLike_Analytics` も互換用に残していますが、a-blog cms のモジュール名解決で `_` が名前空間区切りとして扱われる環境があるため、標準タグは `DFLikeAnalytics` を使ってください。

表示内容は次のとおりです。

- サマリー
- 日別推移
- 人気のエントリー
- いいね履歴

集計範囲は管理画面の「管理画面で集計するブログ」に従います。

公開履歴の標準出力は、安全寄りに `日時`、`記事タイトル / エントリーID`、`操作` のみに限定しています。参照元URL、訪問者ハッシュ、ユーザーIDは標準テンプレートでは出力しません。

外部解析表示の基本CSSは `/extension/plugins/DF_Like/assets/df-like.css` から自動で読み込まれます。テーマ側で見た目を調整したい場合は、テーマCSSで `.df-like-analytics` 以下を上書きしてください。

通常のいいねボタンを表示する `DFLike` とは別のタグです。ボタンではなく、解析結果を公開ページへ埋め込みたい場合に使ってください。

## 人気記事ランキング表示

公開ページにいいね数順の人気記事リンク一覧を表示したい場合は、`DFLikeRanking` を使います。

```html
<!-- BEGIN_MODULE DFLikeRanking -->
  <!-- DFLikeRanking:limit=5 -->
  <section>
    <h2 class="sub-heading">人気記事</h2>
    <!-- BEGIN notFound -->
    <p>いいねされた記事はまだありません。</p>
    <!-- END notFound -->
    <ul class="entry-list is-thumbnail">
  <!-- BEGIN ranking:loop -->
      <li>
        <a href="{entry_url}">
          <div class="entry-list-thumbnail-img-outer">
            <!-- BEGIN_IF [{entry_image_thumbnail}/nem] -->
            <img class="js-focused-image"
              data-focus-x="{entry_image_focal_x}"
              data-focus-y="{entry_image_focal_y}"
              src="{entry_image_thumbnail}" alt="{entry_image_alt}" loading="lazy">
            <!-- ELSE -->
            <img class="js-focused-image" src="/images/noimage.png" alt="" loading="lazy">
            <!-- END_IF -->
          </div>
          <div class="entry-list-thumbnail-info">
            <span class="entry-list-date">{like_count}いいね</span>
            <span class="entry-list-title">{entry_title}</span>
          </div>
        </a>
      </li>
  <!-- END ranking:loop -->
    </ul>
  </section>
<!-- END_MODULE DFLikeRanking -->
```

通常テンプレートでは、表示件数や期間をモジュール内の `<!-- DFLikeRanking:... -->` コメントで指定します。`limit` は未指定時 `5`、最大 `50` です。集計範囲は管理画面の「管理画面で集計するブログ」に従います。

Twigテンプレートでは `V2_DFLikeRanking` を使い、ランキング配列を自由に描画できます。

```twig
{% set ranking = module('V2_DFLikeRanking', null, { limit: 5 }) %}

<section>
  <h2 class="sub-heading">人気記事</h2>
  {% if ranking.notFound %}
  <p>いいねされた記事はまだありません。</p>
  {% endif %}
  <ul class="entry-list is-thumbnail">
    {% for item in ranking.items %}
      <li>
        <a href="{{ item.entry_url }}">
          <div class="entry-list-thumbnail-img-outer">
            {% if item.entry_image_thumbnail %}
              <img class="js-focused-image"
                data-focus-x="{{ item.entry_image_focal_x }}"
                data-focus-y="{{ item.entry_image_focal_y }}"
                src="{{ item.entry_image_thumbnail }}" alt="{{ item.entry_image_alt }}" loading="lazy">
            {% else %}
              <img class="js-focused-image" src="/images/noimage.png" alt="" loading="lazy">
            {% endif %}
          </div>
          <div class="entry-list-thumbnail-info">
            <span class="entry-list-date">{{ item.like_count }}いいね</span>
            <span class="entry-list-title">{{ item.entry_title }}</span>
          </div>
        </a>
      </li>
    {% endfor %}
  </ul>
</section>
```

期間を指定したい場合は、`period` または `start` / `end` を追加します。

```html
<!-- 今日のランキング -->
<!-- BEGIN_MODULE DFLikeRanking --><!-- DFLikeRanking:limit=5;period=today --><!-- END_MODULE DFLikeRanking -->

<!-- 直近7日間のランキング -->
<!-- BEGIN_MODULE DFLikeRanking --><!-- DFLikeRanking:limit=5;period=7d --><!-- END_MODULE DFLikeRanking -->

<!-- 直近30日間のランキング -->
<!-- BEGIN_MODULE DFLikeRanking --><!-- DFLikeRanking:limit=5;period=30d --><!-- END_MODULE DFLikeRanking -->

<!-- 任意期間のランキング -->
<!-- BEGIN_MODULE DFLikeRanking --><!-- DFLikeRanking:limit=5;start=2026-05-01;end=2026-05-31 --><!-- END_MODULE DFLikeRanking -->
```

Twigテンプレートでも同じオプションを指定できます。

```twig
{% set ranking = module('V2_DFLikeRanking', null, { limit: 5, period: '7d' }) %}
{% set ranking = module('V2_DFLikeRanking', null, { limit: 5, start: '2026-05-01', end: '2026-05-31' }) %}
```

期間指定なし、または `period=all` は現在有効ないいね数を集計します。期間指定時は履歴ログをもとに、期間内の `like` を `+1`、`unlike` を `-1` とした純増いいね数でランキングします。

出力できる主な変数は `rank`、`entry_id`、`blog_id`、`entry_title`、`entry_url`、`like_count` です。
記事に標準メイン画像が設定されている場合は、`entry_image_thumbnail`、`entry_image_path`、`entry_image_alt`、`entry_image_width`、`entry_image_height`、`entry_image_ratio`、`entry_image_focal_x`、`entry_image_focal_y` も利用できます。a-blog cms側のメイン画像設定（通常は `entry_main_image`）に従い、フィールド画像がない場合はユニットのメイン画像を参照します。サムネイル表示には `entry_image_thumbnail` の利用を推奨します。画像がない場合、画像系の値は空になります。

## いいね通知

いいね通知をONにすると、指定したa-blog cmsフォームの管理者宛メール設定を使って、いいね時にメール通知できます。

通知対象は `like` のみです。`unlike` では通知しません。フォームの通常送信処理、フォームログ、連番更新、必須項目検証は実行しません。

通知メール本文では次の変数を使えます。

- `entry_id`
- `entry_title`
- `entry_url`
- `object_type`
- `object_id`
- `like_count`
- `liked_at`
- `blog_id`
- `referer`

例:

```text
{entry_title} にいいねされました。
URL: {entry_url}
現在のいいね数: {like_count}
日時: {liked_at}
```

通知に失敗しても、いいね保存は成功として扱います。失敗内容や、フォーム側の管理者宛メール送信がOFFで送信されなかった場合は「最近のエラー」に記録されます。

DFいいねの通知は、指定フォームのメール設定を参照して独自に送信します。通常のフォーム送信としてPOSTするわけではないため、a-blog cmsのフォームログには残りません。通知が届かない場合は、まずDFいいね管理画面の「最近のエラー」で `notification` の詳細を確認してください。

## いいね履歴インポート

他のいいねシステムから移行するために、CSVでいいね履歴を取り込めます。

必須列は `entry_id` のみです。

任意列:

- `visitor_key`
- `action`
- `created_at`
- `blog_id`
- `object_type`
- `object_id`
- `user_id`
- `referer`

省略時の補完:

- `visitor_key`: `import:{entry_id}:{line}` 形式の内部キー
- `action`: `like`
- `created_at`: インポート実行時刻
- `object_type`: `entry`
- `object_id`: `entry_id`
- `blog_id`: 対象エントリーのブログID、取得できない場合は現在のBID

`visitor_key` はそのまま保存せず、内部ではハッシュ化して扱います。ドライランではDBを変更せず、実行前に件数と行エラーを確認できます。

Excelやスプレッドシート由来のCSVで、日時やIDの前後に不可視空白が混ざる場合も、インポート時に軽く正規化します。

同じCSVを再実行しても履歴ログは重複しません。重複ログがある場合でも、現在のいいね状態に不足があれば補修します。

CSV移行後に履歴は表示されるのにフロントの件数へ反映されない場合は、管理画面の「履歴から現在いいねを再構築」を実行してください。`df_like_log` の最新履歴をもとに、現在有効ないいね用の `df_like` を作り直します。

インポート結果に「現在いいねを作成できませんでした」と表示される場合は、DB側で `df_like` へのINSERTが失敗しています。`0.7.8` 以降では行エラーと「最近のエラー」にDBエラー情報を記録するため、テーブル定義差やカラム不足の確認に利用できます。

## 連打制限

いいね追加は、同一IPアドレスとUser-Agentの組み合わせで10分あたり10回までに制限しています。

制限対象は新規 `like` のみです。既に押したいいねの解除は、制限中でも通るようにしています。

## 最近のエラー

管理画面の「最近のエラー」では、通知・インポート・テーブル補修・想定外のいいねPOST失敗を最新20件まで確認できます。PHPエラーログにDFいいね由来のエラーが出ない場合も、まずここを確認してください。

通知ログには、通知OFF、通知フォーム未設定、フォーム削除済み、宛先・件名・本文不足、フォーム側の管理者宛メール送信OFF、送信例外などの理由を記録します。詳細を開くと `form_id`、`form_code`、`form_name`、`AdminFormSend`、`AdminTo_count` などを確認できます。

IPアドレスやUser-Agentの生値は保存しません。内部ログは運用補助用で、解析CSVやいいね履歴CSVには含めません。

管理画面の `エラー履歴を削除` から、現在の集計対象ブログ範囲に含まれる内部エラー履歴だけを削除できます。いいね履歴や現在のいいね数は削除しません。

## 作成されるテーブル

- `acms_df_like`
- `acms_df_like_log`
- `acms_df_like_error_log`

実際の接頭辞は `DB_PREFIX` に従います。

## 既知の制限

- 記事ではないURL単位のいいねには対応していません。対象はエントリーID基準です。
- 通知メールは同一リクエスト内で送信します。大量アクセス時の完全非同期通知は今後の検討対象です。
- いいね履歴インポートは移行補助向けです。移行元の完全な履歴精度が必要な場合は、任意列をできるだけ指定してください。
- `tests/LikeButtonRendererTest.php` はローカルDB fixtureに依存します。実行前にテストファイル冒頭の前提を確認してください。

## リリース告知連携

`DF_RELEASE_SYNC_ENABLED=1` でリリースJSONをSFTP配置したあと、`DF_RELEASE_PUBLISH_ENABLED=1` の場合だけDFリリースへ告知作成POSTを送ります。

```sh
export DF_RELEASE_PUBLISH_ENABLED=1
export DF_RELEASE_PUBLISH_ENDPOINT="https://example.com/bid/1/"
export DF_RELEASE_PUBLISH_TOKEN="DFリリース管理画面のAPIトークン"
```

`DF_RELEASE_PUBLISH_ENABLED` が未設定の場合、既存のリリース処理は変わりません。

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

## 開発応援

DFいいねは無料で利用できます。
もし役に立った場合は、開発継続の応援としてサポートいただけるとうれしいです。

[開発を応援する（500円）](https://buy.stripe.com/14AaEW0ta4yb8er70O9ws00)

## ライセンス

MIT License で公開しています。詳しくは `LICENSE` を参照してください。

このプラグインは a-blog cms 本体を含みません。利用には、別途 a-blog cms の適切なライセンスが必要です。
