<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_coupon_code_edit_logic.php'));

	$page_vars = process_logic(admin_coupon_code_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'coupon-codes',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products',
			'Coupon Codes'=>'/admin/admin_coupon_codes',
			'Edit Coupon Code' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = "Edit Coupon Code";
	$page->begin_box($pageoptions);

	// Prepare override values for defaults (timezone conversion is automatic)
	$override_values = [];

	if(!$coupon_code->key){
		// Set default is_active for new coupons
		$override_values['ccd_is_active'] = 1;
	}

	// Use V2 with model + override values
	// Datetime fields with UTC values are automatically converted to user's local timezone
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'debug' => true,
		'model' => $coupon_code,        // Auto-fills all model fields
		'values' => $override_values,   // Overrides specific fields (defaults)
		'form_debug' => true
	]);

	echo $formwriter->begin_form();

	if($coupon_code->key){
		$formwriter->hiddeninput('ccd_coupon_code_id', ['value' => $coupon_code->key]);
		$formwriter->hiddeninput('action', ['value' => 'edit']);
	}

	$formwriter->textinput('ccd_code', 'Coupon code');

	$formwriter->dropinput('ccd_is_active', 'Active?', [
		'options' => ['Inactive' => 0, 'Active' => 1]
	]);

	$formwriter->textinput('ccd_amount_discount', 'Amount of discount ('.$currency_symbol.')', [
		'validation' => [
			'require_one_group' => [
				'value' => 'discount_fields',
				'message' => 'Please enter either an amount or percent discount'
			]
		]
	]);

	$formwriter->textinput('ccd_percent_discount', 'or percent of discount', [
		'validation' => [
			'require_one_group' => [
				'value' => 'discount_fields',
				'message' => 'Please enter either an amount or percent discount'
			]
		]
	]);

	$formwriter->dropinput('ccd_is_stackable', 'Is this coupon stackable?', [
		'options' => ['No' => 0, 'Yes' => 1]
	]);

	$formwriter->dropinput('ccd_applies_to', 'Applies to', [
		'options' => [
			'All products' => 0,
			'Subscriptions only' => 1,
			'One time purchases only' => 2,
			'Custom (below)' => 3
		],
		'visibility_rules' => [
			'' => [
				'show' => [],
				'hide' => ['products_list']
			],
			3 => [
				'show' => ['products_list'],
				'hide' => []
			],
			0 => [
				'show' => [],
				'hide' => ['products_list']
			],
			1 => [
				'show' => [],
				'hide' => ['products_list']
			],
			2 => [
				'show' => [],
				'hide' => ['products_list']
			]
		]
	]);

	//GET ALL PRODUCTS
	$searches = array();
	$sort = LibraryFunctions::fetch_variable('sort', 'product_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection));
	$products->load();
	$optionvals = $products->get_dropdown_array();

	if ($coupon_code->key) {
		//FILL THE CHECKED VALUES
		$checkedvals = array();
		$coupon_code_products = new MultiCouponCodeProduct(array(
			'coupon_code_id' => $coupon_code->key,
		));
		$coupon_code_products->load();
		foreach ($coupon_code_products as $coupon_code_product){
			$checkedvals[] = $coupon_code_product->get('ccp_pro_product_id');
		}
	}
	else{
		$checkedvals = array();
	}
	$formwriter->checkboxList('products_list', 'Valid products for this code', [
		'options' => $optionvals,
		'checked' => $checkedvals
	]);

	$formwriter->datetimeinput('ccd_start_time', 'Start time');

	$formwriter->datetimeinput('ccd_end_time', 'End time');

	$formwriter->textinput('ccd_max_num_uses', 'Maximum number of uses');

	// Pre-load only the currently selected affiliate user (if editing)
	$affiliate_options = [];
	if ($coupon_code->get('ccd_usr_user_id_affiliate')) {
		$affiliate_user = new User($coupon_code->get('ccd_usr_user_id_affiliate'), TRUE);
		// Format must match AJAX response: "Name - Email"
		$display_text = $affiliate_user->display_name() . ' - ' . $affiliate_user->get('usr_email');
		// Options array format: [label => value]
		$affiliate_options = [$display_text => $affiliate_user->key];
	}

	$formwriter->dropinput('ccd_usr_user_id_affiliate', 'Affiliate User for this coupon', [
		'options' => $affiliate_options,
		'validation' => ['required' => false],
		'ajaxendpoint' => '/ajax/user_search_ajax?includenone=1',
		'empty_option' => '-- Type 3+ characters to search users --'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	echo $formwriter->end_form();

$page->end_box();
	$page->admin_footer();

?>
