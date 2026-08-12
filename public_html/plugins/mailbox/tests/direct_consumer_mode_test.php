<?php
/** @joinery-test
 * name: direct_consumer_mode
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Which mailboxes accept Joinery Direct, by delivery mode.
 *
 * Direct is a STORE channel, never a forwarding one: a Direct message never
 * becomes a MIME document, so there is nothing to relay onward. A store-only
 * mailbox is therefore a Direct recipient; a forward-only one plainly is not.
 * The trap is forward-AND-store: accepting Direct there would store the copy and
 * drop the forward silently — a partial delivery the sender never sees. So a
 * forwarding alias of EITHER kind resolves as "not here", the sender is declined
 * (or handed a decoy at a sealed tier) and falls back to SMTP, which does both
 * halves. This pins that rule.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDirectConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

$suffix = substr(md5(uniqid('dcm', true)), 0, 8);
$domain_name = 'dcm-test-' . $suffix . '.example';

mailbox_purge_domains('dcm-test-%');
harness_defer(function () { mailbox_purge_domains('dcm-test-%'); });

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();
$domain_id = intval($domain->key);

function dcm_make_alias(int $domain_id, string $local, string $mode): void {
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
}

dcm_make_alias($domain_id, 'keep',      InboundEmailAlias::MODE_STORE);
dcm_make_alias($domain_id, 'both',      InboundEmailAlias::MODE_FORWARD_AND_STORE);
dcm_make_alias($domain_id, 'elsewhere', InboundEmailAlias::MODE_FORWARD);

// ---------------------------------------------------------------------------
section('A store-only mailbox is a Direct recipient');
// ---------------------------------------------------------------------------

$store = MailboxDirectConsumer::resolveAddress('keep@' . $domain_name);
check($store !== null && $store['hosts_domain'] === true, 'the domain is hosted here');
check($store['exists'] === true, 'a store-only alias exists as a Direct recipient');
check((int)$store['alias_id'] > 0, 'and resolves to its alias');

// ---------------------------------------------------------------------------
section('A forwarding mailbox is NOT a Direct recipient, so the sender uses SMTP');
// ---------------------------------------------------------------------------

$both = MailboxDirectConsumer::resolveAddress('both@' . $domain_name);
check($both !== null && $both['hosts_domain'] === true,
	'the domain still answers — the refusal is about this mailbox, not the deployment');
check($both['exists'] === false,
	'a forward-and-store alias does NOT accept Direct — Direct would store but silently drop the forward');
check((int)$both['alias_id'] === 0, 'so no alias identity is handed back; it looks absent, like a decoy');

$fwd = MailboxDirectConsumer::resolveAddress('elsewhere@' . $domain_name);
check($fwd !== null && $fwd['exists'] === false,
	'a forward-only alias is not a Direct recipient either');

// ---------------------------------------------------------------------------
section('An unknown local part looks the same as a forwarding one — nothing leaks');
// ---------------------------------------------------------------------------

$absent = MailboxDirectConsumer::resolveAddress('nobody@' . $domain_name);
check($absent !== null && $absent['hosts_domain'] === true && $absent['exists'] === false,
	'a nonexistent address and a forwarding one both answer "hosted, absent" — indistinguishable');

harness_finish();
