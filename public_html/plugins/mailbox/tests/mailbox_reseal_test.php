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
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

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
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));

$box = new SealedBox();
$crypto = new VaultCrypto();

// The mailbox re-seal callback, via the same registry the rotation uses.
$callbacks = VaultUnlock::resealCallbacks();
check(count($callbacks) >= 1, 'the mailbox bootstrap registered a re-seal callback');
$run_reseal = function (int $uid, VaultKey $old_secret, int $old_gen, string $new_pub, int $new_gen) use ($callbacks) {
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
	harness_register_model('InboundEmailMessage', (int)$m->key);
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
$open_body = function (array $row, VaultKey $secret) use ($crypto) {
	$dek = $crypto->openItemDek($row['iem_sealed_key'], $secret);
	return $crypto->openField($row['iem_body_plain'], $dek, InboundEmailMessage::sealAd((int)$row['iem_inbound_email_message_id'], 'iem_body_plain'));
};

// ---- Re-seal drains exactly the named generation --------------------------
section('Generation-scoped re-seal');
$c_before = $read_msg($msg_c);
$run_reseal($uid, vault_fixture_key($kp1['secret']), 1, $kp2['public'], 2);

$a = $read_msg($msg_a);
check((int)$a['iem_key_generation'] === 2, 'gen-1 message A moved to generation 2');
check($open_body($a, vault_fixture_key($kp2['secret'])) === 'gen one message A', 'A opens under the new secret with its original content');
$b = $read_msg($msg_b);
check((int)$b['iem_key_generation'] === 2, 'gen-1 message B moved too');
$c = $read_msg($msg_c);
check($c['iem_sealed_key'] === $c_before['iem_sealed_key'], 'the gen-2 straggler was not touched');

section('DKIM keys ride the rotation');
$d = new InboundEmailDomain((int)$domain->key, TRUE);
check($crypto->openItemDek((string)$d->get('ied_dkim_sealed_key'), vault_fixture_key($kp2['secret'])) === $dkim_live, 'live DKIM key re-sealed to the new generation');
check($crypto->openItemDek((string)$d->get('ied_dkim_pending_sealed_key'), vault_fixture_key($kp2['secret'])) === $dkim_pending, 'pending DKIM key re-sealed too');

// ---- Fail-loud: a broken row blocks retirement ----------------------------
section('Fail-loud contract');
$db = DbConnector::get_instance()->get_db_link();
$stmt = $db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_key = ? WHERE iem_inbound_email_message_id = ?');
$stmt->execute([$crypto->sealItemDek(random_bytes(32), $box->generateKeypair()['public']), $msg_b]);
$threw = false;
try { $run_reseal($uid, vault_fixture_key($kp2['secret']), 2, $kp3['public'], 3); } catch (Throwable $e) { $threw = true; }
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

$run_reseal((int)$owner2->key, vault_fixture_key($kpo1['secret']), 1, $kpo2['public'], 2);
$d2 = new InboundEmailDomain((int)$domain2->key, TRUE);
check($crypto->openItemDek((string)$d2->get('ied_dkim_sealed_key'), vault_fixture_key($kpo2['secret'])) === $dkim2, 'DKIM re-sealed for an owner holding zero mailbox grants');

// ---- Locked reads fail legibly, never leak --------------------------------
section('Locked-state reads');
$row = $read_msg($msg_a);
$threw_locked = false;
try { InboundEmailMessage::decryptSealedFieldStatic('iem_body_plain', $row['iem_body_plain'], $row); } catch (VaultLockedException $e) { $threw_locked = true; }
check($threw_locked, 'a sealed field with no open window raises VaultLockedException, never ciphertext');

// ---- A row re-sealed meanwhile keeps its newer wrapping (B7) ---------------
// The rotation reads a wrapping, re-wraps it and writes it back; anything that
// re-sealed the row in between wins (MailboxReseal::swap).
section('Compare-and-swap: a row changed mid-rotation keeps what it holds');
// The domain's DKIM keys are on generation 3 now (the fail-loud run moved
// them), so the race runs 3 -> 4, where everything else opens.
$v_g3 = new UserEncryptionVault(NULL);
$v_g3->set('uev_usr_user_id', $uid);
$v_g3->set('uev_public_key', $kp3['public']);
$v_g3->set('uev_key_generation', 3);
$kp4 = $box->generateKeypair();
$msg_d = $make_message($v_g3, 'race message D');
$d_dek = $crypto->openItemDek((string)$read_msg($msg_d)['iem_sealed_key'], vault_fixture_key($kp3['secret']));
$kp_race = $box->generateKeypair();
$newer = $crypto->sealItemDek($d_dek, $kp_race['public']);
MailboxReseal::$before_write = function (string $table, int $id) use ($db, $msg_d, $newer) {
	if ($table === 'iem_inbound_email_messages' && $id === $msg_d) {
		$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_key = ?, iem_key_generation = 7
			WHERE iem_inbound_email_message_id = ?')->execute(array($newer, $msg_d));
	}
};
$threw = null;
try {
	$run_reseal($uid, vault_fixture_key($kp3['secret']), 3, $kp4['public'], 4);
} catch (Throwable $e) {
	$threw = $e->getMessage();
} finally {
	MailboxReseal::$before_write = null;
}
$d_row = $read_msg($msg_d);
check($d_row['iem_sealed_key'] === $newer && (int)$d_row['iem_key_generation'] === 7,
	'the newer wrapping survives: the stale one is not written over it');
check($threw === null, 'and the row is skipped, not failed', (string)$threw);
check($open_body($d_row, vault_fixture_key($kp_race['secret'])) === 'race message D', 'the message still opens');

$dkim_newer = $crypto->sealItemDek('FAKE-DKIM-NEWER', $kp_race['public']);
MailboxReseal::$before_write = function (string $table, int $id) use ($db, $domain2, $dkim_newer) {
	if ($table === 'ied_inbound_email_domains' && $id === (int)$domain2->key) {
		$db->prepare('UPDATE ied_inbound_email_domains SET ied_dkim_sealed_key = ? WHERE ied_inbound_email_domain_id = ?')
			->execute(array($dkim_newer, (int)$domain2->key));
	}
};
try {
	$run_reseal((int)$owner2->key, vault_fixture_key($kpo2['secret']), 2, $kp3['public'], 3);
} finally {
	MailboxReseal::$before_write = null;
}
$d2 = new InboundEmailDomain((int)$domain2->key, TRUE);
check((string)$d2->get('ied_dkim_sealed_key') === $dkim_newer, 'a DKIM key re-sealed meanwhile keeps its newer wrapping');

// ---- Contacts re-seal through the generic model path ----------------------
section('Contacts, including one sealed before its owner was recorded');
$contact = new MailboxContact(NULL);
$contact->set('imc_usr_user_id', $uid);
$contact->set('imc_address_hash', hash('sha256', 'reseal-contact|' . $uid . '|' . random_bytes(8)));
$contact->save();
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$contact->key);
// Generation 4 now holds the domain's DKIM keys and message A, so 4 -> 5
// opens everything and no callback stops the run before the contacts'.
$v_g4 = new UserEncryptionVault(NULL);
$v_g4->set('uev_usr_user_id', $uid);
$v_g4->set('uev_public_key', $kp4['public']);
$v_g4->set('uev_key_generation', 4);
MailboxContact::sealColumns((int)$contact->key, $v_g4, array('imc_address' => 'friend@example.com'));
$db->prepare('UPDATE imc_mailbox_contacts SET imc_sealed_owner_user_id = NULL, imc_key_generation = 4
	WHERE imc_mailbox_contact_id = ?')->execute(array((int)$contact->key));
$kp5 = $box->generateKeypair();
$run_reseal($uid, vault_fixture_key($kp4['secret']), 4, $kp5['public'], 5);
$q = $db->prepare('SELECT imc_sealed_key, imc_key_generation FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
$q->execute(array((int)$contact->key));
$c_row = $q->fetch(PDO::FETCH_ASSOC);
check((int)$c_row['imc_key_generation'] === 5, 'a contact with no recorded owner is found by its user and moved');
$c_dek = $crypto->openItemDek((string)$c_row['imc_sealed_key'], vault_fixture_key($kp5['secret']));
check(is_string($c_dek) && $c_dek !== '', 'and its key opens under the new vault key');

harness_finish();
?>
