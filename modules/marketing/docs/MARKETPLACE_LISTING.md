# Marketing Module for Perfex CRM — CodeCanyon Listing

---

## Item Title

**Marketing Module for Perfex CRM — Email Campaigns, AWS SES, Reply-to-Lead & WhatsApp**

---

## Short Description (80 chars)

Professional email marketing automation for Perfex CRM with AWS SES and Reply-to-Lead.

---

## Item Description

### Turn Perfex CRM into a Full Marketing Platform

Stop switching between your CRM and external email tools. The Marketing Module brings professional campaign management, audience segmentation, and delivery analytics directly into Perfex CRM — where your contacts and leads already live.

Built for businesses that use **Amazon SES** for reliable, affordable email delivery at scale. Send thousands of emails for a fraction of what Mailchimp or SendGrid charge, with full bounce, complaint, and delivery tracking built in.

---

### What You Get

**Campaign Builder**
Create, preview, and schedule HTML email campaigns with a visual editor. Save drafts, reopen, and refine before sending. Set send windows, daily caps, and per-batch limits to protect your sender reputation.

**Audience Management**
Build segmented contact lists from your existing Perfex contacts, leads, and customers. Import contacts via CSV with duplicate detection and validation. Manage subscriptions and suppressions automatically.

**Amazon SES / SNS Integration**
Connect your AWS SES account for high-volume, low-cost email delivery. Real-time delivery tracking through SNS webhooks captures bounces, complaints, opens, clicks, and delivery confirmations — all visible in the analytics dashboard.

**Reply-to-Lead Conversion**
Automatically convert inbound email replies into Perfex leads. The module polls your mailbox via IMAP, matches replies to campaigns, and creates or updates leads with configurable status, source, and staff assignment.

**Inbound Reply Webhook**
Accept authenticated inbound email notifications via webhook for real-time reply processing. Secured with HMAC authentication.

**WhatsApp Business Foundation**
WhatsApp Business API settings, webhook verification, inbound message capture, conversation threading, and manual reply — ready for template-based campaigns when your Meta Business app is approved.

**Analytics Dashboard**
Track campaign performance with delivery rates, open rates, click rates, bounce rates, and complaint rates. Provider health monitoring with queue status and failure signals. Link click analytics with human vs bot filtering.

**Advanced Delivery Controls**
Configure emails per cron run, hourly and daily send caps, sleep intervals between sends, and send time windows. Protect your sender reputation with built-in throttling.

**Schema Health & Diagnostics**
Non-destructive schema repair ensures the module always runs correctly after updates. Runtime integrity diagnostics and provider readiness checks give you confidence in your setup.

**Commercial License Management**
Built-in license activation panel with domain-locked validation, grace period handling, and automatic status checks.

---

### Why Choose This Module?

- **Native Perfex Integration** — no external platforms, no API syncing, no duplicate contacts
- **AWS SES Pricing** — send 10,000 emails for ~$1 vs $100+ on other platforms
- **Real-Time Tracking** — bounces, complaints, opens, and clicks via SNS webhooks
- **Reply-to-Lead** — automatically capture interested prospects as Perfex leads
- **Zero Core Modifications** — installs as a standard Perfex module, uninstalls cleanly
- **Production Tested** — actively used in production environments

---

### Requirements

- Perfex CRM 3.2 or higher (tested on 3.4.1)
- PHP 8.1 or higher
- PHP IMAP extension (for Reply-to-Lead mailbox polling)
- Perfex cron job configured (required for campaign sending)
- Amazon SES account with verified sending identity (for email delivery)
- Amazon SNS topic with HTTPS subscription (for delivery tracking)

---

### What's Included

- Full source code (no encryption, no obfuscation)
- Installation guide with step-by-step AWS SES setup instructions
- Audience management with CSV import
- Email campaign builder with visual editor
- SMTP / Amazon SES delivery engine
- SES / SNS webhook receiver for delivery events
- Reply-to-Lead email conversion system
- Inbound reply webhook with HMAC authentication
- WhatsApp Business foundation (settings, webhooks, conversations)
- Analytics dashboard with delivery and engagement metrics
- Schema health diagnostics and non-destructive repair
- Commercial license activation panel
- 6 months of support

---

### Frequently Asked Questions

**Is this standalone software?**
No. This is a module for Perfex CRM. You need a licensed copy of Perfex CRM installed.

**Does it modify Perfex core files?**
No. The module operates entirely within the standard Perfex module architecture. No core files are modified. Clean uninstall is supported.

**Is a cron job required?**
Yes. Perfex cron must be configured for campaign queue processing, scheduled sends, and mailbox polling. The module provides the exact cron command in the settings panel.

**Can I use any SMTP provider?**
Yes. While the module is optimized for Amazon SES with full SNS webhook support, any SMTP provider (Gmail, SendGrid, Mailgun, etc.) can be used for basic email delivery.

**What does the license cover?**
One license covers one Perfex CRM installation. The license gates commercial send actions while leaving installation, settings, diagnostics, and schema repair fully accessible.

**Is WhatsApp sending included?**
The module includes WhatsApp Business API integration for inbound messages, conversations, and manual replies. Template-based campaign sending requires a Meta-approved WhatsApp Business app with an active phone number and approved message templates.

---

### Changelog

**v1.1.0**
- Commercial license activation panel
- Marketplace preparation and documentation
- Release packaging and validation tools

**v1.0.0**
- Initial release: campaigns, audiences, imports, analytics
- SMTP / SES delivery with SNS webhook tracking
- Reply-to-Lead email conversion
- WhatsApp Business foundation
- Schema health diagnostics

---

### Tags

perfex crm, marketing module, email campaign, amazon ses, email marketing, newsletter, perfex module, crm marketing, bulk email, ses sns, reply to lead, whatsapp business, email automation, perfex addon, perfex extension
