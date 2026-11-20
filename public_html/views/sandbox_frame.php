<?php
/**
 * /sandbox_frame?plugin=NAME&kind=admin|member&page=SLUG - the document of a
 * sandboxed plugin's framed page (specs/sandboxed_php_plugins.md design 9).
 *
 * Served only to a frame on one of this site's own pages: the browser says
 * so (Sec-Fetch-Dest: iframe, Sec-Fetch-Site: same-origin). Opened directly,
 * where no outer page exists, it answers nothing. The document is the
 * plugin's own, drawn by its view in the fence; core does not clean it. Its
 * response header (SandboxPages::frameHeaders()) makes the browser treat it
 * as another site with no network: its script cannot read this site's
 * cookies, pages or storage, call its API, load or send anything, or move the
 * outer page; the outer page's frame-src names this route alone. Its one way
 * out is a message to core's bridge on the outer page (assets/js/sandbox-frame.js).
 *
 * @version 1.1 - core's prelude (SandboxPages::framePrelude()) runs first and removes WebRTC
 * @version 1.0
 */

$dest = (string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
$site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if ($dest !== 'iframe' || $site !== 'same-origin') {
	http_response_code(404);
	exit;
}

$session = SessionControl::get_instance();
$plugin = (string)($_GET['plugin'] ?? '');
$kind = ($_GET['kind'] ?? '') === 'admin' ? 'admin' : 'member';
$page = SandboxPages::framedPage($plugin, $kind, (string)($_GET['page'] ?? ''), $session);

foreach (SandboxPages::frameHeaders($plugin) as $name => $value) {
	header($name . ': ' . $value);
}
if (!is_array($page)) {
	http_response_code(404);
	echo '<!doctype html><p>' . htmlspecialchars($page) . '</p>';
	exit;
}
list($query, $fields) = SandboxPages::requestValues($_GET, array());
$result = SandboxHost::page($plugin, $kind, $page['slug'], 'GET', $query, array(), new User($session->get_user_id(), TRUE));
$body = is_array($result['output'] ?? null) && is_string($result['output']['body'] ?? null) ? $result['output']['body'] : null;
if ($result['status'] !== SandboxLog::STATUS_OK || $body === null || strlen($body) > SandboxPages::MAX_FRAME_BYTES) {
	echo '<!doctype html><p>' . htmlspecialchars($body === null || $result['status'] !== SandboxLog::STATUS_OK
		? (string)($result['message'] ?: 'The plugin did not draw this page.') : 'The page is larger than 4 MB.') . '</p>';
	exit;
}
echo SandboxPages::framePrelude();
echo $body;
exit;
