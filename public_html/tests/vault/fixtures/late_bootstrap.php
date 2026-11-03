<?php
/**
 * Fixture bootstrap for plugin_bootstrap_test: a plugin activated after the
 * bootstraps first loaded in the process. Registers one upload purpose.
 */
UploadPurposeRegistry::register('harness_late_purpose', array(
	'source' => 'harness_late',
	'label'  => 'harness late upload',
	'authorize' => function (int $user_id, array $input): ?string {
		return $user_id > 0 ? null : 'Sign in required.';
	},
));
?>
