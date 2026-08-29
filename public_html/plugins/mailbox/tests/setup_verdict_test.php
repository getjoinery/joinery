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
 * @version 1.3 - mail-access grading, and the remembered verdict's
 *   remember/recall/forget contract
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
// Before harness_boot(): the remembered verdict lives in a session, and PHP
// refuses to start one once anything has printed. A caller that has already
// written output (the file validator, which runs the file) leaves no session to
// start — those checks skip rather than fail.
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) { @session_start(); }
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/CheckDomainSetup.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_hints.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

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

// ---------------------------------------------------------------------------
section('A sign-in is not mail access');
// ---------------------------------------------------------------------------
// Google asks for identity and mailbox access as separate tick boxes, so an
// operator can return holding a valid token that authorizes nothing. Every
// symptom of that is the symptom of an expired token — a refused IMAP login —
// so the grant is graded on what the provider said it granted.

$gmail_identity = 'https://www.googleapis.com/auth/userinfo.email openid';
check(InboundImapAccount::missingMailScopes('imap_gmail', $gmail_identity)
		=== array('https://mail.google.com/'),
	'a Gmail grant carrying only identity scopes is missing mail access');
check(InboundImapAccount::missingMailScopes('imap_gmail',
		'https://mail.google.com/ ' . $gmail_identity) === array(),
	'and one carrying the mail scope is not');
check(InboundImapAccount::missingMailScopes('imap_microsoft',
		'https://outlook.office365.com/SMTP.Send offline_access')
		=== array('https://outlook.office365.com/IMAP.AccessAsUser.All'),
	'send permission alone does not let Microsoft read the mailbox');

// Silence is not refusal: a refresh response often omits the scope entirely,
// and turning a working feed away on an absence would be worse than the bug.
check(InboundImapAccount::missingMailScopes('imap_gmail', '') === array(),
	'a provider that reports no scope is not treated as having refused');
check(InboundImapAccount::missingMailScopes('imap_yahoo', $gmail_identity) === array(),
	'a password host requires no scopes at all');

// The same grant decides whether the mailbox can SEND, because Google's one mail
// scope covers both directions — so an identity-only grant must not report
// itself ready to send and then fail at the wire with a raw 535.
check(InboundImapAccount::requiredSendScopes('google') === array('https://mail.google.com/'),
	'Google send authorization names the mail scope rather than assuming it');
check(InboundImapAccount::requiredSendScopes('microsoft')
		=== array('https://outlook.office365.com/SMTP.Send'),
	'Microsoft needs its own send scope alongside IMAP');

// ---------------------------------------------------------------------------
section('The remembered verdict');
// ---------------------------------------------------------------------------
// The banner reads its answer out of the operator's session rather than paying
// for DNS lookups on every mailbox click, which makes staleness the interesting
// problem: an operator who has just fixed the thing the banner names must not be
// told it is still broken. So a verdict is dropped the moment anything that
// produced it changes (InboundImapAccount::save() is the biggest such writer).

if (session_status() !== PHP_SESSION_ACTIVE) {
	harness_skip('the remembered verdict', 'no session available in this context');
} else {

$attention = array('status' => 'attention', 'reason' => 'Renew the authorization.',
	'label' => 'IMAP connection');
mailbox_setup_status_remember(4242, $attention);
$recalled = mailbox_setup_status_recall(4242);
check(is_array($recalled) && $recalled['label'] === 'IMAP connection',
	'a verdict that was reached is remembered');

mailbox_setup_status_forget(4242);
check(mailbox_setup_status_recall(4242) === null,
	'and forgetting it sends the next ask back to the live checks');

mailbox_setup_status_remember(4242, array('status' => 'unknown', 'reason' => '', 'label' => ''));
check(mailbox_setup_status_recall(4242) === null,
	'an unknown answer is never stored — absence of information is not news');

}

harness_finish();
