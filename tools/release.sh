#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  tools/release.sh VERSION

Example:
  tools/release.sh 0.7.27

This script:
  - verifies the worktree is clean
  - verifies ServiceProvider.php contains the requested version
  - creates /private/tmp/DF_Like-vVERSION.zip
  - creates and pushes tag vVERSION when missing
  - creates GitHub Release vVERSION with the ZIP asset

Requirements:
  - git
  - zip
  - php
  - gh auth login
USAGE
}

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
  usage
  exit 0
fi

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  usage >&2
  exit 1
fi

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Version must look like 0.7.27" >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_PARENT="$(dirname "$ROOT_DIR")"
TAG="v$VERSION"
ZIP_PATH="/private/tmp/DF_Like-$TAG.zip"
JSON_PATH="/private/tmp/DF_Like-$TAG-release.json"
NOTES_PATH="/private/tmp/DF_Like-$TAG-release-notes.md"
REPO="datafarmjp/acms-df-like"
PRODUCT="DF_Like"
DISPLAY_NAME="DFいいね"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ] && [ -d /Applications/MAMP/bin/php ]; then
  PHP_BIN="$(find /Applications/MAMP/bin/php -path '*/bin/php' -type f | sort -V | tail -1 || true)"
fi

cd "$ROOT_DIR"

if [ -n "$(git status --porcelain)" ]; then
  echo "Worktree is not clean. Commit or stash changes first." >&2
  git status --short >&2
  exit 1
fi

if ! grep -q "const VERSION = '$VERSION';" ServiceProvider.php; then
  echo "ServiceProvider.php does not contain const VERSION = '$VERSION';" >&2
  exit 1
fi

CHANGELOG_ANCHOR="v${VERSION//./-}"
if ! grep -q "id=\"$CHANGELOG_ANCHOR\"" CHANGELOG.md; then
  echo "CHANGELOG.md does not contain <a id=\"$CHANGELOG_ANCHOR\"></a>." >&2
  exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "gh command was not found. Install GitHub CLI first." >&2
  exit 1
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "php command was not found." >&2
  exit 1
fi

gh auth status >/dev/null

git fetch --tags origin >/dev/null 2>&1 || true

PREVIOUS_TAG="$(
  git tag --sort=-v:refname \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | awk -v current="$TAG" 'current == "" || $0 != current { print; exit }'
)"
PREVIOUS_VERSION="${PREVIOUS_TAG#v}"

rm -f "$ZIP_PATH"
(
  cd "$PLUGIN_PARENT"
  zip -qr "$ZIP_PATH" DF_Like \
    -x 'DF_Like/.git/*' \
    -x 'DF_Like/tests/*' \
    -x 'DF_Like/DEVELOPMENT_SUMMARY.md' \
    -x 'DF_Like/.DS_Store' \
    -x 'DF_Like/**/.DS_Store'
)

"$PHP_BIN" tools/release-json.php \
  --product "$PRODUCT" \
  --display-name "$DISPLAY_NAME" \
  --version "$VERSION" \
  --previous-version "$PREVIOUS_VERSION" \
  --repo "$REPO" \
  --zip-name "$(basename "$ZIP_PATH")" \
  --output "$JSON_PATH"

"$PHP_BIN" -r '
$json = json_decode(file_get_contents($argv[1]), true);
if (!is_array($json)) {
    fwrite(STDERR, "Failed to read release JSON.\n");
    exit(1);
}
$zip = (string)($json["download_url"] ?? "");
$changelog = (string)($json["changelog_url"] ?? "");
$previousTag = (string)($json["previous_tag"] ?? "");
$body = trim((string)($json["body_markdown"] ?? ""));
$bodySincePrevious = trim((string)($json["body_markdown_since_previous_release"] ?? ""));
$zipName = basename($zip);
echo "## 変更内容\n\n";
if ($previousTag !== "" && $bodySincePrevious !== "") {
    $tag = (string)($json["tag"] ?? "");
    echo "{$tag} は、前回公開版 {$previousTag} からの更新を含むリリースです。\n\n";
    echo $bodySincePrevious . "\n\n";
} else {
    echo ($body !== "" ? $body : "- 変更内容はCHANGELOG.mdを確認してください。") . "\n\n";
}
if ($changelog !== "") {
    echo "[CHANGELOG.md の該当箇所を開く]({$changelog})\n\n";
}
echo "## インストール\n\n";
echo "1. `{$zipName}` をダウンロードします。\n";
echo "2. ZIPを展開し、`DF_Like` フォルダを `extension/plugins/` に配置します。\n";
echo "3. a-blog cms の拡張アプリ管理から `DFいいね` をインストール・有効化します。\n\n";
echo "配置例:\n\n";
echo "```text\nextension/plugins/DF_Like/\n```\n\n";
echo "## 注意\n\n";
echo "このプラグインは a-blog cms 本体を含みません。利用には別途 a-blog cms の適切なライセンスが必要です。\n";
' "$JSON_PATH" > "$NOTES_PATH"

if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo "Tag $TAG already exists locally."
else
  git tag "$TAG"
fi

if git ls-remote --tags origin "$TAG" | grep -q "$TAG"; then
  echo "Tag $TAG already exists on origin."
else
  git push origin "$TAG"
fi

if gh release view "$TAG" --repo "$REPO" >/dev/null 2>&1; then
  gh release upload "$TAG" "$ZIP_PATH" --repo "$REPO" --clobber
  gh release edit "$TAG" --repo "$REPO" --title "DF_Like $TAG" --notes-file "$NOTES_PATH"
else
  gh release create "$TAG" "$ZIP_PATH" --repo "$REPO" --title "DF_Like $TAG" --notes-file "$NOTES_PATH"
fi

sync_release_json() {
  if [ "${DF_RELEASE_SYNC_ENABLED:-}" != "1" ]; then
    echo "Release JSON sync skipped. Set DF_RELEASE_SYNC_ENABLED=1 to enable it."
    return 0
  fi

  if ! command -v sftp >/dev/null 2>&1; then
    echo "sftp command was not found. GitHub Release is published, but CMS JSON sync failed." >&2
    return 1
  fi

  : "${DF_RELEASE_SYNC_HOST:?DF_RELEASE_SYNC_HOST is required when DF_RELEASE_SYNC_ENABLED=1}"
  : "${DF_RELEASE_SYNC_USER:?DF_RELEASE_SYNC_USER is required when DF_RELEASE_SYNC_ENABLED=1}"
  : "${DF_RELEASE_SYNC_REMOTE_PATH:?DF_RELEASE_SYNC_REMOTE_PATH is required when DF_RELEASE_SYNC_ENABLED=1}"

  local port="${DF_RELEASE_SYNC_PORT:-22}"
  local remote_dir="${DF_RELEASE_SYNC_REMOTE_PATH%/}/$PRODUCT"
  local remote_latest="$remote_dir/latest.json"
  local remote_version="$remote_dir/$TAG.json"
  local batch_file="/private/tmp/$PRODUCT-$TAG-sftp.batch"

  {
    echo "-mkdir $remote_dir"
    echo "put $JSON_PATH $remote_version"
    echo "put $JSON_PATH $remote_latest"
  } >"$batch_file"

  if sftp -P "$port" -b "$batch_file" "$DF_RELEASE_SYNC_USER@$DF_RELEASE_SYNC_HOST"; then
    echo "Release JSON synced: $remote_latest"
    rm -f "$batch_file"
    return 0
  fi

  rm -f "$batch_file"
  echo "GitHub Release is published, but CMS JSON sync failed." >&2
  return 1
}

sync_release_json

echo "Published $TAG"
echo "ZIP: $ZIP_PATH"
echo "JSON: $JSON_PATH"
echo "Release: https://github.com/$REPO/releases/tag/$TAG"
