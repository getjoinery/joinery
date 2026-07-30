<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function contact_preferences_logic(array $input): LogicResult{

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	$session = SessionControl::get_instance();

	if($input['hash']){
		$user = new User($input['user'], TRUE);

		if($input['hash'] !== $user->get('usr_authhash')){
			return LogicResult::error("Users don't match.  You cannot edit someone else's info.");
		}
	}
	else{
		$session->check_permission(0);
		$user = new User($session->get_user_id(), TRUE);
	}

	if (!empty($_POST)) {
		$page_vars['messages'] = $user->add_user_to_mailing_lists($_POST['new_list_subscribes']);

	}

	$lists = contact_preferences_form_options($user);
	$page_vars['optionvals'] = $lists['options'];
	$page_vars['checkedvals'] = $lists['checked'];
	$page_vars['user'] = $user;

	//$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);


	return LogicResult::render($page_vars);
}

function contact_preferences_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Update contact preferences',
    ];
}

/**
 * The user's subscribable mailing lists and current subscriptions.
 * Private and unlisted lists appear only when the user is already subscribed.
 *
 * @param User $user
 * @return array ['options' => [id => name], 'checked' => [id, ...]]
 */
function contact_preferences_form_options($user) {
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	$mailing_lists = new MultiMailingList(
		array('deleted' => false, 'active' => true),
		array('name'=>'ASC'));
	$mailing_lists->load();

	$user_subscribed_list = array();
	$user_lists = new MultiMailingListRegistrant(
		array('deleted' => false, 'user_id' => $user->key));
	$user_lists->load();
	foreach ($user_lists as $user_list){
		$user_subscribed_list[] = $user_list->get('mlr_mlt_mailing_list_id');
	}

	$optionvals = $mailing_lists->get_dropdown_array();
	//REMOVE ALL OF THE PRIVATE AND UNLISTED LISTS THE USER IS NOT SUBSCRIBED TO
	foreach($optionvals as $key=>$value){
		$mailing_list = new MailingList($key, TRUE);
		if($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC_UNLISTED){
			if(!in_array($key, $user_subscribed_list)){
				unset($optionvals[$key]);
			}
		}
	}

	return array('options' => $optionvals, 'checked' => $user_subscribed_list);
}

/**
 * Form builder — single source for the web contact-preferences form and the
 * JSON form definition (GET /api/v1/form/contact_preferences).
 */
function contact_preferences_logic_form($formwriter, $user = null, $input = []) {
	if (!$user) {
		throw new Exception('contact_preferences form requires a user');
	}

	$lists = contact_preferences_form_options($user);

	$formwriter->checkboxList('new_list_subscribes', 'Check the box to subscribe:', [
		'options' => $lists['options'],
		'checked' => $lists['checked'],
	]);

	$formwriter->hiddeninput('zone', '', ['value' => 'optional']);
	$formwriter->submitbutton('btn_submit', 'Submit');
}

function contact_preferences_logic_descriptor(): array {
	return [
		'description'      => 'Update the user\'s mailing list subscriptions.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'new_list_subscribes' => ['type' => 'string', 'required' => false, 'label' => 'Mailing list IDs to subscribe to (array)'],
		],
	];
}
?>
