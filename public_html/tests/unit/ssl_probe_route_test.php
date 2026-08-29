<?php
/** @joinery-test
 * name: ssl_probe_route
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The SSL routing probe: /sm-ssl-probe.txt must serve the token a management
 * node's provisioning job drops in the webroot — and nothing else, ever.
 *
 * Two ends have to agree for Cloudflare-branch SSL provisioning to work: the
 * server_manager JobCommandBuilder writes {webroot}/sm-ssl-probe.txt and
 * fetches /sm-ssl-probe.txt through the domain, and serve.php must route that
 * URL to a view that serves the file — because a Joinery front controller
 * never serves arbitrary webroot files on its own. That mismatch is exactly
 * how 48 provision_ssl jobs failed in a row against a node whose webroot held
 * a perfectly good token no request could reach.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$probe_file = PathHelper::getIncludePath('sm-ssl-probe.txt');
$probe_view = PathHelper::getIncludePath('views/sm_ssl_probe.php');
@unlink($probe_file);

section('The route exists');

RouteHelper::$match_only_mode  = true;
RouteHelper::$match_only_result = null;
$_REQUEST['__route'] = 'sm-ssl-probe.txt';
include(PathHelper::getIncludePath('serve.php'));
$match = RouteHelper::$match_only_result;
RouteHelper::$match_only_mode = false;
unset($_REQUEST['__route']);

check(is_array($match) && !empty($match['matched']),
	'/sm-ssl-probe.txt matches a route',
	'without a route the front controller 404s the probe and Cloudflare SSL provisioning can never verify routing');
check(strpos(json_encode($match), 'sm_ssl_probe') !== false,
	'and it resolves to the sm_ssl_probe view');

section('The view serves only a real token');

/** Include the view fresh and return [http status, body]. */
function probe_response($probe_view) {
	http_response_code(200);
	ob_start();
	include($probe_view);
	return [http_response_code(), trim(ob_get_clean())];
}

list($status, $body) = probe_response($probe_view);
check($status === 404 && $body === '',
	'no token file: 404 and no body',
	'no probe in flight must look like any other unknown path');

file_put_contents($probe_file, "sm-ssl-probe-0123456789abcdef01234567\n");
list($status, $body) = probe_response($probe_view);
check($status === 200 && $body === 'sm-ssl-probe-0123456789abcdef01234567',
	'a token file: served back verbatim');

file_put_contents($probe_file, "<?php echo 'not a token'; ?>\nsecond line\n");
list($status, $body) = probe_response($probe_view);
check($status === 404 && $body === '',
	'a file that is not one short safe-charset line is not served',
	'the route serves probe tokens, not whatever ends up at that path');

@unlink($probe_file);

section('The builder fetches the URL this route serves');

$builder_src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
check(strpos($builder_src, "/sm-ssl-probe.txt") !== false,
	'JobCommandBuilder probes /sm-ssl-probe.txt',
	'if the builder and the route ever disagree on the path, every Cloudflare probe fails again');

harness_finish();
