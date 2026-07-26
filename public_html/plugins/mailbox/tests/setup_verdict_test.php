<?php
/** @joinery-test
 * name: setup_verdict
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The two grading rules behind the Accounts page's "needs attention" badge
 * (specs/mailbox_setup_verdicts.md).
 *
 * Both exist to stop the badge crying wolf, and a badge that cries wolf gets
 * ignored — which costs more than the feature was worth. So they are worth
 * pinning down without a database or a live domain:
 *
 *   - only a REQUIRED failure flags a domain; advice does not;
 *   - a check the resolver could not answer is never a failure.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/CheckDomainSetup.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_hints.php'));

/** One check row in the shape runDomainChecks() returns. */
function row(string $status, string $severity = InboundEmailSetupCheck::REQUIRED): array {
	return array('id' => 'domain.test', 'scope' => '', 'layer' => 'domain', 'label' => 'Test',
		'severity' => $severity, 'status' => $status, 'summary' => '', 'detail' => '',
		'fix' => null, 'recheckable' => true);
}

const REQUIRED    = InboundEmailSetupCheck::REQUIRED;
const RECOMMENDED = InboundEmailSetupCheck::RECOMMENDED;

// ---------------------------------------------------------------------------
section('Only a required failure flags a domain');
// ---------------------------------------------------------------------------

check(CheckDomainSetup::verdictFor(array(row(InboundEmailSetupCheck::PASS))) === 'ok',
	'a domain whose checks all pass is ok');

check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::PASS),
	row(InboundEmailSetupCheck::FAIL, REQUIRED),
)) === 'attention', 'a required failure flags the domain');

// The case that decides whether this badge is worth reading: a domain that
// receives mail perfectly well but has no DMARC record.
check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::PASS),
	row(InboundEmailSetupCheck::WARN, RECOMMENDED),
)) === 'ok', 'a recommended warning is advice, not a problem — no badge');

check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::PASS),
	row(InboundEmailSetupCheck::FAIL, RECOMMENDED),
)) === 'ok', 'even a recommended FAILURE does not flag the domain');

check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::WARN, REQUIRED),
)) === 'ok', 'a required WARN is not a failure either — only FAIL is');

// ---------------------------------------------------------------------------
section('A check that could not run is never a failure');
// ---------------------------------------------------------------------------

check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::UNKNOWN, REQUIRED),
)) === 'unknown', 'a domain whose only check could not be evaluated is unknown, not broken');

check(CheckDomainSetup::verdictFor(array()) === 'unknown',
	'and so is a domain that produced no rows at all');

check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::PASS),
	row(InboundEmailSetupCheck::UNKNOWN, REQUIRED),
)) === 'ok', 'one unanswered lookup does not spoil a domain that is otherwise fine');

check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::INFO, REQUIRED),
)) === 'unknown', 'an INFO row carries no verdict either');

// A resolver hiccup must never turn a known-good domain into a flapping badge.
check(CheckDomainSetup::verdictFor(array(
	row(InboundEmailSetupCheck::UNKNOWN, REQUIRED),
	row(InboundEmailSetupCheck::UNKNOWN, REQUIRED),
	row(InboundEmailSetupCheck::FAIL, REQUIRED),
)) === 'attention', 'but a real failure still counts alongside unanswered ones');

// ---------------------------------------------------------------------------
section('A stale verdict is not shown');
// ---------------------------------------------------------------------------

// Pointing at a domain that was fixed last week wastes exactly the attention
// this feature is trying to buy.
$now = gmdate('Y-m-d H:i:s');
check(_mailbox_setup_verdict_is_fresh($now) === true, 'a verdict from just now is fresh');
check(_mailbox_setup_verdict_is_fresh(
	LibraryFunctions::time_shift($now, '-1 day', 'Y-m-d H:i:s')) === true,
	'yesterday\'s verdict is fresh');
check(_mailbox_setup_verdict_is_fresh(
	LibraryFunctions::time_shift($now, '-30 days', 'Y-m-d H:i:s')) === false,
	'a month-old verdict is not shown');
check(_mailbox_setup_verdict_is_fresh('') === false,
	'a domain that was never checked shows nothing');

harness_finish();
