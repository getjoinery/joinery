<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_subscription_tiers_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'subscription_tier_id');
	$sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');

	$search_criteria = array();
	if($session->get_permission() < 10){
		$search_criteria['deleted'] = false;
	}

	$subscription_tiers = new MultiSubscriptionTier($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $subscription_tiers->count_all();
	$subscription_tiers->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['subscription_tiers'] = $subscription_tiers;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
