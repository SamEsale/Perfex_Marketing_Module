# Marketing Module Implementation Plan

## Architecture Findings

### Exact Files Inspected

- `application/libraries/App_modules.php`
- `application/libraries/App_module_installer.php`
- `application/libraries/App_module_migration.php`
- `application/libraries/App_mailer.php`
- `application/libraries/App_Email.php`
- `application/helpers/modules_helper.php`
- `application/helpers/upload_helper.php`
- `application/helpers/files_helper.php`
- `application/helpers/menu_helper.php`
- `application/helpers/template_helper.php`
- `application/helpers/func_helper.php`
- `application/hooks/InitModules.php`
- `application/config/routes.php`
- `application/controllers/Cron.php`
- `application/controllers/admin/Mods.php`
- `application/controllers/admin/Emails.php`
- `application/models/Cron_model.php`
- `application/models/Emails_model.php`
- `application/models/Leads_model.php`
- `application/views/admin/settings/includes/email.php`
- `modules/goals/goals.php`
- `modules/goals/install.php`
- `modules/goals/controllers/Goals.php`
- `modules/surveys/surveys.php`
- `modules/surveys/install.php`
- `modules/surveys/controllers/Surveys.php`
- `modules/menu_setup/menu_setup.php`
- `modules/openai/openai.php`
- `application/migrations/106_version_106.php`
- `application/migrations/111_version_111.php`
- `application/migrations/127_version_127.php`
- `application/migrations/194_version_194.php`

### Perfex Module Contract

- Module must live under `modules/marketing/`.
- Main bootstrap file must be `modules/marketing/marketing.php`.
- Folder name and bootstrap filename must match exactly; Perfex discovers modules by `APP_MODULES_PATH/<name>/<name>.php`.
- Upload/install flow is native: admin uploads a ZIP through `admin/modules`, handled by `application/controllers/admin/Mods.php` and `application/libraries/App_module_installer.php`.
- ZIP validity check is minimal but strict: at least one PHP file in the extracted archive must contain a `Module Name:` header, and the module root must include `marketing.php`.
- Supported headers parsed from bootstrap comments:
  - `Module Name`
  - `Module URI`
  - `Version`
  - `Description`
  - `Author`
  - `Author URI`
  - `Requires at least`

### Activation / Upgrade / Uninstall Conventions

- Activation is convention-driven:
  - `register_activation_hook(MARKETING_MODULE_NAME, 'marketing_module_activation_hook');`
  - Activation hook typically `require_once(__DIR__ . '/install.php');`
- Deactivation hook exists and should be used for cleanup of cron state, not destructive data deletion.
- Uninstall can be handled by:
  - `register_uninstall_hook(...)`, or
  - `modules/marketing/uninstall.php`
- Perfex physically deletes the module directory on uninstall via `App_modules::uninstall()`.
- Module DB upgrades are supported natively through `modules/marketing/migrations/NNN_name.php` and `App_module_migration`.
- Recommendation:
  - Use `install.php` only for first-install bootstrap.
  - Put all later schema changes in `migrations/`.
  - Avoid destructive uninstall by default; prefer soft uninstall unless explicitly requested by user/admin.

### Hooks / Permissions / Menu Patterns

- First-party modules register everything from bootstrap file via `hooks()->add_action()` and `hooks()->add_filter()`.
- Permissions are added via `register_staff_capabilities()`.
- Typical capability set:
  - `view`
  - `view_own` when ownership exists
  - `create`
  - `edit`
  - `delete`
- Admin sidebar/menu is added during `admin_init`.
- Common menu APIs:
  - `$CI->app_menu->add_sidebar_menu_item(...)`
  - `$CI->app_menu->add_sidebar_children_item(...)`
  - `$CI->app_menu->add_setup_menu_item(...)`
  - `$CI->app_menu->add_setup_children_item(...)`
  - `$CI->app->add_quick_actions_link(...)`
  - `$CI->app->add_settings_section_child(...)`

### Admin Area / Asset Conventions

- Module admin controllers extend `AdminController`.
- Standard permission gate:
  - `if (staff_cant('view', 'marketing')) { access_denied('marketing'); }`
- AJAX tables commonly use:
  - `$this->app->get_table_data(module_views_path('marketing', 'tables/<file>'));`
- Assets are registered, not hardcoded:
  - `$this->app_scripts->add(...)`
  - `$this->app_css->add(...)`
- Views are loaded with module-relative paths:
  - `$this->load->view('marketing/<view>', $data);`
- Existing module UI pattern favors standard admin layout, DataTables, TinyMCE/HTML editors, and server-rendered PHP views.

### Email / SMTP Infrastructure

- System-wide outbound mail already supports:
  - SMTP
  - Sendmail
  - Mail
  - Microsoft OAuth 2.0
  - Gmail OAuth 2.0
- Core mailer:
  - `application/libraries/App_mailer.php`
  - `application/libraries/App_Email.php`
- Queueing already exists:
  - table `tblmail_queue`
  - option `email_queue_enabled`
  - option `email_queue_skip_with_attachments`
  - cron calls `$this->email->send_queue()` and `$this->email->retry_queue()`
- Implication for `marketing`:
  - reuse core SMTP credentials and mail engine
  - do not build a second transport layer for SMTP
  - maintain a module-level campaign queue/state table, but dispatch actual mail through `App_Email`
- Amazon SES SMTP fits existing system with no core changes.
- SES SNS webhooks for delivery/open/bounce/complaint are not built in for campaigns, so module must add its own public endpoints and event ingestion tables.

### Leads / Contacts / Clients / Staff / Reply Intake

- Leads model and cron already support IMAP-driven lead creation from inbound email.
- Existing lead email integration data points:
  - lead flags `is_imported_from_email_integration`, `email_integration_uid`
  - tables renamed over time from legacy names to `tblleads_email_integration` / `tbllead_integration_emails`
- Contacts are separate from clients; `tblcontacts` stores actual recipient emails.
- Existing recipient sources suitable for marketing audience sync:
  - `tblcontacts`
  - `tblclients`
  - `tblleads`
  - `tblstaff` optionally, but exclude by default from marketing workflows
- Recommendation:
  - marketing recipients should be module-owned normalized rows
  - source links back to lead/client/contact should be nullable references, not hard FK assumptions
  - inbound email reply -> lead creation can reuse reply parsing / existing lead creation flows, but should be module-specific and opt-in

### Upload / Media / File Conventions

- Core upload paths are centralized in `get_upload_path_by_type()`.
- File metadata table is `tblfiles` with polymorphic `rel_type` + `rel_id`.
- Allowed file extensions are controlled globally by `get_option('allowed_files')`.
- Upload validation helpers exist:
  - `_upload_extension_allowed($filename)`
  - `validate_file($file, $allowed_files = [])`
- Recommendation:
  - register a custom upload path for `marketing` via `get_upload_path_by_type` filter
  - store uploaded campaign assets in `tblfiles` with `rel_type = 'marketing_campaign'` or `marketing_asset`
  - additionally keep a module asset mapping table if campaign builder needs richer metadata

### Security Conventions

- Public/admin controller access is explicit; admin pages must check `staff_can/staff_cant`.
- HTML input convention for rich content:
  - use `$this->input->post('<field>', false)` then `html_purify(...)`
- Plain text / parsed input is commonly passed through:
  - `$this->security->xss_clean(...)`
  - `process_text_content_for_display(...)` for output formatting
- CSRF is configurable globally and module-specific public endpoints can be excluded through:
  - `modules/marketing/config/csrf_exclude_uris.php`
  - loaded by `application/hooks/InitModules.php`
- Implication:
  - SES SNS webhook endpoint likely needs CSRF exclusion
  - webhook must implement its own signature validation and topic verification
  - CSV import must validate MIME, extension, header mapping, row size, and email format

### Cron / Background Work Conventions

- Core cron entry is `application/controllers/Cron.php`.
- Module jobs should attach to `after_cron_run` via `register_cron_task()` or `hooks()->add_action('after_cron_run', ...)`.
- Surveys module demonstrates queue-based throttled email sends.
- Recommendation:
  - keep campaign processing incremental per cron run
  - use per-run limits from module options
  - separate jobs for queueing, sending, event reconciliation, and suppression maintenance


## Current Module Structure

The module is no longer a proposal. The current working structure is:

```text
modules/marketing/
├── marketing.php
├── install.php
├── uninstall.php
├── IMPLEMENTATION_PLAN.md
├── README.md
├── docs/
│   └── OPERATIONS.md
├── config/
│   ├── routes.php
│   └── csrf_exclude_uris.php
├── controllers/
│   ├── Analytics.php
│   ├── Audiences.php
│   ├── Campaigns.php
│   ├── Imports.php
│   ├── Marketing.php
│   ├── Settings.php
│   ├── Subscriptions.php
│   └── Webhooks.php
├── models/
│   ├── Marketing_audiences_model.php
│   ├── Marketing_campaigns_model.php
│   ├── Marketing_conversations_model.php
│   ├── Marketing_dashboard_model.php
│   ├── Marketing_events_model.php
│   ├── Marketing_imports_model.php
│   ├── Marketing_integrations_model.php
│   ├── Marketing_reply_leads_model.php
│   └── Marketing_suppressions_model.php
├── libraries/
│   ├── Marketing_mailer.php
│   ├── Marketing_schema.php
│   ├── Marketing_ses_sns.php
│   └── Marketing_unsubscribe.php
├── language/
│   └── english/
│       └── marketing_lang.php
├── migrations/
│   └── 001_initial_schema.php
├── views/
│   ├── analytics/index.php
│   ├── audiences/
│   │   ├── contact_form.php
│   │   ├── form.php
│   │   ├── index.php
│   │   └── members.php
│   ├── campaigns/
│   │   ├── form.php
│   │   ├── index.php
│   │   ├── preview.php
│   │   └── show.php
│   ├── imports/
│   │   ├── index.php
│   │   └── upload.php
│   ├── settings/index.php
│   ├── subscriptions/unsubscribe_result.php
│   └── dashboard.php
└── assets/
    ├── css/marketing-builder.css
    └── js/marketing-builder.js
```

### Bootstrap Responsibilities in `marketing.php`

Implemented:

- define `MARKETING_MODULE_NAME`
- register activation, deactivation, uninstall, and language files
- register admin menu and staff permissions
- register cron queue processing
- register runtime CSRF exclusions for webhook endpoints
- register safe schema bootstrap on activation and admin init
- register one-time Reply-to-Lead status/source default seeding
- register one-time normalization for older reply-created leads still using the `Email Reply` source
- register module action links

### Actual Controller Split

- `Marketing.php`: dashboard entry point
- `Campaigns.php`: campaign CRUD, builder persistence, recipient preparation, scheduling, preview, send/reset/retry flows
- `Audiences.php`: audiences, members, contact management
- `Imports.php`: import UI/upload flow
- `Analytics.php`: analytics overview and recent event reporting
- `Settings.php`: general settings, SMTP settings, Reply-to-Lead settings, SMTP test
- `Subscriptions.php`: public unsubscribe endpoint
- `Webhooks.php`: SES/SNS webhook ingestion and inbound email reply capture


## Current Data / Schema Reality

The original schema section below was a target design. The live module now includes a conservative schema bootstrap and health-check layer.

Current state:

- `modules/marketing/migrations/001_initial_schema.php` remains a safe-mode no-op.
- install and activation now call a module-owned safe schema bootstrap instead of relying on risky migrations.
- `modules/marketing/libraries/Marketing_schema.php` inventories required module-owned marketing tables and safe columns.
- missing module-owned tables are created with `CREATE TABLE IF NOT EXISTS`.
- safe missing columns are added non-destructively with `ALTER TABLE ADD COLUMN`.
- existing column types are not rewritten, columns are not renamed, and live data is not deleted.
- Marketing Settings now exposes a `Schema Health` panel and a CSRF-safe `Repair Marketing Schema` button.
- Reply-to-Lead defaults also seed safe Perfex lead status/source records in:
  - `tblleads_status`
  - `tblleads_sources`

Implication:

- the previous “schema is assumed to exist” install risk has been reduced substantially
- fresh installs now have a safe path to create minimum required module-owned tables
- existing installs now have a safe, admin-visible repair path for missing schema items
- `001_initial_schema.php` is still intentionally conservative, so install/repair logic lives in the bootstrap/helper layer rather than a destructive migration path

### Active Module Options

Current module options now include, at minimum:

- send controls:
  - `marketing_send_enabled`
  - `marketing_send_per_cron_run`
  - `marketing_send_sleep_ms`
  - `marketing_send_hourly_cap`
  - `marketing_send_daily_cap`
  - `marketing_send_start_hour`
  - `marketing_send_end_hour`
- sender / tracking:
  - `marketing_default_from_name`
  - `marketing_default_from_email`
  - `marketing_default_reply_to_email`
  - `marketing_track_opens`
  - `marketing_track_clicks`
- SES / webhook / SMTP:
  - `marketing_ses_verify_signature`
  - `marketing_ses_region`
  - `marketing_ses_configuration_set`
  - SMTP settings fields
- Reply-to-Lead:
  - `marketing_reply_to_lead_enabled`
  - `marketing_reply_to_lead_unknown_only`
  - `marketing_reply_to_lead_status`
  - `marketing_reply_to_lead_source`
  - `marketing_reply_to_lead_assigned_staff`
  - `marketing_reply_to_lead_name_prefix`
  - `marketing_schema_bootstrap_version`
  - `marketing_reply_lead_defaults_seeded`
  - `marketing_reply_lead_source_normalized`


## Permissions And Menu

### Permissions

Register staff capabilities under feature id `marketing`.

Capabilities:

- `view`
- `view_own`
- `create`
- `edit`
- `delete`
- `send`
- `import`
- `settings`

Recommended help semantics:

- `view`: global marketing visibility
- `view_own`: only campaigns/imports created by current staff
- `send`: permission to queue/schedule campaigns
- `import`: permission to upload CSV lists
- `settings`: manage SMTP/SES/webhook/channel settings

### Menu Entries

Sidebar recommendation:

- Top-level sidebar item `marketing`
  - `Dashboard`
  - `Campaigns`
  - `Audiences`
  - `Imports`
  - `Analytics`
  - `Settings`

Quick action recommendation:

- `New Campaign`

Why top-level instead of Utilities child:

- Feature scope is larger than a utility.
- It spans campaigns, analytics, inbound processing, integrations, and settings.


## Development Status By Phase

### Completed Foundations

#### Phase 1

Completed:

- uploadable module scaffold
- bootstrap hooks
- permissions
- sidebar/setup entries
- language file
- settings page skeleton
- non-destructive schema bootstrap
- schema health checker and repair flow
- schema health admin UI

Open:

- decide whether the current bootstrap-based schema strategy should eventually be complemented by a more formal upgrade/versioning path without becoming destructive

#### Phase 2

Completed:

- audience/list CRUD flows
- contact management UI
- normalized audience status/consent handling in code paths
- import UI and import model/controller flow

Open:

- verify/import schema lifecycle against real installs if tables are not pre-provisioned
- expand import diagnostics and automated validation coverage

#### Phase 3

Completed:

- campaign CRUD
- builder-based email design flow
- preview flow
- builder state save/reopen handling

Open:

- more formal regression coverage for builder serialization

#### Phase 4

Completed:

- recipient preparation
- duplicate prevention
- cron-driven sending
- `App_Email`/module mailer-based dispatch
- queue state handling

Open:

- deeper production soak testing under large queues and shared-hosting cron patterns

#### Phase 5

Completed:

- SES/SNS webhook endpoint
- normalized event ingestion
- suppression handling
- analytics rollups

Open:

- broader live-provider validation across more SES payload variants

#### Phase 6

Completed:

- inbound reply capture
- conversation/message persistence flow
- Reply-to-Lead conversion path
- Reply-to-Lead direct lead creation path hardened for webhook context

Open:

- channel placeholders remain limited; no real WhatsApp/Facebook/LinkedIn messaging implementation exists

### Builder / UX Hardening

#### Phase 14

Completed:

- nested block toolbar selection-only behavior
- inner toolbar overlap removal
- editable Hero child elements
- Hero child show/hide/restore/reorder
- Hero child state persistence across save/reopen

Open:

- additional browser-level UX regression testing on narrow layouts and legacy browsers

### Reliability / Analytics / Operations

#### Phase 15

Completed:

- SES/SNS event normalization
- webhook deduplication
- safer recipient state transitions
- automatic suppression on bounce/complaint
- hardened campaign analytics and detail stats
- safe subscription confirmation handling

Open:

- continued live replay testing with duplicated provider payloads

#### Phase 16

Completed:

- segment targeting rules
- estimate recipients flow
- shared eligibility query logic
- campaign detail targeting summary

Open:

- more complex segment presets and saved reusable segments are still not implemented

#### Phase 17

Completed:

- scheduling support
- send windows
- rate-limit enforcement
- campaign lifecycle automation

Open:

- overnight send windows are still a limitation in the current implementation

#### Phase 18

Completed:

- outbound reply markers
- inbound reply matching by marker then sender fallback
- campaign reply visibility
- unmatched inbound reply diagnostics
- admin reply test IDs for manual testing

Open:

- more provider-specific inbound parsing variants can still be added

#### Phase 19

Completed:

- Reply-to-Lead settings panel
- webhook lead conversion response fields
- deterministic direct insert into Perfex `tblleads` for inbound webhook conversion
- duplicate-safe existing lead reuse by sender email
- lead metadata in campaign reply views
- safe default lead status/source seeding:
  - statuses: `New Lead`, `Prospect`
  - sources: `Email Reply`, `Marketing Reply`
- one-time normalization of older reply-created leads from `Email Reply` to `Marketing Reply`

Open:

- complete end-to-end validation in live Perfex environments where lead table columns or lead workflows are customized

#### Phase 20

Completed:

- safe schema dependency inventory for module-owned marketing tables
- non-destructive schema health checker
- admin-visible Schema Health panel
- CSRF-safe `Repair Marketing Schema` action
- fresh install safe table creation path
- existing install safe missing-column repair path
- deployment and operations documentation in `README.md` and `docs/OPERATIONS.md`

Open:

- broader live validation against older installs with unusual manual schema drift
- decide whether any currently manual-review-only schema cases should become automatically repairable

### Still To Work On

- add repeatable smoke/integration tests for:
  - send pipeline
  - webhook ingestion
  - builder serialization
  - inbound reply and Reply-to-Lead
- validate all implemented flows against fresh installs, upgraded installs, and environments with partially missing marketing tables
- decide whether future channel integrations remain placeholders or become active roadmap items
- evaluate whether a future explicit schema versioning strategy is needed beyond the current safe bootstrap and repair model


## Verification Commands

Run from CRM root:

```bash
rg --files modules/marketing
php -l modules/marketing/marketing.php
php -l modules/marketing/controllers/Campaigns.php
php -l modules/marketing/models/Marketing_campaigns_model.php
php -l modules/marketing/migrations/001_initial_schema.php
```

Useful ongoing checks:

```bash
rg -n "Module Name:|register_activation_hook|register_language_files|register_staff_capabilities" modules/marketing
rg -n "after_cron_run|csrf_exclude_uris|html_purify|access_denied|staff_cant" modules/marketing
node --check modules/marketing/assets/js/marketing-builder.js
find modules/marketing -name "*.php" -print0 | xargs -0 -n1 php -l
```

Manual Perfex verification:

1. Activate the module and confirm permissions/menu visibility.
2. Confirm settings load and save correctly:
   - General
   - SMTP
   - Reply-to-Lead
3. Confirm Reply-to-Lead defaults appear in Marketing Settings:
   - Statuses include `New Lead` and `Prospect`
   - Sources include `Email Reply` and `Marketing Reply`
4. Confirm Schema Health shows `OK` or only expected manual-review items.
5. Confirm `Repair Marketing Schema` only creates missing tables / safe columns and does not change live data destructively.
6. Confirm the one-time reply-created lead normalization has run:
   - older leads named `Marketing Reply...` no longer remain on source `Email Reply`
7. Create/import audience contacts and verify segmentation estimates.
8. Create/edit/save/reopen a campaign in the builder.
9. Verify unsubscribe enforcement and insertion do not reset the design.
10. Prepare recipients and verify duplicate suppression / audience exclusions.
11. Run cron and verify scheduled sends, send windows, and rate limiting.
12. Post SES/SNS sample payloads and verify normalized events/suppressions.
13. Post inbound reply payloads and verify:
    - conversation capture
    - reply matching
    - Reply-to-Lead conversion behavior
    - campaign reply visibility


## Risks / Blockers

### High Risk

- Open/click tracking is not provided by core SMTP alone.
- Reliable delivered/open/bounce/complaint stats require SES SNS or provider-specific webhook support.
- If sending through arbitrary third-party SMTP without event webhooks, only `queued/sent/failed` can be guaranteed.
- The remaining schema risk is now concentrated in unusual manual schema drift and upgrade edge cases, not in the basic fresh-install bootstrap path.

### Medium Risk

- Core email queue stores full payloads; very large attachment-heavy campaigns can stress DB size.
- Shared global SMTP settings mean campaign sending identity must respect existing CRM email configuration unless module adds per-campaign overrides carefully.
- Public webhook endpoints need explicit CSRF exclusion and strong signature validation.
- Inbound reply-to-lead behavior can create noisy/duplicate leads without robust matching and throttling.
- Reply-to-Lead behavior may vary across Perfex installs if the local `tblleads` columns or downstream lead workflows are customized.

### Product / Scope Risk

- WhatsApp Business, Facebook Page, LinkedIn DM are not same-week full integrations if OAuth, webhook verification, and message ingestion are included.
- For one-week delivery they should remain settings placeholders + adapter contracts only.

### Data Risk

- Email normalization and suppression rules must be consistent across lists, imports, replies, and webhook events.
- Existing leads/contacts may not have consent states suitable for marketing use; module should avoid assuming GDPR-safe opt-in.


## Exact Next Coding Prompt Recommendation

Use this next:

> Reconcile the `modules/marketing` implementation with a production-safe schema/install strategy. Keep all existing campaign, builder, analytics, webhook, segmentation, scheduling, inbound reply, and Reply-to-Lead behavior intact. Work only inside `modules/marketing`. Do not use risky core migration helpers or direct metadata-table inspection. First inspect which marketing tables/columns the code actually depends on, compare that to the current safe-mode `001_initial_schema.php`, then produce a minimal hardening plan and implement only the non-destructive install/activation improvements that can be done safely without risking existing live data. After coding, run PHP lint on all changed files and summarize remaining schema gaps explicitly.
