<?php
/**
 * Orders profile logic — full order history with pagination, plus what the
 * user owns (own-once products, and any license key attached to one).
 *
 * @version 1.3
 */

function orders_profile_logic(array $input): LogicResult {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);

	$numperpage = 10;
	$page_offset = isset($input['offset']) ? max(0, (int)$input['offset']) : 0;

	$search_criteria = array();
	$search_criteria['user_id'] = $session->get_user_id();

	$orders = new MultiOrder(
		$search_criteria,
		array('ord_order_id' => 'DESC'),
		$numperpage,
		$page_offset
	);
	$numorders = $orders->count_all();
	$orders->load();

	$pager = new Pager(array('numrecords' => $numorders, 'numperpage' => $numperpage));

	$page_vars['orders'] = $orders;
	$page_vars['numorders'] = $numorders;
	$page_vars['pager'] = $pager;

	$ownerships = new MultiOwnership(
		array('user_id' => $session->get_user_id(), 'revoked' => FALSE),
		array('own_ownership_id' => 'DESC')
	);
	$ownerships->load();
	$page_vars['ownerships'] = $ownerships;

	// A tag is plumbing — a derived one reads 'product-17'. Show the buyer the
	// product name(s) carrying the tag; the raw tag only if nothing does.
	$ownership_labels = array();
	if ($ownerships->count() > 0) {
		$tagged_products = new MultiProduct(array('has_ownership_tag' => TRUE));
		foreach ($tagged_products as $tagged_product) {
			$product_tag = trim((string)$tagged_product->get('pro_ownership_tag'));
			$ownership_labels[$product_tag] = isset($ownership_labels[$product_tag])
				? $ownership_labels[$product_tag] . ', ' . $tagged_product->get('pro_name')
				: $tagged_product->get('pro_name');
		}
	}
	$page_vars['ownership_labels'] = $ownership_labels;


	return LogicResult::render($page_vars);
}
?>
