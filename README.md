# Perfex CRM Marketing Module

- Release: `v1.0.0`
- Status: `Production Ready`
- WhatsApp sending: production-capable after valid Meta-approved app, token, phone number, and template are in place

## Overview

The Marketing module adds production-oriented email marketing workflows to Perfex CRM:

- marketing dashboard
- audience and contact management
- CSV import
- campaign builder and preview
- campaign preparation, scheduling, and queue processing
- SMTP / Amazon SES sending
- SES / SNS delivery event ingestion
- WhatsApp Business settings, webhook handling, template test send, and template campaign sending
- unsubscribe handling and suppressions
- inbound reply capture
- optional Reply-to-Lead conversion
- schema health and safe repair tools

For deployment and day-to-day operations, see [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Release Notes

`v1.0.0` is the production package for the Marketing module foundation inside `modules/marketing`.

This release includes:

- production-safe install and activation bootstrap
- campaign queue safety and idempotency hardening
- suppression enforcement for email and supported WhatsApp destinations
- non-destructive schema health and repair behavior
- production-oriented operations and handover documentation

This release does not bypass external provider requirements:

- SMTP / SES delivery still depends on valid mail transport configuration
- WhatsApp sending still depends on a valid Meta-approved app, active token, active number, and approved template

## Module Installation

### ZIP Structure

The uploaded ZIP should contain the module root folder and bootstrap file exactly as Perfex expects:

```text
marketing.zip
└── marketing/
    ├── marketing.php
    ├── install.php
    ├── uninstall.php
    └── ...
```

`marketing.php` must remain at the module root.

### Install Through Perfex

1. Go to `Setup -> Modules`.
2. Upload the module ZIP.
3. Activate the `Marketing` module.

On activation the module:

- registers permissions and menus
- seeds module options
- runs a non-destructive schema health bootstrap
- seeds safe Reply-to-Lead lead source / status defaults
- runs the one-time normalization for older reply-created leads

### Install Instructions

Use this order for a clean production install:

1. Upload the packaged ZIP through `Setup -> Modules`.
2. Activate `Marketing`.
3. Open `Marketing -> Settings`.
4. Review `Schema Health` and run `Repair Marketing Schema` only if needed.
5. Save General, SMTP / SES, Reply-to-Lead, and WhatsApp settings as applicable.
6. Confirm the displayed webhook URLs in Settings are reachable from the public internet when those integrations are used.
7. Confirm the Perfex cron is active before scheduling live campaigns.

## Upgrade Instructions

For an existing installation upgrading to `v1.0.0`:

1. Back up the database and current module ZIP/package as part of the normal deployment process.
2. Replace the module with the `v1.0.0` package through your standard Perfex module deployment flow.
3. Activate or reload the module so the safe bootstrap runs.
4. Open `Marketing -> Settings -> Schema Health`.
5. Confirm required tables, safe columns, and non-destructive indexes are present.
6. Re-check cron, SMTP / SES, webhook URLs, and Reply-to-Lead settings before resuming scheduled sends.

Upgrade behavior is intentionally non-destructive:

- existing data is preserved
- only missing safe schema elements are added
- only missing non-destructive indexes are added when the target table and columns exist
- no core Perfex files are modified by this module

### Permissions

The module registers these staff capabilities under `marketing`:

- `view`
- `view_own`
- `create`
- `edit`
- `delete`
- `send`
- `import`
- `settings`

Users need `marketing settings` permission to manage settings and view Schema Health.

## Fresh Install Behavior

Fresh installs are handled through a safe bootstrap path rather than risky destructive migrations.

The module will:

- create missing module-owned marketing tables with `CREATE TABLE IF NOT EXISTS`
- add only known safe missing columns to existing module-owned marketing tables
- never drop tables
- never rename columns
- never change existing column types
- never delete live data

## Existing Install Behavior

Existing live installs are treated conservatively:

- existing module data is preserved
- only missing module-owned tables are created
- only safe missing columns are added
- only missing non-destructive marketing indexes are added when the target table and columns already exist
- unusual manual schema drift is reported instead of being changed automatically

## Required Cron Setup

The module relies on the normal Perfex cron runner.

- Perfex cron must be active
- recommended frequency is every `1` to `5` minutes
- queue processing is attached through the module cron hook

Cron is responsible for:

- preparing queued send work
- processing due scheduled campaigns
- enforcing send windows
- enforcing per-run, hourly, and daily rate limits
- completing campaign lifecycle transitions

Production hardening notes:

- cron skips campaign queue processing safely when required marketing campaign tables or columns are missing
- cron skips safely when a due campaign has no queued or pending recipients
- queue start and completion are logged with concise counters only
- provider/API failures are logged without access tokens, passwords, payload dumps, or full recipient PII

## Marketing Settings

The Marketing Settings screen includes:

### General Settings

- delivery enabled / disabled
- per-cron-run send cap
- sleep between sends
- hourly and daily caps
- send window start / end time
- default sender name / email
- default reply-to email
- open / click tracking toggles
- SES SNS signature verification toggle

### SMTP / Amazon SES Settings

- SMTP enable toggle
- SMTP host / port / username / password
- SMTP from email / from name / reply-to
- encryption mode
- SES region
- SES configuration set

### Reply-to-Lead Settings

- enable Reply-to-Lead conversion
- create lead only for unknown contacts
- default lead status
- default lead source
- default assigned staff
- lead name fallback prefix

### WhatsApp Business Settings

- enable / disable WhatsApp Business integration
- Meta Graph API version
- WhatsApp Phone Number ID
- WhatsApp Business Account ID
- permanent access token
- webhook verify token
- default template name
- default template language
- test recipient phone number

Recommended values and formats:

- Graph API version should match the Meta app / Cloud API version, for example `v23.0`
- template language should use a WhatsApp locale such as `en_US`
- test recipient must use E.164 format, for example `+46700000000`
- digits-only test recipients are normalized to E.164 automatically when valid, for example `46700000000` becomes `+46700000000`

Production notes:

- the module-level WhatsApp integration is ready for production use
- real sending requires a valid Meta-approved WhatsApp Business App
- real sending requires an active phone number ID and a valid WhatsApp Business Account configuration
- real sending requires a valid, non-expired access token
- real sending requires an approved message template
- expired Meta tokens typically return OAuth code `190` or similar authentication failures

## Security Statement

The `v1.0.0` package is designed to be production-safe within the module boundary:

- work is isolated to `modules/marketing`
- no core Perfex runtime files are required to be modified
- schema repair is non-destructive
- cron logs are kept concise and sanitized
- access tokens, SMTP passwords, full webhook payloads, and full recipient bodies are not intended to be written to normal operational logs
- campaign suppression is enforced before provider send attempts where supported by the stored schema
- public webhook routes are limited to the documented marketing endpoints

### Runtime Webhook URLs

Marketing Settings displays these environment-aware webhook URLs as read-only values:

- SES / SNS Webhook URL
- WhatsApp Webhook URL
- Inbound Reply Webhook URL

Copy the displayed runtime URLs from the settings screen instead of hardcoding them manually.

### Schema Health

- health status for required module-owned tables and safe columns
- missing tables report
- missing columns report
- manual-review items for schema changes the module will not apply automatically
- `Repair Marketing Schema` button for safe repair

## AWS SES / SNS Setup

### SES SMTP

The module can send through SMTP, including Amazon SES SMTP credentials.

Typical SES SMTP requirements:

- verified SES sender identity
- SMTP credentials created in AWS SES
- correct SES region
- optional SES configuration set if event publishing is configured

### SNS Webhook URL

Copy the webhook URL shown in `Marketing -> Settings`. It is generated automatically from the installed Perfex domain.

Expected SES/SNS notification types include normalized handling for:

- `Send`
- `Delivery`
- `Open`
- `Click`
- `Bounce`
- `Complaint`
- `Reject`
- `RenderingFailure`
- `DeliveryDelay`

### SES Sandbox Note

If the AWS SES account is still in sandbox:

- real sending is restricted
- recipient verification rules still apply
- production delivery validation will be limited until sandbox is removed

## Inbound Reply Webhook

Inbound email replies are accepted through the runtime webhook URL shown in `Marketing -> Settings`. It is generated automatically from the installed Perfex domain.

### Reply Marker

Outbound campaign emails include a reply marker in this format:

```text
reply_id:CAMPAIGN_ID:RECIPIENT_ID
```

### Matching Order

Inbound replies are matched in this order:

1. `reply_id` marker
2. sender-email fallback against the latest matching campaign recipient

### Reply-to-Lead Behavior

If enabled:

- duplicate leads are prevented by sender email
- existing leads are reused
- new leads are created directly in Perfex leads table through the module’s safe webhook path

## WhatsApp Template Campaigns

### Webhook URL

Use the webhook URL shown in `Marketing -> Settings -> WhatsApp Business Settings`.

### Required Meta Setup

To use it, configure:

- a Meta app with the WhatsApp product enabled
- a WhatsApp Business Account
- a phone number ID
- a permanent access token
- at least one approved template

### Current Scope

The current implementation supports:

- settings storage
- Meta webhook verification
- inbound WhatsApp message capture
- inbound WhatsApp status capture
- template-based test sending to the configured test recipient
- WhatsApp template campaign sending through the existing queue flow

### Approved Template Requirement

The settings test action and WhatsApp campaigns send approved templates only. They do not send free-form marketing text.

### Campaign Differences

WhatsApp campaigns are opt-in and channel-specific:

- `Email Campaign` keeps the existing builder, unsubscribe enforcement, SMTP / SES delivery, and email analytics flow
- `WhatsApp Template Campaign` uses approved Cloud API templates only
- WhatsApp campaigns do not require the email builder or `{{unsubscribe_url}}`
- existing email builder content is preserved even when a campaign is switched to WhatsApp

### Recipient Phone Requirement

WhatsApp campaigns reuse the same audience and segmentation flow, but recipients must have a valid phone number.

- contacts without a valid phone are skipped during recipient preparation
- phone filtering does not affect email campaigns

### Test and Sandbox Limitations

If the Meta app or WhatsApp number is still in a limited test state:

- sending may be restricted to approved test recipients
- template approval may still be required
- inbound webhooks may be limited until the app is fully configured

## Unsubscribe Behavior

Campaign HTML must include:

```text
{{unsubscribe_url}}
```

The builder and save validation enforce that requirement.

Public unsubscribe endpoint:

```text
/marketing/unsubscribe/{token}
```

When a user unsubscribes:

- unsubscribe event is recorded
- the contact is marked unsubscribed
- suppression is created or updated

Campaign suppression enforcement:

- email campaign sends are blocked for active suppressed email addresses
- WhatsApp campaign sends are blocked for active suppressed phone destinations when the suppression phone column is available
- suppressed queued recipients are marked as terminally skipped before provider delivery attempts

## Schema Safety

The Schema Health panel exists to reduce install risk on both fresh and existing environments.

### What Repair Does

- creates missing module-owned marketing tables
- adds safe missing columns to known marketing tables
- adds missing non-destructive indexes for campaign, event, conversation, list-member, message, and suppression lookup paths when those columns already exist
- leaves existing live data unchanged

### What Repair Does Not Do

- no destructive migration
- no table drops
- no column drops
- no column renames
- no type rewrites
- no cleanup of custom schema drift unless explicitly safe and known

## Troubleshooting

### 419 CSRF webhook issue

If inbound or SES/SNS webhooks return `419 Page Expired`:

- confirm module CSRF exclusions are loaded at runtime
- confirm the webhook route path matches the request path exactly
- confirm requests are using the module webhook URLs, not admin form endpoints

### Missing schema items

If Marketing pages fail due to missing tables or columns:

- open `Marketing -> Settings`
- review `Schema Health`
- run `Repair Marketing Schema`

### Campaign not sending

Check:

- `Campaign Delivery Enabled` is on
- Perfex cron is running
- campaign has a valid audience
- campaign has queued or eligible recipients
- send window allows current time
- per-run / hourly / daily caps are not blocking progress
- SMTP / SES settings are valid

### No SES events

Check:

- SNS topic is configured in SES
- webhook URL is reachable publicly
- signature verification setting matches the current environment capability
- SES configuration set / event publishing is correctly attached

### No Reply-to-Lead conversion

Check:

- inbound reply webhook is reachable
- Reply-to-Lead is enabled
- default lead status and source are set
- sender email is valid
- duplicate lead lookup is not already matching an existing lead

### WhatsApp webhook verification fails

Check:

- the webhook URL is reachable publicly
- the saved Webhook Verify Token matches the value configured in Meta
- runtime CSRF exclusions include the WhatsApp webhook route

### WhatsApp test send fails safely

Check:

- WhatsApp integration is enabled
- Meta Graph API version is set
- Phone Number ID is correct
- permanent access token is saved
- default template name and language are configured
- test recipient phone number is valid
- the template is approved in Meta

### WhatsApp campaign does not send

Check:

- campaign delivery channel is `WhatsApp Template Campaign`
- WhatsApp integration is enabled
- Meta credentials are complete
- campaign template name and language are filled in
- recipients have valid phone numbers
- cron is active and send window / caps permit processing

### Duplicate prevention

The module prevents duplicates in multiple places:

- campaign recipients by contact / email
- campaign recipient preparation by campaign / contact / channel / destination
- queued recipients already marked `sent`, `failed`, `suppressed`, or `skipped` are not resent
- SES/SNS events by natural dedupe keys
- Reply-to-Lead by sender email

## Pre-Go-Live Checklist

Before going live, confirm:

- module is installed and activated
- marketing permissions are assigned
- Schema Health shows `OK`, or only acceptable manual-review items remain
- SMTP / SES settings are configured
- SES sender identities are verified
- Perfex cron is active
- SES/SNS webhook is reachable
- inbound reply webhook is reachable
- WhatsApp webhook is reachable if WhatsApp is being configured
- Reply-to-Lead is configured as intended
- WhatsApp production sending is only enabled after a valid Meta-approved app, valid token, active phone number ID, and approved template are confirmed
- a test campaign can be built, saved, previewed, prepared, and sent safely

## Notes

- Do not modify core Perfex files for this module.
- The module is designed for uploadable ZIP packaging and installation through the Perfex admin module manager.
