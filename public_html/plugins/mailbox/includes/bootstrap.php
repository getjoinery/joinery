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
 * It also wires the outbound send protection consumer
 * (specs/mailbox_outbound_send_protection.md): the two MailIdentityGuard
 * callables (protected-domain predicate + DKIM signer resolver) that core send
 * code reads, and the reseal of a protected domain's sealed DKIM key alongside
 * the message DEKs on a vault key rotation.
 *
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDkimSigner.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

// --- Protected sending identity hooks (specs/mailbox_outbound_send_protection.md) ---
// Core send code (EmailSender ambient guard, SmtpProvider DKIM signing) reads
// these two well-known callables so it never names a mailbox symbol directly.
MailIdentityGuard::registerProtectedDomainCheck(function (string $from_domain): bool {
	return MailboxDkimSigner::isProtected($from_domain);
});
MailIdentityGuard::registerDkimSigner(function (string $from_domain): ?array {
	return MailboxDkimSigner::resolveFor($from_domain);
});

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
	$db = DbConnector::get_instance()->get_db_link();
	$crypto = new VaultCrypto();
	$failed = 0;
	$ids = array();

	// Message DEKs: only users holding mailbox grants have sealed messages.
	// This block is conditional; the DKIM block below is NOT — owning a
	// protected sending domain is independent of holding mailbox grants, and
	// an early return here would silently skip the DKIM re-seal (permanent
	// key loss once the old generation is retired).
	$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
	if (count($alias_ids)) {
		$in = implode(',', array_map('intval', $alias_ids));
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id IN ($in)
			 AND iem_content_sealed = true AND iem_key_generation = ? AND iem_delete_time IS NULL");
		$stmt->execute(array($old_key_generation));
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
	}

	// Contact store (specs/mailbox_compose_maturity.md § Phase 4): a sealed contact's
	// DEK is sealed to the owner's vault, so a rotation must re-wrap it too, or the
	// contact becomes unreadable. Same envelope re-seal as messages (the address/name
	// ciphertext under the DEK is untouched).
	$contact_ids = $db->query('SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts
		WHERE imc_usr_user_id = ' . intval($user_id) . ' AND imc_content_sealed = true
		AND imc_key_generation = ' . intval($old_key_generation))->fetchAll(PDO::FETCH_COLUMN);
	foreach ($contact_ids as $cid) {
		$cid = intval($cid);
		try {
			$row = $db->prepare('SELECT imc_sealed_key FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
			$row->execute(array($cid));
			$sealed = (string)$row->fetchColumn();
			if ($sealed === '') {
				continue;
			}
			$dek = $crypto->openItemDek($sealed, $old_secret_key);
			$new_sealed_key = $crypto->sealItemDek($dek, $new_public_key);
			$upd = $db->prepare('UPDATE imc_mailbox_contacts SET imc_sealed_key = ?, imc_key_generation = ?
				WHERE imc_mailbox_contact_id = ?');
			$upd->execute(array($new_sealed_key, $new_key_generation, $cid));
		} catch (Throwable $e) {
			$failed++;
			error_log('Mailbox vault reseal: failed for contact ' . $cid . ': ' . $e->getMessage());
		}
	}

	// Protected-domain DKIM keys seal to this same vault public key, so a
	// rotation must re-seal them alongside the message DEKs or the in-app
	// signer can no longer unwrap the key. Both the live key and any
	// rotation-pending key (staged but not yet cut over) are re-sealed.
	// Fail-loud on the same contract: a key that cannot be re-sealed blocks
	// retiring the old generation.
	$dkim_failed = 0;
	$dkim_columns = array('ied_dkim_sealed_key', 'ied_dkim_pending_sealed_key');
	$protected = InboundEmailDomain::ProtectedForOwner($user_id);
	foreach ($protected as $domain) {
		foreach ($dkim_columns as $col) {
			$sealed = (string)$domain->get($col);
			if ($sealed === '') {
				continue;
			}
			try {
				$private = $crypto->openItemDek($sealed, $old_secret_key);
				$resealed = $crypto->sealItemDek($private, $new_public_key);
				$upd = $db->prepare(
					'UPDATE ied_inbound_email_domains SET ' . $col . ' = ?
					 WHERE ied_inbound_email_domain_id = ?');
				$upd->execute(array($resealed, intval($domain->key)));
			} catch (Throwable $e) {
				$dkim_failed++;
				error_log('Mailbox vault reseal: failed to re-seal DKIM key (' . $col . ') for domain '
					. $domain->get('ied_domain') . ': ' . $e->getMessage());
			}
		}
	}

	if ($failed > 0 || $dkim_failed > 0) {
		throw new RuntimeException(
			'Mailbox reseal: ' . $failed . ' of ' . count($ids) . ' sealed messages and '
			. $dkim_failed . ' of ' . count($protected) . ' protected DKIM keys could not be re-sealed; '
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

// --- Chunked upload purpose (specs/chunked_upload_purposes.md) ---
// A mail archive is routinely larger than a single web request can carry, so it
// comes in through the platform's resumable chunked transport rather than a form
// POST. This declares the policy around those bytes; the transport itself is
// shared and needs to know nothing about mail.
require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
UploadPurposeRegistry::register('mail_import_archive', array(
	'source' => File::SOURCE_MAIL_IMPORT_ARCHIVE,
	'label'  => 'mail archive',

	// Private, and NOT a Drive item: the archive is working material for one
	// import run, so it stays out of the member's Drive listing and off their
	// Drive quota.
	'restrictions' => array('fil_private' => true),

	// Uploading is gated on the feature being on and the caller being signed in.
	// Mailbox access is deliberately NOT checked here — a file can be uploaded
	// before its destination is chosen, and mail_import_start checks the grant
	// on the mailbox at the point it actually matters.
	'authorize' => function (int $user_id, array $input): ?string {
		if ($user_id <= 0) {
			return 'Sign in required.';
		}
		if (!Globalvars::get_instance()->get_setting('mailbox_import_enabled')) {
			return 'Mail archive import is switched off on this site.';
		}
		return null;
	},
));
?>
