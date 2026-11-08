<?php
/**
 * UnsealDaemonPublisher - cross-compiles the unseal daemon
 * (maintenance_scripts/install_tools/joinery_unseal, specs/unseal_daemon.md)
 * into prebuilt binaries at publish time.
 *
 * Every node runs a prebuilt daemon: its installer, a core host installer,
 * copies bin/joinery-unseal-<uname -m> outside the tree at the platform's root
 * moments. The binaries ride the core archive with the rest of
 * maintenance_scripts/install_tools, hashed and covered by the signed manifest
 * like every shipped file, and tests/vault/unseal_daemon_test.php drives the
 * one for this box's architecture.
 *
 * Called by publish_upgrade.php beside the parser jail's publisher, before the
 * core archive is built. A build that was owed and did not happen refuses the
 * release: a daemon known to be stale must not ship.
 *
 * @version 1.0
 */

class UnsealDaemonPublisher extends GoBinaryPublisher {

	const SOURCE_SUBDIR = 'maintenance_scripts/install_tools/joinery_unseal';
	const BIN_SUBDIR    = 'maintenance_scripts/install_tools/joinery_unseal/bin';
	const BINARY        = 'joinery-unseal';
	const LABEL         = 'Unseal daemon';
	const CACHE_ROOT    = 'joinery-unseal-build';
}
?>
