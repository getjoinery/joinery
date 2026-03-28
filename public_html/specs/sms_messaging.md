# SMS Messaging Integration Spec

## Overview

Add SMS/text messaging capability to the Joinery platform. This provides a general-purpose SMS sending infrastructure (similar to the existing Mailgun email integration) that can be used by any feature — 2FA verification codes, event reminders, booking confirmations, admin notifications, etc.

## Architecture

The integration follows the same pattern as the existing email system: a helper class wraps a third-party API provider, credentials are stored in settings, and any part of the system can send messages through a simple static method call.

## Provider Options

| Provider | US Cost/msg | Phone # Cost | PHP SDK | Notes |
|----------|-------------|-------------|---------|-------|
| **Telnyx** | ~$0.004 | ~$1.00/mo | Yes (`telnyx/telnyx-php`) | Cheapest per-message, good API, less community support |
| **Plivo** | ~$0.005 | ~$0.50/mo | Yes (`plivo/plivo-php`) | Best balance of cheap + usable, good docs |
| **AWS SNS** | ~$0.00645 | N/A (uses shared pool) | Yes (`aws/aws-sdk-php`) | Cheapest if already on AWS, no-frills, best for one-way only |
| **Vonage (Nexmo)** | ~$0.0068 | ~$1.00/mo | Yes (`vonage/client-core`) | Better international rates, solid API |
| **Twilio** | ~$0.0079 | ~$1.15/mo | Yes (`twilio/sdk`) | Industry standard, best docs/community, most expensive |

**Recommendation:** Twilio or Plivo. Twilio has the best documentation and community support. Plivo is ~35% cheaper with a good API. At low volume (under 1000 msgs/month) the cost difference is negligible — pick based on developer experience. The implementation below is provider-agnostic; switching providers only requires changing the helper class internals.

**Carrier registration note:** All providers require A2P 10DLC registration for US messaging (~$15 one-time + $2/month). This is a carrier requirement, not provider-specific. Without it, messages get filtered as spam. The provider walks you through the registration process.

## Implementation Plan

### Phase 1: Core Infrastructure

**Composer dependency (pick one):**
```
twilio/sdk ^7.0
# or
plivo/plivo-php ^4.0
```

**New settings (migration):**
| Setting | Default | Purpose |
|---------|---------|---------|
| `sms_active` | `false` | Master on/off switch for SMS |
| `sms_provider` | `twilio` | Which provider to use (for future multi-provider support) |
| `sms_account_id` | `` | Account SID (Twilio) or Auth ID (Plivo) |
| `sms_auth_token` | `` | Auth token |
| `sms_phone_number` | `` | Sender phone number (purchased from provider, E.164 format e.g. `+15551234567`) |

**Helper class (`includes/SmsHelper.php`):**
```php
class SmsHelper {
    private static $instance = null;
    private $provider;
    private $from_number;

    public static function get_instance()     // Singleton, loads credentials from settings
    public function send($to, $body)          // Send SMS, returns success/failure + message SID
    public static function sendSms($to, $body) // Static convenience wrapper

    // Formatting
    public static function formatE164($phone, $country_code) // Ensure E.164 format for API
}
```

Usage from anywhere in the system:
```php
require_once(PathHelper::getIncludePath('includes/SmsHelper.php'));
SmsHelper::sendSms('+15551234567', 'Your verification code is 123456');
```

**Logging — use existing RequestLogger:**
Each SMS send should be logged via `RequestLogger::log()` with type `sms` for debugging and cost tracking. No new table needed.

**Phone number formatting:**
The existing `phn_phone_numbers` table stores numbers with country codes. `SmsHelper::formatE164()` combines `phn_country_code` + `phn_phone_number` into the E.164 format required by all SMS APIs (e.g., `+15551234567`). The `PhoneNumber::get_formatted_country_code()` method already provides the `+1` style prefix.

**Files to create/modify:**
| File | Action |
|------|--------|
| `composer.json` | Add SMS provider SDK |
| `includes/SmsHelper.php` | **New** — SMS sending helper |
| `migrations/migrations.php` | Add 5 SMS settings |

### Phase 2: Admin Configuration

**Admin settings (adm/admin_settings.php):**
Add an "SMS / Text Messaging" section with:
- `sms_active` — checkbox: "Enable SMS messaging"
- `sms_provider` — dropdown: Twilio / Plivo / Telnyx / Vonage
- `sms_account_id` — text: "Account SID / Auth ID"
- `sms_auth_token` — password field: "Auth Token"
- `sms_phone_number` — text: "Sender Phone Number (E.164 format)"
- "Send Test SMS" button — sends a test message to a specified number

**Files to modify:**
| File | Action |
|------|--------|
| `adm/admin_settings.php` | Add SMS settings section |

### Phase 3: Inbound SMS (Optional, Future)

If two-way messaging is needed later:
- **Webhook endpoint**: `ajax/sms_webhook.php` receives delivery status callbacks and inbound messages (same pattern as Mailgun inbound)
- **Inbound storage**: New `ism_inbound_sms` table (mirrors `iem_inbound_emails` pattern)
- **Webhook URL** configured in the provider's dashboard, pointing to `https://yourdomain.com/ajax/sms_webhook`

This phase is not needed for outbound-only use cases (2FA codes, notifications, reminders).

## Integration Points

Once the core infrastructure is in place, SMS can be used by:

| Feature | Use Case | Priority |
|---------|----------|----------|
| **2FA verification** | Send TOTP code via SMS as alternative to authenticator app | Pairs with TOTP spec |
| **Event reminders** | "Your event starts in 1 hour" | Scheduled task |
| **Booking confirmations** | "Your booking is confirmed for Tuesday at 3pm" | On booking creation |
| **Admin alerts** | Notify admin of critical events (failed payments, etc.) | On trigger |
| **Password reset** | SMS-based password reset code | Alternative to email |

Each of these would be a separate feature that calls `SmsHelper::sendSms()` — the SMS spec only covers the infrastructure layer.

## Cost Considerations

- At low volume (<500 msgs/month), total cost is under $5/month regardless of provider
- Phone number: $0.50-1.15/month
- A2P 10DLC registration: ~$15 one-time + $2/month
- Per-message: $0.004-0.008 depending on provider
- **International messages cost significantly more** ($0.05-0.15+/msg) — consider gating international SMS behind a setting
- SMS segments are 160 characters; longer messages are split into multiple billable segments

## Files Summary

### New Files
| File | Purpose |
|------|--------|
| `includes/SmsHelper.php` | SMS sending helper class |

### Modified Files
| File | Changes |
|------|---------|
| `composer.json` | Add SMS provider SDK dependency |
| `migrations/migrations.php` | Add 5 SMS settings |
| `adm/admin_settings.php` | Add SMS configuration section |
