<?php
/**
 * outbound_site_ceiling.php — the speed ceiling this site asks for itself.
 *
 * The machine's joinery-limits unit runs this on every pass, as the web
 * server's user (inside the container on a Docker host, on the host for a bare
 * metal site), and holds the site to the lower of this and the figure root set
 * (maintenance_scripts/install_tools/outbound_limits.sh,
 * specs/node_outbound_and_transfer.md WP5). Prints the setting
 * outbound_speed_ceiling_mbit as a whole number of Mbit/s, or nothing when it
 * is empty or not one.
 *
 * The host treats what this prints as untrusted: the site's own code can write
 * the setting, and could rewrite this file. Either way the most it can do is
 * slow the site down, because the host applies it only when lower than its own
 * figure. The site can tighten its ceiling, never loosen it.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

$value = trim((string)Globalvars::get_instance()->get_setting('outbound_speed_ceiling_mbit', true, true));
if (preg_match('/^[1-9][0-9]{0,5}$/', $value)) {
	echo $value, "\n";
}
