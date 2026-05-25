<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_mailing_list_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($input['mlt_mailing_list_id'])) {
		$mailing_list = new MailingList($input['mlt_mailing_list_id'], TRUE);
	} else {
		$mailing_list = new MailingList(NULL);
	}

	if($input){

		$editable_fields = array('mlt_name', 'mlt_description', 'mlt_is_active', 'mlt_visibility', 'mlt_provider_list_id', 'mlt_ctt_contact_type_id', 'mlt_emt_email_template_id', 'mlt_fil_file_id');
		$integer_fields = array('mlt_ctt_contact_type_id', 'mlt_emt_email_template_id', 'mlt_fil_file_id');

		foreach($editable_fields as $field) {
			if(isset($input[$field])) {
				$value = $input[$field];
				// Convert empty strings to NULL for integer fields
				if(in_array($field, $integer_fields) && $value === '') {
					$value = NULL;
				}
				$mailing_list->set($field, $value);
			}
		}

		if(!$mailing_list->get('mlt_link') || $_SESSION['permission'] == 10){
			if($input['mlt_link']){
				$mailing_list->set('mlt_link', $mailing_list->create_url($input['mlt_link']));
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
