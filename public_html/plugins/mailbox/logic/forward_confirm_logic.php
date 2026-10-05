<?php
/**
 * The page a forwarding confirmation link opens (/mail/forward-confirm?t=...).
 * No login: the random token in the link is the credential, and it reaches
 * only the person at the destination. Confirming takes a press of the button
 * (a POST), so a mail scanner that follows the link confirms nothing
 * (specs/relay_receive_only_forwarding.md, rule 4).
 *
 * @version 1.0
 */
function forward_confirm_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ForwardConfirmation.php'));

	$token = trim((string)($input['t'] ?? ($input['token'] ?? '')));
	$row = ($token !== '') ? InboundForwardDestination::GetByToken($token) : null;

	$page_vars = array('is_valid_page' => $row !== null, 'token' => $token, 'confirmed_now' => false);
	if (!$row) {
		return LogicResult::render($page_vars);
	}
	if (!empty($input['confirm']) && !$row->is_confirmed()) {
		$row = ForwardConfirmation::confirm($token);
		$page_vars['confirmed_now'] = true;
	}
	$page_vars['already_confirmed'] = $row->is_confirmed() && !$page_vars['confirmed_now'];
	$page_vars['source'] = $row->source_label();
	$page_vars['destination'] = (string)$row->get('ifd_destination');
	return LogicResult::render($page_vars);
}

function forward_confirm_logic_descriptor(): array {
	return [
		'description' => 'Confirm that mail may be forwarded to the address a forwarding confirmation link was sent to.',
		'mutates'     => true,
		'auth'        => [
			'capability'       => null,
			'requires_session' => false,
			'allow_guest'      => true,
		],
		'input'       => [
			'token'   => ['type' => 'string', 'required' => true, 'label' => 'Token from the confirmation link'],
			'confirm' => ['type' => 'bool', 'required' => false, 'label' => 'Confirm forwarding'],
		],
	];
}
