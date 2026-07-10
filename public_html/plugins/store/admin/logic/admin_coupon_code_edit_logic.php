<?php

function admin_coupon_code_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/coupon_code_products_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$settings = Globalvars::get_instance();
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));

	// Check GET for initial load, POST for form submission (uses edit_primary_key_value)
	$coupon_code_id = $input['ccd_coupon_code_id'] ?? NULL;

	if (isset($input['edit_primary_key_value'])) {
		$coupon_code = new CouponCode($input['edit_primary_key_value'], TRUE);
	} elseif ($coupon_code_id) {
		$coupon_code = new CouponCode($coupon_code_id, TRUE);
	}
	else{
		$coupon_code = new CouponCode(NULL);
	}

	if(LibraryFunctions::isFormSubmission()){
		// Handle start time using FormWriterV2Base helper
		$start_time = FormWriterV2Base::process_datetimeinput($input, 'ccd_start_time', true);
		if($start_time !== NULL){
			$coupon_code->set('ccd_start_time', $start_time);
		}

		// Handle end time using FormWriterV2Base helper
		$end_time = FormWriterV2Base::process_datetimeinput($input, 'ccd_end_time', true);
		if($end_time !== NULL){
			$coupon_code->set('ccd_end_time', $end_time);
		}

		if(empty($input['ccd_amount_discount'])){
			$coupon_code->set('ccd_amount_discount', NULL);
		}
		else{
			$coupon_code->set('ccd_amount_discount', $input['ccd_amount_discount']);
		}

		if(empty($input['ccd_percent_discount'])){
			$input['ccd_percent_discount'] = NULL;
		}
		else{
			$coupon_code->set('ccd_percent_discount', $input['ccd_percent_discount']);
		}

		$input['ccd_is_active'] = (bool)$input['ccd_is_active'];
		$input['ccd_is_stackable'] = (bool)$input['ccd_is_stackable'];
		$input['ccd_code'] = strtolower($input['ccd_code']);

		if(!$input['ccd_max_num_uses']){
			$input['ccd_max_num_uses'] = 0;
		}

		if(!$input['ccd_applies_to']){
			$input['ccd_applies_to'] = 0;
		}

		if(!$input['ccd_usr_user_id_affiliate']){
			$input['ccd_usr_user_id_affiliate'] = null;
		}

		$editable_fields = array('ccd_code', 'ccd_is_active', 'ccd_usr_user_id_affiliate', 'ccd_is_stackable', 'ccd_max_num_uses', 'ccd_applies_to');

		foreach($editable_fields as $field) {
			$coupon_code->set($field, $input[$field]);
		}

		$coupon_code->prepare();
		$coupon_code->save();
		$coupon_code->load();

		//CLEAR ALL ENTRIES
		$searches = array('coupon_id' => $coupon_code->key);
		$coupon_code_products = new MultiCouponCodeProduct($searches);
		$coupon_code_products->load();
		foreach($coupon_code_products as $coupon_code_product){
			$coupon_code_product->permanent_delete();
		}

		//LOAD THE NEW ENTRIES
		if($input['ccd_applies_to'] == 3){
			foreach (($input['products_list'] ?? []) as $product_id){
				$coupon_code_product = new CouponCodeProduct(NULL);
				$coupon_code_product->set('ccp_ccd_coupon_code_id', $coupon_code->key);
				$coupon_code_product->set('ccp_pro_product_id', $product_id);
				$coupon_code_product->save();
			}
		}

		return LogicResult::redirect('/plugins/store/admin/admin_coupon_code?ccd_coupon_code_id='.$coupon_code->key);
	}

	$page_vars = array(
		'coupon_code' => $coupon_code,
		'session' => $session,
		'currency_symbol' => $currency_symbol,
	);

	return LogicResult::render($page_vars);
}

?>
