<?php
/** @joinery-test
 * name: sandbox_own_rows
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Own-rows grants (specs/sandboxed_php_plugins.md WP5a, design 8) and the
 * admin page check of design 11.
 *
 * What is pinned here, unfenced through the same PHP command line and, where
 * this box has the plugin's user, in the real fence:
 *  - a plugin granted "each member's own Notification: read" lists only the
 *    viewing member's rows, and the list's count is theirs alone;
 *  - an admin viewing the page gets only the admin's own rows, not everyone's;
 *  - reading another member's row by id is refused;
 *  - a signal call, which has no viewer, gets nothing;
 *  - the viewer header is refused on any key that is not a sandboxed
 *    plugin's, so nobody else can name a viewer;
 *  - the manifest refuses an own-rows grant on a model with no owner column
 *    or on public content; the approval screen names it, and warns when
 *    personal records and outside websites go together; a new own-rows
 *    grant in an update waits on approval;
 *  - a plugin holding every row of a personal model shows its admin page
 *    only to a viewer who may read that model (design 11).
 *
 * Run: php tests/run.php db --filter=sandbox_own_rows
 *
 * @version 1.2 - the own-rows line reads in plain words (found in a real browser)
 * @version 1.1 - reviewer2 J1-J3: credential models and models the API does not show are refused
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

$files = array(
	'views/mine.php' => <<<'PHP'
<?php
$titles = array();
foreach (new MultiNotification(array('type' => 'sbx_own_test'), array('notification_id' => 'ASC')) as $n) {
	$titles[] = $n->get('ntf_title');
}
$total = (new MultiNotification(array('type' => 'sbx_own_test')))->count_all();
$other = 'none';
try {
	$o = new Notification((int)SandboxPage::query('other'), TRUE);
	$other = $o->key ? 'read' : 'no key';
} catch (Throwable $e) {
	$other = 'refused';
}
echo '<p>titles=' . htmlspecialchars(implode('|', $titles)) . ';total=' . (int)$total . ';other=' . $other . '</p>';
PHP,
	'plugin.php' => <<<'PHP'
<?php
function on_signal(array $input) {
	try {
		return array('count' => count(new MultiNotification(array('type' => 'sbx_own_test'))));
	} catch (Throwable $e) {
		return array('refused' => $e->getMessage());
	}
}
PHP,
);
$manifest = array(
	'permissions' => array('own_records' => array('Notification' => array('read'))),
	'profile_pages' => array(array('slug' => 'mine', 'title' => 'Mine')),
	'signals' => array('account.signup'),
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
harness_set_setting_mem('enable_csp', '1');
harness_set_setting_mem('csp_report_only', '0');
harness_set_setting_mem('csp_strict_scripts', 'enforce');

/** A notification of the test's kind for $user. */
function sbx_own_note(User $user, string $title): Notification {
	$n = new Notification(NULL);
	$n->set('ntf_usr_user_id', (int)$user->key);
	$n->set('ntf_type', 'sbx_own_test');
	$n->set('ntf_title', $title);
	$n->save();
	$n->load();
	harness_register_model('Notification', (int)$n->key);
	return $n;
}

$checks = function (string $name, string $label, array $people, Notification $b_note) use ($sign_in) {
	list($a, $b, $admin) = $people;
	$logic = 'logic/sandbox_page_logic.php';
	$view = function (User $who, int $other) use ($logic, $name, $sign_in) {
		$sign_in($who);
		$r = harness_call_logic($logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'mine', 'other' => $other), 'GET');
		return (string)($r->data['drawn']['html'] ?? json_encode(array($r->error, $r->data['drawn'] ?? null)));
	};
	$html = $view($a, (int)$b_note->key);
	check(strpos($html, 'titles=A1|A2;total=2') !== false, $label . ': a member sees only their own rows, and the count is theirs alone', $html);
	check(strpos($html, 'other=refused') !== false || strpos($html, 'other=no key') !== false, $label . ': another member\'s row by id is not read', $html);
	$html = $view($b, 0);
	check(strpos($html, 'titles=B1;total=1') !== false, $label . ': the other member sees only theirs', $html);
	$html = $view($admin, (int)$b_note->key);
	check(strpos($html, 'titles=;total=0') !== false && strpos($html, 'other=read') === false,
		$label . ': an admin viewing the page gets only the admin\'s own rows, as a member', $html);
	$result = SandboxHost::signal($name, 'account.signup', array('user_id' => (int)$a->key), 'msg_' . bin2hex(random_bytes(4)), gmdate('Y-m-d\TH:i:s\Z'));
	check(strpos((string)($result['output']['refused'] ?? ''), 'no member viewing') !== false, $label . ': a signal call, with no viewer, gets nothing', json_encode($result['output'] ?? $result['message']));
};

try {
	$a = make_user('SbxOwnA', 0);
	$b = make_user('SbxOwnB', 0);
	$admin = make_user('SbxOwnAdmin', 10);
	sbx_own_note($a, 'A1');
	sbx_own_note($a, 'A2');
	$b_note = sbx_own_note($b, 'B1');
	sbx_own_note($admin, 'X');
	// The admin's own row has another type, so "only the admin's own" reads as none of the test's.
	$q = DbConnector::get_instance()->get_db_link()->prepare("UPDATE ntf_notifications SET ntf_type = 'sbx_own_other' WHERE ntf_usr_user_id = ? AND ntf_type = 'sbx_own_test'");
	$q->execute(array((int)$admin->key));

	section('The manifest and the approval screen');
	foreach (array('User' => 'no owner column', 'Page' => 'public content', 'Passkey' => 'sign-in or key details',
		'ApiKey' => 'API does not show') as $model => $words) {
		$m = SandboxManifest::fromArray(array('name' => 'hts_ow', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
			'permissions' => array('own_records' => array($model => array('read')))), 'hts_ow');
		check(strpos(implode(' ', SandboxCompatibility::problems($m)), $words) !== false, 'An own-rows grant on ' . $model . ' is refused: ' . $words,
			implode(' ', SandboxCompatibility::problems($m)));
	}
	$m = SandboxManifest::fromArray(array('name' => 'hts_ow', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1) + $manifest
		+ array('outbound_hosts' => array('hooks.example.com')), 'hts_ow');
	$words = SandboxConsent::describe(SandboxConsent::grantsOf($m));
	check(strpos(json_encode($words["Each member's own records"] ?? null), 'only the rows of the member viewing') !== false, 'The approval screen names the own-rows grant');
	check(strpos(json_encode($words["Each member's own records"] ?? null), "Each member's own notifications: read") !== false,
		'in plain words: each member\'s own notifications', json_encode($words["Each member's own records"] ?? null));
	check(isset($words['Warning']), 'and warns when personal records and an outside website go together', json_encode($words['Warning'] ?? null));
	$before = SandboxConsent::grantsOf(SandboxManifest::fromArray(array('name' => 'hts_ow', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1), 'hts_ow'));
	check((SandboxConsent::additions(SandboxConsent::grantsOf($m), $before)['own_records'] ?? null) === array('Notification' => array('read')),
		'An update adding an own-rows grant asks for it anew (design 12)');

	section('The viewer header, from anyone but core');
	$key_admin = make_user('SbxOwnKeyAdmin', 10);
	$key = make_machine_key((int)$key_admin->key, 'sbx own test', 4);
	$host = preg_replace('~^https?://~', '', explode('/', (string)Globalvars::get_instance()->get_setting('webDir'))[0]);
	$ch = curl_init('https://' . $host . '/api/v1/Notifications');
	curl_setopt_array($ch, array(CURLOPT_RESOLVE => array($host . ':443:127.0.0.1'), CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('public-key: ' . $key['api_key']->get('apk_public_key'), 'secret-key: ' . $key['secret_key'],
		SandboxRelay::VIEWER_HEADER . ': ' . (int)$a->key)));
	$body = (string)curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	check($status === 403 && strpos($body, 'named only by core') !== false, 'Another key naming a viewer is refused', $status . ' ' . mb_substr($body, 0, 200));

	section('Unfenced, the same PHP command line');
	$p = sandbox_fixture_plugin($manifest, array('files' => $files));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	$scope = (new ApiKey((int)SandboxInstall::forPlugin($name)->get('sbi_apk_api_key_id'), TRUE))->scope();
	check(in_array('Notification:read:own', $scope, true) && !in_array('Notification:read', $scope, true), 'Its key holds the own-rows entry and no all-rows one', json_encode($scope));
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$checks($name, 'Unfenced', array($a, $b, $admin), $b_note);
	SandboxRunner::$command_override = null;

	section('An admin page beside every row of a personal model (design 11)');
	$q = sandbox_fixture_plugin(array('permissions' => array('records' => array('Order' => array('read'))),
		'admin_pages' => array(array('slug' => 'main', 'title' => 'Main', 'sections' => array(array('type' => 'text', 'text' => 'hello'))))));
	$pm->install($q['name'], false);
	sandbox_approve($q['name']);
	$pm->activate($q['name']);
	SandboxPlugins::reset();
	$low = make_user('SbxOwnLowAdmin', 5);
	$sign_in($low);
	$r = harness_call_logic('adm/logic/admin_sandbox_page_logic.php', 'admin_sandbox_page_logic', array('plugin' => $q['name'], 'page' => 'main'), 'GET');
	check(strpos((string)$r->error, 'may not read') !== false, 'A viewer below Order\'s read level is not shown its page', (string)$r->error);
	$sign_in($admin);
	$r = harness_call_logic('adm/logic/admin_sandbox_page_logic.php', 'admin_sandbox_page_logic', array('plugin' => $q['name'], 'page' => 'main'), 'GET');
	check(!$r->error, 'One who may read Orders is', (string)$r->error);

	section('In the fence');
	$fence = 'sbxownrows';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced own rows', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => $files, 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced own rows', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence, false);
			sandbox_approve($fence);
			$pm->activate($fence);
			SandboxPlugins::reset();
			$checks($fence, 'Fenced', array($a, $b, $admin), $b_note);
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
