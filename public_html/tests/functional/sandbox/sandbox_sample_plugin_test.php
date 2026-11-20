<?php
/** @joinery-test
 * name: sandbox_sample_plugin
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * The author kit's sample plugin, docs/examples/notes_sample
 * (specs/sandboxed_php_plugins.md WP7), does what docs/sandboxed_plugins.md
 * says it does, so the example an author copies is known to work.
 *
 * What is pinned here: the folder validates; installed and approved, its
 * admin page lists notes and adds one through a form (a POST, then a
 * redirect to itself); its member page shows the greeting setting and the
 * member's own unread notifications; its framed board draws its document
 * and the board's pin call pins a note; its signal adds a note and its
 * schedule unpins. Unfenced through the same PHP command line, and in the
 * fence where this box has jpx-notes_sample.
 *
 * Run: php tests/run.php db --filter=sandbox_sample_plugin
 *
 * @version 1.1 - reviewer2 M5: only a note's author may pin it
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(PathHelper::getIncludePath('tasks/SandboxScheduledRun.php'));
harness_boot();

$sample = PathHelper::getIncludePath('docs/examples/notes_sample');
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

/** The sample's src/ files, path => contents. */
function sbx_sample_files(string $src): array {
	$files = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$files[substr((string)$f, strlen($src) + 1)] = (string)file_get_contents((string)$f);
	}
	return $files;
}

$checks = function (string $label, User $admin, User $member) use ($sign_in) {
	$name = 'notes_sample';
	$class = SandboxManifest::classFor($name, 'Note');
	$multi = 'Multi' . $class;
	$prefix = SandboxManifest::prefixFor($name, 'Note');
	$admin_logic = 'adm/logic/admin_sandbox_page_logic.php';

	$sign_in($admin);
	$saved = array($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? null);
	$_GET = array('plugin' => $name, 'page' => 'notes');
	$_POST = array('text' => 'Bring snacks');
	$_SERVER['REQUEST_METHOD'] = 'POST';
	require_once(PathHelper::getIncludePath($admin_logic));
	try {
		$r = admin_sandbox_page_logic(array_merge($_GET, $_POST));
	} finally {
		list($_GET, $_POST, $method) = $saved;
		$_SERVER['REQUEST_METHOD'] = $method;
	}
	check(strpos((string)$r->redirect, 'page=notes&added=') !== false, $label . ': the admin form adds a note and comes back to the page', (string)($r->redirect ?? $r->error));
	$r = harness_call_logic($admin_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'notes'), 'GET');
	$html = (string)($r->data['drawn']['html'] ?? json_encode($r->data['drawn'] ?? $r->error));
	check(strpos($html, 'Bring snacks') !== false && strpos($html, '<table class="table">') !== false && strpos($html, 'name="text"') !== false,
		$label . ': the admin page lists it, with the form', $html);

	$sign_in($member);
	$r = harness_call_logic('logic/sandbox_page_logic.php', 'sandbox_page_logic', array('plugin' => $name, 'page' => 'mine'), 'GET');
	$html = (string)($r->data['drawn']['html'] ?? json_encode($r->data['drawn'] ?? $r->error));
	check(strpos($html, '<h2>Hello</h2>') !== false && strpos($html, 'You have 0 unread notifications') !== false,
		$label . ': the member page shows the greeting and the member\'s own unread count', $html);

	$page = SandboxPages::framedPage($name, 'member', 'board', SessionControl::get_instance());
	check(is_array($page), $label . ': the framed board is a page the member may open', is_string($page) ? $page : '');
	$result = SandboxHost::page($name, 'member', 'board', 'GET', array(), array(), $member);
	check(strpos((string)($result['output']['body'] ?? ''), 'Bring snacks') !== false && strpos((string)($result['output']['body'] ?? ''), "handler: 'pin'") !== false,
		$label . ': it draws its document with the notes and its script', json_encode($result['message']));
	$row = iterator_to_array(new $multi(array('text' => 'Bring snacks')), false)[0] ?? null;
	$pin = function () use ($name, $row) {
		return harness_call_logic('logic/sandbox_frame_call_logic.php', 'sandbox_frame_call_logic',
			array('plugin' => $name, 'kind' => 'member', 'page' => 'board', 'handler' => 'pin', 'data' => array('id' => (int)($row ? $row->key : 0))), 'POST');
	};
	$r = $pin();
	$pinned = $row ? (new $class((int)$row->key, TRUE))->get($prefix . '_pinned') : null;
	check(($r->data['output']['status'] ?? '') === 'error' && !$pinned, $label . ': a member cannot pin a note someone else wrote', json_encode($r->data ?: $r->error));
	$sign_in($admin);
	$r = $pin();
	$pinned = $row ? (new $class((int)$row->key, TRUE))->get($prefix . '_pinned') : null;
	check(($r->data['ok'] ?? false) && $pinned, $label . ': its author pins it from the board', json_encode($r->data ?: $r->error));

	$sign_in($admin);
	$result = SandboxHost::signal($name, 'account.signup', array('user_id' => 1), 'msg_' . bin2hex(random_bytes(4)), gmdate('Y-m-d\TH:i:s\Z'));
	$news = iterator_to_array(new $multi(array('text' => 'Someone new signed up.')), false);
	check($result['status'] === SandboxLog::STATUS_OK && count($news) >= 1, $label . ': its signal adds a note', json_encode($result['message']));
	$tasks = iterator_to_array(new MultiScheduledTask(array('plugin_name' => $name)), false);
	$run = $tasks ? (new SandboxScheduledRun())->run($tasks[0]->get_task_config()) : array('status' => 'none');
	$still = count(iterator_to_array(new $multi(array('pinned' => true, 'deleted' => false)), false));
	check($run['status'] === 'success' && $still === 0, $label . ': its schedule unpins every note', json_encode($run));
};

try {
	$admin = make_user('SbxSampleAdmin', 10);
	$member = make_user('SbxSampleMember', 0);

	section('The folder');
	check(SandboxPlugins::isSandboxedDir($sample), 'docs/examples/notes_sample is a sandboxed plugin folder');
	$manifest = SandboxManifest::fromFile($sample . '/manifest.json', 'notes_sample');
	check(SandboxCompatibility::problems($manifest) === array(), 'Its manifest validates and this site has what it asks for',
		implode('; ', SandboxCompatibility::problems($manifest)));

	$why = SandboxRunner::unavailable('hts_none');
	$fenced = !($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER)
		&& function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor('notes_sample')) !== false;
	sandbox_fixture_plugin(json_decode((string)file_get_contents($sample . '/manifest.json'), true),
		array('name' => 'notes_sample', 'files' => sbx_sample_files($sample . '/src'), 'keep_folder' => !$fenced));
	$pm = PluginManager::getInstance();
	$sign_in($admin);
	$pm->install('notes_sample', false);
	sandbox_approve('notes_sample');
	$pm->activate('notes_sample');
	SandboxPlugins::reset();

	if ($fenced) {
		section('In the fence');
		$checks('Fenced', $admin, $member);
	} else {
		section('Unfenced, the same PHP command line');
		harness_skip('The fenced run', 'jpx-notes_sample is not made yet (or this box has no launcher 2.1); the root step makes it within a minute of the folder, which is left in place');
		SandboxRunner::$command_override = function (array $envelope) {
			$cmd = SandboxRunner::command($envelope);
			return array_slice($cmd, array_search('--', $cmd, true) + 1);
		};
		harness_defer(function () { SandboxRunner::$command_override = null; });
		$checks('Unfenced', $admin, $member);
		SandboxRunner::$command_override = null;
	}
	$sign_in($admin);
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
