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
NOTES_PATH="/private/tmp/DF_Like-$TAG-release-notes.md"
REPO="datafarmjp/acms-df-like"

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

if ! command -v gh >/dev/null 2>&1; then
  echo "gh command was not found. Install GitHub CLI first." >&2
  exit 1
fi

gh auth status >/dev/null

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

cat > "$NOTES_PATH" <<EOF
## 変更内容

詳しくは CHANGELOG.md の \`$TAG\` を参照してください。

## インストール

1. \`DF_Like-$TAG.zip\` をダウンロードします。
2. ZIPを展開し、\`DF_Like\` フォルダを \`extension/plugins/\` に配置します。
3. a-blog cms の拡張アプリ管理から \`DFいいね\` をインストール・有効化します。

配置例:

\`\`\`text
extension/plugins/DF_Like/
\`\`\`

## 注意

このプラグインは a-blog cms 本体を含みません。利用には別途 a-blog cms の適切なライセンスが必要です。
EOF

git fetch --tags origin >/dev/null 2>&1 || true

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

echo "Published $TAG"
echo "ZIP: $ZIP_PATH"
echo "Release: https://github.com/$REPO/releases/tag/$TAG"
