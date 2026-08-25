<?php
/**
 * PromotedRowRepair - pay the sealing debt a Sent-folder direction promotion
 * leaves behind, and retire the duplicate rows the same defect created
 * (specs/bugfix_promoted_sent_row_sealing.md).
 *
 * An IMAP-stored row is sealed as INBOUND — iem_recipient in the clear, which
 * is correct routing metadata there. When the Sent-folder pass promotes it to
 * outbound (ImapIngestor::markDirectionOutbound), that plaintext becomes a
 * sealed-contract violation the promoting cron process cannot fix: sealing
 * under the row's EXISTING DEK needs the owner's in-window secret, and a CLI
 * process never holds a window. The promotion records the debt
 * (iem_reseal_pending); this consumer pays it wherever the owner happens to be
 * on the site with their vault open, via VaultDeferredWork
 * (specs/implemented/in_window_deferred_work.md).
 *
 * The work predicate ALSO matches rows whose flag was never set — a plaintext
 * recipient on a sealed outbound row IS the debt, flag or no flag — so rows
 * broken before the flag existed are found and healed with no repair
 * migration. (A migration could not have marked them anyway: migrations run
 * before plugin schema sync, so the column would not exist on first deploy.)
 *
 * Duplicate retirement rides the same pass. Before the composed-copy dedup fix
 * (ImapIngestor v1.15) the All Mail coverage pass stored its own copy of every
 * locally-composed send — the composer copy's sealed recipient made the
 * (Message-ID, recipient, direction) unique key structurally blind to it. When
 * a row being repaired has a live outbound sibling with a SEALED recipient on
 * the same (alias, Message-ID), that sibling is the composer's copy: the
 * repair adopts the IMAP locator onto it, migrates custom-label memberships,
 * and soft-deletes the duplicate. The duplicate's locator is nulled FIRST —
 * ImapSyncer::pushTrash() MOVEs any reference-backed soft-deleted row into the
 * source's Trash folder, and retiring our redundant row must never relocate
 * the user's copy at the provider.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

class PromotedRowRepair {

	/** Safety cap per drain pass; the backlog is finite (one row per promoted send). */
	const DEFAULT_MAX = 100;

	/**
	 * Is there a promoted row still owing its recipient seal for this owner?
	 * Cheap, indexed, no decrypt — it runs on every vault heartbeat.
	 *
	 * Scoped by iem_sealed_owner_user_id, like every per-owner drain: a legacy
	 * row sealed before that column existed can't be attributed to a window
	 * here and is left to the read-path tolerance
	 * (InboundEmailMessage::decryptSealedFieldStatic).
	 */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT 1 FROM iem_inbound_email_messages
			  WHERE iem_direction = 'outbound'
			    AND iem_content_sealed = true
			    AND iem_sealed_owner_user_id = ?
			    AND iem_delete_time IS NULL
			    AND (iem_reseal_pending = true
			         OR (iem_recipient IS NOT NULL AND iem_recipient <> ''
			             AND iem_recipient NOT LIKE 'v1.aead.%'))
			  LIMIT 1");
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Repair up to $max rows owned by $user_id, using their in-window vault
	 * secret. Returns the number of rows completed. Per-row failures are logged
	 * and the row is left for the next drain — one bad row never stalls the
	 * rest (the same contract DeferredIngest holds).
	 *
	 * $deadline is a microtime(true) value: repair stops before starting a new
	 * row once it passes.
	 */
	public static function drainForUser(int $user_id, string $secret_key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
		if ($user_id <= 0 || $secret_key === '') {
			return 0;
		}
		$rows = self::candidateRows($user_id, $max);
		$done = 0;
		foreach ($rows as $row) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
			$id = intval($row['iem_inbound_email_message_id']);
			try {
				// One row is one unit for the hot-turn rule: unwrapping the DEK
				// opens this owner's sealed scope, and nothing one row decrypts
				// is in play when the next one starts.
				$ok = SealedEgressGuard::isolate(function () use ($row, $secret_key) {
					return self::repairOne($row, $secret_key);
				});
				if ($ok) {
					$done++;
				}
			} catch (\Throwable $e) {
				error_log('PromotedRowRepair: failed to repair message ' . $id . ': ' . $e->getMessage());
			}
		}
		return $done;
	}

	/** The rows owing repair, oldest first — same predicate as hasWork(). */
	private static function candidateRows(int $user_id, int $max): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id,
			        iem_message_id_header, iem_recipient, iem_sealed_key, iem_reseal_pending,
			        iem_iia_inbound_imap_account_id, iem_imap_uid, iem_imap_uidvalidity, iem_imap_folder
			   FROM iem_inbound_email_messages
			  WHERE iem_direction = 'outbound'
			    AND iem_content_sealed = true
			    AND iem_sealed_owner_user_id = ?
			    AND iem_delete_time IS NULL
			    AND (iem_reseal_pending = true
			         OR (iem_recipient IS NOT NULL AND iem_recipient <> ''
			             AND iem_recipient NOT LIKE 'v1.aead.%'))
			  ORDER BY iem_inbound_email_message_id ASC
			  LIMIT " . intval($max));
		$stmt->execute(array($user_id));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Repair one row: seal the plaintext recipient under the row's existing
	 * DEK, retire it if it duplicates the composer's copy, clear the flag.
	 * Idempotent at every step — a crash mid-repair leaves the flag set, and
	 * the next drain resumes where this one stopped.
	 *
	 * @return bool true when the row is fully repaired
	 */
	private static function repairOne(array $row, string $secret_key): bool {
		$id = intval($row['iem_inbound_email_message_id']);
		$db = DbConnector::get_instance()->get_db_link();

		// 1. Seal the recipient under the row's EXISTING DEK, so the columns
		// and attachments already sealed under it stay readable. Skipped when
		// already sealed (a resumed repair) or empty (nothing to seal — the
		// read path returns ''/null verbatim on a sealed row).
		$recipient = $row['iem_recipient'];
		$needs_seal = is_string($recipient) && $recipient !== ''
			&& strpos($recipient, 'v1.aead.') !== 0;
		if ($needs_seal) {
			$sealed_key = (string)($row['iem_sealed_key'] ?? '');
			if ($sealed_key === '') {
				// Sealed row without a key wrapping is a deeper inconsistency
				// this pass cannot repair; the read-path tolerance keeps it
				// rendering. Log and stand down (retried next drain, so the
				// log line recurs — which is the honest signal).
				error_log('PromotedRowRepair: message ' . $id
					. ' is sealed but has no iem_sealed_key; cannot seal its recipient.');
				return false;
			}
			$crypto = new VaultCrypto();
			$dek = $crypto->openItemDek($sealed_key, $secret_key); // throws on mismatch
			// Reused DEK: sealColumns() never touches the key wrapping, so the
			// vault argument is not consulted.
			InboundEmailMessage::sealColumns($id, null, array('iem_recipient' => $recipient), $dek);
		}

		// 2. Retire the row if it duplicates the composer's copy.
		self::retireIfDuplicate($row);

		// 3. Debt paid.
		$stmt = $db->prepare(
			'UPDATE iem_inbound_email_messages SET iem_reseal_pending = false
			  WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($id));
		return true;
	}

	/**
	 * If $row duplicates a live outbound sibling whose recipient IS sealed —
	 * the composer's copy, which the coverage pass could not dedup against —
	 * merge into it and soft-delete $row.
	 *
	 * Order matters: the locator moves (or is dropped) BEFORE the soft-delete,
	 * because ImapSyncer::pushTrash() would otherwise MOVE the user's copy at
	 * the provider into Trash when it sees a reference-backed deleted row.
	 */
	private static function retireIfDuplicate(array $row): void {
		$id = intval($row['iem_inbound_email_message_id']);
		$alias_id = $row['iem_iea_inbound_email_alias_id'];
		$header = trim((string)($row['iem_message_id_header'] ?? ''));
		if ($alias_id === null || $header === '') {
			return; // not attributable to a composed sibling
		}
		$db = DbConnector::get_instance()->get_db_link();

		// The composer's copy: same alias + Message-ID, outbound, live, and a
		// SEALED recipient (this row's was plaintext — that asymmetry is what
		// tells the two copies apart). Oldest first, deterministically.
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id, iem_iia_inbound_imap_account_id
			   FROM iem_inbound_email_messages
			  WHERE iem_iea_inbound_email_alias_id = ?
			    AND iem_message_id_header = ?
			    AND iem_direction = 'outbound'
			    AND iem_inbound_email_message_id <> ?
			    AND iem_delete_time IS NULL
			    AND iem_recipient LIKE 'v1.aead.%'
			  ORDER BY iem_inbound_email_message_id ASC
			  LIMIT 1");
		$stmt->execute(array(intval($alias_id), substr($header, 0, 255), $id));
		$keeper = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$keeper) {
			return; // no composer sibling — this promoted row IS the sent record
		}
		$keeper_id = intval($keeper['iem_inbound_email_message_id']);

		// Locator to the keeper (so its parts stay fetchable), if it has none.
		if ($row['iem_iia_inbound_imap_account_id'] !== null
				&& $keeper['iem_iia_inbound_imap_account_id'] === null) {
			$stmt = $db->prepare(
				'UPDATE iem_inbound_email_messages
				    SET iem_iia_inbound_imap_account_id = ?, iem_imap_uid = ?,
				        iem_imap_uidvalidity = ?, iem_imap_folder = ?
				  WHERE iem_inbound_email_message_id = ?
				    AND iem_iia_inbound_imap_account_id IS NULL');
			$stmt->execute(array(
				intval($row['iem_iia_inbound_imap_account_id']),
				$row['iem_imap_uid'] !== null ? intval($row['iem_imap_uid']) : null,
				$row['iem_imap_uidvalidity'] !== null ? intval($row['iem_imap_uidvalidity']) : null,
				$row['iem_imap_folder'],
				$keeper_id,
			));
		}

		// Strip the duplicate's remote binding BEFORE deleting it (see above).
		$stmt = $db->prepare(
			'UPDATE iem_inbound_email_messages
			    SET iem_iia_inbound_imap_account_id = NULL, iem_imap_uid = NULL,
			        iem_imap_uidvalidity = NULL, iem_imap_folder = NULL
			  WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($id));

		// Custom-label memberships follow the message where the keeper lacks
		// the label; the remainder (labels the keeper already carries) are
		// dropped with the duplicate.
		$stmt = $db->prepare(
			'UPDATE ilm_inbound_label_members m
			    SET ilm_iem_inbound_email_message_id = ?
			  WHERE m.ilm_iem_inbound_email_message_id = ?
			    AND NOT EXISTS (
			        SELECT 1 FROM ilm_inbound_label_members k
			         WHERE k.ilm_iem_inbound_email_message_id = ?
			           AND k.ilm_ilb_inbound_email_label_id = m.ilm_ilb_inbound_email_label_id)');
		$stmt->execute(array($keeper_id, $id, $keeper_id));
		$stmt = $db->prepare(
			'DELETE FROM ilm_inbound_label_members WHERE ilm_iem_inbound_email_message_id = ?');
		$stmt->execute(array($id));

		// Retire. Soft delete (the house pattern for message rows), so the
		// merge is inspectable in Trash and reversible until purge.
		$stmt = $db->prepare(
			'UPDATE iem_inbound_email_messages SET iem_delete_time = now()
			  WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($id));
	}
}
