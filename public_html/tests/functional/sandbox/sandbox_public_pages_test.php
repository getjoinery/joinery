<?php
/** @joinery-test
 * name: sandbox_public_pages
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * A sandboxed plugin's pages anyone can open, signed in or not
 * (spec sandboxed_plugin_public_pages).
 *
 * What is pinned here:
 *  - the manifest's public_pages, cache and cache_query, and what they may
 *    not hold; the approval screen's line; a page not approved is not there;
 *  - the address /{plugin}/{slug} (and /{plugin} for its index), over HTTP
 *    on this site, only where nothing else on the site answers;
 *  - a visitor not signed in: the call says it is anonymous, and whether it
 *    answers a checked form POST; a signed-in visitor is the viewer;
 *  - load: the anonymous pool of its own (a member's page still runs while
 *    visitors hold it), one call per network, the per-network limit (an IPv6
 *    visitor counted across its /64), refusals writing nothing, the row's
 *    network, the shorter retention;
 *  - the relay: a visitor's GET or handler call refused a write, mail, an
 *    action, an outside request and page.refresh; a checked POST capped at
 *    10 writes and 3 recipients, and never a batch, with the site's spam
 *    checks switched off;
 *  - the spam checks: the inputs added after cleaning, the checks' names
 *    the plugin cannot draw or receive, a failing check stopping the call;
 *  - the cache: the bare address, cache_query, 200 copies, never a POST
 *    form, a framed page, a failed draw or "cache": false; page.refresh
 *    under the plugin's own address only; deactivation drops its copies;
 *  - framed public pages: a guest's frame and frame call; a guest calling
 *    sandbox_frame_call for a member or admin page, refused.
 *
 * The plugin's code is a stand-in runner (SandboxHost::$runner), except
 * where the relay itself is under test, which is called directly.
 *
 * Run: php tests/run.php db --filter=sandbox_public_pages
 *
 * @version 1.1 - reviewer2 R1-R6: forms found by the cleaner, actions in the write cap, the request's own query
 *                names, reserved field names, a userless call always a visitor's, distinct cache keys
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

$sign_in = function (User $user) {
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	$_SESSION['saved_messages'] = array();
};
$sign_out = function () {
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
	unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
	$_SESSION['saved_messages'] = array();
};
$from = function (string $ip) {
	$_SERVER['REMOTE_ADDR'] = $ip;
};
$db = DbConnector::get_instance()->get_db_link();
$networks = array();
harness_defer(function () use ($db, &$networks) {
	foreach (array_unique($networks) as $n) {
		$db->prepare("DELETE FROM rql_request_logs WHERE rql_ip_network = ? AND rql_feature IN ('sandbox_public_page', 'sandbox_public_mail')")->execute(array($n));
	}
});
$count_logs = function (string $name) use ($db): int {
	$q = $db->prepare('SELECT COUNT(*) FROM sbl_sandbox_logs WHERE sbl_plugin = ?');
	$q->execute(array($name));
	return (int)$q->fetchColumn();
};
$count_limit_rows = function (string $network) use ($db): int {
	$q = $db->prepare("SELECT COUNT(*) FROM rql_request_logs WHERE rql_feature = 'sandbox_public_page' AND rql_ip_network = ?");
	$q->execute(array($network));
	return (int)$q->fetchColumn();
};
$lock_dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
$hold = function (string $file) use ($lock_dir) {
	if (!is_dir($lock_dir)) {
		@mkdir($lock_dir, 0775, true);
	}
	$fh = fopen($lock_dir . '/' . $file, 'c');
	flock($fh, LOCK_EX);
	return $fh;
};
$release = function (array $fhs) {
	foreach ($fhs as $fh) {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
};
$net_lock = function (string $ip): string {
	return 'anon_net_' . (hexdec(substr(hash('sha256', IpAddress::network($ip)), 0, 8)) % SandboxHost::NETWORK_LOCKS) . '.lock';
};

$manifest = array(
	'send_email' => true,
	'models' => array(array('name' => 'Note', 'fields' => array(array('name' => 'text', 'type' => 'text')))),
	'profile_pages' => array(array('slug' => 'mine', 'title' => 'Mine', 'sections' => array(array('type' => 'text', 'text' => 'Yours.')))),
	'public_pages' => array(
		array('slug' => 'index', 'title' => 'Welcome', 'sections' => array(array('type' => 'text', 'text' => 'Hello, anyone.'))),
		array('slug' => 'about', 'title' => 'About us', 'cache_query' => array('id'),
			'sections' => array(array('type' => 'text', 'text' => 'We are a plugin.'))),
		array('slug' => 'contact', 'title' => 'Contact', 'sections' => array(
			array('type' => 'form', 'handler' => 'contact', 'fields' => array(array('name' => 'msg', 'label' => 'Message')))),
		),
		array('slug' => 'board', 'title' => 'Board'),
		array('slug' => 'live', 'title' => 'Live', 'frame' => true, 'handlers' => array('poll')),
		array('slug' => 'nocache', 'title' => 'Fresh', 'cache' => false, 'sections' => array(array('type' => 'text', 'text' => 'Always new.'))),
	),
);

try {
	$admin = make_user('SbxPublicAdmin', 10);
	$member = make_user('SbxPublicMember', 0);

	section('The manifest');
	$base = array('name' => 'hts_m', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1, 'id' => '0123456789abcdef0123456789abcdef');
	$m = SandboxManifest::fromArray($base + $manifest, 'hts_m');
	check(count($m->publicPages()) === 6, 'public_pages are read');
	$problems = function (callable $change) use ($base, $manifest) {
		$d = $base + $manifest;
		$change($d);
		return implode(' | ', SandboxManifest::problems($d, 'hts_m'));
	};
	$p = $problems(function (&$d) { $d['public_pages'][0]['sections'] = array(array('type' => 'table', 'model' => 'Note', 'columns' => array('text'))); });
	check(strpos($p, 'cannot show a table') !== false, 'A public page cannot show a table', $p);
	$p = $problems(function (&$d) { $d['public_pages'][0]['sections'] = array(array('type' => 'settings')); });
	check(strpos($p, 'cannot hold the plugin\'s settings') !== false, 'Nor the settings', $p);
	$p = $problems(function (&$d) { $d['public_pages'][0]['cache'] = 'yes'; });
	check(strpos($p, 'cache is true or false') !== false, 'cache is true or false', $p);
	$p = $problems(function (&$d) { $d['public_pages'][1]['cache_query'] = array('plugin'); });
	check(strpos($p, 'is not a query name') !== false, 'cache_query cannot name core\'s keys', $p);
	$p = $problems(function (&$d) { $d['public_pages'][1]['cache_query'] = array('A b'); });
	check(strpos($p, 'is not a query name') !== false, 'nor anything but a plain name', $p);
	$p = $problems(function (&$d) { $d['public_pages'][0]['extra'] = 1; });
	check(strpos($p, 'may hold only slug, title, sections, frame, handlers, cache and cache_query') !== false, 'No other key', $p);
	$p = $problems(function (&$d) { $d['public_pages'][0]['slug'] = 'mine'; });
	check(strpos($p, "a member page's too") !== false, 'A slug cannot be a member page\'s too (both drawn by src/views/{slug}.php)', $p);
	$p = $problems(function (&$d) { $d['public_pages'][2]['sections'][0]['fields'][0]['name'] = 'website_url'; });
	check(strpos($p, 'a name the site uses on the form') !== false, 'A form field cannot take a spam check\'s name (R4)', $p);
	$p = $problems(function (&$d) { $d['public_pages'][2]['sections'][0]['fields'][0]['name'] = 'section'; });
	check(strpos($p, 'a name the site uses on the form') !== false, 'nor a page key\'s', $p);
	$p = $problems(function (&$d) { $d['profile_pages'][0]['cache'] = true; });
	check(strpos($p, 'may hold only slug, title, sections, frame and handlers') !== false, 'A member page has no cache key', $p);

	section('The approval');
	$grants = SandboxConsent::grantsOf($m);
	check(($grants['public_pages']['contact'] ?? '') === 'Contact (with a form visitors fill in)'
		&& strpos((string)($grants['public_pages']['board'] ?? ''), 'drawn by the plugin') !== false,
		'Each public page is a grant, saying what it is', json_encode($grants['public_pages']));
	$described = SandboxConsent::describe($grants);
	$line = 'Pages anyone can open, signed in or not. What they show and do runs with the access approved here';
	check(isset($described[$line]) && in_array('About us', $described[$line], true), 'The approval screen names them', json_encode(array_keys($described)));
	$more = SandboxConsent::additions($grants, array('public_pages' => array()) + $grants);
	check(isset($more['public_pages']['about']), 'A new public page asks again', json_encode($more));

	section('Installed and approved');
	$p = sandbox_fixture_plugin($manifest);
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	$host = sandbox_stand_in_runner(array(
		'on_page' => array('ok' => true, 'output' => array('body' => '<p class="b">Board</p><form method="post"><input name="msg"><button>Send</button></form>')),
		'on_ui'   => array('ok' => true, 'output' => array('message' => 'Thanks')),
	));
	$last = function () use ($host) {
		$r = $host['requests']();
		return end($r) ?: array();
	};

	section('The address');
	check((SandboxPublicPages::forPath('/' . $name . '/about')['page']['slug'] ?? null) === 'about', '/{plugin}/{slug} names its public page');
	check((SandboxPublicPages::forPath('/' . $name)['page']['slug'] ?? null) === 'index', '/{plugin} names its index');
	check(SandboxPublicPages::forPath('/' . $name . '/mine') === null, 'A member page is not a public page');
	check(SandboxPublicPages::forPath('/' . $name . '/nope') === null && SandboxPublicPages::forPath('/' . $name . '/about/more') === null,
		'Nor any other address under it');
	check(SandboxPublicPages::url($name, 'about') === '/' . $name . '/about', 'Its address');
	$row = SandboxInstall::forPlugin($name);
	$consent = $row->consent();
	unset($consent['public_pages']['about']);
	$row->set('sbi_consent', $consent);
	$row->save();
	check(SandboxPublicPages::forPath('/' . $name . '/about') === null, 'A public page not approved is not there');
	sandbox_approve($name);

	$web = rtrim((string)Globalvars::get_instance()->get_setting('webDir'), '/');
	$web = preg_match('~^https?://~', $web) ? $web : 'https://' . $web;
	$fetch = function (string $path) use ($web) {
		$ch = curl_init($web . $path);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'Mozilla/5.0 (harness)'));
		$body = (string)curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array($code, $body);
	};
	list($code, $body) = $fetch('/' . $name . '/about');
	if ($code === 0) {
		harness_skip('The address over HTTP', 'this site does not answer at ' . $web);
	} else {
		check($code === 200 && strpos($body, 'We are a plugin.') !== false && strpos($body, 'drawn by the sandboxed plugin') !== false,
			'Over HTTP, a visitor not signed in opens /{plugin}/{slug}, under the provenance line', $code . ' ' . mb_substr(strip_tags($body), 0, 300));
		list($code) = $fetch('/' . $name . '/nope');
		check($code === 404, 'Another address under it is a 404', (string)$code);
		list($code) = $fetch('/' . $name . '/mine');
		check($code === 404, 'And a member page is not opened there', (string)$code);
		list($code) = $fetch('/sandbox_public_page');
		check($code === 404, 'The view at its own name is a 404', (string)$code);
		list($code, $body) = $fetch('/' . $name);
		check($code === 200 && strpos($body, 'Hello, anyone.') !== false, '/{plugin} opens its index', (string)$code);
	}

	section('A visitor not signed in');
	$sign_out();
	$from('198.51.100.7');
	$networks[] = '198.51.100.7';
	harness_set_setting_mem('enable_csp', '1');
	harness_set_setting_mem('csp_report_only', '0');
	harness_set_setting_mem('csp_strict_scripts', 'enforce');
	// The honeypot and captcha off; a blank question cannot be forced in
	// memory (harness_set_setting_mem()), so the posts below give the site's
	// own answer, whatever it is.
	harness_set_setting_mem('use_honeypot', '0');
	harness_set_setting_mem('use_captcha', '0');
	$answer = function () {
		return (string)Globalvars::get_instance()->get_setting('anti_spam_answer');
	};
	$public_logic = function (string $slug, array $data, string $method) use ($name) {
		$_SERVER['REQUEST_URI'] = '/' . $name . '/' . $slug;
		return harness_call_logic('logic/sandbox_public_page_logic.php', 'sandbox_public_page_logic', $data, $method);
	};
	$before = $count_logs($name);
	$r = $public_logic('board', array(), 'GET');
	$env = $last();
	check(!empty($r->data['drawn']['ok']) && ($env['input']['kind'] ?? '') === 'public' && $env['input']['user'] === null
		&& ($env['anonymous'] ?? null) === array('checked_post' => false), 'A GET is the plugin\'s view with no viewer, an anonymous call, not a checked POST',
		json_encode(array($r->data['drawn'] ?? $r->error, $env['anonymous'] ?? null)));
	check((int)($r->data['drawn']['post_forms'] ?? 0) === 1 && strpos((string)$r->data['drawn']['html'], 'sbxpost:') === false,
		'Its POST form is kept, counted by the cleaner, and the marker is gone', json_encode($r->data['drawn'] ?? null));
	$q = $db->prepare('SELECT sbl_usr_user_id, sbl_ip_network FROM sbl_sandbox_logs WHERE sbl_plugin = ? ORDER BY sbl_sandbox_log_id DESC LIMIT 1');
	$q->execute(array($name));
	$logged = $q->fetch(PDO::FETCH_ASSOC);
	check($count_logs($name) === $before + 1 && $logged['sbl_usr_user_id'] === null && $logged['sbl_ip_network'] === '198.51.100.7',
		'Its log row has no user and carries the visitor\'s network', json_encode($logged));
	check($count_limit_rows('198.51.100.7') === 1, 'and one row counts it against the network\'s limit');

	$r = $public_logic('board', array('msg' => 'hi', 'antispam_question' => $answer()), 'POST');
	$env = $last();
	check(($env['anonymous'] ?? null) === array('checked_post' => true) && ($env['input']['fields']['msg'] ?? null) === 'hi',
		'A POST that passed the spam checks is a checked POST', json_encode(array($env['anonymous'] ?? null, $r->redirect)));

	$n = count($host['requests']());
	$r = $public_logic('contact', array('action' => 'form', 'section' => '0', 'sbx_msg' => 'hello', 'antispam_question' => $answer()), 'POST');
	$env = $last();
	check(count($host['requests']()) === $n + 1 && ($env['input']['handler'] ?? '') === 'contact' && ($env['input']['kind'] ?? '') === 'public'
		&& ($env['anonymous'] ?? null) === array('checked_post' => true) && $r->redirect === '/' . $name . '/contact',
		'A drawn form\'s POST reaches its handler as a checked POST, and comes back to the page', json_encode(array($env['input'] ?? null, $r->redirect)));

	section('The spam checks');
	harness_set_setting_mem('use_honeypot', '1');
	harness_set_setting_mem('anti_spam_answer', 'Blue');
	check(SandboxPublicPages::spamCheck(array('website_url' => 'x', 'antispam_question' => 'blue')) === 'honeypot', 'A filled honeypot is caught (answered 404)');
	check(strpos(SandboxPublicPages::spamCheck(array('antispam_question' => 'red')), 'anti-spam') !== false, 'A wrong answer is caught');
	check(SandboxPublicPages::spamCheck(array('antispam_question' => 'BLUE')) === '', 'The right answer passes');
	$n = count($host['requests']());
	$r = $public_logic('board', array('msg' => 'hi', 'antispam_question' => 'red'), 'POST');
	check(count($host['requests']()) === $n && $r->redirect === '/' . $name . '/board', 'A POST failing a check never reaches the plugin');
	$r = $public_logic('board', array('msg' => 'hi', 'antispam_question' => 'blue', 'website_url' => ''), 'POST');
	$env = $last();
	check(($env['anonymous']['checked_post'] ?? null) === true && !isset($env['input']['fields']['antispam_question']) && !array_key_exists('website_url', $env['input']['fields']),
		'One that passes reaches it, without the checks\' fields', json_encode($env['input']['fields'] ?? null));
	$r = $public_logic('board', array(), 'GET');
	$html = (string)($r->data['drawn']['html'] ?? '');
	check(strpos($html, 'name="website_url"') !== false && strpos($html, 'name="antispam_question"') !== false
		&& strpos($html, 'position:absolute') !== false, 'The site\'s inputs are in the plugin\'s POST form, added after cleaning', $html);
	$token = bin2hex(random_bytes(16));
	$clean = DocumentText::parseWith('SandboxHtmlCleaner', '<p title="<form >">x</p><form title="a>b" method="post"><input name="q"></form>'
		. '<form method="get"><input name="s"></form>', array('page_url' => '/x', 'asset_url' => '/a', 'image_hosts' => array(), 'form_hidden' => array(),
		'post_marker' => $token));
	$text = (string)$clean['text'];
	check(substr_count($text, SandboxHtmlCleaner::postMarker($token)) === 1
		&& strpos($text, SandboxHtmlCleaner::postMarker($token) . '</form><form method="get"') !== false,
		'A POST form is found from the DOM, whatever its attributes hold, and a GET form is not (R1)', $text);
	$clean = DocumentText::parseWith('SandboxHtmlCleaner', '<form method="post"><input name="website_url"><input name="antispam_question_answer" value="blue"></form>',
		array('page_url' => '/x', 'asset_url' => '/a', 'image_hosts' => array(), 'form_hidden' => array()));
	check(strpos((string)$clean['text'], 'website_url') === false && strpos((string)$clean['text'], 'antispam_question_answer') === false,
		'A plugin cannot draw the checks\' fields', (string)$clean['text']);
	$sign_in($member);
	$r = $public_logic('board', array(), 'GET');
	check(strpos((string)($r->data['drawn']['html'] ?? ''), 'website_url') === false, 'A signed-in visitor gets no spam checks');
	$sign_out();
	harness_set_setting_mem('use_honeypot', '0');

	section('A signed-in visitor is the viewer');
	$sign_in($member);
	$r = $public_logic('board', array(), 'GET');
	$env = $last();
	check(($env['input']['user']['id'] ?? null) === (int)$member->key && !isset($env['anonymous']), 'The call is the member\'s, with full access', json_encode($env['anonymous'] ?? null));
	$held = array($hold('anon_slot_0.lock'), $hold('anon_slot_1.lock'));
	$r = $public_logic('board', array(), 'GET');
	check(!empty($r->data['drawn']['ok']), 'and takes a member\'s slot, not the visitors\' pool', json_encode($r->data['drawn'] ?? null));
	$release($held);
	$sign_out();

	section('Load');
	$held = array($hold('anon_slot_0.lock'), $hold('anon_slot_1.lock'));
	$before = $count_logs($name);
	$rows_before = $count_limit_rows('198.51.100.7');
	$res = SandboxHost::page($name, 'public', 'board', 'GET', array(), array(), null, array(), array('checked_post' => false));
	check($res['status'] === SandboxLog::STATUS_BUSY, 'With the visitors\' pool held, a visitor is told it is busy', json_encode($res));
	check($count_logs($name) === $before && $count_limit_rows('198.51.100.7') === $rows_before, 'and nothing is written for the refusal');
	$res = SandboxHost::page($name, 'member', 'mine', 'GET', array(), array(), $member);
	check($res['status'] === SandboxLog::STATUS_OK, 'while a member\'s page still runs');
	$res = SandboxHost::ui($name, 'contact', array(), $admin);
	check($res['status'] === SandboxLog::STATUS_OK, 'and an admin\'s');
	$release($held);

	$held = array($hold($net_lock('198.51.100.7')));
	$res = SandboxHost::page($name, 'public', 'board', 'GET', array(), array(), null, array(), array('checked_post' => false));
	check($res['status'] === SandboxLog::STATUS_BUSY && ($res['refused'] ?? '') === 'network_busy', 'One call at a time per network', json_encode($res));
	$other = '203.0.113.9';
	$networks[] = $other;
	$from($other);
	if ($net_lock($other) !== $net_lock('198.51.100.7')) {
		$res = SandboxHost::page($name, 'public', 'board', 'GET', array(), array(), null, array(), array('checked_post' => false));
		check($res['status'] === SandboxLog::STATUS_OK, 'and another network\'s call runs', json_encode($res));
	}
	$release($held);

	$v6 = '2001:db8:77:1::1';
	$networks[] = IpAddress::network($v6);
	$from($v6);
	for ($i = 0; $i < SandboxHost::PUBLIC_CALLS_PER_MINUTE; $i++) {
		RequestLogger::log(SandboxHost::PUBLIC_LIMIT_FEATURE, 'call', true);
	}
	$from('2001:db8:77:1:ffff::2');
	$before = $count_logs($name);
	$res = SandboxHost::page($name, 'public', 'board', 'GET', array(), array(), null, array(), array('checked_post' => false));
	check(($res['refused'] ?? '') === 'limited' && $count_logs($name) === $before, 'An IPv6 visitor is limited across its /64, and the refusal leaves no row', json_encode($res));
	$from('2001:db8:77:2::1');
	$networks[] = IpAddress::network('2001:db8:77:2::1');
	$res = SandboxHost::page($name, 'public', 'board', 'GET', array(), array(), null, array(), array('checked_post' => false));
	check($res['status'] === SandboxLog::STATUS_OK, 'and the next /64 is not', json_encode($res));
	$from('198.51.100.7');

	$rules = ScheduledTaskRegistry::retentionRules();
	check(($rules['sbl_sandbox_logs:anonymous']['policy']['only_where'] ?? '') === 'sbl_ip_network IS NOT NULL'
		&& ($rules['sbl_sandbox_logs:anonymous']['policy']['window_setting'] ?? '') === 'sandbox_anonymous_log_retention_days'
		&& isset($rules['sbl_sandbox_logs:all']), 'Visitors\' rows have a retention of their own', json_encode(array_keys($rules)));
	check((string)Globalvars::get_instance()->get_setting('sandbox_anonymous_log_retention_days') === '2', 'two days by default');

	section('The relay, for a visitor not signed in');
	$relay = function (bool $checked) use ($name) {
		return new SandboxRelay($name, array('input' => array('user' => null), 'anonymous' => array('checked_post' => $checked)));
	};
	$mail = function (array $to) {
		$message = EmailMessage::create($to[0], 'Hello', 'Body');
		foreach (array_slice($to, 1) as $address) {
			$message->cc($address);
		}
		return $message->toTransfer();
	};
	$reads_only = 'may only read';
	$r = $relay(false);
	foreach (array(
		'a create' => array('ask' => 'record.create', 'model' => 'Note', 'fields' => array('text' => 'x')),
		'an update' => array('ask' => 'record.update', 'model' => 'Note', 'id' => 1, 'fields' => array('text' => 'x')),
		'a delete' => array('ask' => 'record.delete', 'model' => 'Note', 'id' => 1),
		'an action' => array('ask' => 'action', 'name' => 'notification_unread_count', 'input' => array()),
		'mail' => array('ask' => 'email.send', 'message' => $mail(array('a@example.com'))),
		'an outside request' => array('ask' => 'http', 'method' => 'GET', 'url' => 'https://example.com/'),
		'page.refresh' => array('ask' => 'page.refresh', 'path' => '/' . $name . '/about'),
		'an upload' => array('ask' => 'file.bytes', 'part' => '', 'more' => false, 'name' => 'a.txt'),
	) as $what => $ask) {
		$a = $r->handle($ask);
		check(empty($a['ok']) && strpos((string)($a['error'] ?? ''), $reads_only) !== false, 'A GET or handler call is refused ' . $what, json_encode($a));
	}
	$a = $r->handle(array('ask' => 'log', 'level' => 'info', 'message' => 'hi'));
	check(!empty($a['ok']), 'but may log');
	$a = $r->handle(array('ask' => 'record.get', 'model' => 'Note', 'id' => 0));
	check(strpos((string)($a['error'] ?? ''), $reads_only) === false, 'and read', json_encode($a));

	$r = $relay(true);
	$refused = 0;
	for ($i = 1; $i <= 11; $i++) {
		// An id of 0 is refused after the count, so nothing is written.
		$a = $r->handle(array('ask' => 'record.update', 'model' => 'Note', 'id' => 0, 'fields' => array('text' => 'x')));
		if (strpos((string)($a['error'] ?? ''), 'at most 10 changes') !== false) {
			$refused = $i;
			break;
		}
	}
	check($refused === 11, 'A checked POST is refused its eleventh write', (string)$refused);
	$r2 = $relay(true);
	for ($i = 0; $i < 10; $i++) {
		$r2->handle(array('ask' => 'record.delete', 'model' => 'Note', 'id' => 0));
	}
	$a = $r2->handle(array('ask' => 'action', 'name' => 'notification_unread_count', 'input' => array()));
	check(strpos((string)($a['error'] ?? ''), 'at most 10 changes') !== false, 'and an action counts in the same 10 (P18)', json_encode($a));
	$a = $r->handle(array('ask' => 'email.send', 'message' => $mail(array('a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'))));
	check(strpos((string)($a['error'] ?? ''), 'at most 3 people') !== false, 'With every spam check off, a checked POST is still capped at 3 recipients', json_encode($a));
	$a = $r->handle(array('ask' => 'email.batch', 'message' => $mail(array('a@example.com')), 'recipients' => array('b@example.com')));
	check(strpos((string)($a['error'] ?? ''), 'batch') !== false, 'and never sends a batch', json_encode($a));
	$from('198.51.100.8');
	$networks[] = '198.51.100.8';
	for ($i = 0; $i < SandboxRelay::ANON_MAIL_PER_HOUR - 1; $i++) {
		RequestLogger::log(SandboxRelay::ANON_MAIL_FEATURE, 'recipient', true);
	}
	$a = $relay(true)->handle(array('ask' => 'email.send', 'message' => $mail(array('a@example.com', 'b@example.com'))));
	check(strpos((string)($a['error'] ?? ''), 'in the last hour') !== false, 'Each recipient counts against the network\'s 10 an hour', json_encode($a));
	$from('198.51.100.7');

	section('The cache');
	$dir = harness_scratch_dir('sbx_public_cache');
	StaticPageCache::setCacheDirForTests($dir);
	harness_defer(function () { StaticPageCache::setCacheDirForTests(null); });
	$page = function (string $slug) use ($name) {
		return SandboxPublicPages::page($name, $slug);
	};
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$verdict = function (string $slug, array $query, bool $drawn = true, bool $form = false) use ($name, $page, $dir) {
		StaticPageCache::setCacheDirForTests($dir);
		$ok = SandboxPublicPages::mayCache($name, $page($slug), $query, $drawn, $form);
		$ignored = StaticPageCache::shouldIgnore('/' . $name . '/' . $slug, $query);
		return array($ok, $ignored);
	};
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (harness)';
	check($verdict('about', array()) === array(true, false), 'The bare address is cached');
	check($verdict('about', array('id' => '4')) === array(true, false), 'and a query name the page lists');
	check($verdict('about', array('id' => '4', 'x' => '1')) === array(false, true), 'but not with a name it does not list: skipped, no index entry');
	check($verdict('index', array('q' => '1')) === array(false, true), 'A page listing none is cached at its bare address only');
	check($verdict('about', array('plugin' => '1')) === array(false, true) && $verdict('about', array('website_url' => '1')) === array(false, true),
		'A name core drops from the plugin\'s query still counts against the cache (R3)');
	check($verdict('contact', array(), true, true) === array(false, true), 'A page with a POST form is never cached');
	check($verdict('live', array()) === array(false, true), 'Nor a framed page');
	check($verdict('nocache', array()) === array(false, true), 'Nor one that opts out');
	check($verdict('board', array(), false) === array(false, true), 'Nor a draw that failed (busy, limited)');
	StaticPageCache::setCacheDirForTests($dir);
	for ($i = 0; $i < SandboxPublicPages::MAX_COPIES; $i++) {
		StaticPageCache::createCache('/' . $name . '/about', array('id' => (string)$i), '<html><body>' . $i . '</body></html>', array('headers' => array(), 'https_redirect' => false));
	}
	check(StaticPageCache::countPath('/' . $name . '/about') === SandboxPublicPages::MAX_COPIES, 'Two hundred copies of one page are kept',
		(string)StaticPageCache::countPath('/' . $name . '/about'));
	check($verdict('about', array('id' => '9999')) === array(false, true), 'and past them a request is served uncached');
	$sign_in($member);
	check($verdict('about', array()) === array(false, false), 'A signed-in visitor\'s page is never cached (no change made)');
	$sign_out();

	StaticPageCache::setCacheDirForTests($dir);
	$a = (new SandboxRelay($name, array('input' => array('user' => null))))->handle(array('ask' => 'page.refresh', 'path' => '/' . $name . '/about'));
	check(!empty($a['ok']) && ($a['removed'] ?? 0) === SandboxPublicPages::MAX_COPIES && StaticPageCache::countPath('/' . $name . '/about') === 0,
		'page.refresh drops its page\'s copies', json_encode($a));
	$a = (new SandboxRelay($name, array('input' => array('user' => null))))->handle(array('ask' => 'page.refresh', 'path' => '/login'));
	check(empty($a['ok']) && strpos((string)($a['error'] ?? ''), 'its own pages only') !== false, 'but no page outside its own address', json_encode($a));
	$a = (new SandboxRelay($name, array('input' => array('user' => null))))->handle(array('ask' => 'page.refresh', 'path' => '/' . $name . 'x/about'));
	check(empty($a['ok']), 'nor a plugin whose name starts the same', json_encode($a));
	$a = $relay(true)->handle(array('ask' => 'page.refresh', 'path' => '/' . $name . '/about'));
	check(!empty($a['ok']), 'and a checked POST may too');
	StaticPageCache::createCache('/' . $name . '/about', array(), '<html><body>a</body></html>', array('headers' => array(), 'https_redirect' => false));
	SandboxPluginManager::deactivate($name);
	check(StaticPageCache::countPath('/' . $name . '/about') === 0, 'Deactivating drops its cached pages');
	StaticPageCache::createCache('/' . $name . '/about', array(), '<html><body>a</body></html>', array('headers' => array(), 'https_redirect' => false));
	SandboxPluginManager::reconcile($name);
	check(StaticPageCache::countPath('/' . $name . '/about') === 0, 'and so does a new approval, activation or files');
	StaticPageCache::createCache('/' . $name . '/about', array(), '<html><body>a</body></html>', array('headers' => array(), 'https_redirect' => false));
	SandboxPluginManager::install($name);
	check(StaticPageCache::countPath('/' . $name . '/about') === 0, 'and an install');
	$in_fence = (string)file_get_contents(PathHelper::getIncludePath('utils/sandbox_runtime.php'));
	check(strpos($in_fence, "SandboxPipe::ask('page.refresh'") !== false
		&& strpos((string)file_get_contents(PathHelper::getIncludePath('includes/StaticPageCache.php')), "function_exists('joinery_fence_page_refresh')") !== false,
		'In the fence, StaticPageCache::invalidatePath() is the page.refresh ask, as native code calls it');
	StaticPageCache::setCacheDirForTests(null);
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$res = SandboxHost::page($name, 'member', 'mine', 'GET', array(), array(), null);
	$env = $last();
	check(($env['anonymous'] ?? null) === array('checked_post' => false), 'A page or UI call with no user is a visitor\'s, read only, whatever its caller passed (R5)',
		json_encode($env['anonymous'] ?? null));
	StaticPageCache::setCacheDirForTests($dir);
	StaticPageCache::createCache('/' . $name . '/about', array('id' => '1-2'), '<html><body>one-two</body></html>', array('headers' => array(), 'https_redirect' => false));
	StaticPageCache::createCache('/' . $name . '/about', array('id' => '12'), '<html><body>twelve</body></html>', array('headers' => array(), 'https_redirect' => false));
	check(StaticPageCache::countPath('/' . $name . '/about') === 2, '?id=1-2 and ?id=12 are two cached pages, not one (R6)',
		(string)StaticPageCache::countPath('/' . $name . '/about'));
	StaticPageCache::setCacheDirForTests(null);
	$frame_view = (string)file_get_contents(PathHelper::getIncludePath('views/sandbox_frame.php'));
	check(strpos($frame_view, 'StaticPageCache::skipThisRequest();') !== false, 'The frame document is never cached');

	section('Framed public pages');
	$session = SessionControl::get_instance();
	check(is_array(SandboxPages::framedPage($name, 'public', 'live', $session)), 'A guest may open a framed public page');
	check(SandboxPages::framedPage($name, 'member', 'live', $session) === 'That page does not exist.', 'but not as a member page');
	check(strpos(SandboxPages::frameUrl($name, 'public', 'live'), 'kind=public') !== false, 'Its frame says it is public');
	$frame_call = function (string $kind, string $handler) use ($name) {
		return harness_call_logic('logic/sandbox_frame_call_logic.php', 'sandbox_frame_call_logic',
			array('plugin' => $name, 'kind' => $kind, 'page' => 'live', 'handler' => $handler, 'data' => array('x' => 1)), 'POST');
	};
	$r = $frame_call('public', 'poll');
	$env = $last();
	check(!empty($r->data['ok']) && ($env['input']['source'] ?? '') === 'frame' && ($env['anonymous'] ?? null) === array('checked_post' => false),
		'A guest\'s frame call is an anonymous call that only reads', json_encode(array($r->data, $r->error, $env['anonymous'] ?? null)));
	foreach (array('member', 'admin') as $kind) {
		$n = count($host['requests']());
		$r = $frame_call($kind, 'poll');
		check((string)$r->error === 'That page does not exist.' && count($host['requests']()) === $n,
			'A guest calling sandbox_frame_call for a ' . $kind . ' page is refused', json_encode(array($r->error, $r->data)));
	}
	require_once(PathHelper::getIncludePath('logic/sandbox_frame_call_logic.php'));
	$auth = sandbox_frame_call_logic_descriptor()['auth'];
	check(!empty($auth['allow_guest']) && !empty($auth['requires_person_credential']), 'The frame call admits a guest, and never a machine key', json_encode($auth));
	check(!function_exists('sandbox_public_page_logic_descriptor'), 'The public page is not an API action (P9)');
	$redirect = SandboxPages::redirectTarget($name, 'public', array('page' => 'about', 'query' => array('id' => '3')));
	check($redirect === '/' . $name . '/about?id=3', 'A public page redirects to its own public pages', (string)$redirect);
	check(SandboxPages::redirectTarget($name, 'public', array('page' => 'mine')) === null, 'and never to a member page');

	section('The plugin\'s own code');
	$host['stop']();
	$sign_out();
	$from('198.51.100.7');
	$view = <<<'PHP'
<?php
$me = (string)SandboxRuntime::$envelope['plugin'];
try {
	$n = StaticPageCache::invalidatePath('/' . $me . '/about');
	echo '<p class="r">dropped ' . (int)$n . '</p>';
} catch (Throwable $e) {
	echo '<p class="r">refused: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '<p class="k">kind ' . htmlspecialchars((string)(SandboxRuntime::$envelope['input']['kind'] ?? '')) . '</p>';
PHP;
	$own = array('public_pages' => array(array('slug' => 'board', 'title' => 'Board')));
	$run_own = function (string $plugin, string $label) use ($public_logic, $answer, $dir) {
		StaticPageCache::setCacheDirForTests($dir);
		StaticPageCache::createCache('/' . $plugin . '/about', array(), '<html><body>a</body></html>', array('headers' => array(), 'https_redirect' => false));
		$_SERVER['REQUEST_URI'] = '/' . $plugin . '/board';
		$r = harness_call_logic('logic/sandbox_public_page_logic.php', 'sandbox_public_page_logic', array(), 'GET');
		$html = (string)($r->data['drawn']['html'] ?? '');
		check(strpos($html, 'kind public') !== false, $label . ': a public page is drawn by src/views/{slug}.php, told it is public', json_encode($r->data['drawn'] ?? $r->error));
		check(strpos($html, 'refused: ') !== false && strpos($html, 'may only read') !== false
			&& StaticPageCache::countPath('/' . $plugin . '/about') === 1,
			$label . ': on a visitor\'s GET, StaticPageCache::invalidatePath() is refused', $html);
		$_SERVER['REQUEST_URI'] = '/' . $plugin . '/board';
		$r = harness_call_logic('logic/sandbox_public_page_logic.php', 'sandbox_public_page_logic', array('antispam_question' => $answer()), 'POST');
		$html = (string)($r->data['drawn']['html'] ?? '');
		check(strpos($html, 'dropped 1') !== false && StaticPageCache::countPath('/' . $plugin . '/about') === 0,
			$label . ': on a checked POST it drops the page\'s cached copies, as native code does', $html);
		StaticPageCache::setCacheDirForTests(null);
	};
	$q = sandbox_fixture_plugin($own, array('files' => array('views/board.php' => $view)));
	$pm->install($q['name'], false);
	sandbox_approve($q['name']);
	$pm->activate($q['name']);
	SandboxPlugins::reset();
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$run_own($q['name'], 'Unfenced');
	SandboxRunner::$command_override = null;

	$fence = 'sbxpublic';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced public page', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($own, array('name' => $fence, 'files' => array('views/board.php' => $view), 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced public page', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence, false);
			sandbox_approve($fence);
			$pm->activate($fence);
			SandboxPlugins::reset();
			$run_own($fence, 'Fenced');
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
