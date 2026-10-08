<?php
/**
 * verify_release.php - check, from your own computer, that a Joinery release
 * is built from the public code and is in the public log (spec
 * release_transparency, D7).
 *
 * Run it from a clone of the public repository (github.com/getjoinery/joinery),
 * with PHP 8 (curl, sodium, openssl), git and tar. Go, at the version the
 * release names, lets it also rebuild the binaries and compare them byte for
 * byte; without it those two checks are reported as not run.
 *
 *   php utils/verify_release.php 0.8.467 --source=https://getjoinery.com
 *
 *   --source=URL       the upgrade source the release came from (a site's Updates page shows its own)
 *   --core-archive=F   check this core archive instead of downloading it
 *   --statement=F      check this RELEASE_STATEMENT instead of the core archive's
 *   --core-repo=DIR    read the core commit from this clone instead of GitHub
 *   --agent-repo=DIR   read the agent commit from this clone instead of GitHub
 *   --go=PATH          the Go to rebuild with
 *   --no-rebuild       do not rebuild the binaries
 *   --offline          fetch nothing: read the commits from --core-repo and --agent-repo, and skip
 *                      the checks that need GitHub or Sigstore
 *   --keep             keep the working files and say where
 *
 * It needs no site, no settings and no database. Exit code 0 when every check
 * that ran passed, 1 when one failed, 2 for a usage error.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}

require_once(__DIR__ . '/../includes/PathHelper.php');

// No settings, no database, no theme chain, no plugin registry.
ClassAutoloader::restrictToCore();

$opts = array();
$flags = array('source', 'core-archive', 'statement', 'core-repo', 'agent-repo', 'go');
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--(' . implode('|', $flags) . ')=(.+)$/', $arg, $m)) {
		$opts[str_replace('-', '_', $m[1])] = $m[2];
	} elseif ($arg === '--no-rebuild') {
		$opts['rebuild'] = false;
	} elseif ($arg === '--offline') {
		$opts['offline'] = true;
	} elseif ($arg === '--keep') {
		$opts['keep'] = true;
	} elseif ($arg !== '' && $arg[0] !== '-' && !isset($opts['version'])) {
		$opts['version'] = $arg;
	} else {
		fwrite(STDERR, "unknown argument: {$arg}\n");
		fwrite(STDERR, "usage: php utils/verify_release.php <version> --source=https://example.com [--no-rebuild] [--keep]\n");
		exit(2);
	}
}
if (!isset($opts['version'])) {
	fwrite(STDERR, "usage: php utils/verify_release.php <version> --source=https://example.com [--no-rebuild] [--keep]\n");
	exit(2);
}

$verifier = new ReleaseVerifier($opts);
exit($verifier->run() ? 0 : 1);
