<?php
/** @joinery-test
 * name: root_request
 * tier: safe
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

// The kind that must never exist.
//
// This queue and uploads/staging are both www-data-writable — they have to be —
// so a request file proves only that something running as the web user wrote
// it. A kind that installed a staged directory would therefore turn the spec's
// own premise (one bug that lets an attacker write one file) into root code
// execution: stage a plugin.json and a migrations/migrations.php, queue the
// request, and root moves it into plugins/ and includes the migration as root.
foreach (array('install_plugin_package', 'install_theme_package') as $forbidden) {
	check(!in_array($forbidden, RootRequest::KINDS, true),
		"'$forbidden' is not a kind",
		'installing an uploaded package is a shell command, not something the web user can ask for');
	check(strpos($dispatcher, "case '" . $forbidden . "'") === false,
		"and the dispatcher has no handler for it");
	check(strpos($runner, $forbidden) === false,
		"and the runner's kind list does not carry it",
		'a kind the runner accepts and the web side cannot name is a hole with no door');
	$threw = false;
	try { RootRequest::submit($forbidden, array('staged' => '/tmp/x')); }
	catch (InvalidArgumentException $e) { $threw = true; }
	check($threw, "and submitting it is refused");
}

// Every kind that remains either fetches from the configured upgrade source or
// writes something that is not executed. If that stops being true, this is the
// check that should stop it.
check(count(RootRequest::KINDS) === 6, 'there are six kinds', implode(', ', RootRequest::KINDS));

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
check(strpos($runner_text, 'flock -n') !== false,
	'the queue is held under a kernel lock while it is worked');

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
