<?php
/**
 * AccessGateRegistry + AccessGateProvider
 *
 * Videos and files can be gated so that only users who meet some external
 * condition may view them — historically "is the user registered for event X".
 * That condition is generalized behind a provider: a gated record stores a
 * provider key + a reference id, and the provider answers whether a given user
 * may access that reference. Event_manager registers the `event_registration`
 * provider; other gate kinds (group, tier) remain separate core concerns.
 *
 * Fail-closed by design: a record gated to a provider that isn't registered
 * denies access. An ungated record (null/empty provider) allows access.
 *
 * @version 1.0.0
 */

interface AccessGateProvider {
    /** Stable provider key stored in the record's *_access_provider column. */
    public function key(): string;
    /** Human label for the admin picker. */
    public function label(): string;
    /** reference_id => label options for the admin picker (caller adds the "All" option). */
    public function options(): array;
    /** Whether $user_id may access the given reference. */
    public function userMayAccess(int $user_id, int $ref): bool;
}

class AccessGateRegistry {

    /** @var array<string,AccessGateProvider> keyed by provider key */
    private static $providers = [];

    /** Register a provider. Idempotent (last-wins by key). */
    public static function register(AccessGateProvider $provider): void {
        self::$providers[$provider->key()] = $provider;
    }

    /** Get a provider by key, or null. */
    public static function get(string $key): ?AccessGateProvider {
        return self::$providers[$key] ?? null;
    }

    /** All registered providers, registration order. */
    public static function all(): array {
        return array_values(self::$providers);
    }

    /**
     * Central access decision for a gated record.
     *
     * - null/empty provider  → true  (ungated — matches the historical "All")
     * - anonymous (null user) → false (a gate can't be satisfied logged-out)
     * - unknown provider      → false (fail-closed)
     * - otherwise delegate to the provider
     */
    public static function userMayAccess(?string $provider, $ref, ?int $user_id): bool {
        if ($provider === null || $provider === '') {
            return true;
        }
        if ($user_id === null) {
            return false;
        }
        $p = self::$providers[$provider] ?? null;
        if ($p === null) {
            return false;
        }
        return $p->userMayAccess($user_id, (int)$ref);
    }

    /** Register core-owned access-gate providers. */
    public static function registerCoreDefaults(): void {
        // MOVED-TO-PLUGIN (phase 4): the event_registration gate moves to
        // event_manager serve.php once events are a plugin. Kept here while the
        // events tables are still core so gated files/videos keep enforcing.
        require_once(PathHelper::getIncludePath('includes/access_gate_providers/EventRegistrationGate.php'));
        self::register(new EventRegistrationGate());
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$providers = [];
    }
}

// Register core-owned providers when this file is loaded.
AccessGateRegistry::registerCoreDefaults();
