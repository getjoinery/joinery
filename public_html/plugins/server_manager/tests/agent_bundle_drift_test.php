<?php
/** @joinery-test
 * name: agent_bundle_drift
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Agent bundle drift — the bundled agent must match the agent source.
 *
 * On a publishing control plane, plugins/server_manager/agent_dist/ is built
 * from the agent checkout named by the server_manager_agent_source_path
 * setting. If the two fall out of step, every release published from that box
 * ships an agent the publisher did not intend, and nothing else notices: the
 * platform upgrades cleanly, the dashboard looks healthy, and the first
 * symptom is whatever the newer agent was supposed to fix, failing in the
 * field days later.
 *
 * This is that invariant as a check that costs nothing and runs every day.
 *
 * A box with no agent source is not a publishing control plane; there is no
 * drift to detect and the source checks report as skipped.
 *
 * Run: php plugins/server_manager/tests/agent_bundle_drift_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));

$dist_dir = PathHelper::getIncludePath('plugins/server_manager/agent_dist');
$src      = AgentDistPublisher::sourcePath();
$manifest = AgentDistPublisher::readManifest($dist_dir);

section('Bundled artifact is internally consistent');

check(is_array($manifest), 'agent_dist/manifest.json is readable',
	"no readable manifest at {$dist_dir}/manifest.json");

if (is_array($manifest)) {
	check(!empty($manifest['version']), 'manifest declares a version',
		'manifest.json has no version field');
	check(!empty($manifest['binaries']), 'manifest declares at least one binary',
		'manifest.json has no binaries');

	// A manifest naming a file that is not there is the other way a bundle can
	// be wrong: the version looks current, and the agent finds nothing to install.
	check(AgentDistPublisher::artifactsPresent($dist_dir, $manifest),
		'every binary named in the manifest exists on disk',
		'manifest names a file missing from agent_dist');

	foreach (($manifest['binaries'] ?? array()) as $platform => $entry) {
		check(!empty($entry['sha256']) && !empty($entry['signature']),
			"{$platform}: has sha256 and signature",
			"manifest entry for {$platform} is missing sha256 or signature");
	}
}

section('Bundle matches the agent source on this box');

$has_source = is_dir($src) && file_exists($src . '/main.go');

if (!$has_source) {
	// Not a publishing control plane. Recorded rather than silently absent, so
	// a run on the wrong box cannot be mistaken for a passing drift check.
	harness_skip('drift check not applicable', "no agent source at {$src}");
} else {
	$source_version  = AgentDistPublisher::readSourceVersion($src);
	$bundled_version = $manifest['version'] ?? null;

	check($source_version !== null, 'agent source version is readable',
		"could not read 'var version' from {$src}/main.go");

	check(
		$source_version !== null && $source_version === $bundled_version,
		'bundled agent version matches the agent source',
		"agent source is v" . var_export($source_version, true)
		. " but agent_dist is v" . var_export($bundled_version, true)
		. " - the last publish from this box shipped a stale agent; rebuild the bundle"
	);
}

harness_finish();
