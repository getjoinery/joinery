<?php
/**
 * host_restrictions.php - the machine's restrictions, for a shell script
 * (specs/host_restrictions.md). A thin CLI over HostRestrictions, so a shell
 * caller asks exactly the question the PHP side asks.
 *
 *   php utils/host_restrictions.php has NAME   exit 0 when NAME is on, 1 when not
 *   php utils/host_restrictions.php show       every restriction that is on, one name=value per line
 *
 * When the file was not read cleanly, every known restriction is on and the
 * reason goes to stderr.
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}
require_once(__DIR__ . '/../includes/HostRestrictions.php');

$verb = $argv[1] ?? '';
$why = HostRestrictions::refusal();
if ($why !== '') {
	fwrite(STDERR, 'host restrictions: ' . HostRestrictions::path() . ': ' . $why . "\n");
}
if ($verb === 'has' && isset($argv[2]) && $argv[2] !== '') {
	exit(HostRestrictions::has($argv[2]) ? 0 : 1);
}
if ($verb === 'show') {
	foreach (HostRestrictions::all() as $name => $value) {
		echo $name . '=' . $value . "\n";
	}
	exit(0);
}
fwrite(STDERR, "Usage: host_restrictions.php has NAME | show\n");
exit(2);
