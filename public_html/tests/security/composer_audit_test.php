<?php
/** @joinery-test
 * name: composer_audit
 * tier: safe
 * env: dev-only
 * needs: []
 * covers: [composer.json, composer.lock, plugins/**composer.json, plugins/**composer.lock]
 * timeout: 120
 * parallel: true
 */
/**
 * Every composer lock this tree ships has no known security advisory and no
 * abandoned package: core's public_html/composer.lock, and any plugin's own
 * composer.lock.
 *
 * It asks Packagist's advisory database (composer audit --locked), so it audits
 * exactly what a release installs, not what happens to sit in vendor/ here. A new
 * advisory appears without any file here changing, which is why this belongs
 * in the pre-publish gate (db --fresh, which reuses no earlier result).
 *
 * When Packagist cannot be reached the check is a reported skip: this box
 * could not ask, which is not a pass. Any other run that yields no report (a
 * broken composer.json, an unreadable lock, a composer too old for a flag) is
 * a failure that prints composer's output.
 *
 * An abandoned package fails too, unless it is in ACCEPTED_ABANDONED below
 * with the reason it was looked at and kept.
 *
 * A failing check names each package, the affected versions and the advisory
 * title. The fix is usually `composer update --with-all-dependencies PACKAGE`.
 *
 * Run: php tests/run.php --filter=composer_audit
 *
 * @version 1.1
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$public_html = realpath(__DIR__ . '/../..');

// Every composer project the tree ships: core, then each plugin with its own lock.
$projects = array('core' => $public_html);
foreach (glob($public_html . '/plugins/*/composer.lock') ?: array() as $lock) {
	$projects['plugin ' . basename(dirname($lock))] = dirname($lock);
}

/**
 * Abandoned packages that were looked at and kept, each with why.
 * 'package/name' => 'reason (who, date)'
 */
const ACCEPTED_ABANDONED = array();

/** Words composer prints when it could not reach the advisory database. */
const UNREACHABLE = '/network disabled|could not be downloaded|could not resolve|curl error|timed out|connection refused|network is unreachable|failed to open stream/i';

$composer = trim((string)shell_exec('command -v composer 2>/dev/null'));

section('Composer locks have no known advisories');

if ($composer === '') {
	harness_skip('composer audit', 'composer is not installed on this box');
	harness_finish();
}

foreach ($projects as $label => $dir) {
	if (!is_file($dir . '/composer.json') || !is_file($dir . '/composer.lock')) {
		check(false, $label . ': has both composer.json and composer.lock', $dir);
		continue;
	}

	// stdout carries the JSON report; stderr (warnings, progress) is kept apart
	// so a warning can never break the parse.
	$proc = proc_open(array($composer, 'audit', '--locked', '--format=json', '--abandoned=report', '--no-plugins', '--no-interaction'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
	if (!is_resource($proc)) {
		check(false, $label . ': composer audit could be started');
		continue;
	}
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($proc);

	$report = json_decode(trim((string)$stdout), true);
	if (!is_array($report) || !array_key_exists('advisories', $report)) {
		$said = trim($stderr . "\n" . $stdout);
		if (preg_match(UNREACHABLE, $said)) {
			harness_skip($label . ': composer audit', 'Packagist could not be reached: ' . substr($said, 0, 200));
		} else {
			check(false, $label . ': composer audit returned a report', 'exit ' . $exit . ': ' . substr($said, 0, 1000));
		}
		continue;
	}

	$findings = array();
	foreach ((array)$report['advisories'] as $package => $advisories) {
		foreach ((array)$advisories as $a) {
			$findings[] = $package . ' (' . ($a['affectedVersions'] ?? '?') . '): '
				. ($a['cve'] ?? $a['advisoryId'] ?? '') . ' ' . ($a['title'] ?? '');
		}
	}
	check($findings === array(), $label . ': no known security advisory in composer.lock',
		count($findings) . " advisories:\n  " . implode("\n  ", $findings));

	$abandoned = array();
	foreach ((array)($report['abandoned'] ?? array()) as $package => $replacement) {
		if (isset(ACCEPTED_ABANDONED[$package])) {
			continue;
		}
		$abandoned[] = $package . ($replacement ? ' (use ' . $replacement . ')' : ' (no replacement named)');
	}
	check($abandoned === array(), $label . ': no abandoned package in composer.lock that has not been reviewed',
		implode(', ', $abandoned) . ' (review it, then replace it or add it to ACCEPTED_ABANDONED with the reason)');
}

harness_finish();
