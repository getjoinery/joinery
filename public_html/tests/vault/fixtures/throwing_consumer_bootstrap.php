<?php
/**
 * Test fixture: a VAULT CONSUMER plugin's load point that registers its reseal
 * callback and then dies, the way a plugin left behind by a newer core does
 * when it names a constant that core no longer has. Used by
 * tests/vault/plugin_bootstrap_test.php and
 * tests/vault/sealed_consumer_declaration_test.php.
 */
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));

VaultUnlock::onReseal(function (int $user_id, VaultKey $old_key, int $old_key_generation,
		string $new_public_key, int $new_key_generation) {
	// Nothing to re-seal: the fixture owns no rows. Registering is the point.
});

$never_reached = File::SOURCE_NO_SUCH_TAG;
?>
