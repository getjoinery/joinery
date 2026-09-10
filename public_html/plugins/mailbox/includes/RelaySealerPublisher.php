<?php
/**
 * RelaySealerPublisher - cross-compiles the relay-sealer binary into the
 * mailbox plugin artifact at publish time.
 *
 * A relay is a mail machine with no compiler: provision_relay.sh 2.9 consumes
 * a PREBUILT sealer from provisioning/bin/relay-sealer-<uname -m> and refuses
 * to proceed without one. Something has to produce those binaries, and the
 * publish is the only place that both has the source and knows a release is
 * being cut. So the mailbox plugin archive carries them, the same way the core
 * archive carries the agent artifact.
 *
 * Called by publish_upgrade.php beside AgentDistPublisher, before any plugin
 * archive is built, so the binaries are on disk in time to be hashed, covered
 * by the plugin's signed RELEASE_MANIFEST, and tarred with the plugin.
 *
 * The mechanics — the source stamp, the ELF check, the staging swap, the
 * statuses and the no-toolchain refusal — are GoBinaryPublisher's; this class
 * names the program. bin/ lives inside the plugin tree, so leaving it
 * byte-identical when nothing changed matters more here than for the agent: a
 * needless rebuild would move the tree hash and auto-bump the mailbox plugin's
 * version on every publish.
 *
 * @version 1.1 - the mechanics move to core's GoBinaryPublisher
 * @version 1.0
 */

class RelaySealerPublisher extends GoBinaryPublisher {

	const SOURCE_SUBDIR = 'public_html/plugins/mailbox/provisioning/relay-sealer';
	const BIN_SUBDIR    = 'public_html/plugins/mailbox/provisioning/bin';
	const BINARY        = 'relay-sealer';
	const LABEL         = 'Relay sealer';
	const CACHE_ROOT    = '/var/tmp/joinery-relay-sealer-build';
}
?>
