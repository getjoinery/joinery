<?php
/** @joinery-test
 * name: setup_steps_copy
 * tier: safe
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

harness_finish();
