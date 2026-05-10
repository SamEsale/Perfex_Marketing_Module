#!/usr/bin/env bash
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE_NAME="marketing"
RELEASE_DIR="$MODULE_DIR/release"
TMP_DIR="$(mktemp -d)"
STAGE_DIR="$TMP_DIR/$MODULE_NAME"
VALIDATOR_SCRIPT="$MODULE_DIR/scripts/verify_release_package.sh"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

VERSION="$(sed -n 's/^Version:[[:space:]]*//p' "$MODULE_DIR/marketing.php" | head -n 1 | tr -d '\r')"
if [[ -z "$VERSION" ]]; then
  echo "Unable to determine module version from marketing.php" >&2
  exit 1
fi

if [[ -x "$VALIDATOR_SCRIPT" ]]; then
  echo "Validating release source tree..."
  bash "$VALIDATOR_SCRIPT" --source-only
fi

echo "Linting PHP files..."
find "$MODULE_DIR" \
  -path "$RELEASE_DIR" -prune -o \
  -path "$MODULE_DIR/node_modules" -prune -o \
  -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null

echo "Checking JavaScript syntax..."
node --check "$MODULE_DIR/assets/js/marketing-builder.js"

echo "Scanning for debug artifacts..."
DEBUG_GREP_PATTERN='var'
DEBUG_GREP_PATTERN+='_dump\|pri'
DEBUG_GREP_PATTERN+='nt_r\|console\.'
DEBUG_GREP_PATTERN+='log\|TO''DO\|FIX''ME'
if grep -R -n "$DEBUG_GREP_PATTERN" "$MODULE_DIR" \
  --exclude-dir=release \
  --exclude-dir=node_modules \
  --exclude="*.zip"; then
  echo "Debug artifacts found. Remove them before packaging." >&2
  exit 1
fi

mkdir -p "$RELEASE_DIR"
rsync -a "$MODULE_DIR/" "$STAGE_DIR/" \
  --exclude ".git" \
  --exclude ".DS_Store" \
  --exclude "__MACOSX" \
  --exclude ".idea" \
  --exclude ".vscode" \
  --exclude ".env" \
  --exclude ".env.*" \
  --exclude "*.bak" \
  --exclude "*~" \
  --exclude "node_modules" \
  --exclude "tests" \
  --exclude "tmp" \
  --exclude "cache" \
  --exclude "release" \
  --exclude "*.zip" \
  --exclude "*/.DS_Store"

(
  cd "$TMP_DIR"
  zip -rq "$RELEASE_DIR/${MODULE_NAME}_v${VERSION}.zip" "$MODULE_NAME"
)

if [[ -x "$VALIDATOR_SCRIPT" ]]; then
  echo "Validating generated release ZIP..."
  bash "$VALIDATOR_SCRIPT" "$RELEASE_DIR/${MODULE_NAME}_v${VERSION}.zip"
fi

echo "Created $RELEASE_DIR/${MODULE_NAME}_v${VERSION}.zip"
