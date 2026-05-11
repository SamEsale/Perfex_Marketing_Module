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
DASHBOARD_VIEW_FILE="modules/marketing/views/dashboard.php"
MARKETING_CONTROLLER_FILE="modules/marketing/controllers/Marketing.php"
CAMPAIGNS_CONTROLLER_FILE="modules/marketing/controllers/Campaigns.php"
ANALYTICS_CONTROLLER_FILE="modules/marketing/controllers/Analytics.php"
README_FILE="modules/marketing/README.md"
INSTALLATION_FILE="modules/marketing/INSTALLATION.md"
OPERATIONS_FILE="modules/marketing/docs/OPERATIONS.md"
RELEASE_VALIDATOR_FILE="modules/marketing/scripts/verify_release_package.sh"
ANALYTICS_VIEW_FILE="modules/marketing/views/analytics/index.php"

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
  view_line="$(first_fixed_match 'Recent Failure Metrics' "$DASHBOARD_VIEW_FILE")"

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
  controls_line="$(first_fixed_match 'Operator Controls' "$DASHBOARD_VIEW_FILE")"
  actions_line="$(first_fixed_match 'Recent Operator Actions' "$DASHBOARD_VIEW_FILE")"
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
  local cleanup_helper_line event_delete_line webhook_delete_line public_links_line suppressions_line
  cleanup_helper_line="$(first_match 'function[[:space:]]+run_retention_cleanup' "$EVENTS_FILE")"
  event_delete_line="$(first_fixed_match "\$this->db->delete(\$this->table);" "$EVENTS_FILE")"
  webhook_delete_line="$(first_fixed_match "\$this->db->delete(\$this->webhookLogTable);" "$EVENTS_FILE")"
  public_links_line="$(first_fixed_match 'marketing_public_links' "$EVENTS_FILE")"
  suppressions_line="$(first_fixed_match 'marketing_suppressions' "$EVENTS_FILE")"

  if [[ -z "$cleanup_helper_line" || -z "$event_delete_line" || -z "$webhook_delete_line" || -n "$public_links_line" || -n "$suppressions_line" ]]; then
    fail \
      "retention cleanup only targets events and webhook logs, not campaigns, recipients, suppressions, or public links" \
      "expected explicit retention helper plus event/webhook deletes only in $EVENTS_FILE; helper=${cleanup_helper_line:-missing}; event_delete=${event_delete_line:-missing}; webhook_delete=${webhook_delete_line:-missing}; public_links=${public_links_line:-none}; suppressions=${suppressions_line:-none}"
    return
  fi

  pass \
    "retention cleanup only targets events and webhook logs, not campaigns, recipients, suppressions, or public links" \
    "helper=$cleanup_helper_line | event_delete=$event_delete_line | webhook_delete=$webhook_delete_line"
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
  local dashboard_line view_line candidate_line
  dashboard_line="$(first_match "'retention_diagnostics'[[:space:]]*=>" "$DASHBOARD_FILE")"
  view_line="$(first_fixed_match 'Telemetry Retention' "$DASHBOARD_VIEW_FILE")"
  candidate_line="$(first_fixed_match 'events_cleanup_candidate_preview' "$DASHBOARD_VIEW_FILE")"

  if [[ -z "$dashboard_line" || -z "$view_line" || -z "$candidate_line" ]]; then
    fail \
      "dashboard diagnostics surface retention status and cleanup candidate previews" \
      "expected retention diagnostics in $DASHBOARD_FILE and view rendering in $DASHBOARD_VIEW_FILE; dashboard=${dashboard_line:-missing}; view=${view_line:-missing}; candidate=${candidate_line:-missing}"
    return
  fi

  pass \
    "dashboard diagnostics surface retention status and cleanup candidate previews" \
    "dashboard=$dashboard_line | view=$view_line | candidate=$candidate_line"
}

check_no_destructive_event_history_clear() {
  local controller_post_line controller_model_line controller_flag_line model_guard_line safe_button_line destructive_button_line
  controller_post_line="$(first_fixed_match "if (\$this->input->post('run_retention_cleanup')) {" "$ANALYTICS_CONTROLLER_FILE")"
  controller_model_line="$(first_fixed_match '$summary = $this->marketing_events_model->run_retention_cleanup();' "$ANALYTICS_CONTROLLER_FILE")"
  controller_flag_line="$(first_fixed_match "\$data['can_run_retention_cleanup'] = staff_can('settings', 'marketing');" "$ANALYTICS_CONTROLLER_FILE")"
  model_guard_line="$(first_fixed_match 'return false;' "$EVENTS_FILE")"
  safe_button_line="$(first_fixed_match 'Run Retention Cleanup' "$ANALYTICS_VIEW_FILE")"
  destructive_button_line="$(first_fixed_match 'marketing_clear_event_history' "$ANALYTICS_VIEW_FILE")"

  if [[ -z "$controller_post_line" || -z "$controller_model_line" || -z "$controller_flag_line" || -z "$model_guard_line" || -z "$safe_button_line" || -n "$destructive_button_line" ]]; then
    fail \
      "analytics exposes only safe bounded retention cleanup and keeps destructive history clearing disabled" \
      "expected retention cleanup POST path, bounded model call, permission flag, fail-closed clear_event_history(), visible safe button, and no destructive clear button; post=${controller_post_line:-missing}; model_call=${controller_model_line:-missing}; flag=${controller_flag_line:-missing}; model=${model_guard_line:-missing}; safe_button=${safe_button_line:-missing}; destructive_button=${destructive_button_line:-unexpected}"
    return
  fi

  pass \
    "analytics exposes only safe bounded retention cleanup and keeps destructive history clearing disabled" \
    "post=$controller_post_line | model_call=$controller_model_line | safe_button=$safe_button_line"
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
check_queue_warning_telemetry_propagation
check_provider_failure_normalization_helper
check_provider_failure_categories_distinguishable
check_provider_throttle_not_bounce
check_normalized_failure_codes_in_telemetry
check_dashboard_failure_metric_surface
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
check_no_destructive_event_history_clear
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
