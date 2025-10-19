<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_mailing_list_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($get_vars['mlt_mailing_list_id'])) {
		$mailing_list = new MailingList($get_vars['mlt_mailing_list_id'], TRUE);
	} else {
		$mailing_list = new MailingList(NULL);
	}

	if($post_vars){

		$editable_fields = array('mlt_name', 'mlt_description', 'mlt_is_active', 'mlt_visibility');

		if($mailing_list->get('mlt_fil_file_id')){
			$mailing_list->set('mlt_fil_file_id', $post_vars['mlt_fil_file_id']);
		}

		if($mailing_list->get('mlt_emt_email_template_id')){
			$mailing_list->set('mlt_emt_email_template_id', $post_vars['mlt_emt_email_template_id']);
		}

		if($mailing_list->get('mlt_mailchimp_list_id')){
			$mailing_list->set('mlt_mailchimp_list_id', $post_vars['mlt_mailchimp_list_id']);
		}

		if($mailing_list->get('mlt_ctt_contact_type_id')){
			$mailing_list->set('mlt_ctt_contact_type_id', $post_vars['mlt_ctt_contact_type_id']);
		}

		foreach($editable_fields as $field) {
			$mailing_list->set($field, $post_vars[$field]);
		}

		if(!$mailing_list->get('mlt_link') || $_SESSION['permission'] == 10){
			if($post_vars['mlt_link']){
				$mailing_list->set('mlt_link', $mailing_list->create_url($post_vars['mlt_link']));
			}
			else{
				$mailing_list->set('mlt_link', $mailing_list->create_url($mailing_list->get('mlt_name')));
			}
		}

		$mailing_list->prepare();
		$mailing_list->save();
		$mailing_list->load();

		return LogicResult::redirect('/admin/admin_mailing_list?mlt_mailing_list_id='.$mailing_list->key);
	}

	$page_vars = array(
		'mailing_list' => $mailing_list,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
