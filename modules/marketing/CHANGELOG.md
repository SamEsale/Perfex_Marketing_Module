# Changelog

## 1.1.0

- Added commercial marketplace preparation documentation for install, support, operations, licensing, and listing copy
- Added `License & Updates` settings panel with masked key handling and read-only status metadata
- Added `Marketing_license` client library with configurable API base URL support, domain fingerprint payloads, and 7-day grace handling
- Added safe commercial-action license enforcement for campaign sends, WhatsApp campaign sends, and large recipient-batch preparation
- Added module-local release build script at `scripts/build_release.sh`
- Preserved v1.0.0 non-destructive schema behavior and existing settings/webhook flows

## 1.0.0

- Initial production-ready release of the Perfex CRM Marketing module
- Included campaigns, audiences, imports, analytics, SMTP / SES delivery, SES / SNS webhooks, Reply-to-Lead, WhatsApp foundation, and Schema Health
