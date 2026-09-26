<?php
/**
 * API action: mailbox/device_ai_host — set, change or remove where the
 * caller's own AI model answers (specs/fortress_mail_device_ai.md § R2).
 *
 * POST /api/v1/action/mailbox/device_ai_host (browser session). Params: origin
 * (scheme://host[:port]; '' removes it). Returns {origin} — what is stored, or
 * null after a removal.
 *
 * The origin decides where this person's end-to-end encrypted mail may be sent
 * from their browser: the mailbox page names exactly it in its CSP. Setting or
 * changing it therefore needs a fresh second-factor confirmation, the gate
 * vault_client_add_wrapping uses; the refusal carries requires_stepup so the
 * page confirms and retries. Removing it needs none: it can only narrow where
 * mail goes.
 *
 * @version 1.0
 */

function device_ai_host_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::error('Sign in required.');
	}

	$origin = trim((string)($input['origin'] ?? ''));
	if ($origin === '') {
		MailboxDeviceAiHost::setForUser($user_id, '');
		return LogicResult::render(array('origin' => null));
	}

	try {
		$origin = MailboxDeviceAiHost::normalizeOrigin($origin);
	} catch (MailboxDeviceAiHostException $e) {
		return LogicResult::error($e->getMessage());
	}
	// The same origin again changes nothing, so it asks for nothing.
	if ($origin === MailboxDeviceAiHost::originForUser($user_id)) {
		return LogicResult::render(array('origin' => $origin));
	}
	if ($session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before choosing where your mail is sent.', array('requires_stepup' => true));
	}

	return LogicResult::render(array('origin' => MailboxDeviceAiHost::setForUser($user_id, $origin)));
}

function device_ai_host_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Set, change or remove the origin of the caller\'s own AI model, where their browser sends end-to-end encrypted mail to be judged; setting it requires a recent step-up',
		'input' => [
			'origin' => ['type' => 'string', 'required' => false, 'label' => 'Model origin (scheme://host[:port]); empty removes it'],
		],
	);
}
?>
