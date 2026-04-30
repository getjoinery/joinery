<?php
/**
 * MailingListProvider Interface
 *
 * All mailing list providers must implement this interface. Provider classes
 * live in includes/mailing_list_providers/ and are auto-discovered by
 * MailingListService.
 *
 * To add a new provider, create a single file in includes/mailing_list_providers/
 * extending AbstractMailingListProvider. No other files need modification.
 */
interface MailingListProvider {
    /**
     * Return the provider's unique key (e.g., 'mailchimp', 'convertkit').
     * This is the value stored in the mailing_list_provider setting.
     */
    public static function getKey(): string;

    /**
     * Return a human-readable label for admin UI (e.g., 'MailChimp').
     */
    public static function getLabel(): string;

    /**
     * Return an array of setting field definitions this provider requires.
     * Each entry: ['key' => 'setting_name', 'label' => 'Human Label',
     *              'type' => 'text|password|textarea', 'helptext' => '...']
     */
    public static function getSettingsFields(): array;

    /**
     * Validate that this provider's required settings are configured.
     * Static, cheap — checks settings are non-empty / well-formed.
     * Does NOT make network calls.
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validateConfiguration(): array;

    /**
     * Test the live API connection. Makes a network call.
     * Used by the admin "Connection OK?" panel.
     * Always returns its array (with success => false and an error string on
     * failure) — does NOT throw.
     *
     * Returns:
     *   [
     *     'success' => bool,
     *     'label' => string,           // e.g. "Connected — 5 lists found"
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
     * values. See docs/plugin_developer_guide.md "Mailing list providers" for
     * the canonical mapping table and example provider mappings.
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
