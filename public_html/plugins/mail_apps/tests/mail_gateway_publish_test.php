<?php
/** @joinery-test
 * name: mail_gateway_publish
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * timeout: 60
 * covers: [public_html/plugins/mail_apps/provisioning/install_mail_gateway.sh]
 *
 * A server has no compiler. install_mail_gateway.sh installs a PREBUILT gateway
 * from provisioning/bin/mail-gateway-<uname -m>, and MailGatewayPublisher is
 * what produces those binaries at publish time. What is checked here:
 *
 *   1. The publisher and the installer agree on the file names (two languages,
 *      no shared constant).
 *   2. Publish, the release statement and the verifier all reach the gateway:
 *      it is on GoBinaryPublisher::PLUGIN_PUBLISHERS, its source is held and
 *      its bin/ generated (ReleaseCommit), and the statement names each binary
 *      mail-gateway/<uname -m>.
 *   3. When this tree has binaries, they are usable and stamped with the
 *      source beside them.
 *
 * The build mechanics (ELF check, stamp, refusal without a toolchain) are
 * GoBinaryPublisher's and are covered by relay_sealer_publish. Nothing here
 * compiles anything.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$publishers = GoBinaryPublisher::pluginPublishers();
$provisioning = PathHelper::getIncludePath('plugins/mail_apps/provisioning');

// ---------------------------------------------------------------------------
section('The publisher and the installer agree on the file names');

check(in_array('MailGatewayPublisher', $publishers, true), 'The gateway is a plugin Go program publish builds', json_encode($publishers));
check(MailGatewayPublisher::SOURCE_SUBDIR === 'public_html/plugins/mail_apps/provisioning/mail-gateway'
	&& is_file($provisioning . '/mail-gateway/main.go'), 'Its source is the gateway in provisioning/mail-gateway');
check(MailGatewayPublisher::BIN_SUBDIR === 'public_html/plugins/mail_apps/provisioning/bin', 'Its binaries go to provisioning/bin');

$script = (string)@file_get_contents($provisioning . '/install_mail_gateway.sh');
check($script !== '', 'install_mail_gateway.sh is readable');
check(strpos($script, 'ARCH="$(uname -m)"') !== false, 'The installer selects on uname -m');
check(strpos($script, '${SCRIPT_DIR}/bin/' . MailGatewayPublisher::BINARY . '-${ARCH}') !== false,
	'It looks in bin/ beside itself for ' . MailGatewayPublisher::BINARY . '-<uname -m>');
check(array_keys(MailGatewayPublisher::ARCHES) === array('x86_64', 'aarch64'), 'The publisher writes uname -m names');
check(strpos($script, 'go build') === false, 'The installer does not compile anything');

// ---------------------------------------------------------------------------
section('Publish, the statement and the verifier reach it');

check(in_array(MailGatewayPublisher::SOURCE_SUBDIR . '/', ReleaseCommit::JOINERY_HELD_DIRS, true),
	'Its source is held: an uncommitted change there refuses a publish');
check(in_array(MailGatewayPublisher::BIN_SUBDIR . '/', ReleaseCommit::JOINERY_GENERATED_DIRS, true),
	'Its bin/ is generated: it ships though git ignores it');
check(ReleaseCommit::joineryFileShips(MailGatewayPublisher::BIN_SUBDIR . '/mail-gateway-x86_64', array()),
	'A built binary ships');
check(MailGatewayPublisher::artifactName('aarch64') === 'mail-gateway/aarch64', 'The statement names it mail-gateway/<uname -m>');

$publish = (string)@file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
check(strpos($publish, 'GoBinaryPublisher::pluginPublishers()') !== false
	&& strpos($publish, 'GoBinaryPublisher::pluginPublishers()') < strpos($publish, 'Creating individual plugin archives'),
	'Publish builds the plugin Go programs before it makes the plugin archives');
$verifier = (string)@file_get_contents(PathHelper::getIncludePath('includes/ReleaseVerifier.php'));
check(substr_count($verifier, 'GoBinaryPublisher::pluginPublishers()') >= 3,
	'The verifier loads, checks and rebuilds every plugin Go program');

// ---------------------------------------------------------------------------
section('This tree, as it stands');

// A working copy that has never published has no binaries yet; when it has
// them, they must be right.
$bin = $provisioning . '/bin';
if (is_dir($bin) && is_file($bin . '/' . MailGatewayPublisher::STAMP_FILE)) {
	check(MailGatewayPublisher::binariesPresent($bin) === null, 'The binaries in this tree are usable',
		(string)MailGatewayPublisher::binariesPresent($bin));
	check(MailGatewayPublisher::readStamp($bin) === MailGatewayPublisher::sourceHash($provisioning . '/mail-gateway'),
		'And carry the stamp of the source beside them');
} else {
	check(true, 'No published binaries in this working copy yet (a publish makes them)');
}

harness_finish();
