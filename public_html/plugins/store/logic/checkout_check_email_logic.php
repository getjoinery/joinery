<?php
/**
 * Report whether an account exists for an email address (checkout contact
 * step: shows the "you already have an account — log in?" prompt).
 *
 * Browser-session ONLY: an account-existence check is an email-enumeration
 * oracle, so API keys are refused — the CSRF-bound browser credential limits
 * callers to same-origin page JS, and the api rate bucket bounds probing.
 * Invalid or empty email reports exists=false rather than a validation error,
 * matching what the checkout page needs on blur.
 *
 * @version 1.0.0
 */

function checkout_check_email_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$email = isset($input['email']) ? trim((string) $input['email']) : '';
	$exists = false;
	if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$user = User::GetByEmail($email);
		$exists = ($user !== null && $user !== false);
	}

	return LogicResult::render(array('exists' => $exists));
}

function checkout_check_email_logic_descriptor(): array {
	return [
		'description'      => 'Check whether a user account exists for an email address (checkout contact step).',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => [
			'allow_guest'              => true,
			'requires_browser_session' => true,
		],
		'input'            => [
			'email' => ['type' => 'string', 'required' => true, 'label' => 'Email address'],
		],
	];
}
?>
