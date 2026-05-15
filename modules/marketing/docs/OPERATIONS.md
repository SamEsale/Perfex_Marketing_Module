# Operations

## Scope

This package is a **Perfex CRM module** located at `modules/marketing`. It is not standalone software.

## Compatibility

- Compatible target: `Perfex CRM 3.4.x`
- Tested on: `Perfex CRM 3.4.1`

## Deployment Approach

- Use staging before production
- Back up the database and current module ZIP before upgrades
- Do not modify core Perfex files to deploy this module
- Confirm module webhook URLs after each environment move or domain change

## Required Runtime Dependencies

- Perfex cron must be active for campaign execution
- SMTP / SES settings must be valid before email sending
- Module click tracking requires the public route `marketing/track/c/{token}` to be reachable from recipient devices
- Module unsubscribe handling requires the public route `marketing/unsubscribe/{token}` to be reachable from recipient devices
- WhatsApp Business is the only built-in social channel integration in this package.
- WhatsApp sending requires a Meta-approved WhatsApp Business app, an active Phone Number ID, a valid non-expired access token, and approved templates
- Meta approval and business-asset access remain external prerequisites. This module does not bypass Meta review, token issuance, or asset permission checks.

## Safe Installation Flow

1. Upload the `marketing` module ZIP through `Setup -> Modules`.
   The ZIP archive root must be `marketing/`, and the installed module path must resolve to `modules/marketing/marketing.php`.
2. Activate the module.
3. Open `Marketing -> Settings`.
4. Review `Schema Health`.
   Activation uses safe bootstrap only. It creates missing module-owned tables, safe columns, and safe indexes without dropping or renaming live tables.
5. Save the General, SMTP / SES, Reply-to-Lead, WhatsApp, and License panels as needed.
6. Confirm webhook URLs are reachable from the public internet when external providers are in use.
7. Confirm cron is running before any scheduled or batch send.

## Upgrade Flow

1. Back up the database and current module package.
2. Replace the module with the new ZIP.
3. Activate or reload the module.
4. Review `Schema Health`.
   If legacy duplicate tables such as accidental double-prefix `tbltblmarketing_*` tables are reported, treat them as manual review items only.
   Use schema repair only for idempotent reconciliation of missing module-owned schema.
5. Re-check SMTP / SES, Reply-to-Lead, WhatsApp, and License settings.
6. Resume campaign operations only after cron and webhook checks are clean.

## Regression Validation

- From the standalone module root, run `bash scripts/verify_phase2_regressions.sh` before building a release ZIP and again before deploying to production.
- From that same standalone module root, run `bash scripts/verify_release_package.sh` before shipping any commercial ZIP.
- The script is intended to run on a standard Ubuntu host without `rg` / `ripgrep`.
- Expected success result should match the current bounded regression count reported on the release branch.

## Pre-Release Validation

- Build the commercial package from the standalone module root with `bash scripts/build_release.sh`
- Validate the package from that same root with:
  - `bash scripts/verify_phase2_regressions.sh`
  - `bash scripts/verify_release_package.sh`
- Confirm the ZIP archive root is exactly `marketing/`
- Confirm the installed module path will become `modules/marketing/marketing.php`
- Reject any package that would expand to `modules/marketing/modules/marketing/marketing.php`
- Review `Marketing -> Settings -> Provider Readiness`
- Review `Marketing -> Settings -> Release Readiness`
- Run runtime integrity diagnostics from the deployed CRM root with:
  - `php modules/marketing/scripts/verify_phase4_integrity_runtime.php`

## Deployment Sanity Checks

- Confirm `Marketing -> Settings -> Provider Readiness` reflects the expected saved provider state
- Confirm `Marketing -> Settings -> Release Readiness` is available for packaging and support handoff
- Confirm `Marketing -> Dashboard -> Integrity Health` remains visible and read-only
- Confirm rendered webhook URLs match the deployed base URL
- Confirm cron is active before resuming campaign send or schedule operations

## Rollback Note

- Keep the previous known-good module ZIP and a database backup before replacing the deployed package
- Roll back by restoring the prior module ZIP and matching database backup together when a release must be reversed
- Do not treat telemetry cleanup or report cleanup as a substitute for rollback

## Uninstall Notes

- Module uninstall must not be used as a substitute for a backup
- Uninstall should be tested on staging first
- License state does not block uninstall
- Core Perfex files remain untouched

## Security Guarantees

- No token rendering after save
- No intentional secret logging
- No destructive schema operations
- Schema repair only adds missing safe module-owned elements
- Legacy duplicate tables are reported for manual review and are never dropped automatically
- Public click and unsubscribe links use opaque module tokens instead of raw recipient identifiers

## Commercial Licensing Operations

- License status lives under `Marketing -> Settings -> License & Updates`
- Production license API base URL is `https://license.spitol.com/api`
- Configuration order is `MARKETING_LICENSE_API_BASE_URL` constant first, then the `marketing_license_api_base_url` module option, then the built-in production default
- If the license API base URL is empty, status is `unconfigured`
- If the license server is unreachable after a previously active validation, the module grants a `7` day grace period
- Licensing only gates commercial actions such as campaign sending, WhatsApp campaign sending, and large recipient-batch preparation
- Diagnostics, settings access, activation, uninstall, and schema repair remain available

## Troubleshooting

- If campaign send is blocked, review `License & Updates` first
- If campaigns do not process, confirm cron is running and `Campaign Delivery Enabled` is on
- If recipients remain in `processing`, allow the stale-processing timeout to elapse before manual retry or reset. Active `processing` rows are no longer requeued immediately on the next queue run.
- Queue processing now assigns a per-worker recipient lease token. If schema bootstrap is incomplete after deploy, queue processing will fail closed until the recipient lease column is added by the module bootstrap.
- If queue processing logs `recipient_reservation_failed` or `recipient_reservation_conflict`, treat the batch as unreserved and investigate DB write errors or overlapping workers before retrying.
- Queue processing now uses a campaign worker lease in addition to recipient reservation. If warnings include `campaign_{id}_worker_locked`, another worker still owns that campaign lease or the previous lease has not yet gone stale.
- Use the Marketing dashboard queue diagnostics section to inspect current worker leases, stale processing counts, recent recovered rows, and recent queue warning payloads without relying only on application logs.
- That same dashboard diagnostics section now surfaces normalized recent failure metrics such as provider throttling, transient failures, rendering failures, recipient suppressions, and reconciliation-skipped webhook events while keeping raw provider reason text in event payloads.
- Telemetry retention is explicit-only by default. The dashboard surfaces current event and webhook-log retention windows, the hard 30-day floor, the 500-row per-run cap, and bounded cleanup candidate previews without automatically deleting rows during cron.
- Running retention cleanup from Analytics preserves audit-critical events and safely resets only stale tracking-link click aggregates that are older than the retention window and not tied to currently active sending campaigns.
- Retention cleanup must never target campaigns, recipients, suppressions, or public unsubscribe/tracking link records. Only non-critical marketing events and webhook logs are eligible for age-based cleanup.
- Phase 4A schema preparation now ensures WhatsApp-ready conversation and message fields such as channel/provider, normalized phone, provider thread IDs, message type, and media metadata. Automatic WhatsApp lead conversion is still intentionally deferred until webhook POST authenticity and stronger idempotency hardening are in place.
- If queue processing warns `outbound_attempt_replay_blocked`, the module detected a prior uncertain outbound attempt for that recipient and intentionally blocked automatic resend. Use failed-recipient retry only after confirming the earlier attempt outcome.
- If campaign send warnings include `provider_throttle_detected` or `transient_delivery_failure`, treat the current batch as paused by the provider. The module marks the affected recipient with a `temporary_provider_*` failure reason and stops the rest of that batch instead of hammering SES/SMTP.
- If campaign clicks are not appearing, confirm delivered links were rewritten to `marketing/track/c/{token}` and that the redirect endpoint returns `302`
- If unsubscribe actions are not taking effect, confirm the recipient hit the module unsubscribe URL and that a suppression row was created or refreshed
- If SES / SNS events do not appear, confirm the webhook URL, provider-side subscription configuration, and that production deliveries are signed by AWS SNS.
- If SES / SNS signature verification fails, confirm the signed webhook message `TopicArn` matches the configured topic when you use a dedicated topic, and confirm the configured SES region matches the SNS certificate and subscribe URLs.
- Signed SES / SNS messages must also arrive within the configured replay window. Production defaults are `900` seconds max age and `300` seconds future skew tolerance.
- SES / SNS recipient reconciliation now prefers outbound attempt identity and provider message identity over email fallback. Older SES events may still be logged for audit, but stale events should not mutate the active recipient state.
- If SES click telemetry is absent but module click tracking works, treat the issue as SES configuration-set publishing rather than outbound send failure
- If Reply-to-Lead does not create or reuse leads, confirm inbound webhook delivery and Reply-to-Lead settings
- If WhatsApp verification fails, confirm the stored verify token and the displayed webhook URL
- If WhatsApp test sends fail with authentication or authorization errors, confirm the saved access token is still valid, not expired or revoked, and that it includes `whatsapp_business_messaging`.
- Confirm the saved token is assigned access to the configured WhatsApp Phone Number ID. A valid token for the wrong business asset can still fail on the `/messages` endpoint.
- Confirm the configured Phone Number ID is the Cloud API phone number identifier, not another business or app identifier.
- Confirm the configured template name is approved for the configured template language such as `en_US`; template approval or locale mismatches are returned by Meta separately from token failures.
- The `Send Test` button uses the currently saved WhatsApp settings only. Save settings changes first before testing a new token, phone number ID, template, or recipient.
