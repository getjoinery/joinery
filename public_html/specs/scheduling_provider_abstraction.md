# Scheduling Provider Abstraction

## Goal

Decouple scheduling/appointment integrations so swapping providers (Cal.com, Square Appointments, a future Calendly re-integration, etc.) requires creating a single new class, not editing scattered business logic, views, and admin pages.

Follows the pattern established by the existing email provider abstraction (`includes/EmailServiceProvider.php` + `includes/email_providers/*Provider.php`).

This is one of three specs split out from the prior `external_service_abstraction.md`. The other two — theme fonts configuration and mailing list provider abstraction — are independent and unrelated.

---

## Open Decisions

These need to be resolved before implementation begins.

### D1: Webhook contract on the interface

Acuity (the only current provider) has no webhook flow. But Calendly used webhooks (`calendly_webhook.php`, since deleted) and any realistic swap target (Cal.com, Square) does too. Options:

- **A.** Add `getWebhookEndpoint(): ?string` and `handleWebhook(array $payload): void` to the interface. Providers without webhooks return `null` / no-op.
- **B.** Don't put webhooks in the interface. Each provider that needs webhooks registers its own `/ajax/{provider}_webhook.php` endpoint outside the interface.
- **C.** Punt — Acuity has no webhooks, so this spec doesn't address them. Add when a webhook-using provider lands.

Recommendation: **C**. Adding interface methods speculatively is risky — the right shape only emerges from a real second provider.

### D2: `validateApiConnection()` on the interface

The reference pattern (`EmailServiceProvider`) does NOT have this method. Connection-testing is done by admin pages calling provider methods directly. Options:

- **A.** Keep `validateApiConnection()` on the new interface (slight divergence from email pattern, but useful and explicit).
- **B.** Drop it. Put connection-test code in a provider-specific admin partial, matching the email pattern.

Recommendation: **B**. Sibling abstractions should be consistent; if connection testing is worth elevating, do it on `EmailServiceProvider` first.

### D3: Bookings plugin scope

The bookings plugin's columns are Calendly-shaped (`bkn_calendly_event_uri`, etc.). Calendly is gone, Acuity has no booking integration in current code. After this spec lands, what does the bookings plugin look like?

- **A.** This spec wires Acuity into the bookings plugin (new sync utility, store appointments as Booking rows). Largest scope.
- **B.** This spec only abstracts the `get_appointments_logic.php` flow (the `/profile/appointments` page). The bookings plugin is left alone — its columns get renamed to provider-neutral names but stay unused by Acuity. Smaller scope. The plugin remains available for the user's separate Calendly-clone work.
- **C.** This spec abstracts both, but the bookings-plugin Acuity adapter is stubbed/empty.

Recommendation: **B**. The bookings plugin is its own product surface (the user is building a Calendly clone on top of it). Wiring Acuity into it is a separate decision that doesn't need to ride along with the abstraction work.

### D4: `getEmbedCode()` source

The drafted interface includes `getEmbedCode(): array`, but `AcuityScheduling::getEmbedCode()` doesn't exist in current code — Acuity embeds are presumably copy-pasted into views as `<script>` snippets. Options:

- **A.** Drop `getEmbedCode()` from the interface entirely. Embed snippets live in views, not in the provider.
- **B.** Keep it; this spec builds it as new functionality (find Acuity's embed pattern, codify).
- **C.** Find where Acuity's embed currently lives in the system and align.

Recommendation: **A**. Don't speculatively add interface methods. If a future feature needs programmatic embeds, add it then with concrete requirements.

---

## Current State

Direct Acuity API calls with no abstraction. Each touchpoint hardcodes Acuity assumptions.

**Files involved:**
- `includes/AcuityScheduling.php` — 175-line cURL-based API client
- `includes/AcuitySchedulingOAuth.php` — 81-line OAuth2 extension
- `logic/get_appointments_logic.php` — directly instantiates `AcuityScheduling`, calls `/appointments` endpoint
- `adm/admin_settings.php` (~lines 781–786) — Acuity API key/user ID fields, API validation panel
- `plugins/bookings/data/bookings_class.php` — `bkn_calendly_event_uri` column, `GetByCalendlyUri()` method
- `plugins/bookings/data/booking_types_class.php` — `bkt_calendly_event_type_uri` column, `GetByCalendlyUri()` method
- `plugins/bookings/admin/admin_booking.php` — calls `GetByCalendlyUri()`
- `data/users_class.php` — `usr_calendly_uri` column, `User::GetByCalendlyUri()` method
- Settings: `acuity_user_id`, `acuity_api_key`

The bookings plugin's data model carries Calendly-shaped column names from a prior integration. The Calendly code itself was removed in a separate cleanup; the schema lingers.

The `tracking[salesforce_uuid]` correlation pattern (passing the local booking ID through the provider's UTM passthrough so the webhook can find the local row) is documented in the `Booking` class header. Future provider integrations should reuse this technique with an honest field name (e.g., `joinery_booking_id`).

---

## Design

### `SchedulingProvider` interface

Create `includes/scheduling_providers/SchedulingProvider.php`:

```php
interface SchedulingProvider {
    /** Provider unique key (e.g., 'acuity'). Stored in scheduling_provider setting. */
    public static function getKey(): string;

    /** Human-readable label for admin UI. */
    public static function getLabel(): string;

    /**
     * Setting field definitions for the admin settings page.
     * Each entry: ['key' => 'setting_name', 'label' => 'Human Label',
     *              'type' => 'text|password|textarea', 'helptext' => '...']
     */
    public static function getSettingsFields(): array;

    /**
     * Validate that this provider's required settings are configured.
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validateConfiguration(): array;

    /**
     * Get upcoming appointments for a user by email.
     * Returns array of normalized appointment objects:
     *   ['id' => string, 'type' => string, 'datetime' => string (ISO 8601),
     *    'timezone' => string, 'calendar' => string, 'location' => string|null,
     *    'confirmation_url' => string|null]
     */
    public function getUpcomingAppointments(string $email): array;
}
```

Per Open Decisions D1, D2, D4: no webhook methods, no `validateApiConnection`, no `getEmbedCode`. Add later when a concrete second provider needs them.

### `SchedulingService` manager

`includes/SchedulingService.php` — auto-discovers providers via glob on `includes/scheduling_providers/*Provider.php`, same pattern as `EmailSender::discoverProviders()`. Provides `getProvider(string $key = null): ?SchedulingProvider`, `getAvailableServices(): array`, `getProviderSettings(string $key): array`.

---

## Implementation

### Phase 1: Build the abstraction (no consumer changes)

**1a.** Create `includes/scheduling_providers/SchedulingProvider.php` (interface).

**1b.** Create `includes/SchedulingService.php` (discovery + factory).

**1c.** Create `includes/scheduling_providers/AcuityProvider.php`:
- `getKey()` → `'acuity'`
- `getSettingsFields()` returns entries for `acuity_api_key`, `acuity_user_id`
- `getUpcomingAppointments()` wraps the current `/appointments` call from `get_appointments_logic.php`, returning normalized arrays
- `validateConfiguration()` checks both settings are non-empty
- Internally uses `AcuityScheduling.php` (no extraction; the existing client stays as the implementation detail)

After Phase 1: the abstraction exists; nobody calls it yet.

### Phase 2: Wire consumers

**2a.** Refactor `logic/get_appointments_logic.php`:
```php
$provider = SchedulingService::getProvider();
if ($provider) {
    $appointments = $provider->getUpcomingAppointments($user->get('usr_email'));
}
```

**2b.** Add `scheduling_provider` setting via `settings.json` (factory default) seeded with `'acuity'`. No migration needed — the settings system seeds factory defaults automatically.

After Phase 2: appointments page uses the provider; swapping providers becomes mechanically possible.

### Phase 3: Dynamic admin UI

**3a.** Replace hardcoded Acuity fields and validation in `adm/admin_settings.php` with rendering driven by `SchedulingService::getAvailableServices()` and provider `getSettingsFields()`.

After Phase 3: adding a new provider auto-populates the admin UI.

### Phase 4: Rename Calendly-shaped columns to generic names

Per Open Decision D3 recommendation B, the bookings plugin doesn't get an Acuity adapter — but the column names still need to be provider-neutral so they don't lie about their content.

- `bkn_calendly_event_uri` → `bkn_provider_event_id`
- `bkt_calendly_event_type_uri` → `bkt_provider_event_type_id`
- `usr_calendly_uri` → `usr_provider_user_id`
- `Booking::GetByCalendlyUri()` → `GetByProviderEventId()`
- `BookingType::GetByCalendlyUri()` → `GetByProviderEventTypeId()`
- `User::GetByCalendlyUri()` → `GetByProviderUserId()`
- Update Multi class option keys
- Update `admin_booking.php` and any other live callers

Use `ALTER TABLE ... RENAME COLUMN` migrations coordinated with the `$field_specifications` change in the same release. `update_database` does not rename columns automatically.

This phase has no functional changes; it's pure rename. Could ship before or after Phase 1–3.

---

## Edge Cases

### No provider configured
If `scheduling_provider` is empty or the provider class doesn't exist, `SchedulingService::getProvider()` returns null. `get_appointments_logic.php` handles this with the existing "No appointments" rendering — same as the current catch-all behavior.

### Composer dependencies
If a future provider needs an SDK, the provider file loads composer autoload via `PathHelper::getComposerAutoloadPath()` (same pattern as `MailgunProvider`). Acuity's current cURL-based client has no composer dependency.

### The tracking-passthrough correlation pattern
Documented as a class-level docblock on `Booking` (`plugins/bookings/data/bookings_class.php`). Any provider integration that creates Booking rows from external events must echo the local `bkn_booking_id` through the provider's tracking/passthrough field and read it back in the webhook handler. This is the supported pattern for correlating local rows with external scheduled events.

---

## File Changes Summary

| File | Action |
|---|---|
| **Phase 1: Abstraction layer** | |
| `includes/scheduling_providers/SchedulingProvider.php` | **New** — interface |
| `includes/scheduling_providers/AcuityProvider.php` | **New** — wraps AcuityScheduling.php |
| `includes/SchedulingService.php` | **New** — discovery + factory |
| **Phase 2: Wire consumers** | |
| `logic/get_appointments_logic.php` | **Modify** — use SchedulingService |
| `settings.json` | **Modify** — add `scheduling_provider` factory default |
| **Phase 3: Dynamic admin UI** | |
| `adm/admin_settings.php` | **Modify** — dynamic provider section |
| **Phase 4: Column renames (independent)** | |
| `plugins/bookings/data/bookings_class.php` | **Modify** — rename column + method |
| `plugins/bookings/data/booking_types_class.php` | **Modify** — rename column + method |
| `plugins/bookings/admin/admin_booking.php` | **Modify** — use renamed method |
| `data/users_class.php` | **Modify** — rename column + method |
| `migrations/migrations.php` | **Modify** — `ALTER TABLE RENAME COLUMN` migrations |
| **Documentation** | |
| `docs/plugin_developer_guide.md` | **Update** — document `SchedulingProvider` interface |

---

## Testing

- Verify `/profile/appointments` still loads and displays correctly for a logged-in user with Acuity configured (Phase 2).
- Verify admin settings page renders scheduling provider fields dynamically (Phase 3).
- Verify connection test still works from admin settings (Phase 3).
- Verify renamed methods (`GetByProviderEventId`, etc.) replace all callers of the old names (Phase 4).
- Verify the tracking-passthrough correlation pattern documented on the `Booking` class is honored by any new provider (when one lands).
