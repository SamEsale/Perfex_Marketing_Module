#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_DIR="$ROOT_DIR/release"
TMP_DIR="$(mktemp -d)"
STAGE_DIR="$TMP_DIR/marketing"
TAG_NAME="${1:-${GITHUB_REF_NAME:-}}"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

if [[ -z "$TAG_NAME" ]]; then
  TAG_NAME="v$(sed -n 's/^Version:[[:space:]]*//p' "$ROOT_DIR/marketing.php" | head -n 1 | tr -d '\r')"
fi

if [[ -z "$TAG_NAME" || "$TAG_NAME" == "v" ]]; then
  echo "Unable to determine release tag." >&2
  exit 1
fi

DEBUG_GREP_PATTERN='var'
DEBUG_GREP_PATTERN+='_dump\|pri'
DEBUG_GREP_PATTERN+='nt_r\|console\.'
DEBUG_GREP_PATTERN+='log\|TO''DO\|FIX''ME'

echo "Checking for debug artifacts..."
if grep -R -n "$DEBUG_GREP_PATTERN" "$ROOT_DIR" \
  --exclude-dir=.git \
  --exclude-dir=.github \
  --exclude-dir=node_modules \
  --exclude-dir=release \
  --exclude="build_release.sh" \
  --exclude="*.zip"; then
  echo "Debug artifacts found. Remove them before packaging." >&2
  exit 1
fi

echo "Checking for removed WhatsApp diagnostics..."
if grep -R -n \
  -e "WHATSAPP_SAVE_ENTERED" \
  -e "MARKETING_SETTINGS_DIAG" \
  -e "WHATSAPP_SAVE_OPTION" \
  -e "WHATSAPP_SAVE_MISMATCH" \
  -e "WhatsApp form probe received" \
  -e "WhatsApp settings save handler reached" \
  -e "WhatsApp saved:" \
  "$ROOT_DIR" \
  --exclude-dir=.git \
  --exclude-dir=.github \
  --exclude-dir=node_modules \
  --exclude-dir=release \
  --exclude="build_release.sh" \
  --exclude="*.zip"; then
  echo "Removed WhatsApp diagnostics were found." >&2
  exit 1
fi

echo "Linting marketing.php..."
php -l "$ROOT_DIR/marketing.php" >/dev/null

echo "Linting PHP files..."
find "$ROOT_DIR" \
  -path "$ROOT_DIR/.git" -prune -o \
  -path "$ROOT_DIR/.github" -prune -o \
  -path "$ROOT_DIR/node_modules" -prune -o \
  -path "$ROOT_DIR/release" -prune -o \
  -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null

echo "Checking JavaScript syntax..."
node --check "$ROOT_DIR/assets/js/marketing-builder.js"

mkdir -p "$RELEASE_DIR"
rm -f "$RELEASE_DIR/marketing_${TAG_NAME}.zip"
mkdir -p "$STAGE_DIR"

echo "Staging clean module package..."
rsync -a "$ROOT_DIR/" "$STAGE_DIR/" \
  --exclude ".git" \
  --exclude ".github" \
  --exclude ".DS_Store" \
  --exclude "__MACOSX" \
  --exclude "*.bak" \
  --exclude "*~" \
  --exclude "node_modules" \
  --exclude "release" \
  --exclude "temp" \
  --exclude "*.tmp" \
  --exclude "*.temp" \
  --exclude "*.swp" \
  --exclude ".env" \
  --exclude "*.zip"

echo "Building release archive..."
(
  cd "$TMP_DIR"
  zip -rq "$RELEASE_DIR/marketing_${TAG_NAME}.zip" "marketing"
)

echo "Created $RELEASE_DIR/marketing_${TAG_NAME}.zip"
