<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_api_key_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$settings = Globalvars::get_instance();

	if (isset($post_vars['edit_primary_key_value'])) {
		$api_key = new ApiKey($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['apk_api_key_id'])) {
		$api_key = new ApiKey($get_vars['apk_api_key_id'], TRUE);
	}
	else {
		$api_key = new ApiKey(NULL);
	}

	if($post_vars){

		$editable_fields = array('apk_name','apk_is_active','apk_permission', 'apk_ip_restriction');

		foreach($editable_fields as $field) {
			$api_key->set($field, $post_vars[$field]);
		}

		// Process datetime fields using FormWriter V2 helper
		$start_time = FormWriterV2Base::process_datetimeinput($post_vars, 'apk_start_time', true);
		if($start_time !== NULL){
			$api_key->set('apk_start_time', $start_time);
		}

		$expires_time = FormWriterV2Base::process_datetimeinput($post_vars, 'apk_expires_time', true);
		if($expires_time !== NULL){
			$api_key->set('apk_expires_time', $expires_time);
		}

		$api_key->set('apk_usr_user_id', $session->get_user_id());

		// Only set keys if creating new API key (not on edit)
		if(!$api_key->key){
			$public_key = 'public_'.LibraryFunctions::random_string(16);
			$secret_key = 'secret_'.LibraryFunctions::random_string(16);
			$api_key->set('apk_public_key', $public_key);
			$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_key));
		}

		$api_key->prepare();
		$api_key->save();
		$api_key->load();

		return LogicResult::redirect('/admin/admin_api_key?apk_api_key_id='. $api_key->key);
	}

	$page_vars = array(
		'api_key' => $api_key,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
