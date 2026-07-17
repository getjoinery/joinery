<?php
/** @joinery-test
 * name: sender_context
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity Phase 5 — member-context panel (specs/mailbox_compose_maturity.md
 * § Phase 5).
 *
 * Covers:
 *  - Permission gate: a non-admin (level < 5) is refused.
 *  - Resolution hit/miss: a message from a member email resolves the member card;
 *    a non-member email → is_member:false.
 *  - No-oracle + scope: the input is a message id (never an address), and a message
 *    outside the caller's mailbox scope is refused.
 *  - Plugin sections track PluginHelper::isPluginActive.
 *
 * Sessions are simulated with SessionControl::set_api_user (the same mechanism the API
 * dispatcher uses), so the logic runs exactly as it would behind /api/v1.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/sender_context_logic.php'));

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

$g = new InboundEmailMailboxGrant(NULL);
$g->set('ieg_iea_inbound_email_alias_id', $alias);
$g->set('ieg_usr_user_id', $admin_uid);
$g->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);

$mk_msg = function ($alias_id, $sender, $mid) use ($domain) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', $sender);
	$m->set('iem_recipient', 'inbox@x');
	$m->set('iem_subject', 's');
	$m->set('iem_message_id_header', $mid);
	$m->set('iem_thread_key', $mid);
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	return (int)$m->key;
};
$member_msg = $mk_msg($alias, 'Member Person <' . $member_email . '>', '<ctx-mem@x>');
$stranger_msg = $mk_msg($alias, 'nobody-' . bin2hex(random_bytes(3)) . '@stranger.example', '<ctx-str@x>');
$other_msg = $mk_msg($other_alias, 'Member Person <' . $member_email . '>', '<ctx-oth@x>');

// ── Permission gate ──────────────────────────────────────────────────────────
section('Permission gate');
$r = $run($plain_uid, $member_msg);
check($r->error !== null, 'a non-admin (level < 5) is refused', json_encode($r->data));

// ── Resolution — member match ────────────────────────────────────────────────
section('Resolution — member');
$r = $run($admin_uid, $member_msg);
check($r->error === null, 'an admin gets a result', (string)$r->error);
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
$r = $run($admin_uid, 0);
check($r->error !== null, 'a missing message id is refused (input is a message id, never an address)');

harness_finish();
