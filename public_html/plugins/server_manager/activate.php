<?php
/**
 * Server Manager plugin activation hook.
 *
 * Runs after the plugin's tables are created and its declared settings are
 * seeded (PluginManager::onActivate). Nothing to do: provisioning is keyless,
 * so the management node mints no SSH key here — a machine we create receives
 * none, and the root password we seal per-provision is the only credential
 * (specs/keyless_provisioning.md).
 */
function server_manager_activate() {
	// Intentionally empty.
}
