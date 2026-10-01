<?php
/**
 * API action: private_ai_consent — where the signed-in member's Private
 * content may be read by AI (PrivateContentConsent).
 *
 * POST /api/v1/action/private_ai_consent          → { consent, options }
 * POST /api/v1/action/private_ai_consent { action: save, consent }
 *
 * Loosening the answer (local → trusted → cloud) asks for a recent second
 * factor from a member who has one, as loosening a mail domain's consent
 * does; the refusal carries `requires_stepup` and the page runs
 * JoineryPasskeys.withStepUp. Tightening asks nothing.
 *
 * @version 1.0
 */
function private_ai_consent_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PrivateContentConsent.php'));
	require_once(PathHelper::getIncludePath('includes/ProtectionLevelChange.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$current = PrivateContentConsent::forUser($user_id);

	if (($input['action'] ?? '') === 'save') {
		$raw = strtolower(trim((string)($input['consent'] ?? '')));
		if (!in_array($raw, PrivateContentConsent::CONSENTS, true)) {
			return LogicResult::error('Choose one of the offered answers.');
		}
		if (PrivateContentConsent::isLoosening($current, $raw) && ProtectionLevelChange::stepUpOutstanding()) {
			return LogicResult::error('Confirm it is you before letting your private content travel further.',
				array('requires_stepup' => true));
		}
		PrivateContentConsent::set($user_id, $raw);
		return LogicResult::render(array('saved' => true, 'consent' => $raw));
	}

	return LogicResult::render(array(
		'consent' => $current,
		'options' => PrivateContentConsent::options(),
	));
}

function private_ai_consent_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'mutates'          => true,
		'description'      => 'Read or save where the signed-in member\'s Private content may be read by AI: local (default), trusted, or cloud. Loosening is refused with `requires_stepup` when a recent second factor is needed.',
		'input'            => array(
			'action'  => array('type' => 'string', 'required' => false, 'enum' => array('save'), 'label' => 'Pass "save" to write; omit to read'),
			'consent' => array('type' => 'string', 'required' => false, 'enum' => array('local', 'trusted', 'cloud'), 'label' => 'Where private content may be read by AI'),
		),
	);
}
?>
