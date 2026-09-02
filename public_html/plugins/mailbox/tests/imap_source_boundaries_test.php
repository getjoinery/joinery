<?php
/** @joinery-test
 * name: imap_source_boundaries
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An IMAP-source domain is not a hosted domain
 * (specs/imap_source_domain_boundaries.md § 2-4).
 *
 * Connecting an external account (Gmail over IMAP) creates a real domain row
 * for the provider's domain as an FK anchor for the mailbox alias. That row
 * must never read as an identity this deployment owns. What this pins down:
 *
 *  - is_authoritative(): the one place the distinction lives — an IMAP-source
 *    row answers no; an enabled hosted row answers yes; disabled answers no;
 *  - isHostedEmailAddress(): an address on an IMAP-source domain is NOT
 *    hosted here, so one connected Gmail never blocks @gmail.com signups —
 *    while a disabled hosted domain still answers yes (re-enabling it would
 *    bring the circularity back);
 *  - userHostedDomainNames(): a grant on an IMAP-source mailbox contributes
 *    no hosted domain, so the login-email-change step-up stays off;
 *  - DirectSigningIdentity::ensureFor(): refuses to mint a signing identity
 *    for an IMAP-source domain (the guard sits at the mint, so no future
 *    caller can reintroduce one) and still mints for a hosted domain;
 *  - MessengerFederation::localDomain(): an IMAP-source domain is not local,
 *    so its provider's addresses are not swallowed as unreachable-by-chat;
 *  - MailboxDirectConsumer::resolveAddress(): no Direct authority claimed for
 *    an IMAP-source domain — a hosted domain still answers;
 *  - InboundEmailSetupCheck::runDomainChecks(): nothing to say about an
 *    IMAP-source domain — no DNS grading, no Direct plan, no identity mint;
 *  - mailbox_receive_mode(): an IMAP-source row does not decide the receive
 *    topology;
 *  - transport (§5): OutboundTransport::forHostedAlias() refuses an address on
 *    an IMAP-source domain, and MailboxSender::sendCapabilityFor() names the
 *    disabled feed BEFORE a send — with no fallthrough to platform egress;
 *  - the Setup tab's Sending row for a pulled-in mailbox reads the same answer;
 *  - the wizard's receiving list (§6): a connected account is "connected
 *    account" while its feed is on and "connection paused" when off;
 *  - provisioning (§9.3): a plus-tagged address is refused as a connected
 *    mailbox, naming the base address, and leaves no domain row behind.
 *
 * Run: php tests/run.php db --filter=imap_source_boundaries
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDirectConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));

$tag = substr(bin2hex(random_bytes(4)), 0, 8);

/** A domain row, hosted or IMAP-source. */
function isb_domain(string $name, bool $imap_source, bool $enabled = true): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', $name);
	$domain->set('ied_is_enabled', $enabled);
	$domain->set('ied_is_imap_source', $imap_source);
	$domain->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);
	return $domain;
}

/** A store-mode alias on $domain with a grant for $user_id. */
function isb_mailbox(InboundEmailDomain $domain, string $local, int $user_id): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_is_enabled', true);
	$alias->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);

	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
	$grant->set('ieg_usr_user_id', $user_id);
	$grant->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);
	return $alias;
}

// A user to hold grants. Content tables are empty on the test DB; make one.
$user = new User(NULL);
$user->set('usr_email', 'isb-' . $tag . '@outside.example');
$user->set('usr_first_name', 'Boundary');
$user->set('usr_last_name', 'Fixture');
$user->set('usr_password', 'x');
$user->save();
harness_register_row('usr_users', 'usr_user_id', (int)$user->key);
$user_id = (int)$user->key;

$imap_name    = 'isb-imap-' . $tag . '.example';
$hosted_name  = 'isb-hosted-' . $tag . '.example';
$paused_name  = 'isb-paused-' . $tag . '.example';

// Snapshot BEFORE creating rows: does the environment already hold a
// receiving (non-IMAP) domain? receive_mode assertions below are relative to
// this, so leftovers from other suites cannot fail them.
$pre = new MultiInboundEmailDomain(array('deleted' => false));
$pre->load();
$pre_receiving = false;
foreach ($pre as $d) {
	if (!$d->is_imap_source()) { $pre_receiving = true; break; }
}

$imap   = isb_domain($imap_name, true);
$hosted = isb_domain($hosted_name, false);
$paused = isb_domain($paused_name, false, false);

section('is_authoritative() is the distinction, stated once');
check($imap->is_imap_source(), 'the anchor row knows it is an IMAP source');
check(!$imap->is_authoritative(), 'an IMAP-source domain is never authoritative');
check($hosted->is_authoritative(), 'an enabled hosted domain is authoritative');
check(!$hosted->is_imap_source(), 'a hosted domain is not an IMAP source');
check(!$paused->is_authoritative(), 'a disabled hosted domain is not authoritative');

section('Hosted-address guards: one connected Gmail blocks nobody');
check(!InboundEmailDomain::isHostedEmailAddress('anyone@' . $imap_name),
	'an address on an IMAP-source domain is not hosted here',
	'this is the guard behind registration and the recovery address');
check(InboundEmailDomain::isHostedEmailAddress('someone@' . $hosted_name),
	'an address on a hosted domain still answers hosted');
check(InboundEmailDomain::isHostedEmailAddress('someone@' . $paused_name),
	'a DISABLED hosted domain still answers hosted — re-enabling restores the circularity');
check(!InboundEmailDomain::isHostedEmailAddress('someone@isb-nowhere-' . $tag . '.example'),
	'an unregistered domain is not hosted');

isb_mailbox($imap, 'isb-feed', $user_id);
$names = InboundEmailDomain::userHostedDomainNames($user_id);
check(!in_array($imap_name, $names, true),
	'a grant on an IMAP-source mailbox contributes no hosted domain');
isb_mailbox($hosted, 'isb-local', $user_id);
$names = InboundEmailDomain::userHostedDomainNames($user_id);
check(in_array($hosted_name, $names, true),
	'a grant on a hosted mailbox still contributes its domain');

section('Direct signing identity: refused at the mint');
DirectSigningIdentity::resetForTests();
$refused = false;
try {
	DirectSigningIdentity::ensureFor($imap_name);
} catch (RuntimeException $e) {
	$refused = (stripos($e->getMessage(), 'not authoritative') !== false);
}
check($refused, 'ensureFor() refuses an IMAP-source domain, naming the reason',
	'guarded at the mint so no future caller can reintroduce one');
check(!DirectSigningIdentity::hasIdentity($imap_name),
	'no identity row exists for the IMAP-source domain after the refusal');

$minted = DirectSigningIdentity::ensureFor($hosted_name);
harness_register_row('jdi_direct_identities', 'jdi_direct_identity_id', (int)$minted->key);
check($minted->key > 0, 'a hosted domain still mints a signing identity');

section('Messenger and Direct resolution: not local, not hosted');
if (class_exists('MessengerFederation')) {
	check(!MessengerFederation::localDomain($imap_name),
		'an IMAP-source domain is not local to the messenger',
		'its provider addresses must resolve over the wire, not be swallowed');
	check(MessengerFederation::localDomain($hosted_name),
		'an enabled hosted domain is local to the messenger');
	check(!MessengerFederation::localDomain($paused_name),
		'a disabled hosted domain is not local');
} else {
	check(true, 'messenger plugin inactive here — localDomain covered elsewhere');
}

check(MailboxDirectConsumer::resolveAddress('anyone@' . $imap_name) === null,
	'no Direct authority is claimed for an address on an IMAP-source domain');
$resolved = MailboxDirectConsumer::resolveAddress('anyone@' . $hosted_name);
check(is_array($resolved) && $resolved['hosts_domain'] === true,
	'a hosted domain still answers Direct resolution');

section('Setup checks and receive mode have nothing to say');
$checker = new InboundEmailSetupCheck();
check($checker->runDomainChecks($imap_name) === array(),
	'runDomainChecks() answers nothing for an IMAP-source domain',
	'no DNS grading, no Direct plan, no machine-sender card, no identity mint');
check(!DirectSigningIdentity::hasIdentity($imap_name),
	'and the check run minted no identity as a side effect');

if (!$pre_receiving) {
	// Only our rows exist: with the hosted rows soft-deleted, the IMAP anchor
	// alone must read as "no receiving domain yet".
	$hosted->set('ied_delete_time', gmdate('Y-m-d H:i:s'));
	$hosted->save();
	$paused->set('ied_delete_time', gmdate('Y-m-d H:i:s'));
	$paused->save();
	$imap_only_mode = mailbox_receive_mode();
	check($imap_only_mode === mailbox_receive_mode_resolve(mailbox_receive_relay_exists(),
			(string)Globalvars::get_instance()->get_setting('mailbox_receive_mode'), false),
		'an IMAP-source row alone does not decide the receive topology');
	$hosted->set('ied_delete_time', NULL);
	$hosted->save();
	$paused->set('ied_delete_time', NULL);
	$paused->save();
} else {
	check(true, 'pre-existing receiving domains present — receive-mode delta not assertable here');
}

section('Transport: a connected mailbox never leaks to platform egress');
require_once(PathHelper::getIncludePath('includes/OutboundTransport.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
$refusal = OutboundTransport::forHostedAlias('isb-feed@' . $imap_name);
check($refusal->error !== null && stripos($refusal->error, 'connected account') !== false,
	'forHostedAlias() refuses an IMAP-source address, naming the connected account',
	'the backstop: even a caller that skips the preflight cannot reach platform egress');
check($refusal->transport === null, 'and configures no transport for it');
$hosted_ok = OutboundTransport::forHostedAlias('isb-local@' . $hosted_name);
check($hosted_ok->error === null, 'a hosted alias still resolves a transport');

$feed_alias = null;
$feed_aliases = new MultiInboundEmailAlias(array('domain_id' => (int)$imap->key, 'deleted' => false));
foreach ($feed_aliases as $candidate) {
	if ($candidate->get('iea_alias') === 'isb-feed') { $feed_alias = $candidate; break; }
}
check($feed_alias !== null, 'the IMAP-source mailbox fixture exists');

$cap = MailboxSender::sendCapabilityFor($feed_alias);
check(!$cap['ok'] && stripos((string)$cap['error'], 'no connected account') !== false,
	'a pulled-in mailbox with no feed at all cannot send, and says so');

// A feed in the state every feed is born in: disabled.
$feed = new InboundImapAccount(NULL);
$feed->set('iia_provider_key', 'gmail');
$feed->set('iia_iea_inbound_email_alias_id', (int)$feed_alias->key);
$feed->set('iia_username', 'isb-feed@' . $imap_name);
$feed->set('iia_label', 'isb fixture');
$feed->set('iia_imap_host', 'imap.gmail.com');
$feed->set('iia_imap_port', 993);
$feed->set('iia_imap_encryption', InboundImapAccount::ENC_SSL);
$feed->set('iia_auth_method', InboundImapAccount::AUTH_PASSWORD);
$feed->set('iia_is_enabled', false);
$feed->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$feed->key);

$cap = MailboxSender::sendCapabilityFor($feed_alias);
check(!$cap['ok'] && stripos((string)$cap['error'], 'currently disabled') !== false,
	'a disabled feed is named as the reason the mailbox cannot send',
	'the same words the compose banner shows and the send refuses with');
check($cap['transport'] === null, 'no transport is configured for a disabled feed — no fallthrough');

// The Setup tab's Sending row reads the same answer.
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));
$scoped = mailbox_setup_scoped_rows((int)$feed_alias->key);
$sending = null;
foreach ((array)($scoped['forwarding'] ?? array()) as $row) {
	if ($row['id'] === 'imap.sending') { $sending = $row; break; }
}
check($sending !== null && $sending['status'] === InboundEmailSetupCheck::WARN,
	'the Setup tab shows a Sending row for the pulled-in mailbox, amber while the feed is off');
check(mailbox_setup_verdict($scoped)['status'] === 'attention',
	'so the mailbox verdict does not read green');

section('Wizard receiving list: a connected account is the arrangement');
require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));
$rows = array();
foreach (SetupSteps::receivingMailboxes() as $row) {
	$rows[$row['address']] = $row;
}
$feed_address = 'isb-feed@' . $imap_name;
check(isset($rows[$feed_address]) && $rows[$feed_address]['connected'] && !$rows[$feed_address]['ok']
		&& $rows[$feed_address]['note'] === 'connection paused',
	'a connected account with its feed off reads "connection paused", never "waiting for DNS"');
$feed->set('iia_is_enabled', true);
$feed->save();
$rows = array();
foreach (SetupSteps::receivingMailboxes() as $row) {
	$rows[$row['address']] = $row;
}
check(isset($rows[$feed_address]) && $rows[$feed_address]['ok']
		&& $rows[$feed_address]['note'] === 'connected account',
	'with the feed on it reads "connected account" and counts as receiving');
$hosted_address = 'isb-local@' . $hosted_name;
check(isset($rows[$hosted_address]) && !$rows[$hosted_address]['connected']
		&& $rows[$hosted_address]['note'] === 'waiting for DNS',
	'a hosted mailbox without a DNS verdict still waits for DNS');

section('Provisioning: a plus-tagged address is refused, leaving nothing behind');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/provisioning.php'));
$plus_domain = 'isb-plus-' . $tag . '.example';
$plus = mailbox_provision_mailbox($plus_domain, 'someone+tag', $user_id, true);
check($plus['error'] !== null && strpos($plus['error'], 'someone@' . $plus_domain) !== false,
	'a plus-tagged connect is refused and names the base address to connect instead');
check(InboundEmailDomain::GetByDomain($plus_domain) === false,
	'and no provider domain row was created for the refused connect');

harness_finish();
