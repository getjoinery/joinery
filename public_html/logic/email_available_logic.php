<?php
/**
 * email_available — is an email address free to register?
 *
 * Read-only availability oracle for the admin user-add form's remote email
 * validator (FormWriter `remote` rule → data.valid). Admin-only (floor 5): an
 * open version would be an account-enumeration oracle.
 */

function email_available_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$email = isset($input['email']) ? trim((string) $input['email']) : '';

	// Empty is treated as available — matches the field's empty-is-valid behavior
	// (the JS validator only calls this when the field has a value, so this is a
	// backstop).
	if ($email === '') {
		return LogicResult::render(['valid' => true]);
	}

	$available = User::GetByEmail($email) ? false : true;

	return LogicResult::render(['valid' => $available]);
}

function email_available_logic_descriptor(): array {
	return [
		'description' => 'Check whether an email address is available (not already registered).',
		'mutates'     => false,
		'auth'        => [
			'capability'          => 'read',
			'min_user_permission' => 5,
		],
		'input'       => [
			'email' => ['type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'Email address'],
		],
	];
}
?>
