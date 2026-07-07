<?php
/**
 * Mailbox plugin bootstrap - loaded once per request by
 * VaultUnlock::loadConsumerBootstraps() whenever the plugin is active. Wires
 * mail into the Sealed Vault's two generic consumer hooks (docs/sealed_vault.md
 * § The consumer contract): the File decrypt hook for sealed attachments, and
 * the rotation re-seal / window-wipe callbacks.
 *
 * The sealed-field model hook ($sealed_fields on InboundEmailMessage) needs no
 * registration here — it is declared directly on the model class, which is
 * already required wherever a message is read.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

// --- Sealed-File decrypt hook (docs/sealed_vault.md § The two generic consumer hooks) ---
// Resolve the owning message (and its owner) from the attachment manifest,
// require an open window for that owner, then open the per-message DEK and
// the attachment's own AEAD blob.
File::registerDecryptHook(File::SOURCE_EMAIL_ATTACHMENT, function (string $ciphertext, File $file): string {
	$manifest = new MultiInboundMessageAttachment(array('file_id' => intval($file->key)));
	$manifest->load();
	if ($manifest->count() === 0) {
		throw new VaultLockedException(); // no manifest row - nothing this hook can resolve
	}
	$att = $manifest->get(0);
	$message_id = intval($att->get('ima_iem_inbound_email_message_id'));

	$msg = new InboundEmailMessage($message_id, TRUE);
	if (!$msg->key) {
		throw new VaultLockedException(); // dangling manifest row - nothing to open
	}
	// openSealedAttachment() keys on ima_is_sealed — a plaintext File (a
	// pre-vault attachment on a since-backfilled message) streams as-is.
	return InboundEmailMessage::openSealedAttachment($msg, $att, $ciphertext);
});

// --- Rotation re-seal callback (docs/sealed_vault.md § Key rotation) ---
// Scoped to the generation being drained (iem_key_generation =
// $old_key_generation — the only generation $old_secret_key can open) and
// fail-loud per the VaultUnlock::onReseal() contract: every row is attempted,
// then any failure THROWS so the ceremony refuses to retire the old
// wrappings while content is still sealed to them. The FTS index is sealed
// under the now-superseded key too, so it is purged rather than re-sealed —
// the next unlock rebuilds it from the freshly-resealed rows.
VaultUnlock::onReseal(function (int $user_id, string $old_secret_key, int $old_key_generation, string $new_public_key, int $new_key_generation) {
	$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
	if (!count($alias_ids)) {
		return;
	}

	$db = DbConnector::get_instance()->get_db_link();
	$in = implode(',', array_map('intval', $alias_ids));
	$stmt = $db->prepare(
		"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
		 WHERE iem_iea_inbound_email_alias_id IN ($in)
		 AND iem_content_sealed = true AND iem_key_generation = ? AND iem_delete_time IS NULL");
	$stmt->execute(array($old_key_generation));
	$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

	$crypto = new VaultCrypto();
	$failed = 0;

	foreach ($ids as $id) {
		$id = intval($id);
		try {
			$msg = new InboundEmailMessage($id, TRUE);
			if (!$msg->key || !$msg->get('iem_sealed_key')) {
				continue;
			}
			$dek = $crypto->openItemDek((string)$msg->get('iem_sealed_key'), $old_secret_key);
			$new_sealed_key = $crypto->sealItemDek($dek, $new_public_key);
			$upd = $db->prepare(
				'UPDATE iem_inbound_email_messages SET iem_sealed_key = ?, iem_key_generation = ?
				 WHERE iem_inbound_email_message_id = ?');
			$upd->execute(array($new_sealed_key, $new_key_generation, $id));
		} catch (Throwable $e) {
			$failed++;
			error_log('Mailbox vault reseal: failed for message ' . $id . ': ' . $e->getMessage());
		}
	}

	$index = new MailboxIndex();
	$index->purgePersisted($user_id);

	if ($failed > 0) {
		throw new RuntimeException(
			'Mailbox reseal: ' . $failed . ' of ' . count($ids) . ' sealed messages could not be re-sealed; '
			. 'the old key generation must not be retired.');
	}
});

// --- Window-wipe callback (docs/sealed_vault.md § consumer contract) ---
// Clears the /dev/shm FTS working copy when a window closes (explicit lock,
// a credential event, or lockAll). The persisted sealed blob is untouched —
// the next unlock restores it via MailboxIndex::ensureOpen().
VaultUnlock::onWipe(function (int $user_id, ?string $scope) {
	if ($scope !== null && $scope !== UserEncryptionVault::SCOPE_USER) {
		return;
	}
	$index = new MailboxIndex();
	$index->wipe($user_id);
});
?>
