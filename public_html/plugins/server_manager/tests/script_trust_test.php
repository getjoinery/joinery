<?php
/** @joinery-test
 * name: script_trust
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Whether a node can still run the scripts it would run as root.
 *
 * The agent verifies every site script against a signed manifest before running
 * it as root. When that verification cannot be done it refuses EVERY script
 * primitive at once — apply_update included, which is what makes the state
 * self-sustaining: the upgrade that would repair the node is refused by the same
 * check that is failing. Getjoinery sat like that for four days while the
 * dashboard showed nothing but a handful of unrelated-looking job failures.
 *
 * Two things are pinned here.
 *
 * FIRST, the classification, which is the part with a security consequence. A
 * manifest that cannot be used and a file that does not match its signed hash
 * both produce a refusal, and they are opposite problems. The first is repaired
 * by delivering a correct manifest. The second means the file on disk is not the
 * file that was published — the exact event the check exists to catch — and
 * delivering a manifest would paper over it. Anything that ever automates
 * recovery reads this classification to decide, so a wrong answer here is not a
 * cosmetic bug.
 *
 * SECOND, the bookkeeping: the state is stamped as the refusal arrives, its
 * first-seen time survives the nightly re-refusals that follow, and it clears
 * only on the node's own evidence — a job type that once refused here on trust
 * grounds completing now. It must never clear on a primitive that proves
 * nothing, and the plane must never hold its own list of which primitives are
 * script-backed.
 *
 * Run: php plugins/server_manager/tests/script_trust_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

function st_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest ST ' . $suffix);
	$node->set('mgn_slug', 'harnessst-' . $suffix);
	$node->set('mgn_host', '192.0.2.30');
	$node->set('mgn_ssh_user', 'root');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);
	return $node;
}

function st_job($node, $type, $outcome, $message) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_node_id', $node->key);
	$job->set('mjb_job_type', $type);
	$job->set('mjb_status', $outcome === 'completed' ? 'completed' : 'failed');
	$job->set('mjb_agent_outcome', $outcome);
	$job->set('mjb_commands', array());
	$job->set('mjb_error_message', $message);
	$job->save();
	$job->load();
	harness_register_row('mjb_management_jobs', 'mjb_id', $job->key);
	return $job;
}

// The wordings the agent actually produces, copied from its source rather than
// paraphrased — this classification is a coupling to those strings and a test
// written from memory would not notice them changing.
$MANIFEST_BAD_KEY = 'Refused by the node: primitive "apply_update" refused: no script from this release '
	. 'can be verified before running as root: tree manifest signature does not verify against the '
	. 'compiled-in release key';
$MANIFEST_NONE = 'Refused by the node: primitive "backup_run" refused: this release carries no signed '
	. 'per-file tree manifest, so no script can be verified before running as root';
$FILE_MODIFIED = 'Refused by the node: primitive "apply_update" refused: file does not match its '
	. 'signed hash — it has been modified since release: public_html/utils/upgrade.php';
$FILE_UNLISTED = 'Refused by the node: primitive "backup_run" refused: file is not in the signed '
	. 'release manifest: public_html/utils/stranger.php';

// ---------------------------------------------------------------------------
section('Telling an unusable manifest from a file that does not match one');

check(NodeMonitorHealth::classify_script_trust($MANIFEST_BAD_KEY) === 'untrusted_manifest',
	'a manifest signed by the wrong key is a manifest problem');
check(NodeMonitorHealth::classify_script_trust($MANIFEST_NONE) === 'untrusted_manifest',
	'no manifest at all is a manifest problem');

// The distinction that matters. These must NEVER read as a manifest problem:
// the remedy for a manifest problem is to deliver a manifest, and doing that
// here would overwrite the evidence that a root-run file was modified.
check(NodeMonitorHealth::classify_script_trust($FILE_MODIFIED) === 'untrusted_file',
	'a file that does not match its signed hash is a FILE problem, not a manifest one');
check(NodeMonitorHealth::classify_script_trust($FILE_UNLISTED) === 'untrusted_file',
	'a file missing from the manifest is a FILE problem, not a manifest one');

// Everything else is somebody else's refusal and must not colour a node red.
check(NodeMonitorHealth::classify_script_trust('') === null,
	'an empty reason is not a trust event');
check(NodeMonitorHealth::classify_script_trust(
	'Refused by the node: primitive "managed_domain_prepare" is not carried by this agent') === null,
	'a node declining a primitive it does not carry is not a trust event');
check(NodeMonitorHealth::classify_script_trust(
	'Refused by the node: policy forbids destructive operations on this machine') === null,
	'a policy refusal is not a trust event');

// ---------------------------------------------------------------------------
section('Recording the state as the refusal arrives');

$node = st_node();
check((string)$node->get('mgn_script_trust') === '', 'a new node carries no trust state');

$job = st_job($node, 'apply_update', 'refused', $MANIFEST_BAD_KEY);
NodeMonitorHealth::note_script_trust($node, $job);
$node->load();
check($node->get('mgn_script_trust') === 'untrusted_manifest',
	'a trust refusal marks the node', var_export($node->get('mgn_script_trust'), true));
check(!empty($node->get('mgn_script_trust_since')), 'and stamps when it was first seen');
check($node->get('mgn_script_trust_reason') === $MANIFEST_BAD_KEY,
	'and keeps the reason the node gave, verbatim');
check($node->get('mgn_script_trust_job_type') === 'apply_update',
	'and remembers which job type refused');

$first_seen = $node->get('mgn_script_trust_since');

// The nightly backup refuses too, night after night. How long a node has been
// unmanageable is the number that makes it urgent; re-stamping it would report
// a four-day-old problem as new every morning.
$job2 = st_job($node, 'backup_run', 'refused', $MANIFEST_NONE);
NodeMonitorHealth::note_script_trust($node, $job2);
$node->load();
check($node->get('mgn_script_trust_since') === $first_seen,
	'a later refusal of the same kind does not reset how long it has been true');

// ---------------------------------------------------------------------------
section('Refusals that say nothing about trust leave the state alone');

$clean = st_node();
$other = st_job($clean, 'check_status', 'refused',
	'Refused by the node: primitive "check_status" is not carried by this agent');
NodeMonitorHealth::note_script_trust($clean, $other);
$clean->load();
check((string)$clean->get('mgn_script_trust') === '',
	'an unrelated refusal does not mark a node untrusted');

// ---------------------------------------------------------------------------
section('Clearing on the node own evidence');

// A primitive that needs no script proves nothing about script verification.
// Clearing on it would report a still-broken node as healthy.
$probe = st_job($node, 'ssl_probe', 'completed', '');
NodeMonitorHealth::note_script_trust($node, $probe);
$node->load();
check($node->get('mgn_script_trust') === 'untrusted_manifest',
	'a completed primitive that never runs a script does not clear the state');

// A job type that HAS refused here on trust grounds, now completing, is the
// node saying it can verify scripts again. That evidence needs no plane-side
// list of which primitives are script-backed.
$fixed = st_job($node, 'apply_update', 'completed', '');
NodeMonitorHealth::note_script_trust($node, $fixed);
$node->load();
check($node->get('mgn_script_trust') === 'ok',
	'a job type that once refused on trust grounds, completing, clears the state',
	var_export($node->get('mgn_script_trust'), true));
check(empty($node->get('mgn_script_trust_since')), 'and the first-seen stamp is released');
check((string)$node->get('mgn_script_trust_reason') === '', 'and the stale reason is dropped');

// ---------------------------------------------------------------------------
section('What a node volunteers on its poll');

// The case a refusal cannot reach: a node that is refusing but has no job
// dispatched to it. It polls every cycle, so the poll is the one moment it can
// say so for itself.
$quiet = st_node();
NodeMonitorHealth::note_reported_script_trust($quiet, 'untrusted_manifest');
$quiet->save();
$quiet->load();
check($quiet->get('mgn_script_trust') === 'untrusted_manifest',
	'a node that reports it cannot verify scripts is marked without any job at all');
check(!empty($quiet->get('mgn_script_trust_since')), 'and its first-seen is stamped');
check(stripos((string)$quiet->get('mgn_script_trust_reason'), 'on its poll') !== false,
	'and the reason says the node volunteered it', $quiet->get('mgn_script_trust_reason'));

// The node's own account outranks a stale refusal, in both directions — that is
// what lets it recover without being sent a job of the right type first.
$recovered = st_node();
NodeMonitorHealth::note_script_trust($recovered, st_job($recovered, 'apply_update', 'refused', $MANIFEST_BAD_KEY));
$recovered->load();
check($recovered->get('mgn_script_trust') === 'untrusted_manifest', 'marked from a refusal first');
NodeMonitorHealth::note_reported_script_trust($recovered, 'ok');
$recovered->save();
$recovered->load();
check($recovered->get('mgn_script_trust') === 'ok',
	'a node reporting ok clears a state set by an earlier refusal');
check(empty($recovered->get('mgn_script_trust_since')), 'and releases the first-seen stamp');

// A reason already recorded from a refusal is the more useful of the two and is
// kept rather than replaced by the generic poll wording.
$keeps = st_node();
NodeMonitorHealth::note_script_trust($keeps, st_job($keeps, 'backup_run', 'refused', $MANIFEST_NONE));
$keeps->load();
NodeMonitorHealth::note_reported_script_trust($keeps, 'untrusted_manifest');
$keeps->save();
$keeps->load();
check($keeps->get('mgn_script_trust_reason') === $MANIFEST_NONE,
	'a reason from a refusal survives a later poll that says the same thing');

// Anything that is not one of the three answers is not an answer. An older
// agent sends nothing at all, and nothing must never read as healthy.
$untouched = st_node();
NodeMonitorHealth::note_reported_script_trust($untouched, 'healthy');
NodeMonitorHealth::note_reported_script_trust($untouched, '');
NodeMonitorHealth::note_reported_script_trust($untouched, 'ok; DROP TABLE');
$untouched->save();
$untouched->load();
check((string)$untouched->get('mgn_script_trust') === '',
	'an answer outside the closed set moves nothing');

// ---------------------------------------------------------------------------
section('What the dashboard is told');

$bad = st_node();
NodeMonitorHealth::note_script_trust($bad, st_job($bad, 'apply_update', 'refused', $MANIFEST_BAD_KEY));
$bad->load();
$health = NodeMonitorHealth::script_trust_health($bad);
check($health['is_problem'] === true, 'an untrusted node is a problem');
check(stripos($health['label'], 'no longer be managed') !== false,
	'and the headline says the node cannot be managed, not that a job failed', $health['label']);
check(stripos($health['detail'], 'refused by the same check') !== false,
	'and says why it cannot repair itself');

$tampered = st_node();
NodeMonitorHealth::note_script_trust($tampered, st_job($tampered, 'apply_update', 'refused', $FILE_MODIFIED));
$tampered->load();
$health = NodeMonitorHealth::script_trust_health($tampered);
check(stripos($health['detail'], 're-delivering a manifest') !== false,
	'a modified file is reported as NOT fixed by re-delivering a manifest', $health['detail']);

// detail is escaped whole by the dashboard, so it must be plain text — a health
// line that smuggles markup either renders as literal tags or, worse, does not.
foreach ([$MANIFEST_BAD_KEY, $FILE_MODIFIED] as $i => $msg) {
	$n = st_node();
	NodeMonitorHealth::note_script_trust($n, st_job($n, 'apply_update', 'refused', $msg));
	$n->load();
	$d = NodeMonitorHealth::script_trust_health($n);
	check(strip_tags($d['detail']) === $d['detail'], 'health detail ' . $i . ' carries no markup');
	check(strip_tags($d['label']) === $d['label'], 'health label ' . $i . ' carries no markup');
}

// A node in the clear is not listed.
$ok = st_node();
$health = NodeMonitorHealth::script_trust_health($ok);
check($health['is_problem'] === false, 'a node with no trust state is not a problem');

// Compared as ints on purpose: a freshly saved model hands back a string key
// while one loaded through a collection hands back an int, and a strict
// comparison across the two silently reports every node as absent.
$listed = array_map('intval', array_column(NodeMonitorHealth::script_trust_problems(), 'id'));
check(in_array((int)$bad->key, $listed, true), 'an untrusted node reaches the dashboard list');
check(!in_array((int)$ok->key, $listed, true), 'a healthy node does not');
check(!in_array((int)$node->key, $listed, true), 'and neither does one that has recovered');

harness_finish();
