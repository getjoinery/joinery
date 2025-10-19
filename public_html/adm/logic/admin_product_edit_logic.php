<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_product_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('/data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('/data/products_class.php'));
	require_once(PathHelper::getIncludePath('/data/product_groups_class.php'));
	require_once(PathHelper::getIncludePath('/data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('/data/product_requirement_instances_class.php'));
	require_once(PathHelper::getIncludePath('/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('/data/events_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = Product::$currency_symbols[$currency_code];

	// Load or create product
	if (isset($get_vars['p']) || isset($post_vars['p'])) {
		$product_id = isset($post_vars['p']) ? $post_vars['p'] : $get_vars['p'];
		$product = new Product($product_id, TRUE);
	} else {
		$product = new Product(NULL);
	}

	// Process POST actions
	if ($post_vars || $get_vars['action']) {

		if ($post_vars['action'] == 'add' || $post_vars['action'] == 'edit') {

			if($post_vars['pro_requirements']){
				$total_value = 0;
				foreach ($post_vars['pro_requirements'] as $choice => $value){
					$total_value += $value;
				}
				$product->set('pro_requirements', $total_value);
			}

			$product->save_requirement_instances($post_vars['additional_pro_requirements']);

			if($post_vars['pro_evt_event_id'] == '' || $post_vars['pro_evt_event_id'] == 0){
				$product->set('pro_evt_event_id', NULL);

			}
			else{
				$product->set('pro_evt_event_id', intval($post_vars['pro_evt_event_id']));
			}

			//MUST BE INTEGER
			$product->set('pro_expires', (int)$post_vars['pro_expires']);
			$product->set('pro_prg_product_group_id', (int)$post_vars['pro_prg_product_group_id']);

			//PRICE MUST BE INTEGER
			if($post_vars['pro_grp_group_id']){
				$post_vars['pro_grp_group_id'] = (int)$post_vars['pro_grp_group_id'];
			}
			else{
				$post_vars['pro_grp_group_id'] = NULL;
			}

			// Handle subscription tier ID
			if($post_vars['pro_sbt_subscription_tier_id']){
				$post_vars['pro_sbt_subscription_tier_id'] = (int)$post_vars['pro_sbt_subscription_tier_id'];
			}
			else{
				$post_vars['pro_sbt_subscription_tier_id'] = NULL;
			}

			//STORE THE PRODUCT SCRIPTS
			$product->set('pro_product_scripts', NULL);
			if(is_array($post_vars['product_scripts'])){
				$product->set('pro_product_scripts', implode(',', $post_vars['product_scripts']));
			}

			$editable_fields = array('pro_name', 'pro_description', 'pro_max_purchase_count', 'pro_max_cart_count', 'pro_after_purchase_message','pro_is_active', 'pro_receipt_body', 'pro_grp_group_id', 'pro_sbt_subscription_tier_id', 'pro_digital_link', 'pro_short_description');

			foreach($editable_fields as $field) {
				$product->set($field, $post_vars[$field]);
			}

			if(!$product->get('pro_link') || $_SESSION['permission'] == 10){
				if($post_vars['pro_link']){
					$product->set('pro_link', $product->create_url($post_vars['pro_link']));
				}
				else{
					$product->set('pro_link', $product->create_url($event->get('pro_name')));
				}
			}

			$product->prepare();

			//IF STRIPE IS ENABLED, CREATE A PRODUCT
			if($settings->get_setting('checkout_type') != 'none'){
				$stripe_helper = new StripeHelper();
				$product_info=array();
				$product_info['name'] = $product->get('pro_name');
				//$product_info['description'] = '';

				if($stripe_helper->test_mode){
					if(!$product->get('pro_stripe_product_id_test')){
						$stripe_product = $stripe_helper->create_product($product_info);
						$product->set('pro_stripe_product_id_test', $stripe_product['id']);
						if(!$stripe_product['id']){
							throw new SystemDisplayablePermanentError("Unable to create a stripe product.");
						}
					}
				}
				else{
					if(!$product->get('pro_stripe_product_id')){
						$stripe_product = $stripe_helper->create_product($product_info);
						if(!$stripe_product['id']){
							throw new SystemDisplayablePermanentError("Unable to create a stripe product.");
						}
						$product->set('pro_stripe_product_id', $stripe_product['id']);
					}
				}

			}

			$product->save();
			$product->load();

		}

		if ($get_vars['action'] == 'new_version') {
			$product_version = new ProductVersion(NULL);
			$product_version->set('prv_pro_product_id', $product->key);
			$product_version->set('prv_version_name', $get_vars['version_name']);
			$product_version->set('prv_version_price', $get_vars['version_price']);
			$product_version->set('prv_price_type', $get_vars['prv_price_type']);
			$product_version->set('prv_trial_period_days', $get_vars['prv_trial_period_days']);
			$product_version->set('prv_status', 1);
			$product_version->prepare();
			$product_version->save();
		}
		else if ($get_vars['action'] == 'remove_version') {
			$product_version = new ProductVersion($get_vars['v'], TRUE);
			$product_version->set('prv_status', 0);
			$product_version->prepare();
			$product_version->save();
		}
		else if ($get_vars['action'] == 'activate_version') {
			$product_version = new ProductVersion($get_vars['v'], TRUE);
			$product_version->set('prv_status', 1);
			$product_version->prepare();
			$product_version->save();
		}

		if($post_vars['json_confirm']){
			echo json_encode($product->key);
		}
		return LogicResult::redirect('/admin/admin_product?pro_product_id='. $product->key);
	}

	// Load data for display
	if ($product->key) {
		$breadcrumb = 'Product '.$product->get('pro_name');
	}
	else{
		$breadcrumb = 'New Product';
	}

	// Load events for dropdown
	$events = new MultiEvent(
		array('deleted'=>false, 'past'=>false),
		NULL,
		NULL,
		NULL);
	$numevents = $events->count_all();
	if($numevents){
		$events->load();
	}

	// Load groups (bundles) for dropdown
	$groups = new MultiGroup(
		array('category'=>'event'),
		NULL,
		NULL,
		NULL,
	);
	$numbundles = $groups->count_all();
	if($numbundles){
		$groups->load();
	}

	// Load subscription tiers
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	$subscription_tiers = MultiSubscriptionTier::GetAllActive();

	// Load product groups
	$pgs = new MultiProductGroup(
		array(),
		NULL,
		NULL,
		NULL);
	$has_product_groups = $pgs->count_all();
	if($has_product_groups){
		$pgs->load();
	}

	// Load requirement instances
	$instances = $product->get_requirement_instances(false);

	// Load product requirements
	$product_requirements = new MultiProductRequirement(
		array('deleted'=>false),
		NULL,
		NULL,
		NULL);
	$has_product_requirements = $product_requirements->count_all();
	if($has_product_requirements){
		$product_requirements->load();
	}

	// Build product scripts options
	$product_scripts_optionvals = array();
	$product_scripts_optionvals = array_merge($product_scripts_optionvals, LibraryFunctions::getFunctionNamesFromFile(PathHelper::getRootDir() . '/logic/product_scripts_logic.php'));

	$plugins = LibraryFunctions::list_plugins();
	foreach($plugins as $plugin){
		// Check for hooks in the correct location: hooks/product_purchase.php
		$product_script_file = PathHelper::getRootDir().'/plugins/'.$plugin.'/hooks/product_purchase.php';
		if(file_exists($product_script_file)){
			$product_scripts_optionvals = array_merge($product_scripts_optionvals, LibraryFunctions::getFunctionNamesFromFile($product_script_file));
		}
	}

	// Return page variables for rendering
	return LogicResult::render(array(
		'product' => $product,
		'breadcrumb' => $breadcrumb,
		'currency_code' => $currency_code,
		'currency_symbol' => $currency_symbol,
		'events' => $events,
		'numevents' => $numevents,
		'groups' => $groups,
		'numbundles' => $numbundles,
		'subscription_tiers' => $subscription_tiers,
		'pgs' => $pgs,
		'has_product_groups' => $has_product_groups,
		'instances' => $instances,
		'product_requirements' => $product_requirements,
		'has_product_requirements' => $has_product_requirements,
		'product_scripts_optionvals' => $product_scripts_optionvals,
		'session' => $session,
		'settings' => $settings,
	));
}

?>
