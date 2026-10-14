<?php
/** @joinery-test
 * name: test_covers_declared
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A test that reads a script as text says so in its header.
 *
 * The runner knows what a PHP test reaches by the files it LOADS. A test that
 * reads a script without loading it (a shell installer's text, a Go source)
 * reaches that file only if its header declares it (`covers:`); without that,
 * --changed never selects it when the script changes, and a result it passed
 * before the change is reused as if it still held (B16, 2026-10-14:
 * installer_contract read install.sh with no covers and kept passing after an
 * edit that broke it). This finds every declared PHP test that reads a .sh or
 * .go file through file_get_contents and has no covers line in its header.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** Tests that read only files they make themselves, never a repo script, with why. */
$fixture_only = array(
	'tests/unit/rollback_previous_release_test.php' => 'reads the fixture trees it builds in a temporary folder',
);

/**
 * Tests that reached scripts without declaring them when this check began
 * (B16): each is given its covers: line and taken off this list. A test not
 * on it that reads a script with no covers fails here.
 */
$known_b16 = array(
	'plugins/mailbox/tests/listener_decommission_test.php',
	'plugins/mailbox/tests/relay_upgrade_test.php',
	'plugins/mailbox/tests/spam_filtering_test.php',
	'plugins/persona_browser/tests/media_cache_location_test.php',
	'plugins/server_manager/tests/agent_artifact_channel_test.php',
	'plugins/server_manager/tests/agent_release_channel_test.php',
	'plugins/server_manager/tests/agent_vocabulary_words_test.php',
	'plugins/server_manager/tests/republish_from_manifest_test.php',
	'plugins/server_manager/tests/tree_manifest_test.php',
	'tests/backups/backup_ledger_test.php',
	'tests/backups/backup_tree_swap_test.php',
	'tests/direct/joinery_direct_relay_test.php',
	'tests/security/package_signature_test.php',
	'tests/security/upgrade_stale_release_files_test.php',
	'tests/unit/image_decoder_test.php',
	'tests/unit/release_commit_test.php',
	'tests/unit/root_request_test.php',
	'tests/unit/secret_box_config_key_test.php',
);

section('Every test that reads a script declares what it covers');

$root = PathHelper::getIncludePath('');
$files = array_merge(
	glob($root . 'tests/*/*_test.php') ?: array(),
	glob($root . 'plugins/*/tests/*_test.php') ?: array()
);
$checked = 0;
$missing = array();
$known_left = array();
foreach ($files as $path) {
	$src = (string)file_get_contents($path);
	if (!preg_match('/^[ \t\/*#]*@joinery-test\b(.*?)\*\//ms', $src, $hm)) {
		continue;
	}
	$rel = substr($path, strlen($root));
	$reads_script = false;
	foreach (preg_split('/\R/', $src) as $line) {
		if (strpos($line, 'file_get_contents(') !== false && preg_match('/\.(sh|go)[\'"]/', $line)) {
			$reads_script = true;
			break;
		}
	}
	// A path built in one line and read in the next: a script literal named
	// beside a file_get_contents of a variable.
	if (!$reads_script && strpos($src, 'file_get_contents(') !== false
			&& preg_match('/[\'"][A-Za-z0-9_\/.-]*(maintenance_scripts|provisioning)\/[A-Za-z0-9_\/.-]+\.(sh|go)[\'"]/', $src)) {
		$reads_script = true;
	}
	if (!$reads_script) {
		continue;
	}
	$checked++;
	$header = $hm[1];
	if (preg_match('/^[ \t*#\/]*covers\s*:/mi', $header)) {
		continue;
	}
	if (isset($fixture_only[$rel])) {
		continue;
	}
	if (in_array($rel, $known_b16, true)) {
		$known_left[] = $rel;
		continue;
	}
	$missing[] = $rel;
}
foreach ($known_b16 as $rel) {
	check(in_array($rel, $known_left, true) || !is_file($root . $rel), "{$rel} is still a B16 test with no covers",
		'it has its covers now, or reads no script: take it off $known_b16');
}
check($checked >= 10, 'the tests that read scripts are found', $checked . ' found');
check($missing === array(), 'each declares covers: in its header (or is named here as reading only its own fixtures, or is a known B16 test)',
	implode(', ', $missing));

harness_finish();
