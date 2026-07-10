<?php

function admin_coupon_codes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'coupon_code_id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	$search_criteria = array();
	if($session->get_permission() < 10){
		$search_criteria['deleted'] = false;
	}

	$coupon_codes = new MultiCouponCode($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $coupon_codes->count_all();
	$coupon_codes->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['coupon_codes'] = $coupon_codes;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
