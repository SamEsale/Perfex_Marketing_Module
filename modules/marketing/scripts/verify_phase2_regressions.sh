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
SCHEMA_FILE="modules/marketing/libraries/Marketing_schema.php"
BOOTSTRAP_FILE="modules/marketing/marketing.php"
WEBHOOKS_FILE="modules/marketing/controllers/Webhooks.php"
SES_SNS_FILE="modules/marketing/libraries/Marketing_ses_sns.php"
INSTALL_FILE="modules/marketing/install.php"
MAILER_FILE="modules/marketing/libraries/Marketing_mailer.php"

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

check_transient_provider_backoff_safety() {
  local classify_helper transient_flag throttle_flag temp_failure_reason temp_throttle_reason warning_line
  classify_helper="$(first_match 'function[[:space:]]+classify_provider_failure' "$MAILER_FILE")"
  transient_flag="$(first_fixed_match "'transient_failure' => !\$success ? \$this->classify_provider_failure(\$failureReason)['transient'] : false" "$MAILER_FILE")"
  throttle_flag="$(first_fixed_match "'provider_throttle' => !\$success ? \$this->classify_provider_failure(\$failureReason)['throttle'] : false" "$MAILER_FILE")"
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
  default_line="$(first_fixed_match "'marketing_ses_verify_signature' => '1'" "$INSTALL_FILE")"

  if [[ -z "$version_line" || -z "$topic_line" || -z "$cert_region_line" || -z "$confirm_region_line" || -z "$default_line" ]]; then
    fail \
      "SES SNS verifier checks signature version, region, topic ARN, and safe install defaults" \
      "expected strict verification hooks in $SES_SNS_FILE and default enabled option in $INSTALL_FILE; version=${version_line:-missing}; topic=${topic_line:-missing}; cert_region=${cert_region_line:-missing}; confirm_region=${confirm_region_line:-missing}; default=${default_line:-missing}"
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
check_transient_provider_backoff_safety
check_ses_sns_signature_enforcement
check_ses_sns_verifier_strictness
check_sns_message_id_idempotency

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  printf 'FAILED phase2-regressions %d passed, %d failed\n' "$PASS_COUNT" "$FAIL_COUNT"
  exit 1
fi

printf 'OK phase2-regressions %d checks passed\n' "$PASS_COUNT"
