<?php
/** @joinery-test
 * name: setup_steps_copy
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * SetupSteps::copyFor — a step's intro copy may be a plain string or a
 * callable evaluated against live state. These pin the contract: strings
 * pass through, callables run, and a callable that throws reads as ''
 * rather than a fatal, matching how statusFor treats its predicates.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));

section('String copy passes through');
check(SetupSteps::copyFor(array('key' => 't', 'copy' => 'plain intro'), null) === 'plain intro',
	'a string copy is returned as-is');
check(SetupSteps::copyFor(array('key' => 't'), null) === '',
	'a step with no copy reads as empty');

section('Callable copy is evaluated');
check(SetupSteps::copyFor(array('key' => 't', 'copy' => function (?User $viewer): string {
	return 'computed intro';
}), null) === 'computed intro', 'a callable copy returns its computed string');

section('A throwing callable never fatals the wizard');
check(SetupSteps::copyFor(array('key' => 't', 'copy' => function (?User $viewer): string {
	throw new RuntimeException('boom');
}), null) === '', 'it reads as empty copy instead');

section('The mail_send step uses a callable copy');
$step = SetupSteps::get('mail_send');
check(is_array($step) && is_callable($step['copy'] ?? null),
	'mail_send derives its intro from live state',
	'the step must not ask a configured site to pick a provider');

section('A step may take its place from live state');
SetupSteps::register('zz_order_probe', array('title' => 'Probe', 'scope' => 'user',
	'order' => function (): int { return -5; }, 'status' => function (?User $viewer): string { return 'none'; }));
$keys = array_column(SetupSteps::steps(), 'key');
check(($keys[0] ?? '') === 'zz_order_probe', 'a callable order is called and sorts the step by what it returns',
	implode(',', array_slice($keys, 0, 3)));
check(is_int(SetupSteps::steps()[0]['order']), 'and steps() hands back the resolved number, not the callable');

// A site its management node backs up runs no backup until the owner's key
// exists, so there the key step comes right after the secure connection.
$backups = SetupSteps::get('backups');
check(is_callable($backups['order'] ?? null), 'the Backups step decides its place from whether the site is managed');
check(call_user_func($backups['order']) === (ManagementNodeStatus::is_managed() ? 6 : 80),
	'early (6, after the secure connection at 5) on a managed site, near the end (80) otherwise');
check(SetupSteps::get('https')['order'] === 5, 'the secure connection the in-browser key needs still comes first');

harness_finish();
