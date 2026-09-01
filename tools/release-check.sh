#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  tools/release-check.sh VERSION

Example:
  tools/release-check.sh 0.7.55
USAGE
}

VERSION="${1:-}"
if [ -z "$VERSION" ] || [ "$VERSION" = "-h" ] || [ "$VERSION" = "--help" ]; then
  usage
  [ -z "$VERSION" ] && exit 1
  exit 0
fi

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Version must look like 0.7.55" >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ] && [ -d /Applications/MAMP/bin/php ]; then
  PHP_BIN="$(find /Applications/MAMP/bin/php -path '*/bin/php' -type f | sort -V | tail -1 || true)"
fi

cd "$ROOT_DIR"

fail() {
  echo "release-check failed: $*" >&2
  exit 1
}

require_file() {
  [ -f "$1" ] || fail "$1 was not found."
}

require_grep() {
  local pattern="$1"
  local file="$2"
  grep -q "$pattern" "$file" || fail "$file does not contain: $pattern"
}

require_file ServiceProvider.php
require_file README.md
require_file CHANGELOG.md
require_file RELEASE.md
require_file CHECKLIST.md
require_file NEXT.md
require_file tools/release.sh
require_file tools/release-json.php

[ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ] || fail "php command was not found."
command -v node >/dev/null 2>&1 || fail "node command was not found."

ANCHOR="v${VERSION//./-}"
require_grep "const VERSION = '$VERSION';" ServiceProvider.php
require_grep "id=\"$ANCHOR\"" CHANGELOG.md
require_grep "data-current-version=\"$VERSION\"" template/admin/app/df-like.html
require_grep "df-like-admin.css?v=$VERSION" template/admin/app/df-like.html
require_grep "df-like-admin.js?v=$VERSION" template/admin/app/df-like.html
require_grep "df-like-admin-snippet-modals.js?v=$VERSION" template/admin/app/df-like.html
require_grep "df-like-entry-index.js?v=$VERSION" template/admin/entry/index-asset.html
require_grep "df-like.js?v=$VERSION" Services/LikeButtonRenderer.php
require_grep "df-like.css?v=$VERSION" Services/LikeButtonRenderer.php

require_grep "InjectTemplate" ServiceProvider.php
require_grep "admin-main" ServiceProvider.php
require_grep "admin-topicpath" ServiceProvider.php
require_grep "BEGIN_IF \[%{ADMIN}/eq/app_df-like\]" template/admin/app/df-like.html
require_grep "BEGIN app_df-like" template/admin/topicpath/df-like.html

if grep -R "syncAdminTemplate" . --exclude-dir=.git --exclude=release-check.sh >/dev/null 2>&1; then
  fail "syncAdminTemplate remains in the plugin."
fi

if grep -R "themes/system/admin/app/df-like.html" ServiceProvider.php Hook.php GET POST Services template assets >/dev/null 2>&1; then
  fail "Direct system admin template path remains outside README."
fi

git diff --check

for file in assets/*.js; do
  node --check "$file"
done

find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null

for file in tools/*.sh; do
  bash -n "$file"
done

JSON_PATH="/private/tmp/DF_Like-v$VERSION-release-check.json"
"$PHP_BIN" tools/release-json.php \
  --product DF_Like \
  --display-name DFいいね \
  --version "$VERSION" \
  --repo datafarmjp/acms-df-like \
  --zip-name "DF_Like-v$VERSION.zip" \
  --output "$JSON_PATH" >/dev/null

"$PHP_BIN" -r '
$json = json_decode(file_get_contents($argv[1]), true);
if (!is_array($json) || empty($json["body_markdown"]) || empty($json["changelog_url"])) {
    fwrite(STDERR, "release JSON is missing required fields.\n");
    exit(1);
}
' "$JSON_PATH"

rm -f "$JSON_PATH"

echo "release-check passed: $VERSION"
