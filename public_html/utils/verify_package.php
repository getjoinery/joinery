<?php
/**
 * verify_package.php — is this directory a package we built?
 *
 * A thin CLI over PackageSignature for the host converger
 * (maintenance_scripts/install_tools/_plugin_installers_start.sh), which runs
 * a plugin's declared host_installer as root on every converge and must not
 * run one out of a directory nobody we know built (specs/package_signing.md
 * WP5). A shell script cannot read an Ed25519 signature; this can.
 *
 *   php utils/verify_package.php <dir> [--keys=<file>]
 *
 * Prints one line, `verdict: <name>: <why>`, and exits 0 for `signed` and 1
 * for anything else. --keys names a key file other than the node's own
 * config/release_verify_keys — the gate hands it a throwaway key; nothing on
 * a real box passes it. Reads nothing but the directory and the key file.
 *
 * On a node that requires the release log (config/release_log_required),
 * `signed` also needs the package's release statement in the public log;
 * anything less is `unlogged`, and the host installer does not run. Keys the
 * statement's key chain proves are appended to the node's key files when
 * this runs as root, the converger being one of the root callers B9 names.
 *
 * The publishing-box exemption (a box that holds config/agent_signing_key
 * trusts its own tree) is the converger's decision, not this tool's: this
 * answers the question about bytes and nothing else.
 *
 * @version 1.2 - keys a package proves that cannot be recorded exit 1, so the host installer waits for the next converge
 * @version 1.1 - the release log: `unlogged`, and proven keys persisted by root
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}

require_once(__DIR__ . '/../includes/PathHelper.php');

$dir = '';
$keys_file = null;
foreach (array_slice($argv, 1) as $arg) {
	if (strpos($arg, '--keys=') === 0) {
		$keys_file = substr($arg, strlen('--keys='));
	} elseif ($arg !== '' && $arg[0] !== '-') {
		$dir = $arg;
	}
}
if ($dir === '') {
	fwrite(STDERR, "Usage: verify_package.php <dir> [--keys=<file>]\n");
	exit(2);
}

$verdict = PackageSignature::verify($dir, $keys_file);
echo 'verdict: ' . $verdict->line() . "\n";
if ($verdict->signed() && $verdict->keys_proven && $keys_file === null
		&& function_exists('posix_geteuid') && posix_geteuid() === 0) {
	try {
		foreach (PackageSignature::persistProvenKeys($verdict->keys_proven) as $line) {
			echo 'key proven by the release log chain: ' . $line . "\n";
		}
	} catch (Throwable $e) {
		// The keys first, or nothing: the converger runs the host installer
		// only on exit 0, so this tick skips it and the next tries again.
		echo 'keys not recorded: ' . $e->getMessage() . "\n";
		exit(1);
	}
}
exit($verdict->signed() ? 0 : 1);
