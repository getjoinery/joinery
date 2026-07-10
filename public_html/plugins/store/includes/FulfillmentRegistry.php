<?php
/**
 * FulfillmentRegistry + FulfillmentProvider
 *
 * A product can "do something" when purchased beyond recording the order — most
 * notably, an event ticket registers the buyer for an event. That coupling is
 * generalized so the store never has to know events exist: a product stores a
 * fulfillment provider key + a reference id, and the provider supplies the
 * admin picker, any extra purchase-time requirements, and the fulfill() action
 * run on successful purchase. Event_manager registers the `event_registration`
 * provider.
 *
 * Store-owned registry. Fail soft: a product with no fulfillment provider simply
 * has nothing to fulfill.
 *
 * @version 1.0.0
 */

interface FulfillmentProvider {
    /** Stable key stored in pro_fulfillment_provider. */
    public function key(): string;
    /** Human label for the product-edit picker. */
    public function label(): string;
    /** reference_id => label options for the product-edit picker. */
    public function options(): array;
    /**
     * Extra product requirements to auto-attach for this fulfillment (e.g. a
     * required survey). Returns AbstractProductRequirement[].
     */
    public function extraRequirements(Product $product, int $ref): array;
    /**
     * Run fulfillment on a successful, paid purchase. Returns
     * ['ref_id' => ?int, 'label' => ?string, 'labels' => ?array] for the
     * order line summary; the provider owns any of its own notifications/signals.
     */
    public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array;
    /** Admin HTML label/link describing a reference. */
    public function displayReference(int $ref): string;
}

class FulfillmentRegistry {

    /** @var array<string,FulfillmentProvider> keyed by provider key */
    private static $providers = [];

    /** Register a provider. Idempotent (last-wins by key). */
    public static function register(FulfillmentProvider $provider): void {
        self::$providers[$provider->key()] = $provider;
    }

    /** Get a provider by key, or null. */
    public static function get(string $key): ?FulfillmentProvider {
        return self::$providers[$key] ?? null;
    }

    /** All registered providers, registration order. */
    public static function all(): array {
        return array_values(self::$providers);
    }

    /** Register core-visible fulfillment providers. */
    public static function registerCoreDefaults(): void {
        // No core fulfillment providers. The event_registration provider
        // registers from event_manager's serve.php when that plugin is active.
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$providers = [];
    }
}

// Register core-visible providers when this file is loaded.
FulfillmentRegistry::registerCoreDefaults();
