<?php
/**
 * Phase 3 gate fixtures (phase3_gate.sh): the mailbox-granted fixture user
 * and the local sender alias the mail leg replies to. Idempotent — safe to
 * run every gate invocation.
 *
 * Usage:
 *   php phase3_fixtures.php ensure <user_email> <sender_local_part>
 *
 * The user's own mailbox alias is derived from the local part of
 * <user_email> (a store-mode alias on the live inbound domain, created by
 * the Phase 2 setup). This script:
 *   1. grants the user that mailbox (ieg_inbound_email_mailbox_grants)
 *   2. ensures a second store-mode alias <sender_local_part> on the same
 *      domain, so the in-app reply is delivered locally and verifiable in
 *      iem_inbound_email_messages
 *
 * Prints "grant=<id> sender_alias=<id>" on success.
 *
 * @version 1.0.0
 */

require_once('/var/www/html/joinerytest/public_html/tests/functional/api/api_test_harness.php');
harness_require_debug_mode();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

$cmd = $argv[1] ?? '';
if ($cmd !== 'ensure' || !isset($argv[2], $argv[3])) {
	fwrite(STDERR, "usage: php phase3_fixtures.php ensure <user_email> <sender_local_part>\n");
	exit(1);
}
$user_email = $argv[2];
$sender_local = $argv[3];

$user = User::GetByEmail($user_email);
if (!$user || !$user->key) {
	fwrite(STDERR, "no user for $user_email\n");
	exit(1);
}

list($mailbox_local, $domain_name) = explode('@', $user_email, 2);

// Resolve the inbound domain + the user's mailbox alias.
$dblink = DbConnector::get_instance()->get_db_link();
$q = $dblink->prepare("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains WHERE ied_domain = ? AND ied_delete_time IS NULL");
$q->execute([$domain_name]);
$domain_id = (int)$q->fetchColumn();
if (!$domain_id) {
	fwrite(STDERR, "no inbound domain row for $domain_name\n");
	exit(1);
}

function find_alias_id($dblink, $domain_id, $local) {
	$q = $dblink->prepare("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
		WHERE iea_ied_inbound_email_domain_id = ? AND iea_alias = ? AND iea_delete_time IS NULL");
	$q->execute([$domain_id, $local]);
	return (int)$q->fetchColumn();
}

$mailbox_alias_id = find_alias_id($dblink, $domain_id, $mailbox_local);
if (!$mailbox_alias_id) {
	fwrite(STDERR, "no alias '$mailbox_local' on domain $domain_name (Phase 2 setup creates it)\n");
	exit(1);
}

// 1. Mailbox grant (user ↔ their alias), if missing.
$grants = new MultiInboundEmailMailboxGrant(array(
	'user_id'  => $user->key,
	'alias_id' => $mailbox_alias_id,
));
$grants->load();
$grant_id = 0;
foreach ($grants as $g) {
	$grant_id = $g->key;
	break;
}
if (!$grant_id) {
	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', $mailbox_alias_id);
	$grant->set('ieg_usr_user_id', $user->key);
	$grant->save();
	$grant_id = $grant->key;
}

// 2. Sender alias (store mode), if missing.
$sender_alias_id = find_alias_id($dblink, $domain_id, $sender_local);
if (!$sender_alias_id) {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $sender_local);
	$alias->set('iea_destinations', '');
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_is_enabled', TRUE);
	$alias->set('iea_description', 'Phase 3 iOS gate reply target');
	$alias->save();
	$sender_alias_id = $alias->key;
}

echo "grant=$grant_id sender_alias=$sender_alias_id\n";
?>
