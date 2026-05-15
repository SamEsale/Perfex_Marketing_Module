# Perfex CRM Marketing Module

Version: `1.1.0`  
Target package: `v1.1.0-commercial-prep`

This project is a module for **Perfex CRM**. It is not standalone software and must be installed inside an existing Perfex CRM instance.

## Compatibility

- Compatible target: `Perfex CRM 3.4.x`
- Tested on: `Perfex CRM 3.4.1`
- PHP: use the PHP version supported by your Perfex CRM installation

## What The Module Does

- Marketing dashboard, audiences, and CSV imports
- Email campaign builder, save/reopen flow, preview, and queue processing
- Module-owned click redirect tracking for email campaigns
- Tokenized unsubscribe handling with global suppression enforcement
- SMTP / Amazon SES campaign delivery
- SES / SNS webhook ingestion
- Reply-to-Lead conversion from inbound marketing replies
- WhatsApp Business settings, webhook verification, test template send, and campaign foundation
- Schema Health and non-destructive repair tools
- Commercial licensing panel for marketplace distribution
- Read-only Provider Readiness and Release Readiness panels for operator handoff

## Active Integrations

- Email campaigns through module-managed SMTP / Amazon SES configuration
- SES / SNS delivery and engagement telemetry
- Inbound marketing reply webhook with Reply-to-Lead support
- WhatsApp Business configuration, webhook verification, inbound conversation handling, manual operator reply surface, and campaign foundation

## Deferred Integrations

- Facebook is not active in this commercial package
- LinkedIn is not active in this commercial package
- WhatsApp provider approval and token lifecycle still depend on external Meta approval and asset access outside this module

## Operational Guarantees

- No core Perfex files are modified
- Installation and upgrade paths are non-destructive
- Safe schema bootstrap only creates missing module-owned tables, columns, and indexes
- Legacy duplicate tables such as accidental double-prefix `tbltblmarketing_*` tables are reported for manual review and are never dropped automatically
- No destructive schema operations are performed
- Access tokens and license keys are never rendered back in full after save
- Secrets are not intentionally logged

## Requirements

- Perfex cron is required for campaign execution
- Deploy to staging before production whenever possible
- WhatsApp Business is the only built-in social channel integration in this package.
- WhatsApp sending requires:
  - a Meta-approved WhatsApp Business app
  - an active Phone Number ID
  - a valid non-expired access token
  - approved templates

## Install

1. Build or obtain a ZIP whose archive root is `marketing/`.
   After upload, the module must install to `modules/marketing/marketing.php` inside the Perfex CRM working tree.
   The ZIP must not contain a nested path such as `marketing/modules/marketing/marketing.php`.
2. In Perfex CRM, open `Setup -> Modules`.
3. Upload the ZIP and activate `Marketing`.
4. Open `Marketing -> Settings`.
5. Review `Schema Health` and run repair only if the report shows missing safe schema elements.
   Legacy duplicate tables, if found, are reported for manual review only.
6. Save General, SMTP / SES, Reply-to-Lead, WhatsApp, and License settings as needed.
7. Confirm the webhook URLs shown in settings resolve correctly for the target environment.
8. Confirm cron is running before scheduling or sending campaigns.

## Tracking And Unsubscribe

- When `Track Link Clicks` is enabled for an email campaign, normal `http` and `https` links are rewritten to module-owned redirect URLs under `marketing/track/c/{token}`.
- The redirect endpoint records a `click` event with `provider=module`, updates the recipient click counters, and then redirects with HTTP `302` to the stored original URL.
- `{{unsubscribe_url}}` is replaced with a tokenized module unsubscribe URL and creates or refreshes a global marketing suppression on use.
- SES configuration sets remain supported for provider telemetry such as delivered, bounced, complained, opened, and optional SES click events.

Detailed install, upgrade, uninstall, and troubleshooting steps are in [INSTALLATION.md](INSTALLATION.md).

## Commercial Licensing

The `License & Updates` settings panel is designed for future marketplace activation.

- Production license API base URL: `https://license.spitol.com/api`
- If the license API base URL is empty, license status becomes `unconfigured` and the module remains safe to install and operate for diagnostics
- If the license server is unreachable after a previously active validation, a `7` day grace period is allowed
- License enforcement only blocks commercial actions such as sending campaigns, sending WhatsApp campaign batches, and preparing large recipient batches
- Activation, uninstall, settings access, and schema repair are not blocked by license state

Configuration order:

1. `MARKETING_LICENSE_API_BASE_URL` constant, if defined
2. `marketing_license_api_base_url` module option, if saved
3. built-in production default `https://license.spitol.com/api`

## Build A Release ZIP

Use the module-local packaging script:

```bash
bash scripts/build_release.sh
```

The script lint-checks PHP and JS, scans for common debug artifacts, and creates `release/marketing_vX.Y.Z.zip` without including the full Perfex root.
The release ZIP root remains `marketing/`, so the uploaded package resolves to `modules/marketing/marketing.php` after installation.

Validate the package before shipping:

```bash
bash scripts/verify_phase2_regressions.sh
bash scripts/verify_release_package.sh
```

The release validator confirms:

- ZIP root is exactly `marketing/`
- installed module path resolves to `modules/marketing/marketing.php`
- nested `marketing/modules/marketing/` roots are rejected
- release docs and operator scripts are present
- release artifacts such as `.git`, `.github`, `node_modules`, `vendor`, `.env`, logs, temp files, and nested ZIPs are excluded

## Operator Handoff

- Review `Marketing -> Settings -> Provider Readiness` before testing provider flows
- Review `Marketing -> Settings -> Release Readiness` before packaging or support handoff
- Run runtime integrity diagnostics from the deployed CRM root with:

```bash
php modules/marketing/scripts/verify_phase4_integrity_runtime.php
```

- Treat WhatsApp send/auth failures during this phase as external Meta approval or asset-access issues unless the saved settings are clearly incomplete

## Documentation

- [INSTALLATION.md](INSTALLATION.md)
- [SUPPORT.md](SUPPORT.md)
- [EULA.md](EULA.md)
- [CHANGELOG.md](CHANGELOG.md)
- [docs/OPERATIONS.md](docs/OPERATIONS.md)
- [docs/PRODUCTION_CHECKLIST.md](docs/PRODUCTION_CHECKLIST.md)
- [docs/RELEASE_MANIFEST.md](docs/RELEASE_MANIFEST.md)
- [docs/MARKETPLACE_LISTING.md](docs/MARKETPLACE_LISTING.md)
