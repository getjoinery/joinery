<?php
/** @joinery-test
 * name: imap_feed_health
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * IMAP feed health — a broken feed is announced once, and the fix is findable
 * (specs/mailbox_imap_feed_health.md).
 *
 * A dead authorization used to be re-detected every poll and told nobody. Now
 * every fetch path reports its outcome to the account, which keeps the last
 * announced state on the row and raises mailbox.imap_feed_broken / _recovered
 * on transition only. This test drives the rule and the row directly (no IMAP
 * server), checks the provisioning health check reads that state, checks the
 * signal declarations, and checks a denied consent lands on the Accounts tab
 * with the cause translated.
 *
 * No signal is dispatched here (announce=false): a dispatch would notify the
 * dev site's admins about a fixture address.
 *
 * Run: php tests/run.php db --filter=imap_feed_health
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2State.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ConsumerRegistry.php'));
require_once(PathHelper::getIncludePath('logic/oauth_callback_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$OK = InboundImapAccount::HEALTH_OK;
$BR = InboundImapAccount::HEALTH_BROKEN;
$T  = InboundImapAccount::HEALTH_FAILURE_THRESHOLD;

// ---------------------------------------------------------------------------
section('The rule: when a fetch outcome changes what has been announced');

$t = function ($prev, $ok, $auth, $n) { return InboundImapAccount::feedHealthTransition($prev, $ok, $auth, $n); };
check($t($OK, false, true, 1) === 'broken', 'ok → auth failure breaks at once (it never self-heals)');
check($t($OK, false, false, 1) === 'none' && $t($OK, false, false, $T - 1) === 'none', 'ok → one or two ordinary failures: silence');
check($t($OK, false, false, $T) === 'broken', "ok → the {$T}th ordinary failure in a row breaks");
check($t($BR, false, false, $T + 5) === 'none' && $t($BR, false, true, 1) === 'none', 'broken → still failing: announced already, silence');
check($t($BR, true, false, 0) === 'recovered', 'broken → a success recovers');
check($t($OK, true, false, 0) === 'none', 'ok → success: silence is the normal state');
check($t('', false, true, 1) === 'broken' && $t('', true, false, 0) === 'none', 'an empty previous state reads as ok');

// ---------------------------------------------------------------------------
section('The row: outcomes accumulate, transitions fire once');

$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'feed health fixture');
$account->set('iia_username', 'feedhealth-' . bin2hex(random_bytes(4)) . '@example.test');
$account->set('iia_provider_key', 'imap_gmail');
$account->set('iia_auth_method', InboundImapAccount::AUTH_OAUTH2);
$account->set('iia_oauth_access_token_enc', 'fixture-not-a-real-token');
$account->set('iia_is_enabled', true);
$account->set('iia_needs_reauth', false);
$account->save();
$account_id = (int)$account->key;
check($account_id > 0, 'fixture feed created');
harness_defer(function () use ($db, $account_id) {
	$db->prepare("DELETE FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id = ?")->execute(array($account_id));
});
$reload = function () use ($account_id) { return new InboundImapAccount($account_id, TRUE); };

$fired = array();
for ($i = 1; $i <= $T + 1; $i++) {
	$fired[] = $account->observeFetchOutcome(false, 'Connection refused', 0, false);
}
$expected = array_fill(0, $T - 1, 'none');
$expected[] = 'broken';
$expected[] = 'none';
check($fired === $expected, "ordinary failures: " . implode(',', $expected) . " — got " . implode(',', $fired));
$row = $reload();
check($row->get('iia_health_state') === $BR && (int)$row->get('iia_consecutive_failures') === $T + 1,
	'the row remembers broken and the count');
check((string)$row->get('iia_broken_since') !== '', 'and since when');

check($account->observeFetchOutcome(true, '', 3, false) === 'recovered', 'a success recovers, once');
check($account->observeFetchOutcome(true, '', 0, false) === 'none', 'the next success is silent');
$row = $reload();
check($row->get('iia_health_state') === $OK && (int)$row->get('iia_consecutive_failures') === 0
	&& ($row->get('iia_broken_since') === null || $row->get('iia_broken_since') === ''),
	'the row is ok again, count reset, since cleared');

// A success between failures resets the count: two failures, success, two failures — never broken.
$seq = array();
$seq[] = $account->observeFetchOutcome(false, 'x', 0, false);
$seq[] = $account->observeFetchOutcome(false, 'x', 0, false);
$seq[] = $account->observeFetchOutcome(true, '', 0, false);
$seq[] = $account->observeFetchOutcome(false, 'x', 0, false);
$seq[] = $account->observeFetchOutcome(false, 'x', 0, false);
check($seq === array('none', 'none', 'none', 'none', 'none'), 'a success between failures resets the count');
$account->observeFetchOutcome(true, '', 0, false);

// Auth failure: the refused credential breaks at once, from ok, on the first failure.
$account->markNeedsReauth();
check($account->needsReauth(), 'the fixture reads as needing reauth (OAuth, token on file, flag set)');
check($account->observeFetchOutcome(false, 'OAuth token refresh failed', 0, false) === 'broken', 'an auth failure breaks on the first miss');
check($account->observeFetchOutcome(false, 'OAuth token refresh failed', 0, false) === 'none', 'and is not announced again');

section('The announcement says what an owner needs');
$p = $account->feedHealthPayload('broken', true, 'OAuth token refresh failed');
check($p['address'] === (string)$account->get('iia_username') && $p['provider'] === 'Gmail / Google Workspace', 'address and provider label');
check(strpos($p['reason'], 'revoked or has expired') !== false, 'the auth reason is plain: revoked or expired');
check(strpos($p['reason'], 'Testing mode') !== false && strpos($p['reason'], '7 days') !== false, 'and for Google names the Testing-mode 7-day expiry');
check(strpos($p['sending'], 'affected too') !== false, 'says sending through the same account is affected');
check(strpos($p['fix'], 'Reconnect') !== false, 'names the fix');
check($p['since'] !== '', 'carries since-when');
$account->set('iia_needs_reauth', false);
$account->save();
$account->set('iia_consecutive_failures', 3);
$p2 = $account->feedHealthPayload('broken', false, 'Connection timed out');
check(strpos($p2['reason'], 'last 3 fetch attempts failed') !== false && strpos($p2['reason'], 'timed out') !== false,
	'an ordinary outage names the count and the detail');
$p3 = $account->feedHealthPayload('recovered', false, '', 4);
check(strpos($p3['detail'], '4 messages') !== false, 'recovery says what the first fetch brought in');

section('The provisioning check reads the same state');
$threw = '';
try { InboundEmailHealth::checkImapFeeds(); } catch (ProvisioningCheckFailed $e) { $threw = $e->getMessage(); }
check(strpos($threw, (string)$account->get('iia_username')) !== false && strpos($threw, 'Reconnect') !== false,
	'a broken feed fails the check by address, with the fix', $threw);
$account->observeFetchOutcome(true, '', 0, false);
$threw = '';
try { InboundEmailHealth::checkImapFeeds(); } catch (ProvisioningCheckFailed $e) { $threw = $e->getMessage(); }
// The check is sitewide, and this deployment may carry a real feed that is
// broken for real — so the assertion is that THIS account is no longer named,
// not that nothing at all is wrong on the site.
check(strpos($threw, (string)$account->get('iia_username')) === false,
	'a recovered feed passes it', $threw);

section('A disabled feed takes part in no transitions');
$account->set('iia_is_enabled', false);
$account->save();
$account->markNeedsReauth();
check($account->observeFetchOutcome(false, 'x', 0, false) === 'none' && $account->observeFetchOutcome(false, 'x', 0, false) === 'none'
	&& $account->observeFetchOutcome(false, 'x', 0, false) === 'none', 'failures on a disabled feed announce nothing');
check($reload()->get('iia_health_state') === $OK && (int)$reload()->get('iia_consecutive_failures') === 0, 'and touch no state');
$threw = '';
try { InboundEmailHealth::checkImapFeeds(); } catch (ProvisioningCheckFailed $e) { $threw = $e->getMessage(); }
check(strpos($threw, (string)$account->get('iia_username')) === false, 'nor does the check name it');
$account->set('iia_is_enabled', true);
$account->set('iia_needs_reauth', false);
$account->save();

section('Nothing here touches the folder cursors');
$cursor_rows = (int)$db->query("SELECT COUNT(*) FROM iif_inbound_imap_folders WHERE iif_iia_inbound_imap_account_id = " . $account_id)->fetchColumn();
check($cursor_rows === 0, 'the fixture has no folder rows and health bookkeeping created none — the cursor is untouched by an outage');

// ---------------------------------------------------------------------------
section('Both signals are declared with a notify block');
$catalog = SignalBus::signals();
foreach (array('mailbox.imap_feed_broken', 'mailbox.imap_feed_recovered') as $sig) {
	check(isset($catalog[$sig]), "$sig is in the merged catalog");
	check(!empty($catalog[$sig]['notify']['default_email']) && ($catalog[$sig]['notify']['ntf_type'] ?? '') === 'system',
		"$sig notifies admins in-app and by email");
}
check(strpos((string)($catalog['mailbox.imap_feed_broken']['notify']['title_template'] ?? ''), '{address}') !== false,
	'the broken title leads with the address');

// ---------------------------------------------------------------------------
section('A denied consent lands on the Accounts tab with the cause translated');
$session = SessionControl::get_instance();
$nonce = OAuth2State::issue('google', 'inbound_imap', array(), array('account_id' => $account_id), InboundImapOAuthConsumer::ACCOUNTS_URL);
$r = oauth_callback_logic(array('state' => $nonce, 'error' => 'access_denied'));
check($r instanceof LogicResult && $r->redirect === InboundImapOAuthConsumer::ACCOUNTS_URL,
	'access_denied for an inbound_imap flow redirects to the Accounts tab', var_export($r->redirect ?? null, true));
$messages = $session->get_messages('/plugins/mailbox/admin/admin_mailbox_accounts');
$texts = array();
foreach ($messages as $m) { $texts[] = (string)$m->message; }
$joined = implode(' ', $texts);
check(strpos($joined, 'test user') !== false && strpos($joined, 'Testing mode') !== false,
	'the flash names the test-user and Testing-mode causes', substr($joined, 0, 200));
check(strpos($joined, 'Reconnect') !== false, 'and tells the operator what to press next');
$session->mark_shown($messages);

// A generic provider error still comes back with a way forward.
$nonce = OAuth2State::issue('google', 'inbound_imap', array(), array('account_id' => $account_id), InboundImapOAuthConsumer::ACCOUNTS_URL);
$r = oauth_callback_logic(array('state' => $nonce, 'error' => 'temporarily_unavailable'));
check($r instanceof LogicResult && $r->redirect === InboundImapOAuthConsumer::ACCOUNTS_URL, 'any provider error lands on the Accounts tab');
$messages = $session->get_messages('/plugins/mailbox/admin/admin_mailbox_accounts');
$joined = '';
foreach ($messages as $m) { $joined .= ' ' . (string)$m->message; }
check(strpos($joined, 'temporarily_unavailable') !== false && strpos($joined, 'Reconnect') !== false, 'naming the error code and the next step');
$session->mark_shown($messages);

// A consumer with nothing to add keeps the default: back to returnUrl with oauth=cancelled.
$nonce = OAuth2State::issue('google', 'test_echo', array(), array(), '/imap/edit?id=3');
$r = oauth_callback_logic(array('state' => $nonce, 'error' => 'access_denied'));
check($r instanceof LogicResult && $r->redirect === '/imap/edit?id=3&oauth=cancelled',
	'a consumer without the denial hook gets the plain cancelled return', var_export($r->redirect ?? null, true));

harness_finish();
