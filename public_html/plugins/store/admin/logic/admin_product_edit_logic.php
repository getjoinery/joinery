<?php

function admin_product_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('/plugins/store/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('/data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('/plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('/plugins/store/data/product_groups_class.php'));
	require_once(PathHelper::getIncludePath('/plugins/store/data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('/plugins/store/data/product_requirement_instances_class.php'));
	require_once(PathHelper::getIncludePath('/plugins/store/data/order_items_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = CurrencyHelper::symbol($currency_code);

	// Load or create product
	// CRITICAL: Check edit_primary_key_value (form submission first), fallback to GET
	if (isset($input['edit_primary_key_value'])) {
		$product = new Product($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['pro_product_id'])) {
		$product = new Product($input['pro_product_id'], TRUE);
	} elseif (isset($input['p'])) {
		// Backward compatibility: old URL parameter 'p'
		$product = new Product($input['p'], TRUE);
	} else {
		$product = new Product(NULL);
	}

	// Photo action handlers (early return — skip full product save).
	// Intentional GET-action mutations — opt in to the GET-is-read-only tripwire.
	if ($product->key && isset($input['action']) && $input['action'] == 'set_primary_photo') {
		SystemBase::$allow_get_mutation = true;
		try { $product->set_primary_photo((int)$input['photo_id']); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/plugins/store/admin/admin_product_edit?pro_product_id='.$product->key);
	}

	if ($product->key && isset($input['action']) && $input['action'] == 'clear_primary_photo') {
		SystemBase::$allow_get_mutation = true;
		try { $product->clear_primary_photo(); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/plugins/store/admin/admin_product_edit?pro_product_id='.$product->key);
	}

	// Process POST actions
	// CRITICAL: Check for POST submission
	if (LibraryFunctions::isFormSubmission()) {
		// Add-only logic
		if (!$product->key) {
			$product->set('pro_created_by', $session->get_user_id());
		}

		// Build requirement instances from the unified checkbox list
		$requirement_specs = [];

		// System requirements (Tier 2 — class_name directly)
		if (!empty($input['system_requirements']) && is_array($input['system_requirements'])) {
			foreach ($input['system_requirements'] as $class_name) {
				$requirement_specs[] = ['class_name' => $class_name, 'config' => []];
			}
		}

		// Question requirements (Tier 1 — QuestionRequirement with question_id config)
		if (!empty($input['question_requirements']) && is_array($input['question_requirements'])) {
			foreach ($input['question_requirements'] as $question_id) {
				$requirement_specs[] = [
					'class_name' => 'QuestionRequirement',
					'config' => ['question_id' => intval($question_id)],
				];
			}
		}

		// Fulfillment picker: value is "" (none) or "{provider}:{ref}".
		$fulfillment = isset($input['fulfillment']) ? $input['fulfillment'] : '';
		if($fulfillment === ''){
			$product->set('pro_fulfillment_provider', NULL);
			$product->set('pro_fulfillment_ref', NULL);
		}
		else{
			list($fp_provider, $fp_ref) = array_pad(explode(':', $fulfillment, 2), 2, NULL);
			$product->set('pro_fulfillment_provider', $fp_provider);
			$product->set('pro_fulfillment_ref', ($fp_ref === NULL || $fp_ref === '') ? NULL : (int)$fp_ref);
		}

		//MUST BE INTEGER
		$product->set('pro_expires', (int)$input['pro_expires']);
		$product->set('pro_prg_product_group_id', (int)$input['pro_prg_product_group_id']);

		//PRICE MUST BE INTEGER
		if($input['pro_grp_group_id']){
			$input['pro_grp_group_id'] = (int)$input['pro_grp_group_id'];
		}
		else{
			$input['pro_grp_group_id'] = NULL;
		}

		// Event-bundle products (a group of events) fulfill through the same
		// event_registration provider; mark them so checkout invokes it. This
		// bundle→provider coupling folds into the fulfillment provider's own
		// picker when the bundle picker moves to event_manager.
		if($input['pro_grp_group_id'] && !$product->get('pro_fulfillment_provider')){
			$product->set('pro_fulfillment_provider', 'event_registration');
		}

		// Handle subscription tier ID
		if($input['pro_sbt_subscription_tier_id']){
			$input['pro_sbt_subscription_tier_id'] = (int)$input['pro_sbt_subscription_tier_id'];
		}
		else{
			$input['pro_sbt_subscription_tier_id'] = NULL;
		}

		//STORE THE PRODUCT SCRIPTS
		$product->set('pro_product_scripts', NULL);
		if(is_array($input['product_scripts'])){
			$product->set('pro_product_scripts', implode(',', $input['product_scripts']));
		}

		$editable_fields = array('pro_name', 'pro_description', 'pro_max_purchase_count', 'pro_max_cart_count', 'pro_after_purchase_message','pro_is_active', 'pro_grp_group_id', 'pro_sbt_subscription_tier_id', 'pro_digital_link', 'pro_short_description', 'pro_emt_receipt_template_id', 'pro_licensed_plugin');

		foreach($editable_fields as $field) {
			$product->set($field, $input[$field]);
		}

		if(!$product->get('pro_link') || $_SESSION['permission'] == 10){
			if($input['pro_link']){
				$product->set('pro_link', $product->create_url($input['pro_link']));
			}
			else{
				$product->set('pro_link', $product->create_url($product->get('pro_name')));
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
						return LogicResult::error('Unable to create a stripe product.');
					}
				}
			}
			else{
				if(!$product->get('pro_stripe_product_id')){
					$stripe_product = $stripe_helper->create_product($product_info);
					if(!$stripe_product['id']){
						return LogicResult::error('Unable to create a stripe product.');
					}
					$product->set('pro_stripe_product_id', $stripe_product['id']);
				}
			}

		}

		$product->save();
		$product->load();

		$product->save_requirement_instances($requirement_specs);

		return LogicResult::redirect('/plugins/store/admin/admin_product?pro_product_id='. $product->key);
	}

	// Handle GET actions for version management.
	// Intentional GET-action mutations — opt in to the GET-is-read-only tripwire.
	if (($input['action'] ?? '') == 'new_version') {
		$product_version = new ProductVersion(NULL);
		$product_version->set('prv_pro_product_id', $product->key);
		$product_version->set('prv_version_name', $input['version_name']);
		$product_version->set('prv_version_price', $input['version_price']);
		$product_version->set('prv_price_type', $input['prv_price_type']);
		$product_version->set('prv_trial_period_days', $input['prv_trial_period_days']);
		$product_version->set('prv_status', 1);
		SystemBase::$allow_get_mutation = true;
		try { $product_version->prepare(); $product_version->save(); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/plugins/store/admin/admin_product?pro_product_id='. $product->key);
	}
	else if (($input['action'] ?? '') == 'remove_version') {
		$product_version = new ProductVersion($input['v'], TRUE);
		$product_version->set('prv_status', 0);
		SystemBase::$allow_get_mutation = true;
		try { $product_version->prepare(); $product_version->save(); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/plugins/store/admin/admin_product?pro_product_id='. $product->key);
	}
	else if (($input['action'] ?? '') == 'activate_version') {
		$product_version = new ProductVersion($input['v'], TRUE);
		$product_version->set('prv_status', 1);
		SystemBase::$allow_get_mutation = true;
		try { $product_version->prepare(); $product_version->save(); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/plugins/store/admin/admin_product?pro_product_id='. $product->key);
	}

	// Load data for display
	if ($product->key) {
		$breadcrumb = 'Product '.$product->get('pro_name');
	}
	else{
		$breadcrumb = 'New Product';
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

	// Load email templates for the receipt-template override dropdown
	$receipt_templates = new MultiEmailTemplateStore(
		array(),
		array('emt_name' => 'ASC'),
		NULL,
		NULL);
	if ($receipt_templates->count_all()) {
		$receipt_templates->load();
	}

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

	// Load requirement instances for the product
	$instances = $product->get_requirement_instances(false);

	// Load the requirement registry for the unified checkbox list
	require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
	$grouped_requirements = AbstractProductRequirement::getGrouped();

	// Build product scripts options
	$product_scripts_optionvals = array();
	$product_scripts_optionvals = array_merge($product_scripts_optionvals, LibraryFunctions::getFunctionNamesFromFile(PathHelper::getIncludePath('hooks/product_purchase.php')));

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
		'groups' => $groups,
		'numbundles' => $numbundles,
		'subscription_tiers' => $subscription_tiers,
		'pgs' => $pgs,
		'has_product_groups' => $has_product_groups,
		'instances' => $instances,
		'grouped_requirements' => $grouped_requirements,
		'product_scripts_optionvals' => $product_scripts_optionvals,
		'receipt_templates' => $receipt_templates,
		'session' => $session,
		'settings' => $settings,
	));
}

?>
