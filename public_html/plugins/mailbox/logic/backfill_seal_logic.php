<?php
/**
 * API action: mailbox/backfill_seal — pre-launch convergence to the sealed
 * form (specs/implemented/inbound_email_encryption_at_rest.md § 9).
 *
 * One-time, in-window, idempotent pass over the calling user's OWN mailbox(es)
 * (the alias they are the single grantee of): every not-yet-sealed message is
 * sealed now that they hold a Sealed Vault. A message still carrying its raw
 * (a legacy fallback-stored row) re-splits its attachments to sealed Files and
 * destroys the raw; an already-lean row (Files already extracted, from before
 * the vault existed) has its content columns sealed while its existing
 * attachment Files stay plaintext — safe, because sealed state is recorded
 * per file (ima_is_sealed) and every byte reader keys on that flag, so those
 * Files keep streaming as-is (the accepted pre-launch residual; this platform
 * has no production users yet).
 *
 * POST /api/v1/action/mailbox/backfill_seal (session key). No params. Call
 * repeatedly (a bounded batch per call) until {done: true} — mirrors
 * ApplyInboundEmailFilters's cursor/batch shape, but caller-driven since this
 * needs the unlock window, which a cron task never has.
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function backfill_seal_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

	$batch_size = 25;

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$vault = UserEncryptionVault::loadForUser($user_id);
	if (!$vault) {
		return LogicResult::error('Set up your vault before sealing existing mail.');
	}
	$secret = VaultUnlock::secretKey($user_id);
	if ($secret === null) {
		return LogicResult::error('Unlock your vault first.');
	}

	$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
	// Only the mailboxes this user is the SINGLE owner of — a shared mailbox
	// is never sealed (specs/implemented/inbound_email_encryption_at_rest.md §
	// 4.3), so it is excluded here too.
	$owned_alias_ids = array();
	foreach ($alias_ids as $aid) {
		if (InboundEmailMessage::singleOwnerUserId($aid) === $user_id) {
			$owned_alias_ids[] = $aid;
		}
	}
	if (!count($owned_alias_ids)) {
		return LogicResult::render(['done' => true, 'sealed' => 0, 'message' => 'No single-owner mailbox to seal.']);
	}

	$db = DbConnector::get_instance()->get_db_link();
	$in = implode(',', array_map('intval', $owned_alias_ids));
	$stmt = $db->prepare(
		"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
		 WHERE iem_iea_inbound_email_alias_id IN ($in)
		   AND iem_content_sealed = false AND iem_delete_time IS NULL
		 ORDER BY iem_inbound_email_message_id ASC LIMIT " . $batch_size);
	$stmt->execute();
	$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

	if (!count($ids)) {
		return LogicResult::render(['done' => true, 'sealed' => 0, 'message' => 'Nothing left to seal.']);
	}

	$router = new InboundEmailRouter();
	$sealed = 0;
	foreach ($ids as $id) {
		$msg = new InboundEmailMessage((int)$id, TRUE);
		if (!$msg->key) {
			continue;
		}
		try {
			// Seals every content column this row actually holds — including the
			// AI summary and scan a Standard-era triage may have left behind,
			// and a draft's recipient/bcc/draft-state — using the same
			// per-direction predicate the read path uses.
			$dek = InboundEmailMessage::sealExistingRow($msg, $vault);

			$raw = $msg->getRawMessage();
			if ($raw !== null && $raw !== '') {
				// Still carries its raw (legacy fallback row): re-split
				// attachments into sealed Files, then destroy the raw. Any
				// Files already linked from a prior extraction attempt are
				// left in place; extractAttachmentsToFiles is additive.
				$router->resealBackfillAttachments((int)$msg->key, $raw, $dek);
				$router->destroyRawAfterBackfill((int)$msg->key);
			}
			$sealed++;
		} catch (Throwable $e) {
			error_log('backfill_seal: failed for message ' . $id . ': ' . $e->getMessage());
		}
	}

	return LogicResult::render(['done' => count($ids) < $batch_size, 'sealed' => $sealed]);
}

function backfill_seal_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Seal one batch of the caller\'s own not-yet-sealed mail (pre-launch convergence); call repeatedly until done',
	];
}
?>
