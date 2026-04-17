# External Service Abstraction

## Goal

Identify and decouple tightly-coupled external service integrations so that swapping providers requires creating a single new class — not modifying business logic, views, or admin pages scattered across the codebase. This follows the same pattern established by the email provider abstraction.

**Explicitly out of scope:** Payment processors (Stripe, PayPal). These cover the entire realistic market for a platform like this, the integration surface is enormous, and a swap scenario is essentially hypothetical. The existing two-provider setup is the industry standard and is working.

## Services Covered

### 1. Scheduling/Appointments (Acuity Scheduling)

**Current state:** Direct Acuity API calls with no abstraction. Dead Calendly code also exists (disabled).

**Why this matters:** Scheduling is a category where people genuinely switch providers — Cal.com, Calendly, Square Appointments, and others are viable alternatives. The bookings plugin already has Calendly-specific column names (`bkn_calendly_event_uri`, `bkt_calendly_event_type_uri`) baked into its schema, making this coupling visible at the database level.

**Files involved:**
- `includes/AcuityScheduling.php` — 175-line API client (cURL-based, basic auth)
- `includes/AcuitySchedulingOAuth.php` — 81-line OAuth2 extension
- `logic/get_appointments_logic.php` — directly instantiates `AcuityScheduling`, calls `/appointments` endpoint
- `adm/admin_settings.php` (~lines 748-790) — Acuity API key/user ID fields, API validation panel
- `plugins/bookings/data/bookings_class.php` — `bkn_calendly_event_uri` column, `GetByCalendlyUri()` method
- `plugins/bookings/data/booking_types_class.php` — `bkt_calendly_event_type_uri` column, `GetByCalendlyUri()` method
- `plugins/bookings/admin/admin_booking_types.php` — hardcoded "Sync with Calendly" link
- `plugins/bookings/admin/admin_bookings.php` — hardcoded "Sync with Calendly" link
- `plugins/bookings/admin/admin_booking.php` — calls `GetByCalendlyUri()`
- `ajax/calendly_webhook.php` — disabled Calendly webhook handler
- `ajax/calendly_init.php` — disabled Calendly init
- `utils/calendly_synchronize.php` — disabled Calendly sync utility
- Settings: `acuity_user_id`, `acuity_api_key`, `calendly_organization_uri`, `calendly_organization_name`, `calendly_api_key`, `calendly_api_token`

### 2. Mailing List Provider (MailChimp)

**Current state:** Direct MailChimp SDK usage embedded in model classes and a utility script. The `MailingList` model directly calls the MailChimp API on subscribe/unsubscribe, making it impossible to swap providers without editing the model.

**Why this matters:** MailChimp alternatives are common (ConvertKit, Brevo/Sendinblue, Buttondown, Listmonk). The mailing list concept is generic — the provider is an implementation detail.

**Files involved:**
- `data/mailing_lists_class.php` — `use MailchimpAPI\Mailchimp` at top of file, `subscribe_to_mailchimp_list()` and `unsubscribe_from_mailchimp_list()` methods called inline during `add_registrant()` / `remove_registrant()`, `mlt_mailchimp_list_id` column
- `data/contact_types_class.php` — `ctt_mailchimp_list_id` column
- `data/users_class.php` — `usr_mailchimp_user_id` column
- `utils/mailchimp_synchronize.php` — 153-line sync script, directly uses MailChimp SDK
- `adm/admin_settings_email.php` (~lines 182-215) — MailChimp API key field, API validation panel
- `adm/admin_mailing_list_edit.php` — "Mailchimp List ID" text input
- `adm/admin_mailing_list.php` — displays MailChimp status
- `adm/admin_contact_type.php` — displays MailChimp list ID
- `adm/admin_contact_type_edit.php` — "Mailchimp List ID" text input
- `adm/logic/admin_contact_type_edit_logic.php` — saves `ctt_mailchimp_list_id`
- `adm/logic/admin_mailing_list_edit_logic.php` — saves `mlt_mailchimp_list_id`
- Composer: `jhut89/mailchimp3php` dependency
- Settings: `mailchimp_api_key`

### 3. Google Fonts (Hardcoded CDN)

**Current state:** Every theme's `PublicPage.php` hardcodes `fonts.googleapis.com` URLs with theme-specific font families. There is no configuration — changing fonts requires editing PHP files.

**Why this matters:** This is less about swapping providers and more about configurability. Self-hosting fonts (for GDPR compliance or performance), using a different CDN, or simply changing font families currently requires editing PHP source. The `theme.json` config already exists for each theme but has no font configuration.

**Files with hardcoded Google Fonts URLs:**
- `includes/PublicPage.php` — Inter + Playfair Display
- `includes/PublicPageJoinerySystem.php` — Open Sans + Poppins
- `includes/PublicPageFalcon.php` — Open Sans + Poppins
- `theme/empoweredhealth/includes/PublicPage.php` — Poppins
- `theme/empoweredhealth-html5/includes/PublicPage.php` — Poppins
- `theme/zoukroom/includes/PublicPage.php` — Nunito Sans
- `theme/zoukroom-html5/includes/PublicPage.php` — Nunito Sans
- `theme/getjoinery/includes/PublicPage.php` — Inter + Manrope
- `plugins/scrolldaddy/includes/PublicPage.php` — DM Sans + Poppins

---

## Design

### 1. Scheduling Provider Interface

Create `includes/scheduling_providers/SchedulingProvider.php`:

```php
interface SchedulingProvider {
    /**
     * Return the provider's unique key (e.g., 'acuity', 'calendly').
     * Stored in the scheduling_provider setting.
     */
    public static function getKey(): string;

    /**
     * Return a human-readable label for admin UI.
     */
    public static function getLabel(): string;

    /**
     * Return an array of setting keys this provider requires.
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
     * Optional: Test the API connection and return status for admin UI.
     * Returns ['success' => bool, 'label' => string, 
     *          'details' => [...], 'error' => string|null]
     */
    public static function validateApiConnection(): array;

    /**
     * Get upcoming appointments for a user by email.
     * Returns array of normalized appointment objects:
     * [
     *   ['id' => string, 'type' => string, 'datetime' => string (ISO 8601),
     *    'timezone' => string, 'calendar' => string, 'location' => string|null,
     *    'confirmation_url' => string|null]
     * ]
     */
    public function getUpcomingAppointments(string $email): array;

    /**
     * Get an embed widget URL/HTML for scheduling.
     * Returns ['type' => 'iframe'|'script', 'html' => string]
     */
    public function getEmbedCode(array $options = []): array;
}
```

**Provider discovery** uses the same glob pattern as email providers — drop a `*Provider.php` file in `includes/scheduling_providers/` and it auto-registers.

**Scheduling service manager** in `includes/SchedulingService.php`:

```php
class SchedulingService {
    private static $providers = null;

    public static function discoverProviders(): array { /* glob pattern */ }
    public static function getProvider(string $key = null): ?SchedulingProvider { /* ... */ }
    public static function getAvailableServices(): array { /* key => label */ }
    public static function getProviderSettings(string $key): array { /* ... */ }
}
```

### 2. Mailing List Provider Interface

Create `includes/mailing_list_providers/MailingListProvider.php`:

```php
interface MailingListProvider {
    /**
     * Return the provider's unique key (e.g., 'mailchimp', 'convertkit').
     */
    public static function getKey(): string;

    /**
     * Return a human-readable label for admin UI.
     */
    public static function getLabel(): string;

    /**
     * Return setting key definitions for admin settings page.
     */
    public static function getSettingsFields(): array;

    /**
     * Validate provider configuration.
     */
    public static function validateConfiguration(): array;

    /**
     * Optional: Test the API connection.
     */
    public static function validateApiConnection(): array;

    /**
     * Subscribe a user to a remote list.
     * $remote_list_id is the provider-specific list/audience ID.
     * Returns the provider's subscriber ID (stored in usr_mailing_list_provider_id).
     */
    public function subscribe(string $remote_list_id, string $email,
                              string $first_name, string $last_name): ?string;

    /**
     * Unsubscribe a user from a remote list.
     */
    public function unsubscribe(string $remote_list_id, string $email): bool;

    /**
     * Get all subscribers from a remote list for sync purposes.
     * Yields arrays: ['email' => string, 'status' => 'subscribed'|'unsubscribed'|'cleaned',
     *                 'last_changed' => string (ISO 8601)]
     * Uses generator/pagination internally to handle large lists.
     */
    public function getSubscribers(string $remote_list_id, int $offset = 0,
                                   int $limit = 1000): array;

    /**
     * Get available lists/audiences from the remote provider.
     * Returns [['id' => string, 'name' => string, 'member_count' => int]]
     */
    public function getLists(): array;
}
```

### 3. Google Fonts via theme.json

Add font configuration to `theme.json`:

```json
{
    "name": "Default Theme",
    "cssFramework": "html5",
    "fonts": {
        "url": "https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,700;1,700&display=swap",
        "preconnect": [
            "https://fonts.googleapis.com",
            "https://fonts.gstatic.com"
        ]
    }
}
```

`PublicPageBase` reads this config via `ThemeHelper::config('fonts')` and outputs the `<link>` tags. Theme-specific `PublicPage.php` files no longer hardcode font URLs. Omitting the `fonts` key means no external fonts are loaded (self-hosted or system fonts only).

---

## Implementation

### Phase 1: Scheduling Provider Abstraction

**1a. Create interface and provider directory**

- Create `includes/scheduling_providers/SchedulingProvider.php` with the interface defined above
- Create `includes/SchedulingService.php` with discovery, `getProvider()`, `getAvailableServices()`, `getProviderSettings()`

**1b. Create `includes/scheduling_providers/AcuityProvider.php`**

Extract from `AcuityScheduling.php`:
- Implement all interface methods
- `getKey()` returns `'acuity'`
- `getSettingsFields()` returns entries for `acuity_api_key`, `acuity_user_id`
- `getUpcomingAppointments()` wraps the current `/appointments` API call logic from `get_appointments_logic.php`, returning normalized appointment arrays
- `getEmbedCode()` wraps `AcuityScheduling::getEmbedCode()`
- `validateApiConnection()` wraps the `/me` endpoint check currently in `admin_settings.php`
- Keep `AcuityScheduling.php` and `AcuitySchedulingOAuth.php` as internal implementation details used by the provider

**1c. Refactor `logic/get_appointments_logic.php`**

Replace direct Acuity instantiation with:
```php
$provider = SchedulingService::getProvider();
if ($provider) {
    $appointments = $provider->getUpcomingAppointments($user->get('usr_email'));
    // Render normalized appointments
}
```

**1d. Refactor admin settings (scheduling section)**

Replace the hardcoded Acuity fields and validation in `adm/admin_settings.php` with dynamic rendering from `SchedulingService::getAvailableServices()` and provider `getSettingsFields()`. Add a `scheduling_provider` setting to select the active provider.

**1e. Add `scheduling_provider` setting**

Add migration to insert `scheduling_provider` setting with default value `'acuity'` for existing installs.

**1f. Clean up dead Calendly code**

Remove disabled files that are not part of the provider abstraction:
- `ajax/calendly_init.php`
- `ajax/calendly_webhook.php`
- `utils/calendly_synchronize.php`
- `tests/integration/calendly_test.php`

Remove Calendly settings from `adm/admin_settings.php`: `calendly_organization_uri`, `calendly_organization_name`, `calendly_api_key`, `calendly_api_token`.

**1g. Rename bookings plugin Calendly-specific columns**

The bookings plugin has provider-specific column names that should be generic:
- `bkn_calendly_event_uri` → `bkn_provider_event_id` 
- `bkt_calendly_event_type_uri` → `bkt_provider_event_type_id`
- `GetByCalendlyUri()` → `GetByProviderEventId()` (on both Booking and BookingType)
- Update the corresponding Multi class option keys
- Update `admin_booking.php` and any other references

These are data class field renames — the `update_database` system handles column changes automatically.

### Phase 2: Mailing List Provider Abstraction

**2a. Create interface and provider directory**

- Create `includes/mailing_list_providers/MailingListProvider.php` with the interface defined above
- Create `includes/MailingListService.php` with discovery, `getProvider()`, `getAvailableServices()`, `getProviderSettings()`

**2b. Create `includes/mailing_list_providers/MailChimpProvider.php`**

Extract from `mailing_lists_class.php`:
- Move `subscribe_to_mailchimp_list()` logic into `subscribe()`
- Move `unsubscribe_from_mailchimp_list()` logic into `unsubscribe()`
- Move the sync iteration logic from `utils/mailchimp_synchronize.php` into `getSubscribers()`
- Move the API validation from `admin_settings_email.php` into `validateApiConnection()`
- `getSettingsFields()` returns entries for `mailchimp_api_key`
- `getLists()` wraps the `->lists()->get()` call

**2c. Refactor `data/mailing_lists_class.php`**

Replace `subscribe_to_mailchimp_list()` and `unsubscribe_from_mailchimp_list()` with provider-agnostic calls:

```php
function sync_subscribe($usr_user_id) {
    $provider = MailingListService::getProvider();
    if (!$provider || !$this->get('mlt_provider_list_id')) {
        return true; // No provider configured, local-only operation
    }
    $user = new User($usr_user_id, TRUE);
    $subscriber_id = $provider->subscribe(
        $this->get('mlt_provider_list_id'),
        $user->get('usr_email'),
        $user->get('usr_first_name'),
        $user->get('usr_last_name')
    );
    if ($subscriber_id) {
        $user->set('usr_mailing_list_provider_id', $subscriber_id);
        $user->save();
    }
    return $subscriber_id !== null;
}
```

Remove `use MailchimpAPI\Mailchimp` from the top of the file.

**2d. Rename provider-specific columns**

- `mlt_mailchimp_list_id` → `mlt_provider_list_id` (on MailingList)
- `ctt_mailchimp_list_id` → `ctt_provider_list_id` (on ContactType)
- `usr_mailchimp_user_id` → `usr_mailing_list_provider_id` (on User)
- Update all admin pages that reference these columns (form fields, display labels)

**2e. Refactor `utils/mailchimp_synchronize.php`**

Replace with a provider-agnostic `utils/mailing_list_synchronize.php` that calls `MailingListService::getProvider()->getSubscribers()`. The sync logic (compare timestamps, decide direction) stays the same — only the API calls change.

**2f. Refactor admin settings (mailing list section)**

Replace the hardcoded MailChimp settings in `adm/admin_settings_email.php` with dynamic rendering from `MailingListService::getAvailableServices()` and provider `getSettingsFields()`. Add a `mailing_list_provider` setting.

**2g. Add `mailing_list_provider` setting**

Add migration to insert `mailing_list_provider` setting with default value `'mailchimp'` for existing installs.

### Phase 3: Google Fonts Configuration

**3a. Add `fonts` key to theme.json schema**

Define the schema as shown in the Design section. Update `ThemeHelper` to support `config('fonts')`.

**3b. Add font config to each theme's theme.json**

For each theme that currently hardcodes Google Fonts, add the appropriate `fonts` configuration to its `theme.json`.

**3c. Add font rendering to `PublicPageBase`**

Add a method to `PublicPageBase` that reads font config and outputs the appropriate `<link>` and `<link rel="preconnect">` tags:

```php
protected function render_font_links() {
    $fonts = ThemeHelper::config('fonts');
    if (!$fonts) return;
    
    if (!empty($fonts['preconnect'])) {
        foreach ($fonts['preconnect'] as $url) {
            echo '<link rel="preconnect" href="' . htmlspecialchars($url) . '"';
            if (strpos($url, 'gstatic') !== false) echo ' crossorigin';
            echo '>' . "\n";
        }
    }
    if (!empty($fonts['url'])) {
        echo '<link href="' . htmlspecialchars($fonts['url']) . '" rel="stylesheet">' . "\n";
    }
}
```

**3d. Remove hardcoded font URLs from theme PublicPage files**

Replace the hardcoded `<link>` tags in each theme's `PublicPage.php` with a call to `$this->render_font_links()`. Each theme gets its fonts from `theme.json` instead of PHP source.

---

## Edge Cases

### Scheduling: No provider configured

If `scheduling_provider` is empty or the provider class doesn't exist, `SchedulingService::getProvider()` returns null. `get_appointments_logic.php` handles this gracefully with a "No appointments" message — same as the current catch-all behavior.

### Mailing list: Local-only operation

Sites that don't use any external mailing list provider should still have fully functional local mailing lists. The `sync_subscribe()` / `sync_unsubscribe()` methods return `true` immediately when no provider is configured. The external sync utility (`mailing_list_synchronize.php`) exits early with a message.

### Mailing list: Provider-specific list ID format

MailChimp uses alphanumeric list IDs. ConvertKit uses numeric tag/form IDs. The `mlt_provider_list_id` column is `varchar(255)` — any format works. The admin UI labels this generically ("Remote List ID") with provider-specific help text from `getSettingsFields()`.

### Google Fonts: Themes without theme.json

If a theme has no `theme.json` or no `fonts` key, `render_font_links()` outputs nothing. This is the correct behavior for themes using self-hosted or system fonts.

### Google Fonts: Admin interface

The admin interface uses `PublicPageJoinerySystem` which will also read from theme.json. Since the admin always uses the `joinery-system` theme, its font config goes in `theme/joinery-system/theme.json`.

### Column renames and data migration

Column renames (`mlt_mailchimp_list_id` → `mlt_provider_list_id`, etc.) are handled by the `update_database` system based on `$field_specifications` changes. However, `update_database` adds new columns — it does not rename existing ones. The approach:
1. Add the new column name to `$field_specifications`
2. Add a data migration to copy values from the old column to the new one
3. Remove the old column from `$field_specifications` (it will be dropped by `update_database` if it has no data, or left as an orphan if cleanup is manual)

Alternatively, use a SQL migration: `ALTER TABLE ... RENAME COLUMN old_name TO new_name`. This is cleaner but must be coordinated with the `$field_specifications` change in the same release.

### Composer dependency management

`MailChimpProvider` needs `jhut89/mailchimp3php`. This stays in `composer.json`. Future providers may add their own dependencies. The provider file loads composer autoload via `PathHelper::getComposerAutoloadPath()` before using the SDK, same as `MailgunProvider` does today.

---

## Not Doing

### Payment Processors (Stripe, PayPal)

Stripe and PayPal cover the entire realistic market for this platform. The alternatives (Square, Braintree/owned by PayPal, Authorize.net/legacy) don't represent genuine swap scenarios. The integration surface is enormous (~2,100 lines of helper code, 25+ files, processor-specific database columns everywhere, separate webhook endpoints, different subscription lifecycle APIs). An abstraction layer here would be significant engineering effort for a hypothetical scenario. The existing two-provider conditional logic is ugly but done and working.

---

## File Changes Summary

| File | Action |
|---|---|
| **Phase 1: Scheduling** | |
| `includes/scheduling_providers/SchedulingProvider.php` | **New** — interface |
| `includes/scheduling_providers/AcuityProvider.php` | **New** — extracted from AcuityScheduling.php |
| `includes/SchedulingService.php` | **New** — discovery + factory |
| `logic/get_appointments_logic.php` | **Modify** — use SchedulingService |
| `adm/admin_settings.php` | **Modify** — dynamic scheduling provider UI |
| `plugins/bookings/data/bookings_class.php` | **Modify** — rename column + method |
| `plugins/bookings/data/booking_types_class.php` | **Modify** — rename column + method |
| `plugins/bookings/admin/admin_booking_types.php` | **Modify** — generic sync link |
| `plugins/bookings/admin/admin_bookings.php` | **Modify** — generic sync link |
| `plugins/bookings/admin/admin_booking.php` | **Modify** — use renamed method |
| `ajax/calendly_init.php` | **Delete** — dead code |
| `ajax/calendly_webhook.php` | **Delete** — dead code |
| `utils/calendly_synchronize.php` | **Delete** — dead code |
| `tests/integration/calendly_test.php` | **Delete** — dead code |
| **Phase 2: Mailing List** | |
| `includes/mailing_list_providers/MailingListProvider.php` | **New** — interface |
| `includes/mailing_list_providers/MailChimpProvider.php` | **New** — extracted from mailing_lists_class |
| `includes/MailingListService.php` | **New** — discovery + factory |
| `data/mailing_lists_class.php` | **Modify** — remove MailChimp SDK usage, use provider |
| `data/contact_types_class.php` | **Modify** — rename column |
| `data/users_class.php` | **Modify** — rename column |
| `utils/mailchimp_synchronize.php` | **Replace** with `utils/mailing_list_synchronize.php` |
| `adm/admin_settings_email.php` | **Modify** — dynamic mailing list provider UI |
| `adm/admin_mailing_list_edit.php` | **Modify** — generic field label |
| `adm/admin_mailing_list.php` | **Modify** — generic display |
| `adm/admin_contact_type.php` | **Modify** — generic display |
| `adm/admin_contact_type_edit.php` | **Modify** — generic field label |
| `adm/logic/admin_contact_type_edit_logic.php` | **Modify** — use renamed column |
| `adm/logic/admin_mailing_list_edit_logic.php` | **Modify** — use renamed column |
| **Phase 3: Google Fonts** | |
| `includes/PublicPageBase.php` | **Modify** — add `render_font_links()` |
| Various `theme/*/theme.json` | **Modify** — add `fonts` config |
| Various `theme/*/includes/PublicPage.php` | **Modify** — remove hardcoded font links |
| `includes/PublicPage.php` | **Modify** — remove hardcoded font links |
| `includes/PublicPageJoinerySystem.php` | **Modify** — remove hardcoded font links |
| `includes/PublicPageFalcon.php` | **Modify** — remove hardcoded font links |
| `plugins/scrolldaddy/includes/PublicPage.php` | **Modify** — remove hardcoded font links |
| **Documentation** | |
| `docs/plugin_developer_guide.md` | **Update** — document scheduling and mailing list provider interfaces |

## Testing

### Phase 1 Testing

- Verify appointments page still loads and displays correctly for a logged-in user with Acuity configured
- Verify admin settings page renders scheduling provider fields dynamically
- Verify API validation still works from admin settings
- Verify bookings plugin admin pages work with renamed columns
- Verify no references to deleted Calendly files remain (grep for `calendly_init`, `calendly_webhook`, `calendly_synchronize`)

### Phase 2 Testing

- Verify mailing list subscribe/unsubscribe still syncs to MailChimp
- Verify the sync utility runs correctly with the new provider abstraction
- Verify admin settings page renders mailing list provider fields dynamically
- Verify admin mailing list and contact type pages display generic labels
- Verify that with no provider configured, local mailing list operations work without errors

### Phase 3 Testing

- Verify each theme still loads correct fonts (browser test — check Network tab or rendered font-family)
- Verify removing the `fonts` key from theme.json results in no external font requests
- Verify admin interface still loads correct fonts
