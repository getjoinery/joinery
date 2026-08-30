<?php
/** @joinery-test
 * name: restore_dispatch
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What this management node may and may not do about a restore.
 *
 * The claim the whole mechanism rests on is a negative one: a management node
 * that had been compromised can dispatch a restore job and can do NOTHING
 * whatsoever to get it approved. That claim is only as good as the wire format,
 * so the wire format is what is asserted here — against the agent's own source,
 * not against this plane's belief about it.
 *
 * Four things:
 *
 *   1. The destructive gate opens for a node whose agent can ask its own
 *      operator, and stays shut for one that cannot. It is a ROUTING decision,
 *      never permission: this plane grants none.
 *   2. No restore primitive declares a parameter through which an approval
 *      answer, a signature, or a token could travel. This is the one that makes
 *      "the plane cannot relay an approval" a property of the shape rather than
 *      of anybody's care — someone adding a convenience parameter later fails
 *      here rather than silently reopening it.
 *   3. Bringing a backup back sends a SIGNATURE, never a credential. A node
 *      holds a write-only bucket credential on purpose; a restore needs a read,
 *      and the read that gets granted is one object, expiring.
 *   4. A restore aimed at a node that cannot take a primitive refuses with an
 *      answer an operator can act on. The SSH steps below it are dead — the
 *      agent refuses ssh and scp by name — so composing them would produce a
 *      job that dies at step one, during a restore.
 *
 * A box with no agent source cannot check 2; that check reports as skipped.
 *
 * Run: php plugins/server_manager/tests/restore_dispatch_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

/** A stand-in node: only the columns the builders read. */
class RestoreDispatchNode {
	public $key = 1;
	private $fields;
	public function __construct(array $fields = array()) {
		$this->fields = array_merge(array(
			'mgn_slug'             => 'testnode',
			'mgn_agent_public_key' => 'AAAA',
			'mgn_agent_version'    => '1.13.0',
			'mgn_agent_primitives' => '',
			'mgn_web_root'         => '/var/www/html/testnode/public_html',
		), $fields);
	}
	public function get($f) { return $this->fields[$f] ?? null; }
}

// ── 1. The gate ─────────────────────────────────────────────────────────────
section('The destructive gate opens only for a node that can ask its own operator');

$paired = new RestoreDispatchNode();
check(JobCommandBuilder::node_can_dispatch_destructive($paired) === true,
	'a paired node may be sent a restore — its agent will ask before running one');

$unpaired = new RestoreDispatchNode(array('mgn_agent_public_key' => ''));
check(JobCommandBuilder::node_can_dispatch_destructive($unpaired) === false,
	'an unpaired node may not — there is nobody on it to do the asking');

foreach (array('restore_database', 'restore_project', 'restore_chain') as $op) {
	check(JobCommandBuilder::has_primitive($paired, $op) === true,
		"{$op} routes to the node's own agent on a 1.13.0 node");
	check(JobCommandBuilder::has_primitive($unpaired, $op) === false,
		"{$op} does not route to an unpaired node");
}

// The version floor is live now, not decorative: a 1.12.0 agent ships the
// restore vocabulary and refuses every job in it at a compiled ceiling, so
// routing to it would trade a transport for a guaranteed refusal.
$old = new RestoreDispatchNode(array('mgn_agent_version' => '1.12.0'));
foreach (array('restore_database', 'restore_chain', 'download_backup', 'stage_chain') as $op) {
	check(JobCommandBuilder::has_primitive($old, $op) === false,
		"{$op} is not routed to an agent that predates the approval verifier");
}

// THE SAME NODE, REPORTING ITS VOCABULARY — which is what a real 1.12.0 node
// does, and which the check above does not model.
//
// A node's own reported list normally WINS over any version inference, and that
// is right for every other operation: the report is the only account of a
// node's vocabulary that is not a guess. It is wrong here, because shipping the
// restore primitives and being able to AUTHORIZE a job in one are different
// facts — 1.12.0 ships them and refuses the whole class at a compiled ceiling.
// A gate that only caught nodes reporting nothing would have missed every node
// the rollout actually produces.
$old_reporting = new RestoreDispatchNode(array(
	'mgn_agent_version'    => '1.12.0',
	'mgn_agent_primitives' => 'check_status,backup_run,restore_database,restore_project,restore_chain',
));
check(JobCommandBuilder::node_can_dispatch_destructive($old_reporting) === false,
	'a 1.12.0 node that reports the restore primitives still may not be sent one',
	'it ships them and refuses them; routing there swaps this plane\'s legible '
	. '"apply an update first" for the agent\'s opaque "does not accept destructive primitives"');
foreach (array('restore_database', 'restore_project', 'restore_chain') as $op) {
	check(JobCommandBuilder::has_primitive($old_reporting, $op) === false,
		"{$op} is refused for a vocabulary-reporting 1.12.0 node");
}

// And the reported list still wins in the direction that matters — a current
// node that does NOT report a restore primitive is not sent one.
$current_partial = new RestoreDispatchNode(array(
	'mgn_agent_version'    => '1.13.0',
	'mgn_agent_primitives' => 'check_status,restore_database',
));
check(JobCommandBuilder::has_primitive($current_partial, 'restore_database') === true,
	'a current node that reports the primitive is routed at');
check(JobCommandBuilder::has_primitive($current_partial, 'restore_chain') === false,
	'and one it does not report is still refused — the node\'s own list is not overridden');

// A node's own reported vocabulary still wins over the version inference.
$reported = new RestoreDispatchNode(array(
	'mgn_agent_version'    => '1.13.0',
	'mgn_agent_primitives' => 'check_status,list_backups',
));
check(JobCommandBuilder::has_primitive($reported, 'restore_database') === false,
	'a node that does not report the primitive is not sent it, whatever its version says');

// ── 2. The wire format ──────────────────────────────────────────────────────
section('No restore can carry an approval on the wire');

$source_path = AgentDistPublisher::sourcePath();
if (!$source_path || !is_dir($source_path . '/primitives')) {
	harness_skip('no agent source on this box — the restore vocabulary cannot be read',
		(string)$source_path);
} else {
	// Read the declared parameters of each restore straight out of the agent's
	// registration blocks. This is the same source-of-truth approach
	// primitive_transport_parity uses, and for the same reason: a list restated
	// on this side would be a list that falls behind.
	$vocab = array();
	foreach (glob($source_path . '/primitives/*.go') as $file) {
		if (substr($file, -8) === '_test.go') { continue; }
		$src = (string)file_get_contents($file);
		if (!preg_match_all('/Register\(Primitive\{(.*?)\n\t\}\)/s', $src, $blocks)) { continue; }
		foreach ($blocks[1] as $block) {
			if (!preg_match('/Name:\s*"([a-z][a-z0-9_]*)"/', $block, $n)) { continue; }
			preg_match_all('/\{Name:\s*"([a-z][a-z0-9_]*)"/', $block, $p);
			$vocab[$n[1]] = $p[1];
		}
	}

	$banned = array(
		'approval', 'approved', 'approval_answer', 'approval_token', 'signature',
		'signed', 'token', 'assertion', 'challenge', 'answer', 'authorization',
		'authorized', 'consent', 'confirm', 'confirmation', 'override',
	);
	foreach (array('restore_database', 'restore_project', 'restore_chain') as $op) {
		check(isset($vocab[$op]), "the agent registers {$op}");
		foreach (($vocab[$op] ?? array()) as $param) {
			$bad = false;
			foreach ($banned as $word) {
				if (strpos($param, $word) !== false) { $bad = true; break; }
			}
			check(!$bad, "{$op} has no parameter that could carry an approval", $param);
		}
	}

	// And the plane never composes one either, so the builders cannot start
	// sending a field the vocabulary would refuse.
	$builder_src = (string)file_get_contents(
		PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
	check(strpos($builder_src, "'approval'") === false && strpos($builder_src, "'approval_answer'") === false,
		'this plane composes no approval field for any job');
}

// ── 3. Bringing a backup back sends a signature, not a credential ───────────
section('A node is handed one signed object, never a bucket credential');

$creds = array(
	'access_key' => 'AKIAEXAMPLEEXAMPLE',
	'secret_key' => 'notarealsecretnotarealsecretnotareal1234',
	'region'     => 'us-west-004',
	'endpoint'   => 'https://s3.us-west-004.example.com',
);
$url = S3Signer::presign_get($creds, 'a-bucket', '/joinery-backups/testnode/manager/db.sql.gz.enc', 900);

check(strpos($url, 'https://') === 0, 'the link is https — a signature is a bearer token');
check(strpos($url, 'X-Amz-Signature=') !== false, 'the link carries a signature');
check(strpos($url, 'X-Amz-Expires=900') !== false, 'and an expiry', $url);
check(strpos($url, $creds['secret_key']) === false,
	'the secret key is nowhere in the link — that is the whole point of signing here');
check(strpos($url, '/joinery-backups/testnode/manager/db.sql.gz.enc') !== false,
	'the object it names is in the link, and inside the signature, so it cannot be re-pointed');

// The window is the job's own claim budget: a link that outlives its job is a
// standing read sitting in a job row.
$budget = ManagementJob::PRIMITIVE_CLAIM_BUDGETS['download_backup'] ?? 0;
check($budget > 0, 'download_backup has its own claim budget', (string)$budget);
$long = S3Signer::presign_get($creds, 'a-bucket', '/x/y.sql.gz', $budget);
check(strpos($long, 'X-Amz-Expires=' . $budget) !== false,
	'a signed download link expires with the job that carries it', (string)$budget);

// SigV4's own ceiling, clamped rather than passed through.
$forever = S3Signer::presign_get($creds, 'a-bucket', '/x/y.sql.gz', 99999999);
check(strpos($forever, 'X-Amz-Expires=604800') !== false,
	'an absurd window is clamped to the seven-day maximum, not signed as asked');

// The profile and the shelf the object is on have to agree. They are chosen
// independently — one a parameter, one read out of a listing — so nothing made
// them, and a mismatch is a job that was always going to be refused on the node
// for a reason unrelated to what went wrong.
$mismatch = '';
try {
	JobCommandBuilder::build_download_backup_primitive($paired, array(
		'filename'   => 'db.sql.gz.enc',
		'profile'    => 'site',
		'cloud_path' => 'joinery-backups/testnode/manager/db.sql.gz.enc'));
} catch (Exception $e) { $mismatch = $e->getMessage(); }
if (strpos($mismatch, 'no enabled cloud backup target') !== false) {
	// The shelf is resolved before the key is checked, so a box with no target
	// configured cannot reach this. Reported as skipped rather than passing on
	// the wrong refusal.
	harness_skip('a profile that disagrees with the object\'s own shelf is refused here',
		'this box has no enabled backup target to resolve');
} else {
	check(strpos($mismatch, "'manager' shelf") !== false && strpos($mismatch, "'site' one") !== false,
		'a profile that disagrees with the object\'s own shelf is refused here', $mismatch);
}

// ── 4. A restore with nowhere to go says so ─────────────────────────────────
section('A restore that cannot travel refuses with something an operator can act on');

$params = array('filename' => 'db.sql.gz.enc', 'cloud_path' => 'joinery-backups/testnode/manager/db.sql.gz.enc');

try {
	JobCommandBuilder::build_restore_database($unpaired, $params + array('local_path' => '/backups/db.sql.gz.enc'));
	check(false, 'an unpaired node refuses a restore');
} catch (Exception $e) {
	check(strpos($e->getMessage(), 'no paired agent') !== false,
		'an unpaired node refuses a restore and names why', $e->getMessage());
	check(strpos($e->getMessage(), 'SSH') !== false,
		'and says the SSH route is gone rather than silently composing it', $e->getMessage());
}

try {
	JobCommandBuilder::build_restore_database($old, $params + array('local_path' => '/backups/db.sql.gz.enc'));
	check(false, 'an out-of-date agent refuses a restore');
} catch (Exception $e) {
	check(strpos($e->getMessage(), '1.13.0') !== false,
		'an out-of-date agent refuses a restore and names the version needed', $e->getMessage());
}

try {
	JobCommandBuilder::build_download_backup($unpaired, $params);
	check(false, 'an unpaired node refuses to fetch a backup back');
} catch (Exception $e) {
	check(strpos($e->getMessage(), 'paired agent') !== false,
		'and refuses to fetch a backup back, for the same reason', $e->getMessage());
}

// ── The primitive envelope a restore actually produces ──────────────────────
section('A dispatched restore names a primitive and nothing else');

$built = JobCommandBuilder::build_restore_database_primitive($paired, array(
	'filename' => 'db_2026-08-30.sql.gz.enc',
	'profile'  => 'manager',
));
check(($built['primitive'] ?? '') === 'restore_database',
	'the job is a primitive envelope, not a list of steps');
check(!isset($built['steps']), 'and carries no steps at all');
$sent = array_keys($built['params'] ?? array());
sort($sent);
check($sent === array('file', 'profile'),
	'it sends a NAME and a profile — no path, no key, no approval', implode(',', $sent));

harness_finish();
