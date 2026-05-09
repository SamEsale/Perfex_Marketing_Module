# Marketing Module Operations Guide

- Release: `v1.0.0`
- Status: `Production Ready`
- Scope note: WhatsApp sending still depends on valid Meta-approved app, token, number, and template state

## Scope

This document covers production deployment and operations for the Perfex Marketing module.

It is intended for:

- CRM administrators
- DevOps / hosting administrators
- support engineers validating email delivery, webhooks, replies, and schema health

## 1. Installation and Activation

### Module ZIP Requirements

The uploaded module package must preserve the expected Perfex module structure:

```text
marketing.zip
└── marketing/
    ├── marketing.php
    ├── install.php
    ├── uninstall.php
    └── ...
```

### Install Procedure

1. Open `Setup -> Modules`.
2. Upload the `marketing.zip` package.
3. Activate `Marketing`.

### What Activation Does

Activation performs only safe, non-destructive setup:

- option seeding
- module menu and permissions registration
- safe schema bootstrap
- Reply-to-Lead source / status default seeding
- one-time normalization of older reply-created leads
- WhatsApp Business option seeding
- safe additive schema fields for WhatsApp campaign delivery when missing
- safe additive non-destructive index hardening for campaign, recipient, event, conversation, message, and suppression lookup paths when tables and columns already exist

## 2. Permissions

Relevant module capabilities:

- `view`
- `view_own`
- `create`
- `edit`
- `delete`
- `send`
- `import`
- `settings`

Staff need `marketing settings` permission to:

- manage module settings
- view Schema Health
- run schema repair

## 3. Cron Operations

### Perfex Cron Requirement

The module depends on the main Perfex cron runner.

Recommended schedule:

- every `1` to `5` minutes
- do not enable scheduled live campaigns until cron is confirmed to be running normally

### Cron Responsibilities

Cron handles:

- due queued recipient processing
- scheduled campaign activation
- send window enforcement
- per-run, hourly, and daily send caps
- lifecycle transitions such as `scheduled -> sending -> sent`

Production hardening behavior:

- cron exits safely when required campaign queue tables or columns are missing
- cron exits safely when a campaign has no queued or pending recipients to process
- queue runs log start and completion counters only
- recipient-level provider/API failures are logged without secrets, message bodies, webhook payload dumps, or full recipient PII

### Send Windows and Rate Limits

The following settings affect throughput:

- `marketing_send_per_cron_run`
- `marketing_send_sleep_ms`
- `marketing_send_hourly_cap`
- `marketing_send_daily_cap`
- `marketing_send_start_hour`
- `marketing_send_end_hour`

If sending pauses unexpectedly, check these values first.

## 4. Marketing Settings

### General Settings

Use this panel for:

- delivery enable / disable
- send windows
- rate limits
- tracking toggles
- default sender details
- SES SNS signature verification setting

### SMTP / SES Settings

Use this panel for:

- SMTP server settings
- SES SMTP credentials
- SES region
- SES configuration set

Operational requirement:

- live email campaign sending should not be enabled until SMTP / SES test sending succeeds with the intended production sender identity

### Reply-to-Lead Settings

Use this panel for:

- enabling Reply-to-Lead
- unknown-contacts-only mode
- default lead status / source
- assigned staff
- lead name fallback prefix

### WhatsApp Business Settings

Use this panel for:

- enabling the WhatsApp Business foundation
- Meta Graph API version
- phone number ID
- WhatsApp Business Account ID
- permanent access token
- webhook verify token
- default approved template name
- default template language
- test recipient phone number

Recommended values and formats:

- Graph API version should match the Meta app / Cloud API version, for example `v23.0`
- template language should use a WhatsApp locale such as `en_US`
- test recipient must use E.164 format, for example `+46700000000`
- digits-only test recipients are normalized to E.164 automatically when valid, for example `46700000000` becomes `+46700000000`

The settings screen also displays runtime-generated webhook URLs for:

- SES / SNS
- WhatsApp
- Inbound Reply

Always copy the runtime URLs from the Settings screen instead of hardcoding them in external systems.

### Schema Health

Use this panel to:

- confirm required module-owned tables and safe columns are present
- review missing schema items
- run safe schema repair when needed

Schema repair and activation bootstrap are non-destructive:

- no table drops
- no column renames
- no foreign key additions in this hardening pass
- no index drops or renames
- only missing indexes are added when the target table and columns already exist

## 5. AWS SES / SNS Setup

### SES SMTP

The module sends mail through SMTP. For Amazon SES:

- verify sender identity in SES
- create SES SMTP credentials
- set region correctly
- optionally configure an SES configuration set

### SNS Webhook URL

Copy the webhook URL shown in `Marketing -> Settings`. It is generated automatically from the installed Perfex domain.

Expected runtime URLs shown in Settings:

- SES / SNS: `marketing/webhooks/ses_sns`
- Inbound email reply: `marketing/webhooks/inbound_email`
- WhatsApp: `marketing/webhooks/whatsapp`

### Expected Event Types

The module normalizes these SES/SNS event families:

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

If SES is still in sandbox:

- sending volume is limited
- unverified recipients may be blocked
- live delivery validation will be incomplete

## 6. WhatsApp Business

### Endpoint

Use the webhook URL shown in `Marketing -> Settings -> WhatsApp Business Settings`.

### Required Meta Setup

Before testing, configure:

- a Meta app with the WhatsApp product
- a WhatsApp Business Account
- a phone number ID
- a permanent access token
- at least one approved template
- ensure the access token is still valid and not expired

### Current Scope

The module now supports:

- settings
- Meta webhook verification
- inbound message / status capture
- approved-template test sends to the configured test recipient
- WhatsApp template campaign sending through the normal campaign queue

The WhatsApp integration is module-ready, but live sending still depends on a valid Meta-approved WhatsApp Business App, active phone number ID, valid non-expired access token, and approved template.

### Template-Only Behavior

The settings page test button and WhatsApp campaigns send approved templates only. They do not send free-form marketing text.

If the Meta token is expired or invalid, test sends and campaign sends can fail with OAuth code `190` or other authentication errors from Meta.

This hardening pass does not change external Meta approval or token validity behavior. An expired or invalid token can still return a Meta authentication error, and an enabled integration should not be reported as disabled solely because the token is invalid.

### Recipient Eligibility

WhatsApp campaigns reuse the same audience and segmentation flow, but recipients must have valid phone numbers.

- contacts without valid phones are skipped during recipient preparation
- this does not change email recipient eligibility

### Suppression Enforcement

Campaign delivery enforces active suppressions immediately before provider sends:

- email campaigns are blocked by normalized suppressed email
- WhatsApp campaigns are blocked by normalized suppressed phone when the suppression phone column is present
- suppressed recipients are marked terminally and are not sent
- already terminal recipients are not resent on later cron runs

## 7. Inbound Reply Webhook

### Endpoint

Use the inbound reply webhook URL shown in `Marketing -> Settings`. It is generated automatically from the installed Perfex domain.

### Reply Tracking

Outbound campaign mail includes a hidden reply marker:

```text
reply_id:CAMPAIGN_ID:RECIPIENT_ID
```

### Matching Behavior

The inbound webhook attempts:

1. `reply_id` match
2. sender-email fallback

### Reply-to-Lead

If enabled:

- existing lead by email is reused
- duplicate lead creation is prevented
- new leads are inserted directly into Perfex `tblleads` through the module’s safe webhook path

Recommended setup:

- enable Reply-to-Lead only after the inbound email webhook is publicly reachable
- set the default lead status, source, and assignment before enabling automatic lead creation
- verify one live inbound test before relying on the automation

## 8. Unsubscribe and Suppression

### Required Token

Campaign HTML must contain:

```text
{{unsubscribe_url}}
```

### Public Unsubscribe Endpoint

```text
/marketing/unsubscribe/{token}
```

### Suppression Outcomes

The module creates or updates suppressions for:

- unsubscribe
- bounce
- complaint

Delivery enforcement behavior:

- email campaign recipients are suppressed by normalized email
- WhatsApp campaign recipients are suppressed by normalized phone when the suppression phone column exists
- suppressed recipients are not sent
- recipients already in terminal states are not resent by later cron runs

## 9. Schema Safety and Repair

### Schema Health Panel

The panel reports:

- `OK` when healthy
- missing module-owned tables
- missing safe columns
- manual-review items the module will not change automatically

### Repair Marketing Schema

The repair button is safe and CSRF-protected.

It only:

- creates missing module-owned tables
- adds safe missing columns
- adds missing non-destructive indexes when the target table and columns already exist

It does not:

- delete data
- drop tables
- rename columns
- rewrite column types

## 10. Common Failure Modes

### 419 Page Expired on webhook

Check:

- runtime CSRF exclusions are loaded
- request is hitting the correct module webhook path
- reverse proxy / rewrite rules are not changing the path unexpectedly

### Missing schema items

Check:

- `Marketing -> Settings -> Schema Health`
- run `Repair Marketing Schema`
- review any remaining manual-review items

### Campaign not sending

Check:

- delivery enabled
- cron active
- valid audience
- queued or eligible recipients exist
- send window allows current time
- SMTP / SES credentials valid
- hourly or daily caps not exhausted

### No SES events arriving

Check:

- SNS topic is configured in SES
- webhook URL is reachable externally
- event publishing is enabled
- signature verification setting matches the environment

### No Reply-to-Lead conversion

Check:

- inbound webhook is reachable
- Reply-to-Lead is enabled
- default lead status / source are configured
- sender email is valid
- duplicate email lookup is not already reusing an existing lead

### WhatsApp webhook verification fails

Check:

- the public webhook URL is correct
- the webhook verify token in Meta matches the value saved in Marketing Settings
- the module CSRF exclusions are loaded at runtime

### WhatsApp test send fails

Check:

- WhatsApp integration is enabled
- Meta Graph API version is configured
- phone number ID is configured
- permanent access token is saved
- default template name and language are configured
- test recipient phone number is valid
- the template is approved and available to the configured business number

### Duplicate prevention

The module prevents duplicates in:

- recipient preparation
- event ingestion
- Reply-to-Lead lead reuse by sender email

## 11. Production Checklist

Before enabling real campaign sending:

- SMTP or SES SMTP is configured
- SES identities are verified
- SES sandbox status is understood
- Perfex cron is active
- SES/SNS webhook is reachable
- inbound reply webhook is reachable
- WhatsApp webhook is reachable if WhatsApp is being configured
- Reply-to-Lead settings are configured as intended
- WhatsApp approved template, token, and test recipient are configured if WhatsApp is being tested
- Schema Health is `OK` or only acceptable manual-review items remain
- test campaign build / save / reopen works
- test queue processing works
- test unsubscribe works
- test webhook ingestion works
