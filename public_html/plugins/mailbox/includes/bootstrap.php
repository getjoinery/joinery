<?php
/**
 * Mailbox plugin bootstrap - the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request by the plugin
 * bootstrap loader (PluginBootstraps) whenever the plugin is active. Wires
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
 * It also registers mail parsing as a deferred-work consumer
 * (specs/in_window_deferred_work.md), so a Fortress backlog drains anywhere the
 * owner is on the site with an open window, not only on a mailbox view.
 *
 * @version 1.7
 * @changelog 1.7 - the reseal callback covers soft-deleted messages: a deleted
 *   row is restorable, and one left on a retired generation would come back
 *   permanently unreadable.
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

// --- Unlock-window caps (specs/mailbox_security_levels.md § The Unlock Window) ---
// Mail's window policy, expressed as this consumer's opinion about the shared
// server-custody window rather than as something core knows: a Fortress user's
// window ends after 2h without a content decrypt and unconditionally 24h after
// arming; a Private user gets the 7-day absolute backstop only. Core folds every
// registered provider strictest-wins, so a member who set a tight window on any
// consumer keeps it.
//
// The fail-closed pair is the Fortress caps: an error resolving the level must
// never grant an uncapped window to someone who may have configured the
// strictest policy. A real Fortress user sees no difference; anyone else gets a
// tighter-than-usual window until the fault clears.
VaultUnlock::onWindowCaps(
	function (int $user_id): array {
		$level = InboundEmailDomain::maxSecurityLevelForUser($user_id);
		if ($level === 'fortress') {
			return array(
				'idle'     => VaultUnlock::FORTRESS_IDLE_CAP_SECONDS,
				'absolute' => VaultUnlock::FORTRESS_ABSOLUTE_CAP_SECONDS,
			);
		}
		if ($level === 'private') {
			return array('idle' => null, 'absolute' => VaultUnlock::PRIVATE_ABSOLUTE_CAP_SECONDS);
		}
		return array('idle' => null, 'absolute' => null);
	},
	array(
		'idle'     => VaultUnlock::FORTRESS_IDLE_CAP_SECONDS,
		'absolute' => VaultUnlock::FORTRESS_ABSOLUTE_CAP_SECONDS,
	)
);

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
		// Soft-deleted messages are re-sealed too (the resealRows() contract): a
		// deleted row is restorable, and one left on a retired generation would
		// come back permanently unreadable.
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id IN ($in)
			 AND iem_content_sealed = true AND iem_key_generation = ?");
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

	// A Joinery Direct signing key under vault custody seals to the same public
	// key, for the same reason (docs/joinery_direct.md § The relay at Fortress:
	// custody mirrors DKIM). Losing it in a rotation would not lose mail — Direct
	// would simply stop signing and every send would fall back to SMTP — but it
	// would be a silent capability loss, so it re-seals here on the same
	// fail-loud contract.
	require_once(PathHelper::getIncludePath('data/direct_identities_class.php'));
	$direct_failed = 0;
	$direct_rows = new MultiDirectIdentity(array('owner_user_id' => $user_id));
	$direct_rows->load();
	$direct_count = 0;
	foreach ($direct_rows as $identity) {
		$sealed = (string)$identity->get('jdi_sealed_secret_key');
		if ($sealed === '') {
			continue;
		}
		$direct_count++;
		try {
			$secret = $crypto->openItemDek($sealed, $old_secret_key);
			$upd = $db->prepare('UPDATE jdi_direct_identities SET jdi_sealed_secret_key = ?
				WHERE jdi_direct_identity_id = ?');
			$upd->execute(array($crypto->sealItemDek($secret, $new_public_key), intval($identity->key)));
		} catch (Throwable $e) {
			$direct_failed++;
			error_log('Mailbox vault reseal: failed to re-seal the Direct signing key for '
				. $identity->get('jdi_domain') . ': ' . $e->getMessage());
		}
	}

	if ($failed > 0 || $dkim_failed > 0 || $direct_failed > 0) {
		throw new RuntimeException(
			'Mailbox reseal: ' . $failed . ' of ' . count($ids) . ' sealed messages, '
			. $dkim_failed . ' of ' . count($protected) . ' protected DKIM keys and '
			. $direct_failed . ' of ' . $direct_count . ' Direct signing keys could not be re-sealed; '
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

// --- Deferred work consumer (specs/in_window_deferred_work.md) ---
// Fortress mail arrives sealed and unparsed while the owner is logged out; only
// their open window can turn it into readable fields. Registering here means the
// backlog drains wherever the owner happens to be on the site, not only when
// they open the mailbox. Mailbox registers FIRST (it declares a lower vaultConsumer
// `order` than joinery_ai) because the AI email jobs skip unparsed mail —
// parsing has to lead.
require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DeferredIngest.php'));
VaultDeferredWork::register(
	'mailbox_parse',
	function (int $user_id): bool {
		return DeferredIngest::hasWork($user_id);
	},
	function (int $user_id, string $secret_key, float $deadline): int {
		return DeferredIngest::drainForUser($user_id, $secret_key, DeferredIngest::DEFAULT_MAX, $deadline);
	}
);

// --- Joinery Direct consumer hooks (docs/joinery_direct.md) ---
// The channel is core and kind-independent; who an address belongs to, whose
// contacts authorize a delivery, and whose vault holds a domain's signing key
// are all mailbox facts. Core reads them through these registered callables and
// never names a mailbox symbol — the same discipline the identity hooks above
// follow. With the plugin inactive nothing registers, so the endpoint hosts no
// addresses and every preflight refuses at request level.
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRecipients.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectContactGate.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDirectConsumer.php'));

DirectRecipients::registerResolver(function (string $address): ?array {
	return MailboxDirectConsumer::resolveAddress($address);
});
DirectContactGate::registerLookup(function (int $user_id, int $alias_id, string $address): bool {
	return MailboxDirectConsumer::isContact($user_id, $alias_id, $address);
});
DirectSigningIdentity::registerVaultOwnerResolver(function (string $domain): ?int {
	return MailboxDirectConsumer::signingVaultOwner($domain);
});

// A relay-fronted deployment sends Direct THROUGH the relay, so the recipient
// sees the relay's address and never the box's — the same reason its MX points
// at the relay. The box still signs; the relay only transports. With no relay
// enabled this resolves to null and requests go out from the box directly.
require_once(PathHelper::getIncludePath('includes/joinery_direct/JoineryDirect.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DirectRelayEgress.php'));
JoineryDirect::registerEgress(function () {
	return DirectRelayEgress::forDeployment();
});

// Mail's own Direct policy: try the direct channel first, fall back to SMTP for
// every recipient it did not deliver. This mapping is mail's alone — no other
// kind's declined or failed result ever produces an SMTP send.
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DirectMailTransport.php'));
EmailSender::registerDirectAttempt(function (EmailMessage $message): array {
	return DirectMailTransport::attempt($message);
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
