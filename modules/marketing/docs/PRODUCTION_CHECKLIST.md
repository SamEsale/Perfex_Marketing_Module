# Production Checklist

## Scope

This checklist is for commercial packaging, deployment review, and operator handoff of the Perfex CRM Marketing module.

## Pre-Install / Pre-Upgrade Verification

- Confirm the package came from the standalone module root
- Confirm backups exist for the database and the previous module ZIP
- Confirm the target Perfex CRM version matches the supported baseline
- Run from the standalone module root:
  - `bash scripts/build_release_package.sh`
  - `bash scripts/verify_phase2_regressions.sh`
  - `bash scripts/verify_release_package.sh`

## ZIP Structure Validation

- ZIP root must be exactly `marketing/`
- Installed path must resolve to `modules/marketing/marketing.php`
- `modules/marketing/modules/marketing/marketing.php` is invalid and must be rejected
- Confirm release docs and validation scripts are present in the package

## Activation Checks

- Activate the module through `Setup -> Modules`
- Confirm the module appears under the Marketing menu
- Confirm `Marketing -> Settings` loads without PHP errors
- Review `Schema Health` before any operational testing

## Provider Readiness Review

- Review `Marketing -> Settings -> Provider Readiness`
- Confirm email delivery configuration state is as expected
- Confirm SES / SNS webhook verification state is as expected
- Confirm inbound email reply secret readiness is as expected
- Confirm WhatsApp readiness reflects saved settings only and does not imply external provider approval

## Release Readiness Review

- Review `Marketing -> Settings -> Release Readiness`
- Confirm module metadata, release validation tooling, operations docs, and diagnostics availability appear as expected
- Treat `Needs Review` or `Unavailable` as handoff blockers until clarified

## License / Support Readiness

- Review `Marketing -> Settings -> License & Updates`
- Confirm support staff understand license status labels before deployment
- Do not include license keys, tokens, or secrets in handoff notes

## SES / SNS Webhook Readiness

- Confirm the rendered SES / SNS webhook URL matches the deployment base URL
- Confirm saved signature verification expectations are understood
- Confirm the module is not being deployed with unknown SNS topic or region state

## Inbound Email Reply Webhook Readiness

- Confirm the rendered inbound email webhook URL matches the deployment base URL
- Confirm the inbound email secret is configured before production reply automation is expected

## WhatsApp Readiness

- Confirm WhatsApp Business is the only active social integration surface in this package
- Facebook and LinkedIn are deferred / inactive and must not be presented as active integrations
- Confirm YouTube is not presented as an active social integration surface
- Confirm email-builder social icons for Facebook, Instagram, X/Twitter, YouTube, LinkedIn, and Website are treated as email content only, not inbox or webhook integrations
- Confirm operators understand that WhatsApp sending depends on external Meta approval, asset access, token validity, Phone Number ID, and approved templates
- Treat Meta approval gaps as external readiness blockers, not module runtime defects by default

## Campaign Send Safety Checklist

- Confirm cron is active before scheduling or sending campaigns
- Confirm provider readiness is reviewed before enabling production sending
- Confirm unsubscribe and click routes are reachable when those features are in use

## Queue Operations Checklist

- Confirm queue warnings are reviewed from the dashboard before large send operations
- Confirm operators understand queue pause, stale recovery, and failure metrics surfaces
- Do not treat runtime warnings as automatic repair instructions

## Analytics / Reporting Sanity Checklist

- Confirm Marketing Analytics loads
- Confirm campaign performance reporting and provider health summary are visible
- Confirm Marketing Reports loads and remains an operator surface only
- Confirm Provider Readiness and Release Readiness remain read-only and do not run shell diagnostics from the UI

## Email Builder Manual Smoke Checklist

- Confirm company address insert/save/reload/preview works from saved Perfex company settings
- Confirm social icons save/reload and appear in Preview Desktop and Preview Mobile when populated
- Confirm social icons remain icon-only and empty social channels stay hidden
- Confirm unsubscribe footer validation still blocks sending content without unsubscribe support

## WhatsApp Manual Smoke Checklist

- Confirm inbound WhatsApp messages create or attach to the expected conversation
- Confirm allowlisted media metadata summaries render safely for inbound media messages when present
- Confirm outbound manual replies persist on the conversation timeline
- Confirm sent, delivered, read, and failed status webhooks reconcile into the existing outbound message state without duplicating message rows
- Confirm manual lead conversion is POST-only, remains explicit, and blocks ambiguous matches instead of silently linking the wrong lead

## Runtime Integrity Diagnostics

- Run from the deployed CRM root:
  - `php modules/marketing/scripts/verify_phase4_integrity_runtime.php`
- If `critical_count=0`, remaining warnings are review items only
- Warning categories such as audit-preserved orphaned recipient refs and reconciliation-skipped events are not automatic repair instructions in this release process

## Rollback Checklist

- Keep the previous known-good module ZIP
- Keep a matching database backup
- Roll back the module ZIP and database together when reversing a release
- Do not rely on cleanup tools or retention cleanup as rollback substitutes

## Support Handoff Checklist

- Provide the validated module ZIP
- Provide release-facing docs:
  - `README.md`
  - `INSTALLATION.md`
  - `docs/OPERATIONS.md`
  - `docs/PRODUCTION_CHECKLIST.md`
  - `docs/RELEASE_MANIFEST.md`
- Confirm `No secrets included` in the package and handoff notes
- Confirm `No Perfex core modifications` are required by this module package
