<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function pricing_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));

	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/product_versions_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	$pricing_active = $settings->get_setting('pricing_page');
	if(!$pricing_active){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	// Determine billing period (month or year)
	$page_choice = isset($get_vars['page']) ? $get_vars['page'] : 'month';
	$billing_period = ($page_choice == 'year') ? 'year' : 'month';
	$page_vars['page_choice'] = $page_choice;

	// Get all active subscription tiers ordered by tier_level
	$tiers = MultiSubscriptionTier::GetAllActive();

	// For each tier, get associated products and their best version
	$tier_display_data = array();
	foreach ($tiers as $tier) {
		$products = new MultiProduct(array(
			'pro_sbt_subscription_tier_id' => $tier->key,
			'is_active' => TRUE,
			'deleted' => FALSE
		));
		$products->load();

		foreach ($products as $product) {
			// Get public versions for this billing period, ordered by priority
			$versions = new MultiProductVersion(array(
				'product_id' => $product->key,
				'prv_display_priority' => '> 0',
				'is_active' => TRUE
			), array('prv_display_priority' => 'DESC'));
			$versions->load();

			// Find the best matching version for this billing period
			$display_version = null;
			foreach ($versions as $version) {
				if ($version->get('prv_price_type') == $billing_period) {
					$display_version = $version;
					break;  // Take highest priority
				}
			}

			if ($display_version) {
				$tier_display_data[] = array(
					'tier' => $tier,
					'product' => $product,
					'version' => $display_version
				);
			}
		}
	}

	$page_vars['tier_display_data'] = $tier_display_data;
	$page_vars['numrecords'] = count($tier_display_data);
	$page_vars['currency_symbol'] = Product::$currency_symbols[$settings->get_setting('site_currency')];

	return LogicResult::render($page_vars);
}
?>
