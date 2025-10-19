<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_email_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('/data/emails_class.php'));
	require_once(PathHelper::getIncludePath('/data/mailing_lists_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

	// Load or create email
	if (isset($get_vars['eml_email_id']) || isset($post_vars['eml_email_id'])) {
		$eml_email_id = isset($post_vars['eml_email_id']) ? $post_vars['eml_email_id'] : $get_vars['eml_email_id'];
		$email = new Email($eml_email_id, TRUE);
	} else {
		$email = new Email(NULL);
	}

	// Process POST actions
	if($post_vars){

		if($post_vars['eml_mlt_mailing_list_id'] == ''){
			$post_vars['eml_mlt_mailing_list_id'] = NULL;
		}

		$editable_fields = array('eml_description', 'eml_subject', 'eml_from_address', 'eml_from_name', 'eml_message_html', 'eml_preview_text', 'eml_ctt_contact_type_id', 'eml_mlt_mailing_list_id');

		foreach($editable_fields as $field) {
			$email->set($field, $post_vars[$field]);
		}

		if(!$email->key){
			$email->set('eml_usr_user_id',$session->get_user_id());
			$email->set('eml_status', Email::EMAIL_CREATED);
		}

		$email->set('eml_reply_to',$post_vars['eml_from_address']);
		$email->set('eml_message_template_html', $post_vars['eml_message_template_html']);
		$email->set('eml_type', Email::TYPE_MARKETING);

		$email->prepare();
		$email->save();
		$email->load();

		return LogicResult::redirect('/admin/admin_email?eml_email_id='.$email->key);
	}

	// Load data for display
	$sitename = $settings->get_setting('site_name');
	$defaultemailname = $settings->get_setting('defaultemailname');
	$defaultemail = $settings->get_setting('defaultemail');

	// Load contact types for dropdown
	$contact_types = new MultiContactType(
		array('deleted'=>false),
		NULL,
		NULL,
		NULL);
	$contact_types->load();

	// Load mailing lists for dropdown
	$mailing_lists = new MultiMailingList(
		array('deleted'=>false, 'active'=> true),
		NULL,
		NULL,
		NULL);
	$mailing_lists->load();

	// Return page variables for rendering
	return LogicResult::render(array(
		'email' => $email,
		'sitename' => $sitename,
		'defaultemailname' => $defaultemailname,
		'defaultemail' => $defaultemail,
		'contact_types' => $contact_types,
		'mailing_lists' => $mailing_lists,
		'session' => $session,
		'settings' => $settings,
	));
}

?>
