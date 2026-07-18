<?php
/**
 * Server Manager plugin activation hook.
 *
 * Runs after the plugin's tables are created and its declared settings are
 * seeded (PluginManager::onActivate). One job: make sure the control plane
 * has its customer-cloud provisioning SSH keypair, so the Provisioning
 * page's key row is green from the first visit. Idempotent and
 * non-destructive (an existing key or custom path is never touched);
 * failure is logged, never fatal — the page offers a Generate button as
 * the fallback.
 */
function server_manager_activate() {
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
	try {
		$result = ProvisioningSetup::ensureSshKey();
		if (!$result['ok']) {
			error_log('server_manager activate: provisioning key not created — ' . $result['message']);
		}
	} catch (Exception $e) {
		error_log('server_manager activate: provisioning key generation failed — ' . $e->getMessage());
	}
}
