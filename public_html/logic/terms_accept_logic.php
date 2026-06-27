<?php

function terms_accept_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login');
	}

	$user = new User($session->get_user_id(), true);
	$page_vars['user'] = $user;

	// Already accepted? Refresh cache and bounce them off this page.
	if (!empty($user->get('usr_terms_accepted_time'))) {
		$_SESSION['terms_accepted'] = true;
		$dest = $session->get_return() ?: '/profile';
		return LogicResult::redirect($dest);
	}

	if (!empty($_POST)) {
		if (empty($input['accept_terms'])) {
			return LogicResult::error('You must agree to the Terms of Use and Privacy Policy to continue.');
		}

		$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$user->save();

		$_SESSION['terms_accepted'] = true;

		$dest = $session->get_return() ?: '/profile';
		return LogicResult::redirect($dest);
	}

	return LogicResult::render($page_vars);
}

function terms_accept_logic_descriptor(): array {
	return [
		'description'      => 'Capture acceptance of the Terms of Use and Privacy Policy.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'accept_terms' => ['type' => 'checkbox', 'required' => true, 'label' => 'I agree to the Terms of Use and Privacy Policy'],
		],
	];
}
?>
