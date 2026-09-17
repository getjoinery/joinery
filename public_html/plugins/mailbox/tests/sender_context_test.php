<?php
/** @joinery-test
 * name: sender_context
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity Phase 5 — contact panel (specs/mailbox_compose_maturity.md § Phase 5).
 *
 * Covers:
 *  - Permission split: every mailbox grantee gets the contact half (address, display
 *    name, their own contact-store entry); the site-account half — the member card,
 *    orders, registrations — is admins only, and `account_visible` says which they got.
 *  - Resolution hit/miss: a message from a member email resolves the member card;
 *    a non-member email → is_member:false.
 *  - No-oracle + scope: the input is a message id (never an address), and a message
 *    outside the caller's mailbox scope is refused — admin or not.
 *  - Plugin sections track PluginHelper::isPluginActive.
 *  - `others`: everyone else on the message (To + Cc) minus the mailbox itself and the
 *    counterparty, each with their own contact-store answer; a sent message also drops
 *    its sender (the mailbox again).
 *
 * Sessions are simulated with SessionControl::set_api_user (the same mechanism the API
 * dispatcher uses), so the logic runs exactly as it would behind /api/v1.
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/sender_context_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();

// Run the logic with a simulated session for $actor_uid.
$run = function ($actor_uid, $message_id) use ($session) {
	$session->set_api_user($actor_uid);
	try {
		return sender_context_logic(array('message_id' => $message_id));
	} finally {
		$session->clear_api_user();
	}
};

// ── Fixtures ─────────────────────────────────────────────────────────────────
$admin = make_user('CtxAdmin', 5);          // admin, not superadmin (perm 5 < 10)
$plain = make_user('CtxPlain', 0);          // non-admin
$admin_uid = (int)$admin->key; $plain_uid = (int)$plain->key;

// A "member" the mail is from — a real user with a known email.
$member = make_user('CtxMember', 0);
$member_uid = (int)$member->key;
$member_email = (string)$member->get('usr_email');

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'ctx-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$mk_alias = function ($local) use ($domain) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
};
$alias = $mk_alias('inbox');       // admin is granted this one
$other_alias = $mk_alias('other'); // admin is NOT granted this one

$mk_grant = function ($uid) use ($alias) {
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', $alias);
	$g->set('ieg_usr_user_id', $uid);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
};
$mk_grant($admin_uid);
$mk_grant($plain_uid);   // a non-admin mailbox grantee — gets the contact half only

$mk_msg = function ($alias_id, $sender, $mid, $extra = array()) use ($domain) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', $sender);
	$m->set('iem_recipient', 'inbox@x');
	$m->set('iem_subject', 's');
	$m->set('iem_message_id_header', $mid);
	$m->set('iem_thread_key', $mid);
	foreach ($extra as $k => $v) { $m->set($k, $v); }
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};
$member_msg = $mk_msg($alias, 'Member Person <' . $member_email . '>', '<ctx-mem@x>');
$stranger_msg = $mk_msg($alias, 'nobody-' . bin2hex(random_bytes(3)) . '@stranger.example', '<ctx-str@x>');
$other_msg = $mk_msg($other_alias, 'Member Person <' . $member_email . '>', '<ctx-oth@x>');

// ── Permission split: contact half for all, account half for admins ──────────
section('Permission split');
$r = $run($plain_uid, $member_msg);
check($r->error === null, 'a non-admin mailbox grantee gets a result', (string)$r->error);
check(($r->data['address'] ?? '') === strtolower($member_email), 'the non-admin gets the contact half (address)', json_encode($r->data['address'] ?? null));
check(array_key_exists('contact', $r->data), 'the non-admin gets their own contact-store entry (or null)');
check(empty($r->data['account_visible']), 'account_visible is false for a non-admin', json_encode($r->data['account_visible'] ?? null));
check(!array_key_exists('member', $r->data), 'the member card is withheld from a non-admin', json_encode(array_keys($r->data)));
check(!array_key_exists('orders', $r->data) && !array_key_exists('registrations', $r->data),
	'orders and registrations are withheld from a non-admin');
check(empty($r->data['is_member']), 'a non-admin is told nothing about membership either way');

// ── Resolution — member match ────────────────────────────────────────────────
section('Resolution — member');
$r = $run($admin_uid, $member_msg);
check($r->error === null, 'an admin gets a result', (string)$r->error);
check(!empty($r->data['account_visible']), 'account_visible is true for an admin');
check(!empty($r->data['is_member']), 'the sender resolves to a member', json_encode($r->data['is_member'] ?? null));
check(($r->data['member']['email'] ?? '') === $member_email, 'member email matches', json_encode($r->data['member']['email'] ?? null));
check(($r->data['member']['user_id'] ?? 0) === $member_uid, 'member user_id matches');
check(strpos((string)($r->data['member']['edit_url'] ?? ''), 'admin_user_edit?usr_user_id=' . $member_uid) !== false,
	'member card links to the admin edit page', (string)($r->data['member']['edit_url'] ?? ''));

// Plugin sections track activation.
if (PluginHelper::isPluginActive('store')) {
	check(array_key_exists('orders', $r->data), 'orders section present when the store plugin is active');
} else {
	check(!array_key_exists('orders', $r->data), 'orders section absent when the store plugin is inactive');
}
if (PluginHelper::isPluginActive('event_manager')) {
	check(array_key_exists('registrations', $r->data), 'registrations section present when event_manager is active');
} else {
	check(!array_key_exists('registrations', $r->data), 'registrations section absent when event_manager is inactive');
}

// ── Resolution — miss ────────────────────────────────────────────────────────
section('Resolution — not a member');
$r = $run($admin_uid, $stranger_msg);
check($r->error === null && empty($r->data['is_member']), 'a non-member email → is_member:false', json_encode($r->data));

// ── No-oracle / scope ────────────────────────────────────────────────────────
section('Scope + no-oracle');
$r = $run($admin_uid, $other_msg);
check($r->error !== null, 'a message in a mailbox the admin cannot access is refused (no cross-mailbox oracle)', json_encode($r->data));
$r = $run($plain_uid, $other_msg);
check($r->error !== null, 'mailbox scope still binds the non-admin (no cross-mailbox oracle)', json_encode($r->data));
$r = $run($admin_uid, 0);
check($r->error !== null, 'a missing message id is refused (input is a message id, never an address)');

// ── Everyone else on the message ─────────────────────────────────────────────
section('Others on the message');
$own_addr = 'inbox@' . $domain->get('ied_domain');
$sender_addr = 'alice-' . bin2hex(random_bytes(3)) . '@stranger.example';
$cc1 = 'cc-one-' . bin2hex(random_bytes(3)) . '@stranger.example';
$cc2 = 'cc-two-' . bin2hex(random_bytes(3)) . '@stranger.example';
$to2 = 'to-two-' . bin2hex(random_bytes(3)) . '@stranger.example';
$cc_msg = $mk_msg($alias, 'Alice <' . $sender_addr . '>', '<ctx-cc@x>', array(
	'iem_to' => '"Our Box" <' . $own_addr . '>, ' . $to2,
	'iem_cc' => '"Cc One" <' . $cc1 . '>, ' . $cc2 . ', ' . $sender_addr . ', ' . strtoupper($cc1),
));
$r = $run($plain_uid, $cc_msg);
check($r->error === null, 'a message with To and Cc lists resolves', (string)$r->error);
check(($r->data['address'] ?? '') === $sender_addr, 'the sender is still the counterparty', json_encode($r->data['address'] ?? null));
$others = $r->data['others'] ?? null;
$listed = is_array($others) ? array_column($others, 'address') : array();
check(is_array($others), 'others is present');
check($listed === array($to2, $cc1, $cc2), 'others lists To then Cc, minus the mailbox, the sender and repeats', json_encode($listed));
$by = array(); foreach ((array)$others as $o) { $by[$o['address']] = $o; }
check(($by[$cc1]['display_name'] ?? '') === 'Cc One', 'a Cc entry keeps its display name', json_encode($by[$cc1] ?? null));
check(($by[$cc1]['field'] ?? '') === 'cc' && ($by[$to2]['field'] ?? '') === 'to', 'each entry says which list it came from');
check(array_key_exists('contact', $by[$cc1]) && $by[$cc1]['contact'] === null, 'a stranger on Cc is not in contacts');

// Keep one of them, and the panel's next read says so.
$contacts = new MailboxContacts();
$kept = $contacts->manualAdd($plain_uid, 'Cc One <' . $cc1 . '>', $alias);
check($kept === true, 'the Cc address can be added to this mailbox\'s contacts');
$r = $run($plain_uid, $cc_msg);
$by = array(); foreach ((array)($r->data['others'] ?? array()) as $o) { $by[$o['address']] = $o; }
check(!empty($by[$cc1]['contact']) && empty($by[$cc1]['contact']['locked']), 'once kept, the Cc entry reads back as a contact', json_encode($by[$cc1] ?? null));
check(isset($by[$cc2]) && $by[$cc2]['contact'] === null, 'the other Cc address is still not a contact');
$stmt = $db->prepare('DELETE FROM imc_mailbox_contacts WHERE imc_usr_user_id = ? AND imc_iea_inbound_email_alias_id = ?');
$stmt->execute(array($plain_uid, $alias));

// A sent message: the counterparty is the first recipient, and the sender (the
// mailbox itself) is never listed among the others.
$sent_msg = $mk_msg($alias, $own_addr, '<ctx-sent@x>', array(
	'iem_direction' => 'outbound',
	'iem_recipient' => $to2 . ', ' . $cc1,
	'iem_to' => $to2,
	'iem_cc' => $cc1 . ', ' . $own_addr,
));
$r = $run($plain_uid, $sent_msg);
check(($r->data['address'] ?? '') === $to2, 'on a sent message the first recipient is the counterparty', json_encode($r->data['address'] ?? null));
check(array_column($r->data['others'] ?? array(), 'address') === array($cc1), 'a sent message lists its Cc, never the mailbox itself', json_encode($r->data['others'] ?? null));

// A message with no lists at all says so with an empty array, not an absence.
$r = $run($plain_uid, $stranger_msg);
check(isset($r->data['others']) && $r->data['others'] === array(), 'a message with no To/Cc lists has an empty others', json_encode($r->data['others'] ?? null));

harness_finish();
