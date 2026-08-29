<?php
/** @joinery-test
 * name: restore_primitive_gate
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Restore is built for the agent channel and must not travel it yet.
 *
 * The three restore primitives exist on both sides — the agent compiles them
 * in, this plane can address them — and a destructive primitive is refused at
 * the agent's compiled ceiling until a node-verified approval verifier exists
 * (specs/restore_dispatch_approval_mechanism.md). A plane that routed restore
 * to the primitive transport today would therefore trade a working SSH restore
 * for a guaranteed refusal, and would find that out during a restore.
 *
 * So the gate is the subject of this file. Not "does the builder compose the
 * right envelope" alone, but "is the envelope unreachable", asserted the way
 * the dispatcher asks it rather than by reading the constant back.
 *
 * The strongest form of that question is the one asked below: a node that has
 * PAIRED, is running a NEW ENOUGH agent, and REPORTS the restore primitives in
 * its own claim vocabulary — every condition that normally means "route here" —
 * still does not get a restore over the channel. If the gate held only for
 * nodes that could not run restore anyway, it would not be a gate.
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
 * A node with EVERY reason to be routed at: paired, current, and reporting the
 * restore primitives in its own vocabulary. Nothing but the destructive gate
 * stands between this node and a primitive restore.
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
	$node->set('mgn_agent_version', '1.12.0');
	$node->set('mgn_agent_primitives',
		'apply_update,backup_run,check_status,restore_chain,restore_database,restore_project');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	return $node;
}

section('Restore does not travel the agent channel in this build');

check(JobCommandBuilder::node_can_dispatch_destructive(rpg_node()) === false,
	'node_can_dispatch_destructive() is false',
	'this is the single seam the approval round flips; while it is false no destructive '
	. 'primitive may be dispatched to any node');

check(JobCommandBuilder::DESTRUCTIVE_PRIMITIVES === RESTORE_OPS,
	'the destructive set names exactly the three restore operations',
	'got: ' . implode(',', JobCommandBuilder::DESTRUCTIVE_PRIMITIVES));

$paired = rpg_node();
foreach (RESTORE_OPS as $op) {
	check(JobCommandBuilder::has_primitive($paired, $op) === false,
		"has_primitive() refuses {$op} on a paired node that reports it",
		'the node is paired, runs 1.12.0 and lists this primitive in its own claim vocabulary — '
		. 'every condition that routes an operation to the channel. The destructive gate is the '
		. 'only thing that must stop it, and it did not');

	// The question the dispatcher actually asks. transports_for() still offers
	// the primitive (the builder exists, which is what parity requires); the
	// gate lives one level down, so this asserts the OUTCOME rather than the
	// absence of a method.
	check(in_array('primitive', JobCommandBuilder::transports_for($op), true),
		"transports_for() still lists a primitive transport for {$op}",
		'the builder must exist for primitive_transport_parity_test — the gate is has_primitive(), '
		. 'not a missing method');
}

// And the builders themselves: a paired node still gets SSH steps.
$db_steps = JobCommandBuilder::build_restore_database($paired,
	['filename' => 'joinerytest_20260828.sql.gz.enc', 'local_path' => '/backups/joinerytest_20260828.sql.gz.enc']);
check(is_array($db_steps) && !isset($db_steps['primitive']) && isset($db_steps[0]['type']),
	'build_restore_database() returns SSH steps for a fully paired node',
	'it returned a primitive envelope, so live restore has moved to a transport that refuses');

$chain_steps = JobCommandBuilder::build_restore_chain($paired,
	['chain_id' => 'chain-20260807_231507', 'domain' => 'gate.example.com']);
check(is_array($chain_steps) && !isset($chain_steps['primitive']) && isset($chain_steps[0]['type']),
	'build_restore_chain() returns SSH steps for a fully paired node',
	'it returned a primitive envelope');

section('The version floor is recorded, and is not what is doing the work');

foreach (RESTORE_OPS as $op) {
	check((JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION[$op] ?? null) === '1.12.0',
		"{$op} declares its introducing agent version",
		'kept for contract uniformity: when the destructive gate opens, the floor is already right '
		. 'and no rollout dispatches to an agent that predates the primitive');
}

// Proven, not assumed: an agent OLD enough to fail the floor is refused for the
// same operation, so a reader cannot mistake the floor for the gate.
$ancient = rpg_node(['mgn_agent_version' => '1.9.0', 'mgn_agent_primitives' => '']);
check(JobCommandBuilder::has_primitive($ancient, 'restore_database') === false,
	'an agent below the floor is refused too',
	'both refusals are false, and only one of them would still be false if the gate opened');

section('A restore job may never be given up on while the node is still running it');

$budgets = ManagementJob::PRIMITIVE_CLAIM_BUDGETS;
// The SSH path's own step timeouts are the floor these were sized from: 3600s
// for a database or project restore, 7200s for a chain's restore step. A plane
// budget under the node's deadline requeues a restore that is still running,
// and a second concurrent restore destroys the thing the first was recovering.
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

// The agent refuses an undeclared parameter, so a key the plane sends "just in
// case" is not redundant — it is a refusal. restore_chain resolves its artifacts
// inside the node's own store by chain id, and declares no profile.
check(!isset($chain['params']['profile']),
	'restore_chain sends no profile, which the agent does not declare',
	'got: ' . json_encode($chain['params'] ?? null));

$chain = JobCommandBuilder::build_restore_chain_primitive($paired,
	['chain_id' => 'chain-20260807_231507', 'seq' => '3', 'skip_database' => true,
	 'domain' => 'attacker.example.com']);
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

section('The SSH restore still resolves its own key, so the script fallback is unreachable there');

// restore_database.sh 3.4 gained an envelope-sidecar fallback for the primitive
// path, which has no plane preamble to unseal a key for it. That fallback fires
// ONLY when --key-file is absent, and the SSH path must therefore keep passing
// one — including in the case where nothing unsealed, where KEY_PATH falls back
// to the node's standing key rather than to the empty string. An empty
// --key-file would reach the new branch, which is a behaviour change in the one
// path that had to stay byte-for-byte identical.
$restore_cmd = '';
foreach ($db_steps as $step) {
	if (strpos($step['cmd'] ?? '', 'restore_database.sh') !== false) {
		$restore_cmd = $step['cmd'];
	}
}
check($restore_cmd !== '', 'the SSH restore step invokes restore_database.sh');
check(strpos($restore_cmd, '--key-file "$KEY_PATH"') !== false,
	'the SSH restore passes --key-file explicitly',
	'without it the script would fall through to its own sidecar resolution, which is the '
	. 'primitive path\'s fallback and not this path\'s behaviour');
check(strpos($restore_cmd, 'KEY_PATH="$HOME/.joinery_backup_key"') !== false,
	'KEY_PATH is never empty — it defaults to the node\'s standing key',
	'an unsealed-nothing case that left KEY_PATH empty would pass --key-file "" and reach the '
	. 'new fallback, changing the SSH path this round promised not to touch');

harness_finish();
