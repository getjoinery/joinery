<?php
/**
 * SandboxHostPublisher - cross-compiles the sandboxed plugin host
 * (maintenance_scripts/install_tools/sandbox_host, specs/plugin_tiers.md WP7)
 * into prebuilt binaries at publish time.
 *
 * A site with a sandboxed plugin runs a prebuilt host: install_sandbox_host.sh,
 * a core host installer, copies bin/sandbox-host-<uname -m> to /usr/local/bin
 * at the platform's root moments and supervises it. The binaries ride the core
 * archive with the rest of maintenance_scripts/install_tools, hashed and
 * covered by the signed manifest like every shipped file.
 *
 * Called by publish_upgrade.php beside the parser jail's publisher, before the
 * core archive is built. A build that was owed and did not happen refuses the
 * release: a host known to be stale must not ship.
 *
 * @version 1.0
 */

class SandboxHostPublisher extends GoBinaryPublisher {

	const SOURCE_SUBDIR = 'maintenance_scripts/install_tools/sandbox_host';
	const BIN_SUBDIR    = 'maintenance_scripts/install_tools/sandbox_host/bin';
	const BINARY        = 'sandbox-host';
	const LABEL         = 'Sandbox host';
	const CACHE_ROOT    = '/var/tmp/joinery-sandbox-host-build';
}
?>
