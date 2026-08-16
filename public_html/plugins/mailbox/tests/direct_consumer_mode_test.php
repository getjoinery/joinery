<?php
/** @joinery-test
 * name: direct_consumer_mode
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Recipient identity vs per-kind deliverability, by delivery mode.
 *
 * `exists` is an IDENTITY fact: any live alias is an addressable recipient,
 * whatever its email routing. What a kind needs from that identity is the kind's
 * own declared requirement, judged by the framework: mail requires `email_store`
 * (a Direct payload never becomes MIME, so a forwarding leg cannot run — the
 * decline sends mail back to SMTP, which runs both legs), while chat requires
 * only a consenting `owner`, so a forwarding alias with a single grantee chats
 * fine. This pins the resolver's facts and the requirement vocabulary those
 * facts feed.
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDirectConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));

$suffix = substr(md5(uniqid('dcm', true)), 0, 8);
$domain_name = 'dcm-test-' . $suffix . '.example';

mailbox_purge_domains('dcm-test-%');
harness_defer(function () { mailbox_purge_domains('dcm-test-%'); });

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();
$domain_id = intval($domain->key);

function dcm_make_alias(int $domain_id, string $local, string $mode): int {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', $mode);
	if ($mode !== InboundEmailAlias::MODE_STORE) {
		$a->set('iea_destinations', 'dest@example.test');
	}
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	return intval($a->key);
}

dcm_make_alias($domain_id, 'keep',      InboundEmailAlias::MODE_STORE);
$both_id = dcm_make_alias($domain_id, 'both', InboundEmailAlias::MODE_FORWARD_AND_STORE);
dcm_make_alias($domain_id, 'elsewhere', InboundEmailAlias::MODE_FORWARD);

// ---------------------------------------------------------------------------
section('Every live alias is an addressable identity; only store-only stores email');
// ---------------------------------------------------------------------------

$store = MailboxDirectConsumer::resolveAddress('keep@' . $domain_name);
check($store !== null && $store['hosts_domain'] === true, 'the domain is hosted here');
check($store['exists'] === true, 'a store-only alias exists');
check($store['stores_email'] === true, 'and stores email locally with no forwarding leg');
check((int)$store['alias_id'] > 0, 'and resolves to its alias');

$both = MailboxDirectConsumer::resolveAddress('both@' . $domain_name);
check($both !== null && $both['exists'] === true,
	'a forward-and-store alias is still an addressable identity — its routing is not its existence');
check($both['stores_email'] === false,
	'but it does not qualify as an email store: Direct mail there would keep the copy and silently drop the forward');
check((int)$both['alias_id'] === $both_id, 'its alias identity is handed back');

$fwd = MailboxDirectConsumer::resolveAddress('elsewhere@' . $domain_name);
check($fwd !== null && $fwd['exists'] === true && $fwd['stores_email'] === false,
	'a forward-only alias: same shape — an identity that stores no email');

$absent = MailboxDirectConsumer::resolveAddress('nobody@' . $domain_name);
check($absent !== null && $absent['hosts_domain'] === true && $absent['exists'] === false,
	'an unknown local part answers "hosted, absent" — the domain still speaks, the address does not');

// ---------------------------------------------------------------------------
section('A single grantee makes the alias a consenting owner, whatever its mode');
// ---------------------------------------------------------------------------

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $both_id);
$grant->set('ieg_usr_user_id', 1);
$grant->save();

$owned = MailboxDirectConsumer::resolveAddress('both@' . $domain_name);
check((int)$owned['user_id'] === 1,
	'the forwarding alias now names its owner — chat has a person to land on');
check($owned['stores_email'] === false, 'while its email routing is unchanged');

// ---------------------------------------------------------------------------
section('The requirement vocabulary reads exactly those facts');
// ---------------------------------------------------------------------------

check(DirectKinds::recipientMeets('', $owned) === true,
	'no declared requirement accepts any existing recipient');
check(DirectKinds::recipientMeets(DirectKinds::RECIPIENT_OWNER, $owned) === true,
	'chat\'s owner requirement is met by the single-grantee forwarding alias');
check(DirectKinds::recipientMeets(DirectKinds::RECIPIENT_EMAIL_STORE, $owned) === false,
	'mail\'s email_store requirement is not — mail declines and falls back to SMTP, which runs both legs');
check(DirectKinds::recipientMeets(DirectKinds::RECIPIENT_EMAIL_STORE, $store) === true,
	'and is met by the store-only alias');
check(DirectKinds::recipientMeets(DirectKinds::RECIPIENT_OWNER, $store) === false,
	'which, grantless, is no one\'s chat address');
check(DirectKinds::recipientMeets('made_up_word', $owned) === false,
	'an unknown requirement word never means "anyone"');

// ---------------------------------------------------------------------------
section('The shipped declarations carry the requirements');
// ---------------------------------------------------------------------------

$mail_decl = DirectKinds::declaration('mail');
check($mail_decl !== null && $mail_decl['recipient'] === DirectKinds::RECIPIENT_EMAIL_STORE,
	'mail declares email_store');
$chat_decl = DirectKinds::declaration('chat');
if ($chat_decl !== null) {
	check($chat_decl['recipient'] === DirectKinds::RECIPIENT_OWNER,
		'chat declares owner');
} else {
	check(true, 'messenger plugin inactive here; chat declaration not asserted');
}

harness_finish();
