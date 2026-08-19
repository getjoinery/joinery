<?php
/**
 * SSL routing probe — proof that a domain actually reaches this installation.
 *
 * A control plane provisioning SSL for a Cloudflare-proxied domain cannot use
 * DNS to confirm the domain routes to the node it manages: the domain resolves
 * to Cloudflare's edge, never the host. So its provisioning job writes a
 * one-time token to {public_html}/sm-ssl-probe.txt on the node and fetches
 * /sm-ssl-probe.txt back through the domain — the token coming back is the
 * proof. Every request routes through serve.php, so a file dropped in the
 * webroot is not reachable on its own; this route is what serves it.
 *
 * No token file means no probe is in flight — 404, like any other unknown
 * path. Nothing here is ever cacheable: an edge-cached 404 would outlive the
 * moment the token appears and fail a probe that should pass.
 *
 * The token has no secrecy value — serving it reveals nothing; matching it
 * proves routing.
 *
 * @version 1.0
 */

header('Cache-Control: no-store');

$probe_file = PathHelper::getIncludePath('sm-ssl-probe.txt');
if (!is_file($probe_file)) {
	http_response_code(404);
	return;
}

// The token is one short line of safe characters; anything else in that file
// is not a probe token and is not ours to serve.
$probe_token = trim((string)file_get_contents($probe_file, false, null, 0, 256));
if (!preg_match('/^[A-Za-z0-9._-]{8,128}$/', $probe_token)) {
	http_response_code(404);
	return;
}

header('Content-Type: text/plain');
echo $probe_token;
