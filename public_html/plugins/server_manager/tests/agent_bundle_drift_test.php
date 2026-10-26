<?php
/** @joinery-test
 * name: agent_bundle_drift
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Agent bundle drift — the bundled agent must match the agent source.
 *
 * On a publishing management node, public_html/agent_dist/ is built
 * from the agent checkout named by the server_manager_agent_source_path
 * setting. If the two fall out of step, every release published from that box
 * ships an agent the publisher did not intend, and nothing else notices: the
 * platform upgrades cleanly, the dashboard looks healthy, and the first
 * symptom is whatever the newer agent was supposed to fix, failing in the
 * field days later.
 *
 * This is that invariant as a check that costs nothing and runs every day.
 *
 * A box with no agent source is not a publishing management node; there is no
 * drift to detect and the source checks report as skipped. An agent commit
 * made since the last publish is pending, not drift (the next publish
 * rebuilds the bundle), and reports as skipped too.
 *
 * Run: php plugins/server_manager/tests/agent_bundle_drift_test.php
 *
 * @version 1.1 - an agent commit since the last publish is pending, not drift (B143)
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));

$dist_dir = PathHelper::getIncludePath('agent_dist');
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
	// Not a publishing management node. Recorded rather than silently absent, so
	// a run on the wrong box cannot be mistaken for a passing drift check.
	harness_skip('drift check not applicable', "no agent source at {$src}");
} else {
	// A release builds the agent from the source's HEAD commit, not its
	// working tree, so that is what the bundle is held to: an uncommitted
	// bump in the checkout is another session's work in progress, not drift.
	$source_version  = AgentDistPublisher::readCommittedVersion($src);
	$bundled_version = $manifest['version'] ?? null;

	check($source_version !== null, 'agent source version is readable at HEAD',
		"could not read 'var version' from main.go at the HEAD of {$src}");

	// A publish rebuilds the bundle whenever the source is newer, so an agent
	// commit made since the last publish is pending, not drift: the bundle is
	// held to HEAD only when a release was published after HEAD was committed.
	$head = ReleaseCommit::git($src, array('log', '-1', '--format=%ct', 'HEAD'));
	$head_time = ($head['exit'] === 0) ? (int)trim(implode('', $head['out'])) : 0;
	$last_publish = 0;
	try {
		$newest = DbConnector::get_instance()->get_db_link()
			->query('SELECT max(upg_create_time) FROM upg_upgrades')->fetchColumn();
		$last_publish = $newest ? (int)strtotime($newest . ' UTC') : 0;
	} catch (Exception $e) {
		// No release table here: hold the bundle to HEAD.
	}

	if ($source_version !== $bundled_version && $head_time && $last_publish && $head_time > $last_publish) {
		harness_skip('bundled agent version matches the agent source commit',
			"the agent source's HEAD (v{$source_version}) was committed after the last publish, which shipped v"
			. var_export($bundled_version, true) . '; the next publish rebuilds the bundle');
	} else {
		check(
			$source_version !== null && $source_version === $bundled_version,
			'bundled agent version matches the agent source commit',
			"the agent source's HEAD is v" . var_export($source_version, true)
			. " but agent_dist is v" . var_export($bundled_version, true)
			. ' - a release published after that commit shipped the older agent; rebuild the bundle'
		);
	}
}

harness_finish();
