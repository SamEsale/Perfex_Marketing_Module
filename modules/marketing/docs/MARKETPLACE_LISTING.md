# Marketplace Listing

## Title

Perfex CRM Marketing Module

## Short Description

Commercial marketing automation module for Perfex CRM with campaign builder, SMTP / SES delivery, Reply-to-Lead workflows, WhatsApp Business foundation, and non-destructive schema health tools.

## Positioning

This package is a **module for Perfex CRM**. It is not standalone software.

## Compatibility

- Compatible target: `Perfex CRM 3.4.x`
- Tested on: `Perfex CRM 3.4.1`

## Key Features

- Audience management and CSV imports
- Email campaign builder with save/reopen support
- SMTP / Amazon SES delivery
- SES / SNS webhook ingestion
- Reply-to-Lead conversion from inbound email replies
- WhatsApp Business settings, webhook verification, and template campaign foundation
- Schema Health and non-destructive repair flow
- Commercial license activation panel

## Operational Notes

- Staging deployment is recommended before production
- Perfex cron is required for campaign execution
- No core Perfex files are modified
- WhatsApp sending requires a Meta-approved WhatsApp Business app, an active Phone Number ID, a valid non-expired access token, and approved templates

## Security Notes

- No token rendering after save
- No intentional secret logging
- No destructive schema operations

## Suggested Listing FAQ

### Is this standalone software?

No. This is a module for Perfex CRM.

### Does it modify Perfex core files?

No. The module is designed to operate without modifying core Perfex files.

### Is cron required?

Yes. Cron is required for scheduled and queued campaign execution.

### What does the commercial license gate?

Commercial licensing gates send-related commercial actions while leaving safe installation, diagnostics, settings access, and schema repair available.
