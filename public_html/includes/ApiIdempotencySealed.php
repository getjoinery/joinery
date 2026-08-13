<?php
/**
 * ApiIdempotencySealed — the Sealed Vault consumer for cached API responses.
 *
 * A logic action that opened sealed content stores its idempotency response
 * body sealed to the member whose content it read (ApiLogicEndpoint stores the
 * outcome; ApiIdempotencyKey::$sealed_fields covers the body). That makes the
 * idempotency store a sealed-content holder like any other, so it owes the
 * vault a re-seal on key rotation: without one, a rotation retires the
 * generation the cached bodies are sealed to and a replay inside the retention
 * window reads ciphertext it can no longer open.
 *
 * There is no plugin here to carry a bootstrap — the store is core — so this
 * file is the consumer, declared in vault_consumers.json. Its whole job is the
 * generic model re-seal: the sealed-field model hook already knows the column
 * names, so nothing about the crypto has to be written out again.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));

VaultUnlock::onReseal(VaultUnlock::modelReseal(array(
	ApiIdempotencyKey::class,
)));
?>
