# Support

## Product Scope

The Perfex Marketing Module is a module for **Perfex CRM**. Support does not cover running it as standalone software.

## Recommended Support Workflow

1. Reproduce on staging first when possible.
2. Record the exact Perfex CRM version, PHP version, module version, and active delivery channel.
3. Confirm whether the issue is in General settings, SMTP / SES, Reply-to-Lead, WhatsApp, Schema Health, or License & Updates.
4. Capture sanitized screenshots or logs. Do not include access tokens, SMTP passwords, or full license keys.

## Include In A Support Request

- Perfex CRM version
- Marketing module version
- PHP version
- Current domain
- Whether cron is running
- Whether the issue affects email, Reply-to-Lead, WhatsApp, or licensing
- Sanitized error message text

## Notable Operational Checks

- General settings save
- SMTP settings save and SMTP test
- Reply-to-Lead settings save
- WhatsApp settings save
- Schema Health report
- SES / SNS webhook URL reachability
- WhatsApp webhook verification
- License status and last checked timestamp

## Security Expectations

- Never send access tokens, SMTP passwords, customer lists, campaign contents, or webhook payloads unless they are explicitly sanitized
- License keys should be shared only through secure support channels and never pasted into public issue trackers
