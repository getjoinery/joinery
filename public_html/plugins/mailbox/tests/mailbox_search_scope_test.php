<?php
/** @joinery-test
 * name: mailbox_search_scope
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A search over a sealed mailbox finds sealed mail in EVERY scope the viewer's
 * index covers — the "all mailboxes" view the reader opens on included.
 *
 * The bug this pins: MailboxService::listThreads() consulted the sealed FTS
 * index only when the scope was a single mailbox with a single vault-holding
 * owner. The reader opens on all mailboxes, where the search fell through to
 * the Postgres tsvector expression over columns that are ciphertext on a
 * sealed row, so a member whose whole archive was sealed searched "new
 * orleans" and got 0 of 236 hits. The index is per viewer and covers every
 * mailbox the viewer holds a grant for, so it can answer for any scope.
 *
 * What is pinned:
 *  - locked: the response says search_locked in the all-mailboxes scope, not
 *    just the single-mailbox one, and the unsealed part of the scope still
 *    answers through Postgres under the prompt;
 *  - unlocked: all-mailboxes finds the sealed hit; each single mailbox finds
 *    only its own; a mailbox the viewer holds unsealed is found either way;
 *  - the index is the viewer's, not the mailbox owner's: an all-access viewer
 *    with no grant and no vault searches Postgres only and is never told to
 *    unlock.
 *
 * Run: php -d apc.enable_cli=1 plugins/mailbox/tests/mailbox_search_scope_test.php
 *
 * @version 1.1 - list rows: senders across the thread, snippet from the newest body, HTML only without a plain part
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process',
		'run manually: php -d apc.enable_cli=1 plugins/mailbox/tests/mailbox_search_scope_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}
if (!is_dir(MailboxIndex::SHM_DIR)) {
	harness_skip('index working copies', MailboxIndex::SHM_DIR . ' unavailable');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();

// ---- fixtures ------------------------------------------------------------
$owner = make_user('SearchScopeOwner', 5);
$owner_id = (int)$owner->key;
$admin = make_user('SearchScopeAdmin', 10);
$admin_id = (int)$admin->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'sscope-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->set('ied_owner_usr_user_id', $owner_id);
$domain->save();
$domain_id = (int)$domain->key;
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $domain_id);

$make_alias = function ($local) use ($domain_id) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
};
$sealed_alias = $make_alias('sealed');
$plain_alias  = $make_alias('plain');
foreach (array($sealed_alias, $plain_alias) as $alias_id) {
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', $alias_id);
	$g->set('ieg_usr_user_id', $owner_id);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
}

$make_msg = function ($alias_id, $thread_key, $subject, $body) use ($domain_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', 'sealed@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '');
	$m->set('iem_thread_key', $thread_key);
	$m->set('iem_message_id_header', 'sscope-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

// The owner's vault, with a real keypair so content seals and the window opens.
$owner_kp = sodium_crypto_box_keypair();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $owner_id);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($owner_kp)));
$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
$vault->set('uev_key_generation', 1);
$vault->save();
$vault->load();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);
$secret_b64 = SealedBox::b64url(sodium_crypto_box_secretkey($owner_kp));

// One sealed message whose only searchable copy is inside the index, and one
// plain message in the other mailbox that Postgres can see.
$m_sealed = $make_msg($sealed_alias, '<sealed@x>', 'placeholder', 'placeholder');
InboundEmailMessage::sealAndPersistContent($m_sealed, $vault, 'sender@example.com',
	'sealed@example.com', 'Trip to New Orleans', 'orleanskw in the sealed body', '');
$m_plain = $make_msg($plain_alias, '<plain@x>', 'Plain note', 'orleanskw in the plain body');

// A second, older message in the sealed thread from someone else: the list
// row names every sender in the thread but shows only the newest body.
$m_sealed_older = $make_msg($sealed_alias, '<sealed@x>', 'placeholder', 'placeholder');
InboundEmailMessage::sealAndPersistContent($m_sealed_older, $vault, 'colleague@example.com',
	'sealed@example.com', 'Re: Trip', 'orleanskw earlier in the thread', '');
$db->prepare('UPDATE iem_inbound_email_messages SET iem_received_time = iem_received_time - interval \'1 hour\'
	WHERE iem_inbound_email_message_id = ?')->execute(array($m_sealed_older));

// A sealed message with an HTML body and no plain part: its snippet has to
// come from the HTML, which the list opens only for exactly this case.
$m_html = $make_msg($sealed_alias, '<htmlonly@x>', 'placeholder', 'placeholder');
InboundEmailMessage::sealAndPersistContent($m_html, $vault, 'sender@example.com',
	'sealed@example.com', 'HTML only', '', '<p>htmlonlykw inside markup</p>');

$sealed_col = $db->prepare('SELECT iem_content_sealed, iem_body_plain FROM iem_inbound_email_messages
	WHERE iem_inbound_email_message_id = ?');
$sealed_col->execute(array($m_sealed));
$sealed_row = $sealed_col->fetch(PDO::FETCH_ASSOC);
check(in_array($sealed_row['iem_content_sealed'], array(true, 't', 'true', '1', 1), true),
	'the sealed message is sealed at rest');
check(strpos((string)$sealed_row['iem_body_plain'], 'orleanskw') === false,
	'and its body column holds no plaintext for Postgres to match');

$idx = new MailboxIndex();
$idx->wipe($owner_id);
InboundMailboxSearchIndex::loadOrCreateForUser($owner_id)->set('imi_fts_high_water', 0);

$svc = new MailboxService(MailboxViewer::forUser($owner_id, 5));
$admin_svc = new MailboxService(MailboxViewer::forUser($admin_id, 10));
$keys = function ($result) {
	$out = array();
	foreach ($result['threads'] as $t) { $out[] = $t['thread_key']; }
	sort($out);
	return $out;
};

try {
	// =====================================================================
	section('locked: every scope with sealed content says so');

	check(VaultUnlock::secretKey($owner_id) === null, 'no unlock window is open');
	$all = $svc->listThreads(null, array('q' => 'orleanskw'));
	check(!empty($all['search_locked']), 'the all-mailboxes search reports search_locked');
	check($keys($all) === array('<plain@x>'),
		'and still returns the unsealed hit under the prompt', json_encode($keys($all)));

	$one = $svc->listThreads($sealed_alias, array('q' => 'orleanskw'));
	check(!empty($one['search_locked']), 'the sealed mailbox alone reports search_locked');
	check($keys($one) === array(), 'with nothing to show', json_encode($keys($one)));

	$plain_only = $svc->listThreads($plain_alias, array('q' => 'orleanskw'));
	check(empty($plain_only['search_locked']),
		'a mailbox with no sealed content never asks for an unlock');
	check($keys($plain_only) === array('<plain@x>'), 'and answers from Postgres', json_encode($keys($plain_only)));

	// =====================================================================
	section('unlocked: the index answers for every mailbox the viewer holds');

	VaultUnlock::open($owner_id, $secret_b64);
	check(VaultUnlock::secretKey($owner_id) === $secret_b64, 'the window is open');

	$all = $svc->listThreads(null, array('q' => 'orleanskw'));
	check(empty($all['search_locked']), 'the all-mailboxes search is not locked');
	check($keys($all) === array('<plain@x>', '<sealed@x>'),
		'and finds the sealed hit alongside the plain one', json_encode($keys($all)));

	$subject_hit = $svc->listThreads(null, array('q' => 'new orleans'));
	check($keys($subject_hit) === array('<sealed@x>'),
		'a two-word query matches the sealed subject', json_encode($keys($subject_hit)));

	// =====================================================================
	section('a list row opens only what it shows');

	$row = null;
	foreach ($svc->listThreads(null, array('q' => 'orleanskw'))['threads'] as $t) {
		if ($t['thread_key'] === '<sealed@x>') { $row = $t; }
	}
	check($row !== null, 'the sealed thread is listed');
	check($row && $row['subject'] === 'Trip to New Orleans', 'with the newest message\'s subject', json_encode($row['subject'] ?? null));
	check($row && strpos($row['snippet'], 'orleanskw in the sealed body') !== false,
		'and its snippet from the newest plain body', json_encode($row['snippet'] ?? null));
	check($row && $row['senders'] === 'sender@example.com, colleague@example.com',
		'and every sender in the thread', json_encode($row['senders'] ?? null));
	check($row && intval($row['msg_count']) === 2, 'across both messages');

	$html_row = null;
	foreach ($svc->listThreads(null, array('q' => 'htmlonlykw'))['threads'] as $t) {
		if ($t['thread_key'] === '<htmlonly@x>') { $html_row = $t; }
	}
	check($html_row !== null, 'an HTML-only sealed message is found');
	check($html_row && strpos($html_row['snippet'], 'htmlonlykw inside markup') !== false,
		'and its snippet is read from the HTML when there is no plain part', json_encode($html_row['snippet'] ?? null));

	check($keys($svc->listThreads($sealed_alias, array('q' => 'orleanskw'))) === array('<sealed@x>'),
		'the sealed mailbox alone finds only its own message');
	check($keys($svc->listThreads($plain_alias, array('q' => 'orleanskw'))) === array('<plain@x>'),
		'the plain mailbox alone finds only its own');
	check($keys($svc->listThreads(null, array('q' => 'orleanskw', 'inbox' => true))) === array('<plain@x>', '<sealed@x>'),
		'a search typed on the Inbox tab covers the same set');

	// =====================================================================
	section('the index is the viewer\'s, not the mailbox owner\'s');

	$admin_all = $admin_svc->listThreads(null, array('q' => 'orleanskw'));
	check(empty($admin_all['search_locked']),
		'an all-access viewer with no vault is never told to unlock');
	check($keys($admin_all) === array('<plain@x>'),
		'and sees the unsealed hit through Postgres, never the sealed one', json_encode($keys($admin_all)));
	$admin_one = $admin_svc->listThreads($sealed_alias, array('q' => 'orleanskw'));
	check(empty($admin_one['search_locked']) && $keys($admin_one) === array(),
		'the sealed mailbox is ciphertext to them in every scope', json_encode($admin_one));
} finally {
	VaultUnlock::resetForTests();
	$idx->wipe($owner_id);
}

harness_finish();
