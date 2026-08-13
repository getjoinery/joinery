<?php
/**
 * Test fixture: a VAULT CONSUMER plugin's load point, standing in for
 * plugins/{probe_consumer}/includes/bootstrap.php. Registers the reseal
 * callback its vaultConsumer declaration obliges, so
 * tests/vault/plugin_bootstrap_test.php can prove attribution still works with
 * the bootstrap path living in the top-level plugin.json key.
 */
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));

VaultUnlock::onReseal(function (int $user_id, string $old_secret_key, int $old_key_generation,
		string $new_public_key, int $new_key_generation) {
	// Nothing to re-seal: the fixture owns no rows. Registering is the point.
});
?>
