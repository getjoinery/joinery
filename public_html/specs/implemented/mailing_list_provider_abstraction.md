# Mailing List Provider Abstraction

## Goal

Decouple mailing list integrations so swapping providers (ConvertKit, Brevo/Sendinblue, Buttondown, Listmonk, etc.) requires creating a single new class, not editing scattered model classes, sync utilities, and admin pages. The mailing list concept is generic — the provider is an implementation detail.

Follows the pattern established by the existing email provider abstraction (`includes/EmailServiceProvider.php` + `includes/email_providers/*Provider.php`).

This is one of three specs split out from the prior `external_service_abstraction.md`. The other two — theme fonts configuration and scheduling provider abstraction — are independent and unrelated.

---

## Open Decisions

These need to be resolved before implementation begins.

### D1: `getSubscribers()` pagination shape — RESOLVED: opaque cursor

**Decision:** Use opaque-cursor pagination. Each call returns one batch plus a `next_cursor` token; the caller passes that token back on the next call. The provider encodes whatever its native API needs (offset, page number, real cursor) into the token; the caller treats it as opaque.

**Why:** This is the industry-standard pattern for multi-provider paginated systems (AWS SDKs, GitHub GraphQL, Stripe, etc.). The mailing list provider landscape is split between offset, page-based, and cursor-based pagination — opaque cursors map cleanly to all three. Offset/limit on the interface would be architecturally wrong: a cursor-only provider (Klaviyo, Buttondown, Beehiiv, MailerLite) cannot honestly satisfy it. Generators handle "iterate everything from start" but make resumability, progress reporting, and error recovery awkward — and a sync utility wants all three.

### D2: `validateApiConnection()` on the interface — RESOLVED: keep it

**Decision:** Include `validateApiConnection()` on the `MailingListProvider` interface.

**Why:** Connection testing is part of the provider contract, not an admin-UI concern. Every provider has credentials; every provider can answer "are these credentials valid?" Codifying it in the interface means every provider plug-in supports the admin connection-test panel automatically, and provider authors don't have to reinvent the same shape in their admin partials.

The reference pattern (`EmailServiceProvider`) doesn't have this method — that's a gap to fix later in the email abstraction, not a precedent to replicate here. Sibling consistency is desirable but not at the cost of repeating an architectural shortcut.

### C1: Error semantics for `subscribe()` / `unsubscribe()` / `getSubscribers()` — RESOLVED: typed exceptions

**Decision:** Define `MailingListProviderException` for retryable/permanent provider errors. Use `\InvalidArgumentException` for caller input errors. Reserve `null` (from `subscribe()`) for the soft no-op case where the wrapper short-circuits before calling the provider.

**Three failure categories:**
| Cause | How surfaced |
|---|---|
| Bad caller input (malformed email, etc.) | `\InvalidArgumentException` |
| Transient provider failure (rate limit, 5xx, network) | `MailingListProviderException` with `isRetryable() === true` |
| Permanent provider failure (list missing, key revoked, 4xx other than auth) | `MailingListProviderException` with `isRetryable() === false` |
| No provider configured | The `sync_subscribe` model wrapper returns early; provider methods are never invoked |

**Caller pattern:**
```php
try {
    $id = $provider->subscribe($list_id, $email, $first, $last);
} catch (MailingListProviderException $e) {
    if ($e->isRetryable()) {
        // back off, queue for retry
    } else {
        // log, alert admin, give up
    }
} catch (\InvalidArgumentException $e) {
    // input was bad, fix at the source
}
```

**Why:** `null` / `false` returns lose information. The provider's API tells us exactly what happened (rate-limit headers, HTTP status, error code) — squashing that into a boolean forces every caller into "retry everything" or "retry nothing," both of which are wrong at scale. Typed exceptions are the standard PHP SDK convention (Stripe, AWS, GitHub, Anthropic) so provider authors expect this shape.

`validateApiConnection()` is the exception to this pattern: it always returns its array (with `success => false` and an `error` string on failure) because its caller — the admin status panel — is rendering an OK/not-OK light, not deciding retry behavior.

### C2: Subscriber status enum — RESOLVED: normalize to four values

**Decision:** `getSubscribers()` returns each subscriber with `status ∈ {'subscribed', 'unsubscribed', 'bounced', 'pending'}`. Each provider class maps its native status vocabulary to one of these four.

| Canonical | Meaning | MailChimp source | ConvertKit source | Listmonk source |
|---|---|---|---|---|
| `subscribed` | Actively receives mail | `subscribed` | `active` | `enabled` |
| `unsubscribed` | Opted out (incl. spam-complained) | `unsubscribed` | `cancelled`, `inactive`, `complained` | `disabled` |
| `bounced` | Email invalid; provider stopped sending | `cleaned` | `bounced` | `blocklisted` |
| `pending` | Double opt-in not yet confirmed | `pending` | (n/a) | (n/a) |

**Why:** `'cleaned'` is MailChimp-specific jargon. The point of the abstraction is to make the provider an implementation detail; vendor terms in shared contracts age badly. The four-value canonical set is sufficient for every action the platform takes on a status (keep / remove / flag-as-bouncing / no-op-yet). `complained` (some providers' "marked as spam") collapses into `unsubscribed` — for the platform's purposes the action is the same.

**Mapping cost:** ~5 lines per provider class.

### C3: Provider-specific custom fields (e.g. MMERGE3) — RESOLVED: per-provider settings

**Decision:** Provider-specific custom fields are configured as per-provider settings, not passed through the interface. The interface's `subscribe()` parameters stay limited to the universal set (`email`, `first_name`, `last_name`).

**Pattern:** Each provider class declares whatever extra settings it needs via `getSettingsFields()`. The provider's `subscribe()` reads those settings internally and applies them to the API call.

For `MailChimpProvider`, this preserves the current `MMERGE3 => 'Yes'` behavior via a new setting:

- `mailchimp_default_merge_fields` (JSON, e.g. `{"MMERGE3": "Yes"}`)
- Declared in `MailChimpProvider::getSettingsFields()` as a textarea with helptext explaining MailChimp merge-field syntax
- Read on every `subscribe()` call; merged into the SDK request alongside the universal `FNAME` / `LNAME` mappings

**Why:** The whole point of an abstraction is that callers don't know what provider they're talking to. An `array $extra = []` parameter would reintroduce that coupling — every callsite would have to know what extras a particular provider expects. Customer-specific MailChimp merge fields are configuration, not call-time data: they're the same value on every call. Configuration belongs in settings.

**Forward path:** If a future need genuinely requires per-call variation (rare), an `$extra = []` parameter can be added later as a non-breaking addition. Default-empty means existing callers stay unaffected.

**Sub-question for the user:** is MMERGE3 actually still needed? Worth checking before the port whether anything in the customer's MailChimp setup depends on it; if not, the migration can drop it instead of preserving it. (Answer doesn't change the architectural decision; it just affects whether the seeded `mailchimp_default_merge_fields` setting starts as `{"MMERGE3":"Yes"}` or `{}`.)

### C4: `getLists()` placement — RESOLVED: lives on abstract base (with real implementation in MailChimpProvider)

**Decision:** `getLists()` is NOT on the required `MailingListProvider` interface. It lives on `AbstractMailingListProvider` (see C5) with a default body that throws `\BadMethodCallException`. `MailChimpProvider` overrides it with a real implementation.

**Why:** Combined with C5 (creating the abstract base class anyway), the cleanest home for `getLists()` is the base class. Every realistic provider can implement it, but a hypothetical exception (e.g. a single-list-only service) gets a graceful absence path via the throwing default. Consumers of `getLists()` (the future list-picker UI) wrap calls in `try/catch BadMethodCallException` to handle providers that haven't overridden.

This combines the value of C4=B (real implementation on day one for the only existing provider) with the flexibility of C4=C (future providers can opt out).

**Edge case still applies:** a single-list-only service that wants to support list-picker UX can override with a synthetic single entry (`[['id' => 'default', 'name' => 'Subscribers', 'member_count' => N]]`).

### C5: Abstract base class — RESOLVED: create `AbstractMailingListProvider`

**Decision:** Ship `interface MailingListProvider` (the contract every provider must satisfy) AND `abstract class AbstractMailingListProvider implements MailingListProvider`. Provider classes extend the abstract base; the abstract base provides default throwing implementations for non-universal methods.

**Day-one shape:** `AbstractMailingListProvider` provides a default throwing implementation for `getLists()` (per C4). Future non-universal additions (sequences, broadcasts, stats — see below) get default implementations on the same base class as they're added, keeping additions non-breaking.

**Future methods this insurance is designed for:**

| Plausibly added later | Universally implementable? | Goes on interface? |
|---|---|---|
| `getSubscriber()`, `updateSubscriber()` | Yes | Interface (required) |
| Tag ops (`addTag`, `removeTag`, `getTags`) | Yes | Interface (required) |
| Webhook ops (`registerWebhook`, etc.) | Yes | Interface (required) |
| Bulk ops (`subscribeBatch`, `unsubscribeBatch`) | Yes | Interface (required) |
| Suppression list (`addToSuppression`) | Yes | Interface (required) |
| Sequences/automations (`addToSequence`) | **No** — Listmonk, Buttondown don't have | Abstract base (default throw) |
| Broadcasts (`sendBroadcast`) | **No** — model varies wildly | Abstract base (default throw) |
| List stats (`getListStats`) | **Maybe** — shapes vary | Abstract base if normalizing is lossy |

The pattern: universal future methods extend the interface; non-universal future methods extend the abstract base with throwing defaults. Both kinds of additions stay non-breaking for existing provider classes.

**Sibling-abstraction asymmetry:** `EmailServiceProvider` has no abstract base. This creates a one-time inconsistency — fixable later by introducing `AbstractEmailServiceProvider` as its own refactor. Not bundled here.

---

## Current State

Direct MailChimp SDK usage embedded in model classes and a utility script. The `MailingList` model directly calls the MailChimp API on subscribe/unsubscribe, making it impossible to swap providers without editing the model.

### Bugs in current code (fixed by the port)

- **Subscriber cap**: `utils/mailchimp_synchronize.php` line 39 — `for ($x=0; $x<=10000; $x+=1000)` silently truncates any list larger than 10,000 subscribers. The cursor-based port iterates until `next_cursor === null`, no cap.
- **Variable-variable typo**: `mailchimp_synchronize.php` line 94 — `$mailing_list->unsubscribe_from_mailchimp_list($$user->key)` should be `$user->key`. The "local-is-more-recent → unsubscribe-from-mailchimp" code path has likely never worked. Fix during port.
- **Inconsistent return types**: `subscribe_to_mailchimp_list()` returns the deserialized response object on success, `FALSE` on exception, or `TRUE` when the API key is empty. The new `MailingListProvider::subscribe()` returning `?string` (subscriber ID or null) is a clean replacement; the `sync_subscribe` wrapper interprets null as failure.

### Sync utility wiring

`utils/mailchimp_synchronize.php` has no caller in the codebase — no admin link, no scheduled task, no cron entry. It is hit by URL by an authenticated admin (permission ≥ 5). Replacing the file therefore has zero coordination cost; nothing else needs to change.

**Files involved:**
- `data/mailing_lists_class.php` — `use MailchimpAPI\Mailchimp` at top of file; `subscribe_to_mailchimp_list()` and `unsubscribe_from_mailchimp_list()` methods called inline during `add_registrant()` / `remove_registrant()`; `mlt_mailchimp_list_id` column
- `data/contact_types_class.php` — `ctt_mailchimp_list_id` column
- `data/users_class.php` — `usr_mailchimp_user_id` column
- `utils/mailchimp_synchronize.php` — 153-line sync script, directly uses MailChimp SDK
- `adm/admin_settings_email.php` (~lines 182–215) — MailChimp API key field, API validation panel
- `adm/admin_mailing_list_edit.php` — "Mailchimp List ID" text input
- `adm/admin_mailing_list.php` — displays MailChimp status
- `adm/admin_contact_type.php` — displays MailChimp list ID
- `adm/admin_contact_type_edit.php` — "Mailchimp List ID" text input
- `adm/logic/admin_contact_type_edit_logic.php` — saves `ctt_mailchimp_list_id`
- `adm/logic/admin_mailing_list_edit_logic.php` — saves `mlt_mailchimp_list_id`
- Composer: `jhut89/mailchimp3php` dependency
- Settings: `mailchimp_api_key`

---

## Design

### `MailingListProviderException`

Create `includes/mailing_list_providers/MailingListProviderException.php`:

```php
class MailingListProviderException extends \Exception {
    private bool $retryable;

    public function __construct(string $message, bool $retryable = false,
                                ?\Throwable $previous = null, int $code = 0) {
        parent::__construct($message, $code, $previous);
        $this->retryable = $retryable;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }
}
```

Provider implementations throw this for any provider-side failure. Set `$retryable = true` for rate limits, 5xx responses, and network/timeout errors. Set `$retryable = false` for permanent failures (list missing, credentials revoked, 4xx other than 429).

### `MailingListProvider` interface

Create `includes/mailing_list_providers/MailingListProvider.php`:

```php
interface MailingListProvider {
    /** Provider unique key (e.g., 'mailchimp', 'convertkit'). */
    public static function getKey(): string;

    /** Human-readable label for admin UI. */
    public static function getLabel(): string;

    /**
     * Setting field definitions for the admin settings page.
     */
    public static function getSettingsFields(): array;

    /**
     * Validate provider configuration.
     * Static, cheap — checks settings are non-empty / well-formed.
     * Does NOT make network calls.
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validateConfiguration(): array;

    /**
     * Test the live API connection. Makes a network call.
     * Used by the admin "Connection OK?" panel.
     * Returns:
     *   [
     *     'success' => bool,
     *     'label' => string,           // e.g. "Connected as account@example.com"
     *     'details' => array,          // arbitrary provider-specific status info
     *     'error' => string|null       // human-readable error message on failure
     *   ]
     */
    public function validateApiConnection(): array;

    /**
     * Subscribe a user to a remote list. Idempotent — calling for an already-
     * subscribed user updates their record and returns the existing subscriber ID.
     *
     * Email is normalized to lowercase before any provider call; providers must
     * treat email comparisons as case-insensitive.
     *
     * Returns the provider's subscriber ID (stored in usr_mailing_list_provider_id).
     *
     * Throws \InvalidArgumentException for malformed caller input (bad email format).
     * Throws MailingListProviderException for any provider-side failure;
     *   ->isRetryable() distinguishes transient (rate limit, 5xx) from permanent
     *   (list missing, credentials revoked) errors.
     */
    public function subscribe(string $remote_list_id, string $email,
                              string $first_name, string $last_name): string;

    /**
     * Unsubscribe a user from a remote list. Idempotent — calling for an already-
     * unsubscribed user returns true.
     *
     * Email is normalized to lowercase before any provider call.
     *
     * Throws \InvalidArgumentException for malformed caller input.
     * Throws MailingListProviderException for provider-side failures; see subscribe().
     */
    public function unsubscribe(string $remote_list_id, string $email): bool;

    /**
     * Get one batch of subscribers for sync purposes (opaque-cursor pagination).
     *
     * On the first call pass $cursor = null. The returned 'next_cursor' is an
     * opaque string that the caller passes back on the subsequent call to fetch
     * the next batch. When 'next_cursor' is null, iteration is complete.
     *
     * Each provider encodes its native pagination state into the cursor — offset
     * for MailChimp/Brevo, page number for ConvertKit/Drip, real cursor for
     * Klaviyo/Buttondown. The caller treats the cursor as opaque and never
     * inspects or modifies it.
     *
     * Returns:
     *   [
     *     'subscribers' => [
     *       ['email' => string,
     *        'status' => 'subscribed'|'unsubscribed'|'bounced'|'pending',
     *        'last_changed' => string (ISO 8601) | null],
     *       ...
     *     ],
     *     'next_cursor' => string|null
     *   ]
     *
     * Each provider maps its native status vocabulary into the canonical four
     * values. See spec section "C2" for the mapping table.
     *
     * $limit is a hint; providers may return fewer or more depending on their API.
     *
     * Cursor durability: cursors are valid within a single iteration session
     * only. Do NOT persist them across hours, days, or restarts — provider
     * pagination state may invalidate them. Each sync run starts with cursor=null.
     *
     * Throws MailingListProviderException for any provider-side failure;
     *   ->isRetryable() distinguishes transient from permanent errors.
     */
    public function getSubscribers(string $remote_list_id, ?string $cursor = null,
                                   int $limit = 1000): array;

    // getLists() is NOT on the interface. It lives on AbstractMailingListProvider
    // with a default throwing body, overridden by providers that support it.
}
```

Per Open Decisions D1, D2: opaque-cursor pagination, `validateApiConnection()` included.

### `AbstractMailingListProvider` base class

Create `includes/mailing_list_providers/AbstractMailingListProvider.php`:

```php
abstract class AbstractMailingListProvider implements MailingListProvider {
    /**
     * Get available lists/audiences from the remote provider.
     * Returns [['id' => string, 'name' => string, 'member_count' => int]]
     *
     * Default: throws \BadMethodCallException. Providers that support list
     * enumeration override this method. Consumers (e.g. a future list-picker
     * UI) wrap calls in try/catch to handle providers that don't override.
     */
    public function getLists(): array {
        throw new \BadMethodCallException(
            static::class . ' has not implemented getLists()'
        );
    }

    // Future non-universal methods (sequences, broadcasts, list stats) get
    // default throwing bodies here as they are added to the system, keeping
    // additions non-breaking for existing provider classes.
}
```

Provider classes extend this base instead of implementing the interface directly. See C5 in Open Decisions for the rationale and a forward catalog of non-universal methods.

### `MailingListService` manager

`includes/MailingListService.php` — auto-discovers providers via glob on `includes/mailing_list_providers/*Provider.php`. Discovery filter: classes that implement `MailingListProvider` (which transitively includes everything extending `AbstractMailingListProvider`). Skips the abstract class itself and the exception class via filename or `(new \ReflectionClass($class))->isInstantiable()`.

Provides `getProvider(string $key = null)`, `getAvailableServices()`, `getProviderSettings(string $key)`. Same shape as `EmailSender::discoverProviders()` (line 30 of `includes/EmailSender.php`).

---

## Implementation

### Phase 1: Build the abstraction (no consumer changes)

**1a.** Create `includes/mailing_list_providers/MailingListProvider.php` (interface).

**1b.** Create `includes/MailingListService.php` (discovery + factory).

**1c.** Create `includes/mailing_list_providers/MailChimpProvider.php`. Extract from `mailing_lists_class.php`, `utils/mailchimp_synchronize.php`, and `adm/admin_settings_email.php`:
- Class declaration: `class MailChimpProvider extends AbstractMailingListProvider` (NOT `implements MailingListProvider` directly).
- File header: `require_once(PathHelper::getComposerAutoloadPath());` at the top, followed by `use MailchimpAPI\Mailchimp;` — same pattern as `includes/email_providers/MailgunProvider.php` line 9.
- `getKey()` → `'mailchimp'`
- `getSettingsFields()` returns entries for `mailchimp_api_key` and `mailchimp_default_merge_fields` (JSON textarea, helptext explains MailChimp merge-field syntax; default `{}` or `{"MMERGE3":"Yes"}` depending on the C3 sub-question answer)
- `subscribe()` ← body of `subscribe_to_mailchimp_list()`. Return the MailChimp subscriber ID (`$status->id` from the deserialized response) on success. Throw `MailingListProviderException(retryable=true)` on rate limit / 5xx / network error; throw with `retryable=false` on 4xx (other than 429). The "API key missing" case is handled by the model wrapper's early-return; the provider class itself can assume credentials are present (or fail loudly if not). Reads `mailchimp_default_merge_fields` (JSON-decoded) and merges those into the `merge_fields` block of the SDK call, alongside the universal `FNAME` / `LNAME` mappings derived from the `$first_name` / `$last_name` parameters.
- `unsubscribe()` ← body of `unsubscribe_from_mailchimp_list()`. Return `true` on success; same exception shape as `subscribe()`.
- `validateApiConnection()` ← extracted from the API validation block in `admin_settings_email.php` (~lines 182–230). Pings `lists()->get(['count' => 10])`. Returns success with a label like `"Connected — N lists found"` and `details` containing the list previews; on exception, returns `success => false` with the exception message in `error`.
- `getSubscribers()` ← the iteration loop from `mailchimp_synchronize.php`. The cursor is a serialized integer offset (e.g., `"1000"`); the provider parses it back into the `count`/`offset` parameters MailChimp's API expects. Returns `next_cursor: null` when the API reports fewer results than `$limit`. Maps MailChimp statuses to the canonical set: `subscribed` → `subscribed`, `unsubscribed` → `unsubscribed`, `cleaned` → `bounced`, `pending` → `pending`. Other rare values (`transactional`) map to `unsubscribed`.
- `getLists()` (override of the base class default): wraps `$mailchimp->lists()->get(['count' => 1000])`, returning normalized `[id, name, member_count]` entries.

After Phase 1: the abstraction exists; nobody calls it yet.

### Phase 2: Wire model consumers

**2a.** Refactor `data/mailing_lists_class.php`:

```php
function sync_subscribe($usr_user_id) {
    $provider = MailingListService::getProvider();
    if (!$provider || !$this->get('mlt_provider_list_id')) {
        return true; // No provider configured, local-only operation
    }
    $user = new User($usr_user_id, TRUE);
    try {
        $subscriber_id = $provider->subscribe(
            $this->get('mlt_provider_list_id'),
            $user->get('usr_email'),
            $user->get('usr_first_name'),
            $user->get('usr_last_name')
        );
        $user->set('usr_mailing_list_provider_id', $subscriber_id);
        $user->save();
        return true;
    } catch (MailingListProviderException $e) {
        error_log("Mailing list subscribe failed (retryable=" .
            ($e->isRetryable() ? 'yes' : 'no') . "): " . $e->getMessage());
        return false;
    } catch (\InvalidArgumentException $e) {
        error_log("Mailing list subscribe rejected bad input: " . $e->getMessage());
        return false;
    }
}
```

Remove `use MailchimpAPI\Mailchimp` from the top of the file. Same shape for `sync_unsubscribe`. The wrapper turns exceptions into a bool for the model's existing callers (`add_registrant` / `remove_registrant`) so their contract doesn't change. The sync utility, by contrast, will catch the exception itself and back off on retryable failures.

**2b.** Add `mailing_list_provider` setting via `settings.json` (factory default) seeded with `'mailchimp'`.

After Phase 2: subscribe/unsubscribe goes through the provider.

### Phase 3: Replace sync utility

**3a.** Replace `utils/mailchimp_synchronize.php` with `utils/mailing_list_synchronize.php` that calls `MailingListService::getProvider()->getSubscribers()` in a cursor-driven loop:

```php
$cursor = null;
do {
    try {
        $batch = $provider->getSubscribers($list_id, $cursor);
    } catch (MailingListProviderException $e) {
        if ($e->isRetryable()) {
            sleep(30);
            continue;  // retry the same cursor
        }
        throw $e;  // permanent — abort the sync run
    }
    foreach ($batch['subscribers'] as $subscriber) {
        // compare timestamps, decide direction, sync local
    }
    $cursor = $batch['next_cursor'];
} while ($cursor !== null);
```

The sync logic (compare timestamps, decide direction) stays the same — only the API calls change.

The cursor pattern also enables checkpoint/resume: the sync utility can persist the cursor on failure and resume from there on the next run. (Out of scope for the initial port — call out as a future improvement.)

After Phase 3: nothing in the codebase imports MailChimp directly except `MailChimpProvider`.

### Phase 4: Dynamic admin UI

**4a.** Replace hardcoded MailChimp settings in `adm/admin_settings_email.php` with rendering driven by `MailingListService::getAvailableServices()` and provider `getSettingsFields()`. The connection-test panel calls `$provider->validateApiConnection()` and renders the returned `success`/`label`/`error` fields uniformly across providers.

The dropdown-driven provider switcher already has a proven pattern next to it: the `email_service` and `email_fallback_service` dropinputs in the same file (~line 263) populated from `EmailSender::getAvailableServices()`. Mirror that exactly, with one `mailing_list_provider` dropdown.

After Phase 4: adding a new provider auto-populates the admin UI.

### Phase 5: Rename provider-specific columns

Pure rename, no functional changes. Could ship anytime after Phase 1.

- `mlt_mailchimp_list_id` → `mlt_provider_list_id` (on `MailingList`)
- `ctt_mailchimp_list_id` → `ctt_provider_list_id` (on `ContactType`)
- `usr_mailchimp_user_id` → `usr_mailing_list_provider_id` (on `User`)
- Update form labels in admin pages from "Mailchimp List ID" to "Remote List ID" (with provider-specific helptext).
- Update Multi class option keys.

Use `ALTER TABLE ... RENAME COLUMN` migrations coordinated with the `$field_specifications` change in the same release.

---

## Out of Scope (deliberate deferrals)

These are likely future additions but are explicitly NOT part of this spec. Non-universal future methods can be added to `AbstractMailingListProvider` with throwing defaults (per C5) so they don't break existing providers.

### Webhooks
Real-time event notifications (subscribe/unsubscribe/profile-update) are deferred until a provider's primary integration is webhook-based. Same call as the scheduling provider spec. When added, the methods (`registerWebhook`, `unregisterWebhook`, `verifyWebhookSignature`) go on the required interface — every modern provider supports them.

### OAuth flows
Some providers (HubSpot, Klaviyo) use OAuth2 instead of API keys. The current `getSettingsFields()` shape (text/password fields) can't express an OAuth flow. When a provider needing OAuth is added, that provider class implements an additional static method like `getOAuthAuthorizationUrl()` outside the formal interface; admin UI checks for its presence via `method_exists` and routes accordingly. OAuth is not baked into the interface itself.

### Programmatic list creation
`createList()` is not on the interface. Current workflow: admins create lists in the provider's UI, then enter the ID locally. If a future use case wants programmatic list creation, add it then. (Separate concern from `getLists()`.)

## Edge Cases

### Local-only operation
Sites that don't use any external mailing list provider should still have fully functional local mailing lists. `sync_subscribe()` / `sync_unsubscribe()` return `true` immediately when no provider is configured. The sync utility exits early with a message.

### Provider-specific list ID format
MailChimp uses alphanumeric list IDs. ConvertKit uses numeric tag/form IDs. The `mlt_provider_list_id` column is `varchar(255)` — any format works. The admin UI labels this generically ("Remote List ID") with provider-specific help text from `getSettingsFields()`.

### Composer dependency management
`MailChimpProvider` keeps `jhut89/mailchimp3php` in `composer.json`. Future providers may add their own dependencies. Each provider file loads composer autoload before using its SDK.

### Subscriber-ID column rename
`usr_mailchimp_user_id` becomes `usr_mailing_list_provider_id` (not `usr_provider_user_id`, to disambiguate from the scheduling spec's `usr_provider_user_id`). The two abstractions use independent column namespaces.

---

## File Changes Summary

| File | Action |
|---|---|
| **Phase 1: Abstraction layer** | |
| `includes/mailing_list_providers/MailingListProvider.php` | **New** — interface |
| `includes/mailing_list_providers/AbstractMailingListProvider.php` | **New** — abstract base with default `getLists()` throw |
| `includes/mailing_list_providers/MailingListProviderException.php` | **New** — typed exception with `isRetryable()` |
| `includes/mailing_list_providers/MailChimpProvider.php` | **New** — extends abstract base; extracted from mailing_lists_class + sync utility |
| `includes/MailingListService.php` | **New** — discovery + factory |
| **Phase 2: Wire model consumers** | |
| `data/mailing_lists_class.php` | **Modify** — remove MailChimp SDK; use provider |
| `settings.json` | **Modify** — add `mailing_list_provider` factory default |
| **Phase 3: Replace sync utility** | |
| `utils/mailchimp_synchronize.php` | **Replace** with `utils/mailing_list_synchronize.php` |
| **Phase 4: Dynamic admin UI** | |
| `adm/admin_settings_email.php` | **Modify** — dynamic mailing list provider section |
| **Phase 5: Column renames (independent)** | |
| `data/contact_types_class.php` | **Modify** — rename column |
| `data/users_class.php` | **Modify** — rename column |
| `adm/admin_mailing_list_edit.php` | **Modify** — generic field label |
| `adm/admin_mailing_list.php` | **Modify** — generic display |
| `adm/admin_contact_type.php` | **Modify** — generic display |
| `adm/admin_contact_type_edit.php` | **Modify** — generic field label |
| `adm/logic/admin_contact_type_edit_logic.php` | **Modify** — use renamed column |
| `adm/logic/admin_mailing_list_edit_logic.php` | **Modify** — use renamed column |
| `migrations/migrations.php` | **Modify** — `ALTER TABLE RENAME COLUMN` migrations |
| **Documentation** | |
| `docs/plugin_developer_guide.md` | **Update** — document `MailingListProvider` interface |

---

## Testing

- Verify mailing list subscribe/unsubscribe still syncs to MailChimp (Phase 2).
- Verify the sync utility runs correctly with the new provider abstraction (Phase 3).
- Verify admin settings page renders mailing list provider fields dynamically (Phase 4).
- Verify admin mailing list and contact type pages display generic labels (Phase 5).
- Verify that with no provider configured, local mailing list operations work without errors.
- Verify that switching `mailing_list_provider` to a non-existent key results in graceful degradation (no fatal errors; local-only behavior).
