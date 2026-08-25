<?php
/**
 * API action: backup_recovery_save — store the backup recovery public key and
 * hand back the possession challenge for it.
 *
 * POST /api/v1/action/backup_recovery_save (browser session, superadmin).
 * Params:
 *   public_key  string (required) — base64 X25519 box public key
 *
 * First half of the one-screen recovery setup (RecoveryKeySetupPanel): the key
 * is stored unproven, then the challenge in the response is sealed to what was
 * actually STORED — re-read from the database, not echoed from the input — so
 * the browser opening it with the private half proves the whole path, storage
 * included. backup_recovery_prove records the result.
 *
 * rotate=1 replaces a PROVEN key — the deliberate rotation the panel's Actions
 * menu offers. Without it, saving over a proven key is refused (the accidental
 * overwrite that refusal exists for). Rotation clears the proof, so nothing
 * seals to the new key until the same ceremony proves it.
 *
 * @version 1.1.0 - rotate param: the panel's rotation action replaces a proven key deliberately
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function backup_recovery_save_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can set the backup recovery key.');
	}

	try {
		BackupRecoveryKey::set_public_key((string)($input['public_key'] ?? ''), !empty($input['rotate']));
		return LogicResult::render(array(
			'challenge'  => BackupRecoveryKey::browser_challenge(),
			'public_key' => base64_encode(BackupRecoveryKey::parse_public_key()),
			'info'       => BackupRecoveryKey::BROWSER_INFO,
		));
	} catch (BackupRecoveryKeyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function backup_recovery_save_logic_descriptor(): array {
	return [
		'description' => 'Store the backup recovery public key (unproven) and return its possession challenge.',
		'mutates'     => true,
		'requires_session' => true,
		'input'       => [
			'public_key' => ['type' => 'string', 'required' => true, 'label' => 'Recovery public key (base64)'],
			'rotate'     => ['type' => 'boolean', 'required' => false, 'label' => 'Replace a proven key (deliberate rotation)'],
		],
	];
}
