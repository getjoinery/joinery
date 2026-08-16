<?php
/**
 * RecipientGroupProviderRegistry + RecipientGroupProvider
 *
 * Bulk email can target a "recipient group" — historically either a core Group
 * or an event's registrants/waiting-list. That targeting is generalized behind
 * providers: a recipient-group row stores a provider key + a reference id, and
 * the provider both resolves the reference to a user-id list and supplies the
 * admin picker options. Core ships the `group` provider; event_manager registers
 * `event` and `event_waiting_list`.
 *
 * @version 1.0.0
 */

interface RecipientGroupProvider {
    /** Stable key stored in erg_provider. */
    public function key(): string;
    /** Human label for the targeting UI. */
    public function label(): string;
    /** reference_id => label options for the picker. */
    public function options(): array;
    /** Resolve a reference to a list of user ids ([] if unresolvable). */
    public function resolve(int $reference_id): array;
    /** Friendly label for a single reference (shown in lists). */
    public function reference_label(int $reference_id): string;
}

class RecipientGroupProviderRegistry {

    /** @var array<string,RecipientGroupProvider> keyed by provider key */
    private static $providers = [];

    /** Register a provider. Idempotent (last-wins by key). */
    public static function register(RecipientGroupProvider $provider): void {
        self::$providers[$provider->key()] = $provider;
    }

    /**
     * Get a provider by key, or null. A null key (legacy row with no provider
     * set) resolves to null — callers treat it as an empty recipient group.
     */
    public static function get(?string $key): ?RecipientGroupProvider {
        if ($key === null || $key === '') {
            return null;
        }
        return self::$providers[$key] ?? null;
    }

    /** All registered providers, registration order. */
    public static function all(): array {
        return array_values(self::$providers);
    }

    /** Register core-owned recipient-group providers. */
    public static function registerCoreDefaults(): void {
        require_once(PathHelper::getIncludePath('includes/recipient_group_providers/GroupRecipientProvider.php'));
        self::register(new GroupRecipientProvider());
        require_once(PathHelper::getIncludePath('includes/recipient_group_providers/MailingListRecipientProvider.php'));
        self::register(new MailingListRecipientProvider());
        // The event + event_waiting_list providers register from event_manager's
        // serve.php when that plugin is active.
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$providers = [];
    }
}

// Register core-owned providers when this file is loaded.
RecipientGroupProviderRegistry::registerCoreDefaults();
