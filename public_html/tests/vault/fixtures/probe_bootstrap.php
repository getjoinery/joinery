<?php
/**
 * Test fixture: a BOOTSTRAP-ONLY plugin's load point (no vaultConsumer block).
 * Loaded by tests/vault/plugin_bootstrap_test.php through the plugin bootstrap
 * loader, standing in for plugins/{probe}/includes/bootstrap.php. It registers
 * an upload purpose — the capability a plugin without vault consumership uses a
 * bootstrap for.
 */
require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));

UploadPurposeRegistry::register('harness_probe_purpose', array(
	'source' => 'harness_probe',
	'label'  => 'harness probe upload',
	'authorize' => function (int $user_id, array $input): ?string {
		return $user_id > 0 ? null : 'Sign in required.';
	},
));
?>
