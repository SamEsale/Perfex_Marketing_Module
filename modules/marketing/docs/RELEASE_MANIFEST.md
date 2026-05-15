# Release Manifest

## Module Identity

- Module name: `Marketing`
- Module version: `1.1.0`
- Requires at least: `3.2.*`
- Compatible Perfex baseline: `Perfex CRM 3.4.x`

## Active Integrations

- SMTP / Amazon SES campaign delivery
- SES / SNS webhook telemetry
- Inbound email reply webhook
- WhatsApp Business settings, webhook verification, inbound conversations, and manual operator reply workflow

## Deferred Integrations

- Facebook is deferred / inactive in this package
- LinkedIn is deferred / inactive in this package
- WhatsApp provider approval, asset access, and token lifecycle remain external Meta prerequisites

## Required Release Docs

- `README.md`
- `INSTALLATION.md`
- `docs/OPERATIONS.md`
- `docs/PRODUCTION_CHECKLIST.md`
- `docs/RELEASE_MANIFEST.md`

## Required Validation Scripts

- `scripts/verify_release_package.sh`
- `scripts/verify_phase2_regressions.sh`
- `scripts/verify_phase4_integrity_runtime.php`

## Required Public Routes

- `marketing/webhooks/ses_sns`
- `marketing/webhooks/inbound_email`
- `marketing/webhooks/whatsapp`
- `marketing/track/c/{token}`
- `marketing/unsubscribe/{token}`

## Required CSRF Exclusions

- `marketing/webhooks/ses_sns`
- `marketing/webhooks/inbound_email`
- `marketing/webhooks/whatsapp`

## Lifecycle Hooks

- activation hook
- deactivation hook
- uninstall hook
- app init hook for CSRF exclusions
- after-cron hook for queue processing

## Packaging Root Expectations

- ZIP package root must be `marketing/`
- Installed module path must become `modules/marketing/marketing.php`
- Nested `modules/marketing/modules/marketing/marketing.php` is invalid

## Operational Diagnostics

- release build via `bash scripts/build_release_package.sh`
- release package validation via `bash scripts/verify_release_package.sh`
- regression validation via `bash scripts/verify_phase2_regressions.sh`
- runtime integrity diagnostics via `php modules/marketing/scripts/verify_phase4_integrity_runtime.php`

## External Dependencies / Approvals

- active Perfex cron
- valid email provider configuration
- SES / SNS provider-side configuration when used
- inbound email webhook delivery when used
- external Meta approval, business asset access, valid token, Phone Number ID, and approved templates for WhatsApp sending

## Release Statements

- No secrets included in distributed release artifacts
- No Perfex core modifications required by this module
