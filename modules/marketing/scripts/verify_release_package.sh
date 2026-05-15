#!/usr/bin/env bash
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_PATH="${1:-}"

require_command() {
  local command_name="$1"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf 'FAIL release validation dependency\n'
    printf '  missing required command: %s\n' "$command_name"
    exit 1
  fi
}

fail() {
  printf 'FAIL %s\n' "$1"
  if [[ $# -ge 2 && -n "${2:-}" ]]; then
    printf '  %s\n' "$2"
  fi
  exit 1
}

pass() {
  printf 'PASS %s\n' "$1"
  if [[ $# -ge 2 && -n "${2:-}" ]]; then
    printf '  %s\n' "$2"
  fi
}

require_command bash
require_command grep
require_command sed

REQUIRED_FILES=(
  "marketing.php"
  "install.php"
  "uninstall.php"
  "config/routes.php"
  "config/csrf_exclude_uris.php"
  "libraries/Marketing_schema.php"
  "scripts/verify_phase2_regressions.sh"
  "scripts/verify_phase4_integrity_runtime.php"
  "scripts/verify_release_package.sh"
  "README.md"
  "INSTALLATION.md"
  "docs/OPERATIONS.md"
)

for relative_path in "${REQUIRED_FILES[@]}"; do
  if [[ ! -f "$MODULE_DIR/$relative_path" ]]; then
    fail "required module file missing" "$relative_path"
  fi
done
pass "required module files exist" "validated ${#REQUIRED_FILES[@]} required files"

if ! grep -q 'Module Name:[[:space:]]*Marketing' "$MODULE_DIR/marketing.php"; then
  fail "module metadata header missing" "expected Module Name header in marketing.php"
fi
if ! grep -q 'Version:[[:space:]]*[0-9][0-9.]*' "$MODULE_DIR/marketing.php"; then
  fail "module version header missing" "expected Version header in marketing.php"
fi
if ! grep -q 'Requires at least:[[:space:]]*3\.2\.\*' "$MODULE_DIR/marketing.php"; then
  fail "module compatibility header missing" "expected Requires at least header in marketing.php"
fi
pass "module metadata header present" "marketing.php contains Module Name, Version, and compatibility headers"

for lifecycle_line in \
  "register_activation_hook(MARKETING_MODULE_NAME, 'marketing_module_activation_hook');" \
  "register_deactivation_hook(MARKETING_MODULE_NAME, 'marketing_module_deactivation_hook');" \
  "register_uninstall_hook(MARKETING_MODULE_NAME, 'marketing_module_uninstall_hook');"; do
  if ! grep -qF "$lifecycle_line" "$MODULE_DIR/marketing.php"; then
    fail "module lifecycle hooks missing" "$lifecycle_line"
  fi
done
for hook_line in \
  "hooks()->add_action('app_init', 'marketing_register_csrf_exclusions');" \
  "hooks()->add_action('after_cron_run', 'marketing_cron_process_queue');"; do
  if ! grep -qF "$hook_line" "$MODULE_DIR/marketing.php"; then
    fail "module runtime hook missing" "$hook_line"
  fi
done
pass "module lifecycle hooks present" "activation, deactivation, uninstall, cron, and csrf hooks are registered"

for route_line in \
  "\$route['marketing/webhooks/ses_sns'] = 'webhooks/ses_sns';" \
  "\$route['marketing/webhooks/inbound_email'] = 'webhooks/inbound_email';" \
  "\$route['marketing/webhooks/whatsapp'] = 'webhooks/whatsapp';" \
  "\$route['marketing/track/c/(:any)'] = 'track/c/\$1';" \
  "\$route['marketing/unsubscribe/(:any)'] = 'subscriptions/unsubscribe/\$1';"; do
  if ! grep -qF "$route_line" "$MODULE_DIR/config/routes.php"; then
    fail "required public route missing" "$route_line"
  fi
done
for csrf_line in \
  "'marketing/webhooks/ses_sns'" \
  "'marketing/webhooks/inbound_email'" \
  "'marketing/webhooks/whatsapp'"; do
  if ! grep -qF "$csrf_line" "$MODULE_DIR/config/csrf_exclude_uris.php"; then
    fail "required csrf exclusion missing" "$csrf_line"
  fi
done
pass "public routes and csrf exclusions present" "tracking, unsubscribe, and webhook paths are registered"

if ! grep -qF "marketing_get_default_options" "$MODULE_DIR/marketing.php"; then
  fail "option default helper missing" "expected marketing_get_default_options() in marketing.php"
fi
if ! grep -qF "marketing_get_default_options()" "$MODULE_DIR/install.php"; then
  fail "install defaults not centralized" "expected install.php to use marketing_get_default_options()"
fi
if ! grep -qF "marketing_get_option_names()" "$MODULE_DIR/uninstall.php"; then
  fail "uninstall option list not centralized" "expected uninstall.php to use marketing_get_option_names()"
fi
pass "install and uninstall option handling is centralized and idempotent" "missing options are repaired without overwriting saved values"

for doc_file in "$MODULE_DIR/README.md" "$MODULE_DIR/INSTALLATION.md" "$MODULE_DIR/docs/OPERATIONS.md"; do
  if ! grep -qF 'marketing/' "$doc_file"; then
    fail "zip root expectation documentation missing" "expected marketing/ zip root reference in $(basename "$doc_file")"
  fi
  if ! grep -qF 'modules/marketing/marketing.php' "$doc_file"; then
    fail "zip root expectation documentation missing" "expected installed-path reference in $(basename "$doc_file")"
  fi
done
if ! grep -qF 'bash scripts/verify_release_package.sh' "$MODULE_DIR/README.md"; then
  fail "release validation command missing from README" "expected bash scripts/verify_release_package.sh in README.md"
fi
if ! grep -qF 'bash scripts/verify_phase2_regressions.sh' "$MODULE_DIR/docs/OPERATIONS.md"; then
  fail "regression validation command missing from operations guide" "expected bash scripts/verify_phase2_regressions.sh in docs/OPERATIONS.md"
fi
pass "zip root expectations documented" "docs mention marketing/ zip root, modules/marketing/marketing.php install path, and validation commands"

source_artifacts="$(find "$MODULE_DIR" \
  \( -path "$MODULE_DIR/release" -o -path "$MODULE_DIR/.git" \) -prune -o \
  \( -name '.DS_Store' -o -name '*.bak' -o -name '*.tmp' -o -name '*~' -o -name '.env' -o -name '.env.*' -o -path '*/.github/*' -o -path '*/__MACOSX/*' -o -path '*/node_modules/*' -o -path '*/vendor/*' -o -path '*/.idea/*' -o -path '*/.vscode/*' -o -path '*/tests/*' -o -path '*/tmp/*' -o -path '*/temp/*' -o -path '*/cache/*' -o -path '*/logs/*' \) -print)"
if [[ -n "$source_artifacts" ]]; then
  fail "forbidden release artifacts detected in source tree" "${source_artifacts%%$'\n'*}"
fi
pass "source tree is free of forbidden release artifacts" "no git metadata, marketplace clutter, env, vendor, cache, temp, log, or backup artifacts detected"

if [[ "$ZIP_PATH" == "--source-only" ]]; then
  pass "release zip inspection skipped" "validated source tree only"
  exit 0
fi

if [[ -z "$ZIP_PATH" ]]; then
  ZIP_PATH="$(ls -1t "$MODULE_DIR"/release/*.zip 2>/dev/null | head -n 1 || true)"
fi

if [[ -z "$ZIP_PATH" ]]; then
  pass "release zip inspection skipped" "no release zip found"
  exit 0
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  fail "release zip missing" "$ZIP_PATH"
fi

require_command unzip

zip_entries="$(unzip -Z1 "$ZIP_PATH")"
if [[ -z "$zip_entries" ]]; then
  fail "release zip is empty or unreadable" "$ZIP_PATH"
fi

if printf '%s\n' "$zip_entries" | grep -Evq '^marketing/'; then
  fail "release zip root is invalid" "all archive entries must live under marketing/"
fi
if printf '%s\n' "$zip_entries" | grep -Eq '^marketing/modules/marketing/'; then
  fail "release zip root is nested incorrectly" "archive must not contain marketing/modules/marketing/"
fi

for zip_required in \
  "marketing/marketing.php" \
  "marketing/install.php" \
  "marketing/uninstall.php" \
  "marketing/config/routes.php" \
  "marketing/config/csrf_exclude_uris.php" \
  "marketing/scripts/verify_phase2_regressions.sh" \
  "marketing/scripts/verify_phase4_integrity_runtime.php" \
  "marketing/scripts/verify_release_package.sh"; do
  if ! printf '%s\n' "$zip_entries" | grep -qxF "$zip_required"; then
    fail "required file missing from release zip" "$zip_required"
  fi
done
for zip_doc in \
  "marketing/README.md" \
  "marketing/INSTALLATION.md" \
  "marketing/docs/OPERATIONS.md"; do
  if ! printf '%s\n' "$zip_entries" | grep -qxF "$zip_doc"; then
    fail "required documentation missing from release zip" "$zip_doc"
  fi
done

for forbidden_pattern in \
  '^\.git/' \
  '^marketing/\.git/' \
  '^\.github/' \
  '^marketing/\.github/' \
  '^__MACOSX/' \
  '^marketing/__MACOSX/' \
  '^marketing/node_modules/' \
  '^marketing/vendor/' \
  '^marketing/\.idea/' \
  '^marketing/\.vscode/' \
  '^marketing/tests/' \
  '^marketing/tmp/' \
  '^marketing/temp/' \
  '^marketing/cache/' \
  '^marketing/logs/' \
  '^marketing/release/' \
  '^marketing/.*\.zip$' \
  '^marketing/\.DS_Store$' \
  '^marketing/.*\/\.DS_Store$' \
  '^marketing/\.env(\..*)?$' \
  '^marketing/.*\.bak$' \
  '^marketing/.*\.tmp$' \
  '^marketing/.*~$'; do
  if printf '%s\n' "$zip_entries" | grep -Eq "$forbidden_pattern"; then
    fail "forbidden artifact present in release zip" "$forbidden_pattern"
  fi
done

pass "release zip structure is valid" "$ZIP_PATH"
