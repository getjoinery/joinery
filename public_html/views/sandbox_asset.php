<?php
/**
 * /sandbox_asset?plugin=NAME&file=PATH - one image from a sandboxed plugin's
 * src/assets/, for its plain pages (specs/sandboxed_php_plugins.md design 6).
 *
 * Served by core, outside the fence, so only what SandboxPages::assetFile()
 * accepts: an active plugin, a regular file whose real path is inside its
 * src/assets/ with no link on the way, an image type by extension (never
 * SVG). Anyone may load one: a framed page's document is another site to
 * the browser, which sends it no cookie, and a plugin's own images are its
 * page's, as public as an image the page could carry inline.
 *
 * @version 1.1 - no sign-in: a framed page's images load without the site's cookies
 * @version 1.0
 */

$asset = SandboxPages::assetFile((string)($_GET['plugin'] ?? ''), (string)($_GET['file'] ?? ''));
if ($asset === null) {
	http_response_code(404);
	exit;
}
header('Content-Type: ' . $asset['type']);
header('Content-Length: ' . filesize($asset['path']));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Cache-Control: private, max-age=300');
readfile($asset['path']);
exit;
