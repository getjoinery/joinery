<?php
/**
 * ParserJailPublisher - cross-compiles the parser jail's launcher
 * (maintenance_scripts/install_tools/joinery_jail, specs/parser_jail.md) into
 * prebuilt binaries at publish time.
 *
 * Every node consumes a prebuilt launcher: install_parser_jail.sh, a core host
 * installer, copies bin/joinery-jail-<uname -m> to /usr/local/sbin setuid root
 * at the platform's root moments (container start, site build, node upgrade)
 * and refuses to install anything else. The binaries ride the core archive
 * with the rest of maintenance_scripts/install_tools, hashed and covered by
 * the signed manifest like every shipped file.
 *
 * Called by publish_upgrade.php beside the relay sealer's publisher, before
 * the core archive is built. A build that was owed and did not happen refuses
 * the release: a launcher known to be stale must not ship.
 *
 * @version 1.0
 */

class ParserJailPublisher extends GoBinaryPublisher {

	const SOURCE_SUBDIR = 'maintenance_scripts/install_tools/joinery_jail';
	const BIN_SUBDIR    = 'maintenance_scripts/install_tools/joinery_jail/bin';
	const BINARY        = 'joinery-jail';
	const LABEL         = 'Parser jail';
	const CACHE_ROOT    = '/var/tmp/joinery-jail-build';
}
?>
