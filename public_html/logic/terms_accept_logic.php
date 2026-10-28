<?php
/**
 * /terms-accept — the person accepts the terms they owe (TermsOwed): a site's
 * own terms, or getjoinery's for a hosted site's operators.
 *
 * @version 1.1 - asks only for the terms TermsOwed says are owed, and names whose they are
 */

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

	// Nothing owed (accepted already, or no terms apply)? Refresh the cache
	// and bounce them off this page.
	$owed = TermsOwed::owed($user);
	$page_vars['owed'] = $owed;
	if ($owed === null) {
		$_SESSION['terms_accepted'] = true;
		$dest = $session->get_return() ?: '/profile';
		return LogicResult::redirect($dest);
	}

	if (!empty($_POST)) {
		if (empty($input['accept_terms'])) {
			return LogicResult::error('Tick the box to agree before you continue.', $page_vars);
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
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Capture acceptance of the terms this person owes: the site\'s own, or getjoinery\'s on a hosted site\'s operator account.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'accept_terms' => ['type' => 'checkbox', 'required' => true, 'label' => 'I agree to the Terms of Use and Privacy Policy'],
		],
	];
}
?>
