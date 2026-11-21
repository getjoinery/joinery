<?php
/**
 * Every installed plugin gets an install id (plg_plugins.plg_install_id) and a
 * ledger of what it holds (pil_plugin_install_ledger), and what it kept before
 * ids existed is stamped with the id: a sandboxed plugin's files and call log,
 * and its content-pack apply records (spec plugin_install_identity, design 4).
 * Plugins already uninstalled are left alone. Idempotent.
 */
function plugin_install_ids() {
	foreach (PluginInstallIdentity::backfill() as $line) {
		echo '  ' . $line . "\n";
	}
	return true;
}
