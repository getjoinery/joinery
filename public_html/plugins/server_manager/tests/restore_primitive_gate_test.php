<?php
/** @joinery-test
 * name: restore_primitive_gate
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What a restore job carries, now that restore travels the agent channel.
 *
 * The gate opened (specs/restore_dispatch_approval_mechanism.md): a destructive
 * primitive is dispatchable to a node whose agent can ask its own operator to
 * approve it. That makes everything below LIVE rather than latent — the
 * envelope this plane composes is the one a node acts on, so what it can and
 * cannot say is the whole security surface this file exists to pin.
 *
 * Three properties, and none of them is about permission — this plane grants
 * none, and cannot:
 *
 *   * A restore names a BACKUP, never a path. Under SSH this plane composed an
 *     absolute path and a root process ran it, which is read-and-overwrite-
 *     anything wearing a restore's clothes.
 *   * Nothing crossing this wire can OPEN anything: no key, no bucket, no
 *     credential. The node decrypts with its own key, on its own disk, or not
 *     at all.
 *   * The plane's validation MIRRORS the agent's rather than approximating it.
 *     A stricter rule refuses jobs the node would have run, from a message that
 *     blames the wrong side; a looser one sends a job to be refused on the wire,
 *     where the refusal reads as a node problem.
 *
 * The gate itself, and the wire format that makes an approval unrelayable, are
 * pinned next door in restore_dispatch_test.php.
 *
 * Run: php plugins/server_manager/tests/restore_primitive_gate_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

const RESTORE_OPS = ['restore_database', 'restore_project', 'restore_chain'];

/**
 * A node with every reason to be routed at: paired, current, and reporting the
 * restore primitives in its own vocabulary. What stands between this node and a
 * replaced database is not anything on this plane — it is an operator at that
 * machine's own site, opening a challenge with its backup recovery key.
 */
function rpg_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'Restore Gate Node');
	$node->set('mgn_slug', 'restore-gate');
	$node->set('mgn_host', '192.0.2.10');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_key_path', '/home/user1/.ssh/id_ed25519_claude');
	$node->set('mgn_web_root', '/var/www/html/gatesite/public_html');
	$node->set('mgn_agent_public_key', base64_encode(str_repeat("\x01", 32)));
	$node->set('mgn_agent_version', '1.13.0');
	$node->set('mgn_agent_primitives',
		'apply_update,backup_run,check_status,restore_chain,restore_database,restore_project');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	return $node;
}

section('Restore travels the agent channel, to a node that can ask its own operator');

check(JobCommandBuilder::node_can_dispatch_destructive(rpg_node()) === true,
	'node_can_dispatch_destructive() is true for a paired node',
	'this is a ROUTING decision and never permission — the node still refuses the job unless '
	. 'somebody at its own site opens a challenge sealed to its own backup recovery key');

check(JobCommandBuilder::DESTRUCTIVE_PRIMITIVES === RESTORE_OPS,
	'the destructive set names exactly the three restore operations',
	'got: ' . implode(',', JobCommandBuilder::DESTRUCTIVE_PRIMITIVES));

$paired = rpg_node();
foreach (RESTORE_OPS as $op) {
	check(JobCommandBuilder::has_primitive($paired, $op) === true,
		"has_primitive() routes {$op} to a paired node that reports it");

	check(in_array('primitive', JobCommandBuilder::transports_for($op), true),
		"transports_for() lists a primitive transport for {$op}");
}

// A paired node gets a primitive envelope, not steps. This is the check that
// would catch the transport silently reverting to a route the agent refuses.
$db_built = JobCommandBuilder::build_restore_database($paired,
	['filename' => 'joinerytest_20260828.sql.gz.enc', 'local_path' => '/backups/joinerytest_20260828.sql.gz.enc']);
check(($db_built['primitive'] ?? null) === 'restore_database' && !isset($db_built[0]),
	'build_restore_database() composes a primitive envelope for a paired node',
	'it returned steps — the agent refuses ssh and scp by name, so those would die at step one');

$chain_built = JobCommandBuilder::build_restore_chain($paired,
	['chain_id' => 'chain-20260807_231507', 'domain' => 'gate.example.com']);
check(($chain_built['primitive'] ?? null) === 'restore_chain' && !isset($chain_built[0]),
	'build_restore_chain() composes a primitive envelope for a paired node');

section('The version floor is live, and is not the same thing as the gate');

foreach (RESTORE_OPS as $op) {
	check((JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION[$op] ?? null) === '1.13.0',
		"{$op} requires the agent release that can ask for approval",
		'an earlier agent ships the restore vocabulary and refuses every job in it at a compiled '
		. 'ceiling, so routing to it trades a transport for a guaranteed refusal — discovered '
		. 'during a restore');
}

// Proven, not assumed: an agent below the floor is refused, and so is an
// unpaired node, and they are refused for different reasons.
$ancient = rpg_node(['mgn_agent_version' => '1.9.0', 'mgn_agent_primitives' => '']);
check(JobCommandBuilder::has_primitive($ancient, 'restore_database') === false,
	'an agent below the floor is not routed at');

$unpaired = rpg_node(['mgn_agent_public_key' => '']);
check(JobCommandBuilder::node_can_dispatch_destructive($unpaired) === false,
	'an unpaired node cannot be sent a restore — there is nobody on it to do the asking');

section('A restore job may never be given up on while the node is still running it');

$budgets = ManagementJob::PRIMITIVE_CLAIM_BUDGETS;
// The SSH path's own step timeouts are the floor these were sized from: 3600s
// for a database or project restore, 7200s for a chain's restore step. A plane
// budget under the node's deadline requeues a restore that is still running,
// and a second concurrent restore destroys the thing the first was recovering.
// Each budget now also has to cover the APPROVAL WINDOW: the node claims the
// job and holds it open while a person at that machine answers the challenge.
// A budget sized for the restore alone would requeue a job during the approval
// the restore requires.
$floors = ['restore_database' => 3600, 'restore_project' => 3600, 'restore_chain' => 7200];
foreach ($floors as $op => $floor) {
	check(isset($budgets[$op]) && $budgets[$op] > $floor,
		"the plane waits longer than the SSH path allowed for {$op}",
		'ManagementJob::PRIMITIVE_CLAIM_BUDGETS[' . $op . '] is '
		. ($budgets[$op] ?? 'unset') . ', which is not above the ' . $floor . 's the SSH step allowed');
}

section('A restore names a backup — it can never name a path');

// The property the primitive transport exists to gain. Under SSH this plane
// composed an absolute path and the node ran it, which is read-and-overwrite-
// anything wearing a restore's clothes.
$path_like = [
	'/backups/site.sql.gz'        => 'an absolute path',
	'../../etc/passwd'            => 'a climb out of the backup directory',
	'sub/dir/site.sql.gz'         => 'a relative path',
	'..'                          => 'the parent directory itself',
	'backups\\site.sql.gz'        => 'a windows-style separator',
	''                            => 'nothing at all',
	'.hidden.sql.gz'              => 'a hidden file',
	'-rf.sql.gz'                  => 'a name that reads as a flag',
	'site backup.sql.gz'          => 'a name with a space in it',
];
$path_like[str_repeat('a', 250) . '.sql.gz'] = 'a name longer than the node accepts';
foreach ($path_like as $bad => $why) {
	$refused = false;
	try {
		JobCommandBuilder::build_restore_database_primitive($paired, ['filename' => $bad]);
	} catch (Exception $e) {
		$refused = true;
	}
	check($refused, "a restore refuses {$why}",
		"'{$bad}' composed a job instead of being refused — the node would resolve whatever that "
		. 'basename happens to be in its own backup directory, which is not the file the caller named');
}

section('The envelope carries a name, a target, and nothing that could open anything');

$db = JobCommandBuilder::build_restore_database_primitive($paired,
	['filename' => 'joinerytest_20260828.sql.gz.enc', 'local_path' => '/backups/whatever.sql.gz']);
check(($db['primitive'] ?? null) === 'restore_database' && is_array($db['params'] ?? null),
	'restore_database composes a primitive envelope');
check(($db['params']['file'] ?? null) === 'joinerytest_20260828.sql.gz.enc',
	'it carries the artifact NAME',
	'got: ' . json_encode($db['params'] ?? null));
check(($db['params']['profile'] ?? null) === 'manager',
	'restore_database says whose backup directory to look in',
	'left absent the node resolves the name in the backup base itself, while this plane\'s own '
	. 'backups live in manager/ beneath it — the same default upload_backup and delete_backup send');
check(!isset($db['params']['local_path']) && !isset($db['params']['cloud_path']),
	'the local and cloud paths the caller passed do not cross',
	'a parameter the node ignores is a lie the sender believes; a path it does not ignore is worse');

$proj = JobCommandBuilder::build_restore_project_primitive($paired,
	['filename' => 'gatesite_20260828.tar.gz', 'domain' => 'attacker.example.com']);
check(($proj['params']['project_name'] ?? null) === 'gatesite',
	'restore_project names the project from the node\'s own recorded web root',
	'got: ' . json_encode($proj['params'] ?? null));
check(($proj['params']['force'] ?? null) === true,
	'restore_project is always non-interactive',
	'a job has no terminal to answer a prompt on: an unforced restore would hang until its deadline');
check(($proj['params']['profile'] ?? null) === 'manager',
	'restore_project says whose backup directory to look in',
	'the agent requires it: the two profiles keep separate directories and an archive of the same '
	. 'name exists in both, so a guess eventually restores the management node\'s backup over a site');

$chain = JobCommandBuilder::build_restore_chain_primitive($paired,
	['chain_id' => 'chain-20260807_231507', 'seq' => '3', 'skip_database' => true,
	 'domain' => 'attacker.example.com']);

// The agent refuses an undeclared parameter, so a key the plane sends "just in
// case" is not redundant — it is a refusal. restore_chain resolves its artifacts
// inside the node's own store by chain id, and declares no profile.
//
// (This check used to read $chain before it was assigned, so it passed on an
// undefined value and would have passed whatever the builder sent.)
check(!isset($chain['params']['profile']),
	'restore_chain sends no profile, which the agent does not declare',
	'got: ' . json_encode($chain['params'] ?? null));
check(($chain['params']['chain_id'] ?? null) === 'chain-20260807_231507'
		&& ($chain['params']['project'] ?? null) === 'gatesite'
		&& ($chain['params']['seq'] ?? null) === 3
		&& ($chain['params']['skip_database'] ?? null) === true,
	'restore_chain names the chain, the project, the run and the skip',
	'got: ' . json_encode($chain['params'] ?? null));

$chain_bare = JobCommandBuilder::build_restore_chain_primitive($paired,
	['chain_id' => 'chain-20260807_231507']);
check(!isset($chain_bare['params']['seq']) && !isset($chain_bare['params']['skip_database']),
	'an unasked-for run number and skip are absent rather than defaulted',
	'"restore the whole chain" is what an absent seq means, and it means it more clearly by being absent');

// The plane's validation mirrors the agent's rather than approximating it. A
// rule that is merely stricter would refuse jobs the node would have run, from
// a message that blames the wrong side; a looser one sends a job to be refused
// on the wire, where the refusal reads as a node problem.
$out_of_range = false;
try {
	JobCommandBuilder::build_restore_chain_primitive($paired,
		['chain_id' => 'chain-20260807_231507', 'seq' => '100001']);
} catch (Exception $e) { $out_of_range = true; }
check($out_of_range, 'a run number past the agent\'s own ceiling is refused here',
	'the agent bounds seq at 0..100000; a job outside that travels to a node only to be refused');

$sited = JobCommandBuilder::build_restore_database_primitive($paired,
	['filename' => 'site.sql.gz', 'profile' => 'site']);
check(($sited['params']['profile'] ?? null) === 'site',
	'an explicitly named profile is carried, not overridden by the default');

$bad_chain = false;
try { JobCommandBuilder::build_restore_chain_primitive($paired, ['chain_id' => '../../etc']); }
catch (Exception $e) { $bad_chain = true; }
check($bad_chain, 'a chain id that is not a chain id is refused',
	'the node resolves the chain inside its own store, and the plane must not be able to name a directory');

// The one rule that spans all three: nothing crossing this wire can open
// anything. The node decrypts with its own key, on its own disk, or not at all.
$forbidden = ['key', 'key_file', 'keyfile', 'key_path', 'credentials', 'credentials_b64',
	'recovery_key', 'private', 'bucket', 'path_prefix', 'local_path', 'cloud_path', 'domain'];
foreach (['restore_database' => $db, 'restore_project' => $proj, 'restore_chain' => $chain] as $name => $built) {
	$leaked = array_intersect($forbidden, array_keys($built['params']));
	check(empty($leaked), "{$name} carries no key, no bucket, no path and no domain",
		'it carries: ' . implode(', ', $leaked)
		. ' — a job that can hand a node a key lets a compromised plane use the node to open '
		. 'something the node could not open by itself, and a job that can name a domain lets it '
		. 'redirect a restore onto a name of its choosing');
}

section('A restore with nowhere to go refuses rather than composing a dead transport');

// The SSH builders are still in the file — they are the only written record of
// what a restore used to do, and they come out once the primitive path has been
// proven live on a node (WP5). What must never happen is that one of them gets
// COMPOSED: the agent refuses ssh and scp steps by name, so a job built that way
// dies at its first step with a message about a step type, during a restore.
foreach ([[$unpaired, 'no paired agent'], [$ancient, '1.13.0']] as [$node, $expected]) {
	$refused = '';
	try {
		JobCommandBuilder::build_restore_database($node,
			['filename' => 'x.sql.gz.enc', 'local_path' => '/backups/x.sql.gz.enc']);
	} catch (Exception $e) {
		$refused = $e->getMessage();
	}
	check($refused !== '' && strpos($refused, $expected) !== false,
		'a restore that cannot travel refuses, naming ' . $expected,
		$refused === '' ? 'it composed a job instead' : $refused);
}

harness_finish();
