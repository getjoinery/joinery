<?php
/** @joinery-test
 * name: install_report
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * InstallReport reads config/install_services.txt — what the installer did
 * with the keys the deploy form handed it — for the wizard's first screen.
 * Asserts the line format, the grading (a 'done' whose text says a part did
 * not happen is 'partial', never green), the reader's order, and that an
 * absent or empty file is simply nothing to show.
 *
 * Run:  php tests/unit/install_report_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = sys_get_temp_dir() . '/install_report_test_' . getmypid();
@mkdir($dir, 0700, true);
register_shutdown_function(function () use ($dir) {
	foreach (glob($dir . '/*') ?: array() as $f) { @unlink($f); }
	@rmdir($dir);
});

try {
	section('Absent or empty is nothing to show');
	check(InstallReport::outcomes($dir . '/missing.txt') === array(), 'a missing file reads as no outcomes');
	file_put_contents($dir . '/empty.txt', "\n\n");
	check(InstallReport::outcomes($dir . '/empty.txt') === array(), 'so does an empty one');

	section('The installer\'s lines, graded');
	file_put_contents($dir . '/services.txt', implode("\n", array(
		'backup=done: b2 bucket joinery-backups is the backup target',
		'dns_credential=done: kept for the one publish of the mail records',
		'mail=done: sending as jeremy@test3.example.com; DNS not published: the sending domain could not be registered at SMTP2GO (SMTP2GO did not answer.); provider reports the domain unknown',
		'junk line without an equals sign',
		'unknown_key=done: ignored',
		'mail=done: a second mail line is ignored, the first wins',
	)) . "\n");
	$out = InstallReport::outcomes($dir . '/services.txt');
	check(array_keys($out) === array('mail', 'backup', 'dns_credential'),
		'outcomes come back in the wizard\'s order, unknown keys dropped', json_encode(array_keys($out)));
	check($out['backup']['outcome'] === 'done' && $out['backup']['label'] === 'Backups'
		&& $out['backup']['text'] === 'b2 bucket joinery-backups is the backup target',
		'a plain done: is done, prefix stripped from the text', json_encode($out['backup']));
	check($out['mail']['outcome'] === 'partial',
		'a done: whose text reports a part that did not happen is partial', json_encode($out['mail']));
	check(strpos($out['mail']['text'], 'sending as jeremy@test3.example.com') === 0,
		'the first mail line wins');
	check(InstallReport::dot('done') === 'green' && InstallReport::dot('partial') === 'amber'
		&& InstallReport::dot('skipped') === 'amber' && InstallReport::dot('failed') === 'none',
		'dots: done green, partial and skipped amber, failed none');

	section('Failed and unprefixed values');
	file_put_contents($dir . '/failed.txt', "mail=failed: SMTP2GO rejected the key\nbackup=something unclassified\n");
	$out = InstallReport::outcomes($dir . '/failed.txt');
	check($out['mail']['outcome'] === 'failed' && $out['mail']['text'] === 'SMTP2GO rejected the key',
		'failed: is failed');
	check($out['backup']['outcome'] === 'failed' && $out['backup']['text'] === 'something unclassified',
		'a value with no recognised prefix is shown as recorded and never painted green');

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
