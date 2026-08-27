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
 * A box with no agent source is not a publishing control plane and has no
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

harness_finish();
