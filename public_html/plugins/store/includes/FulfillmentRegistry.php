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
 * @version 1.3.0 - cartRefusal(): one place asks every line's provider whether it can still be
 *                  delivered, so the checkout page asks before a hosted payment session exists and
 *                  the charge asks again only while declining is still free
 * @version 1.2.0 - checkAvailability() receives the cart line's form data, so a provider whose
 *                  availability depends on what the buyer answered can see the answer
 * @version 1.1.0
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
     * Whether this fulfillment can still be delivered, asked BEFORE the charge.
     *
     * fulfill() runs after payment succeeds, so it is the wrong place to
     * discover that a thing has run out: refusing there means the buyer has
     * already been charged for something they cannot be given. Anything with a
     * finite supply — event seats, workshop places, provisioned machines —
     * answers here instead, while the purchase can still be declined for free.
     *
     * Return NULL to proceed, or a buyer-facing sentence explaining the refusal
     * (it is shown to them as-is).
     *
     * $quantity is the number of units this cart line would consume. $data is
     * the line's form data — the buyer's validated answers as process() stored
     * them — for a provider whose availability depends on what was answered
     * (a Managed site's draft, say) rather than on the product alone.
     *
     * Advisory, not a lock: two checkouts can pass this concurrently and both
     * proceed. It closes the ordinary case — a full event still selling seats —
     * not a determined race. A provider needing a hard guarantee enforces it
     * with a database constraint of its own.
     */
    public function checkAvailability(Product $product, int $ref, int $quantity, array $data = []): ?string;
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

    /**
     * The first line in the cart whose provider says it cannot be delivered,
     * as the buyer-facing sentence — or null when every line may proceed.
     *
     * Asked wherever a payment step is about to start: on the checkout page
     * before a hosted payment session is created, and by the charge handler
     * before it charges a card. Never asked after money has moved — a line
     * that goes bad between the session and the return is fulfil()'s to
     * report, because the order is paid by then.
     *
     * A provider that throws is treated as available: the checkout must not
     * go down because one provider cannot answer, and fulfil() reports the
     * truth afterwards.
     */
    public static function cartRefusal($cart): ?string {
        foreach ($cart->items as $cart_item) {
            list($quantity, $product, $data) = $cart_item;
            if (!$product->get('pro_fulfillment_provider')) {
                continue;
            }
            $provider = self::get($product->get('pro_fulfillment_provider'));
            if (!$provider) {
                // An unresolvable provider is handled after the charge, where
                // it is already logged and stamped onto the order.
                continue;
            }
            try {
                $unavailable = $provider->checkAvailability(
                    $product, (int)$product->get('pro_fulfillment_ref'), (int)$quantity, (array)$data);
            } catch (\Throwable $e) {
                error_log('FulfillmentRegistry::cartRefusal: checkAvailability failed for product #'
                    . $product->key . ': ' . $e->getMessage());
                $unavailable = null;
            }
            if ($unavailable !== null) {
                return $unavailable;
            }
        }
        return null;
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
