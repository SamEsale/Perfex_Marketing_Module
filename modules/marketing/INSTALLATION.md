# Installation

## Scope

This package installs as a module inside **Perfex CRM**. It is not standalone software.

## Compatibility

- Compatible target: `Perfex CRM 3.4.x`
- Tested on: `Perfex CRM 3.4.1`

## Before You Start

- Deploy to staging before production
- Back up the database and the current module package
- Confirm your Perfex cron setup is working
- WhatsApp Business is the only built-in social channel integration in this package.

## Install

1. Upload the `marketing` module ZIP through `Setup -> Modules`.
   The ZIP archive root must be `marketing/`, and the installed module path must resolve to `modules/marketing/marketing.php`.
   Do not upload a package that expands to `modules/marketing/modules/marketing/marketing.php`; that indicates a nested module root and is invalid.
2. Activate the module.
3. Open `Marketing -> Settings`.
4. Review `Schema Health`.
   Activation runs a safe bootstrap that only creates missing module-owned tables, safe columns, and safe indexes.
   If legacy duplicate tables such as `tbltblmarketing_*` are detected, they are reported for manual review and are never removed automatically.
5. Save the required General, SMTP / SES, Reply-to-Lead, WhatsApp, and License settings.
6. Confirm the displayed SES / SNS, inbound email, and WhatsApp webhook URLs are correct for the environment.
7. Confirm the module click redirect URL base `marketing/track/c/{token}` resolves publicly if email click tracking is enabled.
8. Confirm the public unsubscribe URL base `marketing/unsubscribe/{token}` resolves publicly.
9. Before shipping a commercial ZIP, validate it from the standalone module root with:
   `bash scripts/build_release_package.sh`
   `bash scripts/verify_phase2_regressions.sh`
   `bash scripts/verify_release_package.sh`
10. Review `Provider Readiness` and `Release Readiness` on the Settings page before final handoff to operators or support staff.
11. Include `docs/PRODUCTION_CHECKLIST.md` and `docs/RELEASE_MANIFEST.md` in the final release handoff packet.
12. Use the browser/manual smoke checklist in `docs/PRODUCTION_CHECKLIST.md` before live deployment approval.

## Upgrade

1. Back up the database and current module ZIP.
2. Replace the module ZIP with the new package.
3. Activate or reload the module.
   Missing module-owned options are repaired idempotently during activation and admin bootstrap without overwriting existing saved values.
4. Review `Schema Health`.
   Use `Repair Marketing Schema` only for idempotent reconciliation of missing module-owned schema.
   If duplicate historical tables are reported, resolve them manually on staging first; the module does not rename or drop them.
5. Re-test SMTP settings, Reply-to-Lead settings, WhatsApp settings, and license status.

## Uninstall

1. Disable sending and finish any active operational review first.
2. Back up the database.
3. Remove the module through the normal Perfex module workflow.
4. Confirm no environment-specific webhooks or external jobs still point to the old module install.

## Troubleshooting

- If settings do not save, verify the logged-in user has `marketing settings` permission
- If campaigns do not run, verify cron is active
- If module click tracking does not register, confirm campaign links were rewritten to `marketing/track/c/{token}` in the delivered message and that the public route is reachable
- If unsubscribe actions do not persist, confirm the public unsubscribe route is reachable and safe schema bootstrap created the public link token table
- If `Schema Health` reports legacy duplicate tables, review them manually on staging before making any database cleanup changes
- If email delivery fails, verify SMTP / SES credentials and provider connectivity
- If WhatsApp sending fails, verify Meta approval, Phone Number ID, token validity, and approved templates
- If WhatsApp conversations load but no delivery/read/failure updates appear, verify WhatsApp status webhooks are reaching the module and that the saved Phone Number ID matches the sending asset
- If manual WhatsApp conversion is blocked, review for ambiguous existing lead matches instead of forcing a duplicate lead
- WhatsApp provider approval/configuration remains externally gated by Meta. A valid-looking saved token may still fail until the Meta app, business asset, and phone-number permissions are fully approved.
- Email-builder social icons for Facebook, Instagram, X/Twitter, YouTube, LinkedIn, and Website are email content only. They do not activate inbox, webhook, or operator integrations for those channels.
- Production licensing uses `https://license.spitol.com/api` by default; only define `MARKETING_LICENSE_API_BASE_URL` or set `marketing_license_api_base_url` if you need to override it
- If licensing shows `unconfigured`, check for an explicit empty override in `MARKETING_LICENSE_API_BASE_URL` or `marketing_license_api_base_url`
- If licensing shows `grace`, restore connectivity to the licensing server before the grace deadline

## Security Notes

- No core Perfex files are modified
- No destructive schema operations are performed
- Secrets are not intentionally rendered back in full after save
- Public tracking and unsubscribe URLs use opaque module tokens and do not expose raw recipient IDs in the URL
- Runtime integrity diagnostics are available from the deployed CRM root with `php modules/marketing/scripts/verify_phase4_integrity_runtime.php`
