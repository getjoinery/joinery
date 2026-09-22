<?php
/** @joinery-test
 * name: root_request
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Root requests: the web side asks, root decides what the asking means.
 *
 * The PHP pool cannot write the code tree (specs/read_only_tree.md), so the
 * operator actions that used to write it from inside a web request are queued
 * as small JSON files and carried out by the host converger as root.
 *
 * The security of the whole arrangement rests on one property: what crosses is
 * a NAME from a closed list, never a command and never a path root will trust.
 * These checks hold that property, and the runner's half of the loop is
 * executed against a scratch site rather than read.
 *
 * Run: php tests/unit/root_request_test.php
 *
 * @version 1.3 - the runner's lock pin reads the bounded wait (flock -w), the shape the lock has since the runner's 2.16
 * @version 1.2 - remove_plugin is a kind; root's checks before it deletes a plugin directory (specs/post_release_fleet_defects.md B1)
 * @version 1.1 - install_package is a kind, because root verifies before it moves (specs/package_signing.md WP3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('A kind is a name from a closed list');

check(is_array(RootRequest::KINDS) && RootRequest::KINDS !== array(),
	'the kinds are declared as a constant');

$threw = false;
try {
	RootRequest::submit('rm_minus_rf');
} catch (InvalidArgumentException $e) {
	$threw = strpos($e->getMessage(), 'unknown kind') !== false;
}
check($threw, 'an unknown kind is refused before anything is written',
	'the queue is a directory the web user writes; a kind it invented must not run');

// Both halves carry the same list. A kind the web side can submit and the
// runner does not know is a request that sits in the queue forever; a kind the
// runner will run and the web side cannot name is a hole with no door.
$runner = (string)file_get_contents(dirname(PathHelper::getRootDir())
	. '/maintenance_scripts/install_tools/_plugin_installers_start.sh');
foreach (RootRequest::KINDS as $kind) {
	check(strpos($runner, $kind) !== false, "the runner knows the kind '$kind'");
}
$dispatcher = (string)file_get_contents(PathHelper::getIncludePath('utils/root_request.php'));
foreach (RootRequest::KINDS as $kind) {
	check(strpos($dispatcher, "case '" . $kind . "'") !== false,
		"the dispatcher handles the kind '$kind'");
}

// The kind that installs a staged upload, and why it is safe to have.
//
// This queue and uploads/staging are both www-data-writable — they have to be —
// so a request file proves only that something running as the web user wrote
// it. A kind that moved a staged directory into the tree on the request's say-so
// would turn the spec's own premise (one bug that lets an attacker write one
// file) into root code execution. So root does not trust the request: it
// verifies the package against the release key before it moves anything, and
// the only way past a refusal is the owner's acknowledgement, checked by root
// against the second-factor marker (specs/package_signing.md WP3).
check(RootRequest::PACKAGE_KIND === 'install_package' && in_array('install_package', RootRequest::KINDS, true),
	"'install_package' is a kind");
$package_case = substr($dispatcher, strpos($dispatcher, "case 'install_package'"));
$package_case = substr($package_case, 0, strpos($package_case, "\tcase '"));
check(strpos($package_case, 'PackageAcknowledgement::check($ack, $requested_by)') !== false,
	'the dispatcher checks an acknowledgement against the marker before it passes --acknowledged');
check(strpos($package_case, "--staged=' . escapeshellarg(\$staged_path)") !== false
	&& strpos($package_case, "\$site_root . '/uploads/staging/' . \$staged") !== false,
	'and hands the installer a path under uploads/staging built from the request, never a path the request named');
check(strpos($package_case, "preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_-]*$~', \$staged)") !== false,
	'the staged directory is <staging id>/<name> and nothing else');
check(strpos($package_case, "\$args['acknowledged']") === false && strpos($package_case, "\$args['approved") === false,
	'the request cannot claim the acknowledgement was checked; only the dispatcher says so');
$installer = (string)file_get_contents(PathHelper::getIncludePath('utils/install_extension.php'));
$verify_at = strpos($installer, 'install_extension_verify($dir, $tree_rel . $staged_name)');
$move_at   = strpos($installer, 'install_extension_copy_tree($dir, $target)');
check($verify_at !== false && $move_at !== false && $verify_at < $move_at,
	'the installer verifies the staged copy before it moves it');
check(strpos($installer, "install_extension_register_as_web_user(\$type, \$name, \$staged !== '')") !== false
	&& strpos($installer, "runuser") !== false,
	'an acknowledged unsigned package runs its database half as the web user, never root');
check(strpos($installer, "refusing to run them as root") !== false,
	'and refuses rather than falling back to root when it cannot switch accounts');
check(strpos($installer, "'unsigned_package_installed'") !== false
	&& strpos($installer, 'EmailSender::quickSend($to, $subject, $body)') !== false,
	'every superadmin is emailed and the event log has a row');

// Every kind either fetches from the configured upgrade source and verifies,
// verifies a staged upload, or writes something that is not executed. If that
// stops being true, this is the check that should stop it.
check(count(RootRequest::KINDS) === 9, 'there are nine kinds', implode(', ', RootRequest::KINDS));

section('remove_plugin: root deletes a plugin directory only when the row and the manifest agree it may');

// The file half of a plugin uninstall (specs/post_release_fleet_defects.md B1).
// The queue is www-data-writable, so the request proves nothing; every check
// below is what root does before it deletes, run here against a scratch
// plugins directory and rows built by hand.
check(in_array('remove_plugin', RootRequest::KINDS, true), "'remove_plugin' is a kind");
$remove_case = substr($dispatcher, strpos($dispatcher, "case 'remove_plugin'"));
$remove_case = substr($remove_case, 0, strpos($remove_case, "\n}\n"));
$refusal_at = strpos($remove_case, 'PluginRemoval::refusal($name, $plugins_dir, $row)');
$remove_at  = strpos($remove_case, 'PluginRemoval::remove($name, $plugins_dir)');
check($refusal_at !== false && $remove_at !== false && $refusal_at < $remove_at,
	'the dispatcher asks PluginRemoval::refusal() before PluginRemoval::remove()');
check(strpos($remove_case, "PathHelper::getAbsolutePath('plugins')") !== false,
	'and removes under the real plugins directory, never a path the request named');
check(strpos($remove_case, 'Plugin::get_by_plugin_name($name)') !== false,
	'the row is read from the database, not taken from the request');

$rp = harness_scratch_dir('remove_plugin');
@mkdir($rp . '/plugins/goodplug', 0700, true);
file_put_contents($rp . '/plugins/goodplug/plugin.json', json_encode(array('name' => 'Good', 'version' => '1.0')));
@mkdir($rp . '/plugins/sysplug', 0700, true);
file_put_contents($rp . '/plugins/sysplug/plugin.json', json_encode(array('name' => 'Sys', 'is_system' => true)));
@mkdir($rp . '/elsewhere/outside', 0700, true);
file_put_contents($rp . '/elsewhere/outside/plugin.json', '{}');
symlink($rp . '/elsewhere/outside', $rp . '/plugins/linked');
$plugins_dir = $rp . '/plugins';

$row = function (string $status, int $active = 0, bool $system = false) {
	$p = new Plugin(null);
	$p->set('plg_name', 'x');
	$p->set('plg_status', $status);
	$p->set('plg_active', $active);
	$p->set('plg_is_system', $system);
	return $p;
};
$uninstalled = $row('uninstalled');

check(PluginRemoval::refusal('../etc', $plugins_dir, $uninstalled) !== '',
	'a name with a path separator is refused before anything is looked at');
check(PluginRemoval::refusal('', $plugins_dir, $uninstalled) !== '', 'an empty name is refused');
check(PluginRemoval::refusal('-rf', $plugins_dir, $uninstalled) !== '', 'a name that does not start with a letter is refused');
check(PluginRemoval::refusal('nosuch', $plugins_dir, $uninstalled) !== '', 'a name with no directory is refused');
check(strpos(PluginRemoval::refusal('linked', $plugins_dir, $uninstalled), 'symlink') !== false,
	'a symlink under plugins/ is refused, so nothing outside plugins/ is ever removed');
check(strpos(PluginRemoval::refusal('sysplug', $plugins_dir, $uninstalled), 'is_system') !== false,
	'a manifest that says is_system is refused whatever the row says');
check(strpos(PluginRemoval::refusal('goodplug', $plugins_dir, null), 'no database row') !== false,
	'no row: refused');
check(strpos(PluginRemoval::refusal('goodplug', $plugins_dir, $row('inactive')), "'inactive', not uninstalled") !== false,
	'an inactive row is refused: only the uninstalled state means the data is gone');
check(PluginRemoval::refusal('goodplug', $plugins_dir, $row('active', 1)) !== '', 'an active row is refused');
check(PluginRemoval::refusal('goodplug', $plugins_dir, $row('uninstalled', 1)) !== '', 'an uninstalled row still flagged active is refused');
check(PluginRemoval::refusal('goodplug', $plugins_dir, $row('uninstalled', 0, true)) !== '', 'a row marked is_system is refused');
check(PluginRemoval::refusal('goodplug', $plugins_dir, $uninstalled) === '',
	'a real directory directly under plugins/, a plain manifest and an uninstalled row: allowed');
check(is_dir($rp . '/plugins/goodplug') && is_dir($rp . '/plugins/sysplug') && is_link($rp . '/plugins/linked'),
	'every refusal left the directories exactly as they were');

// Removal never follows a symlink inside the plugin.
@mkdir($rp . '/plugins/goodplug/sub', 0700, true);
file_put_contents($rp . '/plugins/goodplug/sub/a.php', '<?php');
file_put_contents($rp . '/elsewhere/outside/keep.txt', 'keep');
symlink($rp . '/elsewhere/outside', $rp . '/plugins/goodplug/sub/link');
$removed = PluginRemoval::remove('goodplug', $plugins_dir);
check(!file_exists($rp . '/plugins/goodplug'), 'remove() deletes the plugin directory');
check($removed >= 4, 'and counts what it removed', (string)$removed);
check(is_file($rp . '/elsewhere/outside/keep.txt'), 'a symlink inside the plugin is unlinked, its target untouched');

@unlink($rp . '/plugins/linked');
@unlink($rp . '/elsewhere/outside/keep.txt');
@unlink($rp . '/elsewhere/outside/plugin.json');
@rmdir($rp . '/elsewhere/outside'); @rmdir($rp . '/elsewhere');
@unlink($rp . '/plugins/sysplug/plugin.json'); @rmdir($rp . '/plugins/sysplug');
@rmdir($rp . '/plugins'); @rmdir($rp);

section('A request names the document, never the directory');

// save_doc used to carry docs_dir. The queue is www-data-writable, so a request
// that names a directory is a request that chooses where root writes: point it
// at plugins/<something>/docs and a .md lands in the tree wherever the key
// resolves. Root derives the directory from the key instead, so there is
// nothing in the request left to point anywhere.
$save_doc_case = substr($dispatcher, strpos($dispatcher, "case 'save_doc'"));
$save_doc_case = substr($save_doc_case, 0, strpos($save_doc_case, "\tcase '"));

check(strpos($save_doc_case, "args['docs_dir']") === false,
	'the dispatcher reads no docs_dir out of the request',
	'a request carrying one is ignored: the key is the only thing it gets to say');
check(strpos($dispatcher, "docs_dir'") === false || strpos($dispatcher, "\$args['docs_dir']") === false,
	'and docs_dir is not read anywhere else in it');
check(strpos($save_doc_case, "strpos(\$key, 'plugin/')") !== false
	&& strpos($save_doc_case, "'/docs'") !== false,
	'root derives the directory from the key',
	"a plugin/<name>/ key is that plugin's docs/, anything else is core docs/");

// The web side stopped sending it, so a request in flight from an older release
// is the only one that can carry it - and it is ignored when it arrives.
$help_edit = (string)file_get_contents(PathHelper::getIncludePath('adm/logic/admin_help_edit_logic.php'));
$submit = substr($help_edit, strpos($help_edit, "RootRequest::submit('save_doc'"));
$submit = substr($submit, 0, strpos($submit, '));'));
check(strpos($submit, 'docs_dir') === false,
	'and the docs editor no longer sends it');

section('Submitting writes one request and nothing else');

$id = RootRequest::submit('write_agent_files', array('note' => 'test'), 4500);
check(preg_match('/^\d{9,12}-[0-9a-f]{8}$/', $id) === 1, 'the id carries a time and a nonce', $id);

$path = PathHelper::getSiteRoot() . '/' . RootRequest::QUEUE_DIR . '/' . $id . '.json';
check(is_file($path), 'the request file is in the queue');
$body = json_decode((string)file_get_contents($path), true);
check(is_array($body) && $body['kind'] === 'write_agent_files', 'it names the kind');
check(($body['args']['note'] ?? '') === 'test', 'and carries its arguments verbatim');
check((int)($body['requested_by'] ?? 0) === 4500, 'and who asked');

$status = RootRequest::status($id);
check($status['state'] === 'queued', 'status reads queued');
check($status['kind'] === 'write_agent_files', 'and reports the kind');
check(RootRequest::transcript($id) === '', 'there is no transcript until it runs');

$pending = RootRequest::pending();
$ids = array_column($pending, 'id');
check(in_array($id, $ids, true), 'it shows up as pending');
check(RootRequest::oldest_pending_age() !== null, 'and the queue reports an age');

section('Arguments that cannot be encoded are refused, not queued empty');

// json_encode returns false on invalid UTF-8, which a save_doc body can carry.
// Writing `false` queued an empty file that root refused with exit 2 and no
// transcript — a save that failed for a reason nobody could see.
$threw = false;
try {
	RootRequest::submit('save_doc', array('content' => "\xB1\x31 not utf-8"));
} catch (InvalidArgumentException $e) {
	$threw = strpos($e->getMessage(), 'could not be encoded') !== false;
}
check($threw, 'a request whose arguments will not encode is refused at submit',
	'queueing it wrote an empty file and failed silently later');

section('An id from a request parameter cannot become a path');

// status() and transcript() are reachable from the polling endpoint, and both
// build a filesystem path from what they are handed.
foreach (array('../../../etc/passwd', 'a/../b', '../secrets', '1789000000-ZZZZZZZZ',
               '1789000000-abc', '', '.', '*') as $hostile) {
	check(RootRequest::status($hostile)['state'] === 'unknown',
		'status refuses ' . var_export($hostile, true));
	check(RootRequest::transcript($hostile) === '',
		'transcript refuses ' . var_export($hostile, true));
}

@unlink($path);

section('The runner carries requests out, and refuses what it does not know');

// Executed, not read: a scratch site with its own queue, the runner's root gate
// stripped so the harness can drive it, and a stand-in for the dispatcher so
// nothing real is installed.
$site = sys_get_temp_dir() . '/joinery_rq_' . bin2hex(random_bytes(4));
foreach (array('/public_html/plugins', '/public_html/utils', '/config',
               '/cache/root_requests', '/logs', '/maintenance_scripts/install_tools') as $sub) {
	@mkdir($site . $sub, 0700, true);
}
file_put_contents($site . '/config/Globalvars_site.php', "<?php\n");
$tools = dirname(PathHelper::getRootDir()) . '/maintenance_scripts/install_tools';
foreach (array('_config_secrets.sh', '_tree_trust.sh') as $helper) {
	copy($tools . '/' . $helper, $site . '/maintenance_scripts/install_tools/' . $helper);
}

// The stand-in records what it was told and fails one kind, so both outcomes
// are exercised.
file_put_contents($site . '/public_html/utils/root_request.php',
	"<?php\necho 'kind=' . (\$argv[1] ?? '') . ' ' . (\$argv[2] ?? '') . \"\\n\";\n"
	. "exit((\$argv[1] ?? '') === 'save_doc' ? 3 : 0);\n");

$runner_path = $site . '/maintenance_scripts/install_tools/_plugin_installers_start.sh';
file_put_contents($runner_path,
	str_replace('[[ "$(id -u)" == "0" ]] || return 0', ':', $runner));

$now = time();
$good   = $now . '-aaaaaaaa';
$failing = $now . '-bbbbbbbb';
$bogus  = $now . '-cccccccc';
file_put_contents($site . '/cache/root_requests/' . $good . '.json',
	json_encode(array('kind' => 'write_agent_files', 'args' => array(), 'requested_at' => $now)));
file_put_contents($site . '/cache/root_requests/' . $failing . '.json',
	json_encode(array('kind' => 'save_doc', 'args' => array(), 'requested_at' => $now)));
file_put_contents($site . '/cache/root_requests/' . $bogus . '.json',
	json_encode(array('kind' => 'rm -rf /', 'args' => array(), 'requested_at' => $now)));
file_put_contents($site . '/cache/root_requests/not-an-id.json',
	json_encode(array('kind' => 'upgrade')));

$out = (string)shell_exec('bash ' . escapeshellarg($runner_path)
	. ' --site-root=' . escapeshellarg($site) . ' 2>&1');

$q = $site . '/cache/root_requests';
check(is_file($q . '/done/' . $good . '.json'), 'a request that succeeded is in done/', $out);
check(trim((string)@file_get_contents($q . '/done/' . $good . '.exit')) === '0',
	'with its exit code beside it');
check(is_file($q . '/failed/' . $failing . '.json'), 'one that failed is in failed/');
check(trim((string)@file_get_contents($q . '/failed/' . $failing . '.exit')) === '3',
	'with the code it failed on, so the page can say what happened');
check(is_file($q . '/failed/' . $bogus . '.json'),
	'a kind the runner does not know is refused, not run',
	'this is the check that keeps a command out of the queue');
check(strpos($out, 'names no known kind') !== false, 'and says so');
check(is_file($q . '/not-an-id.json'),
	'a file whose name is not a request id is left alone, never executed');

$log = (string)@file_get_contents($site . '/logs/root_requests/' . $good . '.log');
check(strpos($log, 'kind=write_agent_files') !== false,
	'the transcript records the run', $log);
check(strpos($log, '--request=' . $good) !== false,
	'and the dispatcher is given the id, not the arguments',
	'arguments stay in the file so nothing a web request wrote becomes a shell word');

section('A run killed mid-request does not retry it silently');

@mkdir($q . '/running', 0700, true);
$stale = ($now - 7200) . '-dddddddd';
file_put_contents($q . '/running/' . $stale . '.json',
	json_encode(array('kind' => 'upgrade', 'args' => array())));
touch($q . '/running/' . $stale . '.json', $now - 7200);
$out2 = (string)shell_exec('bash ' . escapeshellarg($runner_path)
	. ' --site-root=' . escapeshellarg($site) . ' 2>&1');
check(is_file($q . '/failed/' . $stale . '.json'),
	'a request left running by a killed run is set aside, not repeated',
	'repeating it blindly could run half an install twice');
check(strpos($out2, 'abandoned') !== false, 'and is reported as abandoned');
$exit_raw = trim((string)@file_get_contents($q . '/failed/' . $stale . '.exit'));
check($exit_raw === (string)RootRequest::ABANDONED_EXIT,
	'the recorded exit code is a number',
	'the word that used to be written here cast to 0, and the page said "Failed, exit 0": ' . $exit_raw);

// And a second run must not declare a LIVE request abandoned. mv preserves the
// submission mtime, so ageing on that made a request that waited an hour before
// starting look abandoned the moment it began.
$fresh = (time()) . '-eeeeeeee';
file_put_contents($q . '/running/' . $fresh . '.json',
	json_encode(array('kind' => 'upgrade', 'args' => array())));
touch($q . '/running/' . $fresh . '.json', time() - 7200);   // submitted two hours ago
touch($q . '/running/' . $fresh . '.json');                   // started just now
shell_exec('bash ' . escapeshellarg($runner_path) . ' --site-root=' . escapeshellarg($site) . ' 2>&1');
check(is_file($q . '/running/' . $fresh . '.json'),
	'a request that started recently is left alone however long it waited to start',
	'the sweep ages by when a request STARTED, not when it was submitted');
@unlink($q . '/running/' . $fresh . '.json');

// One runner at a time. The cron form has no oneshot to lean on, and an upgrade
// takes minutes: two ticks inside one upgrade used to have the second declare
// the first's live request abandoned.
$runner_text = (string)file_get_contents($runner_path);
check(strpos($runner_text, 'flock -w "${LOCK_WAIT_SECONDS}" 9') !== false,
	'the queue is held under a kernel lock while it is worked, and a second runner waits for it');

// Clean up the scratch site.
shell_exec('rm -rf ' . escapeshellarg($site));

section('A page knows whether anything will act on its request');

$state = RootRequest::actorState();
check(in_array($state, array('present', 'stale', 'absent'), true),
	'the actor state is one of present, stale, absent', $state);
check($state === 'present' ? RootRequest::actorWarning() === ''
	: RootRequest::actorWarning() !== '',
	'a machine that cannot promise to carry a request out says so before the button');

harness_finish();
