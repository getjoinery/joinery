<?php

function admin_store_product_mapping_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/mobile_store_products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$mapping_id = $input['msp_mobile_store_product_id'] ?? NULL;

	if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
		$mapping = new MobileStoreProduct($input['edit_primary_key_value'], TRUE);
	} elseif ($mapping_id) {
		$mapping = new MobileStoreProduct($mapping_id, TRUE);
	} else {
		$mapping = new MobileStoreProduct(NULL);
	}

	// The plan dropdown covers every active tier-granting product, one entry
	// per billing period (product version), plus a product-only entry for
	// versionless products. Values encode the pair: "P:{product}" or
	// "V:{product}:{version}".
	$plan_options = array();
	$products = new MultiProduct(array('is_active' => true, 'deleted' => false), array('product_id' => 'ASC'));
	$products->load();
	foreach ($products as $product) {
		if (!$product->get('pro_sbt_subscription_tier_id')) {
			continue;
		}
		$tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
		$tier_name = $tier->key ? ($tier->get('sbt_display_name') ?: $tier->get('sbt_name')) : '?';
		$label_base = $product->get('pro_name') . ' — ' . $tier_name . ' tier';

		$versions = new MultiProductVersion(array('product_id' => $product->key, 'is_active' => true));
		$versions->load();
		$has_versions = false;
		foreach ($versions as $version) {
			$has_versions = true;
			$period = $version->is_subscription() ?: 'one-time';
			$plan_options['V:' . $product->key . ':' . $version->key] =
				$label_base . ' (' . $version->get('prv_version_name') . ', ' . $period . ')';
		}
		if (!$has_versions) {
			$plan_options['P:' . $product->key] = $label_base;
		}
	}

	if (LibraryFunctions::isFormSubmission()) {
		$mapping->set('msp_store', $input['msp_store'] ?? '');
		$mapping->set('msp_store_product_id', trim($input['msp_store_product_id'] ?? ''));
		$mapping->set('msp_is_active', (bool)($input['msp_is_active'] ?? 0));
		$mapping->set('msp_notes', $input['msp_notes'] ?? '');

		$plan = $input['plan'] ?? '';
		if (preg_match('/^V:(\d+):(\d+)$/', $plan, $matches)) {
			$mapping->set('msp_pro_product_id', (int)$matches[1]);
			$mapping->set('msp_prv_product_version_id', (int)$matches[2]);
		} elseif (preg_match('/^P:(\d+)$/', $plan, $matches)) {
			$mapping->set('msp_pro_product_id', (int)$matches[1]);
			$mapping->set('msp_prv_product_version_id', NULL);
		} else {
			return LogicResult::error('Please choose the plan this store product sells.');
		}

		// One active mapping per (store, store product ID).
		$existing = MobileStoreProduct::GetByStoreProductId($mapping->get('msp_store'), $mapping->get('msp_store_product_id'));
		if ($existing !== NULL && $mapping->get('msp_is_active') && (int)$existing->key !== (int)$mapping->key) {
			return LogicResult::error('An active mapping for that store product ID already exists.');
		}

		$mapping->prepare();
		$mapping->save();
		$mapping->load();

		return LogicResult::redirect('/plugins/store/admin/admin_store_product_mappings');
	}

	// Current plan value for the form
	$plan_value = '';
	if ($mapping->get('msp_pro_product_id')) {
		$plan_value = $mapping->get('msp_prv_product_version_id')
			? 'V:' . $mapping->get('msp_pro_product_id') . ':' . $mapping->get('msp_prv_product_version_id')
			: 'P:' . $mapping->get('msp_pro_product_id');
	}

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['mapping'] = $mapping;
	$page_vars['plan_options'] = $plan_options;
	$page_vars['plan_value'] = $plan_value;

	return LogicResult::render($page_vars);
}
?>
