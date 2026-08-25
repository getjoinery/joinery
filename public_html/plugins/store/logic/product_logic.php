<?php
function product_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
	require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/product_requirement_instances_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = [];
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('products_active')){
		return LogicResult::error('This feature is turned off');
	}

	$product = null;
	if (!empty($input['slug'])) {
		$product = Product::get_by_link($input['slug']);
	} elseif (!empty($input['product_id'])) {
		$product_id = LibraryFunctions::fetch_variable_local($input, 'product_id', NULL, TRUE, 'Product ID is required', TRUE, 'int');
		$product = new Product($product_id, TRUE);
	}
	if (!$product || !$product->key) {
		require_once(LibraryFunctions::display_404_page());
	}

	if(!empty($input['product_version_id'])){
		$product_version_id = LibraryFunctions::fetch_variable_local($input, 'product_version_id', NULL, FALSE, '', TRUE, 'int');
		$product_version = new ProductVersion($product_version_id, TRUE);
		$page_vars['product_version'] = $product_version;
	}
	else {
		// If no specific version requested, get the first active version
		$product_versions = $product->get_product_versions(TRUE);
		if ($product_versions && count($product_versions) > 0) {
			$page_vars['product_version'] = $product_versions->get(0);
		} else {
			$page_vars['product_version'] = null;
		}
	}

	if ($product && $session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$product->get('pro_is_active') || $product->get('pro_delete_time')){
			require_once(LibraryFunctions::display_404_page());
		}
	}
	$page_vars['product'] = $product;

	$page_vars['currency_symbol'] = CurrencyHelper::symbol(strtolower($settings->get_setting('site_currency'))) ?? '$';

	$page_vars['display_empty_form'] = TRUE;

	if ($session->get_user_id()) {
		$user = new User($session->get_user_id(), TRUE);
	}
	else{
		$user = NULL;
	}
	$page_vars['user'] = $user;

	// Own-once products: a viewer who already owns this sees "you already own
	// this" where the buy controls would be. Anonymous viewers see the normal
	// buy button — we do not know who they are, and checkout is the authority.
	$ownership_tag = trim((string)$product->get('pro_ownership_tag'));
	$page_vars['already_owned'] = ($ownership_tag !== '' && $user
		&& Ownership::user_owns($user->key, $ownership_tag));

	// Handle edit_item mode: pre-fill form with existing cart item data
	$edit_item_index = isset($input['edit_item']) ? intval($input['edit_item']) : null;
	if ($edit_item_index !== null && empty($_POST)) {
		$cart = ShoppingCart::current();
		$cart_item = $cart->get_item($edit_item_index);
		if ($cart_item) {
			$page_vars['edit_item_index'] = $edit_item_index;
			$page_vars['prefill_data'] = $cart_item[2]; // form_data is element [2]
		}
	}

	if (!empty($_POST)) {

		try {
			list($form_data, $display_data) = $product->validate_form($_POST, $session);
		}
		catch (BasicProductRequirementException $e) {
			return LogicResult::error($e->getMessage());
		}

		// A requirement may contribute companion lines priced from the buyer's
		// own validated answers — a registered domain's one-year fee, say. The
		// store never learns what those lines are for: the requirement returns
		// a product id and the form data that prices it, and both are derived
		// server-side.
		$companion_lines = function ($form_data) use ($product) {
			$lines = array();
			foreach ($product->get_product_requirements() as $requirement) {
				foreach ($requirement->extra_cart_lines($form_data, $product) as $line) {
					$line_product_id = (int)($line['product_id'] ?? 0);
					if ($line_product_id <= 0) {
						continue;
					}
					$line_product = new Product($line_product_id, TRUE);
					if (!$line_product->key) {
						error_log('product_logic: ' . get_class($requirement) . ' asked for cart line '
							. 'product #' . $line_product_id . ', which does not load — line skipped.');
						continue;
					}
					$line_data = (array)($line['form_data'] ?? array());
					if (empty($line_data['product_version'])) {
						// A companion product has one version; the requirement
						// names a product, not a SKU. Resolving it here is also
						// what keeps a parent's version id from leaking onto a
						// line it does not belong to.
						$line_versions = $line_product->get_product_versions(TRUE);
						if (!$line_versions || count($line_versions) === 0) {
							error_log('product_logic: cart line product #' . $line_product_id
								. ' has no active version — line skipped.');
							continue;
						}
						$line_data['product_version'] = $line_versions->get(0)->key;
					}
					$lines[] = array('product' => $line_product, 'form_data' => $line_data);
				}
			}
			return $lines;
		};

		try {
			$cart = ShoppingCart::current();

			// Check if we're updating an existing cart item
			$edit_index = isset($input['edit_item_index']) ? intval($input['edit_item_index']) : null;
			if ($edit_index !== null && $cart->get_item($edit_index) !== null) {
				// The companion lines this item contributed were priced from the
				// answers being replaced. Editing the parent without them would
				// leave the cart charging for the buyer's previous answer — a
				// different domain, at a different price — so the old ones come
				// out before the new ones go in. They are found by matching the
				// exact form data the requirement produced from the OLD answers,
				// so another item's identical companion line is never touched.
				// That matching is load-bearing on extra_cart_lines() being
				// deterministic for identical form data — a requirement that
				// varied its output for the same answers would strand its own
				// old line here.
				$stale = $companion_lines($cart->get_item($edit_index)[2]);
				$cart->update_item($edit_index, $form_data);
				foreach ($stale as $line) {
					foreach ($cart->items as $key => $cart_item) {
						if ((int)$cart_item[1]->key !== (int)$line['product']->key) {
							continue;
						}
						$matches = true;
						foreach ($line['form_data'] as $name => $value) {
							if (!array_key_exists($name, $cart_item[2]) || $cart_item[2][$name] != $value) {
								$matches = false;
								break;
							}
						}
						if ($matches) {
							$cart->remove_item($key);
							break;
						}
					}
				}
				foreach ($companion_lines($form_data) as $line) {
					$cart->add_item($line['product'], $line['form_data']);
				}
			} else {
				// New item — add to cart
				if(!empty($input['user_price'])){
					$donation_id = (int)$settings->get_setting('store_optional_donation_product_id');
					if($donation_id > 0){
						$extra_donation = new Product($donation_id, TRUE);
						$cart->add_item($extra_donation, $form_data);
					}
					else{
						error_log('product_logic: buyer entered a donation amount but '
							. 'store_optional_donation_product_id is not configured — donation skipped.');
					}
				}
				$cart->add_item($product, $form_data);
				foreach ($companion_lines($form_data) as $line) {
					$cart->add_item($line['product'], $line['form_data']);
				}
			}
		}
		catch (ShoppingCartException $e) {
			return LogicResult::error($e->getMessage());
		}

		$settings = Globalvars::get_instance();
		$dest = $settings->get_setting('cart_intermediate_page') ? '/cart' : '/checkout';
		return LogicResult::redirect($dest);
	}

	$page_vars['cart'] = ShoppingCart::current();

	return LogicResult::render($page_vars);
}
?>