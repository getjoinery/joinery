<?php
/**
 * API action: backup_recovery_prove — record possession of the recovery key.
 *
 * POST /api/v1/action/backup_recovery_prove (browser session, superadmin).
 * Params:
 *   proof  string (required) — the sentence recovered from the challenge that
 *          backup_recovery_save returned
 *
 * Second half of the one-screen recovery setup. On success the key is honored
 * for sealing, and — because a proven key was the last missing piece — the
 * nightly backup task switches itself on when a scheduled target exists too
 * (BackupNightly::maybe_activate).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function backup_recovery_prove_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can verify the backup recovery key.');
	}

	try {
		BackupRecoveryKey::record_possession_proof((string)($input['proof'] ?? ''));
	} catch (BackupRecoveryKeyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'proven'     => true,
		'nightly_on' => BackupNightly::maybe_activate(),
	));
}

function backup_recovery_prove_logic_descriptor(): array {
	return [
		'description' => 'Record possession proof for the backup recovery key; may switch nightly backups on.',
		'mutates'     => true,
		'requires_session' => true,
		'input'       => [
			'proof' => ['type' => 'string', 'required' => true, 'label' => 'Recovered challenge sentence'],
		],
	];
}
