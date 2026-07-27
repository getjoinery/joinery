<?php
/** @joinery-test
 * name: setup_verdict
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The grading rules behind the two "needs attention" surfaces
 * (specs/mailbox_setup_verdicts.md): the Accounts page's badge, and the
 * reader's setup banner.
 *
 * Both exist to stop the badge crying wolf, and a badge that cries wolf gets
 * ignored — which costs more than the feature was worth. So they are worth
 * pinning down without a database or a live domain:
 *
 *   - only a REQUIRED failure flags a domain; advice does not;
 *   - a check the resolver could not answer is never a failure.
 *
 * The reader's banner grades the same rows differently on purpose, and the
 * difference is worth pinning down too: it claims to mean "the Setup tab is not
 * all green for this mailbox", so ANYTHING the tab paints amber or red counts,
 * severity included. What stays silent there is only the absence of information.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/CheckDomainSetup.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_hints.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));

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

// ---------------------------------------------------------------------------
section('The reader banner: anything not green counts');
// ---------------------------------------------------------------------------

/** A scoped-rows result carrying these rows in its Receiving group. */
function scoped(array $receiving, array $forwarding = array()): array {
	return array('address' => 'info@example.com', 'domain' => 'example.com', 'mode' => 'store',
		'forwards' => false, 'arrival' => 'postfix', 'imap' => null,
		'receiving' => $receiving, 'forwarding' => $forwarding);
}

check(mailbox_setup_verdict(scoped(array(row(InboundEmailSetupCheck::PASS))))['status'] === 'ok',
	'an all-green mailbox says nothing');

// The case this banner exists for: the Setup tab shows amber, so the reader must
// not report the mailbox as fine.
$spf = row(InboundEmailSetupCheck::WARN, REQUIRED);
$spf['label'] = 'SPF record';
$spf['summary'] = 'SPF authorizes this server, but not the outbound provider.';
$verdict = mailbox_setup_verdict(scoped(array(row(InboundEmailSetupCheck::PASS), $spf)));
check($verdict['status'] === 'attention', 'a required warning banners the mailbox');
check($verdict['label'] === 'SPF record' && strpos($verdict['reason'], 'outbound provider') !== false,
	'and the banner quotes the row the operator will see on the tab');

check(mailbox_setup_verdict(scoped(array(row(InboundEmailSetupCheck::WARN, RECOMMENDED))))['status'] === 'attention',
	'a recommended warning is amber on the tab, so it banners too');

check(mailbox_setup_verdict(scoped(array(), array(row(InboundEmailSetupCheck::FAIL, REQUIRED))))['status'] === 'attention',
	'a broken sending path counts as much as a broken receiving one');

// Absence of information stays silent — the anti-flapping rule the badge shares.
check(mailbox_setup_verdict(scoped(array(row(InboundEmailSetupCheck::UNKNOWN, REQUIRED))))['status'] === 'unknown',
	'a check that could not run is not a verdict');
check(mailbox_setup_verdict(scoped(array(row(InboundEmailSetupCheck::INFO, REQUIRED))))['status'] === 'unknown',
	'nor is an undecidable one');
check(mailbox_setup_verdict(scoped(array(
	row(InboundEmailSetupCheck::PASS),
	row(InboundEmailSetupCheck::OPTIONAL, InboundEmailSetupCheck::RECOMMENDED),
)))['status'] === 'ok', 'a capability nobody turned on is grey on the tab, not amber');

check(mailbox_setup_verdict(null)['status'] === 'unknown',
	'a mailbox that does not resolve reports nothing rather than guessing');

harness_finish();
