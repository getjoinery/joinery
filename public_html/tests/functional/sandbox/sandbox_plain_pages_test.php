<?php
/** @joinery-test
 * name: sandbox_plain_pages
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Plain pages drawn by a sandboxed plugin's own view
 * (specs/sandboxed_php_plugins.md WP5, design 6): an admin page and a member
 * page with no sections in the manifest.
 *
 * What is pinned here, unfenced through the same PHP command line and, where
 * this box has the plugin's user, in the real fence:
 *  - a GET draws the view's body, cleaned in the parser jail: no script, no
 *    handler, no id or data-*, every form posting back to the page, the
 *    page's provenance line naming the plugin;
 *  - a POST reaches the view with its fields, and the view's answer is shown;
 *  - a redirect goes only to one of the plugin's own pages; any other is
 *    refused;
 *  - with the strict script policy not enforced, the page is not drawn and
 *    says why;
 *  - the manifest accepts a page with no sections, the approval screen names
 *    a member page drawn by the plugin;
 *  - an asset is one of the plugin's own images in src/assets: no SVG, no
 *    path out, no link.
 *
 * Run: php tests/run.php db --filter=sandbox_plain_pages
 *
 * @version 1.1 - reviewer2 H1, H2, H3: a post naming another plugin, a GET form submitted as a browser does, the contained box
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

$files = array(
	'admin/main.php' => <<<'PHP'
<?php
if (SandboxPage::query('go') === 'own') { SandboxPage::redirect('other', array('from' => 'main')); return; }
if (SandboxPage::query('go') === 'away') { SandboxPage::redirect('nowhere'); return; }
SandboxPage::title('Plugin main');
if (SandboxPage::isPost()) {
	echo '<p class="said">You said ' . htmlspecialchars((string)SandboxPage::field('who')) . '</p>';
}
?>
<h2 id="h" data-jy-prompt="x" onclick="evil()">Hello</h2>
<script>alert(1)</script>
<form action="/admin/admin_users" method="post"><input name="who"><input type="password" name="pw"><button formaction="/admin/x">Send</button></form>
<img src="assets/logo.png" alt="Logo"><img src="https://evil.example/p.gif">
<a href="javascript:alert(1)">bad</a> <a href="/profile">good</a>
PHP,
	'admin/other.php' => "<?php\necho '<p>Other page, from ' . htmlspecialchars((string)SandboxPage::query('from')) . '</p>';\n",
	'admin/search.php' => "<?php\necho '<p class=\"found\">Found ' . htmlspecialchars((string)SandboxPage::query('q')) . '</p><form method=\"get\"><input name=\"q\"><button>Go</button></form>';\n",
	'views/home.php' => "<?php\n\$s = SessionControl::get_instance();\necho '<p>Member ' . (int)\$s->get_user_id() . '</p><style>x{}</style>';\n",
	'assets/logo.png' => "\x89PNG\r\n\x1a\n",
	'assets/evil.svg' => '<svg onload="alert(1)"></svg>',
);
$manifest = array(
	'admin_pages' => array(array('slug' => 'main', 'title' => 'Main'), array('slug' => 'other', 'title' => 'Other'), array('slug' => 'search', 'title' => 'Search')),
	'profile_pages' => array(array('slug' => 'home', 'title' => 'Home')),
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
$strict = function (string $mode) {
	harness_set_setting_mem('enable_csp', '1');
	harness_set_setting_mem('csp_report_only', '0');
	harness_set_setting_mem('csp_strict_scripts', $mode);
};

$checks = function (string $name, string $label, User $admin, User $member) use ($sign_in, $strict) {
	$admin_logic = 'adm/logic/admin_sandbox_page_logic.php';
	$member_logic = 'logic/sandbox_page_logic.php';
	$strict('enforce');
	$sign_in($admin);
	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main'), 'GET');
	$drawn = $r->data['drawn'] ?? array();
	$html = (string)($drawn['html'] ?? '');
	check(!empty($drawn['ok']) && strpos($html, 'Hello</h2>') !== false, $label . ': the admin page is drawn by its view', json_encode($drawn));
	check(($drawn['title'] ?? '') === 'Plugin main', $label . ': with the title the view set');
	foreach (array('<script' => 'no script', 'onclick' => 'no handler', 'id="h"' => 'no id', 'data-jy' => 'no data-*',
		'password' => 'no password input', 'formaction' => 'no formaction', 'evil.example' => 'no image from an unapproved host',
		'javascript:' => 'no javascript: link', '/admin/admin_users' => 'no form posting elsewhere') as $needle => $words) {
		check(strpos($html, $needle) === false, $label . ': ' . $words, $html);
	}
	check(strpos($html, 'action="' . htmlspecialchars(SandboxPluginManager::adminPageUrl($name, 'main')) . '"') !== false,
		$label . ': its form posts back to the page', $html);
	check(strpos($html, htmlspecialchars(SandboxPages::assetUrl($name)) . 'logo.png') !== false && strpos($html, 'href="/profile"') !== false,
		$label . ': its own image and a plain link are kept', $html);
	check(strpos(SandboxPages::provenance(SandboxPlugins::manifest($name)), 'drawn by the sandboxed plugin') !== false,
		$label . ': the provenance line names the plugin');

	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main', 'who' => 'Ann <b>'), 'POST');
	$html = (string)($r->data['drawn']['html'] ?? '');
	check(strpos($html, 'You said Ann &lt;b&gt;') !== false, $label . ': a POST reaches the view with its fields', $html);

	// A form on this plugin's page that names another plugin and page in its
	// body: the address decides, so it reaches this plugin's view (H1).
	$saved = array($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? null);
	$_GET = array('plugin' => $name, 'page' => 'main');
	$_POST = array('plugin' => 'hts_other', 'page' => 'settings', 'action' => 'settings', 'section' => '0', 'who' => 'Bo');
	$_SERVER['REQUEST_METHOD'] = 'POST';
	require_once(PathHelper::getIncludePath($admin_logic));
	try {
		$r = admin_sandbox_page_logic(array_merge($_GET, $_POST));
	} finally {
		list($_GET, $_POST, $method) = $saved;
		$_SERVER['REQUEST_METHOD'] = $method;
	}
	check(strpos((string)($r->data['drawn']['html'] ?? ''), 'You said Bo') !== false && ($r->data['plugin'] ?? '') === $name,
		$label . ': a post naming another plugin in its body reaches this plugin\'s view', json_encode(array($r->data['plugin'] ?? null, $r->error)));

	// A GET form submitted as a browser does: the action's path, its query
	// replaced by the form's fields (H3).
	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'search'), 'GET');
	$doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . (string)($r->data['drawn']['html'] ?? ''), LIBXML_NOERROR, 'UTF-8');
	$form = $doc->querySelector('form');
	$submitted = array();
	foreach ($form ? $form->querySelectorAll('input') : array() as $input) {
		$submitted[$input->getAttribute('name')] = $input->getAttribute('name') === 'q' ? 'apples' : $input->getAttribute('value');
	}
	check(parse_url((string)($form ? $form->getAttribute('action') : ''), PHP_URL_PATH) === '/admin/admin_sandbox_page'
		&& ($submitted['plugin'] ?? null) === $name && ($submitted['page'] ?? null) === 'search', $label . ': a GET form carries its page\'s keys', json_encode($submitted));
	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', $submitted, 'GET');
	check(strpos((string)($r->data['drawn']['html'] ?? ''), 'Found apples') !== false, $label . ': and its submit reaches the view', json_encode($r->data['drawn'] ?? $r->error));

	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main', 'go' => 'own'), 'GET');
	check($r->redirect === SandboxPluginManager::adminPageUrl($name, 'other') . '&from=main', $label . ': a redirect to its own page', (string)$r->redirect);
	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main', 'go' => 'away'), 'GET');
	check($r->redirect === null && empty($r->data['drawn']['ok']) && strpos((string)($r->data['drawn']['message'] ?? ''), 'other than one of its own pages') !== false,
		$label . ': a redirect anywhere else is refused', json_encode(array($r->redirect, $r->data['drawn'] ?? null)));

	$sign_in($member);
	$r = harness_call_logic($member_logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'home'), 'GET');
	$html = (string)($r->data['drawn']['html'] ?? '');
	check(strpos($html, 'Member ' . (int)$member->key) !== false && strpos($html, '<style') === false,
		$label . ': the member page is drawn for the member viewing it, cleaned', json_encode($r->data['drawn'] ?? $r->error));

	// The views themselves, printed as a request would print them.
	$sign_in($admin);
	$render = function (string $file, array $get) {
		$saved = $_GET;
		$_GET = $get;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		try {
			include PathHelper::getIncludePath($file);
		} catch (Throwable $e) {
			echo 'THREW ' . get_class($e) . ': ' . $e->getMessage();
		} finally {
			$out = (string)ob_get_clean();
			$_GET = $saved;
		}
		return $out;
	};
	$out = $render('adm/admin_sandbox_page.php', array('plugin' => $name, 'page' => 'main'));
	check(strpos($out, 'contain: layout paint') !== false, $label . ': the body sits in a box nothing can be laid outside');
	check(strpos($out, 'drawn by the sandboxed plugin') !== false && strpos($out, 'Hello</h2>') !== false && strpos($out, 'THREW') === false,
		$label . ': the admin view prints the cleaned body under the provenance line', mb_substr(strip_tags($out), -300));
	$sign_in($member);
	$out = $render('views/profile/sandbox_page.php', array('plugin' => $name, 'page' => 'home'));
	check(strpos($out, 'drawn by the sandboxed plugin') !== false && strpos($out, 'Member ' . (int)$member->key) !== false && strpos($out, 'THREW') === false,
		$label . ': the member view does too', mb_substr(strip_tags($out), -300));

	// The API action sandbox_page refuses a drawn page: an app has no script policy (H6).
	$api_session = SessionControl::get_instance();
	$api_session->set_api_user((int)$member->key, null, 0);
	try {
		$r = harness_call_logic($member_logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'home'), 'GET');
	} finally {
		$api_session->clear_api_user();
	}
	check((string)$r->error !== '' && strpos((string)$r->error, 'browser only') !== false && !isset($r->data['drawn']),
		$label . ': the API action refuses a page the plugin draws', (string)$r->error);

	$strict('report');
	$sign_in($admin);
	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main'), 'GET');
	check(empty($r->data['drawn']['ok']) && ($r->data['drawn']['message'] ?? '') === SandboxPages::NO_STRICT_POLICY,
		$label . ': without the strict script policy enforced, the page is not drawn and says why', json_encode($r->data['drawn'] ?? null));
	$strict('enforce');
};

try {
	$admin = make_user('SbxPagesAdmin', 10);
	$member = make_user('SbxPagesMember', 0);

	section('The manifest and the approval screen');
	$m = SandboxManifest::fromArray(array('name' => 'hts_m', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1) + $manifest, 'hts_m');
	check(count($m->adminPages()) === 3 && SandboxPages::isPluginDrawn($m->adminPages()[0]), 'A page with no sections is a page the plugin draws');
	$grants = SandboxConsent::grantsOf($m);
	check(strpos((string)($grants['member_pages']['home'] ?? ''), 'drawn by the plugin') !== false, 'The approval screen says the member page is drawn by the plugin',
		json_encode($grants['member_pages']));

	section('Unfenced, the same PHP command line');
	$p = sandbox_fixture_plugin($manifest, array('files' => $files));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$checks($name, 'Unfenced', $admin, $member);
	SandboxRunner::$command_override = null;

	section('Its assets');
	check(SandboxPages::assetFile($name, 'logo.png')['type'] ?? null === 'image/png', 'Its PNG is served');
	check(SandboxPages::assetFile($name, 'evil.svg') === null, 'An SVG is not');
	check(SandboxPages::assetFile($name, '../manifest.json') === null && SandboxPages::assetFile($name, '../../../config/Globalvars_site.php') === null,
		'Nor a path out of src/assets');
	@symlink('/etc/hostname', $p['dir'] . '/src/assets/link.png');
	check(SandboxPages::assetFile($name, 'link.png') === null, 'Nor a link');
	@unlink($p['dir'] . '/src/assets/link.png');
	check(SandboxPages::assetFile('hts_none', 'logo.png') === null, 'Nor a file of a plugin that is not active');

	section('In the fence');
	$fence = 'sbxpages';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced pages', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => $files, 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced pages', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
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
