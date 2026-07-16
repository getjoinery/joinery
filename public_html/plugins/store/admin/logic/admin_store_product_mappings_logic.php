<?php

function admin_store_product_mappings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/mobile_store_products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);

	$search_criteria = array('deleted' => false);

	$mappings = new MultiMobileStoreProduct($search_criteria, array('mobile_store_product_id' => 'DESC'), $numperpage, $offset);
	$numrecords = $mappings->count_all();
	$mappings->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['mappings'] = $mappings;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
