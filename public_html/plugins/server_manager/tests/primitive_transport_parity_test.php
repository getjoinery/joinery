<?php
/** @joinery-test
 * name: primitive_transport_parity
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The agent's vocabulary and the plane's ability to address it must not drift.
 *
 * The two halves of this system agree by CONVENTION, not by declaration. The
 * agent compiles in a set of primitives; the plane decides whether it can use
 * one by asking method_exists(static::class, "build_{$operation}_primitive").
 * Nothing connects those facts — no interface, no shared file, no registry —
 * so a primitive can exist on every node in the fleet while the plane silently
 * never routes to it.
 *
 * That is not hypothetical. backup_run shipped, was proven to validate, refuse
 * out-of-vocabulary keys and compose its config correctly, and never ran once
 * over the channel: its envelope was written INLINE inside build_backup_run
 * instead of in a method by that name, so the gate it consulted was permanently
 * false and every dispatch fell through to the SSH path. The branch tested for
 * its own absence. Unit tests on both sides were green throughout, because each
 * tested its own half against its author's belief about the other.
 *
 * This is the check neither side could make alone.
 *
 * A box with no agent source is not a publishing management node and has no
 * vocabulary to compare against; those checks report as skipped.
 *
 * Run: php plugins/server_manager/tests/primitive_transport_parity_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

/**
 * Every primitive the agent registers, read from the source of truth: the
 * Register(Primitive{...}) calls themselves, not a list that could fall behind
 * them.
 */
function parity_agent_vocabulary($source_path) {
	$names = [];
	foreach (glob(rtrim($source_path, '/') . '/primitives/*.go') as $file) {
		if (substr($file, -8) === '_test.go') continue;
		$src = file_get_contents($file);
		// Register(Primitive{ ... Name: "x" ... }) — the Name field of a
		// registration, not any string that happens to look like one.
		if (preg_match_all('/Register\(Primitive\{.*?Name:\s*"([a-z][a-z0-9_]*)"/s', $src, $m)) {
			foreach ($m[1] as $name) {
				$names[$name] = true;
			}
		}
	}
	ksort($names);
	return array_keys($names);
}

$source_path = AgentDistPublisher::sourcePath();

section('The plane can address every primitive the agent ships');

if (!$source_path || !is_dir($source_path . '/primitives')) {
	check(true, 'no agent source on this box — vocabulary parity not applicable', $source_path ?: '(unset)');
	harness_finish();
	return;
}

$vocabulary = parity_agent_vocabulary($source_path);
check(!empty($vocabulary), 'the agent vocabulary is readable from source',
	$source_path . '/primitives');

foreach ($vocabulary as $name) {
	// The exact question the plane asks at dispatch time. Asking it the same
	// way is the point — a check that reimplemented the lookup could pass while
	// the real one failed.
	check(method_exists('JobCommandBuilder', "build_{$name}_primitive"),
		"the plane can build a {$name} job",
		"JobCommandBuilder::build_{$name}_primitive() is missing, so has_primitive() is false "
		. "for {$name} and every dispatch silently falls through to API or SSH");
}

section('The plane does not offer primitives the agent cannot run');

// The other direction, which fails differently and worse: the plane builds a
// {primitive: x} job, the node looks x up in its compiled-in vocabulary, does
// not find it, and refuses. That is the node behaving correctly and the job
// failing anyway — a refusal that reads like a node problem and is a plane one.
foreach (get_class_methods('JobCommandBuilder') as $method) {
	if (!preg_match('/^build_(.+)_primitive$/', $method, $m)) continue;
	check(in_array($m[1], $vocabulary, true),
		"the agent ships a {$m[1]} primitive",
		"JobCommandBuilder::{$method}() builds jobs for a primitive no agent compiles in; "
		. "nodes will refuse them as out-of-vocabulary");
}

section('The plane waits at least as long as the node intends to work');

// The fourth drift, and the one that starts a second copy of running work.
//
// The agent applies its own deadline per primitive (Primitive.Timeout, compiled
// in). The plane independently decides when a claim is lost and requeues the
// job. If the plane gives up FIRST it requeues a job that is still running, and
// the node begins a second backup of the same chain — and it is the agent's own
// poll that triggers the sweep, so a long job asks to be killed every fifteen
// seconds. The observed longest primitive job is 631s against a 900s floor.
//
// The safety property is one-directional: plane budget >= agent timeout. Longer
// only slows recovery from a genuine crash; shorter duplicates live work.

/**
 * Package-level duration constants in the agent's primitives package, as
 * seconds. Only the simple `const Name = N * time.Unit` form — anything more
 * elaborate is left unresolved, so a Timeout referring to it still fails loudly
 * rather than being guessed at.
 */
function parity_agent_duration_constants($source_path) {
	$units = [
		'time.Nanosecond' => 1e-9, 'time.Microsecond' => 1e-6, 'time.Millisecond' => 1e-3,
		'time.Second' => 1, 'time.Minute' => 60, 'time.Hour' => 3600,
	];
	$out = [];
	foreach (glob(rtrim($source_path, '/') . '/primitives/*.go') as $file) {
		if (substr($file, -8) === '_test.go') continue;
		$src = file_get_contents($file);
		if (!preg_match_all('/^const\s+(\w+)\s*=\s*(\d+)\s*\*\s*(time\.\w+)\s*$/m', $src, $m, PREG_SET_ORDER)) {
			continue;
		}
		foreach ($m as $c) {
			if (isset($units[$c[3]])) {
				$out[$c[1]] = (int)((int)$c[2] * $units[$c[3]]);
			}
		}
	}
	return $out;
}

function parity_agent_timeouts($source_path) {
	$units = [
		'time.Nanosecond' => 1e-9, 'time.Microsecond' => 1e-6, 'time.Millisecond' => 1e-3,
		'time.Second' => 1, 'time.Minute' => 60, 'time.Hour' => 3600,
	];
	// Named durations the agent adds to a primitive's own work, read from the
	// agent's source rather than copied here. The restore family declares
	// `70*time.Minute + ApprovalWindow`, and that is the honest expression: the
	// node holds a claimed destructive job open while its own operator answers a
	// challenge, so the wait is part of the deadline the plane must not undercut.
	// Resolving the constant keeps the agent free to write it that way; a test
	// that could only read literals would push the number into three places.
	$constants = parity_agent_duration_constants($source_path);
	$timeouts = [];

	foreach (glob(rtrim($source_path, '/') . '/primitives/*.go') as $file) {
		if (substr($file, -8) === '_test.go') continue;
		$src = file_get_contents($file);
		if (!preg_match_all('/Register\(Primitive\{(.*?)\n\t\}\)/s', $src, $blocks)) continue;

		foreach ($blocks[1] as $block) {
			if (!preg_match('/Name:\s*"([a-z][a-z0-9_]*)"/', $block, $n)) continue;
			if (!preg_match('/\n\s*Timeout:\s*([^,\n]+),/', $block, $t)) {
				$timeouts[$n[1]] = null; // declares none: the agent default applies
				continue;
			}
			// Sum of N*time.Unit terms — the only form we write. Anything else
			// fails loudly rather than being guessed at.
			$total = 0; $ok = true;
			foreach (explode('+', $t[1]) as $term) {
				$term = trim($term);
				if (preg_match('/^(\d+)\s*\*\s*(time\.\w+)$/', $term, $m) && isset($units[$m[2]])) {
					$total += (int)$m[1] * $units[$m[2]];
				} elseif (isset($constants[$term])) {
					$total += $constants[$term];
				} else { $ok = false; break; }
			}
			$timeouts[$n[1]] = $ok ? (int)$total : false;
		}
	}
	return $timeouts;
}

$agent_timeouts = parity_agent_timeouts($source_path);
$budgets = ManagementJob::PRIMITIVE_CLAIM_BUDGETS;

foreach ($agent_timeouts as $name => $seconds) {
	if ($seconds === false) {
		check(false, "the agent's declared timeout for {$name} is readable",
			'its Timeout is written in a form this test cannot evaluate — keep it to N*time.Unit sums');
		continue;
	}
	// A primitive with no declared Timeout runs under the agent's own default,
	// which is well inside the plane's floor.
	if ($seconds === null) continue;

	$plane = $budgets[$name] ?? ManagementJob::CLAIM_TIMEOUT_SECONDS;
	check($plane >= $seconds,
		"the plane lets {$name} run as long as the node will ({$seconds}s)",
		"the plane requeues it after {$plane}s while the node works for up to {$seconds}s — "
		. 'the job would be handed out again while the first copy is still running. '
		. 'Raise ManagementJob::PRIMITIVE_CLAIM_BUDGETS[' . $name . '].');
}

section('Primitive-capable operations are dispatched through createFromBuild');

// The third way these halves drift, and the one that cost the most.
//
// createJob() stores what it is given as ['steps' => $x]. Hand it a primitive
// envelope and it becomes {"steps":{"primitive":...}} — no top-level
// "primitive", so isPrimitiveJob() says no, the job goes to the step executor,
// and the node dies on it with "cannot unmarshal object into ... []main.Step".
//
// That happened. The moment build_backup_run() grew a primitive branch, four
// call sites kept handing its result to createJob, and the nightly fleet backup
// failed on every paired node at 04:00 with a JSON parse error — job 7171, and
// nothing in the failure said the word "backup". createJob now refuses an
// envelope outright, which turns that into a loud error at dispatch; this check
// finds it earlier still, in the source, before anyone dispatches anything.
//
// Dynamic job types (createJob($node->key, $job_type, ...)) cannot be resolved
// statically and are not checked here — the runtime guard in createJob covers
// them.
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

$dispatch_roots = [
	PathHelper::getIncludePath('plugins/server_manager'),
	PathHelper::getIncludePath('plugins/mailbox'),
];

$offenders = [];
foreach ($dispatch_roots as $root) {
	if (!is_dir($root)) continue;
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
	foreach ($files as $file) {
		if ($file->getExtension() !== 'php') continue;
		if (strpos($file->getPathname(), '/tests/') !== false) continue;

		$src = file_get_contents($file->getPathname());
		// createJob(<anything>, 'job_type', ...) — the literal job type only.
		if (!preg_match_all('/createJob\(\s*[^,]+,\s*\'([a-z_]+)\'/', $src, $m)) continue;

		foreach ($m[1] as $job_type) {
			if (method_exists('JobCommandBuilder', "build_{$job_type}_primitive")) {
				$offenders[] = basename($file->getPathname()) . " dispatches '{$job_type}'";
			}
		}
	}
}

check(empty($offenders),
	'no dispatch site sends a primitive-capable job type through createJob',
	implode('; ', array_unique($offenders))
	. ' — build_<op>() returns a primitive envelope for a paired node, and createJob would bury it '
	. 'under "steps" where isPrimitiveJob() cannot see it. Use ManagementJob::createFromBuild().');

// And the guard itself fires, proven with a violating envelope rather than
// assumed from reading the code.
$refused = false;
try {
	ManagementJob::createJob(1, 'backup_run', ['primitive' => 'backup_run', 'params' => []], null, null);
} catch (Exception $e) {
	$refused = (stripos($e->getMessage(), 'createFromBuild') !== false);
}
check($refused, 'createJob refuses a primitive envelope and names the right entry point',
	'a buried envelope must fail at dispatch, not on the node at 4am');

harness_finish();
