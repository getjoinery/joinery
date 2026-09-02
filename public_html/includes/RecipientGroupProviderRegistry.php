<?php
/**
 * RecipientGroupProviderRegistry + RecipientGroupProvider
 *
 * Bulk email can target a "recipient group" — historically either a core Group
 * or an event's registrants/waiting-list. That targeting is generalized behind
 * providers: a recipient-group row stores a provider key + a reference id, and
 * the provider both resolves the reference to a user-id list and supplies the
 * admin picker options. Core ships the `group`, `mailing_list` and `user`
 * providers; event_manager registers `event` and `event_waiting_list`.
 *
 * @version 1.2.0
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

    /**
     * Core provider class names not yet instantiated. Instantiation waits for
     * the first lookup: this file is loaded by the autoloader the moment any
     * provider file reaches `implements RecipientGroupProvider`, and a class
     * cannot be constructed from inside its own file before its declaration.
     * @var string[]
     */
    private static $pending_core = [];

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
        self::materializeCoreDefaults();
        return self::$providers[$key] ?? null;
    }

    /** All registered providers, registration order. */
    public static function all(): array {
        self::materializeCoreDefaults();
        return array_values(self::$providers);
    }

    /** Name the core-owned providers; they resolve by class name when first needed. */
    public static function registerCoreDefaults(): void {
        self::$pending_core = ['GroupRecipientProvider', 'MailingListRecipientProvider', 'UserRecipientProvider'];
        // The event + event_waiting_list providers register from event_manager's
        // serve.php when that plugin is active.
    }

    /** Instantiate the named core providers. An explicit register() for the same key wins. */
    private static function materializeCoreDefaults(): void {
        while (($class = array_shift(self::$pending_core)) !== null) {
            $provider = new $class();
            if (!isset(self::$providers[$provider->key()])) {
                self::$providers[$provider->key()] = $provider;
            }
        }
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$providers = [];
        self::$pending_core = [];
    }
}

// Name core-owned providers when this file is loaded.
RecipientGroupProviderRegistry::registerCoreDefaults();
