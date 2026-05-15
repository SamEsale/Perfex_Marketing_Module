#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
MODULE_DIR="$ROOT_DIR/modules/marketing"
PACKAGE_ROOT="marketing"
RELEASE_DIR="$MODULE_DIR/release"
VALIDATOR_SCRIPT="$MODULE_DIR/scripts/verify_release_package.sh"
TMP_DIR="$(mktemp -d)"
STAGE_DIR="$TMP_DIR/$PACKAGE_ROOT"
MANIFEST_FILE="$MODULE_DIR/release/manifest.json"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

fail() {
  printf 'FAIL %s\n' "$1" >&2
  if [[ $# -ge 2 && -n "${2:-}" ]]; then
    printf '  %s\n' "$2" >&2
  fi
  exit 1
}

require_command() {
  local command_name="$1"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    fail "release build dependency missing" "missing required command: $command_name"
  fi
}

require_file() {
  local relative_path="$1"
  if [[ ! -f "$MODULE_DIR/$relative_path" ]]; then
    fail "required release asset missing" "$relative_path"
  fi
}

require_command bash
require_command find
require_command php
require_command rsync
require_command sed
require_command sort
require_command unzip
require_command zip

for required_path in \
  "marketing.php" \
  "README.md" \
  "INSTALLATION.md" \
  "docs/OPERATIONS.md" \
  "docs/PRODUCTION_CHECKLIST.md" \
  "docs/RELEASE_MANIFEST.md" \
  "release/manifest.json" \
  "scripts/build_release_package.sh" \
  "scripts/build_release.sh" \
  "scripts/verify_release_package.sh" \
  "scripts/verify_phase2_regressions.sh" \
  "scripts/verify_phase4_integrity_runtime.php"; do
  require_file "$required_path"
done

VERSION="$(sed -n 's/^Version:[[:space:]]*//p' "$MODULE_DIR/marketing.php" | head -n 1 | tr -d '\r')"
if [[ -z "$VERSION" ]]; then
  fail "module version missing" "unable to determine version from modules/marketing/marketing.php"
fi

RELEASE_ZIP="$RELEASE_DIR/${PACKAGE_ROOT}_v${VERSION}.zip"

if ! php -r '$path = $argv[1]; $json = file_get_contents($path); if ($json === false) {fwrite(STDERR, "read_failed"); exit(1);} json_decode($json, true); if (json_last_error() !== JSON_ERROR_NONE) {fwrite(STDERR, json_last_error_msg()); exit(1);} ' "$MANIFEST_FILE" >/dev/null 2>&1; then
  fail "release manifest JSON invalid" "$MANIFEST_FILE"
fi

if [[ -x "$VALIDATOR_SCRIPT" ]]; then
  printf 'Validating release source tree...\n'
  bash "$VALIDATOR_SCRIPT" --source-only
fi

printf 'Linting PHP files...\n'
find "$MODULE_DIR" \
  -path "$RELEASE_DIR" -prune -o \
  -path "$MODULE_DIR/node_modules" -prune -o \
  -path "$MODULE_DIR/vendor" -prune -o \
  -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

mkdir -p "$RELEASE_DIR"
rm -f "$RELEASE_ZIP"

rsync -a "$MODULE_DIR/" "$STAGE_DIR/" \
  --exclude ".git" \
  --exclude ".github" \
  --exclude ".DS_Store" \
  --exclude "__MACOSX" \
  --exclude ".idea" \
  --exclude ".vscode" \
  --exclude ".env" \
  --exclude ".env.*" \
  --exclude "*~" \
  --exclude "*.bak" \
  --exclude "*.tmp" \
  --exclude "node_modules" \
  --exclude "vendor" \
  --exclude "tests" \
  --exclude "tmp" \
  --exclude "temp" \
  --exclude "cache" \
  --exclude "logs" \
  --exclude "release" \
  --exclude "*.zip"

mkdir -p "$STAGE_DIR/release"
cp "$MANIFEST_FILE" "$STAGE_DIR/release/manifest.json"

(
  cd "$TMP_DIR"
  find "$PACKAGE_ROOT" -print | LC_ALL=C sort | zip -X -q "$RELEASE_ZIP" -@
)

if [[ -x "$VALIDATOR_SCRIPT" ]]; then
  printf 'Validating generated release ZIP...\n'
  bash "$VALIDATOR_SCRIPT" "$RELEASE_ZIP"
fi

printf 'Created %s\n' "$RELEASE_ZIP"
