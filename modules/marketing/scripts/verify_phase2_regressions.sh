#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT_DIR"

TRACK_FILE="modules/marketing/controllers/Track.php"
EVENTS_FILE="modules/marketing/models/Marketing_events_model.php"
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
DASHBOARD_VIEW_FILE="modules/marketing/views/dashboard.php"
MARKETING_CONTROLLER_FILE="modules/marketing/controllers/Marketing.php"
CAMPAIGNS_CONTROLLER_FILE="modules/marketing/controllers/Campaigns.php"
ANALYTICS_CONTROLLER_FILE="modules/marketing/controllers/Analytics.php"
SETTINGS_CONTROLLER_FILE="modules/marketing/controllers/Settings.php"
README_FILE="modules/marketing/README.md"
INSTALLATION_FILE="modules/marketing/INSTALLATION.md"
OPERATIONS_FILE="modules/marketing/docs/OPERATIONS.md"
RELEASE_VALIDATOR_FILE="modules/marketing/scripts/verify_release_package.sh"
CAMPAIGN_FORM_VIEW_FILE="modules/marketing/views/campaigns/form.php"
CAMPAIGN_SHOW_VIEW_FILE="modules/marketing/views/campaigns/show.php"
CAMPAIGN_INDEX_VIEW_FILE="modules/marketing/views/campaigns/index.php"
CAMPAIGN_PREVIEW_VIEW_FILE="modules/marketing/views/campaigns/preview.php"
ANALYTICS_VIEW_FILE="modules/marketing/views/analytics/index.php"
ANALYTICS_FALLBACK_VIEW_FILE="modules/marketing/views/analytics/fallback.php"
SETTINGS_VIEW_FILE="modules/marketing/views/settings/index.php"

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
  local shared_helper queue_helper public_snapshot queue_usage
  shared_helper="$(first_match 'function[[:space:]]+build_campaign_eligibility_snapshot' "$CAMPAIGNS_FILE")"
  queue_helper="$(first_match 'function[[:space:]]+get_campaign_queue_targeting_snapshot' "$CAMPAIGNS_FILE")"
  public_snapshot="$(first_fixed_match 'return $this->build_campaign_eligibility_snapshot($campaign, $overrideRules, false);' "$CAMPAIGNS_FILE")"
  queue_usage="$(first_fixed_match '$snapshot = $this->get_campaign_queue_targeting_snapshot($campaign);' "$CAMPAIGNS_FILE")"

  if [[ -z "$shared_helper" || -z "$queue_helper" || -z "$public_snapshot" || -z "$queue_usage" ]]; then
    fail \
      "campaign eligibility uses one shared helper and queue preparation excludes existing recipients separately" \
      "expected shared eligibility helper in $CAMPAIGNS_FILE; shared=${shared_helper:-missing}; queue_helper=${queue_helper:-missing}; public=${public_snapshot:-missing}; queue=${queue_usage:-missing}"
    return
  fi

  pass \
    "campaign eligibility uses one shared helper and queue preparation excludes existing recipients separately" \
    "shared_helper=$shared_helper | public_snapshot=$public_snapshot | queue_usage=$queue_usage"
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
  local status_guard_line block_reason_line failure_block_line
  status_guard_line="$(first_fixed_match "['failed', 'suppressed', 'skipped']" "$CAMPAIGNS_FILE")"
  block_reason_line="$(first_fixed_match 'terminal_status_blocks_event' "$CAMPAIGNS_FILE")"
  failure_block_line="$(first_fixed_match 'terminal_status_blocks_failure' "$CAMPAIGNS_FILE")"

  if [[ -z "$status_guard_line" || -z "$block_reason_line" || -z "$failure_block_line" ]]; then
    fail \
      "delivery, open, and click cannot overwrite stronger terminal recipient states" \
      "expected terminal status precedence guards in $CAMPAIGNS_FILE; statuses=${status_guard_line:-missing}; engagement=${block_reason_line:-missing}; failure=${failure_block_line:-missing}"
    return
  fi

  pass \
    "delivery, open, and click cannot overwrite stronger terminal recipient states" \
    "statuses=$status_guard_line | engagement=$block_reason_line | failure=$failure_block_line"
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
  view_heading_line="$(first_fixed_match 'Queue Diagnostics' "$DASHBOARD_VIEW_FILE")"
  view_warning_line="$(first_fixed_match "recent_warning_counts" "$DASHBOARD_VIEW_FILE")"
  controller_line="$(first_fixed_match "\$this->load->view('marketing/dashboard', \$data);" "$MARKETING_CONTROLLER_FILE")"

  if [[ -z "$view_heading_line" || -z "$view_warning_line" || -z "$controller_line" ]]; then
    fail \
      "existing dashboard route consumes queue diagnostics without route changes" \
      "expected queue diagnostics section in dashboard view and existing dashboard controller route; heading=${view_heading_line:-missing}; warnings=${view_warning_line:-missing}; controller=${controller_line:-missing}"
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
  local text_line placeholder_line state_line html_line unsafe_line
  text_line="$(first_fixed_match 'id="text_content"' "$CAMPAIGN_FORM_VIEW_FILE")"
  placeholder_line="$(first_fixed_match 'marketing_campaign_text_content_placeholder' "$CAMPAIGN_FORM_VIEW_FILE")"
  state_line="$(first_fixed_match 'id="builder_state"' "$CAMPAIGN_FORM_VIEW_FILE")"
  html_line="$(first_fixed_match 'id="html_content"' "$CAMPAIGN_FORM_VIEW_FILE")"
  unsafe_line="$(first_fixed_match 'Your headline goes here Introduce the offer, explain the value, and set up the email. Call to Action' "$CAMPAIGN_FORM_VIEW_FILE")"

  if [[ -z "$text_line" || -z "$placeholder_line" || -z "$state_line" || -z "$html_line" || -n "$unsafe_line" ]]; then
    fail \
      "campaign plain-text fallback stays placeholder-based and avoids hardcoded demo content in the form view" \
      "expected text_content field, placeholder marker, builder_state/html_content fields, and no unsafe demo copy in $CAMPAIGN_FORM_VIEW_FILE; text=${text_line:-missing}; placeholder=${placeholder_line:-missing}; builder_state=${state_line:-missing}; html=${html_line:-missing}; unsafe_demo=${unsafe_line:-present}"
    return
  fi

  pass \
    "campaign plain-text fallback stays placeholder-based and avoids hardcoded demo content in the form view" \
    "text=$text_line | placeholder=$placeholder_line | builder_state=$state_line | html=$html_line"
}

check_campaign_show_command_center() {
  local center_line marker_line title_line grid_line actions_line next_step_line readiness_line
  center_line="$(first_fixed_match 'marketing-campaign-command-center' "$CAMPAIGN_SHOW_VIEW_FILE")"
  marker_line="$(first_fixed_match 'data-marketing-campaign-command-center="1"' "$CAMPAIGN_SHOW_VIEW_FILE")"
  title_line="$(first_fixed_match 'marketing_campaign_command_center' "$CAMPAIGN_SHOW_VIEW_FILE")"
  grid_line="$(first_fixed_match 'marketing-campaign-command-grid' "$CAMPAIGN_SHOW_VIEW_FILE")"
  actions_line="$(first_fixed_match 'marketing-campaign-send-workflow' "$CAMPAIGN_SHOW_VIEW_FILE")"
  next_step_line="$(first_fixed_match 'marketing_campaign_command_next_step' "$CAMPAIGN_SHOW_VIEW_FILE")"
  readiness_line="$(first_fixed_match 'marketing_campaign_readiness_status' "$CAMPAIGN_SHOW_VIEW_FILE")"

  if [[ -z "$center_line" || -z "$marker_line" || -z "$title_line" || -z "$grid_line" || -z "$actions_line" || -z "$next_step_line" || -z "$readiness_line" ]]; then
    fail \
      "campaign show page surfaces a compact command center using existing readiness and action markers" \
      "expected command center marker, title, grid, workflow wrapper, next-step label, and readiness label in $CAMPAIGN_SHOW_VIEW_FILE; center=${center_line:-missing}; marker=${marker_line:-missing}; title=${title_line:-missing}; grid=${grid_line:-missing}; workflow=${actions_line:-missing}; next_step=${next_step_line:-missing}; readiness=${readiness_line:-missing}"
    return
  fi

  pass \
    "campaign show page surfaces a compact command center using existing readiness and action markers" \
    "center=$center_line | marker=$marker_line | title=$title_line | grid=$grid_line | workflow=$actions_line | next_step=$next_step_line"
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

check_dashboard_operator_ui_structure() {
  local controls_line health_line warnings_line failure_line audit_line retention_line compact_grid_line
  controls_line="$(first_fixed_match 'Queue Controls' "$DASHBOARD_VIEW_FILE")"
  health_line="$(first_fixed_match 'Queue Health' "$DASHBOARD_VIEW_FILE")"
  warnings_line="$(first_fixed_match 'Recent Warnings' "$DASHBOARD_VIEW_FILE")"
  failure_line="$(first_fixed_match 'Failure Metrics' "$DASHBOARD_VIEW_FILE")"
  audit_line="$(first_fixed_match 'Operator Audit' "$DASHBOARD_VIEW_FILE")"
  retention_line="$(first_fixed_match 'Telemetry Retention' "$DASHBOARD_VIEW_FILE")"
  compact_grid_line="$(first_fixed_match 'marketing-ops-compact-grid' "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$controls_line" || -z "$health_line" || -z "$warnings_line" || -z "$failure_line" || -z "$audit_line" || -z "$retention_line" || -z "$compact_grid_line" ]]; then
    fail \
      "dashboard operator controls are rendered as structured commercial UI sections" \
      "expected Queue Controls, Queue Health, Recent Warnings, Failure Metrics, Operator Audit, Telemetry Retention, and compact diagnostics grid markup in $DASHBOARD_VIEW_FILE; controls=${controls_line:-missing}; health=${health_line:-missing}; warnings=${warnings_line:-missing}; failure=${failure_line:-missing}; audit=${audit_line:-missing}; retention=${retention_line:-missing}; compact_grid=${compact_grid_line:-missing}"
    return
  fi

  pass \
    "dashboard operator controls are rendered as structured commercial UI sections" \
    "controls=$controls_line | health=$health_line | compact_grid=$compact_grid_line"
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
  local preserve_line save_line redact_line
  preserve_line="$(first_fixed_match "if (\$accessToken !== '') {" "$SETTINGS_CONTROLLER_FILE")"
  save_line="$(first_fixed_match "\$settings['marketing_whatsapp_access_token'] = \$accessToken;" "$SETTINGS_CONTROLLER_FILE")"
  redact_line="$(first_fixed_match "trim((string) get_option('marketing_whatsapp_access_token'))" "$CAMPAIGNS_FILE")"

  if [[ -z "$preserve_line" || -z "$save_line" || -z "$redact_line" ]]; then
    fail \
      "WhatsApp blank-token preservation remains intact and token redaction stays in failure sanitization" \
      "expected blank-token preserve guard in modules/marketing/controllers/Settings.php and token redaction in $CAMPAIGNS_FILE; preserve=${preserve_line:-missing}; save=${save_line:-missing}; redact=${redact_line:-missing}"
    return
  fi

  pass \
    "WhatsApp blank-token preservation remains intact and token redaction stays in failure sanitization" \
    "preserve=$preserve_line | save=$save_line | redact=$redact_line"
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
  local msg_provider msg_type msg_thread msg_status msg_media msg_context
  conv_channel="$(first_fixed_match "'channel' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
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
  msg_provider="$(first_fixed_match "'provider' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_type="$(first_fixed_match "'message_type' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_thread="$(first_fixed_match "'provider_thread_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_status="$(first_fixed_match "'provider_status' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_media="$(first_fixed_match "'media_meta_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true]" "$SCHEMA_FILE")"
  msg_context="$(first_fixed_match "'context_provider_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true]" "$SCHEMA_FILE")"

  if [[ -z "$conv_channel" || -z "$conv_provider" || -z "$conv_phone" || -z "$conv_norm_phone" || -z "$conv_thread" || -z "$conv_lead" || -z "$conv_assigned" || -z "$conv_unread" || -z "$conv_inbound" || -z "$conv_outbound" || -z "$conv_notes" || -z "$msg_provider" || -z "$msg_type" || -z "$msg_thread" || -z "$msg_status" || -z "$msg_media" || -z "$msg_context" ]]; then
    fail \
      "WhatsApp-ready conversation and message schema fields are repairable and bootstrap-managed" \
      "expected new conversation/message fields in $SCHEMA_FILE; conv_channel=${conv_channel:-missing}; conv_provider=${conv_provider:-missing}; conv_phone=${conv_phone:-missing}; conv_thread=${conv_thread:-missing}; conv_unread=${conv_unread:-missing}; msg_type=${msg_type:-missing}; msg_media=${msg_media:-missing}; msg_context=${msg_context:-missing}"
    return
  fi

  pass \
    "WhatsApp-ready conversation and message schema fields are repairable and bootstrap-managed" \
    "conv_channel=$conv_channel | conv_unread=$conv_unread | msg_type=$msg_type | msg_media=$msg_media"
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
  local lock_line channel_line provider_line phone_line type_line context_line media_line event_payload_line
  lock_line="$(first_fixed_match '$eventLock = $this->marketing_events_model->acquire_event_lock($dedupeCriteria);' "$WEBHOOKS_FILE")"
  channel_line="$(first_fixed_match "'channel' => 'whatsapp'" "$WEBHOOKS_FILE")"
  provider_line="$(first_fixed_match "'provider' => 'whatsapp_cloud'" "$WEBHOOKS_FILE")"
  phone_line="$(first_fixed_match "'normalized_phone' => \$phone" "$WEBHOOKS_FILE")"
  type_line="$(first_fixed_match "'message_type' => \$messageType !== '' ? \$messageType : 'text'" "$WEBHOOKS_FILE")"
  context_line="$(first_fixed_match "'context_provider_message_id' => \$contextProviderMessageId !== '' ? \$contextProviderMessageId : null" "$WEBHOOKS_FILE")"
  media_line="$(first_fixed_match "'media_meta_json' => \$mediaMeta" "$WEBHOOKS_FILE")"
  event_payload_line="$(first_fixed_match "'provider_thread_id' => \$providerThreadId !== '' ? \$providerThreadId : null" "$WEBHOOKS_FILE")"

  if [[ -z "$lock_line" || -z "$channel_line" || -z "$provider_line" || -z "$phone_line" || -z "$type_line" || -z "$context_line" || -z "$media_line" || -z "$event_payload_line" ]]; then
    fail \
      "WhatsApp inbound persistence passes channel, provider, phone, and message metadata into the conversation layer" \
      "expected event lock plus WhatsApp channel/provider/phone/message metadata in $WEBHOOKS_FILE; lock=${lock_line:-missing}; channel=${channel_line:-missing}; provider=${provider_line:-missing}; phone=${phone_line:-missing}; type=${type_line:-missing}; context=${context_line:-missing}; media=${media_line:-missing}; thread=${event_payload_line:-missing}"
    return
  fi

  pass \
    "WhatsApp inbound persistence passes channel, provider, phone, and message metadata into the conversation layer" \
    "lock=$lock_line | channel=$channel_line | type=$type_line | media=$media_line"
}

check_whatsapp_lead_conversion_deferred() {
  local whatsapp_block route_line csrf_line
  whatsapp_block="$(sed -n '/protected function storeWhatsappInboundMessage/,/protected function storeWhatsappStatusEvent/p' "$WEBHOOKS_FILE")"
  route_line="$(first_fixed_match "\$route['marketing/webhooks/whatsapp'] = 'webhooks/whatsapp';" "$ROUTES_FILE")"
  csrf_line="$(first_fixed_match "'marketing/webhooks/whatsapp'" "$CSRF_FILE")"

  if printf '%s\n' "$whatsapp_block" | grep -q 'convert_reply_to_lead'; then
    fail \
      "automatic WhatsApp lead conversion remains deferred in Phase 4A patch 1" \
      "unexpected convert_reply_to_lead call found inside storeWhatsappInboundMessage() in $WEBHOOKS_FILE"
    return
  fi

  if [[ -z "$route_line" || -z "$csrf_line" ]]; then
    fail \
      "WhatsApp webhook route and csrf posture remain unchanged while lead conversion is deferred" \
      "expected whatsapp route and csrf exclusion in module config; route=${route_line:-missing}; csrf=${csrf_line:-missing}"
    return
  fi

  pass \
    "automatic WhatsApp lead conversion remains deferred in Phase 4A patch 1" \
    "route=$route_line | csrf=$csrf_line"
}

check_no_facebook_linkedin_active_ui_or_release_docs() {
  local settings_fb settings_li dashboard_fb dashboard_li readme_fb readme_li install_fb install_li ops_fb ops_li
  settings_fb="$(first_match 'Facebook|facebook' "$SETTINGS_VIEW_FILE")"
  settings_li="$(first_match 'LinkedIn|linkedin' "$SETTINGS_VIEW_FILE")"
  dashboard_fb="$(first_match 'Facebook|facebook' "$DASHBOARD_VIEW_FILE")"
  dashboard_li="$(first_match 'LinkedIn|linkedin' "$DASHBOARD_VIEW_FILE")"
  readme_fb="$(first_match 'Facebook|facebook' "$README_FILE")"
  readme_li="$(first_match 'LinkedIn|linkedin' "$README_FILE")"
  install_fb="$(first_match 'Facebook|facebook' "$INSTALLATION_FILE")"
  install_li="$(first_match 'LinkedIn|linkedin' "$INSTALLATION_FILE")"
  ops_fb="$(first_match 'Facebook|facebook' "$OPERATIONS_FILE")"
  ops_li="$(first_match 'LinkedIn|linkedin' "$OPERATIONS_FILE")"

  if [[ -n "$settings_fb" || -n "$settings_li" || -n "$dashboard_fb" || -n "$dashboard_li" || -n "$readme_fb" || -n "$readme_li" || -n "$install_fb" || -n "$install_li" || -n "$ops_fb" || -n "$ops_li" ]]; then
    fail \
      "Facebook and LinkedIn do not appear in active settings/dashboard UI or release-facing docs" \
      "expected no Facebook/LinkedIn references in settings, dashboard, README, INSTALLATION, or OPERATIONS; settings_fb=${settings_fb:-none}; settings_li=${settings_li:-none}; dashboard_fb=${dashboard_fb:-none}; dashboard_li=${dashboard_li:-none}; readme_fb=${readme_fb:-none}; readme_li=${readme_li:-none}; install_fb=${install_fb:-none}; install_li=${install_li:-none}; ops_fb=${ops_fb:-none}; ops_li=${ops_li:-none}"
    return
  fi

  pass \
    "Facebook and LinkedIn do not appear in active settings/dashboard UI or release-facing docs" \
    "settings/dashboard/release docs are WhatsApp-only"
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
check_campaign_index_scanability
check_campaign_preview_review_shell
check_no_destructive_event_history_clear
check_analytics_view_array_guards
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
check_dashboard_operator_ui_structure
check_whatsapp_send_auth_diagnostics
check_whatsapp_test_alert_safety
check_whatsapp_token_preserve_and_redaction
check_whatsapp_active_social_scope
check_whatsapp_conversation_schema_readiness
check_conversation_model_optional_column_guards
check_whatsapp_inbound_conversation_metadata
check_whatsapp_lead_conversion_deferred
check_no_facebook_linkedin_active_ui_or_release_docs
check_release_validator_exists
check_module_metadata_header
check_module_lifecycle_hooks
check_required_config_and_public_endpoints
check_option_repair_idempotence
check_release_forbidden_artifact_detection
check_ses_sns_replay_window_defaults
check_ses_sns_timestamp_freshness_validation
check_ses_sns_replay_options_passed_from_webhooks
check_transient_provider_backoff_safety
check_ses_sns_signature_enforcement
check_ses_sns_verifier_strictness
check_sns_message_id_idempotency

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  printf 'FAILED phase2-regressions %d passed, %d failed\n' "$PASS_COUNT" "$FAIL_COUNT"
  exit 1
fi

printf 'OK phase2-regressions %d checks passed\n' "$PASS_COUNT"
