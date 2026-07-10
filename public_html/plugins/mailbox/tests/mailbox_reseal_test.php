<?php
/** @joinery-test
 * name: mailbox_vault_reseal
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));   // files_class (via the mailbox bootstrap) references User at load time
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

$box = new SealedBox();
$crypto = new VaultCrypto();

// The mailbox re-seal callback, via the same registry the rotation uses.
$callbacks = VaultUnlock::resealCallbacks();
check(count($callbacks) >= 1, 'the mailbox bootstrap registered a re-seal callback');
$run_reseal = function (int $uid, string $old_secret, int $old_gen, string $new_pub, int $new_gen) use ($callbacks) {
	foreach ($callbacks as $cb) {
		call_user_func($cb, $uid, $old_secret, $old_gen, $new_pub, $new_gen);
	}
};

// ---- Fixtures ------------------------------------------------------------
$user = make_user('MbReseal');
$uid = (int)$user->key;
$kp1 = $box->generateKeypair();   // generation 1 keypair
$kp2 = $box->generateKeypair();   // generation 2 keypair
$kp3 = $box->generateKeypair();   // generation 3 keypair

// A bare vault row (the callback reads only messages/domains, not wrappings).
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp1['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$domain_name = 'reseal-' . bin2hex(random_bytes(4)) . '.example';
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_owner_usr_user_id', $uid);
$domain->set('ied_is_protected_identity', true);
$domain->set('ied_security_level', 'private');
$dkim_live = 'FAKE-DKIM-LIVE-' . bin2hex(random_bytes(8));
$dkim_pending = 'FAKE-DKIM-PENDING-' . bin2hex(random_bytes(8));
$domain->set('ied_dkim_sealed_key', $crypto->sealItemDek($dkim_live, $kp1['public']));
$domain->set('ied_dkim_pending_sealed_key', $crypto->sealItemDek($dkim_pending, $kp1['public']));
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', 'store');
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
$grant->set('ieg_usr_user_id', $uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

$make_message = function (UserEncryptionVault $seal_vault, string $body) use ($domain, $alias) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$alias->key);
	$m->set('iem_recipient', 'inbox@' . $domain->get('ied_domain'));
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_subject', 'subject');
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '<p>' . $body . '</p>');
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	InboundEmailMessage::sealAndPersistContent((int)$m->key, $seal_vault, 'sender@example.com',
		'inbox@' . $domain->get('ied_domain'), 'subject', $body, '<p>' . $body . '</p>');
	return (int)$m->key;
};

$msg_a = $make_message($vault, 'gen one message A');
$msg_b = $make_message($vault, 'gen one message B');
// A generation-2 straggler: sealed via an in-memory vault view of gen 2.
$vault_g2 = new UserEncryptionVault(NULL);
$vault_g2->set('uev_usr_user_id', $uid);
$vault_g2->set('uev_public_key', $kp2['public']);
$vault_g2->set('uev_key_generation', 2);
$msg_c = $make_message($vault_g2, 'gen two message C');

$read_msg = function (int $id) {
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$stmt->execute([$id]);
	return $stmt->fetch(PDO::FETCH_ASSOC);
};
$open_body = function (array $row, string $secret) use ($crypto) {
	$dek = $crypto->openItemDek($row['iem_sealed_key'], $secret);
	return $crypto->openField($row['iem_body_plain'], $dek, InboundEmailMessage::sealAd((int)$row['iem_inbound_email_message_id'], 'iem_body_plain'));
};

// ---- Re-seal drains exactly the named generation --------------------------
section('Generation-scoped re-seal');
$c_before = $read_msg($msg_c);
$run_reseal($uid, $kp1['secret'], 1, $kp2['public'], 2);

$a = $read_msg($msg_a);
check((int)$a['iem_key_generation'] === 2, 'gen-1 message A moved to generation 2');
check($open_body($a, $kp2['secret']) === 'gen one message A', 'A opens under the new secret with its original content');
$b = $read_msg($msg_b);
check((int)$b['iem_key_generation'] === 2, 'gen-1 message B moved too');
$c = $read_msg($msg_c);
check($c['iem_sealed_key'] === $c_before['iem_sealed_key'], 'the gen-2 straggler was not touched');

section('DKIM keys ride the rotation');
$d = new InboundEmailDomain((int)$domain->key, TRUE);
check($crypto->openItemDek((string)$d->get('ied_dkim_sealed_key'), $kp2['secret']) === $dkim_live, 'live DKIM key re-sealed to the new generation');
check($crypto->openItemDek((string)$d->get('ied_dkim_pending_sealed_key'), $kp2['secret']) === $dkim_pending, 'pending DKIM key re-sealed too');

// ---- Fail-loud: a broken row blocks retirement ----------------------------
section('Fail-loud contract');
$db = DbConnector::get_instance()->get_db_link();
$stmt = $db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_key = ? WHERE iem_inbound_email_message_id = ?');
$stmt->execute([$crypto->sealItemDek(random_bytes(32), $box->generateKeypair()['public']), $msg_b]);
$threw = false;
try { $run_reseal($uid, $kp2['secret'], 2, $kp3['public'], 3); } catch (Throwable $e) { $threw = true; }
check($threw, 'an unopenable row makes the callback THROW (retirement must be blocked)');
$a = $read_msg($msg_a);
check((int)$a['iem_key_generation'] === 3, 'every other row was still attempted before the throw');

// ---- A domain owner with no grants still gets DKIM re-sealed --------------
section('DKIM without mailbox grants');
$owner2 = make_user('MbResealOwner2');
$kpo1 = $box->generateKeypair();
$kpo2 = $box->generateKeypair();
$domain2 = new InboundEmailDomain(NULL);
$domain2->set('ied_domain', 'reseal2-' . bin2hex(random_bytes(4)) . '.example');
$domain2->set('ied_owner_usr_user_id', (int)$owner2->key);
$domain2->set('ied_is_protected_identity', true);
$dkim2 = 'FAKE-DKIM-OWNER2-' . bin2hex(random_bytes(8));
$domain2->set('ied_dkim_sealed_key', $crypto->sealItemDek($dkim2, $kpo1['public']));
$domain2->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain2->key);

$run_reseal((int)$owner2->key, $kpo1['secret'], 1, $kpo2['public'], 2);
$d2 = new InboundEmailDomain((int)$domain2->key, TRUE);
check($crypto->openItemDek((string)$d2->get('ied_dkim_sealed_key'), $kpo2['secret']) === $dkim2, 'DKIM re-sealed for an owner holding zero mailbox grants');

// ---- Locked reads fail legibly, never leak --------------------------------
section('Locked-state reads');
$row = $read_msg($msg_a);
$threw_locked = false;
try { InboundEmailMessage::decryptSealedFieldStatic('iem_body_plain', $row['iem_body_plain'], $row); } catch (VaultLockedException $e) { $threw_locked = true; }
check($threw_locked, 'a sealed field with no open window raises VaultLockedException, never ciphertext');

harness_finish();
?>
