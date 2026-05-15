#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT_DIR"

TRACK_FILE="modules/marketing/controllers/Track.php"
EVENTS_FILE="modules/marketing/models/Marketing_events_model.php"
CONVERSATIONS_FILE="modules/marketing/models/Marketing_conversations_model.php"
CAMPAIGNS_FILE="modules/marketing/models/Marketing_campaigns_model.php"
DASHBOARD_FILE="modules/marketing/models/Marketing_dashboard_model.php"
PUBLIC_LINKS_FILE="modules/marketing/models/Marketing_public_links_model.php"
ROUTES_FILE="modules/marketing/config/routes.php"
CSRF_FILE="modules/marketing/config/csrf_exclude_uris.php"
SCHEMA_FILE="modules/marketing/libraries/Marketing_schema.php"
BOOTSTRAP_FILE="modules/marketing/marketing.php"
WEBHOOKS_FILE="modules/marketing/controllers/Webhooks.php"
SES_SNS_FILE="modules/marketing/libraries/Marketing_ses_sns.php"
INSTALL_FILE="modules/marketing/install.php"
UNINSTALL_FILE="modules/marketing/uninstall.php"
MAILER_FILE="modules/marketing/libraries/Marketing_mailer.php"
WHATSAPP_FILE="modules/marketing/libraries/Marketing_whatsapp.php"
SUBSCRIPTIONS_CONTROLLER_FILE="modules/marketing/controllers/Subscriptions.php"
DASHBOARD_VIEW_FILE="modules/marketing/views/dashboard.php"
REPORTS_VIEW_FILE="modules/marketing/views/reports.php"
MARKETING_CONTROLLER_FILE="modules/marketing/controllers/Marketing.php"
CAMPAIGNS_CONTROLLER_FILE="modules/marketing/controllers/Campaigns.php"
ANALYTICS_CONTROLLER_FILE="modules/marketing/controllers/Analytics.php"
SETTINGS_CONTROLLER_FILE="modules/marketing/controllers/Settings.php"
INTEGRITY_MODEL_FILE="modules/marketing/models/Marketing_integrity_model.php"
INTEGRITY_RUNTIME_FILE="modules/marketing/scripts/verify_phase4_integrity_runtime.php"
INTEGRITY_REPAIR_FILE="modules/marketing/scripts/repair_phase4_integrity_runtime.php"
README_FILE="modules/marketing/README.md"
INSTALLATION_FILE="modules/marketing/INSTALLATION.md"
OPERATIONS_FILE="modules/marketing/docs/OPERATIONS.md"
PRODUCTION_CHECKLIST_FILE="modules/marketing/docs/PRODUCTION_CHECKLIST.md"
RELEASE_MANIFEST_DOC_FILE="modules/marketing/docs/RELEASE_MANIFEST.md"
RELEASE_MANIFEST_JSON_FILE="modules/marketing/release/manifest.json"
RELEASE_VALIDATOR_FILE="modules/marketing/scripts/verify_release_package.sh"
RELEASE_BUILDER_FILE="modules/marketing/scripts/build_release_package.sh"
CAMPAIGN_FORM_VIEW_FILE="modules/marketing/views/campaigns/form.php"
CAMPAIGN_SHOW_VIEW_FILE="modules/marketing/views/campaigns/show.php"
CAMPAIGN_INDEX_VIEW_FILE="modules/marketing/views/campaigns/index.php"
CAMPAIGN_PREVIEW_VIEW_FILE="modules/marketing/views/campaigns/preview.php"
BUILDER_JS_ASSET_FILE="modules/marketing/assets/js/marketing-builder.js"
ANALYTICS_VIEW_FILE="modules/marketing/views/analytics/index.php"
ANALYTICS_FALLBACK_VIEW_FILE="modules/marketing/views/analytics/fallback.php"
SETTINGS_VIEW_FILE="modules/marketing/views/settings/index.php"
UNSUBSCRIBE_VIEW_FILE="modules/marketing/views/subscriptions/unsubscribe_result.php"
LANGUAGE_FILE="modules/marketing/language/english/marketing_lang.php"
FALSE_UNSUBSCRIBE_REPAIR_FILE="modules/marketing/scripts/repair_false_unsubscribe_suppressions.php"

PASS_COUNT=0
FAIL_COUNT=0

require_command() {
  local command_name="$1"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf 'FAIL dependency check\n'
    printf '  missing required command: %s\n' "$command_name"
    exit 1
  fi
}

check_dependencies() {
  require_command bash
  require_command grep
  require_command sed
  require_command php
}

pass() {
  printf 'PASS %s\n' "$1"
  if [[ $# -ge 2 && -n "${2:-}" ]]; then
    printf '  %s\n' "$2"
  fi
  PASS_COUNT=$((PASS_COUNT + 1))
}

fail() {
  printf 'FAIL %s\n' "$1"
  if [[ $# -ge 2 && -n "${2:-}" ]]; then
    printf '  %s\n' "$2"
  fi
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

first_match() {
  local pattern="$1"
  local file="$2"
  grep -nE -- "$pattern" "$file" | head -n 1
}

first_fixed_match() {
  local needle="$1"
  local file="$2"
  grep -nF -- "$needle" "$file" | head -n 1
}

git_worktree_available() {
  if ! command -v git >/dev/null 2>&1; then
    return 1
  fi

  git rev-parse --is-inside-work-tree >/dev/null 2>&1
}

git_changed_path_if_available() {
  local path="$1"

  if ! git_worktree_available; then
    return 0
  fi

  git diff --name-only -- "$path" 2>/dev/null || true
}

check_no_raw_expression_where_in() {
  local matches bad
  matches="$(grep -nE -- 'where_in[[:space:]]*\(' "$EVENTS_FILE" "$DASHBOARD_FILE" || true)"
  bad=''

  if [[ -n "$matches" ]]; then
    bad="$(printf '%s\n' "$matches" | grep -E -- 'normalized_event_type_sql|LOWER[[:space:]]*\(|REPLACE[[:space:]]*\(' || true)"
  fi

  if [[ -n "$bad" ]]; then
    fail \
      "normalized event filter does not use raw SQL expressions inside CodeIgniter where_in()" \
      "expected plain column keys only; found: ${bad%%$'\n'*}"
    return
  fi

  pass \
    "normalized event filter does not use raw SQL expressions inside CodeIgniter where_in()" \
    "scanned where_in() calls in $EVENTS_FILE and $DASHBOARD_FILE"
}

check_dashboard_contacts_source() {
  local total_contacts_line helper_line distinct_line
  total_contacts_line="$(first_match "'total_contacts'" "$DASHBOARD_FILE")"

  if [[ -z "$total_contacts_line" ]]; then
    fail \
      "dashboard exposes total_contacts KPI" \
      "expected a total_contacts assignment in $DASHBOARD_FILE"
    return
  fi

  if [[ "$total_contacts_line" == *'count_all($this->contactsTable)'* ]]; then
    fail \
      "dashboard total_contacts does not use raw count_all(\$contactsTable)" \
      "found raw contacts count: $total_contacts_line"
    return
  fi

  if [[ "$total_contacts_line" != *'count_active_audience_contacts()'* ]]; then
    fail \
      "dashboard total_contacts uses active audience membership semantics" \
      "expected count_active_audience_contacts(), found: $total_contacts_line"
    return
  fi

  helper_line="$(first_match 'function[[:space:]]+count_active_audience_contacts' "$DASHBOARD_FILE")"
  distinct_line="$(first_match 'COUNT[[:space:]]*\([[:space:]]*DISTINCT[[:space:]]+c\.id[[:space:]]*\)' "$DASHBOARD_FILE")"

  if [[ -z "$helper_line" || -z "$distinct_line" ]]; then
    fail \
      "dashboard active audience contact helper counts distinct member-backed contacts" \
      "expected helper and DISTINCT count in $DASHBOARD_FILE; helper=${helper_line:-missing}; distinct=${distinct_line:-missing}"
    return
  fi

  pass \
    "dashboard contacts KPI uses active audience membership semantics" \
    "assignment=$total_contacts_line | distinct_count=$distinct_line"
}

check_no_track_debug() {
  local debug_line
  debug_line="$(first_fixed_match 'MARKETING_TRACK_DEBUG' "$TRACK_FILE")"

  if [[ -n "$debug_line" ]]; then
    fail \
      "track controller contains no temporary MARKETING_TRACK_DEBUG logging" \
      "expected no debug marker in $TRACK_FILE; found: $debug_line"
    return
  fi

  pass \
    "track controller contains no temporary MARKETING_TRACK_DEBUG logging" \
    "no MARKETING_TRACK_DEBUG marker found in $TRACK_FILE"
}

check_ses_tracking_guard() {
  local function_line provider_line path_line
  function_line="$(first_match 'function[[:space:]]+should_count_recipient_click_metric' "$CAMPAIGNS_FILE")"
  provider_line="$(first_fixed_match "provider !== 'ses_sns'" "$CAMPAIGNS_FILE")"
  path_line="$(first_fixed_match '/marketing/track/c/' "$CAMPAIGNS_FILE")"

  if [[ -z "$function_line" || -z "$provider_line" || -z "$path_line" ]]; then
    fail \
      "SES tracking URL guard exists for raw-only internal click telemetry" \
      "expected should_count_recipient_click_metric() to guard ses_sns /marketing/track/c/ URLs in $CAMPAIGNS_FILE; function=${function_line:-missing}; provider=${provider_line:-missing}; path=${path_line:-missing}"
    return
  fi

  pass \
    "SES tracking URL guard exists for raw-only internal click telemetry" \
    "function=$function_line | provider_check=$provider_line | path_check=$path_line"
}

check_tracking_route() {
  local route_line
  route_line="$(first_fixed_match "\$route['marketing/track/c/(:any)'] = 'track/c/\$1';" "$ROUTES_FILE")"

  if [[ -z "$route_line" ]]; then
    fail \
      "public tracking route marketing/track/c/(:any) remains unchanged" \
      "expected route \$route['marketing/track/c/(:any)'] = 'track/c/\$1'; in $ROUTES_FILE"
    return
  fi

  pass \
    "public tracking route marketing/track/c/(:any) remains unchanged" \
    "route=$route_line"
}

check_events_and_dashboard_guards() {
  local events_summary_guard events_count_guard dashboard_guard
  events_summary_guard="$(first_fixed_match "\$this->db->table_exists(\$this->table)" "$EVENTS_FILE")"
  events_count_guard="$(first_fixed_match "\$this->column_exists('event_type')" "$EVENTS_FILE")"
  dashboard_guard="$(first_fixed_match "\$this->db->table_exists(\$this->eventsTable)" "$DASHBOARD_FILE")"

  if [[ -z "$events_summary_guard" || -z "$events_count_guard" || -z "$dashboard_guard" ]]; then
    fail \
      "events/dashboard methods guard missing event tables and optional event columns" \
      "expected event-table guards in $EVENTS_FILE and $DASHBOARD_FILE; events_table=${events_summary_guard:-missing}; event_type=${events_count_guard:-missing}; dashboard_table=${dashboard_guard:-missing}"
    return
  fi

  pass \
    "events/dashboard methods guard missing event tables and optional event columns" \
    "events_table=$events_summary_guard | event_type=$events_count_guard | dashboard_table=$dashboard_guard"
}

check_public_links_filtering() {
  local filter_method call_line touch_line empty_return
  filter_method="$(first_match 'function[[:space:]]+filter_existing_columns' "$PUBLIC_LINKS_FILE")"
  call_line="$(first_fixed_match 'filter_existing_columns($update)' "$PUBLIC_LINKS_FILE")"
  touch_line="$(first_match 'function[[:space:]]+touch_token_use' "$PUBLIC_LINKS_FILE")"
  empty_return="$(first_match 'return[[:space:]]+\[\];' "$PUBLIC_LINKS_FILE")"

  if [[ -z "$filter_method" || -z "$call_line" || -z "$touch_line" || -z "$empty_return" ]]; then
    fail \
      "public link helpers filter writes through filter_existing_columns() and drop unknown drifted fields" \
      "expected filter_existing_columns() safeguards in $PUBLIC_LINKS_FILE; method=${filter_method:-missing}; update_call=${call_line:-missing}; touch=${touch_line:-missing}; empty_return=${empty_return:-missing}"
    return
  fi

  pass \
    "public link helpers filter writes through filter_existing_columns() and drop unknown drifted fields" \
    "filter_method=$filter_method | update_call=$call_line | fallback=$empty_return"
}

check_click_dedupe_window() {
  local constant_line value
  constant_line="$(first_match 'UNIQUE_CLICK_WINDOW_SECONDS' "$TRACK_FILE")"

  if [[ -z "$constant_line" ]]; then
    fail \
      "click dedupe window equals 120 seconds" \
      "expected UNIQUE_CLICK_WINDOW_SECONDS in $TRACK_FILE"
    return
  fi

  value="$(printf '%s\n' "$constant_line" | sed -nE 's/.*UNIQUE_CLICK_WINDOW_SECONDS[^0-9]*([0-9]+).*/\1/p')"
  if [[ -z "$value" ]]; then
    fail \
      "click dedupe window equals 120 seconds" \
      "could not parse numeric value from: $constant_line"
    return
  fi

  if [[ "$value" != "120" ]]; then
    fail \
      "click dedupe window equals 120 seconds" \
      "expected 120, found $value in $constant_line"
    return
  fi

  pass \
    "click dedupe window equals 120 seconds" \
    "constant=$constant_line"
}

check_tracking_link_dedupe() {
  local track_usage events_usage
  track_usage="$(first_fixed_match 'tracking_link_id' "$TRACK_FILE")"
  events_usage="$(first_fixed_match 'tracking_link_id' "$EVENTS_FILE")"

  if [[ -z "$track_usage" || -z "$events_usage" ]]; then
    fail \
      "module click dedupe uses tracking_link_id" \
      "expected tracking_link_id in both $TRACK_FILE and $EVENTS_FILE; track=${track_usage:-missing}; events=${events_usage:-missing}"
    return
  fi

  pass \
    "module click dedupe uses tracking_link_id" \
    "track_usage=$track_usage | events_usage=$events_usage"
}

check_duplicate_redirect_metric_gate() {
  local assignment_line count_metric_line unique_metric_line
  assignment_line="$(first_fixed_match 'countHumanEngagement' "$TRACK_FILE")"
  count_metric_line="$(first_fixed_match "'count_metric' => \$countHumanEngagement" "$TRACK_FILE")"
  unique_metric_line="$(first_fixed_match "'count_unique_metric' => \$countHumanEngagement" "$TRACK_FILE")"

  if [[ -z "$assignment_line" || -z "$count_metric_line" || -z "$unique_metric_line" ]]; then
    fail \
      "duplicate module redirects gate recipient KPI updates through countHumanEngagement" \
      "expected countHumanEngagement to drive recipient KPI writes in $TRACK_FILE; assignment=${assignment_line:-missing}; count_metric=${count_metric_line:-missing}; count_unique_metric=${unique_metric_line:-missing}"
    return
  fi

  pass \
    "duplicate module redirects gate recipient KPI updates through countHumanEngagement" \
    "assignment=$assignment_line | count_metric=$count_metric_line | unique_metric=$unique_metric_line"
}

check_campaign_eligibility_semantics() {
  local shared_helper queue_helper public_snapshot queue_usage import_gate_line suppression_map_line explicit_inactive_line inactive_helper_line audiences_status_block_line operations_note_line forbidden_core_diff git_diff_mode
  shared_helper="$(first_match 'function[[:space:]]+build_campaign_eligibility_snapshot' "$CAMPAIGNS_FILE")"
  queue_helper="$(first_match 'function[[:space:]]+get_campaign_queue_targeting_snapshot' "$CAMPAIGNS_FILE")"
  public_snapshot="$(first_fixed_match 'return $this->build_campaign_eligibility_snapshot($campaign, $overrideRules, false);' "$CAMPAIGNS_FILE")"
  queue_usage="$(first_fixed_match '$snapshot = $this->get_campaign_queue_targeting_snapshot($campaign);' "$CAMPAIGNS_FILE")"
  import_gate_line="$(first_fixed_match 'if (!$this->is_sendable_consent($member['\''consent_status'\''] ?? null) || $contactStatus === '\''unsubscribed'\'') {' "$CAMPAIGNS_FILE")"
  suppression_map_line="$(first_fixed_match '$isSuppressed = $isSuppressed || ($normalizedEmail !== null && isset($suppressedEmails[$normalizedEmail]));' "$CAMPAIGNS_FILE")"
  explicit_inactive_line="$(first_fixed_match 'if (!$this->is_active_status($member['\''member_status'\''] ?? null) || $this->contact_is_explicitly_inactive($contactStatus)) {' "$CAMPAIGNS_FILE")"
  inactive_helper_line="$(first_match 'function[[:space:]]+contact_is_explicitly_inactive' "$CAMPAIGNS_FILE")"
  audiences_status_block_line="$(grep -nF -- "['suppressed', 'bounced', 'complained', 'unsubscribed']" modules/marketing/models/Marketing_audiences_model.php | head -n 1 || true)"
  operations_note_line="$(first_fixed_match 'Imported contacts remain active for audience eligibility unless they are explicitly unsubscribed' "$OPERATIONS_FILE")"
  forbidden_core_diff="$(git_changed_path_if_available application/controllers/AdminController.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$shared_helper" || -z "$queue_helper" || -z "$public_snapshot" || -z "$queue_usage" || -z "$import_gate_line" || -z "$suppression_map_line" || -z "$explicit_inactive_line" || -z "$inactive_helper_line" || -n "$audiences_status_block_line" || -z "$operations_note_line" || -n "$forbidden_core_diff" ]]; then
    fail \
      "campaign eligibility excludes imported contacts only through explicit unsubscribe, suppression, inactive membership, or targeting" \
      "expected shared eligibility helper, explicit unsubscribe gate, suppression-map check, explicit inactive helper, no stale status-only suppression fallback, operator note, and no core diffs; shared=${shared_helper:-missing}; queue_helper=${queue_helper:-missing}; public=${public_snapshot:-missing}; queue=${queue_usage:-missing}; unsubscribe_gate=${import_gate_line:-missing}; suppression_map=${suppression_map_line:-missing}; inactive_gate=${explicit_inactive_line:-missing}; inactive_helper=${inactive_helper_line:-missing}; audiences_status_block=${audiences_status_block_line:-none}; operations_note=${operations_note_line:-missing}; git_diff_mode=${git_diff_mode:-unknown}; core_diff=${forbidden_core_diff:-none}"
    return
  fi

  pass \
    "campaign eligibility excludes imported contacts only through explicit unsubscribe, suppression, inactive membership, or targeting" \
    "shared_helper=$shared_helper | unsubscribe_gate=$import_gate_line | suppression_map=$suppression_map_line | git_diff_mode=$git_diff_mode"
}

check_processing_recovery_timeout() {
  local timeout_line reserve_line recover_line fallback_line
  timeout_line="$(first_match 'PROCESSING_STALE_TIMEOUT_SECONDS' "$CAMPAIGNS_FILE")"
  reserve_line="$(first_fixed_match "'status' => 'processing'" "$CAMPAIGNS_FILE")"
  recover_line="$(first_fixed_match "'status' => 'queued'" "$CAMPAIGNS_FILE")"
  fallback_line="$(first_fixed_match "dateupdated IS NULL" "$CAMPAIGNS_FILE")"
  clear_lease_line="$(first_fixed_match "'processing_token' => null" "$CAMPAIGNS_FILE")"

  if [[ -z "$timeout_line" ]]; then
    fail \
      "stale processing recovery uses an explicit timeout" \
      "expected PROCESSING_STALE_TIMEOUT_SECONDS in $CAMPAIGNS_FILE"
    return
  fi

  local timeout_value
  timeout_value="$(printf '%s\n' "$timeout_line" | sed -nE 's/.*PROCESSING_STALE_TIMEOUT_SECONDS[^0-9]*([0-9]+).*/\1/p')"
  if [[ "$timeout_value" != "1800" ]]; then
    fail \
      "stale processing recovery uses an explicit timeout" \
      "expected 1800 seconds, found ${timeout_value:-unparseable} in $timeout_line"
    return
  fi

  if [[ -z "$reserve_line" || -z "$recover_line" || -z "$fallback_line" || -z "$clear_lease_line" ]]; then
    fail \
      "recipient processing lifecycle records timestamps and only recovers stale rows" \
      "expected processing reserve, queued recovery, dateupdated null fallback, and processing_token clear in $CAMPAIGNS_FILE; reserve=${reserve_line:-missing}; recover=${recover_line:-missing}; fallback=${fallback_line:-missing}; clear_lease=${clear_lease_line:-missing}"
    return
  fi

  pass \
    "recipient processing lifecycle records timestamps and only recovers stale rows" \
    "timeout=$timeout_line | reserve=$reserve_line | clear_lease=$clear_lease_line"
}

check_processing_lease_ownership() {
  local schema_line bootstrap_line reserve_line owner_update_line ownership_where_line
  schema_line="$(first_fixed_match "'processing_token' => ['sql' => 'VARCHAR(96) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  bootstrap_line="$(first_match 'MARKETING_SCHEMA_BOOTSTRAP_VERSION' "$BOOTSTRAP_FILE")"
  reserve_line="$(first_fixed_match "'processing_token' => \$workerLeaseToken !== '' ? \$workerLeaseToken : null" "$CAMPAIGNS_FILE")"
  owner_update_line="$(first_match 'function[[:space:]]+update_reserved_recipient_status' "$CAMPAIGNS_FILE")"
  ownership_where_line="$(first_fixed_match "->where('processing_token', \$workerLeaseToken)" "$CAMPAIGNS_FILE")"

  if [[ -z "$schema_line" || -z "$bootstrap_line" || -z "$reserve_line" || -z "$owner_update_line" || -z "$ownership_where_line" ]]; then
    fail \
      "recipient processing lease token is schema-safe and enforced on finalization" \
      "expected schema, bootstrap version, reserve token, owned update helper, and ownership where clause; schema=${schema_line:-missing}; bootstrap=${bootstrap_line:-missing}; reserve=${reserve_line:-missing}; helper=${owner_update_line:-missing}; where=${ownership_where_line:-missing}"
    return
  fi

  pass \
    "recipient processing lease token is schema-safe and enforced on finalization" \
    "schema=$schema_line | reserve=$reserve_line | where=$ownership_where_line"
}

check_queue_reservation_transaction_integrity() {
  local helper_line begin_line rollback_line commit_line conflict_line warning_line
  helper_line="$(first_match 'function[[:space:]]+reserve_next_recipients' "$CAMPAIGNS_FILE")"
  begin_line="$(first_fixed_match '$this->db->trans_begin();' "$CAMPAIGNS_FILE")"
  rollback_line="$(first_fixed_match '$this->db->trans_rollback();' "$CAMPAIGNS_FILE")"
  commit_line="$(first_fixed_match '$this->db->trans_commit();' "$CAMPAIGNS_FILE")"
  conflict_line="$(first_fixed_match 'recipient_reservation_count_mismatch' "$CAMPAIGNS_FILE")"
  warning_line="$(first_fixed_match 'recipient_reservation_conflict' "$CAMPAIGNS_FILE")"

  if [[ -z "$helper_line" || -z "$begin_line" || -z "$rollback_line" || -z "$commit_line" || -z "$conflict_line" || -z "$warning_line" ]]; then
    fail \
      "recipient reservation is transactional and fails closed on partial ownership" \
      "expected reservation helper, transaction boundaries, count mismatch detection, and conflict warning in $CAMPAIGNS_FILE; helper=${helper_line:-missing}; begin=${begin_line:-missing}; rollback=${rollback_line:-missing}; commit=${commit_line:-missing}; mismatch=${conflict_line:-missing}; warning=${warning_line:-missing}"
    return
  fi

  pass \
    "recipient reservation is transactional and fails closed on partial ownership" \
    "helper=$helper_line | begin=$begin_line | mismatch=$conflict_line | warning=$warning_line"
}

check_outbound_attempt_schema_and_queue_guard() {
  local schema_line bootstrap_line queue_requirement helper_line header_line
  schema_line="$(first_fixed_match "'outbound_attempt_token' => ['sql' => 'VARCHAR(96) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  bootstrap_line="$(first_match 'MARKETING_SCHEMA_BOOTSTRAP_VERSION' "$BOOTSTRAP_FILE")"
  queue_requirement="$(first_fixed_match "\$this->recipientsTable => ['id', 'campaign_id', 'status', 'processing_token', 'outbound_attempt_token']" "$CAMPAIGNS_FILE")"
  helper_line="$(first_match 'function[[:space:]]+begin_reserved_outbound_attempt' "$CAMPAIGNS_FILE")"
  header_line="$(first_fixed_match 'X-Marketing-Outbound-Attempt-ID' "$MAILER_FILE")"

  if [[ -z "$schema_line" || -z "$bootstrap_line" || -z "$queue_requirement" || -z "$helper_line" || -z "$header_line" ]]; then
    fail \
      "outbound attempt token is schema-safe, queue-required, and exposed in mail headers" \
      "expected schema, bootstrap version, queue requirement, attempt helper, and outbound attempt header; schema=${schema_line:-missing}; bootstrap=${bootstrap_line:-missing}; queue=${queue_requirement:-missing}; helper=${helper_line:-missing}; header=${header_line:-missing}"
    return
  fi

  pass \
    "outbound attempt token is schema-safe, queue-required, and exposed in mail headers" \
    "schema=$schema_line | queue=$queue_requirement | header=$header_line"
}

check_outbound_attempt_assignment_and_retry_reset() {
  local assignment_line replay_warning_line retry_clear_line mailer_token_line
  assignment_line="$(first_fixed_match '$attemptStart = $this->begin_reserved_outbound_attempt($recipient, $workerLeaseToken);' "$CAMPAIGNS_FILE")"
  replay_warning_line="$(first_fixed_match 'outbound_attempt_replay_blocked' "$CAMPAIGNS_FILE")"
  retry_clear_line="$(first_fixed_match "'outbound_attempt_token' => null" "$CAMPAIGNS_FILE")"
  mailer_token_line="$(first_fixed_match "'outbound_attempt_token' => \$attemptToken" "$MAILER_FILE")"

  if [[ -z "$assignment_line" || -z "$replay_warning_line" || -z "$retry_clear_line" || -z "$mailer_token_line" ]]; then
    fail \
      "outbound attempt identity is assigned before provider send and reset safely on retry" \
      "expected pre-send assignment, replay block warning, retry reset, and mailer attempt token usage; assignment=${assignment_line:-missing}; replay=${replay_warning_line:-missing}; retry=${retry_clear_line:-missing}; mailer=${mailer_token_line:-missing}"
    return
  fi

  pass \
    "outbound attempt identity is assigned before provider send and reset safely on retry" \
    "assignment=$assignment_line | retry=$retry_clear_line | mailer=$mailer_token_line"
}

check_event_reconciliation_helper_exists() {
  local helper_line contact_guard_line
  helper_line="$(first_match 'function[[:space:]]+build_recipient_event_reconciliation' "$CAMPAIGNS_FILE")"
  contact_guard_line="$(first_match 'function[[:space:]]+recipient_contact_state_blocks_engagement_events' "$CAMPAIGNS_FILE")"

  if [[ -z "$helper_line" || -z "$contact_guard_line" ]]; then
    fail \
      "recipient provider-event reconciliation is centralized in the campaigns model" \
      "expected reconciliation and contact-state guards in $CAMPAIGNS_FILE; helper=${helper_line:-missing}; contact_guard=${contact_guard_line:-missing}"
    return
  fi

  pass \
    "recipient provider-event reconciliation is centralized in the campaigns model" \
    "helper=$helper_line | contact_guard=$contact_guard_line"
}

check_event_terminal_state_precedence() {
  local status_guard_line failed_guard_line block_reason_line failure_block_line
  status_guard_line="$(first_fixed_match "return in_array(\$status, ['suppressed', 'skipped'], true);" "$CAMPAIGNS_FILE")"
  failed_guard_line="$(first_fixed_match 'failed_status_requires_strong_identity' "$CAMPAIGNS_FILE")"
  block_reason_line="$(first_fixed_match 'terminal_status_blocks_event' "$CAMPAIGNS_FILE")"
  failure_block_line="$(first_fixed_match 'terminal_status_blocks_failure' "$CAMPAIGNS_FILE")"

  if [[ -z "$status_guard_line" || -z "$failed_guard_line" || -z "$block_reason_line" || -z "$failure_block_line" ]]; then
    fail \
      "delivery, open, and click preserve suppressed or skipped terminal states while requiring strong identity for failed recovery" \
      "expected suppressed/skipped terminal guards, failed strong-identity gate, and failure-event blocking in $CAMPAIGNS_FILE; statuses=${status_guard_line:-missing}; failed=${failed_guard_line:-missing}; engagement=${block_reason_line:-missing}; failure=${failure_block_line:-missing}"
    return
  fi

  pass \
    "delivery, open, and click preserve suppressed or skipped terminal states while requiring strong identity for failed recovery" \
    "statuses=$status_guard_line | failed=$failed_guard_line | failure=$failure_block_line"
}

check_stale_outbound_identity_guard() {
  local match_helper_line attempt_mismatch_line provider_mismatch_line webhook_extract_line
  match_helper_line="$(first_match 'function[[:space:]]+event_matches_active_outbound_identity' "$CAMPAIGNS_FILE")"
  attempt_mismatch_line="$(first_fixed_match 'outbound_attempt_token_mismatch' "$CAMPAIGNS_FILE")"
  provider_mismatch_line="$(first_fixed_match 'provider_message_identity_mismatch' "$CAMPAIGNS_FILE")"
  webhook_extract_line="$(first_fixed_match 'X-Marketing-Outbound-Attempt-ID' "$WEBHOOKS_FILE")"

  if [[ -z "$match_helper_line" || -z "$attempt_mismatch_line" || -z "$provider_mismatch_line" || -z "$webhook_extract_line" ]]; then
    fail \
      "stale outbound attempt or provider message identity is checked before recipient mutation" \
      "expected identity reconciliation guards in $CAMPAIGNS_FILE and SES metadata extraction in $WEBHOOKS_FILE; helper=${match_helper_line:-missing}; attempt=${attempt_mismatch_line:-missing}; provider=${provider_mismatch_line:-missing}; webhook=${webhook_extract_line:-missing}"
    return
  fi

  pass \
    "stale outbound attempt or provider message identity is checked before recipient mutation" \
    "helper=$match_helper_line | attempt=$attempt_mismatch_line | provider=$provider_mismatch_line"
}

check_raw_event_logging_after_reconciliation_skip() {
  local payload_line add_event_line
  payload_line="$(first_fixed_match "_recipient_reconciliation" "$WEBHOOKS_FILE")"
  add_event_line="$(first_fixed_match '$storeResult = $this->marketing_events_model->add_event_idempotent([' "$WEBHOOKS_FILE")"

  if [[ -z "$payload_line" || -z "$add_event_line" ]]; then
    fail \
      "raw SES SNS events are still logged even when recipient mutation is skipped" \
      "expected reconciliation audit metadata and idempotent event storage in $WEBHOOKS_FILE; payload=${payload_line:-missing}; add_event=${add_event_line:-missing}"
    return
  fi

  pass \
    "raw SES SNS events are still logged even when recipient mutation is skipped" \
    "payload=$payload_line | add_event=$add_event_line"
}

check_campaign_worker_lease_schema() {
  local token_line started_line heartbeat_line bootstrap_line
  token_line="$(first_fixed_match "'queue_worker_token' => ['sql' => 'VARCHAR(96) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  started_line="$(first_fixed_match "'queue_worker_started_at' => ['sql' => 'DATETIME NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  heartbeat_line="$(first_fixed_match "'queue_worker_heartbeat_at' => ['sql' => 'DATETIME NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  bootstrap_line="$(first_match 'MARKETING_SCHEMA_BOOTSTRAP_VERSION' "$BOOTSTRAP_FILE")"

  if [[ -z "$token_line" || -z "$started_line" || -z "$heartbeat_line" || -z "$bootstrap_line" ]]; then
    fail \
      "campaign worker lease fields are schema-safe and bootstrap-managed" \
      "expected queue worker token/started/heartbeat columns in $SCHEMA_FILE and bootstrap version in $BOOTSTRAP_FILE; token=${token_line:-missing}; started=${started_line:-missing}; heartbeat=${heartbeat_line:-missing}; bootstrap=${bootstrap_line:-missing}"
    return
  fi

  pass \
    "campaign worker lease fields are schema-safe and bootstrap-managed" \
    "token=$token_line | started=$started_line | heartbeat=$heartbeat_line"
}

check_campaign_worker_lease_acquisition() {
  local helper_line usage_line reservation_line
  helper_line="$(first_match 'function[[:space:]]+acquire_campaign_worker_lease' "$CAMPAIGNS_FILE")"
  usage_line="$(first_fixed_match '$campaignLease = $this->acquire_campaign_worker_lease((int) $campaign[' "$CAMPAIGNS_FILE")"
  reservation_line="$(first_fixed_match '$reservation = $this->reserve_next_recipients($campaign[' "$CAMPAIGNS_FILE")"

  if [[ -z "$helper_line" || -z "$usage_line" || -z "$reservation_line" ]]; then
    fail \
      "campaign worker lease is acquired before recipient reservation begins" \
      "expected lease helper and acquisition before reservation in $CAMPAIGNS_FILE; helper=${helper_line:-missing}; usage=${usage_line:-missing}; reservation=${reservation_line:-missing}"
    return
  fi

  pass \
    "campaign worker lease is acquired before recipient reservation begins" \
    "helper=$helper_line | usage=$usage_line | reservation=$reservation_line"
}

check_campaign_worker_lease_release_guard() {
  local release_helper_line owner_where_line finally_line
  release_helper_line="$(first_match 'function[[:space:]]+release_campaign_worker_lease' "$CAMPAIGNS_FILE")"
  owner_where_line="$(first_fixed_match "\$this->db->where('queue_worker_token', \$workerToken);" "$CAMPAIGNS_FILE")"
  finally_line="$(first_fixed_match '$this->release_campaign_worker_lease((int) $campaign[' "$CAMPAIGNS_FILE")"

  if [[ -z "$release_helper_line" || -z "$owner_where_line" || -z "$finally_line" ]]; then
    fail \
      "campaign worker lease release is owner-token guarded" \
      "expected release helper, owner-token where clause, and finally release call in $CAMPAIGNS_FILE; helper=${release_helper_line:-missing}; where=${owner_where_line:-missing}; finally=${finally_line:-missing}"
    return
  fi

  pass \
    "campaign worker lease release is owner-token guarded" \
    "helper=$release_helper_line | where=$owner_where_line | finally=$finally_line"
}

check_campaign_worker_lease_stale_recovery() {
  local timeout_line helper_line heartbeat_line warning_line
  timeout_line="$(first_match 'QUEUE_WORKER_STALE_TIMEOUT_SECONDS' "$CAMPAIGNS_FILE")"
  helper_line="$(first_match 'function[[:space:]]+campaign_worker_lease_is_stale' "$CAMPAIGNS_FILE")"
  heartbeat_line="$(first_fixed_match 'queue_worker_heartbeat_at' "$CAMPAIGNS_FILE")"
  warning_line="$(first_fixed_match 'campaign_worker_lease_takeover_failed' "$CAMPAIGNS_FILE")"

  if [[ -z "$timeout_line" || -z "$helper_line" || -z "$heartbeat_line" || -z "$warning_line" ]]; then
    fail \
      "stale campaign worker leases are detected and recoverable" \
      "expected worker stale timeout, stale helper, heartbeat tracking, and takeover handling in $CAMPAIGNS_FILE; timeout=${timeout_line:-missing}; helper=${helper_line:-missing}; heartbeat=${heartbeat_line:-missing}; warning=${warning_line:-missing}"
    return
  fi

  pass \
    "stale campaign worker leases are detected and recoverable" \
    "timeout=$timeout_line | helper=$helper_line | heartbeat=$heartbeat_line"
}

check_batch_limit_clamp() {
  local const_line helper_line warning_line rate_line
  const_line="$(first_match 'MAX_SEND_BATCH_LIMIT' "$CAMPAIGNS_FILE")"
  helper_line="$(first_match 'function[[:space:]]+clamp_send_batch_limit' "$CAMPAIGNS_FILE")"
  warning_line="$(first_fixed_match 'batch_limit_clamped' "$CAMPAIGNS_FILE")"
  rate_line="$(first_fixed_match "'per_cron_run' => \$this->clamp_send_batch_limit((int) get_option('marketing_send_per_cron_run'))" "$CAMPAIGNS_FILE")"

  if [[ -z "$const_line" || -z "$helper_line" || -z "$warning_line" || -z "$rate_line" ]]; then
    fail \
      "queue batch size is clamped defensively before processing" \
      "expected batch limit constant, clamp helper, clamp warning, and rate snapshot clamp in $CAMPAIGNS_FILE; const=${const_line:-missing}; helper=${helper_line:-missing}; warning=${warning_line:-missing}; rate=${rate_line:-missing}"
    return
  fi

  pass \
    "queue batch size is clamped defensively before processing" \
    "const=$const_line | helper=$helper_line | warning=$warning_line"
}

check_queue_diagnostics_method() {
  local helper_line dashboard_usage_line
  helper_line="$(first_match 'function[[:space:]]+get_queue_diagnostics' "$CAMPAIGNS_FILE")"
  dashboard_usage_line="$(first_match "'queue_diagnostics'[[:space:]]*=>" "$DASHBOARD_FILE")"

  if [[ -z "$helper_line" || -z "$dashboard_usage_line" ]]; then
    fail \
      "queue diagnostics method exists and is surfaced by the dashboard model" \
      "expected diagnostics helper in $CAMPAIGNS_FILE and dashboard data usage in $DASHBOARD_FILE; helper=${helper_line:-missing}; dashboard=${dashboard_usage_line:-missing}"
    return
  fi

  pass \
    "queue diagnostics method exists and is surfaced by the dashboard model" \
    "helper=$helper_line | dashboard=$dashboard_usage_line"
}

check_queue_diagnostics_column_guards() {
  local worker_guard_line processing_guard_line
  worker_guard_line="$(first_fixed_match "&& \$this->column_exists(\$this->table, 'queue_worker_token')" "$CAMPAIGNS_FILE")"
  processing_guard_line="$(first_fixed_match "if (\$this->column_exists(\$this->recipientsTable, 'dateupdated')) {" "$CAMPAIGNS_FILE")"

  if [[ -z "$worker_guard_line" || -z "$processing_guard_line" ]]; then
    fail \
      "queue diagnostics guard optional worker and processing columns before querying" \
      "expected queue worker token and recipient dateupdated guards in $CAMPAIGNS_FILE; worker=${worker_guard_line:-missing}; processing=${processing_guard_line:-missing}"
    return
  fi

  pass \
    "queue diagnostics guard optional worker and processing columns before querying" \
    "worker=$worker_guard_line | processing=$processing_guard_line"
}

check_queue_diagnostics_stale_worker_count() {
  local helper_line stale_count_line
  helper_line="$(first_match 'function[[:space:]]+get_queue_diagnostics' "$CAMPAIGNS_FILE")"
  stale_count_line="$(first_fixed_match "['campaign_worker_leases_stale']++;" "$CAMPAIGNS_FILE")"

  if [[ -z "$helper_line" || -z "$stale_count_line" ]]; then
    fail \
      "queue diagnostics count stale campaign worker leases" \
      "expected diagnostics helper and stale worker lease increment in $CAMPAIGNS_FILE; helper=${helper_line:-missing}; stale=${stale_count_line:-missing}"
    return
  fi

  pass \
    "queue diagnostics count stale campaign worker leases" \
    "helper=$helper_line | stale=$stale_count_line"
}

check_queue_diagnostics_stale_processing_count() {
  local stale_metric_line cutoff_line
  stale_metric_line="$(first_fixed_match "'recipients_processing_stale' => 0" "$CAMPAIGNS_FILE")"
  cutoff_line="$(first_fixed_match "where('r.dateupdated <=', \$cutoffDateTime);" "$CAMPAIGNS_FILE")"

  if [[ -z "$stale_metric_line" || -z "$cutoff_line" ]]; then
    fail \
      "queue diagnostics count stale recipient processing rows" \
      "expected stale processing metric and cutoff query in $CAMPAIGNS_FILE; metric=${stale_metric_line:-missing}; cutoff=${cutoff_line:-missing}"
    return
  fi

  pass \
    "queue diagnostics count stale recipient processing rows" \
    "metric=$stale_metric_line | cutoff=$cutoff_line"
}

check_dashboard_queue_diagnostics_surface() {
  local view_heading_line view_warning_line controller_line
  view_heading_line="$(first_fixed_match "marketing_dashboard_operations_center" "$DASHBOARD_VIEW_FILE")"
  view_warning_line="$(first_fixed_match "recent_warning_counts" "$DASHBOARD_VIEW_FILE")"
  controller_line="$(first_fixed_match "\$this->load->view('marketing/dashboard', \$data);" "$MARKETING_CONTROLLER_FILE")"

  if [[ -z "$view_heading_line" || -z "$view_warning_line" || -z "$controller_line" ]]; then
    fail \
      "existing dashboard route consumes queue diagnostics without route changes" \
      "expected delivery operations section in dashboard view and existing dashboard controller route; heading=${view_heading_line:-missing}; warnings=${view_warning_line:-missing}; controller=${controller_line:-missing}"
    return
  fi

  pass \
    "existing dashboard route consumes queue diagnostics without route changes" \
    "heading=$view_heading_line | warnings=$view_warning_line | controller=$controller_line"
}

check_dashboard_view_array_guards() {
  local queue_line merge_line warnings_line failures_line operators_line
  queue_line="$(first_fixed_match '$queueDiagnostics = isset($queue_diagnostics) && is_array($queue_diagnostics) ? $queue_diagnostics : [];' "$DASHBOARD_VIEW_FILE")"
  merge_line="$(first_fixed_match '$queueDiagnostics = array_merge([' "$DASHBOARD_VIEW_FILE")"
  warnings_line="$(first_fixed_match "isset(\$queueDiagnostics['recent_warning_counts']) && is_array(\$queueDiagnostics['recent_warning_counts'])" "$DASHBOARD_VIEW_FILE")"
  failures_line="$(first_fixed_match "isset(\$queueDiagnostics['recent_failure_metric_counts']) && is_array(\$queueDiagnostics['recent_failure_metric_counts'])" "$DASHBOARD_VIEW_FILE")"
  operators_line="$(first_fixed_match "isset(\$queueDiagnostics['recent_operator_actions']) && is_array(\$queueDiagnostics['recent_operator_actions'])" "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$queue_line" || -z "$merge_line" || -z "$warnings_line" || -z "$failures_line" || -z "$operators_line" ]]; then
    fail \
      "dashboard view normalizes diagnostics arrays before rendering warning and operator loops" \
      "expected queue diagnostics normalization and array guards in $DASHBOARD_VIEW_FILE; queue=${queue_line:-missing}; merge=${merge_line:-missing}; warnings=${warnings_line:-missing}; failures=${failures_line:-missing}; operators=${operators_line:-missing}"
    return
  fi

  pass \
    "dashboard view normalizes diagnostics arrays before rendering warning and operator loops" \
    "queue=$queue_line | warnings=$warnings_line | operators=$operators_line"
}

check_queue_warning_telemetry_propagation() {
  local recovered_warning_line lease_warning_line payload_warning_line
  recovered_warning_line="$(first_fixed_match 'stale_processing_recovered' "$CAMPAIGNS_FILE")"
  lease_warning_line="$(first_fixed_match 'campaign_worker_lease_recovered' "$CAMPAIGNS_FILE")"
  payload_warning_line="$(first_fixed_match "'warnings' => \$campaignWarnings" "$CAMPAIGNS_FILE")"

  if [[ -z "$recovered_warning_line" || -z "$lease_warning_line" || -z "$payload_warning_line" ]]; then
    fail \
      "queue recovery and worker-lease warnings propagate into batch telemetry" \
      "expected stale processing and worker lease recovery warnings plus batch warning payloads in $CAMPAIGNS_FILE; recovered=${recovered_warning_line:-missing}; lease=${lease_warning_line:-missing}; payload=${payload_warning_line:-missing}"
    return
  fi

  pass \
    "queue recovery and worker-lease warnings propagate into batch telemetry" \
    "recovered=$recovered_warning_line | lease=$lease_warning_line | payload=$payload_warning_line"
}

check_queue_recovery_late_event_safety() {
  local recovery_signature update_reset warning_line replay_line failed_gate_line strong_identity_line suppressed_gate_line skipped_gate_line
  recovery_signature="$(first_fixed_match "private function recover_stuck_processing(\$campaignId = null, ?array &\$details = null)" "$CAMPAIGNS_FILE")"
  update_reset="$(first_fixed_match "'outbound_attempt_token' => null" "$CAMPAIGNS_FILE")"
  warning_line="$(first_fixed_match 'stale_processing_recovered_with_outbound_attempt' "$CAMPAIGNS_FILE")"
  replay_line="$(first_fixed_match 'outbound_attempt_replay_blocked' "$CAMPAIGNS_FILE")"
  failed_gate_line="$(first_fixed_match "failed_status_requires_strong_identity" "$CAMPAIGNS_FILE")"
  strong_identity_line="$(first_fixed_match "provider_event_recovered_failed_recipient" "$CAMPAIGNS_FILE")"
  suppressed_gate_line="$(first_fixed_match "return in_array(\$status, ['suppressed', 'skipped'], true);" "$CAMPAIGNS_FILE")"
  skipped_gate_line="$(first_fixed_match "terminal_status_blocks_failure" "$CAMPAIGNS_FILE")"

  if [[ -z "$recovery_signature" || -z "$update_reset" || -z "$warning_line" || -z "$replay_line" || -z "$failed_gate_line" || -z "$strong_identity_line" || -z "$suppressed_gate_line" || -z "$skipped_gate_line" ]]; then
    fail \
      "stale processing recovery preserves late-event reconciliation safety without weakening suppressed or skipped terminals" \
      "expected recovery details, outbound attempt reset, recovery warning, replay block guard, strong failed identity gate, reconciliation code, and suppressed/skipped terminal protections in $CAMPAIGNS_FILE; recovery=${recovery_signature:-missing}; reset=${update_reset:-missing}; warning=${warning_line:-missing}; replay=${replay_line:-missing}; failed_gate=${failed_gate_line:-missing}; strong=${strong_identity_line:-missing}; terminal=${suppressed_gate_line:-missing}; failure_terminal=${skipped_gate_line:-missing}"
    return
  fi

  pass \
    "stale processing recovery preserves late-event reconciliation safety without weakening suppressed or skipped terminals" \
    "recovery=$recovery_signature | warning=$warning_line | failed_gate=$failed_gate_line | terminal=$suppressed_gate_line"
}

check_provider_failure_normalization_helper() {
  local helper_line mailer_helper_line
  helper_line="$(first_match 'function[[:space:]]+normalize_provider_failure_metric_code' "$CAMPAIGNS_FILE")"
  mailer_helper_line="$(first_match 'function[[:space:]]+classify_provider_failure' "$MAILER_FILE")"

  if [[ -z "$helper_line" || -z "$mailer_helper_line" ]]; then
    fail \
      "provider failure normalization helper exists centrally and builds on outbound failure classification" \
      "expected normalization helper in $CAMPAIGNS_FILE and classifier in $MAILER_FILE; helper=${helper_line:-missing}; mailer=${mailer_helper_line:-missing}"
    return
  fi

  pass \
    "provider failure normalization helper exists centrally and builds on outbound failure classification" \
    "helper=$helper_line | mailer=$mailer_helper_line"
}

check_provider_failure_categories_distinguishable() {
  local transient_line throttle_line permanent_line rendering_line
  transient_line="$(first_fixed_match 'provider_transient_failure' "$CAMPAIGNS_FILE")"
  throttle_line="$(first_fixed_match 'provider_throttle' "$CAMPAIGNS_FILE")"
  permanent_line="$(first_fixed_match 'provider_permanent_failure' "$CAMPAIGNS_FILE")"
  rendering_line="$(first_fixed_match 'provider_rendering_failure' "$CAMPAIGNS_FILE")"

  if [[ -z "$transient_line" || -z "$throttle_line" || -z "$permanent_line" || -z "$rendering_line" ]]; then
    fail \
      "transient, throttle, permanent, and rendering provider failures are distinguishable" \
      "expected normalized provider failure categories in $CAMPAIGNS_FILE; transient=${transient_line:-missing}; throttle=${throttle_line:-missing}; permanent=${permanent_line:-missing}; rendering=${rendering_line:-missing}"
    return
  fi

  pass \
    "transient, throttle, permanent, and rendering provider failures are distinguishable" \
    "transient=$transient_line | throttle=$throttle_line | rendering=$rendering_line"
}

check_provider_throttle_not_bounce() {
  local throttle_warning_line bounce_code_line complaint_code_line
  throttle_warning_line="$(first_fixed_match 'provider_throttle_detected' "$CAMPAIGNS_FILE")"
  bounce_code_line="$(first_fixed_match 'recipient_bounced' "$CAMPAIGNS_FILE")"
  complaint_code_line="$(first_fixed_match 'recipient_complained' "$CAMPAIGNS_FILE")"

  if [[ -z "$throttle_warning_line" || -z "$bounce_code_line" || -z "$complaint_code_line" ]]; then
    fail \
      "provider throttle remains a batch-level backoff signal rather than a bounce or complaint" \
      "expected throttle warning plus separate bounce and complaint normalized codes in $CAMPAIGNS_FILE; throttle=${throttle_warning_line:-missing}; bounce=${bounce_code_line:-missing}; complaint=${complaint_code_line:-missing}"
    return
  fi

  pass \
    "provider throttle remains a batch-level backoff signal rather than a bounce or complaint" \
    "throttle=$throttle_warning_line | bounce=$bounce_code_line | complaint=$complaint_code_line"
}

check_normalized_failure_codes_in_telemetry() {
  local queue_payload_line webhook_payload_line batch_metrics_line
  queue_payload_line="$(first_fixed_match "'normalized_failure_code' => \$normalizedFailureCode" "$CAMPAIGNS_FILE")"
  webhook_payload_line="$(first_fixed_match "'normalized_failure_code' => \$this->marketing_campaigns_model->normalize_provider_failure_metric_code([" "$WEBHOOKS_FILE")"
  batch_metrics_line="$(first_fixed_match "'failure_metrics' => \$campaignFailureMetrics" "$CAMPAIGNS_FILE")"

  if [[ -z "$queue_payload_line" || -z "$webhook_payload_line" || -z "$batch_metrics_line" ]]; then
    fail \
      "normalized warning and failure codes are stored in queue and webhook telemetry" \
      "expected normalized failure codes in queue payloads, webhook payload metadata, and batch telemetry; queue=${queue_payload_line:-missing}; webhook=${webhook_payload_line:-missing}; batch=${batch_metrics_line:-missing}"
    return
  fi

  pass \
    "normalized warning and failure codes are stored in queue and webhook telemetry" \
    "queue=$queue_payload_line | webhook=$webhook_payload_line | batch=$batch_metrics_line"
}

check_dashboard_failure_metric_surface() {
  local diagnostics_line view_line
  diagnostics_line="$(first_fixed_match "'recent_failure_metric_counts' => []" "$CAMPAIGNS_FILE")"
  view_line="$(first_fixed_match 'Failure Metrics' "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$diagnostics_line" || -z "$view_line" ]]; then
    fail \
      "dashboard diagnostics surface normalized recent provider failure metrics" \
      "expected recent failure metric diagnostics in $CAMPAIGNS_FILE and dashboard surface in $DASHBOARD_VIEW_FILE; diagnostics=${diagnostics_line:-missing}; view=${view_line:-missing}"
    return
  fi

  pass \
    "dashboard diagnostics surface normalized recent provider failure metrics" \
    "diagnostics=$diagnostics_line | view=$view_line"
}

check_dashboard_model_retention_fail_closed() {
  local helper_line default_line method_line fallback_line
  helper_line="$(first_match 'function[[:space:]]+get_safe_retention_diagnostics' "$DASHBOARD_FILE")"
  default_line="$(first_match 'function[[:space:]]+get_default_retention_diagnostics' "$DASHBOARD_FILE")"
  method_line="$(first_fixed_match "method_exists(\$eventModel, 'get_retention_diagnostics')" "$DASHBOARD_FILE")"
  fallback_line="$(first_fixed_match 'return $this->get_default_retention_diagnostics();' "$DASHBOARD_FILE")"

  if [[ -z "$helper_line" || -z "$default_line" || -z "$method_line" || -z "$fallback_line" ]]; then
    fail \
      "dashboard model returns retention diagnostics defaults when event-model helpers are unavailable" \
      "expected safe retention helper, defaults helper, method_exists guard, and fallback return in $DASHBOARD_FILE; helper=${helper_line:-missing}; defaults=${default_line:-missing}; method=${method_line:-missing}; fallback=${fallback_line:-missing}"
    return
  fi

  pass \
    "dashboard model returns retention diagnostics defaults when event-model helpers are unavailable" \
    "helper=$helper_line | method=$method_line | fallback=$fallback_line"
}

check_reconciliation_skipped_metric_visible() {
  local reconciliation_line webhook_metric_line raw_log_line
  reconciliation_line="$(first_fixed_match 'recipient_reconciliation_skipped' "$CAMPAIGNS_FILE")"
  webhook_metric_line="$(first_fixed_match "'reconciliation_skipped' => !empty(\$reconciliation['skipped'])" "$WEBHOOKS_FILE")"
  raw_log_line="$(first_fixed_match '$storeResult = $this->marketing_events_model->add_event_idempotent([' "$WEBHOOKS_FILE")"

  if [[ -z "$reconciliation_line" || -z "$webhook_metric_line" || -z "$raw_log_line" ]]; then
    fail \
      "reconciliation-skipped events are metric-visible while raw event logging remains mutation-safe" \
      "expected reconciliation skip metric code in $CAMPAIGNS_FILE and webhook payload/logging in $WEBHOOKS_FILE; code=${reconciliation_line:-missing}; webhook=${webhook_metric_line:-missing}; log=${raw_log_line:-missing}"
    return
  fi

  pass \
    "reconciliation-skipped events are metric-visible while raw event logging remains mutation-safe" \
    "code=$reconciliation_line | webhook=$webhook_metric_line | log=$raw_log_line"
}

check_queue_pause_option_seeded() {
  local default_line install_helper line
  default_line="$(first_fixed_match "'marketing_queue_paused' => '0'" "$BOOTSTRAP_FILE")"
  install_helper="$(first_fixed_match "marketing_get_default_options()" "$INSTALL_FILE")"
  line="$(first_fixed_match "if (get_option(\$name) === false) {" "$BOOTSTRAP_FILE")"

  if [[ -z "$default_line" || -z "$install_helper" || -z "$line" ]]; then
    fail \
      "queue pause option is seeded add-only and idempotently" \
      "expected queue pause default in $BOOTSTRAP_FILE plus centralized install helper and add-only repair semantics; default=${default_line:-missing}; install=${install_helper:-missing}; add_only=${line:-missing}"
    return
  fi

  pass \
    "queue pause option is seeded add-only and idempotently" \
    "default=$default_line | install=$install_helper | add_only=$line"
}

check_queue_pause_before_send() {
  local helper_line guard_line warning_line controller_line
  helper_line="$(first_match 'function[[:space:]]+is_queue_paused' "$CAMPAIGNS_FILE")"
  guard_line="$(first_fixed_match 'if ($this->is_queue_paused()) {' "$CAMPAIGNS_FILE")"
  warning_line="$(first_fixed_match "'queue_paused'" "$CAMPAIGNS_FILE")"
  controller_line="$(first_fixed_match "Campaign queue is paused. Resume the queue before sending." "$CAMPAIGNS_CONTROLLER_FILE")"

  if [[ -z "$helper_line" || -z "$guard_line" || -z "$warning_line" || -z "$controller_line" ]]; then
    fail \
      "queue processing checks pause before reservation or send work begins" \
      "expected queue pause helper and guard in $CAMPAIGNS_FILE plus deterministic admin warning in $CAMPAIGNS_CONTROLLER_FILE; helper=${helper_line:-missing}; guard=${guard_line:-missing}; warning=${warning_line:-missing}; controller=${controller_line:-missing}"
    return
  fi

  pass \
    "queue processing checks pause before reservation or send work begins" \
    "helper=$helper_line | guard=$guard_line | warning=$warning_line"
}

check_queue_operator_actions_guarded() {
  local method_line permission_line post_line form_line
  method_line="$(first_match 'function[[:space:]]+queue_control' "$MARKETING_CONTROLLER_FILE")"
  permission_line="$(first_fixed_match "staff_cant('settings', 'marketing')" "$MARKETING_CONTROLLER_FILE")"
  post_line="$(first_fixed_match "if (!\$this->input->post()) {" "$MARKETING_CONTROLLER_FILE")"
  form_line="$(first_fixed_match "form_open(admin_url('marketing/queue_control')" "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$method_line" || -z "$permission_line" || -z "$post_line" || -z "$form_line" ]]; then
    fail \
      "pause and resume admin actions use permission and POST-safe patterns" \
      "expected queue_control() with settings permission, POST guard, and dashboard POST forms; method=${method_line:-missing}; permission=${permission_line:-missing}; post=${post_line:-missing}; form=${form_line:-missing}"
    return
  fi

  pass \
    "pause and resume admin actions use permission and POST-safe patterns" \
    "method=$method_line | permission=$permission_line | post=$post_line"
}

check_stale_recovery_operator_guard() {
  local worker_helper recipient_helper worker_action_line recipient_action_line
  worker_helper="$(first_match 'function[[:space:]]+recover_stale_campaign_worker_leases' "$CAMPAIGNS_FILE")"
  recipient_helper="$(first_match 'function[[:space:]]+recover_stale_recipient_processing' "$CAMPAIGNS_FILE")"
  worker_action_line="$(first_fixed_match 'recover_stale_worker_leases' "$MARKETING_CONTROLLER_FILE")"
  recipient_action_line="$(first_fixed_match 'recover_stale_processing' "$MARKETING_CONTROLLER_FILE")"

  if [[ -z "$worker_helper" || -z "$recipient_helper" || -z "$worker_action_line" || -z "$recipient_action_line" ]]; then
    fail \
      "stale recovery admin actions are guarded and non-destructive" \
      "expected stale-only recovery helpers in $CAMPAIGNS_FILE and explicit queue control actions in $MARKETING_CONTROLLER_FILE; worker_helper=${worker_helper:-missing}; recipient_helper=${recipient_helper:-missing}; worker_action=${worker_action_line:-missing}; recipient_action=${recipient_action_line:-missing}"
    return
  fi

  pass \
    "stale recovery admin actions are guarded and non-destructive" \
    "worker_helper=$worker_helper | recipient_helper=$recipient_helper | worker_action=$worker_action_line"
}

check_dashboard_operator_status_surface() {
  local paused_line controls_line actions_line rate_line
  paused_line="$(first_fixed_match 'Queue Paused' "$DASHBOARD_VIEW_FILE")"
  controls_line="$(first_fixed_match 'Queue Controls' "$DASHBOARD_VIEW_FILE")"
  actions_line="$(first_fixed_match 'Operator Audit' "$DASHBOARD_VIEW_FILE")"
  rate_line="$(first_fixed_match 'Hourly Remaining' "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$paused_line" || -z "$controls_line" || -z "$actions_line" || -z "$rate_line" ]]; then
    fail \
      "dashboard surfaces paused, stale, and operator status compactly" \
      "expected queue paused label, operator controls, operator audit list, and rate-limit status in $DASHBOARD_VIEW_FILE; paused=${paused_line:-missing}; controls=${controls_line:-missing}; actions=${actions_line:-missing}; rate=${rate_line:-missing}"
    return
  fi

  pass \
    "dashboard surfaces paused, stale, and operator status compactly" \
    "paused=$paused_line | controls=$controls_line | actions=$actions_line"
}

check_queue_operator_telemetry_aliases() {
  local paused_line overlap_line rate_line worker_line recipient_line throttle_line
  paused_line="$(first_fixed_match "'queue_paused'" "$CAMPAIGNS_FILE")"
  overlap_line="$(first_fixed_match "'cron_overlap_detected'" "$CAMPAIGNS_FILE")"
  rate_line="$(first_fixed_match "'rate_limit_exhausted'" "$CAMPAIGNS_FILE")"
  worker_line="$(first_fixed_match "'stale_worker_recovered'" "$CAMPAIGNS_FILE")"
  recipient_line="$(first_fixed_match "'stale_recipient_processing_recovered'" "$CAMPAIGNS_FILE")"
  throttle_line="$(first_fixed_match "'provider_throttle_detected'" "$CAMPAIGNS_FILE")"

  if [[ -z "$paused_line" || -z "$overlap_line" || -z "$rate_line" || -z "$worker_line" || -z "$recipient_line" || -z "$throttle_line" ]]; then
    fail \
      "queue pause, overlap, stale recovery, rate limit, and provider throttle telemetry warnings are propagated" \
      "expected queue/operator telemetry aliases in $CAMPAIGNS_FILE; paused=${paused_line:-missing}; overlap=${overlap_line:-missing}; rate=${rate_line:-missing}; worker=${worker_line:-missing}; recipient=${recipient_line:-missing}; throttle=${throttle_line:-missing}"
    return
  fi

  pass \
    "queue pause, overlap, stale recovery, rate limit, and provider throttle telemetry warnings are propagated" \
    "paused=$paused_line | overlap=$overlap_line | rate=$rate_line"
}

check_retention_options_seeded() {
  local event_line webhook_line repair_line
  event_line="$(first_fixed_match "'marketing_event_retention_days' => '180'" "$BOOTSTRAP_FILE")"
  webhook_line="$(first_fixed_match "'marketing_webhook_log_retention_days' => '180'" "$BOOTSTRAP_FILE")"
  repair_line="$(first_fixed_match "foreach (marketing_get_default_options() as \$name => \$value)" "$BOOTSTRAP_FILE")"

  if [[ -z "$event_line" || -z "$webhook_line" || -z "$repair_line" ]]; then
    fail \
      "retention options are seeded add-only and idempotently through module defaults" \
      "expected retention defaults and add-only repair helper in $BOOTSTRAP_FILE; event=${event_line:-missing}; webhook=${webhook_line:-missing}; repair=${repair_line:-missing}"
    return
  fi

  pass \
    "retention options are seeded add-only and idempotently through module defaults" \
    "event=$event_line | webhook=$webhook_line | repair=$repair_line"
}

check_retention_minimum_floor() {
  local const_line clamp_line mode_line
  const_line="$(first_match 'MIN_RETENTION_DAYS' "$EVENTS_FILE")"
  clamp_line="$(first_fixed_match 'max(self::MIN_RETENTION_DAYS, $days)' "$EVENTS_FILE")"
  mode_line="$(first_fixed_match "'cleanup_mode' => 'explicit_only'" "$EVENTS_FILE")"

  if [[ -z "$const_line" || -z "$clamp_line" || -z "$mode_line" ]]; then
    fail \
      "retention cleanup has a hard minimum retention floor and remains explicit-only" \
      "expected retention floor constant, clamp, and explicit cleanup mode in $EVENTS_FILE; const=${const_line:-missing}; clamp=${clamp_line:-missing}; mode=${mode_line:-missing}"
    return
  fi

  pass \
    "retention cleanup has a hard minimum retention floor and remains explicit-only" \
    "const=$const_line | clamp=$clamp_line | mode=$mode_line"
}

check_retention_delete_limit_bound() {
  local const_line event_limit_line webhook_limit_line
  const_line="$(first_match 'MAX_DELETE_PER_RUN' "$EVENTS_FILE")"
  event_limit_line="$(first_fixed_match '->limit(self::MAX_DELETE_PER_RUN)' "$EVENTS_FILE")"
  webhook_limit_line="$(first_fixed_match '->limit(self::MAX_DELETE_PER_RUN + 1)' "$EVENTS_FILE")"

  if [[ -z "$const_line" || -z "$event_limit_line" || -z "$webhook_limit_line" ]]; then
    fail \
      "retention cleanup is bounded by a per-run delete limit" \
      "expected delete limit constant and bounded candidate queries in $EVENTS_FILE; const=${const_line:-missing}; event=${event_limit_line:-missing}; webhook=${webhook_limit_line:-missing}"
    return
  fi

  pass \
    "retention cleanup is bounded by a per-run delete limit" \
    "const=$const_line | event=$event_limit_line | webhook=$webhook_limit_line"
}

check_retention_excludes_audit_critical_events() {
  local helper_line bounce_line complaint_line unsubscribe_line operator_line suppressed_line
  helper_line="$(first_match 'function[[:space:]]+get_audit_critical_event_types' "$EVENTS_FILE")"
  bounce_line="$(first_fixed_match "'bounce'" "$EVENTS_FILE")"
  complaint_line="$(first_fixed_match "'complaint'" "$EVENTS_FILE")"
  unsubscribe_line="$(first_fixed_match "'unsubscribe'" "$EVENTS_FILE")"
  operator_line="$(first_fixed_match "'operator_action'" "$EVENTS_FILE")"
  suppressed_line="$(first_fixed_match "'suppressed'" "$EVENTS_FILE")"

  if [[ -z "$helper_line" || -z "$bounce_line" || -z "$complaint_line" || -z "$unsubscribe_line" || -z "$operator_line" || -z "$suppressed_line" ]]; then
    fail \
      "retention cleanup excludes audit-critical event types" \
      "expected audit-critical event helper with bounce, complaint, unsubscribe, operator_action, and suppressed types in $EVENTS_FILE; helper=${helper_line:-missing}; bounce=${bounce_line:-missing}; complaint=${complaint_line:-missing}; unsubscribe=${unsubscribe_line:-missing}; operator=${operator_line:-missing}; suppressed=${suppressed_line:-missing}"
    return
  fi

  pass \
    "retention cleanup excludes audit-critical event types" \
    "helper=$helper_line | bounce=$bounce_line | operator=$operator_line"
}

check_retention_cleanup_scope() {
  local cleanup_helper_line event_delete_line webhook_delete_line helper_line click_reset_line click_skip_line suppressions_line recipients_line campaigns_line
  cleanup_helper_line="$(first_match 'function[[:space:]]+run_retention_cleanup' "$EVENTS_FILE")"
  event_delete_line="$(first_fixed_match "\$this->db->delete(\$this->table);" "$EVENTS_FILE")"
  webhook_delete_line="$(first_fixed_match "\$this->db->delete(\$this->webhookLogTable);" "$EVENTS_FILE")"
  helper_line="$(first_fixed_match '$clickAggregateSummary = $this->run_click_aggregate_cleanup($eventRetentionDays);' "$EVENTS_FILE")"
  click_reset_line="$(first_fixed_match "\$summary['click_links_reset'] = (int) (\$clickAggregateSummary['click_links_reset'] ?? 0);" "$EVENTS_FILE")"
  click_skip_line="$(first_fixed_match "\$summary['click_links_skipped'] = (int) (\$clickAggregateSummary['click_links_skipped'] ?? 0);" "$EVENTS_FILE")"
  suppressions_line="$(first_fixed_match 'marketing_suppressions' "$EVENTS_FILE")"
  recipients_line="$(first_fixed_match 'marketing_campaign_recipients' "$EVENTS_FILE")"
  campaigns_line="$(first_fixed_match 'marketing_campaigns' "$EVENTS_FILE")"

  if [[ -z "$cleanup_helper_line" || -z "$event_delete_line" || -z "$webhook_delete_line" || -z "$helper_line" || -z "$click_reset_line" || -z "$click_skip_line" || -n "$suppressions_line" || -n "$recipients_line" || -n "$campaigns_line" ]]; then
    fail \
      "retention cleanup only targets non-critical events, webhook logs, and safe click aggregate resets" \
      "expected explicit retention helper, event/webhook deletes, safe click cleanup integration, and no campaigns/recipients/suppressions cleanup in $EVENTS_FILE; helper=${cleanup_helper_line:-missing}; event_delete=${event_delete_line:-missing}; webhook_delete=${webhook_delete_line:-missing}; click_helper=${helper_line:-missing}; click_reset=${click_reset_line:-missing}; click_skip=${click_skip_line:-missing}; suppressions=${suppressions_line:-none}; recipients=${recipients_line:-none}; campaigns=${campaigns_line:-none}"
    return
  fi

  pass \
    "retention cleanup only targets non-critical events, webhook logs, and safe click aggregate resets" \
    "helper=$cleanup_helper_line | event_delete=$event_delete_line | webhook_delete=$webhook_delete_line | click_helper=$helper_line"
}

check_retention_cleanup_not_auto_cron() {
  local mode_line cron_usage_line
  mode_line="$(first_fixed_match "'cleanup_mode' => 'explicit_only'" "$EVENTS_FILE")"
  cron_usage_line="$(first_fixed_match 'run_retention_cleanup(' "$BOOTSTRAP_FILE")"

  if [[ -z "$mode_line" || -n "$cron_usage_line" ]]; then
    fail \
      "retention cleanup remains explicit instead of auto-running from cron" \
      "expected explicit-only cleanup mode and no cleanup invocation in $BOOTSTRAP_FILE; mode=${mode_line:-missing}; cron_usage=${cron_usage_line:-unexpected}"
    return
  fi

  pass \
    "retention cleanup remains explicit instead of auto-running from cron" \
    "mode=$mode_line"
}

check_retention_dashboard_surface() {
  local dashboard_line view_line candidate_line click_candidate_line
  dashboard_line="$(first_match "'retention_diagnostics'[[:space:]]*=>" "$DASHBOARD_FILE")"
  view_line="$(first_fixed_match 'Telemetry Retention' "$DASHBOARD_VIEW_FILE")"
  candidate_line="$(first_fixed_match 'events_cleanup_candidate_preview' "$DASHBOARD_VIEW_FILE")"
  click_candidate_line="$(first_fixed_match 'click_aggregate_candidate_preview' "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$dashboard_line" || -z "$view_line" || -z "$candidate_line" || -z "$click_candidate_line" ]]; then
    fail \
      "dashboard diagnostics surface retention status and cleanup candidate previews" \
      "expected retention diagnostics in $DASHBOARD_FILE and view rendering in $DASHBOARD_VIEW_FILE; dashboard=${dashboard_line:-missing}; view=${view_line:-missing}; candidate=${candidate_line:-missing}; click_candidate=${click_candidate_line:-missing}"
    return
  fi

  pass \
    "dashboard diagnostics surface retention status and cleanup candidate previews" \
    "dashboard=$dashboard_line | view=$view_line | candidate=$candidate_line | click_candidate=$click_candidate_line"
}

check_campaign_form_setup_guidance() {
  local panel_line marker_line heading_line checklist_line next_step_line
  panel_line="$(first_fixed_match 'marketing-campaign-setup-panel' "$CAMPAIGN_FORM_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-setup="1"' "$CAMPAIGN_FORM_VIEW_FILE")"
  heading_line="$(first_fixed_match 'marketing_campaign_setup_heading' "$CAMPAIGN_FORM_VIEW_FILE")"
  checklist_line="$(first_fixed_match 'marketing-campaign-setup-checklist' "$CAMPAIGN_FORM_VIEW_FILE")"
  next_step_line="$(first_fixed_match 'marketing_campaign_setup_next_step' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$panel_line" || -z "$marker_line" || -z "$heading_line" || -z "$checklist_line" || -z "$next_step_line" ]]; then
    fail \
      "campaign form surfaces compact setup guidance without send or model coupling" \
      "expected setup panel, marker, heading key, checklist wrapper, and next-step helper in $CAMPAIGN_FORM_VIEW_FILE; panel=${panel_line:-missing}; marker=${marker_line:-missing}; heading=${heading_line:-missing}; checklist=${checklist_line:-missing}; next_step=${next_step_line:-missing}"
    return
  fi

  pass \
    "campaign form surfaces compact setup guidance without send or model coupling" \
    "panel=$panel_line | marker=$marker_line | heading=$heading_line | checklist=$checklist_line | next_step=$next_step_line"
}

check_campaign_builder_workspace_layout() {
  local shell_line marker_line header_line workspace_line canvas_line frame_line modal_line preview_frame_line state_line html_line text_line
  shell_line="$(first_fixed_match 'marketing-campaign-builder-shell' "$CAMPAIGN_FORM_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-builder-shell="1"' "$CAMPAIGN_FORM_VIEW_FILE")"
  header_line="$(first_fixed_match 'marketing-campaign-builder-header' "$CAMPAIGN_FORM_VIEW_FILE")"
  workspace_line="$(first_fixed_match 'marketing-campaign-builder-workspace' "$CAMPAIGN_FORM_VIEW_FILE")"
  canvas_line="$(first_fixed_match 'id="marketing-builder-canvas"' "$CAMPAIGN_FORM_VIEW_FILE")"
  frame_line="$(first_fixed_match 'id="marketing-builder-canvas-frame"' "$CAMPAIGN_FORM_VIEW_FILE")"
  modal_line="$(first_fixed_match 'id="marketingBuilderPreviewModal"' "$CAMPAIGN_FORM_VIEW_FILE")"
  preview_frame_line="$(first_fixed_match 'id="marketing-builder-preview-frame"' "$CAMPAIGN_FORM_VIEW_FILE")"
  state_line="$(first_fixed_match 'id="builder_state"' "$CAMPAIGN_FORM_VIEW_FILE")"
  html_line="$(first_fixed_match 'id="html_content"' "$CAMPAIGN_FORM_VIEW_FILE")"
  text_line="$(first_fixed_match 'id="text_content"' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$shell_line" || -z "$marker_line" || -z "$header_line" || -z "$workspace_line" || -z "$canvas_line" || -z "$frame_line" || -z "$modal_line" || -z "$preview_frame_line" || -z "$state_line" || -z "$html_line" || -z "$text_line" ]]; then
    fail \
      "campaign builder workspace stays wrapped while preserving JS-critical builder roots, modal preview, and hidden content fields" \
      "expected builder shell marker, header, workspace wrapper, canvas ids, preview modal/frame, and hidden html/builder/text fields in $CAMPAIGN_FORM_VIEW_FILE; shell=${shell_line:-missing}; marker=${marker_line:-missing}; header=${header_line:-missing}; workspace=${workspace_line:-missing}; canvas=${canvas_line:-missing}; frame=${frame_line:-missing}; modal=${modal_line:-missing}; preview_frame=${preview_frame_line:-missing}; state=${state_line:-missing}; html=${html_line:-missing}; text=${text_line:-missing}"
    return
  fi

  pass \
    "campaign builder workspace stays wrapped while preserving JS-critical builder roots, modal preview, and hidden content fields" \
    "shell=$shell_line | marker=$marker_line | workspace=$workspace_line | canvas=$canvas_line | modal=$modal_line | state=$state_line"
}

check_campaign_audience_estimate_shell() {
  local shell_line marker_line list_line targeting_fields_line filtered_line estimate_button_line estimate_result_line campaign_id_line
  shell_line="$(first_fixed_match 'marketing-campaign-audience-shell' "$CAMPAIGN_FORM_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-audience-shell="1"' "$CAMPAIGN_FORM_VIEW_FILE")"
  list_line="$(first_fixed_match 'id="list_id"' "$CAMPAIGN_FORM_VIEW_FILE")"
  targeting_fields_line="$(first_fixed_match 'id="marketing-targeting-fields"' "$CAMPAIGN_FORM_VIEW_FILE")"
  filtered_line="$(first_fixed_match 'id="targeting_send_mode_filtered"' "$CAMPAIGN_FORM_VIEW_FILE")"
  estimate_button_line="$(first_fixed_match 'id="marketing-estimate-recipients"' "$CAMPAIGN_FORM_VIEW_FILE")"
  estimate_result_line="$(first_fixed_match 'id="marketing-estimate-recipients-result"' "$CAMPAIGN_FORM_VIEW_FILE")"
  campaign_id_line="$(first_fixed_match 'id="marketing-campaign-id"' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$shell_line" || -z "$marker_line" || -z "$list_line" || -z "$targeting_fields_line" || -z "$filtered_line" || -z "$estimate_button_line" || -z "$estimate_result_line" || -z "$campaign_id_line" ]]; then
    fail \
      "campaign audience and estimate UI stays grouped while preserving audience, targeting, and estimate hooks" \
      "expected audience shell marker, list field, targeting markers, estimate button/result, and campaign id hook in $CAMPAIGN_FORM_VIEW_FILE; shell=${shell_line:-missing}; marker=${marker_line:-missing}; list=${list_line:-missing}; targeting=${targeting_fields_line:-missing}; filtered=${filtered_line:-missing}; estimate_button=${estimate_button_line:-missing}; estimate_result=${estimate_result_line:-missing}; campaign_id=${campaign_id_line:-missing}"
    return
  fi

  pass \
    "campaign audience and estimate UI stays grouped while preserving audience, targeting, and estimate hooks" \
    "shell=$shell_line | marker=$marker_line | list=$list_line | targeting=$targeting_fields_line | estimate_button=$estimate_button_line | estimate_result=$estimate_result_line"
}

check_campaign_delivery_settings_shell() {
  local shell_line marker_line channel_line from_name_line from_email_line reply_to_line opens_line clicks_line scheduled_line
  shell_line="$(first_fixed_match 'marketing-campaign-delivery-shell' "$CAMPAIGN_FORM_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-delivery-shell="1"' "$CAMPAIGN_FORM_VIEW_FILE")"
  channel_line="$(first_fixed_match 'id="delivery_channel"' "$CAMPAIGN_FORM_VIEW_FILE")"
  from_name_line="$(first_fixed_match 'id="from_name"' "$CAMPAIGN_FORM_VIEW_FILE")"
  from_email_line="$(first_fixed_match 'id="from_email"' "$CAMPAIGN_FORM_VIEW_FILE")"
  reply_to_line="$(first_fixed_match 'id="reply_to_email"' "$CAMPAIGN_FORM_VIEW_FILE")"
  opens_line="$(first_fixed_match 'id="track_opens"' "$CAMPAIGN_FORM_VIEW_FILE")"
  clicks_line="$(first_fixed_match 'id="track_clicks"' "$CAMPAIGN_FORM_VIEW_FILE")"
  scheduled_line="$(first_fixed_match 'id="scheduled_at"' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$shell_line" || -z "$marker_line" || -z "$channel_line" || -z "$from_name_line" || -z "$from_email_line" || -z "$reply_to_line" || -z "$opens_line" || -z "$clicks_line" || -z "$scheduled_line" ]]; then
    fail \
      "campaign delivery settings stay grouped while preserving delivery, sender, tracking, and schedule field hooks" \
      "expected delivery shell marker and delivery_channel/from_name/from_email/reply_to_email/track_opens/track_clicks/scheduled_at hooks in $CAMPAIGN_FORM_VIEW_FILE; shell=${shell_line:-missing}; marker=${marker_line:-missing}; channel=${channel_line:-missing}; from_name=${from_name_line:-missing}; from_email=${from_email_line:-missing}; reply_to=${reply_to_line:-missing}; track_opens=${opens_line:-missing}; track_clicks=${clicks_line:-missing}; scheduled_at=${scheduled_line:-missing}"
    return
  fi

  pass \
    "campaign delivery settings stay grouped while preserving delivery, sender, tracking, and schedule field hooks" \
    "shell=$shell_line | marker=$marker_line | channel=$channel_line | from_name=$from_name_line | track_opens=$opens_line | scheduled_at=$scheduled_line"
}

check_campaign_form_footer_review_shell() {
  local footer_line marker_line submit_line state_line html_line text_line
  footer_line="$(first_fixed_match 'marketing-campaign-form-footer' "$CAMPAIGN_FORM_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-form-footer="1"' "$CAMPAIGN_FORM_VIEW_FILE")"
  submit_line="$(first_fixed_match 'type="submit" class="btn btn-primary"' "$CAMPAIGN_FORM_VIEW_FILE")"
  state_line="$(first_fixed_match 'id="builder_state"' "$CAMPAIGN_FORM_VIEW_FILE")"
  html_line="$(first_fixed_match 'id="html_content"' "$CAMPAIGN_FORM_VIEW_FILE")"
  text_line="$(first_fixed_match 'id="text_content"' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$footer_line" || -z "$marker_line" || -z "$submit_line" || -z "$state_line" || -z "$html_line" || -z "$text_line" ]]; then
    fail \
      "campaign form footer stays guided while preserving submit and hidden builder content fields" \
      "expected form footer marker, primary submit button, and builder_state/html_content/text_content fields in $CAMPAIGN_FORM_VIEW_FILE; footer=${footer_line:-missing}; marker=${marker_line:-missing}; submit=${submit_line:-missing}; builder_state=${state_line:-missing}; html=${html_line:-missing}; text=${text_line:-missing}"
    return
  fi

  pass \
    "campaign form footer stays guided while preserving submit and hidden builder content fields" \
    "footer=$footer_line | marker=$marker_line | submit=$submit_line | builder_state=$state_line | html=$html_line | text=$text_line"
}

check_campaign_plain_text_placeholder_safety() {
  local text_line placeholder_line state_line html_line unsafe_line controller_normalize_line controller_guard_line js_guard_line step_shell_line step_chip_line legacy_action_line
  text_line="$(first_fixed_match 'id="text_content"' "$CAMPAIGN_FORM_VIEW_FILE")"
  placeholder_line="$(first_fixed_match 'marketing_campaign_text_content_placeholder' "$CAMPAIGN_FORM_VIEW_FILE")"
  state_line="$(first_fixed_match 'id="builder_state"' "$CAMPAIGN_FORM_VIEW_FILE")"
  html_line="$(first_fixed_match 'id="html_content"' "$CAMPAIGN_FORM_VIEW_FILE")"
  unsafe_line="$(first_fixed_match 'Your headline goes here Introduce the offer, explain the value, and set up the email. Call to Action' "$CAMPAIGN_FORM_VIEW_FILE")"
  controller_normalize_line="$(first_fixed_match 'normalize_campaign_text_content' "$CAMPAIGNS_CONTROLLER_FILE")"
  controller_guard_line="$(first_fixed_match 'UNSAFE_DEFAULT_PLAIN_TEXT_SIGNATURES' "$CAMPAIGNS_CONTROLLER_FILE")"
  js_guard_line="$(first_fixed_match 'isUnsafeDefaultPlainText' "$BUILDER_JS_ASSET_FILE")"
  step_shell_line="$(first_fixed_match 'marketing-campaign-builder-steps' "$CAMPAIGN_FORM_VIEW_FILE")"
  step_chip_line="$(first_fixed_match 'marketing-campaign-builder-step-chip' "$CAMPAIGN_FORM_VIEW_FILE")"
  legacy_action_line="$(first_fixed_match 'marketing-campaign-builder-action-chip' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$text_line" || -z "$placeholder_line" || -z "$state_line" || -z "$html_line" || -n "$unsafe_line" || -z "$controller_normalize_line" || -z "$controller_guard_line" || -z "$js_guard_line" || -z "$step_shell_line" || -z "$step_chip_line" || -n "$legacy_action_line" ]]; then
    fail \
      "campaign plain-text fallback stays sanitized and builder guidance stays passive" \
      "expected text_content field, placeholder marker, builder_state/html_content fields, controller normalizer, JS unsafe-demo guard, passive builder step chips, and no legacy action-chip or unsafe demo copy in $CAMPAIGN_FORM_VIEW_FILE; text=${text_line:-missing}; placeholder=${placeholder_line:-missing}; builder_state=${state_line:-missing}; html=${html_line:-missing}; normalize=${controller_normalize_line:-missing}; guard=${controller_guard_line:-missing}; js_guard=${js_guard_line:-missing}; steps=${step_shell_line:-missing}; chip=${step_chip_line:-missing}; legacy_action=${legacy_action_line:-none}; unsafe_demo=${unsafe_line:-present}"
    return
  fi

  pass \
    "campaign plain-text fallback stays sanitized and builder guidance stays passive" \
    "text=$text_line | placeholder=$placeholder_line | builder_state=$state_line | html=$html_line | normalize=$controller_normalize_line | js_guard=$js_guard_line | steps=$step_shell_line"
}

check_campaign_show_command_center() {
  local center_line marker_line title_line grid_line actions_line next_step_line readiness_line css_line preview_line edit_line send_line schedule_line delete_line mailer_diff git_diff_mode
  center_line="$(first_fixed_match 'marketing-campaign-command-center' "$CAMPAIGN_SHOW_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-command-center="1"' "$CAMPAIGN_SHOW_VIEW_FILE")"
  title_line="$(first_fixed_match 'marketing_campaign_command_center' "$CAMPAIGN_SHOW_VIEW_FILE")"
  grid_line="$(first_fixed_match 'marketing-campaign-command-grid' "$CAMPAIGN_SHOW_VIEW_FILE")"
  actions_line="$(first_fixed_match 'marketing-campaign-send-workflow' "$CAMPAIGN_SHOW_VIEW_FILE")"
  next_step_line="$(first_fixed_match 'marketing_campaign_command_next_step' "$CAMPAIGN_SHOW_VIEW_FILE")"
  readiness_line="$(first_fixed_match 'marketing_campaign_readiness_status' "$CAMPAIGN_SHOW_VIEW_FILE")"
  css_line="$(first_fixed_match "marketing-builder.css" "$CAMPAIGN_SHOW_VIEW_FILE")"
  preview_line="$(first_fixed_match "marketing_preview_campaign_button" "$CAMPAIGN_SHOW_VIEW_FILE")"
  edit_line="$(first_fixed_match "admin_url('marketing/campaigns/edit/' . \$campaign['id'])" "$CAMPAIGN_SHOW_VIEW_FILE")"
  send_line="$(first_fixed_match 'send_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"
  schedule_line="$(first_fixed_match "form_open(admin_url('marketing/campaigns/schedule/' . \$campaign['id']))" "$CAMPAIGN_SHOW_VIEW_FILE")"
  delete_line="$(first_fixed_match 'delete_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"
  mailer_diff="$(git_changed_path_if_available "$MAILER_FILE")"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$center_line" || -z "$marker_line" || -z "$title_line" || -z "$grid_line" || -z "$actions_line" || -z "$next_step_line" || -z "$readiness_line" || -z "$css_line" || -z "$preview_line" || -z "$edit_line" || -z "$send_line" || -z "$schedule_line" || -z "$delete_line" || -n "$mailer_diff" ]]; then
    fail \
      "campaign show page keeps its styled command center and action workflow markers intact" \
      "expected command center marker, grid, workflow wrapper, builder CSS include, preview/edit/send/schedule/delete action markers, and no mailer diff; center=${center_line:-missing}; marker=${marker_line:-missing}; title=${title_line:-missing}; grid=${grid_line:-missing}; workflow=${actions_line:-missing}; next_step=${next_step_line:-missing}; readiness=${readiness_line:-missing}; css=${css_line:-missing}; preview=${preview_line:-missing}; edit=${edit_line:-missing}; send=${send_line:-missing}; schedule=${schedule_line:-missing}; delete=${delete_line:-missing}; git_diff_mode=${git_diff_mode:-unknown}; mailer_diff=${mailer_diff:-none}"
    return
  fi

  pass \
    "campaign show page keeps its styled command center and action workflow markers intact" \
    "center=$center_line | css=$css_line | preview=$preview_line | schedule=$schedule_line | git_diff_mode=$git_diff_mode"
}

check_campaign_send_schedule_guidance() {
  local workflow_line marker_line danger_line prepare_line send_line schedule_line reset_line delete_line
  workflow_line="$(first_fixed_match 'marketing-campaign-send-workflow' "$CAMPAIGN_SHOW_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-send-workflow="1"' "$CAMPAIGN_SHOW_VIEW_FILE")"
  danger_line="$(first_fixed_match 'data-marketing-campaign-danger-actions="1"' "$CAMPAIGN_SHOW_VIEW_FILE")"
  prepare_line="$(first_fixed_match 'prepare_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"
  send_line="$(first_fixed_match 'send_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"
  schedule_line="$(first_fixed_match "form_open(admin_url('marketing/campaigns/schedule/' . \$campaign['id']))" "$CAMPAIGN_SHOW_VIEW_FILE")"
  reset_line="$(first_fixed_match 'reset_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"
  delete_line="$(first_fixed_match 'delete_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"

  if [[ -z "$workflow_line" || -z "$marker_line" || -z "$danger_line" || -z "$prepare_line" || -z "$send_line" || -z "$schedule_line" || -z "$reset_line" || -z "$delete_line" ]]; then
    fail \
      "campaign send and schedule workflow stays grouped while preserving action markers" \
      "expected send workflow marker, danger grouping marker, and prepare/send/schedule/reset/delete markers in $CAMPAIGN_SHOW_VIEW_FILE; workflow=${workflow_line:-missing}; marker=${marker_line:-missing}; danger=${danger_line:-missing}; prepare=${prepare_line:-missing}; send=${send_line:-missing}; schedule=${schedule_line:-missing}; reset=${reset_line:-missing}; delete=${delete_line:-missing}"
    return
  fi

  pass \
    "campaign send and schedule workflow stays grouped while preserving action markers" \
    "workflow=$workflow_line | marker=$marker_line | danger=$danger_line | prepare=$prepare_line | send=$send_line | schedule=$schedule_line"
}

check_campaign_reprepare_recipients_workflow() {
  local index_branch_line controller_action_line hidden_input_line generic_route_line generic_admin_route_line permission_line helper_guard_line queued_guard_line operator_action_line model_can_line model_sync_line existing_count_line eligible_snapshot_line queue_snapshot_line prepare_reuse_line skipped_existing_line button_form_line button_label_line send_line schedule_line mailer_diff webhooks_diff whatsapp_diff git_diff_mode
  index_branch_line="$(first_fixed_match "\$this->input->post('reprepare_campaign_id')" "$CAMPAIGNS_CONTROLLER_FILE")"
  controller_action_line="$(first_match 'function[[:space:]]+reprepare_recipients' "$CAMPAIGNS_CONTROLLER_FILE")"
  generic_route_line="$(grep -nF "\$route['marketing/campaigns/(:any)']" "$ROUTES_FILE" || true)"
  generic_admin_route_line="$(grep -nF "\$route['admin/marketing/campaigns/(:any)']" "$ROUTES_FILE" || true)"
  permission_line="$(first_fixed_match "if (staff_cant('send', 'marketing')) {" "$CAMPAIGNS_CONTROLLER_FILE")"
  helper_guard_line="$(first_fixed_match "if (!\$this->marketing_campaigns_model->can_reprepare_recipients(\$campaign)) {" "$CAMPAIGNS_CONTROLLER_FILE")"
  queued_guard_line="$(first_fixed_match "\$queuedRecipients = \$this->marketing_campaigns_model->count_recipients_by_status(\$id, 'queued');" "$CAMPAIGNS_CONTROLLER_FILE")"
  operator_action_line="$(first_fixed_match "manual_reprepare_recipients_requested" "$CAMPAIGNS_CONTROLLER_FILE")"
  model_can_line="$(first_match 'function[[:space:]]+can_reprepare_recipients' "$CAMPAIGNS_FILE")"
  model_sync_line="$(first_match 'function[[:space:]]+sync_newly_eligible_recipients' "$CAMPAIGNS_FILE")"
  existing_count_line="$(first_fixed_match "\$existingRecipientCount = \$this->count_campaign_recipients(\$campaignId);" "$CAMPAIGNS_FILE")"
  eligible_snapshot_line="$(first_fixed_match "\$eligibleSnapshot = \$this->get_campaign_targeting_snapshot(\$campaign);" "$CAMPAIGNS_FILE")"
  queue_snapshot_line="$(first_fixed_match "\$queueSnapshot = \$this->get_campaign_queue_targeting_snapshot(\$campaign);" "$CAMPAIGNS_FILE")"
  prepare_reuse_line="$(first_fixed_match "\$summary = \$this->prepare_recipients(\$campaignId);" "$CAMPAIGNS_FILE")"
  skipped_existing_line="$(first_fixed_match "'skipped_existing_count'" "$CAMPAIGNS_FILE")"
  button_form_line="$(first_fixed_match "form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block'])" "$CAMPAIGN_SHOW_VIEW_FILE")"
  hidden_input_line="$(first_fixed_match 'name="reprepare_campaign_id" value="<?= (int) $campaign['\''id'\'']; ?>">' "$CAMPAIGN_SHOW_VIEW_FILE")"
  button_label_line="$(first_fixed_match "marketing_campaign_reprepare_recipients" "$CAMPAIGN_SHOW_VIEW_FILE")"
  send_line="$(first_fixed_match 'send_campaign_id' "$CAMPAIGN_SHOW_VIEW_FILE")"
  schedule_line="$(first_fixed_match "form_open(admin_url('marketing/campaigns/schedule/' . \$campaign['id']))" "$CAMPAIGN_SHOW_VIEW_FILE")"
  mailer_diff="$(git_changed_path_if_available "$MAILER_FILE")"
  webhooks_diff="$(git_changed_path_if_available "$WEBHOOKS_FILE")"
  whatsapp_diff="$(git_changed_path_if_available "$WHATSAPP_FILE")"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$index_branch_line" || -z "$controller_action_line" || -n "$generic_route_line" || -n "$generic_admin_route_line" || -z "$permission_line" || -z "$helper_guard_line" || -z "$queued_guard_line" || -z "$operator_action_line" || -z "$model_can_line" || -z "$model_sync_line" || -z "$existing_count_line" || -z "$eligible_snapshot_line" || -z "$queue_snapshot_line" || -z "$prepare_reuse_line" || -z "$skipped_existing_line" || -z "$button_form_line" || -z "$hidden_input_line" || -z "$button_label_line" || -z "$send_line" || -z "$schedule_line" || -n "$mailer_diff" || -n "$webhooks_diff" || -n "$whatsapp_diff" ]]; then
    fail \
      "campaign recipient re-prepare stays additive, POST-only, and isolated from runtime send/provider flows" \
      "expected show-page form to follow the same working admin_url('marketing/campaigns') POST pattern as other campaign actions, with hidden reprepare_campaign_id handling in Campaigns::index(), safe-state and queued guards, additive sync helper reuse, direct controller method retained, no broader campaign swallowing route, and no mailer/webhook/whatsapp diffs; index_branch=${index_branch_line:-missing}; action=${controller_action_line:-missing}; generic_route=${generic_route_line:-none}; generic_admin_route=${generic_admin_route_line:-none}; permission=${permission_line:-missing}; helper_guard=${helper_guard_line:-missing}; queued_guard=${queued_guard_line:-missing}; operator=${operator_action_line:-missing}; model_can=${model_can_line:-missing}; model_sync=${model_sync_line:-missing}; existing=${existing_count_line:-missing}; eligible=${eligible_snapshot_line:-missing}; queue=${queue_snapshot_line:-missing}; prepare=${prepare_reuse_line:-missing}; skipped_existing=${skipped_existing_line:-missing}; form=${button_form_line:-missing}; hidden=${hidden_input_line:-missing}; label=${button_label_line:-missing}; send=${send_line:-missing}; schedule=${schedule_line:-missing}; git_diff_mode=${git_diff_mode:-unknown}; mailer_diff=${mailer_diff:-none}; webhooks_diff=${webhooks_diff:-none}; whatsapp_diff=${whatsapp_diff:-none}"
    return
  fi

  pass \
    "campaign recipient re-prepare stays additive, POST-only, and isolated from runtime send/provider flows" \
    "index_branch=$index_branch_line | action=$controller_action_line | form=$button_form_line | hidden=$hidden_input_line | model_sync=$model_sync_line | git_diff_mode=$git_diff_mode"
}

check_campaign_index_scanability() {
  local list_line marker_line title_line status_line actions_line prepare_line send_line delete_line reset_line
  list_line="$(first_fixed_match 'marketing-campaign-list' "$CAMPAIGN_INDEX_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-list="1"' "$CAMPAIGN_INDEX_VIEW_FILE")"
  title_line="$(first_fixed_match 'marketing-campaign-list-title' "$CAMPAIGN_INDEX_VIEW_FILE")"
  status_line="$(first_fixed_match 'marketing-campaign-status-chip' "$CAMPAIGN_INDEX_VIEW_FILE")"
  actions_line="$(first_fixed_match 'marketing-campaign-actions' "$CAMPAIGN_INDEX_VIEW_FILE")"
  prepare_line="$(first_fixed_match 'marketing_campaign_prepare_recipients' "$CAMPAIGN_INDEX_VIEW_FILE")"
  send_line="$(first_fixed_match 'marketing_campaign_send_campaign' "$CAMPAIGN_INDEX_VIEW_FILE")"
  delete_line="$(first_fixed_match "name=\"delete_campaign_id\"" "$CAMPAIGN_INDEX_VIEW_FILE")"
  reset_line="$(first_fixed_match 'marketing_campaign_reset_to_draft_button' "$CAMPAIGN_INDEX_VIEW_FILE")"

  if [[ -z "$list_line" || -z "$marker_line" || -z "$title_line" || -z "$status_line" || -z "$actions_line" || -z "$prepare_line" || -z "$send_line" || -z "$delete_line" || -z "$reset_line" ]]; then
    fail \
      "campaign list remains scannable while preserving existing action markers" \
      "expected list wrapper, marker, title, status chip, action cluster, and prepare/send/delete/reset markers in $CAMPAIGN_INDEX_VIEW_FILE; list=${list_line:-missing}; marker=${marker_line:-missing}; title=${title_line:-missing}; status=${status_line:-missing}; actions=${actions_line:-missing}; prepare=${prepare_line:-missing}; send=${send_line:-missing}; delete=${delete_line:-missing}; reset=${reset_line:-missing}"
    return
  fi

  pass \
    "campaign list remains scannable while preserving existing action markers" \
    "list=$list_line | marker=$marker_line | title=$title_line | status=$status_line | actions=$actions_line"
}

check_campaign_preview_review_shell() {
  local preview_shell_line preview_marker_line preview_note_line preview_html_line show_shell_line show_frame_line
  preview_shell_line="$(first_fixed_match 'marketing-campaign-preview-shell' "$CAMPAIGN_PREVIEW_VIEW_FILE")"
  preview_marker_line="$(first_fixed_match 'data-marketing-campaign-preview-shell="1"' "$CAMPAIGN_PREVIEW_VIEW_FILE")"
  preview_note_line="$(first_fixed_match 'marketing_campaign_preview_review_note' "$CAMPAIGN_PREVIEW_VIEW_FILE")"
  preview_html_line="$(first_fixed_match '<?= $preview_html; ?>' "$CAMPAIGN_PREVIEW_VIEW_FILE")"
  show_shell_line="$(first_fixed_match 'data-marketing-campaign-preview-frame-shell="1"' "$CAMPAIGN_SHOW_VIEW_FILE")"
  show_frame_line="$(first_fixed_match 'data-marketing-campaign-preview-frame="1"' "$CAMPAIGN_SHOW_VIEW_FILE")"

  if [[ -z "$preview_shell_line" || -z "$preview_marker_line" || -z "$preview_note_line" || -z "$preview_html_line" || -z "$show_shell_line" || -z "$show_frame_line" ]]; then
    fail \
      "campaign preview and review surfaces remain wrapped without changing rendered preview semantics" \
      "expected dedicated preview shell marker, review note key, raw preview_html output, and show-page preview shell/frame markers; preview_shell=${preview_shell_line:-missing}; preview_marker=${preview_marker_line:-missing}; preview_note=${preview_note_line:-missing}; preview_html=${preview_html_line:-missing}; show_shell=${show_shell_line:-missing}; show_frame=${show_frame_line:-missing}"
    return
  fi

  pass \
    "campaign preview and review surfaces remain wrapped without changing rendered preview semantics" \
    "preview_shell=$preview_shell_line | preview_marker=$preview_marker_line | preview_html=$preview_html_line | show_shell=$show_shell_line | show_frame=$show_frame_line"
}

check_no_destructive_event_history_clear() {
  local controller_post_line controller_model_line controller_flag_line model_guard_line safe_button_line destructive_button_line summary_line
  controller_post_line="$(first_fixed_match "if (\$this->input->post('run_retention_cleanup')) {" "$ANALYTICS_CONTROLLER_FILE")"
  controller_model_line="$(first_fixed_match '$summary = $this->marketing_events_model->run_retention_cleanup();' "$ANALYTICS_CONTROLLER_FILE")"
  controller_flag_line="$(first_fixed_match "\$data['can_run_retention_cleanup'] = staff_can('settings', 'marketing');" "$ANALYTICS_CONTROLLER_FILE")"
  model_guard_line="$(first_fixed_match 'return false;' "$EVENTS_FILE")"
  safe_button_line="$(first_fixed_match 'Run Retention Cleanup' "$ANALYTICS_VIEW_FILE")"
  destructive_button_line="$(first_fixed_match 'marketing_clear_event_history' "$ANALYTICS_VIEW_FILE")"
  summary_line="$(first_fixed_match "\$clickLinksReset = (int) (\$summary['click_links_reset'] ?? 0);" "$ANALYTICS_CONTROLLER_FILE")"

  if [[ -z "$controller_post_line" || -z "$controller_model_line" || -z "$controller_flag_line" || -z "$model_guard_line" || -z "$safe_button_line" || -z "$summary_line" || -n "$destructive_button_line" ]]; then
    fail \
      "analytics exposes only safe bounded retention cleanup and keeps destructive history clearing disabled" \
      "expected retention cleanup POST path, bounded model call, permission flag, fail-closed clear_event_history(), click reset summary, visible safe button, and no destructive clear button; post=${controller_post_line:-missing}; model_call=${controller_model_line:-missing}; flag=${controller_flag_line:-missing}; model=${model_guard_line:-missing}; summary=${summary_line:-missing}; safe_button=${safe_button_line:-missing}; destructive_button=${destructive_button_line:-unexpected}"
    return
  fi

  pass \
    "analytics exposes only safe bounded retention cleanup and keeps destructive history clearing disabled" \
    "post=$controller_post_line | model_call=$controller_model_line | summary=$summary_line"
}

check_analytics_view_array_guards() {
  local stats_line recent_line normalized_line pagination_line empty_line diagnostics_line warning_line
  stats_line="$(first_fixed_match '$stats = isset($stats) && is_array($stats) ? $stats : [];' "$ANALYTICS_VIEW_FILE")"
  recent_line="$(first_fixed_match '$recent = isset($recent) && is_array($recent) ? $recent : [];' "$ANALYTICS_VIEW_FILE")"
  normalized_line="$(first_fixed_match '$normalizedRecent = [];' "$ANALYTICS_VIEW_FILE")"
  pagination_line="$(first_fixed_match '$events_pagination = isset($events_pagination) && is_array($events_pagination)' "$ANALYTICS_VIEW_FILE")"
  empty_line="$(first_fixed_match '<?php if (empty($recent)) { ?>' "$ANALYTICS_VIEW_FILE")"
  diagnostics_line="$(first_fixed_match '$retentionDiagnostics = isset($retention_diagnostics) && is_array($retention_diagnostics)' "$ANALYTICS_VIEW_FILE")"
  warning_line="$(first_fixed_match '$analytics_warning_message = isset($analytics_warning_message) && is_string($analytics_warning_message)' "$ANALYTICS_VIEW_FILE")"

  if [[ -z "$stats_line" || -z "$recent_line" || -z "$normalized_line" || -z "$pagination_line" || -z "$empty_line" || -z "$diagnostics_line" || -z "$warning_line" ]]; then
    fail \
      "analytics view guards stats, events, pagination, retention diagnostics, and top-click arrays before rendering" \
      "expected stats/recent normalization, per-row event defaults, pagination/diagnostics guards, warning normalization, and empty() handling in $ANALYTICS_VIEW_FILE; stats=${stats_line:-missing}; recent=${recent_line:-missing}; normalized=${normalized_line:-missing}; pagination=${pagination_line:-missing}; empty=${empty_line:-missing}; diagnostics=${diagnostics_line:-missing}; warning=${warning_line:-missing}"
    return
  fi

  pass \
    "analytics view guards stats, events, pagination, retention diagnostics, and top-click arrays before rendering" \
    "stats=$stats_line | recent=$recent_line | normalized=$normalized_line"
}

check_analytics_campaign_performance_reporting() {
  local controller_campaigns controller_health model_campaigns model_health model_limit model_guard model_last_activity view_campaigns view_health view_note destructive_sql forbidden_webhook forbidden_whatsapp git_diff_mode
  controller_campaigns="$(first_fixed_match "\$data['campaign_performance'] = \$campaignPerformance;" "$ANALYTICS_CONTROLLER_FILE")"
  controller_health="$(first_fixed_match "\$data['provider_health'] = \$providerHealth;" "$ANALYTICS_CONTROLLER_FILE")"
  model_campaigns="$(first_match 'function[[:space:]]+get_campaign_performance_summary' "$DASHBOARD_FILE")"
  model_health="$(first_match 'function[[:space:]]+get_provider_health_summary' "$DASHBOARD_FILE")"
  model_limit="$(first_fixed_match 'min(25, (int) $limit)' "$DASHBOARD_FILE")"
  model_guard="$(first_fixed_match "\$this->db->field_exists('campaign_id', \$this->recipientsTable)" "$DASHBOARD_FILE")"
  model_last_activity="$(first_fixed_match "MAX(event_at) AS last_activity_at" "$DASHBOARD_FILE")"
  view_campaigns="$(first_fixed_match "_l('marketing_campaign_performance_reporting')" "$ANALYTICS_VIEW_FILE")"
  view_health="$(first_fixed_match "_l('marketing_provider_health_summary')" "$ANALYTICS_VIEW_FILE")"
  view_note="$(first_fixed_match "_l('marketing_provider_health_summary_note')" "$ANALYTICS_VIEW_FILE")"
  destructive_sql="$(grep -nE -- '\\b(TRUNCATE|DROP|DELETE FROM|empty_table)\\b' "$ANALYTICS_CONTROLLER_FILE" "$DASHBOARD_FILE" "$ANALYTICS_VIEW_FILE" || true)"
  forbidden_webhook="$(git_changed_path_if_available modules/marketing/controllers/Webhooks.php)"
  forbidden_whatsapp="$(git_changed_path_if_available modules/marketing/libraries/Marketing_whatsapp.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$controller_campaigns" || -z "$controller_health" || -z "$model_campaigns" || -z "$model_health" || -z "$model_limit" || -z "$model_guard" || -z "$model_last_activity" || -z "$view_campaigns" || -z "$view_health" || -z "$view_note" || -n "$destructive_sql" || -n "$forbidden_webhook" || -n "$forbidden_whatsapp" ]]; then
    fail \
      "analytics page adds bounded campaign performance reporting and read-only provider health summaries" \
      "expected analytics controller wiring in $ANALYTICS_CONTROLLER_FILE, bounded helper methods plus optional column guards in $DASHBOARD_FILE, reporting sections in $ANALYTICS_VIEW_FILE, no destructive cleanup, and no webhook or WhatsApp runtime file changes; campaigns=${controller_campaigns:-missing}; health=${controller_health:-missing}; model_campaigns=${model_campaigns:-missing}; model_health=${model_health:-missing}; limit=${model_limit:-missing}; guard=${model_guard:-missing}; activity=${model_last_activity:-missing}; view_campaigns=${view_campaigns:-missing}; view_health=${view_health:-missing}; view_note=${view_note:-missing}; destructive=${destructive_sql:-none}; git_diff_mode=${git_diff_mode:-unknown}; webhook_diff=${forbidden_webhook:-none}; whatsapp_diff=${forbidden_whatsapp:-none}"
    return
  fi

  pass \
    "analytics page adds bounded campaign performance reporting and read-only provider health summaries" \
    "controller=$controller_campaigns | model=$model_campaigns | provider=$model_health | git_diff_mode=$git_diff_mode"
}

check_analytics_runtime_fail_closed_queries() {
  local count_line top_links_call_line top_links_data_line top_links_helper_line campaign_helper_line count_helper_line total_rows_helper_line public_token_guard_line public_url_guard_line public_url_select_line public_url_from_line public_url_token_line public_url_active_line public_url_null_guard_line public_url_group_line public_human_sum_line public_unique_sum_line public_old_alias_from_line public_old_alias_sum_line public_bare_url_select_line public_bare_url_null_line public_bare_url_not_empty_line public_bare_url_group_line campaign_id_guard_line pagination_select_line bootstrap_line warning_data_line dashboard_reset_helper_line dashboard_reset_query_line dashboard_reset_call_line
  count_line="$(first_fixed_match "Marketing analytics event count unavailable:" "$ANALYTICS_CONTROLLER_FILE")"
  top_links_call_line="$(first_fixed_match "method_exists(\$this->marketing_dashboard_model, 'get_top_clicked_links')" "$ANALYTICS_CONTROLLER_FILE")"
  top_links_data_line="$(first_fixed_match "\$data['top_clicked_links'] = \$topClickedLinks;" "$ANALYTICS_CONTROLLER_FILE")"
  warning_data_line="$(first_fixed_match "\$data['analytics_warning_message'] = \$hasRuntimeWarning" "$ANALYTICS_CONTROLLER_FILE")"
  top_links_helper_line="$(first_match 'function[[:space:]]+get_top_clicked_links' "$DASHBOARD_FILE")"
  campaign_helper_line="$(first_match 'function[[:space:]]+get_clicks_by_campaign' "$DASHBOARD_FILE")"
  count_helper_line="$(first_match 'function[[:space:]]+safe_count_all' "$DASHBOARD_FILE")"
  total_rows_helper_line="$(first_match 'function[[:space:]]+safe_total_rows' "$DASHBOARD_FILE")"
  public_token_guard_line="$(first_fixed_match "field_exists('token_type', \$this->publicLinksTable)" "$DASHBOARD_FILE")"
  public_url_guard_line="$(first_fixed_match "field_exists('original_url', \$this->publicLinksTable)" "$DASHBOARD_FILE")"
  public_url_select_line="$(first_fixed_match "select('pl.original_url AS url, ' . \$humanCountExpr . ' AS count, ' . \$uniqueCountExpr . ' AS unique_count', false)" "$DASHBOARD_FILE")"
  public_url_from_line="$(first_fixed_match "from(\$this->publicLinksTable . ' pl')" "$DASHBOARD_FILE")"
  public_url_token_line="$(first_fixed_match "where('pl.token_type', 'click')" "$DASHBOARD_FILE")"
  public_url_active_line="$(first_fixed_match "where('pl.is_active', 1)" "$DASHBOARD_FILE")"
  public_url_null_guard_line="$(first_fixed_match "where('pl.original_url IS NOT NULL', null, false)" "$DASHBOARD_FILE")"
  public_url_group_line="$(first_fixed_match "group_by('pl.original_url')" "$DASHBOARD_FILE")"
  public_human_sum_line="$(first_fixed_match "COALESCE(SUM(pl.human_click_count), 0)" "$DASHBOARD_FILE")"
  public_unique_sum_line="$(first_fixed_match "COALESCE(SUM(pl.unique_click_count), 0)" "$DASHBOARD_FILE")"
  public_old_alias_from_line="$(first_fixed_match "from(\$this->publicLinksTable . ' p')" "$DASHBOARD_FILE")"
  public_old_alias_sum_line="$(first_match 'SUM\(p\.(human_click_count|unique_click_count)\)' "$DASHBOARD_FILE")"
  public_bare_url_select_line="$(first_fixed_match "select('original_url AS url, ' . \$humanCountExpr . ' AS count, ' . \$uniqueCountExpr . ' AS unique_count', false)" "$DASHBOARD_FILE")"
  public_bare_url_null_line="$(first_fixed_match "where('original_url IS NOT NULL', null, false)" "$DASHBOARD_FILE")"
  public_bare_url_not_empty_line="$(first_fixed_match "where('original_url !=', '')" "$DASHBOARD_FILE")"
  public_bare_url_group_line="$(first_fixed_match "group_by('original_url')" "$DASHBOARD_FILE")"
  campaign_id_guard_line="$(first_fixed_match "field_exists('campaign_id', \$this->recipientsTable)" "$DASHBOARD_FILE")"
  pagination_select_line="$(first_fixed_match "\$this->column_exists('event_at') ? 'event_at' : 'NULL AS event_at'" "$EVENTS_FILE")"
  bootstrap_line="$(first_fixed_match "function_exists('mb_substr')" "$BOOTSTRAP_FILE")"
  dashboard_reset_helper_line="$(first_match 'function[[:space:]]+reset_dashboard_query_builder' "$DASHBOARD_FILE")"
  dashboard_reset_query_line="$(first_fixed_match "\$this->db->reset_query();" "$DASHBOARD_FILE")"
  dashboard_reset_call_line="$(first_fixed_match "\$this->reset_dashboard_query_builder();" "$DASHBOARD_FILE")"

  if [[ -z "$count_line" || -z "$top_links_call_line" || -z "$top_links_data_line" || -z "$warning_data_line" || -z "$top_links_helper_line" || -z "$campaign_helper_line" || -z "$count_helper_line" || -z "$total_rows_helper_line" || -z "$public_token_guard_line" || -z "$public_url_guard_line" || -z "$public_url_select_line" || -z "$public_url_from_line" || -z "$public_url_token_line" || -z "$public_url_active_line" || -z "$public_url_null_guard_line" || -z "$public_url_group_line" || -z "$public_human_sum_line" || -z "$public_unique_sum_line" || -n "$public_old_alias_from_line" || -n "$public_old_alias_sum_line" || -n "$public_bare_url_select_line" || -n "$public_bare_url_null_line" || -n "$public_bare_url_not_empty_line" || -n "$public_bare_url_group_line" || -z "$campaign_id_guard_line" || -z "$pagination_select_line" || -z "$bootstrap_line" || -z "$dashboard_reset_helper_line" || -z "$dashboard_reset_query_line" || -z "$dashboard_reset_call_line" ]]; then
    fail \
      "analytics runtime queries fail closed across controller defaults, dashboard guards, event pagination, and log sanitization" \
      "expected controller fallback logging/defaults, warning seed, safe top-click helpers, dashboard query-builder reset isolation, count guards, unique pl alias qualification for public-link original_url selects/grouping, no old p alias or bare original_url references, campaign click guard, paginated event field defaults, and mbstring-safe log sanitization; count=${count_line:-missing}; top_call=${top_links_call_line:-missing}; top_data=${top_links_data_line:-missing}; warning=${warning_data_line:-missing}; top_helper=${top_links_helper_line:-missing}; campaign_helper=${campaign_helper_line:-missing}; count_helper=${count_helper_line:-missing}; total_rows=${total_rows_helper_line:-missing}; token_guard=${public_token_guard_line:-missing}; url_guard=${public_url_guard_line:-missing}; url_select=${public_url_select_line:-missing}; url_from=${public_url_from_line:-missing}; url_token=${public_url_token_line:-missing}; url_active=${public_url_active_line:-missing}; url_null=${public_url_null_guard_line:-missing}; url_group=${public_url_group_line:-missing}; human_sum=${public_human_sum_line:-missing}; unique_sum=${public_unique_sum_line:-missing}; old_from=${public_old_alias_from_line:-unexpected}; old_sum=${public_old_alias_sum_line:-unexpected}; bare_select=${public_bare_url_select_line:-unexpected}; bare_null=${public_bare_url_null_line:-unexpected}; bare_not_empty=${public_bare_url_not_empty_line:-unexpected}; bare_group=${public_bare_url_group_line:-unexpected}; campaign_id=${campaign_id_guard_line:-missing}; pagination=${pagination_select_line:-missing}; bootstrap=${bootstrap_line:-missing}; dashboard_reset_helper=${dashboard_reset_helper_line:-missing}; dashboard_reset_query=${dashboard_reset_query_line:-missing}; dashboard_reset_call=${dashboard_reset_call_line:-missing}"
    return
  fi

  pass \
    "analytics runtime queries fail closed across controller defaults, dashboard guards, event pagination, and log sanitization" \
    "count=$count_line | top_helper=$top_links_helper_line | url_from=$public_url_from_line"
}

check_analytics_render_fail_closed() {
  local defaults_line render_helper_line fallback_helper_line return_line baseline_line cleanup_helper_line clear_output_line response_helper_line render_path_helper_line primary_path_const_line fallback_path_const_line primary_render_line primary_header_line primary_catch_line primary_fallback_return_line cleanup_before_fallback_line fallback_render_line fallback_warning_line cleanup_before_minimal_line minimal_html_line marker_line status_line content_type_line fallback_view_line fallback_path_header_line reason_header_line reason_helper_line diagnostic_log_helper_line diagnostic_log_path_line primary_diag_call_line fallback_diag_call_line minimal_diag_call_line isolated_render_helper_line combine_helper_line primary_marker_helper_line primary_marker_guard_line primary_visible_guard_line visibility_helper_line buffer_level_guard_line fallback_visible_guard_line
  defaults_line="$(first_match 'function[[:space:]]+get_default_analytics_view_data' "$ANALYTICS_CONTROLLER_FILE")"
  render_helper_line="$(first_match 'function[[:space:]]+render_analytics_response' "$ANALYTICS_CONTROLLER_FILE")"
  fallback_helper_line="$(first_match 'function[[:space:]]+render_fallback_response' "$ANALYTICS_CONTROLLER_FILE")"
  return_line="$(first_fixed_match 'return $this->render_analytics_response($data);' "$ANALYTICS_CONTROLLER_FILE")"
  baseline_line="$(first_fixed_match '$baselineOutputLevel = ob_get_level();' "$ANALYTICS_CONTROLLER_FILE")"
  cleanup_helper_line="$(first_match 'function[[:space:]]+clean_output_buffers_to_level' "$ANALYTICS_CONTROLLER_FILE")"
  clear_output_line="$(first_match 'function[[:space:]]+clear_pending_output_body' "$ANALYTICS_CONTROLLER_FILE")"
  response_helper_line="$(first_match 'function[[:space:]]+send_html_response' "$ANALYTICS_CONTROLLER_FILE")"
  render_path_helper_line="$(first_match 'function[[:space:]]+set_analytics_render_path_header' "$ANALYTICS_CONTROLLER_FILE")"
  primary_path_const_line="$(first_fixed_match "private const PRIMARY_RENDER_PATH = 'primary-direct';" "$ANALYTICS_CONTROLLER_FILE")"
  fallback_path_const_line="$(first_fixed_match "private const FALLBACK_RENDER_PATH = 'fallback-throwable';" "$ANALYTICS_CONTROLLER_FILE")"
  primary_render_line="$(first_fixed_match "\$this->load->view('marketing/analytics/index', \$data);" "$ANALYTICS_CONTROLLER_FILE")"
  primary_header_line="$(first_fixed_match "\$this->set_analytics_render_path_header(self::PRIMARY_RENDER_PATH);" "$ANALYTICS_CONTROLLER_FILE")"
  primary_catch_line="$(first_fixed_match "Marketing analytics primary render failed:" "$ANALYTICS_CONTROLLER_FILE")"
  primary_fallback_return_line="$(first_fixed_match "return \$this->render_fallback_response(\$data, \$baselineOutputLevel, 'primary_render_throwable', \$exception);" "$ANALYTICS_CONTROLLER_FILE")"
  cleanup_before_fallback_line="$(first_fixed_match '$this->clean_output_buffers_to_level((int) $baselineOutputLevel);' "$ANALYTICS_CONTROLLER_FILE")"
  fallback_render_line="$(first_fixed_match "\$html = \$this->load->view('marketing/analytics/fallback', \$fallbackData, true);" "$ANALYTICS_CONTROLLER_FILE")"
  fallback_warning_line="$(first_fixed_match "'analytics_warning_message' => 'Analytics is temporarily unavailable. A safe fallback view is being shown while runtime issues are contained.'" "$ANALYTICS_CONTROLLER_FILE")"
  cleanup_before_minimal_line="$(grep -nF -- '$this->clean_output_buffers_to_level((int) $baselineOutputLevel);' "$ANALYTICS_CONTROLLER_FILE" | sed -n '2p')"
  minimal_html_line="$(first_match 'function[[:space:]]+build_minimal_fallback_html' "$ANALYTICS_CONTROLLER_FILE")"
  marker_line="$(first_fixed_match 'Marketing Analytics Safe Fallback' "$ANALYTICS_CONTROLLER_FILE")"
  status_line="$(first_fixed_match '->set_status_header(200)' "$ANALYTICS_CONTROLLER_FILE")"
  content_type_line="$(first_fixed_match "->set_content_type('text/html', 'utf-8')" "$ANALYTICS_CONTROLLER_FILE")"
  fallback_view_line="$(first_fixed_match 'No secrets, SQL, tokens, payloads, or raw exception traces are shown here.' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  fallback_path_header_line="$(first_fixed_match "'X-Marketing-Analytics-Render-Path: ' . self::FALLBACK_RENDER_PATH" "$ANALYTICS_CONTROLLER_FILE")"
  reason_header_line="$(first_fixed_match "'X-Marketing-Analytics-Fallback: ' . \$reasonCode" "$ANALYTICS_CONTROLLER_FILE")"
  reason_helper_line="$(first_match 'function[[:space:]]+sanitize_fallback_reason_code' "$ANALYTICS_CONTROLLER_FILE")"
  diagnostic_log_helper_line="$(first_match 'function[[:space:]]+append_analytics_fallback_diagnostic_log' "$ANALYTICS_CONTROLLER_FILE")"
  diagnostic_log_path_line="$(first_fixed_match "marketing-analytics-diagnostic-' . date('Y-m-d') . '.log'" "$ANALYTICS_CONTROLLER_FILE")"
  primary_diag_call_line="$(first_fixed_match "\$this->append_analytics_fallback_diagnostic_log(\$reasonCode, \$primaryException);" "$ANALYTICS_CONTROLLER_FILE")"
  fallback_diag_call_line="$(first_fixed_match "\$this->append_analytics_fallback_diagnostic_log('fallback_render_throwable', \$exception);" "$ANALYTICS_CONTROLLER_FILE")"
  minimal_diag_call_line="$(first_fixed_match "\$this->append_analytics_fallback_diagnostic_log('minimal_fallback');" "$ANALYTICS_CONTROLLER_FILE")"
  isolated_render_helper_line="$(first_match 'function[[:space:]]+render_view_in_isolated_buffer' "$ANALYTICS_CONTROLLER_FILE")"
  combine_helper_line="$(first_match 'function[[:space:]]+combine_rendered_html_fragments' "$ANALYTICS_CONTROLLER_FILE")"
  primary_marker_helper_line="$(first_match 'function[[:space:]]+rendered_html_has_primary_analytics_markers' "$ANALYTICS_CONTROLLER_FILE")"
  primary_marker_guard_line="$(first_fixed_match "analytics primary view missing expected Perfex analytics markers" "$ANALYTICS_CONTROLLER_FILE")"
  primary_visible_guard_line="$(first_fixed_match "analytics primary view returned empty visible output" "$ANALYTICS_CONTROLLER_FILE")"
  visibility_helper_line="$(first_match 'function[[:space:]]+rendered_html_has_visible_content' "$ANALYTICS_CONTROLLER_FILE")"
  buffer_level_guard_line="$(first_fixed_match 'analytics render changed output buffer level unexpectedly' "$ANALYTICS_CONTROLLER_FILE")"
  fallback_visible_guard_line="$(first_fixed_match "analytics fallback view returned empty visible output" "$ANALYTICS_CONTROLLER_FILE")"

  if [[ -z "$defaults_line" || -z "$render_helper_line" || -z "$fallback_helper_line" || -z "$return_line" || -z "$baseline_line" || -z "$cleanup_helper_line" || -z "$clear_output_line" || -z "$response_helper_line" || -z "$render_path_helper_line" || -z "$primary_path_const_line" || -z "$fallback_path_const_line" || -z "$primary_render_line" || -z "$primary_header_line" || -z "$primary_catch_line" || -z "$primary_fallback_return_line" || -z "$cleanup_before_fallback_line" || -z "$fallback_render_line" || -z "$fallback_warning_line" || -z "$cleanup_before_minimal_line" || -z "$minimal_html_line" || -z "$marker_line" || -z "$status_line" || -z "$content_type_line" || -z "$fallback_view_line" || -z "$fallback_path_header_line" || -z "$reason_header_line" || -z "$reason_helper_line" || -z "$diagnostic_log_helper_line" || -z "$diagnostic_log_path_line" || -z "$primary_diag_call_line" || -z "$fallback_diag_call_line" || -z "$minimal_diag_call_line" || -n "$isolated_render_helper_line" || -n "$combine_helper_line" || -n "$primary_marker_helper_line" || -n "$primary_marker_guard_line" || -n "$primary_visible_guard_line" || -n "$visibility_helper_line" || -n "$buffer_level_guard_line" || -n "$fallback_visible_guard_line" ]]; then
    fail \
      "analytics controller uses direct Perfex primary rendering and reserves fallback for actual render exceptions" \
      "expected direct load->view() primary render, explicit primary/fallback render-path headers, cleanup before fallback/minimal fallback, fallback diagnostic logging, HTTP 200 text/html fallback response, fallback view support, and no primary marker/buffer heuristics; defaults=${defaults_line:-missing}; render=${render_helper_line:-missing}; fallback_helper=${fallback_helper_line:-missing}; return=${return_line:-missing}; baseline=${baseline_line:-missing}; cleanup=${cleanup_helper_line:-missing}; clear_output=${clear_output_line:-missing}; response=${response_helper_line:-missing}; render_path_helper=${render_path_helper_line:-missing}; primary_const=${primary_path_const_line:-missing}; fallback_const=${fallback_path_const_line:-missing}; primary=${primary_render_line:-missing}; primary_header=${primary_header_line:-missing}; catch=${primary_catch_line:-missing}; primary_return=${primary_fallback_return_line:-missing}; cleanup_fallback=${cleanup_before_fallback_line:-missing}; fallback=${fallback_render_line:-missing}; warning=${fallback_warning_line:-missing}; cleanup_minimal=${cleanup_before_minimal_line:-missing}; minimal=${minimal_html_line:-missing}; marker=${marker_line:-missing}; status=${status_line:-missing}; content_type=${content_type_line:-missing}; fallback_view=${fallback_view_line:-missing}; fallback_path_header=${fallback_path_header_line:-missing}; reason_header=${reason_header_line:-missing}; reason_helper=${reason_helper_line:-missing}; diag_helper=${diagnostic_log_helper_line:-missing}; diag_path=${diagnostic_log_path_line:-missing}; diag_primary=${primary_diag_call_line:-missing}; diag_fallback=${fallback_diag_call_line:-missing}; diag_minimal=${minimal_diag_call_line:-missing}; isolated=${isolated_render_helper_line:-unexpected}; combine=${combine_helper_line:-unexpected}; primary_marker_helper=${primary_marker_helper_line:-unexpected}; primary_marker_guard=${primary_marker_guard_line:-unexpected}; primary_visible=${primary_visible_guard_line:-unexpected}; visibility_helper=${visibility_helper_line:-unexpected}; buffer_level=${buffer_level_guard_line:-unexpected}; fallback_visible=${fallback_visible_guard_line:-unexpected}"
    return
  fi

  pass \
    "analytics controller uses direct Perfex primary rendering and reserves fallback for actual render exceptions" \
    "primary=$primary_render_line | primary_header=$primary_header_line | fallback_header=$fallback_path_header_line"
}

check_analytics_failure_ui_sanitized() {
  local fallback_copy_line raw_exception_line raw_trace_line raw_sql_line get_message_line
  fallback_copy_line="$(first_fixed_match 'No secrets, SQL, tokens, payloads, or raw exception traces are shown here.' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  raw_exception_line="$(grep -nE -- 'Exception|Throwable|ParseError|Stack trace' "$ANALYTICS_VIEW_FILE" "$ANALYTICS_FALLBACK_VIEW_FILE" || true)"
  raw_sql_line="$(grep -nE -- 'SELECT |INSERT |UPDATE |DELETE ' "$ANALYTICS_FALLBACK_VIEW_FILE" || true)"
  get_message_line="$(grep -nF -- 'getMessage()' "$ANALYTICS_VIEW_FILE" "$ANALYTICS_FALLBACK_VIEW_FILE" || true)"
  raw_trace_line="$(grep -nF -- '$exception' "$ANALYTICS_VIEW_FILE" "$ANALYTICS_FALLBACK_VIEW_FILE" || true)"

  if [[ -z "$fallback_copy_line" || -n "$raw_exception_line" || -n "$raw_sql_line" || -n "$get_message_line" || -n "$raw_trace_line" ]]; then
    fail \
      "analytics failure UI stays sanitized and does not expose raw exception details" \
      "expected safe fallback copy and no raw exception, SQL, getMessage(), or exception variable output in analytics views; copy=${fallback_copy_line:-missing}; exception=${raw_exception_line:-none}; sql=${raw_sql_line:-none}; getMessage=${get_message_line:-none}; exception_var=${raw_trace_line:-none}"
    return
  fi

  pass \
    "analytics failure UI stays sanitized and does not expose raw exception details" \
    "copy=$fallback_copy_line"
}

check_analytics_fallback_view_standalone() {
  local doctype_line marker_line warning_line card_line init_head_line init_tail_line body_line comment_line
  doctype_line="$(first_fixed_match '<!DOCTYPE html>' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  marker_line="$(first_fixed_match 'Marketing Analytics Safe Fallback' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  warning_line="$(first_fixed_match '$analytics_warning_message = isset($analytics_warning_message) && is_string($analytics_warning_message)' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  card_line="$(first_fixed_match 'marketing-analytics-fallback-card-value' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  body_line="$(first_fixed_match '<body>' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  comment_line="$(first_fixed_match 'marketing_analytics_render_path=fallback-throwable' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  init_head_line="$(first_fixed_match 'init_head()' "$ANALYTICS_FALLBACK_VIEW_FILE")"
  init_tail_line="$(first_fixed_match 'init_tail()' "$ANALYTICS_FALLBACK_VIEW_FILE")"

  if [[ -z "$doctype_line" || -z "$marker_line" || -z "$warning_line" || -z "$card_line" || -z "$body_line" || -z "$comment_line" || -n "$init_head_line" || -n "$init_tail_line" ]]; then
    fail \
      "analytics fallback view is standalone and visibly renders safe fallback content" \
      "expected standalone HTML document, visible safe-fallback marker, warning text, KPI cards, fallback render-path comment, and no init_head/init_tail dependency in $ANALYTICS_FALLBACK_VIEW_FILE; doctype=${doctype_line:-missing}; marker=${marker_line:-missing}; warning=${warning_line:-missing}; card=${card_line:-missing}; body=${body_line:-missing}; comment=${comment_line:-missing}; init_head=${init_head_line:-unexpected}; init_tail=${init_tail_line:-unexpected}"
    return
  fi

  pass \
    "analytics fallback view is standalone and visibly renders safe fallback content" \
    "doctype=$doctype_line | marker=$marker_line | comment=$comment_line"
}

check_analytics_primary_view_normal_layout() {
  local init_head_line init_tail_line wrapper_line metric_line section_line table_line fallback_marker_line doctype_line diagnostic_comment_line diagnostic_header_line
  init_head_line="$(first_fixed_match 'init_head()' "$ANALYTICS_VIEW_FILE")"
  init_tail_line="$(first_fixed_match 'init_tail()' "$ANALYTICS_VIEW_FILE")"
  wrapper_line="$(first_fixed_match '<div id="wrapper">' "$ANALYTICS_VIEW_FILE")"
  metric_line="$(first_fixed_match 'marketing-metric-card' "$ANALYTICS_VIEW_FILE")"
  section_line="$(first_fixed_match 'marketing-analytics-section-title' "$ANALYTICS_VIEW_FILE")"
  table_line="$(first_fixed_match '<table class="table dt-table">' "$ANALYTICS_VIEW_FILE")"
  fallback_marker_line="$(first_fixed_match 'Marketing Analytics Safe Fallback' "$ANALYTICS_VIEW_FILE")"
  doctype_line="$(first_fixed_match '<!DOCTYPE html>' "$ANALYTICS_VIEW_FILE")"
  diagnostic_comment_line="$(first_fixed_match 'marketing_analytics_render_path=' "$ANALYTICS_VIEW_FILE")"
  diagnostic_header_line="$(first_fixed_match 'X-Marketing-Analytics-Render-Path' "$ANALYTICS_VIEW_FILE")"

  if [[ -z "$init_head_line" || -z "$init_tail_line" || -z "$wrapper_line" || -z "$metric_line" || -z "$section_line" || -z "$table_line" || -n "$fallback_marker_line" || -n "$doctype_line" || -n "$diagnostic_comment_line" || -n "$diagnostic_header_line" ]]; then
    fail \
      "analytics primary view remains the normal Perfex admin layout and is not forced into fallback markup" \
      "expected init_head/init_tail layout hooks, wrapper shell, metric cards, analytics sections, and dt-table in $ANALYTICS_VIEW_FILE, with no standalone doctype, fallback marker, or fallback diagnostics markers; init_head=${init_head_line:-missing}; init_tail=${init_tail_line:-missing}; wrapper=${wrapper_line:-missing}; metric=${metric_line:-missing}; section=${section_line:-missing}; table=${table_line:-missing}; fallback_marker=${fallback_marker_line:-unexpected}; doctype=${doctype_line:-unexpected}; diagnostic_comment=${diagnostic_comment_line:-unexpected}; diagnostic_header=${diagnostic_header_line:-unexpected}"
    return
  fi

  pass \
    "analytics primary view remains the normal Perfex admin layout and is not forced into fallback markup" \
    "wrapper=$wrapper_line | metric=$metric_line | table=$table_line"
}

check_retention_preserves_unsubscribe_audit_events() {
  local helper_line unsubscribe_line operator_line
  helper_line="$(first_match 'function[[:space:]]+get_audit_critical_event_types' "$EVENTS_FILE")"
  unsubscribe_line="$(first_fixed_match "'unsubscribe'" "$EVENTS_FILE")"
  operator_line="$(first_fixed_match "'operator_action'" "$EVENTS_FILE")"

  if [[ -z "$helper_line" || -z "$unsubscribe_line" || -z "$operator_line" ]]; then
    fail \
      "retention cleanup preserves unsubscribe and other audit-critical events" \
      "expected audit-critical helper plus unsubscribe and operator_action markers in $EVENTS_FILE; helper=${helper_line:-missing}; unsubscribe=${unsubscribe_line:-missing}; operator=${operator_line:-missing}"
    return
  fi

  pass \
    "retention cleanup preserves unsubscribe and other audit-critical events" \
    "helper=$helper_line | unsubscribe=$unsubscribe_line | operator=$operator_line"
}

check_no_destructive_retention_calls() {
  local empty_line truncate_line controller_clear_line
  empty_line="$(first_fixed_match 'empty_table(' "$EVENTS_FILE")"
  truncate_line="$(first_fixed_match 'truncate(' "$EVENTS_FILE")"
  controller_clear_line="$(first_fixed_match "clear_marketing_events" "$ANALYTICS_CONTROLLER_FILE")"

  if [[ -n "$empty_line" || -n "$truncate_line" || -n "$controller_clear_line" ]]; then
    fail \
      "retention cleanup does not use destructive clear-history paths" \
      "expected no empty_table(), truncate(), or clear_marketing_events path in analytics retention flow; empty=${empty_line:-none}; truncate=${truncate_line:-none}; controller=${controller_clear_line:-none}"
    return
  fi

  pass \
    "retention cleanup does not use destructive clear-history paths" \
    "no empty_table(), truncate(), or clear_marketing_events path found"
}

check_click_aggregate_cleanup_scope() {
  local helper_line from_line join_line type_click_line stale_from_line stale_join_line stale_type_click_line type_unsubscribe_line reset_line skip_line public_links_reset_helper_line public_links_reset_query_line public_links_reset_call_line tblp_line
  helper_line="$(first_match 'function[[:space:]]+reset_stale_click_aggregates' "$PUBLIC_LINKS_FILE")"
  from_line="$(first_fixed_match "from(\$this->table . ' pl')" "$PUBLIC_LINKS_FILE")"
  join_line="$(first_fixed_match "join(\$this->campaignsTable . ' c', 'c.id = pl.campaign_id', 'left')" "$PUBLIC_LINKS_FILE")"
  type_click_line="$(first_fixed_match "where('pl.token_type', self::TYPE_CLICK)" "$PUBLIC_LINKS_FILE")"
  stale_from_line="$(first_fixed_match "from(\$this->table . ' p')" "$PUBLIC_LINKS_FILE")"
  stale_join_line="$(first_fixed_match "join(\$this->campaignsTable . ' c', 'c.id = p.campaign_id', 'left')" "$PUBLIC_LINKS_FILE")"
  stale_type_click_line="$(first_fixed_match "where('p.token_type', self::TYPE_CLICK)" "$PUBLIC_LINKS_FILE")"
  type_unsubscribe_line="$(first_fixed_match 'TYPE_UNSUBSCRIBE' "$PUBLIC_LINKS_FILE")"
  reset_line="$(first_fixed_match "\$summary['click_links_reset'] = (int) (\$clickAggregateSummary['click_links_reset'] ?? 0);" "$EVENTS_FILE")"
  skip_line="$(first_fixed_match "\$summary['click_links_skipped'] = (int) (\$clickAggregateSummary['click_links_skipped'] ?? 0);" "$EVENTS_FILE")"
  public_links_reset_helper_line="$(first_match 'function[[:space:]]+reset_public_links_query_builder' "$PUBLIC_LINKS_FILE")"
  public_links_reset_query_line="$(grep -nF -- '$this->db->reset_query();' "$PUBLIC_LINKS_FILE" | tail -n 1 || true)"
  public_links_reset_call_line="$(first_fixed_match "\$this->reset_public_links_query_builder();" "$PUBLIC_LINKS_FILE")"
  tblp_line="$(first_fixed_match 'tblp.' "$PUBLIC_LINKS_FILE")"

  if [[ -z "$helper_line" || -z "$from_line" || -z "$join_line" || -z "$type_click_line" || -n "$stale_from_line" || -n "$stale_join_line" || -n "$stale_type_click_line" || -z "$type_unsubscribe_line" || -z "$reset_line" || -z "$skip_line" || -z "$public_links_reset_helper_line" || -z "$public_links_reset_query_line" || -z "$public_links_reset_call_line" || -n "$tblp_line" ]]; then
    fail \
      "click aggregate cleanup targets only tracking links and reports reset counts" \
      "expected click aggregate reset helper in $PUBLIC_LINKS_FILE, public-links query-builder reset isolation, unique pl alias in from/join/TYPE_CLICK scope with no stale p or tblp alias references, unsubscribe constant preserved, and click_links_reset/click_links_skipped summary keys in $EVENTS_FILE; helper=${helper_line:-missing}; from=${from_line:-missing}; join=${join_line:-missing}; click_scope=${type_click_line:-missing}; stale_from=${stale_from_line:-unexpected}; stale_join=${stale_join_line:-unexpected}; stale_click_scope=${stale_type_click_line:-unexpected}; unsubscribe=${type_unsubscribe_line:-missing}; reset=${reset_line:-missing}; skipped=${skip_line:-missing}; public_reset_helper=${public_links_reset_helper_line:-missing}; public_reset_query=${public_links_reset_query_line:-missing}; public_reset_call=${public_links_reset_call_line:-missing}; tblp=${tblp_line:-unexpected}"
    return
  fi

  pass \
    "click aggregate cleanup targets only tracking links and reports reset counts" \
    "helper=$helper_line | from=$from_line | join=$join_line | click_scope=$type_click_line | reset=$reset_line | skipped=$skip_line"
}

check_click_aggregate_cleanup_bounds() {
  local floor_line limit_line limit_usage_line active_campaign_line
  floor_line="$(first_match 'RETENTION_MIN_DAYS' "$PUBLIC_LINKS_FILE")"
  limit_line="$(first_match 'RETENTION_RESET_LIMIT' "$PUBLIC_LINKS_FILE")"
  limit_usage_line="$(first_fixed_match 'min(self::RETENTION_RESET_LIMIT, (int) $limit)' "$PUBLIC_LINKS_FILE")"
  active_campaign_line="$(first_fixed_match 'ACTIVE_CAMPAIGN_STATUSES' "$PUBLIC_LINKS_FILE")"

  if [[ -z "$floor_line" || -z "$limit_line" || -z "$limit_usage_line" || -z "$active_campaign_line" ]]; then
    fail \
      "click aggregate cleanup is bounded, respects retention floor, and skips active campaigns" \
      "expected retention floor, reset limit, bounded limit usage, and active campaign guard in $PUBLIC_LINKS_FILE; floor=${floor_line:-missing}; limit=${limit_line:-missing}; usage=${limit_usage_line:-missing}; active=${active_campaign_line:-missing}"
    return
  fi

  pass \
    "click aggregate cleanup is bounded, respects retention floor, and skips active campaigns" \
    "floor=$floor_line | limit=$limit_line | usage=$limit_usage_line"
}

check_events_public_links_fail_closed() {
  local helper_line diagnostics_line method_line warning_line cleanup_method_line
  helper_line="$(first_match 'function[[:space:]]+get_public_links_model' "$EVENTS_FILE")"
  diagnostics_line="$(first_match 'function[[:space:]]+get_click_aggregate_retention_diagnostics_summary' "$EVENTS_FILE")"
  cleanup_method_line="$(first_match 'function[[:space:]]+run_click_aggregate_cleanup' "$EVENTS_FILE")"
  method_line="$(first_fixed_match "method_exists(\$publicLinksModel, 'reset_stale_click_aggregates')" "$EVENTS_FILE")"
  warning_line="$(first_fixed_match "'warnings' => ['click_aggregate_cleanup_unavailable']" "$EVENTS_FILE")"

  if [[ -z "$helper_line" || -z "$diagnostics_line" || -z "$cleanup_method_line" || -z "$method_line" || -z "$warning_line" ]]; then
    fail \
      "marketing events public-link integration fails closed when cleanup helpers are unavailable" \
      "expected public-links loader, diagnostics helper, cleanup helper, method_exists guard, and fail-closed warning defaults in $EVENTS_FILE; loader=${helper_line:-missing}; diagnostics=${diagnostics_line:-missing}; cleanup=${cleanup_method_line:-missing}; method=${method_line:-missing}; warning=${warning_line:-missing}"
    return
  fi

  pass \
    "marketing events public-link integration fails closed when cleanup helpers are unavailable" \
    "loader=$helper_line | cleanup=$cleanup_method_line | method=$method_line"
}

check_settings_two_column_layout() {
  local grid_line shell_line column_line
  grid_line="$(first_fixed_match '.marketing-settings-grid {' "$SETTINGS_VIEW_FILE")"
  shell_line="$(first_fixed_match '.marketing-settings-shell {' "$SETTINGS_VIEW_FILE")"
  column_line="$(first_fixed_match '<div class="marketing-settings-grid">' "$SETTINGS_VIEW_FILE")"

  if [[ -z "$grid_line" || -z "$shell_line" || -z "$column_line" ]]; then
    fail \
      "settings page uses a structured two-column layout shell" \
      "expected settings shell and two-column grid in $SETTINGS_VIEW_FILE; shell=${shell_line:-missing}; grid=${grid_line:-missing}; markup=${column_line:-missing}"
    return
  fi

  pass \
    "settings page uses a structured two-column layout shell" \
    "shell=$shell_line | grid=$grid_line | markup=$column_line"
}

check_settings_mobile_responsive_layout() {
  local media_line collapse_line field_line smtp_line whatsapp_line
  media_line="$(first_fixed_match '@media (max-width: 767px)' "$SETTINGS_VIEW_FILE")"
  collapse_line="$(first_fixed_match 'grid-template-columns: 1fr;' "$SETTINGS_VIEW_FILE")"
  field_line="$(first_fixed_match 'marketing_send_enabled' "$SETTINGS_VIEW_FILE")"
  smtp_line="$(first_fixed_match 'marketing_smtp_enabled' "$SETTINGS_VIEW_FILE")"
  whatsapp_line="$(first_fixed_match 'marketing_whatsapp_enabled' "$SETTINGS_VIEW_FILE")"

  if [[ -z "$media_line" || -z "$collapse_line" || -z "$field_line" || -z "$smtp_line" || -z "$whatsapp_line" ]]; then
    fail \
      "settings page collapses cleanly for mobile and preserves core settings fields" \
      "expected responsive media query, single-column collapse, and core settings fields in $SETTINGS_VIEW_FILE; media=${media_line:-missing}; collapse=${collapse_line:-missing}; general=${field_line:-missing}; smtp=${smtp_line:-missing}; whatsapp=${whatsapp_line:-missing}"
    return
  fi

  pass \
    "settings page collapses cleanly for mobile and preserves core settings fields" \
    "media=$media_line | collapse=$collapse_line | general=$field_line"
}

check_settings_license_block_order() {
  local inbound_line license_line second_column_line
  inbound_line="$(first_fixed_match "marketing_inbound_reply_webhook_url" "$SETTINGS_VIEW_FILE")"
  license_line="$(first_fixed_match "marketing_license_updates_heading" "$SETTINGS_VIEW_FILE")"
  second_column_line="$(grep -nF -- '<div class="marketing-settings-column">' "$SETTINGS_VIEW_FILE" | sed -n '2p')"

  if [[ -z "$inbound_line" || -z "$license_line" || -z "$second_column_line" ]]; then
    fail \
      "settings page places License & Updates after Inbound Reply Webhook URL in the left column" \
      "expected inbound webhook section, license section, and second column marker in $SETTINGS_VIEW_FILE; inbound=${inbound_line:-missing}; license=${license_line:-missing}; second_column=${second_column_line:-missing}"
    return
  fi

  local inbound_no license_no second_column_no
  inbound_no="${inbound_line%%:*}"
  license_no="${license_line%%:*}"
  second_column_no="${second_column_line%%:*}"

  if [[ "$license_no" -le "$inbound_no" || "$license_no" -ge "$second_column_no" ]]; then
    fail \
      "settings page places License & Updates after Inbound Reply Webhook URL in the left column" \
      "expected license block after inbound webhook and before second column; inbound=$inbound_line | license=$license_line | second_column=$second_column_line"
    return
  fi

  pass \
    "settings page places License & Updates after Inbound Reply Webhook URL in the left column" \
    "inbound=$inbound_line | license=$license_line | second_column=$second_column_line"
}

check_settings_provider_readiness_panel() {
  local controller_assignment controller_helper email_line ses_line inbound_line whatsapp_line smtp_check token_check app_secret_check inbound_secret_check preserve_inbound preserve_token preserve_secret view_heading view_note view_panel view_status view_loop view_secret_exposure forbidden_webhook forbidden_whatsapp forbidden_ses git_diff_mode
  controller_assignment="$(first_fixed_match "\$data['provider_readiness'] = \$this->build_provider_readiness_summary(" "$SETTINGS_CONTROLLER_FILE")"
  controller_helper="$(first_match 'function[[:space:]]+build_provider_readiness_summary' "$SETTINGS_CONTROLLER_FILE")"
  email_line="$(first_fixed_match "_l('marketing_provider_readiness_email_delivery')" "$SETTINGS_CONTROLLER_FILE")"
  ses_line="$(first_fixed_match "_l('marketing_provider_readiness_ses_webhooks')" "$SETTINGS_CONTROLLER_FILE")"
  inbound_line="$(first_fixed_match "_l('marketing_provider_readiness_inbound_email')" "$SETTINGS_CONTROLLER_FILE")"
  whatsapp_line="$(first_fixed_match "_l('marketing_provider_readiness_whatsapp')" "$SETTINGS_CONTROLLER_FILE")"
  smtp_check="$(first_fixed_match "trim((string) get_option('marketing_smtp_host')) !== ''" "$SETTINGS_CONTROLLER_FILE")"
  token_check="$(first_fixed_match "\$whatsappAccessTokenConfigured" "$SETTINGS_CONTROLLER_FILE")"
  app_secret_check="$(first_fixed_match "\$whatsappAppSecretConfigured" "$SETTINGS_CONTROLLER_FILE")"
  inbound_secret_check="$(first_fixed_match "\$inboundEmailSecretConfigured" "$SETTINGS_CONTROLLER_FILE")"
  preserve_inbound="$(first_fixed_match "'marketing_inbound_email_secret' => \$postedSecret !== '' ? \$postedSecret : \$existingSecret," "$SETTINGS_CONTROLLER_FILE")"
  preserve_token="$(first_fixed_match "if (\$accessToken !== '') {" "$SETTINGS_CONTROLLER_FILE")"
  preserve_secret="$(first_fixed_match "if (\$appSecret !== '') {" "$SETTINGS_CONTROLLER_FILE")"
  view_heading="$(first_fixed_match "_l('marketing_provider_readiness_heading')" "$SETTINGS_VIEW_FILE")"
  view_note="$(first_fixed_match "_l('marketing_provider_readiness_note')" "$SETTINGS_VIEW_FILE")"
  view_panel="$(first_fixed_match 'marketing-provider-readiness-list' "$SETTINGS_VIEW_FILE")"
  view_status="$(first_fixed_match 'marketing-provider-readiness-status-' "$SETTINGS_VIEW_FILE")"
  view_loop="$(first_fixed_match "<?php foreach (\$provider_readiness as \$providerStatus) { ?>" "$SETTINGS_VIEW_FILE")"
  view_secret_exposure="$(grep -nE -- "get_option\\('marketing_(whatsapp_access_token|whatsapp_app_secret|inbound_email_secret)'\\)" "$SETTINGS_VIEW_FILE" || true)"
  forbidden_webhook="$(git_changed_path_if_available modules/marketing/controllers/Webhooks.php)"
  forbidden_whatsapp="$(git_changed_path_if_available modules/marketing/libraries/Marketing_whatsapp.php)"
  forbidden_ses="$(git_changed_path_if_available modules/marketing/libraries/Marketing_ses_sns.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$controller_assignment" || -z "$controller_helper" || -z "$email_line" || -z "$ses_line" || -z "$inbound_line" || -z "$whatsapp_line" || -z "$smtp_check" || -z "$token_check" || -z "$app_secret_check" || -z "$inbound_secret_check" || -z "$preserve_inbound" || -z "$preserve_token" || -z "$preserve_secret" || -z "$view_heading" || -z "$view_note" || -z "$view_panel" || -z "$view_status" || -z "$view_loop" || -n "$view_secret_exposure" || -n "$forbidden_webhook" || -n "$forbidden_whatsapp" || -n "$forbidden_ses" ]]; then
    fail \
      "settings page adds read-only provider readiness without exposing secrets or changing provider runtime files" \
      "expected controller-driven provider readiness summary in $SETTINGS_CONTROLLER_FILE, read-only readiness panel markup in $SETTINGS_VIEW_FILE, preserved blank-secret/token save behavior, no direct secret rendering via get_option() in the view, and no webhook/provider library file changes; assignment=${controller_assignment:-missing}; helper=${controller_helper:-missing}; email=${email_line:-missing}; ses=${ses_line:-missing}; inbound=${inbound_line:-missing}; whatsapp=${whatsapp_line:-missing}; smtp=${smtp_check:-missing}; token=${token_check:-missing}; app_secret=${app_secret_check:-missing}; inbound_secret=${inbound_secret_check:-missing}; preserve_inbound=${preserve_inbound:-missing}; preserve_token=${preserve_token:-missing}; preserve_secret=${preserve_secret:-missing}; heading=${view_heading:-missing}; note=${view_note:-missing}; panel=${view_panel:-missing}; status=${view_status:-missing}; loop=${view_loop:-missing}; view_secret_exposure=${view_secret_exposure:-none}; git_diff_mode=${git_diff_mode:-unknown}; webhook_diff=${forbidden_webhook:-none}; whatsapp_diff=${forbidden_whatsapp:-none}; ses_diff=${forbidden_ses:-none}"
    return
  fi

  pass \
    "settings page adds read-only provider readiness without exposing secrets or changing provider runtime files" \
    "controller=$controller_helper | panel=$view_heading | git_diff_mode=$git_diff_mode"
}

check_settings_release_readiness_panel() {
  local controller_assignment controller_helper module_line license_line docs_line validator_line provider_line integrity_line operations_line docs_path integrity_path validator_path view_heading view_note view_loop lang_heading lang_note view_secret_exposure shell_exec_usage forbidden_webhook forbidden_whatsapp forbidden_ses git_diff_mode
  controller_assignment="$(first_fixed_match "\$data['release_readiness'] = \$this->build_release_readiness_summary(" "$SETTINGS_CONTROLLER_FILE")"
  controller_helper="$(first_match 'function[[:space:]]+build_release_readiness_summary' "$SETTINGS_CONTROLLER_FILE")"
  module_line="$(first_fixed_match "_l('marketing_release_readiness_module_metadata')" "$SETTINGS_CONTROLLER_FILE")"
  license_line="$(first_fixed_match "_l('marketing_release_readiness_license_support')" "$SETTINGS_CONTROLLER_FILE")"
  docs_line="$(first_fixed_match "_l('marketing_release_readiness_docs')" "$SETTINGS_CONTROLLER_FILE")"
  validator_line="$(first_fixed_match "_l('marketing_release_readiness_release_validation')" "$SETTINGS_CONTROLLER_FILE")"
  provider_line="$(first_fixed_match "_l('marketing_release_readiness_provider_summary')" "$SETTINGS_CONTROLLER_FILE")"
  integrity_line="$(first_fixed_match "_l('marketing_release_readiness_integrity')" "$SETTINGS_CONTROLLER_FILE")"
  operations_line="$(first_fixed_match "_l('marketing_release_readiness_operations')" "$SETTINGS_CONTROLLER_FILE")"
  docs_path="$(first_fixed_match "'/README.md'" "$SETTINGS_CONTROLLER_FILE")"
  integrity_path="$(first_fixed_match "'/scripts/verify_phase4_integrity_runtime.php'" "$SETTINGS_CONTROLLER_FILE")"
  validator_path="$(first_fixed_match "'/scripts/verify_release_package.sh'" "$SETTINGS_CONTROLLER_FILE")"
  view_heading="$(first_fixed_match "_l('marketing_release_readiness_heading')" "$SETTINGS_VIEW_FILE")"
  view_note="$(first_fixed_match "_l('marketing_release_readiness_note')" "$SETTINGS_VIEW_FILE")"
  view_loop="$(first_fixed_match "<?php foreach (\$release_readiness as \$readinessItem) { ?>" "$SETTINGS_VIEW_FILE")"
  lang_heading="$(first_fixed_match "\$lang['marketing_release_readiness_heading']" "modules/marketing/language/english/marketing_lang.php")"
  lang_note="$(first_fixed_match "\$lang['marketing_release_readiness_note']" "modules/marketing/language/english/marketing_lang.php")"
  view_secret_exposure="$(grep -nE -- "license_key|access_token|app_secret|webhook_verify_token|inbound_email_secret" "$SETTINGS_VIEW_FILE" | grep 'release_readiness' || true)"
  shell_exec_usage="$(grep -nE -- 'shell_exec|exec[(]|system[(]|proc_open|popen' "$SETTINGS_CONTROLLER_FILE" "$SETTINGS_VIEW_FILE" || true)"
  forbidden_webhook="$(git_changed_path_if_available modules/marketing/controllers/Webhooks.php)"
  forbidden_whatsapp="$(git_changed_path_if_available modules/marketing/libraries/Marketing_whatsapp.php)"
  forbidden_ses="$(git_changed_path_if_available modules/marketing/libraries/Marketing_ses_sns.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$controller_assignment" || -z "$controller_helper" || -z "$module_line" || -z "$license_line" || -z "$docs_line" || -z "$validator_line" || -z "$provider_line" || -z "$integrity_line" || -z "$operations_line" || -z "$docs_path" || -z "$integrity_path" || -z "$validator_path" || -z "$view_heading" || -z "$view_note" || -z "$view_loop" || -z "$lang_heading" || -z "$lang_note" || -n "$view_secret_exposure" || -n "$shell_exec_usage" || -n "$forbidden_webhook" || -n "$forbidden_whatsapp" || -n "$forbidden_ses" ]]; then
    fail \
      "settings page adds read-only release readiness without shell execution or runtime file changes" \
      "expected controller-driven release readiness summary, settings-page panel markup, language strings, module-relative doc/validator/integrity file checks, no secret rendering, no shell execution, and no provider/webhook runtime file changes; assignment=${controller_assignment:-missing}; helper=${controller_helper:-missing}; module=${module_line:-missing}; license=${license_line:-missing}; docs=${docs_line:-missing}; validator=${validator_line:-missing}; provider=${provider_line:-missing}; integrity=${integrity_line:-missing}; operations=${operations_line:-missing}; docs_path=${docs_path:-missing}; integrity_path=${integrity_path:-missing}; validator_path=${validator_path:-missing}; heading=${view_heading:-missing}; note=${view_note:-missing}; loop=${view_loop:-missing}; lang_heading=${lang_heading:-missing}; lang_note=${lang_note:-missing}; secret=${view_secret_exposure:-none}; shell=${shell_exec_usage:-none}; git_diff_mode=${git_diff_mode:-unknown}; webhook_diff=${forbidden_webhook:-none}; whatsapp_diff=${forbidden_whatsapp:-none}; ses_diff=${forbidden_ses:-none}"
    return
  fi

  pass \
    "settings page adds read-only release readiness without shell execution or runtime file changes" \
    "controller=$controller_helper | panel=$view_heading | git_diff_mode=$git_diff_mode"
}

check_dashboard_operator_ui_structure() {
  local operations_title_line controls_line health_line warnings_line failure_line audit_line retention_line compact_grid_line executive_shell_line advanced_details_line visible_raw_command
  operations_title_line="$(first_fixed_match "marketing_dashboard_operations_center" "$DASHBOARD_VIEW_FILE")"
  controls_line="$(first_fixed_match 'Queue Controls' "$DASHBOARD_VIEW_FILE")"
  health_line="$(first_fixed_match 'Queue Health' "$DASHBOARD_VIEW_FILE")"
  warnings_line="$(first_fixed_match 'Recent Warnings' "$DASHBOARD_VIEW_FILE")"
  failure_line="$(first_fixed_match 'Failure Metrics' "$DASHBOARD_VIEW_FILE")"
  audit_line="$(first_fixed_match 'Operator Audit' "$DASHBOARD_VIEW_FILE")"
  retention_line="$(first_fixed_match 'Telemetry Retention' "$DASHBOARD_VIEW_FILE")"
  compact_grid_line="$(first_fixed_match 'marketing-ops-compact-grid' "$DASHBOARD_VIEW_FILE")"
  executive_shell_line="$(first_fixed_match 'marketing-dashboard-hero' "$DASHBOARD_VIEW_FILE")"
  advanced_details_line="$(first_fixed_match 'marketing-ops-advanced' "$DASHBOARD_VIEW_FILE")"
  visible_raw_command="$(grep -nF "marketing-ops-section-copy\">Read-only runtime integrity checks for queue state, provider events, inbound replies, and schema drift. Run <code>php modules/marketing/scripts/verify_phase4_integrity_runtime.php</code>" "$DASHBOARD_VIEW_FILE" || true)"

  if [[ -z "$operations_title_line" || -z "$controls_line" || -z "$health_line" || -z "$warnings_line" || -z "$failure_line" || -z "$audit_line" || -z "$retention_line" || -z "$compact_grid_line" || -z "$executive_shell_line" || -z "$advanced_details_line" || -n "$visible_raw_command" ]]; then
    fail \
      "dashboard operator controls are rendered as structured commercial UI sections" \
      "expected executive dashboard shell, Delivery Operations, Queue Controls, Queue Health, Recent Warnings, Failure Metrics, Operator Audit, Telemetry Retention, compact diagnostics grid, and collapsed advanced diagnostics commands without raw CLI copy in always-visible integrity text; hero=${executive_shell_line:-missing}; operations=${operations_title_line:-missing}; controls=${controls_line:-missing}; health=${health_line:-missing}; warnings=${warnings_line:-missing}; failure=${failure_line:-missing}; audit=${audit_line:-missing}; retention=${retention_line:-missing}; compact_grid=${compact_grid_line:-missing}; advanced=${advanced_details_line:-missing}; visible_raw=${visible_raw_command:-none}"
    return
  fi

  pass \
    "dashboard operator controls are rendered as structured commercial UI sections" \
    "hero=$executive_shell_line | operations=$operations_title_line | controls=$controls_line | advanced=$advanced_details_line"
}

check_marketing_reports_surface() {
  local controller_method controller_recent_campaigns controller_recent_activity controller_view menu_item dashboard_campaigns dashboard_activity dashboard_reset reports_title reports_note reports_campaigns reports_activity reports_campaigns_table reports_activity_table campaigns_length activity_length reset_campaigns reset_activity reset_button_type reset_handler reset_guard reset_search reset_column_search reset_order reset_length reset_page reset_draw reset_label reset_note reset_form
  controller_method="$(first_match 'function[[:space:]]+reports' "$MARKETING_CONTROLLER_FILE")"
  controller_recent_campaigns="$(first_fixed_match "get_recent_campaigns(5)" "$MARKETING_CONTROLLER_FILE")"
  controller_recent_activity="$(first_fixed_match "get_recent_activity(10)" "$MARKETING_CONTROLLER_FILE")"
  controller_view="$(first_fixed_match "\$this->load->view('marketing/reports', \$data);" "$MARKETING_CONTROLLER_FILE")"
  menu_item="$(first_fixed_match "admin_url('marketing/reports')" "$BOOTSTRAP_FILE")"
  dashboard_campaigns="$(first_fixed_match "_l('marketing_recent_campaigns')" "$DASHBOARD_VIEW_FILE")"
  dashboard_activity="$(first_fixed_match "_l('marketing_recent_activity')" "$DASHBOARD_VIEW_FILE")"
  dashboard_reset="$(first_fixed_match 'data-marketing-reset-table=' "$DASHBOARD_VIEW_FILE")"
  reports_title="$(first_fixed_match "_l('marketing_reports_heading')" "$REPORTS_VIEW_FILE")"
  reports_note="$(first_fixed_match "_l('marketing_reports_note')" "$REPORTS_VIEW_FILE")"
  reports_campaigns="$(first_fixed_match "_l('marketing_reports_recent_campaigns')" "$REPORTS_VIEW_FILE")"
  reports_activity="$(first_fixed_match "_l('marketing_reports_recent_activity')" "$REPORTS_VIEW_FILE")"
  reports_campaigns_table="$(first_fixed_match 'marketing-reports-recent-campaigns-table' "$REPORTS_VIEW_FILE")"
  reports_activity_table="$(first_fixed_match 'marketing-reports-recent-activity-table' "$REPORTS_VIEW_FILE")"
  campaigns_length="$(first_fixed_match 'data-marketing-default-length="25"' "$REPORTS_VIEW_FILE")"
  activity_length="$(printf '%s\n' "$campaigns_length" | tail -n 1)"
  reset_campaigns="$(first_fixed_match 'data-marketing-reset-table="campaigns"' "$REPORTS_VIEW_FILE")"
  reset_activity="$(first_fixed_match 'data-marketing-reset-table="activity"' "$REPORTS_VIEW_FILE")"
  reset_button_type="$(first_fixed_match 'type="button"' "$REPORTS_VIEW_FILE")"
  reset_handler="$(first_fixed_match "trigger.getAttribute('data-marketing-reset-table')" "$REPORTS_VIEW_FILE")"
  reset_guard="$(first_fixed_match "$.fn.DataTable.isDataTable" "$REPORTS_VIEW_FILE")"
  reset_search="$(first_fixed_match "dataTable.search('');" "$REPORTS_VIEW_FILE")"
  reset_column_search="$(first_fixed_match "dataTable.columns().search('');" "$REPORTS_VIEW_FILE")"
  reset_order="$(first_fixed_match "dataTable.order(defaults.order);" "$REPORTS_VIEW_FILE")"
  reset_length="$(first_fixed_match "dataTable.page.len(defaults.length);" "$REPORTS_VIEW_FILE")"
  reset_page="$(first_fixed_match "dataTable.page('first');" "$REPORTS_VIEW_FILE")"
  reset_draw="$(first_fixed_match "dataTable.draw(false);" "$REPORTS_VIEW_FILE")"
  reset_label="$(first_fixed_match "marketing_table_reset_view" "modules/marketing/language/english/marketing_lang.php")"
  reset_note="$(first_fixed_match "marketing_table_reset_view_note" "$REPORTS_VIEW_FILE")"
  reset_form="$(grep -nF "form_open(admin_url('marketing/reset" "$REPORTS_VIEW_FILE" || true)"

  if [[ -z "$controller_method" || -z "$controller_recent_campaigns" || -z "$controller_recent_activity" || -z "$controller_view" || -z "$menu_item" || -n "$dashboard_campaigns" || -n "$dashboard_activity" || -n "$dashboard_reset" || -z "$reports_title" || -z "$reports_note" || -z "$reports_campaigns" || -z "$reports_activity" || -z "$reports_campaigns_table" || -z "$reports_activity_table" || -z "$campaigns_length" || -z "$activity_length" || -z "$reset_campaigns" || -z "$reset_activity" || -z "$reset_button_type" || -z "$reset_handler" || -z "$reset_guard" || -z "$reset_search" || -z "$reset_column_search" || -z "$reset_order" || -z "$reset_length" || -z "$reset_page" || -z "$reset_draw" || -z "$reset_label" || -z "$reset_note" || -n "$reset_form" ]]; then
    fail \
      "marketing reports page contains recent tables and client-side reset controls while dashboard stays operational" \
      "expected reports controller/view/menu wiring, dashboard removal of recent campaign/activity tables, and report-page-only DataTables reset controls with 25-row defaults; method=${controller_method:-missing}; campaigns=${controller_recent_campaigns:-missing}; activity=${controller_recent_activity:-missing}; view=${controller_view:-missing}; menu=${menu_item:-missing}; dashboard_campaigns=${dashboard_campaigns:-unexpected}; dashboard_activity=${dashboard_activity:-unexpected}; dashboard_reset=${dashboard_reset:-unexpected}; reports_title=${reports_title:-missing}; reports_note=${reports_note:-missing}; reports_campaigns=${reports_campaigns:-missing}; reports_activity=${reports_activity:-missing}; campaigns_table=${reports_campaigns_table:-missing}; activity_table=${reports_activity_table:-missing}; campaigns_length=${campaigns_length:-missing}; activity_length=${activity_length:-missing}; reset_campaigns=${reset_campaigns:-missing}; reset_activity=${reset_activity:-missing}; button=${reset_button_type:-missing}; handler=${reset_handler:-missing}; guard=${reset_guard:-missing}; search=${reset_search:-missing}; column_search=${reset_column_search:-missing}; order=${reset_order:-missing}; length=${reset_length:-missing}; page=${reset_page:-missing}; draw=${reset_draw:-missing}; label=${reset_label:-missing}; note=${reset_note:-missing}; reset_form=${reset_form:-none}"
    return
  fi

  pass \
    "marketing reports page contains recent tables and client-side reset controls while dashboard stays operational" \
    "method=$controller_method | menu=$menu_item | campaigns=$reports_campaigns_table | activity=$reports_activity_table | length=$campaigns_length"
}

check_marketing_reports_cleanup_controls() {
  local controller_method controller_permission controller_post_guard controller_target controller_confirmation controller_redirect activity_helper campaigns_helper activity_delete activity_trans campaign_blocked reports_form reports_target_activity reports_target_campaigns reports_warning reports_export reports_typed reports_phrase_activity reports_phrase_campaigns reports_danger_zone reports_activity_card reports_campaign_card forbidden_truncate protected_contacts protected_lists protected_suppressions protected_conversations protected_messages protected_leads protected_integrations protected_options protected_perfex
  controller_method="$(first_match 'function[[:space:]]+reports_cleanup' "$MARKETING_CONTROLLER_FILE")"
  controller_permission="$(first_fixed_match "staff_cant('settings', 'marketing')" "$MARKETING_CONTROLLER_FILE")"
  controller_post_guard="$(first_fixed_match "if (!\$this->input->post()) {" "$MARKETING_CONTROLLER_FILE")"
  controller_target="$(first_fixed_match "\$target = trim((string) \$this->input->post('cleanup_target', true));" "$MARKETING_CONTROLLER_FILE")"
  controller_confirmation="$(first_fixed_match "\$confirmation = trim((string) \$this->input->post('cleanup_confirmation', false));" "$MARKETING_CONTROLLER_FILE")"
  controller_redirect="$(first_fixed_match "redirect(admin_url('marketing/reports'));" "$MARKETING_CONTROLLER_FILE")"
  activity_helper="$(first_match 'function[[:space:]]+purge_report_activity' "$DASHBOARD_FILE")"
  campaigns_helper="$(first_match 'function[[:space:]]+purge_report_campaigns' "$DASHBOARD_FILE")"
  activity_delete="$(first_fixed_match "\$deleted = \$this->db->delete(\$this->eventsTable);" "$DASHBOARD_FILE")"
  activity_trans="$(first_fixed_match "\$this->db->trans_begin();" "$DASHBOARD_FILE")"
  campaign_blocked="$(first_fixed_match "marketing_reports_cleanup_campaigns_blocked" "$DASHBOARD_FILE")"
  reports_form="$(first_fixed_match "form_open(admin_url('marketing/reports_cleanup'))" "$REPORTS_VIEW_FILE")"
  reports_target_activity="$(first_fixed_match 'name="cleanup_target" value="activity"' "$REPORTS_VIEW_FILE")"
  reports_target_campaigns="$(first_fixed_match 'name="cleanup_target" value="campaigns"' "$REPORTS_VIEW_FILE")"
  reports_warning="$(first_fixed_match "_l('marketing_reports_cleanup_warning')" "$REPORTS_VIEW_FILE")"
  reports_export="$(first_fixed_match "_l('marketing_reports_cleanup_export_warning')" "$REPORTS_VIEW_FILE")"
  reports_typed="$(first_fixed_match 'name="cleanup_confirmation"' "$REPORTS_VIEW_FILE")"
  reports_phrase_activity="$(first_fixed_match "DELETE ACTIVITY REPORTS" "$REPORTS_VIEW_FILE")"
  reports_phrase_campaigns="$(first_fixed_match "DELETE CAMPAIGN REPORTS" "$REPORTS_VIEW_FILE")"
  reports_danger_zone="$(first_fixed_match 'data-marketing-reports-danger-zone="1"' "$REPORTS_VIEW_FILE")"
  reports_activity_card="$(first_fixed_match 'data-marketing-reports-cleanup-card="activity"' "$REPORTS_VIEW_FILE")"
  reports_campaign_card="$(first_fixed_match 'data-marketing-reports-cleanup-card="campaigns"' "$REPORTS_VIEW_FILE")"
  forbidden_truncate="$(grep -nE -- 'truncate\(|empty_table\(|TRUNCATE|EMPTY_TABLE' "$MARKETING_CONTROLLER_FILE" "$DASHBOARD_FILE" "$REPORTS_VIEW_FILE" || true)"
  protected_contacts="$(grep -nF -- 'delete($this->contactsTable' "$DASHBOARD_FILE" || true)"
  protected_lists="$(grep -nF -- 'delete($this->listsTable' "$DASHBOARD_FILE" || true)"
  protected_suppressions="$(grep -nF -- 'delete($this->suppressionsTable' "$DASHBOARD_FILE" || true)"
  protected_conversations="$(grep -nF -- 'delete($this->conversationsTable' "$DASHBOARD_FILE" || true)"
  protected_messages="$(grep -nF -- 'delete($this->messagesTable' "$DASHBOARD_FILE" || true)"
  protected_leads="$(grep -nF -- 'tblleads' "$DASHBOARD_FILE" "$MARKETING_CONTROLLER_FILE" || true)"
  protected_integrations="$(grep -nF -- 'delete($this->integrationsTable' "$DASHBOARD_FILE" || true)"
  protected_options="$(grep -nE -- 'delete_option|update_option|add_option' "$MARKETING_CONTROLLER_FILE" "$DASHBOARD_FILE" || true)"
  protected_perfex="$(grep -nE -- 'tblclients|tblcontacts|tblstaff|tblleads' "$DASHBOARD_FILE" "$MARKETING_CONTROLLER_FILE" || true)"

  if [[ -z "$controller_method" || -z "$controller_permission" || -z "$controller_post_guard" || -z "$controller_target" || -z "$controller_confirmation" || -z "$controller_redirect" || -z "$activity_helper" || -z "$campaigns_helper" || -z "$activity_delete" || -z "$activity_trans" || -z "$campaign_blocked" || -z "$reports_form" || -z "$reports_target_activity" || -z "$reports_target_campaigns" || -z "$reports_warning" || -z "$reports_export" || -z "$reports_typed" || -z "$reports_phrase_activity" || -z "$reports_phrase_campaigns" || -z "$reports_danger_zone" || -z "$reports_activity_card" || -z "$reports_campaign_card" || -n "$forbidden_truncate" || -n "$protected_contacts" || -n "$protected_lists" || -n "$protected_suppressions" || -n "$protected_conversations" || -n "$protected_messages" || -n "$protected_leads" || -n "$protected_integrations" || -n "$protected_options" || -n "$protected_perfex" ]]; then
    fail \
      "marketing reports cleanup remains explicit, POST-only, permission-guarded, and narrowly scoped" \
      "expected reports_cleanup() guardrails, activity-only event deletion with transaction, blocked campaign purge helper, danger-zone copy with export warning and typed confirmations, and no truncate/protected-table cleanup; method=${controller_method:-missing}; permission=${controller_permission:-missing}; post=${controller_post_guard:-missing}; target=${controller_target:-missing}; confirmation=${controller_confirmation:-missing}; redirect=${controller_redirect:-missing}; activity_helper=${activity_helper:-missing}; campaigns_helper=${campaigns_helper:-missing}; activity_delete=${activity_delete:-missing}; activity_trans=${activity_trans:-missing}; campaign_blocked=${campaign_blocked:-missing}; form=${reports_form:-missing}; target_activity=${reports_target_activity:-missing}; target_campaigns=${reports_target_campaigns:-missing}; warning=${reports_warning:-missing}; export=${reports_export:-missing}; typed=${reports_typed:-missing}; phrase_activity=${reports_phrase_activity:-missing}; phrase_campaigns=${reports_phrase_campaigns:-missing}; zone=${reports_danger_zone:-missing}; activity_card=${reports_activity_card:-missing}; campaign_card=${reports_campaign_card:-missing}; forbidden_truncate=${forbidden_truncate:-none}; protected_contacts=${protected_contacts:-none}; protected_lists=${protected_lists:-none}; protected_suppressions=${protected_suppressions:-none}; protected_conversations=${protected_conversations:-none}; protected_messages=${protected_messages:-none}; protected_leads=${protected_leads:-none}; protected_integrations=${protected_integrations:-none}; protected_options=${protected_options:-none}; protected_perfex=${protected_perfex:-none}"
    return
  fi

  pass \
    "marketing reports cleanup remains explicit, POST-only, permission-guarded, and narrowly scoped" \
    "controller=$controller_method | activity=$activity_helper | campaigns=$campaigns_helper | warning=$reports_warning"
}

check_whatsapp_send_auth_diagnostics() {
  local endpoint_line bearer_line message_line type_line code_line subcode_line trace_line
  endpoint_line="$(first_fixed_match "rawurlencode(\$phoneNumberId) . '/messages'" "$WHATSAPP_FILE")"
  bearer_line="$(first_fixed_match "'Authorization: Bearer ' . \$accessToken" "$WHATSAPP_FILE")"
  message_line="$(first_fixed_match "'meta_error_message' => \$this->sanitizeMessage(\$error['message'] ?? \$rawResponse)" "$WHATSAPP_FILE")"
  type_line="$(first_fixed_match "'meta_error_type' => \$this->sanitizeMetaScalar(\$error['type'] ?? '')" "$WHATSAPP_FILE")"
  code_line="$(first_fixed_match "'meta_error_code' => \$this->sanitizeMetaScalar(\$error['code'] ?? '')" "$WHATSAPP_FILE")"
  subcode_line="$(first_fixed_match "'meta_error_subcode' => \$this->sanitizeMetaScalar(\$error['error_subcode'] ?? '')" "$WHATSAPP_FILE")"
  trace_line="$(first_fixed_match "'meta_fbtrace_id' => \$this->sanitizeMetaScalar(\$error['fbtrace_id'] ?? '')" "$WHATSAPP_FILE")"

  if [[ -z "$endpoint_line" || -z "$bearer_line" || -z "$message_line" || -z "$type_line" || -z "$code_line" || -z "$subcode_line" || -z "$trace_line" ]]; then
    fail \
      "WhatsApp send path keeps phone_number_id endpoint, bearer auth, and structured Meta error parsing" \
      "expected phone_number_id /messages endpoint, Authorization bearer header, and structured meta_error_* fields in modules/marketing/libraries/Marketing_whatsapp.php; endpoint=${endpoint_line:-missing}; bearer=${bearer_line:-missing}; message=${message_line:-missing}; type=${type_line:-missing}; code=${code_line:-missing}; subcode=${subcode_line:-missing}; trace=${trace_line:-missing}"
    return
  fi

  pass \
    "WhatsApp send path keeps phone_number_id endpoint, bearer auth, and structured Meta error parsing" \
    "endpoint=$endpoint_line | bearer=$bearer_line | type=$type_line"
}

check_whatsapp_test_alert_safety() {
  local formatter_line status_line type_line code_line trace_line warning_line
  formatter_line="$(first_match 'function[[:space:]]+format_whatsapp_test_error_message' "$SETTINGS_CONTROLLER_FILE")"
  status_line="$(first_fixed_match "\$details[] = 'HTTP ' . \$statusCode;" "$SETTINGS_CONTROLLER_FILE")"
  type_line="$(first_fixed_match "\$details[] = 'type: ' . \$metaType;" "$SETTINGS_CONTROLLER_FILE")"
  code_line="$(first_fixed_match "\$details[] = 'code: ' . \$metaCode;" "$SETTINGS_CONTROLLER_FILE")"
  trace_line="$(first_fixed_match "\$details[] = 'fbtrace_id: ' . \$fbtraceId;" "$SETTINGS_CONTROLLER_FILE")"
  warning_line="$(first_fixed_match 'Send Test uses the currently saved WhatsApp settings.' "$SETTINGS_VIEW_FILE")"

  if [[ -z "$formatter_line" || -z "$status_line" || -z "$type_line" || -z "$code_line" || -z "$trace_line" || -z "$warning_line" ]]; then
    fail \
      "WhatsApp test alert exposes sanitized Meta diagnostics and warns that Send Test uses saved settings" \
      "expected formatter plus HTTP/type/code/fbtrace fields in $SETTINGS_CONTROLLER_FILE and saved-settings warning in $SETTINGS_VIEW_FILE; formatter=${formatter_line:-missing}; status=${status_line:-missing}; type=${type_line:-missing}; code=${code_line:-missing}; trace=${trace_line:-missing}; warning=${warning_line:-missing}"
    return
  fi

  pass \
    "WhatsApp test alert exposes sanitized Meta diagnostics and warns that Send Test uses saved settings" \
    "formatter=$formatter_line | status=$status_line | warning=$warning_line"
}

check_whatsapp_token_preserve_and_redaction() {
  local preserve_line save_line secret_preserve_line secret_save_line redact_line secret_field_line secret_status_line
  preserve_line="$(first_fixed_match "if (\$accessToken !== '') {" "$SETTINGS_CONTROLLER_FILE")"
  save_line="$(first_fixed_match "\$settings['marketing_whatsapp_access_token'] = \$accessToken;" "$SETTINGS_CONTROLLER_FILE")"
  secret_preserve_line="$(first_fixed_match "if (\$appSecret !== '') {" "$SETTINGS_CONTROLLER_FILE")"
  secret_save_line="$(first_fixed_match "\$settings['marketing_whatsapp_app_secret'] = \$appSecret;" "$SETTINGS_CONTROLLER_FILE")"
  redact_line="$(first_fixed_match "trim((string) get_option('marketing_whatsapp_access_token'))" "$CAMPAIGNS_FILE")"
  secret_field_line="$(first_fixed_match 'marketing_whatsapp_app_secret' "$SETTINGS_VIEW_FILE")"
  secret_status_line="$(first_fixed_match 'marketing_whatsapp_app_secret_status_configured' "$SETTINGS_VIEW_FILE")"

  if [[ -z "$preserve_line" || -z "$save_line" || -z "$secret_preserve_line" || -z "$secret_save_line" || -z "$redact_line" || -z "$secret_field_line" || -z "$secret_status_line" ]]; then
    fail \
      "WhatsApp blank-token/app-secret preservation remains intact and token redaction stays in failure sanitization" \
      "expected blank-token and blank-app-secret preserve guards in modules/marketing/controllers/Settings.php, token redaction in $CAMPAIGNS_FILE, and WhatsApp app-secret status UI in $SETTINGS_VIEW_FILE; preserve=${preserve_line:-missing}; save=${save_line:-missing}; secret_preserve=${secret_preserve_line:-missing}; secret_save=${secret_save_line:-missing}; redact=${redact_line:-missing}; secret_field=${secret_field_line:-missing}; secret_status=${secret_status_line:-missing}"
    return
  fi

  pass \
    "WhatsApp blank-token/app-secret preservation remains intact and token redaction stays in failure sanitization" \
    "preserve=$preserve_line | secret_preserve=$secret_preserve_line | secret_status=$secret_status_line"
}

check_whatsapp_active_social_scope() {
  local route_line settings_line test_line model_const_line model_filter_line helper_line
  route_line="$(first_fixed_match "\$route['marketing/webhooks/whatsapp'] = 'webhooks/whatsapp';" "$ROUTES_FILE")"
  settings_line="$(first_fixed_match 'marketing_whatsapp_enabled' "$SETTINGS_VIEW_FILE")"
  test_line="$(first_fixed_match 'marketing_whatsapp_test_heading' "$SETTINGS_VIEW_FILE")"
  model_const_line="$(first_fixed_match "const ACTIVE_SOCIAL_CHANNELS = ['whatsapp_business', 'whatsapp'];" "modules/marketing/models/Marketing_integrations_model.php")"
  model_filter_line="$(first_fixed_match "where_in('channel', self::ACTIVE_SOCIAL_CHANNELS)" "modules/marketing/models/Marketing_integrations_model.php")"
  helper_line="$(first_match 'function[[:space:]]+get_active_social_integrations' "modules/marketing/models/Marketing_integrations_model.php")"

  if [[ -z "$route_line" || -z "$settings_line" || -z "$test_line" || -z "$model_const_line" || -z "$model_filter_line" || -z "$helper_line" ]]; then
    fail \
      "WhatsApp remains the only active social integration surface and legacy channel rows are hidden from active integration queries" \
      "expected WhatsApp route/settings/test markers plus ACTIVE_SOCIAL_CHANNELS filtering in modules/marketing/models/Marketing_integrations_model.php; route=${route_line:-missing}; settings=${settings_line:-missing}; test=${test_line:-missing}; const=${model_const_line:-missing}; filter=${model_filter_line:-missing}; helper=${helper_line:-missing}"
    return
  fi

  pass \
    "WhatsApp remains the only active social integration surface and legacy channel rows are hidden from active integration queries" \
    "route=$route_line | settings=$settings_line | const=$model_const_line"
}

check_whatsapp_conversation_schema_readiness() {
  local conv_channel conv_provider conv_phone conv_norm_phone conv_thread conv_lead conv_assigned conv_unread conv_inbound conv_outbound conv_notes
  local msg_channel msg_provider msg_type msg_thread msg_status msg_media msg_context
  conv_channel="$(first_fixed_match "'channel' => ['sql' => \"VARCHAR(50) NOT NULL DEFAULT 'email'\", 'repairable' => true]" "$SCHEMA_FILE")"
  conv_provider="$(first_fixed_match "'provider' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_phone="$(first_fixed_match "'contact_phone' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_norm_phone="$(first_fixed_match "'normalized_phone' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_thread="$(first_fixed_match "'provider_thread_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_lead="$(first_fixed_match "'lead_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_assigned="$(first_fixed_match "'assigned_staff_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_unread="$(first_fixed_match "'unread_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_inbound="$(first_fixed_match "'last_inbound_at' => ['sql' => 'DATETIME NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_outbound="$(first_fixed_match "'last_outbound_at' => ['sql' => 'DATETIME NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  conv_notes="$(first_fixed_match "'internal_notes' => ['sql' => 'TEXT NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_channel="$(first_fixed_match "'channel' => ['sql' => \"VARCHAR(50) NOT NULL DEFAULT 'email'\", 'repairable' => true]" "$SCHEMA_FILE")"
  msg_provider="$(first_fixed_match "'provider' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_type="$(first_fixed_match "'message_type' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_thread="$(first_fixed_match "'provider_thread_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_status="$(first_fixed_match "'provider_status' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_media="$(first_fixed_match "'media_meta_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_context="$(first_fixed_match "'context_provider_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true]" "$SCHEMA_FILE")"

  if [[ -z "$conv_channel" || -z "$conv_provider" || -z "$conv_phone" || -z "$conv_norm_phone" || -z "$conv_thread" || -z "$conv_lead" || -z "$conv_assigned" || -z "$conv_unread" || -z "$conv_inbound" || -z "$conv_outbound" || -z "$conv_notes" || -z "$msg_channel" || -z "$msg_provider" || -z "$msg_type" || -z "$msg_thread" || -z "$msg_status" || -z "$msg_media" || -z "$msg_context" ]]; then
    fail \
      "WhatsApp-ready conversation and message schema fields are repairable and bootstrap-managed" \
      "expected new conversation/message fields in $SCHEMA_FILE; conv_channel=${conv_channel:-missing}; conv_provider=${conv_provider:-missing}; conv_phone=${conv_phone:-missing}; conv_thread=${conv_thread:-missing}; conv_unread=${conv_unread:-missing}; msg_channel=${msg_channel:-missing}; msg_type=${msg_type:-missing}; msg_media=${msg_media:-missing}; msg_context=${msg_context:-missing}"
    return
  fi

  pass \
    "WhatsApp-ready conversation and message schema fields are repairable and bootstrap-managed" \
    "conv_channel=$conv_channel | conv_unread=$conv_unread | msg_channel=$msg_channel | msg_type=$msg_type | msg_media=$msg_media"
}

check_conversation_model_optional_column_guards() {
  local conv_filter_line msg_filter_line phone_helper_line unread_guard_line media_helper_line thread_filter_line
  conv_filter_line="$(first_fixed_match '$insert = $this->filter_existing_columns($this->conversationsTable, $insert);' "modules/marketing/models/Marketing_conversations_model.php")"
  msg_filter_line="$(first_fixed_match '$insert = $this->filter_existing_columns($this->messagesTable, $insert);' "modules/marketing/models/Marketing_conversations_model.php")"
  phone_helper_line="$(first_match 'function[[:space:]]+normalize_phone' "modules/marketing/models/Marketing_conversations_model.php")"
  unread_guard_line="$(first_fixed_match "&& \$this->db->field_exists('unread_count', \$this->conversationsTable)" "modules/marketing/models/Marketing_conversations_model.php")"
  media_helper_line="$(first_match 'function[[:space:]]+encode_media_meta_json' "modules/marketing/models/Marketing_conversations_model.php")"
  thread_filter_line="$(first_fixed_match "if (\$providerThreadId !== '' && \$this->db->field_exists('provider_thread_id', \$this->conversationsTable)) {" "modules/marketing/models/Marketing_conversations_model.php")"

  if [[ -z "$conv_filter_line" || -z "$msg_filter_line" || -z "$phone_helper_line" || -z "$unread_guard_line" || -z "$media_helper_line" || -z "$thread_filter_line" ]]; then
    fail \
      "conversation model guards optional columns and metadata writes before persisting WhatsApp-ready fields" \
      "expected filter_existing_columns(), normalized phone helper, unread_count guard, media-meta encoder, and provider_thread guard in modules/marketing/models/Marketing_conversations_model.php; conv_filter=${conv_filter_line:-missing}; msg_filter=${msg_filter_line:-missing}; phone=${phone_helper_line:-missing}; unread=${unread_guard_line:-missing}; media=${media_helper_line:-missing}; thread=${thread_filter_line:-missing}"
    return
  fi

  pass \
    "conversation model guards optional columns and metadata writes before persisting WhatsApp-ready fields" \
    "conv_filter=$conv_filter_line | msg_filter=$msg_filter_line | unread=$unread_guard_line"
}

check_whatsapp_inbound_conversation_metadata() {
  local lock_line channel_line provider_line phone_line type_line context_line media_line event_payload_line signature_helper signature_header hmac_line equals_line compat_line message_idempotent_line message_dedupe_line status_idempotent_line status_lock_line
  lock_line="$(first_fixed_match '$eventLock = $this->marketing_events_model->acquire_event_lock($dedupeCriteria);' "$WEBHOOKS_FILE")"
  channel_line="$(first_fixed_match "'channel' => 'whatsapp'" "$WEBHOOKS_FILE")"
  provider_line="$(first_fixed_match "'provider' => 'whatsapp_cloud'" "$WEBHOOKS_FILE")"
  phone_line="$(first_fixed_match "'normalized_phone' => \$phone" "$WEBHOOKS_FILE")"
  type_line="$(first_fixed_match "'message_type' => \$messageType !== '' ? \$messageType : 'text'" "$WEBHOOKS_FILE")"
  context_line="$(first_fixed_match "'context_provider_message_id' => \$contextProviderMessageId !== '' ? \$contextProviderMessageId : null" "$WEBHOOKS_FILE")"
  media_line="$(first_fixed_match "'media_meta_json' => \$mediaMeta" "$WEBHOOKS_FILE")"
  event_payload_line="$(first_fixed_match "'provider_thread_id' => \$providerThreadId !== '' ? \$providerThreadId : null" "$WEBHOOKS_FILE")"
  signature_helper="$(first_match 'function[[:space:]]+verify_post_signature' "$WHATSAPP_FILE")"
  signature_header="$(first_fixed_match "x-hub-signature-256" "$WHATSAPP_FILE")"
  hmac_line="$(first_fixed_match "hash_hmac('sha256', (string) \$rawBody, \$appSecret)" "$WHATSAPP_FILE")"
  equals_line="$(first_fixed_match "hash_equals(\$expectedSignature, strtolower(\$presentedSignature))" "$WHATSAPP_FILE")"
  compat_line="$(first_fixed_match "'warning' => 'whatsapp_post_signature_not_configured'" "$WHATSAPP_FILE")"
  message_idempotent_line="$(first_fixed_match 'add_message_idempotent([' "$WEBHOOKS_FILE")"
  message_dedupe_line="$(first_fixed_match "'dedupe_key' => \$dedupeKey" "$WEBHOOKS_FILE")"
  status_idempotent_line="$(first_fixed_match 'add_event_idempotent([' "$WEBHOOKS_FILE")"
  status_lock_line="$(first_fixed_match "return 'whatsapp_status:' . \$eventType . ':' . \$providerMessageId;" "$WEBHOOKS_FILE")"

  if [[ -z "$lock_line" || -z "$channel_line" || -z "$provider_line" || -z "$phone_line" || -z "$type_line" || -z "$context_line" || -z "$media_line" || -z "$event_payload_line" || -z "$signature_helper" || -z "$signature_header" || -z "$hmac_line" || -z "$equals_line" || -z "$compat_line" || -z "$message_idempotent_line" || -z "$message_dedupe_line" || -z "$status_idempotent_line" || -z "$status_lock_line" ]]; then
    fail \
      "WhatsApp inbound path verifies POST signatures and uses idempotent message/event persistence" \
      "expected POST signature helper/header/hmac/hash_equals compatibility marker in $WHATSAPP_FILE plus lock-backed WhatsApp channel/provider/phone metadata and idempotent message/event storage in $WEBHOOKS_FILE; helper=${signature_helper:-missing}; header=${signature_header:-missing}; hmac=${hmac_line:-missing}; equals=${equals_line:-missing}; compat=${compat_line:-missing}; lock=${lock_line:-missing}; channel=${channel_line:-missing}; provider=${provider_line:-missing}; phone=${phone_line:-missing}; type=${type_line:-missing}; context=${context_line:-missing}; media=${media_line:-missing}; thread=${event_payload_line:-missing}; message_idempotent=${message_idempotent_line:-missing}; message_dedupe=${message_dedupe_line:-missing}; status_idempotent=${status_idempotent_line:-missing}; status_lock=${status_lock_line:-missing}"
    return
  fi

  pass \
    "WhatsApp inbound path verifies POST signatures and uses idempotent message/event persistence" \
    "helper=$signature_helper | message=$message_idempotent_line | status=$status_idempotent_line"
}

check_whatsapp_lead_conversion_deferred() {
  local whatsapp_block route_line csrf_line readiness_helper phone_helper
  whatsapp_block="$(sed -n '/protected function storeWhatsappInboundMessage/,/protected function storeWhatsappStatusEvent/p' "$WEBHOOKS_FILE")"
  route_line="$(first_fixed_match "\$route['marketing/webhooks/whatsapp'] = 'webhooks/whatsapp';" "$ROUTES_FILE")"
  csrf_line="$(first_fixed_match "'marketing/webhooks/whatsapp'" "$CSRF_FILE")"
  readiness_helper="$(first_match 'function[[:space:]]+get_whatsapp_lead_conversion_readiness' "modules/marketing/models/Marketing_reply_leads_model.php")"
  phone_helper="$(first_match 'function[[:space:]]+find_lead_by_phone' "modules/marketing/models/Marketing_reply_leads_model.php")"

  if printf '%s\n' "$whatsapp_block" | grep -q 'convert_reply_to_lead'; then
    fail \
      "automatic WhatsApp lead conversion remains deferred in Phase 4A patch 1" \
      "unexpected convert_reply_to_lead call found inside storeWhatsappInboundMessage() in $WEBHOOKS_FILE"
    return
  fi

  if [[ -z "$route_line" || -z "$csrf_line" || -z "$readiness_helper" || -z "$phone_helper" ]]; then
    fail \
      "WhatsApp webhook route stays unchanged while lead conversion remains deferred behind a readiness helper" \
      "expected whatsapp route/csrf plus readiness helper in modules/marketing/models/Marketing_reply_leads_model.php; route=${route_line:-missing}; csrf=${csrf_line:-missing}; readiness=${readiness_helper:-missing}; phone=${phone_helper:-missing}"
    return
  fi

  pass \
    "automatic WhatsApp lead conversion remains deferred in Phase 4A patch 1" \
    "route=$route_line | csrf=$csrf_line | readiness=$readiness_helper"
}

check_whatsapp_dashboard_visibility() {
  local helper_line summary_line preview_helper controller_load controller_call default_data view_title view_note view_escape view_lead_state destructive_sql
  helper_line="$(first_match 'function[[:space:]]+get_recent_whatsapp_conversations' "$CONVERSATIONS_FILE")"
  summary_line="$(first_fixed_match "'unread_message_count' => 0" "$CONVERSATIONS_FILE")"
  preview_helper="$(first_match 'function[[:space:]]+get_latest_inbound_message_preview' "$CONVERSATIONS_FILE")"
  controller_load="$(first_fixed_match "Marketing_conversations_model" "$MARKETING_CONTROLLER_FILE")"
  controller_call="$(first_fixed_match "get_recent_whatsapp_conversations(5)" "$MARKETING_CONTROLLER_FILE")"
  default_data="$(first_fixed_match "'whatsapp_conversations' => [" "$MARKETING_CONTROLLER_FILE")"
  view_title="$(first_fixed_match "_l('marketing_dashboard_whatsapp_conversations')" "$DASHBOARD_VIEW_FILE")"
  view_note="$(first_fixed_match "_l('marketing_dashboard_whatsapp_conversations_note')" "$DASHBOARD_VIEW_FILE")"
  view_escape="$(first_fixed_match "html_escape(\$preview)" "$DASHBOARD_VIEW_FILE")"
  view_lead_state="$(first_fixed_match "_l('marketing_dashboard_whatsapp_convert_unavailable')" "$DASHBOARD_VIEW_FILE")"
  destructive_sql="$(grep -nE -- '\\b(DELETE|TRUNCATE|DROP|ALTER|CREATE)\\b' "$CONVERSATIONS_FILE" "$MARKETING_CONTROLLER_FILE" "$DASHBOARD_VIEW_FILE" || true)"

  if [[ -z "$helper_line" || -z "$summary_line" || -z "$preview_helper" || -z "$controller_load" || -z "$controller_call" || -z "$default_data" || -z "$view_title" || -z "$view_note" || -z "$view_escape" || -z "$view_lead_state" || -n "$destructive_sql" ]]; then
    fail \
      "WhatsApp dashboard visibility remains bounded, read-only, and escaped" \
      "expected recent WhatsApp helper/preview lookup in $CONVERSATIONS_FILE, dashboard wiring in $MARKETING_CONTROLLER_FILE, escaped WhatsApp panel output in $DASHBOARD_VIEW_FILE, and no destructive SQL; helper=${helper_line:-missing}; summary=${summary_line:-missing}; preview=${preview_helper:-missing}; load=${controller_load:-missing}; call=${controller_call:-missing}; default=${default_data:-missing}; title=${view_title:-missing}; note=${view_note:-missing}; escape=${view_escape:-missing}; lead=${view_lead_state:-missing}; destructive=${destructive_sql:-none}"
    return
  fi

  pass \
    "WhatsApp dashboard visibility remains bounded, read-only, and escaped" \
    "helper=$helper_line | controller=$controller_call | title=$view_title"
}

check_whatsapp_conversation_detail_view() {
  local controller_method controller_view controller_model controller_messages model_conversation model_messages model_scope_channel model_scope_provider dashboard_link view_title view_summary view_timeline view_escape view_mark_read view_convert view_return_to view_no_payload_json view_no_media_json view_details model_limit no_auto_mark_read forbidden_webhook git_diff_mode
  controller_method="$(first_match 'function[[:space:]]+whatsapp_conversation' "$MARKETING_CONTROLLER_FILE")"
  controller_view="$(first_fixed_match "\$this->load->view('marketing/whatsapp_conversation', \$data);" "$MARKETING_CONTROLLER_FILE")"
  controller_model="$(first_fixed_match "get_whatsapp_conversation(\$conversationId)" "$MARKETING_CONTROLLER_FILE")"
  controller_messages="$(first_fixed_match "get_whatsapp_conversation_messages(\$conversationId, 100)" "$MARKETING_CONTROLLER_FILE")"
  model_conversation="$(first_match 'function[[:space:]]+get_whatsapp_conversation' "$CONVERSATIONS_FILE")"
  model_messages="$(first_match 'function[[:space:]]+get_whatsapp_conversation_messages' "$CONVERSATIONS_FILE")"
  model_scope_channel="$(first_fixed_match "\$this->db->where('c.channel', 'whatsapp');" "$CONVERSATIONS_FILE")"
  model_scope_provider="$(first_fixed_match "\$this->db->where('c.provider', 'whatsapp_cloud');" "$CONVERSATIONS_FILE")"
  dashboard_link="$(first_fixed_match "admin_url('marketing/whatsapp_conversation/' . (int) (\$conversation['id'] ?? 0))" "$DASHBOARD_VIEW_FILE")"
  view_title="$(first_fixed_match "_l('marketing_whatsapp_conversation_title')" "modules/marketing/views/whatsapp_conversation.php")"
  view_summary="$(first_fixed_match "_l('marketing_whatsapp_conversation_summary')" "modules/marketing/views/whatsapp_conversation.php")"
  view_timeline="$(first_fixed_match "_l('marketing_whatsapp_conversation_timeline')" "modules/marketing/views/whatsapp_conversation.php")"
  view_escape="$(first_fixed_match 'html_escape($bodyText)' "modules/marketing/views/whatsapp_conversation.php")"
  view_mark_read="$(first_fixed_match "form_open(admin_url('marketing/mark_whatsapp_conversation_read')" "modules/marketing/views/whatsapp_conversation.php")"
  view_convert="$(first_fixed_match "form_open(admin_url('marketing/convert_whatsapp_conversation')" "modules/marketing/views/whatsapp_conversation.php")"
  view_return_to="$(first_fixed_match 'name="return_to" value="<?= html_escape($returnTo); ?>"' "modules/marketing/views/whatsapp_conversation.php")"
  view_no_payload_json="$(grep -nF 'payload_json' "modules/marketing/views/whatsapp_conversation.php" || true)"
  view_no_media_json="$(grep -nF 'media_meta_json' "modules/marketing/views/whatsapp_conversation.php" || true)"
  view_details="$(first_fixed_match '<details class="marketing-whatsapp-details">' "modules/marketing/views/whatsapp_conversation.php")"
  model_limit="$(first_fixed_match 'min(200, (int) $limit)' "$CONVERSATIONS_FILE")"
  no_auto_mark_read="$(grep -nF 'mark_whatsapp_conversation_read(' "modules/marketing/views/whatsapp_conversation.php" || true)"
  forbidden_webhook="$(git_changed_path_if_available modules/marketing/controllers/Webhooks.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$controller_method" || -z "$controller_view" || -z "$controller_model" || -z "$controller_messages" || -z "$model_conversation" || -z "$model_messages" || -z "$model_scope_channel" || -z "$model_scope_provider" || -z "$dashboard_link" || -z "$view_title" || -z "$view_summary" || -z "$view_timeline" || -z "$view_escape" || -z "$view_mark_read" || -z "$view_convert" || -z "$view_return_to" || -n "$view_no_payload_json" || -n "$view_no_media_json" || -z "$view_details" || -z "$model_limit" || -n "$no_auto_mark_read" || -n "$forbidden_webhook" ]]; then
    fail \
      "WhatsApp conversation detail view remains bounded, escaped, and read-only on GET" \
      "expected controller/model/view wiring, WhatsApp-only scoping, dashboard link, escaped timeline rendering, POST-form reuse for mark-read and convert, return_to preservation, no raw payload/media JSON output, bounded message loading, no automatic GET mutation, and no webhook file changes; controller=${controller_method:-missing}; view=${controller_view:-missing}; model=${controller_model:-missing}; messages=${controller_messages:-missing}; helper=${model_conversation:-missing}; helper_messages=${model_messages:-missing}; channel=${model_scope_channel:-missing}; provider=${model_scope_provider:-missing}; dashboard_link=${dashboard_link:-missing}; title=${view_title:-missing}; summary=${view_summary:-missing}; timeline=${view_timeline:-missing}; escape=${view_escape:-missing}; mark_read=${view_mark_read:-missing}; convert=${view_convert:-missing}; return_to=${view_return_to:-missing}; payload_json=${view_no_payload_json:-none}; media_json=${view_no_media_json:-none}; details=${view_details:-missing}; limit=${model_limit:-missing}; auto_mutation=${no_auto_mark_read:-none}; git_diff_mode=${git_diff_mode:-unknown}; webhook_diff=${forbidden_webhook:-none}"
    return
  fi

  pass \
    "WhatsApp conversation detail view remains bounded, escaped, and read-only on GET" \
    "controller=$controller_method | helper=$model_conversation | view=$view_title | return_to=$view_return_to | git_diff_mode=$git_diff_mode"
}

check_whatsapp_manual_reply_workflow() {
  local controller_method controller_permission controller_post_guard controller_conversation controller_body controller_length controller_return_to controller_library model_helper model_channel model_provider model_direction model_type library_method library_endpoint library_bearer view_form view_conversation_id view_return_to view_textarea view_maxlength view_escape view_no_payload_json view_no_media_json view_no_access_token view_no_app_secret destructive_sql forbidden_webhook git_diff_mode
  controller_method="$(first_match 'function[[:space:]]+send_whatsapp_conversation_reply' "$MARKETING_CONTROLLER_FILE")"
  controller_permission="$(first_fixed_match "staff_cant('settings', 'marketing')" "$MARKETING_CONTROLLER_FILE")"
  controller_post_guard="$(first_fixed_match "if (!\$this->input->post()) {" "$MARKETING_CONTROLLER_FILE")"
  controller_conversation="$(first_fixed_match "\$conversationId = (int) \$this->input->post('conversation_id', true);" "$MARKETING_CONTROLLER_FILE")"
  controller_body="$(first_fixed_match "\$replyBody = trim((string) \$this->input->post('reply_body', false));" "$MARKETING_CONTROLLER_FILE")"
  controller_length="$(first_fixed_match "mb_strlen(\$replyBody) > \$maxLength" "$MARKETING_CONTROLLER_FILE")"
  controller_return_to="$(first_fixed_match "get_safe_marketing_redirect('marketing/whatsapp_conversation/' . \$conversationId)" "$MARKETING_CONTROLLER_FILE")"
  controller_library="$(first_fixed_match "\$sendResult = \$this->marketing_whatsapp->send_text_message(\$recipientPhone, \$replyBody);" "$MARKETING_CONTROLLER_FILE")"
  model_helper="$(first_match 'function[[:space:]]+record_whatsapp_outbound_reply' "$CONVERSATIONS_FILE")"
  model_channel="$(first_fixed_match "'channel' => 'whatsapp'" "$CONVERSATIONS_FILE")"
  model_provider="$(first_fixed_match "'provider' => 'whatsapp_cloud'" "$CONVERSATIONS_FILE")"
  model_direction="$(first_fixed_match "'direction' => 'outbound'" "$CONVERSATIONS_FILE")"
  model_type="$(first_fixed_match "'message_type' => 'text'" "$CONVERSATIONS_FILE")"
  library_method="$(first_match 'function[[:space:]]+send_text_message' "$WHATSAPP_FILE")"
  library_endpoint="$(first_fixed_match "/messages';" "$WHATSAPP_FILE")"
  library_bearer="$(first_fixed_match "'Authorization: Bearer ' . \$accessToken" "$WHATSAPP_FILE")"
  view_form="$(first_fixed_match "form_open(admin_url('marketing/send_whatsapp_conversation_reply'))" "modules/marketing/views/whatsapp_conversation.php")"
  view_conversation_id="$(first_fixed_match "name=\"conversation_id\" value=\"<?= (int) (\$conversation['" "modules/marketing/views/whatsapp_conversation.php")"
  view_return_to="$(first_fixed_match 'name="return_to" value="<?= html_escape($returnTo); ?>"' "modules/marketing/views/whatsapp_conversation.php")"
  view_textarea="$(first_fixed_match 'name="reply_body"' "modules/marketing/views/whatsapp_conversation.php")"
  view_maxlength="$(first_fixed_match 'maxlength="<?= (int) $replyMaxLength; ?>"' "modules/marketing/views/whatsapp_conversation.php")"
  view_escape="$(first_fixed_match "<?= sprintf(html_escape(_l('marketing_whatsapp_reply_body_helper')), (int) \$replyMaxLength); ?>" "modules/marketing/views/whatsapp_conversation.php")"
  view_no_payload_json="$(grep -nF 'payload_json' "modules/marketing/views/whatsapp_conversation.php" || true)"
  view_no_media_json="$(grep -nF 'media_meta_json' "modules/marketing/views/whatsapp_conversation.php" || true)"
  view_no_access_token="$(grep -nF 'access_token' "modules/marketing/views/whatsapp_conversation.php" || true)"
  view_no_app_secret="$(grep -nF 'app_secret' "modules/marketing/views/whatsapp_conversation.php" || true)"
  destructive_sql="$(grep -nE -- '\\b(TRUNCATE|DROP|DELETE FROM|empty_table)\\b' "$MARKETING_CONTROLLER_FILE" "$CONVERSATIONS_FILE" "$WHATSAPP_FILE" "modules/marketing/views/whatsapp_conversation.php" || true)"
  forbidden_webhook="$(git_changed_path_if_available modules/marketing/controllers/Webhooks.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$controller_method" || -z "$controller_permission" || -z "$controller_post_guard" || -z "$controller_conversation" || -z "$controller_body" || -z "$controller_length" || -z "$controller_return_to" || -z "$controller_library" || -z "$model_helper" || -z "$model_channel" || -z "$model_provider" || -z "$model_direction" || -z "$model_type" || -z "$library_method" || -z "$library_endpoint" || -z "$library_bearer" || -z "$view_form" || -z "$view_conversation_id" || -z "$view_return_to" || -z "$view_textarea" || -z "$view_maxlength" || -z "$view_escape" || -n "$view_no_payload_json" || -n "$view_no_media_json" || -n "$view_no_access_token" || -n "$view_no_app_secret" || -n "$destructive_sql" || -n "$forbidden_webhook" ]]; then
    fail \
      "manual WhatsApp reply stays POST-only, bounded, persisted, and isolated from webhook or queue behavior" \
      "expected POST-only controller action, permission guard, body validation, safe return_to redirect, outbound WhatsApp persistence in $CONVERSATIONS_FILE, sanitized library send helper in $WHATSAPP_FILE, bounded reply form in modules/marketing/views/whatsapp_conversation.php, no raw payload/media JSON or secrets rendered, no destructive cleanup, and no webhook file changes; method=${controller_method:-missing}; permission=${controller_permission:-missing}; post=${controller_post_guard:-missing}; conversation=${controller_conversation:-missing}; body=${controller_body:-missing}; length=${controller_length:-missing}; return_to=${controller_return_to:-missing}; library_call=${controller_library:-missing}; model=${model_helper:-missing}; channel=${model_channel:-missing}; provider=${model_provider:-missing}; direction=${model_direction:-missing}; type=${model_type:-missing}; library=${library_method:-missing}; endpoint=${library_endpoint:-missing}; bearer=${library_bearer:-missing}; form=${view_form:-missing}; conversation_id=${view_conversation_id:-missing}; return_field=${view_return_to:-missing}; textarea=${view_textarea:-missing}; maxlength=${view_maxlength:-missing}; escape=${view_escape:-missing}; payload_json=${view_no_payload_json:-none}; media_json=${view_no_media_json:-none}; access_token=${view_no_access_token:-none}; app_secret=${view_no_app_secret:-none}; destructive=${destructive_sql:-none}; git_diff_mode=${git_diff_mode:-unknown}; webhook_diff=${forbidden_webhook:-none}"
    return
  fi

  pass \
    "manual WhatsApp reply stays POST-only, bounded, persisted, and isolated from webhook or queue behavior" \
    "controller=$controller_method | model=$model_helper | library=$library_method | form=$view_form | git_diff_mode=$git_diff_mode"
}

check_whatsapp_manual_lead_conversion() {
  local controller_method controller_permission controller_post_guard controller_call model_convert model_readiness model_existing_link model_create_link model_context model_phone_match dashboard_form dashboard_escape dashboard_deferred destructive_sql
  controller_method="$(first_match 'function[[:space:]]+convert_whatsapp_conversation' "$MARKETING_CONTROLLER_FILE")"
  controller_permission="$(first_fixed_match "staff_cant('settings', 'marketing')" "$MARKETING_CONTROLLER_FILE")"
  controller_post_guard="$(first_fixed_match "if (!\$this->input->post()) {" "$MARKETING_CONTROLLER_FILE")"
  controller_call="$(first_fixed_match "convert_whatsapp_conversation_to_lead(\$conversationId, get_staff_user_id())" "$MARKETING_CONTROLLER_FILE")"
  model_convert="$(first_match 'function[[:space:]]+convert_whatsapp_conversation_to_lead' "modules/marketing/models/Marketing_reply_leads_model.php")"
  model_readiness="$(first_fixed_match "get_whatsapp_lead_conversion_readiness([" "modules/marketing/models/Marketing_reply_leads_model.php")"
  model_existing_link="$(first_fixed_match "link_whatsapp_conversation_to_lead(\$conversationId, (int) \$readiness['lead_id'])" "modules/marketing/models/Marketing_reply_leads_model.php")"
  model_create_link="$(first_fixed_match "link_whatsapp_conversation_to_lead(\$conversationId, \$leadId)" "modules/marketing/models/Marketing_reply_leads_model.php")"
  model_context="$(first_fixed_match "get_whatsapp_conversation_context(\$conversationId)" "modules/marketing/models/Marketing_reply_leads_model.php")"
  model_phone_match="$(first_fixed_match "find_safe_whatsapp_existing_lead(\$context)" "modules/marketing/models/Marketing_reply_leads_model.php")"
  dashboard_form="$(first_fixed_match "form_open(admin_url('marketing/convert_whatsapp_conversation')" "$DASHBOARD_VIEW_FILE")"
  dashboard_escape="$(first_fixed_match "html_escape(\$conversionLabel)" "$DASHBOARD_VIEW_FILE")"
  dashboard_deferred="$(first_fixed_match '$conversionLabel = trim((string) ($leadConversion[' "$DASHBOARD_VIEW_FILE")"
  destructive_sql="$(grep -nE -- '\\b(DELETE|TRUNCATE|DROP|ALTER|CREATE)\\b' "$MARKETING_CONTROLLER_FILE" "modules/marketing/models/Marketing_reply_leads_model.php" "$DASHBOARD_VIEW_FILE" || true)"

  if [[ -z "$controller_method" || -z "$controller_permission" || -z "$controller_post_guard" || -z "$controller_call" || -z "$model_convert" || -z "$model_readiness" || -z "$model_existing_link" || -z "$model_create_link" || -z "$model_context" || -z "$model_phone_match" || -z "$dashboard_form" || -z "$dashboard_escape" || -z "$dashboard_deferred" || -n "$destructive_sql" ]]; then
    fail \
      "manual WhatsApp lead conversion remains POST-only, duplicate-safe, and dashboard-bounded" \
      "expected POST-only controller action in $MARKETING_CONTROLLER_FILE, explicit conversion helper in modules/marketing/models/Marketing_reply_leads_model.php, bounded dashboard form in $DASHBOARD_VIEW_FILE, and no destructive SQL; method=${controller_method:-missing}; permission=${controller_permission:-missing}; post=${controller_post_guard:-missing}; call=${controller_call:-missing}; model=${model_convert:-missing}; readiness=${model_readiness:-missing}; existing=${model_existing_link:-missing}; created=${model_create_link:-missing}; context=${model_context:-missing}; phone=${model_phone_match:-missing}; form=${dashboard_form:-missing}; escape=${dashboard_escape:-missing}; action_label=${dashboard_deferred:-missing}; destructive=${destructive_sql:-none}"
    return
  fi

  pass \
    "manual WhatsApp lead conversion remains POST-only, duplicate-safe, and dashboard-bounded" \
    "controller=$controller_method | model=$model_convert | form=$dashboard_form"
}

check_whatsapp_mark_read_control() {
  local controller_method controller_post_guard controller_call model_helper model_scope_channel model_scope_provider model_unread_only dashboard_form dashboard_status destructive_sql
  controller_method="$(first_match 'function[[:space:]]+mark_whatsapp_conversation_read' "$MARKETING_CONTROLLER_FILE")"
  controller_post_guard="$(first_fixed_match "if (!\$this->input->post()) {" "$MARKETING_CONTROLLER_FILE")"
  controller_call="$(first_fixed_match "mark_whatsapp_conversation_read(\$conversationId)" "$MARKETING_CONTROLLER_FILE")"
  model_helper="$(first_match 'function[[:space:]]+mark_whatsapp_conversation_read' "$CONVERSATIONS_FILE")"
  model_scope_channel="$(first_fixed_match "\$this->db->where('channel', 'whatsapp');" "$CONVERSATIONS_FILE")"
  model_scope_provider="$(first_fixed_match "\$this->db->where('provider', 'whatsapp_cloud');" "$CONVERSATIONS_FILE")"
  model_unread_only="$(first_fixed_match "\$update = ['unread_count' => 0];" "$CONVERSATIONS_FILE")"
  dashboard_form="$(first_fixed_match "form_open(admin_url('marketing/mark_whatsapp_conversation_read')" "$DASHBOARD_VIEW_FILE")"
  dashboard_status="$(first_fixed_match "_l('marketing_dashboard_whatsapp_mark_read_none')" "$DASHBOARD_VIEW_FILE")"
  destructive_sql="$(grep -nE -- '\\b(DELETE|TRUNCATE|DROP|ALTER|CREATE)\\b' "$MARKETING_CONTROLLER_FILE" "$CONVERSATIONS_FILE" "$DASHBOARD_VIEW_FILE" || true)"

  if [[ -z "$controller_method" || -z "$controller_post_guard" || -z "$controller_call" || -z "$model_helper" || -z "$model_scope_channel" || -z "$model_scope_provider" || -z "$model_unread_only" || -z "$dashboard_form" || -z "$dashboard_status" || -n "$destructive_sql" ]]; then
    fail \
      "manual WhatsApp mark-read remains POST-only, scoped, and unread-only" \
      "expected mark-read controller/model/dashboard hooks with WhatsApp channel/provider scoping, unread_count-only mutation, and no destructive SQL; method=${controller_method:-missing}; post=${controller_post_guard:-missing}; call=${controller_call:-missing}; helper=${model_helper:-missing}; channel=${model_scope_channel:-missing}; provider=${model_scope_provider:-missing}; unread=${model_unread_only:-missing}; form=${dashboard_form:-missing}; status=${dashboard_status:-missing}; destructive=${destructive_sql:-none}"
    return
  fi

  pass \
    "manual WhatsApp mark-read remains POST-only, scoped, and unread-only" \
    "controller=$controller_method | helper=$model_helper | form=$dashboard_form"
}

check_no_facebook_linkedin_active_ui_or_release_docs() {
  local settings_fb settings_li dashboard_fb dashboard_li readme_facebook readme_linkedin install_facebook install_linkedin ops_facebook ops_linkedin readme_deferred install_deferred ops_deferred
  settings_fb="$(first_match 'Facebook|facebook' "$SETTINGS_VIEW_FILE")"
  settings_li="$(first_match 'LinkedIn|linkedin' "$SETTINGS_VIEW_FILE")"
  dashboard_fb="$(first_match 'Facebook|facebook' "$DASHBOARD_VIEW_FILE")"
  dashboard_li="$(first_match 'LinkedIn|linkedin' "$DASHBOARD_VIEW_FILE")"
  readme_facebook="$(first_match 'Facebook|facebook' "$README_FILE")"
  readme_linkedin="$(first_match 'LinkedIn|linkedin' "$README_FILE")"
  install_facebook="$(first_match 'Facebook|facebook' "$INSTALLATION_FILE")"
  install_linkedin="$(first_match 'LinkedIn|linkedin' "$INSTALLATION_FILE")"
  ops_facebook="$(first_match 'Facebook|facebook' "$OPERATIONS_FILE")"
  ops_linkedin="$(first_match 'LinkedIn|linkedin' "$OPERATIONS_FILE")"
  readme_deferred="$(first_match 'deferred|not active' "$README_FILE")"
  install_deferred="$(first_match 'deferred|not active' "$INSTALLATION_FILE")"
  ops_deferred="$(first_match 'deferred|Meta approval|not active' "$OPERATIONS_FILE")"

  if [[ -n "$settings_fb" || -n "$settings_li" || -n "$dashboard_fb" || -n "$dashboard_li" || ( -n "$readme_facebook" && -z "$readme_deferred" ) || ( -n "$readme_linkedin" && -z "$readme_deferred" ) || ( -n "$install_facebook" && -z "$install_deferred" ) || ( -n "$install_linkedin" && -z "$install_deferred" ) || ( -n "$ops_facebook" && -z "$ops_deferred" ) || ( -n "$ops_linkedin" && -z "$ops_deferred" ) ]]; then
    fail \
      "Facebook and LinkedIn stay out of active UI and only appear in docs as deferred or inactive channels" \
      "expected no Facebook/LinkedIn references in settings/dashboard, and any release-doc references must explicitly frame those channels as deferred/inactive; settings_fb=${settings_fb:-none}; settings_li=${settings_li:-none}; dashboard_fb=${dashboard_fb:-none}; dashboard_li=${dashboard_li:-none}; readme_fb=${readme_facebook:-none}; readme_li=${readme_linkedin:-none}; readme_deferred=${readme_deferred:-missing}; install_fb=${install_facebook:-none}; install_li=${install_linkedin:-none}; install_deferred=${install_deferred:-missing}; ops_fb=${ops_facebook:-none}; ops_li=${ops_linkedin:-none}; ops_deferred=${ops_deferred:-missing}"
    return
  fi

  pass \
    "Facebook and LinkedIn stay out of active UI and only appear in docs as deferred or inactive channels" \
    "settings/dashboard remain WhatsApp-only while release docs can describe deferred channels safely"
}

check_release_validator_exists() {
  local shebang_line source_only_line zip_root_line
  if [[ ! -x "$RELEASE_VALIDATOR_FILE" ]]; then
    fail \
      "release package validation script exists and is executable" \
      "expected executable validator at $RELEASE_VALIDATOR_FILE"
    return
  fi

  shebang_line="$(first_fixed_match '#!/usr/bin/env bash' "$RELEASE_VALIDATOR_FILE")"
  source_only_line="$(first_fixed_match '--source-only' "$RELEASE_VALIDATOR_FILE")"
  zip_root_line="$(first_fixed_match 'all archive entries must live under marketing/' "$RELEASE_VALIDATOR_FILE")"

  if [[ -z "$shebang_line" || -z "$source_only_line" || -z "$zip_root_line" ]]; then
    fail \
      "release package validation script exists and is executable" \
      "expected bash shebang, source-only mode, and zip-root validation in $RELEASE_VALIDATOR_FILE; shebang=${shebang_line:-missing}; source_only=${source_only_line:-missing}; zip_root=${zip_root_line:-missing}"
    return
  fi

  pass \
    "release package validation script exists and is executable" \
    "shebang=$shebang_line | source_only=$source_only_line | zip_root=$zip_root_line"
}

check_module_metadata_header() {
  local module_line version_line requires_line constant_line
  module_line="$(first_fixed_match 'Module Name: Marketing' "$BOOTSTRAP_FILE")"
  version_line="$(first_match '^Version:' "$BOOTSTRAP_FILE")"
  requires_line="$(first_match '^Requires at least:' "$BOOTSTRAP_FILE")"
  constant_line="$(first_fixed_match "define('MARKETING_MODULE_NAME', 'marketing');" "$BOOTSTRAP_FILE")"

  if [[ -z "$module_line" || -z "$version_line" || -z "$requires_line" || -z "$constant_line" ]]; then
    fail \
      "module metadata header remains present" \
      "expected module metadata header and module-name constant in $BOOTSTRAP_FILE; module=${module_line:-missing}; version=${version_line:-missing}; requires=${requires_line:-missing}; constant=${constant_line:-missing}"
    return
  fi

  pass \
    "module metadata header remains present" \
    "module=$module_line | version=$version_line | requires=$requires_line"
}

check_module_lifecycle_hooks() {
  local activation_line deactivation_line uninstall_line cron_line csrf_line
  activation_line="$(first_fixed_match "register_activation_hook(MARKETING_MODULE_NAME, 'marketing_module_activation_hook');" "$BOOTSTRAP_FILE")"
  deactivation_line="$(first_fixed_match "register_deactivation_hook(MARKETING_MODULE_NAME, 'marketing_module_deactivation_hook');" "$BOOTSTRAP_FILE")"
  uninstall_line="$(first_fixed_match "register_uninstall_hook(MARKETING_MODULE_NAME, 'marketing_module_uninstall_hook');" "$BOOTSTRAP_FILE")"
  cron_line="$(first_fixed_match "hooks()->add_action('after_cron_run', 'marketing_cron_process_queue');" "$BOOTSTRAP_FILE")"
  csrf_line="$(first_fixed_match "hooks()->add_action('app_init', 'marketing_register_csrf_exclusions');" "$BOOTSTRAP_FILE")"

  if [[ -z "$activation_line" || -z "$deactivation_line" || -z "$uninstall_line" || -z "$cron_line" || -z "$csrf_line" ]]; then
    fail \
      "lifecycle hooks remain registered" \
      "expected activation, deactivation, uninstall, cron, and csrf hooks in $BOOTSTRAP_FILE; activation=${activation_line:-missing}; deactivation=${deactivation_line:-missing}; uninstall=${uninstall_line:-missing}; cron=${cron_line:-missing}; csrf=${csrf_line:-missing}"
    return
  fi

  pass \
    "lifecycle hooks remain registered" \
    "activation=$activation_line | cron=$cron_line | csrf=$csrf_line"
}

check_confirmed_unsubscribe_flow() {
  local route_line get_guard_line confirm_line apply_call_line helper_line render_helper_line render_call_line lang_loader_line suppression_line event_line view_form_line view_checkbox_line view_note_line view_csrf_line public_layout_line page_class_line card_class_line status_class_line details_class_line button_class_line email_label_call_line campaign_label_call_line raw_uppercase_line csrf_exclude_line lang_title_line lang_confirm_line lang_message_line lang_required_line lang_checkbox_line lang_button_line lang_note_line lang_email_line lang_campaign_line lang_invalid_line lang_success_line lang_pending_line lang_status_success_line lang_status_error_line import_skip_line click_skip_line click_event_line unsubscribe_count_line repair_usage_line repair_apply_line webhooks_diff mailer_diff ses_diff whatsapp_diff git_diff_mode
  route_line="$(first_fixed_match "\$route['marketing/unsubscribe/(:any)'] = 'subscriptions/unsubscribe/\$1';" "$ROUTES_FILE")"
  get_guard_line="$(first_fixed_match "if (\$requestMethod !== 'POST') {" "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  confirm_line="$(first_fixed_match "unsubscribe_confirmation" "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  apply_call_line="$(first_fixed_match "\$result = \$this->apply_confirmed_unsubscribe(\$payload, \$context);" "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  helper_line="$(first_match 'function[[:space:]]+apply_confirmed_unsubscribe' "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  render_helper_line="$(first_match 'function[[:space:]]+render_public_unsubscribe_view' "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  render_call_line="$(first_fixed_match "\$this->render_public_unsubscribe_view(\$data);" "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  lang_loader_line="$(first_match 'function[[:space:]]+ensure_public_language_loaded' "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  suppression_line="$(first_fixed_match "'marketing_unsubscribe'" "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  event_line="$(first_fixed_match "'provider' => 'marketing_unsubscribe'" "$SUBSCRIPTIONS_CONTROLLER_FILE")"
  view_form_line="$(first_fixed_match 'class="marketing-unsubscribe-form"' "$UNSUBSCRIBE_VIEW_FILE")"
  view_checkbox_line="$(first_fixed_match 'name="unsubscribe_confirmation" value="confirm" required' "$UNSUBSCRIBE_VIEW_FILE")"
  view_note_line="$(first_fixed_match "marketing_unsubscribe_confirm_note" "$UNSUBSCRIBE_VIEW_FILE")"
  view_csrf_line="$(first_fixed_match 'csrf_' "$UNSUBSCRIBE_VIEW_FILE")"
  public_layout_line="$(first_fixed_match '<!DOCTYPE html>' "$UNSUBSCRIBE_VIEW_FILE")"
  page_class_line="$(first_fixed_match 'marketing-unsubscribe-page' "$UNSUBSCRIBE_VIEW_FILE")"
  card_class_line="$(first_fixed_match 'marketing-unsubscribe-card' "$UNSUBSCRIBE_VIEW_FILE")"
  status_class_line="$(first_fixed_match 'marketing-unsubscribe-status' "$UNSUBSCRIBE_VIEW_FILE")"
  details_class_line="$(first_fixed_match 'marketing-unsubscribe-details' "$UNSUBSCRIBE_VIEW_FILE")"
  button_class_line="$(first_fixed_match 'marketing-unsubscribe-button' "$UNSUBSCRIBE_VIEW_FILE")"
  email_label_call_line="$(first_fixed_match "_l('marketing_unsubscribe_email_label')" "$UNSUBSCRIBE_VIEW_FILE")"
  campaign_label_call_line="$(first_fixed_match "_l('marketing_unsubscribe_campaign_label')" "$UNSUBSCRIBE_VIEW_FILE")"
  raw_uppercase_line="$(first_match 'MARKETING_UNSUBSCRIBE_[A-Z_]+' "$UNSUBSCRIBE_VIEW_FILE")"
  csrf_exclude_line="$(first_fixed_match "'marketing/unsubscribe/.*'" "$CSRF_FILE")"
  lang_title_line="$(first_fixed_match "\$lang['marketing_unsubscribe_title']" "$LANGUAGE_FILE")"
  lang_confirm_line="$(first_fixed_match "\$lang['marketing_unsubscribe_confirm_title']" "$LANGUAGE_FILE")"
  lang_message_line="$(first_fixed_match "\$lang['marketing_unsubscribe_confirm_message']" "$LANGUAGE_FILE")"
  lang_required_line="$(first_fixed_match "\$lang['marketing_unsubscribe_confirm_required']" "$LANGUAGE_FILE")"
  lang_checkbox_line="$(first_fixed_match "\$lang['marketing_unsubscribe_confirm_checkbox']" "$LANGUAGE_FILE")"
  lang_button_line="$(first_fixed_match "\$lang['marketing_unsubscribe_confirm_button']" "$LANGUAGE_FILE")"
  lang_note_line="$(first_fixed_match "\$lang['marketing_unsubscribe_confirm_note']" "$LANGUAGE_FILE")"
  lang_email_line="$(first_fixed_match "\$lang['marketing_unsubscribe_email_label']" "$LANGUAGE_FILE")"
  lang_campaign_line="$(first_fixed_match "\$lang['marketing_unsubscribe_campaign_label']" "$LANGUAGE_FILE")"
  lang_invalid_line="$(first_fixed_match "\$lang['marketing_unsubscribe_invalid']" "$LANGUAGE_FILE")"
  lang_success_line="$(first_fixed_match "\$lang['marketing_unsubscribe_success']" "$LANGUAGE_FILE")"
  lang_pending_line="$(first_fixed_match "\$lang['marketing_unsubscribe_status_pending']" "$LANGUAGE_FILE")"
  lang_status_success_line="$(first_fixed_match "\$lang['marketing_unsubscribe_status_success']" "$LANGUAGE_FILE")"
  lang_status_error_line="$(first_fixed_match "\$lang['marketing_unsubscribe_status_error']" "$LANGUAGE_FILE")"
  import_skip_line="$(first_fixed_match "if (\$this->marketing_audiences_model->is_contact_suppressed(\$normalizedEmail)) {" "modules/marketing/controllers/Imports.php")"
  click_skip_line="$(first_fixed_match "|| strpos(\$path, '/marketing/unsubscribe/') !== false" "$MAILER_FILE")"
  click_event_line="$(first_fixed_match "'event_type' => 'click'" "$TRACK_FILE")"
  unsubscribe_count_line="$(first_fixed_match "'unsubscribe' => \$this->count_events_by_normalized_types(['unsubscribe'], \$campaignId)," "$EVENTS_FILE")"
  repair_usage_line="$(first_fixed_match 'repair_false_unsubscribe_suppressions.php --email=user@example.com' "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  repair_apply_line="$(first_fixed_match '--apply' "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  webhooks_diff="$(git_changed_path_if_available "$WEBHOOKS_FILE")"
  mailer_diff="$(git_changed_path_if_available "$MAILER_FILE")"
  ses_diff="$(git_changed_path_if_available "$SES_SNS_FILE")"
  whatsapp_diff="$(git_changed_path_if_available "$WHATSAPP_FILE")"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$route_line" || -z "$get_guard_line" || -z "$confirm_line" || -z "$apply_call_line" || -z "$helper_line" || -z "$render_helper_line" || -z "$render_call_line" || -z "$lang_loader_line" || -z "$suppression_line" || -z "$event_line" || -z "$view_form_line" || -z "$view_checkbox_line" || -z "$view_note_line" || -n "$view_csrf_line" || -z "$public_layout_line" || -z "$page_class_line" || -z "$card_class_line" || -z "$status_class_line" || -z "$details_class_line" || -z "$button_class_line" || -z "$email_label_call_line" || -z "$campaign_label_call_line" || -n "$raw_uppercase_line" || -z "$csrf_exclude_line" || -z "$lang_title_line" || -z "$lang_confirm_line" || -z "$lang_message_line" || -z "$lang_required_line" || -z "$lang_checkbox_line" || -z "$lang_button_line" || -z "$lang_note_line" || -z "$lang_email_line" || -z "$lang_campaign_line" || -z "$lang_invalid_line" || -z "$lang_success_line" || -z "$lang_pending_line" || -z "$lang_status_success_line" || -z "$lang_status_error_line" || -z "$import_skip_line" || -z "$click_skip_line" || -z "$click_event_line" || -z "$unsubscribe_count_line" || -z "$repair_usage_line" || -z "$repair_apply_line" || -n "$webhooks_diff" || -n "$mailer_diff" || -n "$ses_diff" || -n "$whatsapp_diff" ]]; then
    fail \
      "public unsubscribe GET stays non-mutating, renders a standalone public confirmation page, and unsubscribe analytics stay distinct from click telemetry" \
      "expected public unsubscribe route, non-POST guard, confirmed apply helper, standalone render helper, public language loader, marketing_unsubscribe creation only in confirmed flow, POST confirmation form without public csrf dependency, polished standalone layout markers, translated email/campaign labels, no raw uppercase key text, csrf exclusion for token-confirmed POST, unsubscribe URL click-skip in mailer, click telemetry staying click-only, unsubscribe analytics counting unsubscribe events only, import suppression skip, repair script, and no webhook/mailer/provider diffs; route=${route_line:-missing}; get_guard=${get_guard_line:-missing}; confirm=${confirm_line:-missing}; apply=${apply_call_line:-missing}; helper=${helper_line:-missing}; render_helper=${render_helper_line:-missing}; render_call=${render_call_line:-missing}; lang_loader=${lang_loader_line:-missing}; suppression=${suppression_line:-missing}; event=${event_line:-missing}; form=${view_form_line:-missing}; checkbox=${view_checkbox_line:-missing}; note=${view_note_line:-missing}; csrf_view=${view_csrf_line:-none}; page=${page_class_line:-missing}; card=${card_class_line:-missing}; status=${status_class_line:-missing}; details=${details_class_line:-missing}; button=${button_class_line:-missing}; email_call=${email_label_call_line:-missing}; campaign_call=${campaign_label_call_line:-missing}; raw_upper=${raw_uppercase_line:-none}; csrf_exclude=${csrf_exclude_line:-missing}; lang_title=${lang_title_line:-missing}; lang_confirm=${lang_confirm_line:-missing}; lang_message=${lang_message_line:-missing}; lang_required=${lang_required_line:-missing}; lang_checkbox=${lang_checkbox_line:-missing}; lang_button=${lang_button_line:-missing}; lang_note=${lang_note_line:-missing}; lang_email=${lang_email_line:-missing}; lang_campaign=${lang_campaign_line:-missing}; lang_invalid=${lang_invalid_line:-missing}; lang_success=${lang_success_line:-missing}; lang_pending=${lang_pending_line:-missing}; lang_success_status=${lang_status_success_line:-missing}; lang_error_status=${lang_status_error_line:-missing}; click_skip=${click_skip_line:-missing}; click_event=${click_event_line:-missing}; unsubscribe_count=${unsubscribe_count_line:-missing}; import_skip=${import_skip_line:-missing}; repair_usage=${repair_usage_line:-missing}; repair_apply=${repair_apply_line:-missing}; git_diff_mode=${git_diff_mode:-unknown}; webhooks_diff=${webhooks_diff:-none}; mailer_diff=${mailer_diff:-none}; ses_diff=${ses_diff:-none}; whatsapp_diff=${whatsapp_diff:-none}"
    return
  fi

  pass \
    "public unsubscribe GET stays non-mutating, renders a standalone public confirmation page, and unsubscribe analytics stay distinct from click telemetry" \
    "get_guard=$get_guard_line | render=$render_helper_line | lang_loader=$lang_loader_line | page=$page_class_line | click_skip=$click_skip_line | unsubscribe_count=$unsubscribe_count_line | git_diff_mode=$git_diff_mode"
}

check_false_unsubscribe_repair_script_safety() {
  local cli_only_line dry_run_line apply_gate_line db_prefix_guard_line table_scope_line reason_scope_line source_scope_line active_scope_line
  cli_only_line="$(first_fixed_match "if (PHP_SAPI !== 'cli') {" "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  dry_run_line="$(first_fixed_match 'Dry run only. Re-run with --apply to deactivate the matching suppressions.' "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  apply_gate_line="$(first_fixed_match "if (!\$applyMode) {" "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  db_prefix_guard_line="$(first_fixed_match 'function unsubscribe_repair_define_runtime_db_prefix_fallback()' "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  table_scope_line="$(first_fixed_match "\$tableName = db_prefix() . 'marketing_suppressions';" "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  reason_scope_line="$(first_fixed_match "reason='unsubscribe'" "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  source_scope_line="$(first_fixed_match "source='marketing_unsubscribe'" "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"
  active_scope_line="$(first_fixed_match "active=1" "$FALSE_UNSUBSCRIBE_REPAIR_FILE")"

  if [[ -z "$cli_only_line" || -z "$dry_run_line" || -z "$apply_gate_line" || -z "$db_prefix_guard_line" || -z "$table_scope_line" || -z "$reason_scope_line" || -z "$source_scope_line" || -z "$active_scope_line" ]]; then
    fail \
      "false unsubscribe repair script stays CLI-only, dry-run-first, bootstrap-safe, and tightly scoped" \
      "expected CLI-only guard, dry-run default, --apply gate, db_prefix() fallback helper, tblmarketing_suppressions scope, and unsubscribe-only active marketing_unsubscribe update filter in $FALSE_UNSUBSCRIBE_REPAIR_FILE; cli=${cli_only_line:-missing}; dry_run=${dry_run_line:-missing}; apply=${apply_gate_line:-missing}; db_prefix=${db_prefix_guard_line:-missing}; table=${table_scope_line:-missing}; reason=${reason_scope_line:-missing}; source=${source_scope_line:-missing}; active=${active_scope_line:-missing}"
    return
  fi

  pass \
    "false unsubscribe repair script stays CLI-only, dry-run-first, bootstrap-safe, and tightly scoped" \
    "cli=$cli_only_line | db_prefix=$db_prefix_guard_line | source=$source_scope_line"
}

check_required_config_and_public_endpoints() {
  local ses_route inbound_route whatsapp_route tracking_route unsubscribe_route csrf_ses_line csrf_inbound_line csrf_whatsapp_line
  ses_route="$(first_fixed_match "\$route['marketing/webhooks/ses_sns'] = 'webhooks/ses_sns';" "$ROUTES_FILE")"
  inbound_route="$(first_fixed_match "\$route['marketing/webhooks/inbound_email'] = 'webhooks/inbound_email';" "$ROUTES_FILE")"
  whatsapp_route="$(first_fixed_match "\$route['marketing/webhooks/whatsapp'] = 'webhooks/whatsapp';" "$ROUTES_FILE")"
  tracking_route="$(first_fixed_match "\$route['marketing/track/c/(:any)'] = 'track/c/\$1';" "$ROUTES_FILE")"
  unsubscribe_route="$(first_fixed_match "\$route['marketing/unsubscribe/(:any)'] = 'subscriptions/unsubscribe/\$1';" "$ROUTES_FILE")"
  csrf_ses_line="$(first_fixed_match "'marketing/webhooks/ses_sns'" "$CSRF_FILE")"
  csrf_inbound_line="$(first_fixed_match "'marketing/webhooks/inbound_email'" "$CSRF_FILE")"
  csrf_whatsapp_line="$(first_fixed_match "'marketing/webhooks/whatsapp'" "$CSRF_FILE")"

  if [[ -z "$ses_route" || -z "$inbound_route" || -z "$whatsapp_route" || -z "$tracking_route" || -z "$unsubscribe_route" || -z "$csrf_ses_line" || -z "$csrf_inbound_line" || -z "$csrf_whatsapp_line" ]]; then
    fail \
      "required config files, public routes, and csrf exclusions remain present" \
      "expected webhook/tracking/unsubscribe routes and csrf exclusions; ses=${ses_route:-missing}; inbound=${inbound_route:-missing}; whatsapp=${whatsapp_route:-missing}; track=${tracking_route:-missing}; unsubscribe=${unsubscribe_route:-missing}; csrf_ses=${csrf_ses_line:-missing}; csrf_inbound=${csrf_inbound_line:-missing}; csrf_whatsapp=${csrf_whatsapp_line:-missing}"
    return
  fi

  pass \
    "required config files, public routes, and csrf exclusions remain present" \
    "ses=$ses_route | inbound=$inbound_route | whatsapp=$whatsapp_route"
}

check_inbound_reply_hardening() {
  local option_default auth_helper hash_line compat_line header_line normalize_regex unambiguous_helper latest_call dedupe_helper provider_lookup dedupe_lookup duplicate_branch lead_skip event_store settings_field settings_runtime controller_nested preserve_blank update_secret hidden_value visibility_note inbound_conversation_channel inbound_conversation_provider conversation_fallback_trigger conversation_fallback_default whatsapp_channel
  option_default="$(first_fixed_match "'marketing_inbound_email_secret' => ''" "$BOOTSTRAP_FILE")"
  auth_helper="$(first_match 'function[[:space:]]+validateInboundEmailAuthentication' "$WEBHOOKS_FILE")"
  hash_line="$(first_fixed_match "hash_equals(\$configuredSecret, \$presentedSecret)" "$WEBHOOKS_FILE")"
  compat_line="$(first_fixed_match "'warning' => 'inbound_email_auth_not_configured'" "$WEBHOOKS_FILE")"
  header_line="$(first_fixed_match "x-marketing-inbound-secret" "$WEBHOOKS_FILE")"
  normalize_regex="$(first_fixed_match "preg_match('/<([^<>@\\s]+@[^<>@\\s]+)>/'" "$WEBHOOKS_FILE")"
  unambiguous_helper="$(first_match 'function[[:space:]]+findUnambiguousRecipientByEmail' "$WEBHOOKS_FILE")"
  latest_call="$(first_fixed_match 'findLatestRecipientByEmail(' "$WEBHOOKS_FILE")"
  dedupe_helper="$(first_match 'function[[:space:]]+add_message_idempotent' "$CONVERSATIONS_FILE")"
  provider_lookup="$(first_match 'function[[:space:]]+find_message_by_provider_message_id' "$CONVERSATIONS_FILE")"
  dedupe_lookup="$(first_match 'function[[:space:]]+find_message_by_dedupe_key' "$CONVERSATIONS_FILE")"
  duplicate_branch="$(first_fixed_match "if (!empty(\$messageStoreResult['duplicate'])) {" "$WEBHOOKS_FILE")"
  lead_skip="$(first_fixed_match "'lead_error' => \$messageId <= 0" "$WEBHOOKS_FILE")"
  event_store="$(first_fixed_match "\$this->marketing_events_model->add_event_idempotent([" "$WEBHOOKS_FILE")"
  settings_field="$(first_fixed_match "marketing_inbound_email_secret" "$SETTINGS_VIEW_FILE")"
  settings_runtime="$(first_fixed_match "marketing_inbound_email_secret_runtime_" "$SETTINGS_VIEW_FILE")"
  controller_nested="$(first_fixed_match "'marketing_inbound_email_secret' => (string) \$getValue('marketing_inbound_email_secret', false)," "$SETTINGS_CONTROLLER_FILE")"
  preserve_blank="$(first_fixed_match "'marketing_inbound_email_secret' => \$postedSecret !== '' ? \$postedSecret : \$existingSecret," "$SETTINGS_CONTROLLER_FILE")"
  update_secret="$(first_fixed_match "\$postedSecret = trim((string) (\$posted['marketing_inbound_email_secret'] ?? ''));" "$SETTINGS_CONTROLLER_FILE")"
  hidden_value="$(first_fixed_match 'id="marketing_inbound_email_secret" name="<?= html_escape($settingFieldName('\''marketing_inbound_email_secret'\'')); ?>" class="form-control" value="" autocomplete="new-password"' "$SETTINGS_VIEW_FILE")"
  visibility_note="$(first_fixed_match "marketing_inbound_email_secret_visibility_note" "$SETTINGS_VIEW_FILE")"
  inbound_conversation_channel="$(first_fixed_match "'channel' => 'email'" "$WEBHOOKS_FILE")"
  inbound_conversation_provider="$(first_fixed_match "'provider' => 'inbound_email'" "$WEBHOOKS_FILE")"
  conversation_fallback_trigger="$(first_fixed_match "\$sourceType === 'email_reply' || \$provider === 'inbound_email'" "$CONVERSATIONS_FILE")"
  conversation_fallback_default="$(first_fixed_match "\$channel = 'email';" "$CONVERSATIONS_FILE")"
  whatsapp_channel="$(first_fixed_match "'channel' => 'whatsapp'" "$WEBHOOKS_FILE")"

  if [[ -z "$option_default" || -z "$auth_helper" || -z "$hash_line" || -z "$compat_line" || -z "$header_line" || -z "$normalize_regex" || -z "$unambiguous_helper" || -n "$latest_call" || -z "$dedupe_helper" || -z "$provider_lookup" || -z "$dedupe_lookup" || -z "$duplicate_branch" || -z "$lead_skip" || -z "$event_store" || -z "$settings_field" || -z "$settings_runtime" || -z "$controller_nested" || -z "$preserve_blank" || -z "$update_secret" || -z "$hidden_value" || -z "$visibility_note" || -z "$inbound_conversation_channel" || -z "$inbound_conversation_provider" || -z "$conversation_fallback_trigger" || -z "$conversation_fallback_default" || -z "$whatsapp_channel" ]]; then
    fail \
      "inbound email reply handling remains authenticated, ambiguity-aware, and idempotent" \
      "expected inbound secret default and auth guard in $BOOTSTRAP_FILE/$WEBHOOKS_FILE, unambiguous sender fallback without legacy latest-recipient call, idempotent message helpers in $CONVERSATIONS_FILE, duplicate lead-skip branch, idempotent reply events, settings field/runtime note in $SETTINGS_VIEW_FILE plus nested settings persistence in $SETTINGS_CONTROLLER_FILE, and non-null inbound conversation channel metadata; option=${option_default:-missing}; auth=${auth_helper:-missing}; hash=${hash_line:-missing}; compat=${compat_line:-missing}; header=${header_line:-missing}; normalize=${normalize_regex:-missing}; unambiguous=${unambiguous_helper:-missing}; legacy_latest=${latest_call:-absent}; dedupe=${dedupe_helper:-missing}; provider_lookup=${provider_lookup:-missing}; dedupe_lookup=${dedupe_lookup:-missing}; duplicate=${duplicate_branch:-missing}; lead_skip=${lead_skip:-missing}; event=${event_store:-missing}; settings=${settings_field:-missing}; runtime=${settings_runtime:-missing}; nested=${controller_nested:-missing}; preserve=${preserve_blank:-missing}; update=${update_secret:-missing}; hidden=${hidden_value:-missing}; note=${visibility_note:-missing}; inbound_channel=${inbound_conversation_channel:-missing}; inbound_provider=${inbound_conversation_provider:-missing}; fallback_trigger=${conversation_fallback_trigger:-missing}; fallback_default=${conversation_fallback_default:-missing}; whatsapp_channel=${whatsapp_channel:-missing}"
    return
  fi

  pass \
    "inbound email reply handling remains authenticated, ambiguity-aware, and idempotent" \
    "auth=$auth_helper | hash=$hash_line | fallback=$unambiguous_helper | dedupe=$dedupe_helper | event=$event_store"
}

check_phase4_integrity_diagnostics() {
  local model_helper runtime_script runtime_method dashboard_controller_load dashboard_view_title dashboard_view_note dashboard_view_advanced dashboard_view_hidden_apply inbound_message_channel inbound_message_provider whatsapp_message_channel destructive_sql cli_guard bootstrap_guard db_prefix_guard exit_zero exit_one exit_two orphaned_warning_model orphaned_warning_runtime orphaned_reported_runtime reconciliation_model reconciliation_runtime reconciliation_runtime_warning reconciliation_exclusion runtime_exclusion
  model_helper="$(first_match 'class[[:space:]]+Marketing_integrity_model' "$INTEGRITY_MODEL_FILE")"
  runtime_script="$(first_fixed_match 'Marketing Integrity Diagnostics' "$INTEGRITY_RUNTIME_FILE")"
  runtime_method="$(first_match 'function[[:space:]]+get_integrity_diagnostics' "$INTEGRITY_MODEL_FILE")"
  dashboard_controller_load="$(first_fixed_match "Marketing_integrity_model" "$MARKETING_CONTROLLER_FILE")"
  dashboard_view_title="$(first_fixed_match 'Integrity Health' "$DASHBOARD_VIEW_FILE")"
  dashboard_view_note="$(first_fixed_match "marketing_dashboard_integrity_health_copy" "$DASHBOARD_VIEW_FILE")"
  dashboard_view_advanced="$(first_fixed_match 'marketing-ops-advanced' "$DASHBOARD_VIEW_FILE")"
  dashboard_view_hidden_apply="$(first_fixed_match 'repair_phase4_integrity_runtime.php --apply' "$DASHBOARD_VIEW_FILE")"
  inbound_message_channel="$(first_fixed_match "'channel' => 'email'" "$WEBHOOKS_FILE")"
  inbound_message_provider="$(first_fixed_match "'provider' => 'inbound_email'" "$WEBHOOKS_FILE")"
  whatsapp_message_channel="$(first_fixed_match "'channel' => 'whatsapp'" "$WEBHOOKS_FILE")"
  cli_guard="$(first_fixed_match "PHP_SAPI !== 'cli'" "$INTEGRITY_RUNTIME_FILE")"
  bootstrap_guard="$(first_fixed_match "define('BASEPATH', \$crmRoot . '/system/');" "$INTEGRITY_RUNTIME_FILE")"
  db_prefix_guard="$(first_fixed_match "function db_prefix()" "$INTEGRITY_RUNTIME_FILE")"
  exit_zero="$(first_fixed_match "\$exitStatus = \$criticalCount > 0 ? 1 : 0;" "$INTEGRITY_RUNTIME_FILE")"
  exit_one="$(first_fixed_match "marketing_exit_with_status(2, \"Perfex CRM root not found. Run this from the deployed CRM root.\");" "$INTEGRITY_RUNTIME_FILE")"
  exit_two="$(first_fixed_match "marketing_exit_with_status(\$exitStatus, '', false);" "$INTEGRITY_RUNTIME_FILE")"
  orphaned_warning_model="$(first_fixed_match "Audit-Preserved Orphaned Event Recipient Refs', 'warning'" "$INTEGRITY_MODEL_FILE")"
  orphaned_warning_runtime="$(first_fixed_match "'label' => 'Audit-Preserved Orphaned Event Recipient Refs'" "$INTEGRITY_RUNTIME_FILE")"
  orphaned_reported_runtime="$(first_fixed_match "'severity' => 'warning'" "$INTEGRITY_RUNTIME_FILE")"
  reconciliation_model="$(first_fixed_match "Reconciliation Skipped Events', 'warning'" "$INTEGRITY_MODEL_FILE")"
  reconciliation_runtime="$(first_fixed_match "'label' => 'Reconciliation Skipped Events'" "$INTEGRITY_RUNTIME_FILE")"
  reconciliation_runtime_warning="$(first_fixed_match "'severity' => 'warning'" "$INTEGRITY_RUNTIME_FILE")"
  reconciliation_exclusion="$(first_fixed_match "e.campaign_recipient_id = 0 OR r.id IS NOT NULL" "$INTEGRITY_MODEL_FILE")"
  runtime_exclusion="$(first_fixed_match "e.campaign_recipient_id = 0 or r.id is not null" "$INTEGRITY_RUNTIME_FILE")"
  destructive_sql="$(grep -nE '\b(DELETE|UPDATE|INSERT|TRUNCATE|DROP|ALTER|CREATE)\b' "$INTEGRITY_RUNTIME_FILE" || true)"

  if [[ -z "$model_helper" || -z "$runtime_script" || -z "$runtime_method" || -z "$dashboard_controller_load" || -z "$dashboard_view_title" || -z "$dashboard_view_note" || -z "$dashboard_view_advanced" || -z "$dashboard_view_hidden_apply" || -z "$inbound_message_channel" || -z "$inbound_message_provider" || -z "$whatsapp_message_channel" || -z "$cli_guard" || -z "$bootstrap_guard" || -z "$db_prefix_guard" || -z "$exit_zero" || -z "$exit_one" || -z "$exit_two" || -z "$orphaned_warning_model" || -z "$orphaned_warning_runtime" || -z "$orphaned_reported_runtime" || -z "$reconciliation_model" || -z "$reconciliation_runtime" || -z "$reconciliation_runtime_warning" || -z "$reconciliation_exclusion" || -z "$runtime_exclusion" || -n "$destructive_sql" ]]; then
    fail \
      "phase 4 integrity diagnostics remain read-only, reusable, and dashboard-visible" \
      "expected integrity model/runtime script/dashboard wiring, safe CLI bootstrap/db_prefix/exit handling, warning-only orphaned audit refs, warning-only reconciliation skipped diagnostics excluding orphaned recipient refs, advanced/collapsed dashboard diagnostics commands, plus inbound email message channel metadata and preserved WhatsApp markers; model=${model_helper:-missing}; runtime=${runtime_script:-missing}; method=${runtime_method:-missing}; controller=${dashboard_controller_load:-missing}; title=${dashboard_view_title:-missing}; note=${dashboard_view_note:-missing}; advanced=${dashboard_view_advanced:-missing}; hidden_apply=${dashboard_view_hidden_apply:-missing}; cli=${cli_guard:-missing}; basepath=${bootstrap_guard:-missing}; db_prefix=${db_prefix_guard:-missing}; exit0=${exit_zero:-missing}; exit2=${exit_one:-missing}; exit_final=${exit_two:-missing}; orphaned_model=${orphaned_warning_model:-missing}; orphaned_runtime=${orphaned_warning_runtime:-missing}; orphaned_severity=${orphaned_reported_runtime:-missing}; reconciliation_model=${reconciliation_model:-missing}; reconciliation_runtime=${reconciliation_runtime:-missing}; reconciliation_runtime_warning=${reconciliation_runtime_warning:-missing}; reconciliation_exclusion=${reconciliation_exclusion:-missing}; runtime_exclusion=${runtime_exclusion:-missing}; inbound_channel=${inbound_message_channel:-missing}; inbound_provider=${inbound_message_provider:-missing}; whatsapp_channel=${whatsapp_message_channel:-missing}; destructive=${destructive_sql:-none}"
    return
  fi

  pass \
    "phase 4 integrity diagnostics remain read-only, reusable, and dashboard-visible" \
    "model=$model_helper | runtime=$runtime_script | advanced=$dashboard_view_advanced | orphaned=$orphaned_warning_model | reconciliation=$reconciliation_model | cli=$cli_guard"
}

check_phase4_integrity_repair() {
  local repair_script repair_cli_guard repair_bootstrap_guard repair_db_prefix_guard repair_help repair_apply repair_dry_run repair_exit_dry repair_exit_bootstrap repair_exit_apply repair_orphaned_note repair_reconciliation_note repair_reconciliation_exclusion dashboard_repair_note dashboard_repair_apply forbidden_sql validator_runtime
  repair_script="$(first_fixed_match 'Marketing Integrity Repair' "$INTEGRITY_REPAIR_FILE")"
  repair_cli_guard="$(first_fixed_match "PHP_SAPI !== 'cli'" "$INTEGRITY_REPAIR_FILE")"
  repair_bootstrap_guard="$(first_fixed_match "define('BASEPATH', \$crmRoot . '/system/');" "$INTEGRITY_REPAIR_FILE")"
  repair_db_prefix_guard="$(first_fixed_match "function db_prefix()" "$INTEGRITY_REPAIR_FILE")"
  repair_help="$(first_fixed_match "--help" "$INTEGRITY_REPAIR_FILE")"
  repair_apply="$(first_fixed_match "--apply" "$INTEGRITY_REPAIR_FILE")"
  repair_dry_run="$(first_fixed_match "\$applyMode = false;" "$INTEGRITY_REPAIR_FILE")"
  repair_exit_dry="$(first_fixed_match "marketing_repair_exit(1, '', false);" "$INTEGRITY_REPAIR_FILE")"
  repair_exit_bootstrap="$(first_fixed_match "marketing_repair_exit(2, \"Perfex CRM root not found. Run this from the deployed CRM root.\");" "$INTEGRITY_REPAIR_FILE")"
  repair_exit_apply="$(first_fixed_match "marketing_repair_exit(3, \"Apply failed while repairing: \" . \$target['title']);" "$INTEGRITY_REPAIR_FILE")"
  repair_orphaned_note="$(first_fixed_match 'preserved audit events with no automatic relink/delete' "$INTEGRITY_REPAIR_FILE")"
  repair_reconciliation_note="$(first_fixed_match 'mutation-safe telemetry where recipient mutation was intentionally skipped' "$INTEGRITY_REPAIR_FILE")"
  repair_reconciliation_exclusion="$(first_fixed_match "e.campaign_recipient_id = 0 or r.id is not null" "$INTEGRITY_REPAIR_FILE")"
  dashboard_repair_note="$(first_fixed_match 'repair_phase4_integrity_runtime.php' "$DASHBOARD_VIEW_FILE")"
  dashboard_repair_apply="$(first_fixed_match 'repair_phase4_integrity_runtime.php --apply' "$DASHBOARD_VIEW_FILE")"
  validator_runtime="$(first_fixed_match 'Marketing Integrity Diagnostics' "$INTEGRITY_RUNTIME_FILE")"
  forbidden_sql="$(grep -nE '\b(DELETE|TRUNCATE|DROP|ALTER|CREATE)\b' "$INTEGRITY_REPAIR_FILE" || true)"

  if [[ -z "$repair_script" || -z "$repair_cli_guard" || -z "$repair_bootstrap_guard" || -z "$repair_db_prefix_guard" || -z "$repair_help" || -z "$repair_apply" || -z "$repair_dry_run" || -z "$repair_exit_dry" || -z "$repair_exit_bootstrap" || -z "$repair_exit_apply" || -z "$repair_orphaned_note" || -z "$repair_reconciliation_note" || -z "$repair_reconciliation_exclusion" || -z "$dashboard_repair_note" || -z "$dashboard_repair_apply" || -z "$validator_runtime" || -n "$forbidden_sql" ]]; then
    fail \
      "phase 4 integrity repair remains explicit, CLI-only, and non-destructive" \
      "expected repair runtime script with CLI bootstrap, db_prefix fallback, dry-run default, apply-only writes, explicit exit handling, orphaned-event and reconciliation-skipped diagnostic notes, orphaned-exclusion parity, dashboard repair commands, and preserved read-only validator; script=${repair_script:-missing}; cli=${repair_cli_guard:-missing}; basepath=${repair_bootstrap_guard:-missing}; db_prefix=${repair_db_prefix_guard:-missing}; help=${repair_help:-missing}; apply=${repair_apply:-missing}; dry_run=${repair_dry_run:-missing}; exit1=${repair_exit_dry:-missing}; exit2=${repair_exit_bootstrap:-missing}; exit3=${repair_exit_apply:-missing}; orphaned=${repair_orphaned_note:-missing}; reconciliation=${repair_reconciliation_note:-missing}; reconciliation_exclusion=${repair_reconciliation_exclusion:-missing}; dashboard=${dashboard_repair_note:-missing}; dashboard_apply=${dashboard_repair_apply:-missing}; validator=${validator_runtime:-missing}; forbidden=${forbidden_sql:-none}"
    return
  fi

  pass \
    "phase 4 integrity repair remains explicit, CLI-only, and non-destructive" \
    "script=$repair_script | cli=$repair_cli_guard | dry_run=$repair_dry_run | apply=$repair_apply"
}

check_option_repair_idempotence() {
  local defaults_helper_line option_names_line ensure_helper_line install_usage_line uninstall_usage_line add_only_line
  defaults_helper_line="$(first_match 'function[[:space:]]+marketing_get_default_options' "$BOOTSTRAP_FILE")"
  option_names_line="$(first_match 'function[[:space:]]+marketing_get_option_names' "$BOOTSTRAP_FILE")"
  ensure_helper_line="$(first_match 'function[[:space:]]+marketing_ensure_required_options' "$BOOTSTRAP_FILE")"
  install_usage_line="$(first_fixed_match "marketing_get_default_options()" "$INSTALL_FILE")"
  uninstall_usage_line="$(first_fixed_match "marketing_get_option_names()" "$UNINSTALL_FILE")"
  add_only_line="$(first_fixed_match "if (get_option(\$name) === false) {" "$BOOTSTRAP_FILE")"

  if [[ -z "$defaults_helper_line" || -z "$option_names_line" || -z "$ensure_helper_line" || -z "$install_usage_line" || -z "$uninstall_usage_line" || -z "$add_only_line" ]]; then
    fail \
      "install and bootstrap option repair remains idempotent" \
      "expected centralized defaults, option-name helper, install/uninstall usage, and add-only repair semantics; defaults=${defaults_helper_line:-missing}; names=${option_names_line:-missing}; ensure=${ensure_helper_line:-missing}; install=${install_usage_line:-missing}; uninstall=${uninstall_usage_line:-missing}; add_only=${add_only_line:-missing}"
    return
  fi

  pass \
    "install and bootstrap option repair remains idempotent" \
    "defaults=$defaults_helper_line | install=$install_usage_line | uninstall=$uninstall_usage_line"
}

check_release_forbidden_artifact_detection() {
  local source_check_line zip_check_line readme_line install_line operations_line
  source_check_line="$(first_fixed_match 'forbidden release artifacts detected in source tree' "$RELEASE_VALIDATOR_FILE")"
  zip_check_line="$(first_fixed_match 'forbidden artifact present in release zip' "$RELEASE_VALIDATOR_FILE")"
  readme_line="$(first_fixed_match 'bash scripts/verify_release_package.sh' "$README_FILE")"
  install_line="$(first_fixed_match 'bash scripts/verify_release_package.sh' "$INSTALLATION_FILE")"
  operations_line="$(first_fixed_match 'bash scripts/verify_release_package.sh' "$OPERATIONS_FILE")"

  if [[ -z "$source_check_line" || -z "$zip_check_line" || -z "$readme_line" || -z "$install_line" || -z "$operations_line" ]]; then
    fail \
      "forbidden release artifacts are detectable and release validation is documented" \
      "expected source/zip artifact detection in $RELEASE_VALIDATOR_FILE and validator command in docs; source=${source_check_line:-missing}; zip=${zip_check_line:-missing}; readme=${readme_line:-missing}; installation=${install_line:-missing}; operations=${operations_line:-missing}"
    return
  fi

  pass \
    "forbidden release artifacts are detectable and release validation is documented" \
    "source=$source_check_line | zip=$zip_check_line | readme=$readme_line"
}

check_release_packaging_docs_hardening() {
  local zip_root_line nested_root_line docs_required_line integrity_script_line vendor_forbidden_line source_forbidden_line readme_zip_root readme_install_path readme_nested_root readme_provider_panel readme_release_panel readme_integrity readme_meta install_zip_root install_install_path install_nested_root install_panels install_integrity install_meta ops_preflight ops_release_validator ops_provider_panel ops_release_panel ops_integrity ops_rollback ops_meta forbidden_controller forbidden_model forbidden_view forbidden_library git_diff_mode
  zip_root_line="$(first_fixed_match 'all archive entries must live under marketing/' "$RELEASE_VALIDATOR_FILE")"
  nested_root_line="$(first_fixed_match 'archive must not contain marketing/modules/marketing/' "$RELEASE_VALIDATOR_FILE")"
  docs_required_line="$(first_fixed_match 'required documentation missing from release zip' "$RELEASE_VALIDATOR_FILE")"
  integrity_script_line="$(first_fixed_match 'marketing/scripts/verify_phase4_integrity_runtime.php' "$RELEASE_VALIDATOR_FILE")"
  vendor_forbidden_line="$(first_fixed_match '^marketing/vendor/' "$RELEASE_VALIDATOR_FILE")"
  source_forbidden_line="$(first_fixed_match "no git metadata, marketplace clutter, env, vendor, cache, temp, log, or backup artifacts detected" "$RELEASE_VALIDATOR_FILE")"
  readme_zip_root="$(first_fixed_match 'marketing/' "$README_FILE")"
  readme_install_path="$(first_fixed_match 'modules/marketing/marketing.php' "$README_FILE")"
  readme_nested_root="$(first_fixed_match 'marketing/modules/marketing/marketing.php' "$README_FILE")"
  readme_provider_panel="$(first_fixed_match 'Provider Readiness' "$README_FILE")"
  readme_release_panel="$(first_fixed_match 'Release Readiness' "$README_FILE")"
  readme_integrity="$(first_fixed_match 'php modules/marketing/scripts/verify_phase4_integrity_runtime.php' "$README_FILE")"
  readme_meta="$(first_fixed_match 'Meta approval' "$README_FILE")"
  install_zip_root="$(first_fixed_match 'marketing/' "$INSTALLATION_FILE")"
  install_install_path="$(first_fixed_match 'modules/marketing/marketing.php' "$INSTALLATION_FILE")"
  install_nested_root="$(first_fixed_match 'modules/marketing/modules/marketing/marketing.php' "$INSTALLATION_FILE")"
  install_panels="$(first_fixed_match 'Provider Readiness' "$INSTALLATION_FILE")"
  install_integrity="$(first_fixed_match 'php modules/marketing/scripts/verify_phase4_integrity_runtime.php' "$INSTALLATION_FILE")"
  install_meta="$(first_fixed_match 'Meta' "$INSTALLATION_FILE")"
  ops_preflight="$(first_fixed_match '## Pre-Release Validation' "$OPERATIONS_FILE")"
  ops_release_validator="$(first_fixed_match 'bash scripts/verify_release_package.sh' "$OPERATIONS_FILE")"
  ops_provider_panel="$(first_fixed_match 'Provider Readiness' "$OPERATIONS_FILE")"
  ops_release_panel="$(first_fixed_match 'Release Readiness' "$OPERATIONS_FILE")"
  ops_integrity="$(first_fixed_match 'php modules/marketing/scripts/verify_phase4_integrity_runtime.php' "$OPERATIONS_FILE")"
  ops_rollback="$(first_fixed_match '## Rollback Note' "$OPERATIONS_FILE")"
  ops_meta="$(first_fixed_match 'Meta approval' "$OPERATIONS_FILE")"
  forbidden_controller="$(git_changed_path_if_available modules/marketing/controllers/Settings.php)"
  forbidden_model="$(git_changed_path_if_available modules/marketing/models/Marketing_dashboard_model.php)"
  forbidden_view="$(git_changed_path_if_available modules/marketing/views/settings/index.php)"
  forbidden_library="$(git_changed_path_if_available modules/marketing/libraries/Marketing_whatsapp.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$zip_root_line" || -z "$nested_root_line" || -z "$docs_required_line" || -z "$integrity_script_line" || -z "$vendor_forbidden_line" || -z "$source_forbidden_line" || -z "$readme_zip_root" || -z "$readme_install_path" || -z "$readme_nested_root" || -z "$readme_provider_panel" || -z "$readme_release_panel" || -z "$readme_integrity" || -z "$readme_meta" || -z "$install_zip_root" || -z "$install_install_path" || -z "$install_nested_root" || -z "$install_panels" || -z "$install_integrity" || -z "$install_meta" || -z "$ops_preflight" || -z "$ops_release_validator" || -z "$ops_provider_panel" || -z "$ops_release_panel" || -z "$ops_integrity" || -z "$ops_rollback" || -z "$ops_meta" || -n "$forbidden_controller" || -n "$forbidden_model" || -n "$forbidden_view" || -n "$forbidden_library" ]]; then
    fail \
      "release validator and handoff docs enforce commercial packaging expectations without runtime PHP changes" \
      "expected hardened zip-root/nested-root/docs/script checks in $RELEASE_VALIDATOR_FILE, release-facing docs that mention marketing/ root, modules/marketing/marketing.php install path, provider readiness, release readiness, runtime integrity diagnostics, rollback, and Meta approval caveat, and no runtime PHP file diffs; zip_root=${zip_root_line:-missing}; nested_root=${nested_root_line:-missing}; docs_required=${docs_required_line:-missing}; integrity_script=${integrity_script_line:-missing}; vendor=${vendor_forbidden_line:-missing}; source=${source_forbidden_line:-missing}; readme_zip=${readme_zip_root:-missing}; readme_install=${readme_install_path:-missing}; readme_nested=${readme_nested_root:-missing}; readme_provider=${readme_provider_panel:-missing}; readme_release=${readme_release_panel:-missing}; readme_integrity=${readme_integrity:-missing}; readme_meta=${readme_meta:-missing}; install_zip=${install_zip_root:-missing}; install_install=${install_install_path:-missing}; install_nested=${install_nested_root:-missing}; install_panels=${install_panels:-missing}; install_integrity=${install_integrity:-missing}; install_meta=${install_meta:-missing}; ops_preflight=${ops_preflight:-missing}; ops_release_validator=${ops_release_validator:-missing}; ops_provider=${ops_provider_panel:-missing}; ops_release=${ops_release_panel:-missing}; ops_integrity=${ops_integrity:-missing}; ops_rollback=${ops_rollback:-missing}; ops_meta=${ops_meta:-missing}; git_diff_mode=${git_diff_mode:-unknown}; controller_diff=${forbidden_controller:-none}; model_diff=${forbidden_model:-none}; view_diff=${forbidden_view:-none}; library_diff=${forbidden_library:-none}"
    return
  fi

  pass \
    "release validator and handoff docs enforce commercial packaging expectations without runtime PHP changes" \
    "validator=$zip_root_line | docs=$ops_preflight | git_diff_mode=$git_diff_mode"
}

check_release_handoff_manifest_assets() {
  local checklist_exists manifest_doc_exists manifest_json_exists validator_checklist validator_manifest_doc validator_manifest_json validator_manifest_keys validator_nested_root validator_meta validator_deferred validator_no_secrets validator_no_core checklist_release_cmd checklist_regression_cmd checklist_integrity_cmd checklist_zip_root checklist_nested_root checklist_meta checklist_facebook checklist_linkedin checklist_critical manifest_doc_zip_root manifest_doc_install_path manifest_doc_hooks manifest_doc_no_secrets manifest_doc_no_core manifest_doc_deferred manifest_json_module manifest_json_version manifest_json_root manifest_json_install manifest_json_active manifest_json_deferred manifest_json_commands manifest_json_security forbidden_controller forbidden_model forbidden_library forbidden_view git_diff_mode
  checklist_exists="$(first_fixed_match '# Production Checklist' "$PRODUCTION_CHECKLIST_FILE")"
  manifest_doc_exists="$(first_fixed_match '# Release Manifest' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_json_exists="$(first_fixed_match '"module_name": "Marketing"' "$RELEASE_MANIFEST_JSON_FILE")"
  validator_checklist="$(first_fixed_match '"docs/PRODUCTION_CHECKLIST.md"' "$RELEASE_VALIDATOR_FILE")"
  validator_manifest_doc="$(first_fixed_match '"docs/RELEASE_MANIFEST.md"' "$RELEASE_VALIDATOR_FILE")"
  validator_manifest_json="$(first_fixed_match '"release/manifest.json"' "$RELEASE_VALIDATOR_FILE")"
  validator_manifest_keys="$(first_fixed_match '"security_notes"' "$RELEASE_VALIDATOR_FILE")"
  validator_nested_root="$(first_fixed_match 'archive must not contain marketing/modules/marketing/' "$RELEASE_VALIDATOR_FILE")"
  validator_meta="$(first_fixed_match 'Meta approval caveat missing' "$RELEASE_VALIDATOR_FILE")"
  validator_deferred="$(first_fixed_match 'deferred integration statement missing' "$RELEASE_VALIDATOR_FILE")"
  validator_no_secrets="$(first_fixed_match 'no-secrets release statement missing' "$RELEASE_VALIDATOR_FILE")"
  validator_no_core="$(first_fixed_match 'no-core-modification release statement missing' "$RELEASE_VALIDATOR_FILE")"
  checklist_release_cmd="$(first_fixed_match 'bash scripts/verify_release_package.sh' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_regression_cmd="$(first_fixed_match 'bash scripts/verify_phase2_regressions.sh' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_integrity_cmd="$(first_fixed_match 'php modules/marketing/scripts/verify_phase4_integrity_runtime.php' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_zip_root="$(first_fixed_match 'ZIP root must be exactly `marketing/`' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_nested_root="$(first_fixed_match 'modules/marketing/modules/marketing/marketing.php` is invalid' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_meta="$(first_fixed_match 'Meta approval' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_facebook="$(first_fixed_match 'Facebook and LinkedIn are deferred / inactive' "$PRODUCTION_CHECKLIST_FILE")"
  checklist_linkedin="$checklist_facebook"
  checklist_critical="$(first_fixed_match 'critical_count=0' "$PRODUCTION_CHECKLIST_FILE")"
  manifest_doc_zip_root="$(first_fixed_match 'ZIP package root must be `marketing/`' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_doc_install_path="$(first_fixed_match 'modules/marketing/marketing.php' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_doc_hooks="$(first_fixed_match 'Lifecycle Hooks' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_doc_no_secrets="$(first_fixed_match 'No secrets included' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_doc_no_core="$(first_fixed_match 'No Perfex core modifications' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_doc_deferred="$(first_fixed_match 'deferred / inactive' "$RELEASE_MANIFEST_DOC_FILE")"
  manifest_json_module="$(first_fixed_match '"module_name"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_version="$(first_fixed_match '"version"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_root="$(first_fixed_match '"package_root"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_install="$(first_fixed_match '"install_path"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_active="$(first_fixed_match '"active_integrations"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_deferred="$(first_fixed_match '"deferred_integrations"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_commands="$(first_fixed_match '"validation_commands"' "$RELEASE_MANIFEST_JSON_FILE")"
  manifest_json_security="$(first_fixed_match '"security_notes"' "$RELEASE_MANIFEST_JSON_FILE")"
  forbidden_controller="$(git_changed_path_if_available modules/marketing/controllers/Settings.php)"
  forbidden_model="$(git_changed_path_if_available modules/marketing/models/Marketing_dashboard_model.php)"
  forbidden_library="$(git_changed_path_if_available modules/marketing/libraries/Marketing_whatsapp.php)"
  forbidden_view="$(git_changed_path_if_available modules/marketing/views/settings/index.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$checklist_exists" || -z "$manifest_doc_exists" || -z "$manifest_json_exists" || -z "$validator_checklist" || -z "$validator_manifest_doc" || -z "$validator_manifest_json" || -z "$validator_manifest_keys" || -z "$validator_nested_root" || -z "$validator_meta" || -z "$validator_deferred" || -z "$validator_no_secrets" || -z "$validator_no_core" || -z "$checklist_release_cmd" || -z "$checklist_regression_cmd" || -z "$checklist_integrity_cmd" || -z "$checklist_zip_root" || -z "$checklist_nested_root" || -z "$checklist_meta" || -z "$checklist_facebook" || -z "$checklist_linkedin" || -z "$checklist_critical" || -z "$manifest_doc_zip_root" || -z "$manifest_doc_install_path" || -z "$manifest_doc_hooks" || -z "$manifest_doc_no_secrets" || -z "$manifest_doc_no_core" || -z "$manifest_doc_deferred" || -z "$manifest_json_module" || -z "$manifest_json_version" || -z "$manifest_json_root" || -z "$manifest_json_install" || -z "$manifest_json_active" || -z "$manifest_json_deferred" || -z "$manifest_json_commands" || -z "$manifest_json_security" || -n "$forbidden_controller" || -n "$forbidden_model" || -n "$forbidden_library" || -n "$forbidden_view" ]]; then
    fail \
      "production checklist and release manifests harden release handoff without runtime PHP changes" \
      "expected checklist/doc/json manifest assets, release validator enforcement, required commands, marketing/ root and invalid nested-root warnings, Meta approval caveat, deferred Facebook/LinkedIn statement, no-secrets and no-core-modifications statements, and no runtime PHP diffs; checklist=${checklist_exists:-missing}; manifest_doc=${manifest_doc_exists:-missing}; manifest_json=${manifest_json_exists:-missing}; validator_checklist=${validator_checklist:-missing}; validator_manifest_doc=${validator_manifest_doc:-missing}; validator_manifest_json=${validator_manifest_json:-missing}; validator_manifest_keys=${validator_manifest_keys:-missing}; validator_nested=${validator_nested_root:-missing}; validator_meta=${validator_meta:-missing}; validator_deferred=${validator_deferred:-missing}; validator_no_secrets=${validator_no_secrets:-missing}; validator_no_core=${validator_no_core:-missing}; checklist_release=${checklist_release_cmd:-missing}; checklist_regression=${checklist_regression_cmd:-missing}; checklist_integrity=${checklist_integrity_cmd:-missing}; checklist_zip=${checklist_zip_root:-missing}; checklist_nested=${checklist_nested_root:-missing}; checklist_meta=${checklist_meta:-missing}; checklist_facebook=${checklist_facebook:-missing}; checklist_critical=${checklist_critical:-missing}; manifest_doc_zip=${manifest_doc_zip_root:-missing}; manifest_doc_install=${manifest_doc_install_path:-missing}; manifest_doc_hooks=${manifest_doc_hooks:-missing}; manifest_doc_no_secrets=${manifest_doc_no_secrets:-missing}; manifest_doc_no_core=${manifest_doc_no_core:-missing}; manifest_doc_deferred=${manifest_doc_deferred:-missing}; json_module=${manifest_json_module:-missing}; json_version=${manifest_json_version:-missing}; json_root=${manifest_json_root:-missing}; json_install=${manifest_json_install:-missing}; json_active=${manifest_json_active:-missing}; json_deferred=${manifest_json_deferred:-missing}; json_commands=${manifest_json_commands:-missing}; json_security=${manifest_json_security:-missing}; git_diff_mode=${git_diff_mode:-unknown}; controller_diff=${forbidden_controller:-none}; model_diff=${forbidden_model:-none}; library_diff=${forbidden_library:-none}; view_diff=${forbidden_view:-none}"
    return
  fi

  pass \
    "production checklist and release manifests harden release handoff without runtime PHP changes" \
    "checklist=$checklist_exists | manifest=$manifest_doc_exists | manifest_json=$manifest_json_exists | git_diff_mode=$git_diff_mode"
}

check_release_build_script_hardening() {
  local builder_exists builder_shebang builder_strict builder_zip_name builder_package_root builder_manifest_copy builder_validator_source builder_validator_zip builder_exclude_release builder_exclude_vendor builder_exclude_node builder_exclude_env builder_exclude_tmp
  local readme_build installation_build operations_build checklist_build manifest_build
  local forbidden_controller forbidden_model forbidden_library forbidden_view git_diff_mode

  builder_exists="$(first_match '^#!/usr/bin/env bash' "$RELEASE_BUILDER_FILE")"
  builder_shebang="$builder_exists"
  builder_strict="$(first_fixed_match 'set -euo pipefail' "$RELEASE_BUILDER_FILE")"
  builder_zip_name="$(first_fixed_match 'RELEASE_ZIP="$RELEASE_DIR/${PACKAGE_ROOT}_v${VERSION}.zip"' "$RELEASE_BUILDER_FILE")"
  builder_package_root="$(first_fixed_match 'PACKAGE_ROOT="marketing"' "$RELEASE_BUILDER_FILE")"
  builder_manifest_copy="$(first_fixed_match 'cp "$MANIFEST_FILE" "$STAGE_DIR/release/manifest.json"' "$RELEASE_BUILDER_FILE")"
  builder_validator_source="$(first_fixed_match 'bash "$VALIDATOR_SCRIPT" --source-only' "$RELEASE_BUILDER_FILE")"
  builder_validator_zip="$(first_fixed_match 'bash "$VALIDATOR_SCRIPT" "$RELEASE_ZIP"' "$RELEASE_BUILDER_FILE")"
  builder_exclude_release="$(first_fixed_match '--exclude "release"' "$RELEASE_BUILDER_FILE")"
  builder_exclude_vendor="$(first_fixed_match '--exclude "vendor"' "$RELEASE_BUILDER_FILE")"
  builder_exclude_node="$(first_fixed_match '--exclude "node_modules"' "$RELEASE_BUILDER_FILE")"
  builder_exclude_env="$(first_fixed_match '--exclude ".env"' "$RELEASE_BUILDER_FILE")"
  builder_exclude_tmp="$(first_fixed_match '--exclude "*.tmp"' "$RELEASE_BUILDER_FILE")"
  readme_build="$(first_fixed_match 'bash scripts/build_release_package.sh' "$README_FILE")"
  installation_build="$(first_fixed_match 'bash scripts/build_release_package.sh' "$INSTALLATION_FILE")"
  operations_build="$(first_fixed_match 'bash scripts/build_release_package.sh' "$OPERATIONS_FILE")"
  checklist_build="$(first_fixed_match 'bash scripts/build_release_package.sh' "$PRODUCTION_CHECKLIST_FILE")"
  manifest_build="$(first_fixed_match 'bash scripts/build_release_package.sh' "$RELEASE_MANIFEST_DOC_FILE")"
  forbidden_controller="$(git_changed_path_if_available modules/marketing/controllers/Settings.php)"
  forbidden_model="$(git_changed_path_if_available modules/marketing/models/Marketing_dashboard_model.php)"
  forbidden_library="$(git_changed_path_if_available modules/marketing/libraries/Marketing_whatsapp.php)"
  forbidden_view="$(git_changed_path_if_available modules/marketing/views/settings/index.php)"
  git_diff_mode="$(git_worktree_available && printf 'git-worktree' || printf 'no-git-worktree')"

  if [[ -z "$builder_exists" || -z "$builder_shebang" || -z "$builder_strict" || -z "$builder_zip_name" || -z "$builder_package_root" || -z "$builder_manifest_copy" || -z "$builder_validator_source" || -z "$builder_validator_zip" || -z "$builder_exclude_release" || -z "$builder_exclude_vendor" || -z "$builder_exclude_node" || -z "$builder_exclude_env" || -z "$builder_exclude_tmp" || -z "$readme_build" || -z "$installation_build" || -z "$operations_build" || -z "$checklist_build" || -z "$manifest_build" || -n "$forbidden_controller" || -n "$forbidden_model" || -n "$forbidden_library" || -n "$forbidden_view" ]]; then
    fail \
      "release build script creates auditable marketing zip packages without runtime PHP changes" \
      "expected strict bash release builder, marketing_v1.1.0 zip path, marketing package root, staged release manifest, source and zip validation, forbidden artifact exclusions, docs build-command references, and no runtime PHP diffs; builder=${builder_exists:-missing}; strict=${builder_strict:-missing}; zip_name=${builder_zip_name:-missing}; root=${builder_package_root:-missing}; manifest_copy=${builder_manifest_copy:-missing}; source_validate=${builder_validator_source:-missing}; zip_validate=${builder_validator_zip:-missing}; exclude_release=${builder_exclude_release:-missing}; exclude_vendor=${builder_exclude_vendor:-missing}; exclude_node=${builder_exclude_node:-missing}; exclude_env=${builder_exclude_env:-missing}; exclude_tmp=${builder_exclude_tmp:-missing}; readme=${readme_build:-missing}; installation=${installation_build:-missing}; operations=${operations_build:-missing}; checklist=${checklist_build:-missing}; manifest=${manifest_build:-missing}; git_diff_mode=${git_diff_mode:-unknown}; controller_diff=${forbidden_controller:-none}; model_diff=${forbidden_model:-none}; library_diff=${forbidden_library:-none}; view_diff=${forbidden_view:-none}"
    return
  fi

  pass \
    "release build script creates auditable marketing zip packages without runtime PHP changes" \
    "builder=$builder_exists | strict=$builder_strict | zip=$builder_zip_name | git_diff_mode=$git_diff_mode"
}

check_ses_sns_replay_window_defaults() {
  local install_helper ensure_window ensure_skew
  install_helper="$(first_fixed_match "marketing_get_default_options()" "$INSTALL_FILE")"
  ensure_window="$(first_fixed_match "'marketing_ses_sns_replay_window_seconds' => '900'" "$BOOTSTRAP_FILE")"
  ensure_skew="$(first_fixed_match "'marketing_ses_sns_future_skew_seconds' => '300'" "$BOOTSTRAP_FILE")"

  if [[ -z "$install_helper" || -z "$ensure_window" || -z "$ensure_skew" ]]; then
    fail \
      "SES SNS replay freshness defaults are seeded safely without overwriting existing values" \
      "expected centralized install defaults helper in $INSTALL_FILE and replay/future-skew defaults in $BOOTSTRAP_FILE; install_helper=${install_helper:-missing}; ensure_window=${ensure_window:-missing}; ensure_skew=${ensure_skew:-missing}"
    return
  fi

  pass \
    "SES SNS replay freshness defaults are seeded safely without overwriting existing values" \
    "install_helper=$install_helper | ensure_window=$ensure_window | ensure_skew=$ensure_skew"
}

check_ses_sns_timestamp_freshness_validation() {
  local helper_line parse_line malformed_line stale_line future_line
  helper_line="$(first_match 'function[[:space:]]+validate_timestamp_freshness' "$SES_SNS_FILE")"
  parse_line="$(first_match 'function[[:space:]]+parse_sns_timestamp' "$SES_SNS_FILE")"
  malformed_line="$(first_fixed_match 'SNS Timestamp is missing or malformed.' "$SES_SNS_FILE")"
  stale_line="$(first_fixed_match 'SNS Timestamp is outside the allowed replay window.' "$SES_SNS_FILE")"
  future_line="$(first_fixed_match 'SNS Timestamp is too far in the future.' "$SES_SNS_FILE")"

  if [[ -z "$helper_line" || -z "$parse_line" || -z "$malformed_line" || -z "$stale_line" || -z "$future_line" ]]; then
    fail \
      "SES SNS verifier rejects malformed, stale, and future-skewed timestamps" \
      "expected timestamp freshness and parsing helpers with malformed/stale/future errors in $SES_SNS_FILE; helper=${helper_line:-missing}; parse=${parse_line:-missing}; malformed=${malformed_line:-missing}; stale=${stale_line:-missing}; future=${future_line:-missing}"
    return
  fi

  pass \
    "SES SNS verifier rejects malformed, stale, and future-skewed timestamps" \
    "helper=$helper_line | malformed=$malformed_line | stale=$stale_line"
}

check_ses_sns_replay_options_passed_from_webhooks() {
  local helper_line window_line skew_line
  helper_line="$(first_match 'function[[:space:]]+getSesSnsVerificationOptions' "$WEBHOOKS_FILE")"
  window_line="$(first_fixed_match "'replay_window_seconds' => max(1, (int) get_option('marketing_ses_sns_replay_window_seconds'))" "$WEBHOOKS_FILE")"
  skew_line="$(first_fixed_match "'future_skew_seconds' => max(0, (int) get_option('marketing_ses_sns_future_skew_seconds'))" "$WEBHOOKS_FILE")"

  if [[ -z "$helper_line" || -z "$window_line" || -z "$skew_line" ]]; then
    fail \
      "Webhooks pass replay freshness options into SES SNS verification" \
      "expected replay window and future skew options in $WEBHOOKS_FILE; helper=${helper_line:-missing}; window=${window_line:-missing}; skew=${skew_line:-missing}"
    return
  fi

  pass \
    "Webhooks pass replay freshness options into SES SNS verification" \
    "helper=$helper_line | window=$window_line | skew=$skew_line"
}

check_transient_provider_backoff_safety() {
  local classify_helper transient_flag throttle_flag temp_failure_reason temp_throttle_reason warning_line
  classify_helper="$(first_match 'function[[:space:]]+classify_provider_failure' "$MAILER_FILE")"
  transient_flag="$(first_fixed_match "'transient_failure' => !\$success ? \$failureClassification['transient'] : false" "$MAILER_FILE")"
  throttle_flag="$(first_fixed_match "'provider_throttle' => !\$success ? \$failureClassification['throttle'] : false" "$MAILER_FILE")"
  temp_failure_reason="$(first_fixed_match 'temporary_provider_failure:' "$CAMPAIGNS_FILE")"
  temp_throttle_reason="$(first_fixed_match 'temporary_provider_throttle:' "$CAMPAIGNS_FILE")"
  warning_line="$(first_fixed_match 'provider_throttle_detected' "$CAMPAIGNS_FILE")"

  if [[ -z "$classify_helper" || -z "$transient_flag" || -z "$throttle_flag" || -z "$temp_failure_reason" || -z "$temp_throttle_reason" || -z "$warning_line" ]]; then
    fail \
      "transient provider failures are classified and stop the current batch conservatively" \
      "expected failure classifier in $MAILER_FILE and temporary provider warning flow in $CAMPAIGNS_FILE; helper=${classify_helper:-missing}; transient=${transient_flag:-missing}; throttle=${throttle_flag:-missing}; temp_failure=${temp_failure_reason:-missing}; temp_throttle=${temp_throttle_reason:-missing}; warning=${warning_line:-missing}"
    return
  fi

  pass \
    "transient provider failures are classified and stop the current batch conservatively" \
    "helper=$classify_helper | transient=$transient_flag | warning=$warning_line"
}

check_ses_sns_signature_enforcement() {
  local require_helper production_line verify_call options_helper options_usage
  require_helper="$(first_match 'function[[:space:]]+shouldRequireSesSnsSignatureVerification' "$WEBHOOKS_FILE")"
  production_line="$(first_fixed_match "ENVIRONMENT === 'production'" "$WEBHOOKS_FILE")"
  verify_call="$(first_fixed_match '$this->marketing_ses_sns->verify($rawBody, $headers, $verificationOptions)' "$WEBHOOKS_FILE")"
  options_helper="$(first_match 'function[[:space:]]+getSesSnsVerificationOptions' "$WEBHOOKS_FILE")"
  options_usage="$(first_fixed_match '$verificationOptions = $this->getSesSnsVerificationOptions();' "$WEBHOOKS_FILE")"

  if [[ -z "$require_helper" || -z "$production_line" || -z "$verify_call" || -z "$options_helper" || -z "$options_usage" ]]; then
    fail \
      "SES SNS webhook signature verification is enforced in production" \
      "expected production enforcement helper and verify() call in $WEBHOOKS_FILE; helper=${require_helper:-missing}; production=${production_line:-missing}; verify=${verify_call:-missing}; options_helper=${options_helper:-missing}; options_usage=${options_usage:-missing}"
    return
  fi

  pass \
    "SES SNS webhook signature verification is enforced in production" \
    "helper=$require_helper | production=$production_line | verify=$verify_call"
}

check_ses_sns_verifier_strictness() {
  local version_line topic_line cert_region_line confirm_region_line default_line
  version_line="$(first_fixed_match "SignatureVersion'] !== '1'" "$SES_SNS_FILE")"
  topic_line="$(first_fixed_match "topic_arn_matches" "$SES_SNS_FILE")"
  cert_region_line="$(first_fixed_match "signing_cert_url_matches_region" "$SES_SNS_FILE")"
  confirm_region_line="$(first_fixed_match "is_valid_sns_action_url(\$subscribeUrl, \$expectedRegion)" "$SES_SNS_FILE")"
  default_line="$(first_fixed_match "'marketing_ses_verify_signature' => '1'" "$BOOTSTRAP_FILE")"

  if [[ -z "$version_line" || -z "$topic_line" || -z "$cert_region_line" || -z "$confirm_region_line" || -z "$default_line" ]]; then
    fail \
      "SES SNS verifier checks signature version, region, topic ARN, and safe install defaults" \
      "expected strict verification hooks in $SES_SNS_FILE and default enabled option in $BOOTSTRAP_FILE; version=${version_line:-missing}; topic=${topic_line:-missing}; cert_region=${cert_region_line:-missing}; confirm_region=${confirm_region_line:-missing}; default=${default_line:-missing}"
    return
  fi

  pass \
    "SES SNS verifier checks signature version, region, topic ARN, and safe install defaults" \
    "version=$version_line | cert_region=$cert_region_line | default=$default_line"
}

check_sns_message_id_idempotency() {
  local webhook_line criteria_line lock_line filter_line
  webhook_line="$(first_fixed_match "'sns_message_id' => \$event['sns_message_id'] ?? null" "$WEBHOOKS_FILE")"
  criteria_line="$(first_match 'sns_message_id' "$EVENTS_FILE")"
  lock_line="$(first_match 'function[[:space:]]+acquire_event_lock' "$EVENTS_FILE")"
  filter_line="$(first_fixed_match "where('sns_message_id', \$snsMessageId)" "$EVENTS_FILE")"

  if [[ -z "$webhook_line" || -z "$criteria_line" || -z "$lock_line" || -z "$filter_line" ]]; then
    fail \
      "SNS message id idempotency remains protected" \
      "expected sns_message_id in webhook storage, event dedupe criteria, lock path, and query filters; webhook=${webhook_line:-missing}; criteria=${criteria_line:-missing}; lock=${lock_line:-missing}; filter=${filter_line:-missing}"
    return
  fi

  pass \
    "SNS message id idempotency remains protected" \
    "webhook=$webhook_line | criteria=$criteria_line | filter=$filter_line"
}

check_dependencies

check_no_raw_expression_where_in
check_dashboard_contacts_source
check_no_track_debug
check_ses_tracking_guard
check_tracking_route
check_events_and_dashboard_guards
check_public_links_filtering
check_click_dedupe_window
check_tracking_link_dedupe
check_duplicate_redirect_metric_gate
check_campaign_eligibility_semantics
check_processing_recovery_timeout
check_processing_lease_ownership
check_queue_reservation_transaction_integrity
check_outbound_attempt_schema_and_queue_guard
check_outbound_attempt_assignment_and_retry_reset
check_event_reconciliation_helper_exists
check_event_terminal_state_precedence
check_stale_outbound_identity_guard
check_raw_event_logging_after_reconciliation_skip
check_campaign_worker_lease_schema
check_campaign_worker_lease_acquisition
check_campaign_worker_lease_release_guard
check_campaign_worker_lease_stale_recovery
check_batch_limit_clamp
check_queue_diagnostics_method
check_queue_diagnostics_column_guards
check_queue_diagnostics_stale_worker_count
check_queue_diagnostics_stale_processing_count
check_dashboard_queue_diagnostics_surface
check_dashboard_view_array_guards
check_queue_warning_telemetry_propagation
check_queue_recovery_late_event_safety
check_provider_failure_normalization_helper
check_provider_failure_categories_distinguishable
check_provider_throttle_not_bounce
check_normalized_failure_codes_in_telemetry
check_dashboard_failure_metric_surface
check_dashboard_model_retention_fail_closed
check_reconciliation_skipped_metric_visible
check_queue_pause_option_seeded
check_queue_pause_before_send
check_queue_operator_actions_guarded
check_stale_recovery_operator_guard
check_dashboard_operator_status_surface
check_queue_operator_telemetry_aliases
check_retention_options_seeded
check_retention_minimum_floor
check_retention_delete_limit_bound
check_retention_excludes_audit_critical_events
check_retention_cleanup_scope
check_retention_cleanup_not_auto_cron
check_retention_dashboard_surface
check_campaign_form_setup_guidance
check_campaign_audience_estimate_shell
check_campaign_delivery_settings_shell
check_campaign_form_footer_review_shell
check_campaign_plain_text_placeholder_safety
check_campaign_builder_workspace_layout
check_campaign_show_command_center
check_campaign_send_schedule_guidance
check_campaign_reprepare_recipients_workflow
check_campaign_index_scanability
check_campaign_preview_review_shell
check_no_destructive_event_history_clear
check_analytics_view_array_guards
check_analytics_campaign_performance_reporting
check_analytics_runtime_fail_closed_queries
check_analytics_render_fail_closed
check_analytics_primary_view_normal_layout
check_analytics_fallback_view_standalone
check_analytics_failure_ui_sanitized
check_retention_preserves_unsubscribe_audit_events
check_no_destructive_retention_calls
check_click_aggregate_cleanup_scope
check_click_aggregate_cleanup_bounds
check_events_public_links_fail_closed
check_settings_two_column_layout
check_settings_mobile_responsive_layout
check_settings_license_block_order
check_settings_provider_readiness_panel
check_settings_release_readiness_panel
check_dashboard_operator_ui_structure
check_marketing_reports_surface
check_marketing_reports_cleanup_controls
check_whatsapp_send_auth_diagnostics
check_whatsapp_test_alert_safety
check_whatsapp_token_preserve_and_redaction
check_whatsapp_active_social_scope
check_whatsapp_conversation_schema_readiness
check_conversation_model_optional_column_guards
check_whatsapp_inbound_conversation_metadata
check_whatsapp_lead_conversion_deferred
check_whatsapp_dashboard_visibility
check_whatsapp_conversation_detail_view
check_whatsapp_manual_reply_workflow
check_whatsapp_manual_lead_conversion
check_whatsapp_mark_read_control
check_no_facebook_linkedin_active_ui_or_release_docs
check_release_validator_exists
check_module_metadata_header
check_module_lifecycle_hooks
check_required_config_and_public_endpoints
check_confirmed_unsubscribe_flow
check_false_unsubscribe_repair_script_safety
check_option_repair_idempotence
check_release_forbidden_artifact_detection
check_release_packaging_docs_hardening
check_release_handoff_manifest_assets
check_release_build_script_hardening
check_ses_sns_replay_window_defaults
check_ses_sns_timestamp_freshness_validation
check_ses_sns_replay_options_passed_from_webhooks
check_transient_provider_backoff_safety
check_ses_sns_signature_enforcement
check_ses_sns_verifier_strictness
check_sns_message_id_idempotency
check_inbound_reply_hardening
check_phase4_integrity_diagnostics
check_phase4_integrity_repair

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  printf 'FAILED phase2-regressions %d passed, %d failed\n' "$PASS_COUNT" "$FAIL_COUNT"
  exit 1
fi

printf 'OK phase2-regressions %d checks passed\n' "$PASS_COUNT"
