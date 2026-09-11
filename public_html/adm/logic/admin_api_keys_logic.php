<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_api_keys_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'api_key_id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	// Machine keys (integrations) by default; the filter exposes user session
	// keys for fleet-wide visibility and revocation.
	$type = LibraryFunctions::fetch_variable_local($input, 'filter', ApiKey::TYPE_MACHINE);
	if (!in_array($type, array(ApiKey::TYPE_MACHINE, ApiKey::TYPE_SESSION))) {
		$type = ApiKey::TYPE_MACHINE;
	}

	$search_criteria = array('type' => $type);
	$api_keys = new MultiApiKey($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $api_keys->count_all();
	$api_keys->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['api_keys'] = $api_keys;
	$page_vars['type'] = $type;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
