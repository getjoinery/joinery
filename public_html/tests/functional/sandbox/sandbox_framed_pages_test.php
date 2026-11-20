<?php
/** @joinery-test
 * name: sandbox_framed_pages
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Framed pages (specs/sandboxed_php_plugins.md WP5b, design 9): a plugin's
 * own document, script and styles, in a sealed frame.
 *
 * What is pinned here (the browser's own behaviour, V6, is a live check):
 *  - the manifest takes "frame": true on a page with no sections, and nothing
 *    else; the approval screen names a framed member page and lists framed
 *    admin pages as a grant; a page that becomes framed asks anew;
 *  - the framed route's headers: sandbox allow-scripts (and never
 *    allow-same-origin), default-src 'none', no connect, form or frame
 *    source, frame-ancestors 'self', no referrer; over HTTP, a request that is
 *    not a same-origin frame load gets nothing;
 *  - the outer page holds the frame (sandboxed by its attribute too) and
 *    core's bridge, and its frame-src names the framed route alone;
 *  - a framed page not approved is not shown; the frame's call reaches the
 *    plugin's handler as the viewer, and a bad handler or another page is
 *    refused;
 *  - unfenced and, where this box has the plugin's user, in the fence: the
 *    framed route draws the plugin's whole document.
 *
 * Run: php tests/run.php db --filter=sandbox_framed_pages
 *
 * @version 1.2 - core's prelude removes WebRTC in the frame (V6, found in a real browser)
 * @version 1.1 - reviewer2 K1, K2, K4: declared handlers only, the enforced frame-src, the route's refusals
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(__DIR__ . '/../../lib/http.php');
harness_boot();

$files = array(
	'admin/board.php' => "<?php\necho '<!doctype html><html><head><style>body{background:#eef}</style></head><body><div id=\"b\">Board</div>"
		. "<script>parent.postMessage({type:\"sbx-height\",height:400},\"*\")</script></body></html>';\n",
	'views/board.php' => "<?php\necho '<!doctype html><html><body><p>Member board for ' . (int)SessionControl::get_instance()->get_user_id() . '</p></body></html>';\n",
	'plugin.php' => "<?php\nfunction on_ui(array \$input) { return array('echo' => \$input['values'] ?? null, 'handler' => \$input['handler'] ?? null,"
		. " 'user' => \$input['user']['id'] ?? null, 'source' => \$input['source'] ?? null, 'page' => \$input['page'] ?? null); }\n",
);
$manifest = array(
	'admin_pages' => array(array('slug' => 'board', 'title' => 'Board', 'frame' => true)),
	'profile_pages' => array(array('slug' => 'board', 'title' => 'My board', 'frame' => true, 'handlers' => array('save'))),
	'limits' => array('memory_mb' => 32, 'timeout_ms' => 5000),
);
$sign_in = function (User $user) {
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	$_SESSION['saved_messages'] = array();
};
/** The framed route's output, as a same-origin frame load. */
$frame = function (string $name, string $kind) {
	$saved = array($_GET, $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null, $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null);
	$_GET = array('plugin' => $name, 'kind' => $kind, 'page' => 'board');
	$_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';
	$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
	ob_start();
	try {
		// The view exits; run it in a child scope that turns exit into a return.
		$code = (string)file_get_contents(PathHelper::getIncludePath('views/sandbox_frame.php'));
		$code = str_replace(array('exit;'), array('return;'), $code);
		// header() warns once a CLI run has printed; the headers are checked
		// through SandboxPages::frameHeaders() and over HTTP instead.
		$level = error_reporting(E_ALL & ~E_WARNING);
		try {
			eval('?>' . $code);
		} finally {
			error_reporting($level);
		}
	} finally {
		$out = (string)ob_get_clean();
		list($_GET, $dest, $site) = $saved;
		$_SERVER['HTTP_SEC_FETCH_DEST'] = $dest;
		$_SERVER['HTTP_SEC_FETCH_SITE'] = $site;
	}
	return $out;
};

$checks = function (string $name, string $label, User $admin, User $member) use ($sign_in, $frame) {
	$sign_in($admin);
	$out = $frame($name, 'admin');
	check(strpos($out, '<div id="b">Board</div>') !== false && strpos($out, 'postMessage') !== false,
		$label . ': the framed route draws the plugin\'s whole document, uncleaned', mb_substr($out, 0, 300));
	$sign_in($member);
	$out = $frame($name, 'member');
	check(strpos($out, 'Member board for ' . (int)$member->key) !== false, $label . ': and a member\'s, for that member', mb_substr($out, 0, 300));
	$out = $frame($name, 'admin');
	check(strpos($out, 'Board</div>') === false, $label . ': a member is not given the admin page\'s document', mb_substr($out, 0, 200));
	$r = harness_call_logic('logic/sandbox_frame_call_logic.php', 'sandbox_frame_call_logic',
		array('plugin' => $name, 'kind' => 'member', 'page' => 'board', 'handler' => 'save', 'data' => array('x' => 1)), 'POST');
	check(!$r->error && ($r->data['ok'] ?? false) && ($r->data['output']['echo'] ?? null) === array('x' => 1)
		&& ($r->data['output']['handler'] ?? '') === 'save' && ($r->data['output']['user'] ?? null) === (int)$member->key
		&& ($r->data['output']['source'] ?? '') === 'frame' && ($r->data['output']['page'] ?? '') === 'board',
		$label . ': the frame\'s call reaches the plugin\'s handler, as the viewer, saying it came from the frame', json_encode($r->data ?: $r->error));
	$r = harness_call_logic('logic/sandbox_frame_call_logic.php', 'sandbox_frame_call_logic',
		array('plugin' => $name, 'kind' => 'member', 'page' => 'board', 'handler' => 'admin_only_form'), 'POST');
	check(strpos((string)$r->error, 'may not call') !== false, $label . ': a handler the page does not declare (an admin form\'s) is refused (K1)', (string)$r->error);
	$r = harness_call_logic('logic/sandbox_frame_call_logic.php', 'sandbox_frame_call_logic',
		array('plugin' => $name, 'kind' => 'member', 'page' => 'board', 'handler' => 'Bad-Name'), 'POST');
	check((string)$r->error !== '', $label . ': a handler with no plain name is refused', (string)$r->error);
	$r = harness_call_logic('logic/sandbox_frame_call_logic.php', 'sandbox_frame_call_logic',
		array('plugin' => $name, 'kind' => 'admin', 'page' => 'board', 'handler' => 'save'), 'POST');
	check((string)$r->error !== '', $label . ': a member cannot call through the admin page', (string)$r->error);
	$sign_in($admin);
};

try {
	$admin = make_user('SbxFrameAdmin', 10);
	$member = make_user('SbxFrameMember', 0);

	section('The manifest and the approval screen');
	$bad = function (array $page) {
		try {
			SandboxManifest::fromArray(array('name' => 'hts_fr', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1, 'admin_pages' => array($page)), 'hts_fr');
			return '';
		} catch (SandboxManifestException $e) {
			return $e->getMessage();
		}
	};
	check(strpos($bad(array('slug' => 'a', 'title' => 'A', 'frame' => true, 'sections' => array(array('type' => 'text', 'text' => 'x')))), 'no sections') !== false,
		'A framed page with sections is refused');
	check(strpos($bad(array('slug' => 'a', 'title' => 'A', 'frame' => 'yes')), 'true or false') !== false, 'frame is true or false');
	$m = SandboxManifest::fromArray(array('name' => 'hts_fr', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1) + $manifest, 'hts_fr');
	$grants = SandboxConsent::grantsOf($m);
	check(strpos((string)($grants['member_pages']['board'] ?? ''), 'framed page running the plugin') !== false, 'A framed member page says so on the approval screen');
	check(($grants['framed_admin_pages'] ?? null) === array('board' => 'Board') && isset(SandboxConsent::describe($grants)["Run its own script in admins' browsers, on these framed admin pages"]),
		'A framed admin page is a grant, listed in words', json_encode($grants['framed_admin_pages'] ?? null));
	$plain = SandboxConsent::grantsOf(SandboxManifest::fromArray(array('name' => 'hts_fr', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
		'admin_pages' => array(array('slug' => 'board', 'title' => 'Board')), 'profile_pages' => array(array('slug' => 'board', 'title' => 'My board'))), 'hts_fr'));
	$more = SandboxConsent::additions($grants, $plain);
	check(isset($more['framed_admin_pages']['board'], $more['member_pages']['board']), 'Pages that become framed ask anew (design 12)', json_encode($more));

	section('The framed route\'s headers');
	$h = SandboxPages::frameHeaders('hts_fr');
	$csp = $h['Content-Security-Policy'] ?? '';
	foreach (array('sandbox allow-scripts;', "default-src 'none'", "connect-src 'none'", "form-action 'none'", "frame-src 'none'",
		"base-uri 'none'", "frame-ancestors 'self'", "script-src 'unsafe-inline'") as $needle) {
		check(strpos($csp, $needle) !== false, 'Its policy has ' . $needle, $csp);
	}
	check(strpos($csp, 'allow-same-origin') === false && strpos($csp, 'allow-top-navigation') === false && strpos($csp, 'allow-popups') === false
		&& strpos($csp, 'allow-forms') === false, 'and none of the sandbox\'s allowances but scripts', $csp);
	check(($h['Referrer-Policy'] ?? '') === 'no-referrer' && ($h['Cache-Control'] ?? '') === 'no-store', 'No referrer, no cache');
	$r = harness_request('GET', SandboxPages::frameUrl('hts_fr', 'admin', 'board'), array('accept' => null));
	check($r['status'] === 404 && trim($r['body']) === '', 'Over HTTP, opened directly (no same-origin frame load), it answers nothing', 'HTTP ' . $r['status']);
	$r = harness_request('GET', SandboxPages::frameUrl('hts_fr', 'admin', 'board'), array('accept' => null,
		'headers' => array('Sec-Fetch-Dest: iframe', 'Sec-Fetch-Site: cross-site')));
	check($r['status'] === 404 && trim($r['body']) === '', 'nor to another site\'s frame', 'HTTP ' . $r['status']);
	$r = harness_request('GET', SandboxPages::frameUrl('hts_fr', 'admin', 'board'), array('accept' => null,
		'headers' => array('Sec-Fetch-Dest: iframe', 'Sec-Fetch-Site: same-site')));
	check($r['status'] === 404 && trim($r['body']) === '', 'nor to a same-site frame (K4)', 'HTTP ' . $r['status']);
	$r = harness_request('GET', SandboxPages::frameUrl('hts_fr', 'admin', 'board'), array('accept' => null,
		'headers' => array('Sec-Fetch-Dest: document', 'Sec-Fetch-Site: same-origin')));
	check($r['status'] === 404 && trim($r['body']) === '', 'nor to a same-origin page opened as a document (K4)', 'HTTP ' . $r['status']);
	$r = harness_request('GET', SandboxPages::frameUrl('hts_fr', 'admin', 'board'), array('accept' => null,
		'headers' => array('Sec-Fetch-Dest: iframe', 'Sec-Fetch-Site: same-origin')));
	check($r['status'] === 404 && strpos($r['body'], 'does not exist') !== false
		&& harness_header_matches($r['headers'], '~^Content-Security-Policy: sandbox allow-scripts;~i'),
		'A frame load of a page it may not open is refused under the frame\'s own policy (K4)', 'HTTP ' . $r['status'] . ' ' . implode(' | ', $r['headers']));
	// V6 in a real browser: Chromium ignores the webrtc directive, so core's
	// own script runs first in the frame and removes the WebRTC constructors.
	$prelude = SandboxPages::framePrelude();
	check(strpos($prelude, '<!doctype html><script>') === 0 && strpos($prelude, '"RTCPeerConnection"') !== false
		&& strpos($prelude, '"webkitRTCPeerConnection"') !== false && strpos($prelude, 'configurable:false') !== false,
		'Core\'s prelude removes the WebRTC constructors for good', $prelude);
	$view = (string)file_get_contents(PathHelper::getIncludePath('views/sandbox_frame.php'));
	check(preg_match('~echo SandboxPages::framePrelude\(\);\s*echo \$body;~', $view) === 1,
		'and the framed route writes it ahead of the plugin\'s document');
	$src = SandboxPages::frameSource();
	foreach (array('enable_csp off' => array(), 'report-only' => PublicPageBase::csp_headers(true, true, 'off')) as $case => $base) {
		$sent = PublicPageBase::csp_headers_with_frame_only($base, $src);
		$enforced = array_filter($sent, function ($h) use ($src) { return $h[0] === 'Content-Security-Policy' && $h[1] === 'frame-src ' . $src; });
		check(count($enforced) === 1, 'With ' . $case . ', the outer page still sends an enforced frame-src of the framed route alone (K2)', json_encode($sent));
	}
	$value = PublicPageBase::with_frame_only(PublicPageBase::csp_headers(true, false, 'enforce')[0][1], $src);
	preg_match('~frame-src [^;]*~', $value, $fs);
	check(($fs[0] ?? '') === 'frame-src ' . $src, 'The outer page\'s frame-src names the framed route alone', $fs[0] ?? $value);

	section('Unfenced, the same PHP command line');
	$p = sandbox_fixture_plugin($manifest, array('files' => $files));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	$sign_in($admin);
	$r = harness_call_logic('adm/logic/admin_sandbox_page_logic.php', 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'board'), 'GET');
	check(!empty($r->data['framed']), 'The admin page is framed', json_encode($r->error));
	$markup = SandboxPages::frameMarkup(SandboxPlugins::manifest($name), 'admin', array('slug' => 'board', 'title' => 'Board'));
	check(strpos($markup, 'sandbox="allow-scripts"') !== false && strpos($markup, htmlspecialchars(SandboxPages::frameUrl($name, 'admin', 'board'))) !== false
		&& strpos($markup, '/assets/js/sandbox-frame.js') !== false && strpos($markup, 'drawn by the sandboxed plugin') !== false,
		'It holds the frame, sandboxed by its attribute too, core\'s bridge and the provenance line', $markup);
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$checks($name, 'Unfenced', $admin, $member);
	SandboxRunner::$command_override = null;

	section('Not approved');
	$sign_in($admin);
	$q = sandbox_fixture_plugin($manifest, array('files' => $files));
	$pm->install($q['name'], false);
	$r = harness_call_logic('adm/logic/admin_sandbox_page_logic.php', 'admin_sandbox_page_logic', array('plugin' => $q['name'], 'page' => 'board'), 'GET');
	check((string)$r->error !== '', 'An inactive, unapproved plugin\'s framed page is not shown', (string)$r->error);
	check(SandboxPages::framedPage($q['name'], 'admin', 'board', SessionControl::get_instance()) === 'That page does not exist.', 'nor served by the framed route');

	section('In the fence');
	$fence = 'sbxframes';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced frames', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => $files, 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced frames', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence, false);
			sandbox_approve($fence);
			$pm->activate($fence);
			SandboxPlugins::reset();
			$checks($fence, 'Fenced', $admin, $member);
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
