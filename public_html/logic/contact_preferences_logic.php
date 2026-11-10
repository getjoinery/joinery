<?php

/**
 * The member's email preferences page (spec email_lists_plugin G6). Core owns
 * who the page is for and the form; what it offers comes from the sections
 * plugins register with ContactPreferencesRegistry (the email_lists plugin's
 * mailing lists, for one).
 *
 * The person is the signed-in member, or the one an email footer's link
 * names, proved by its hash. The link's user and hash ride on the form, so
 * saving works without signing in.
 *
 * @version 2.1 - the mailing-list section is the email_lists plugin's
 * @version 2.0
 */
function contact_preferences_logic(array $input): LogicResult{

	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$via_link = !empty($input['hash']);

	if($via_link){
		$user_id = intval($input['user'] ?? 0);
		$user = $user_id > 0 && User::check_if_exists($user_id) ? new User($user_id, TRUE) : null;

		$stored = $user ? (string)$user->get('usr_authhash') : '';
		if($stored === '' || !hash_equals($stored, (string)$input['hash'])){
			return LogicResult::error('This link no longer works. Sign in to change which emails you get.');
		}
	}
	else{
		$session->check_permission(0);
		$user = new User($session->get_user_id(), TRUE);
	}

	$context = array('via_link' => $via_link);
	$page_vars = array('messages' => array());

	if (LibraryFunctions::isFormSubmission() && ContactPreferencesRegistry::hasSections()) {
		if ($via_link) {
			// No one is signed in: the link's hash, checked above, names the
			// person, so their own preference rows are written past the model check.
			$page_vars['messages'] = SystemBase::rule_checked_write(function () use ($user, $input, $context) {
				return ContactPreferencesRegistry::save($user, $input, $context);
			});
		} else {
			$page_vars['messages'] = ContactPreferencesRegistry::save($user, $input, $context);
		}
	}

	$page_vars['user'] = $user;
	$page_vars['has_sections'] = ContactPreferencesRegistry::hasSections();
	$page_vars['via_link'] = $via_link;
	$page_vars['link_user'] = $via_link ? (int)$user->key : null;
	$page_vars['link_hash'] = $via_link ? (string)$input['hash'] : null;

	return LogicResult::render($page_vars);
}

/**
 * Form builder — single source for the web contact-preferences form and the
 * JSON form definition (GET /api/v1/form/contact_preferences): every
 * registered section, then the footer link's user and hash when the person
 * came through one.
 */
function contact_preferences_logic_form($formwriter, $user = null, $input = []) {
	if (!$user) {
		throw new Exception('contact_preferences form requires a user');
	}

	$via_link = !empty($input['hash']);
	ContactPreferencesRegistry::render($formwriter, $user, array('via_link' => $via_link));

	if ($via_link) {
		$formwriter->hiddeninput('user', '', ['value' => (int)$user->key]);
		$formwriter->hiddeninput('hash', '', ['value' => (string)$input['hash']]);
	}
	$formwriter->hiddeninput('zone', '', ['value' => 'optional']);
	$formwriter->submitbutton('btn_submit', 'Submit');
}

function contact_preferences_logic_descriptor(): array {
	return [
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Update the user\'s email preferences: each section a plugin adds reads its own inputs (mailing lists: new_list_subscribes).',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'new_list_subscribes' => ['type' => 'string', 'required' => false, 'label' => 'Mailing list IDs to subscribe to (array)'],
		],
	];
}
?>
