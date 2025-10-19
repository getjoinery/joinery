<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_coupon_code_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));
	require_once(PathHelper::getIncludePath('data/coupon_code_products_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	if (isset($get_vars['ccd_coupon_code_id'])) {
		$coupon_code = new CouponCode($get_vars['ccd_coupon_code_id'], TRUE);
	}
	else{
		$coupon_code = new CouponCode(NULL);
	}

	if($post_vars){
		if($post_vars['ccd_start_time_date'] && $post_vars['ccd_start_time_time']){
			$time_combined = $post_vars['ccd_start_time_date'] . ' ' . LibraryFunctions::toDBTime($post_vars['ccd_start_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
			$coupon_code->set('ccd_start_time', $utc_time);
			$coupon_code->set('ccd_start_time_local', $time_combined);
		}

		if($post_vars['ccd_end_time_date'] && $post_vars['ccd_end_time_time']){
			$time_combined = $post_vars['ccd_end_time_date'] . ' ' . LibraryFunctions::toDBTime($post_vars['ccd_end_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
			$coupon_code->set('ccd_end_time', $utc_time);
			$coupon_code->set('ccd_end_time_local', $time_combined);
		}

		if(empty($post_vars['ccd_amount_discount'])){
			$coupon_code->set('ccd_amount_discount', NULL);
		}
		else{
			$coupon_code->set('ccd_amount_discount', $post_vars['ccd_amount_discount']);
		}

		if(empty($post_vars['ccd_percent_discount'])){
			$post_vars['ccd_percent_discount'] = NULL;
		}
		else{
			$coupon_code->set('ccd_percent_discount', $post_vars['ccd_percent_discount']);
		}

		$post_vars['ccd_is_active'] = (bool)$post_vars['ccd_is_active'];
		$post_vars['ccd_is_stackable'] = (bool)$post_vars['ccd_is_stackable'];
		$post_vars['ccd_code'] = strtolower($post_vars['ccd_code']);

		if(!$post_vars['ccd_max_num_uses']){
			$post_vars['ccd_max_num_uses'] = 0;
		}

		if(!$post_vars['ccd_applies_to']){
			$post_vars['ccd_applies_to'] = 0;
		}

		if(!$post_vars['ccd_usr_user_id_affiliate']){
			$post_vars['ccd_usr_user_id_affiliate'] = null;
		}

		$editable_fields = array('ccd_code', 'ccd_is_active', 'ccd_usr_user_id_affiliate', 'ccd_is_stackable', 'ccd_max_num_uses', 'ccd_applies_to');

		foreach($editable_fields as $field) {
			$coupon_code->set($field, $post_vars[$field]);
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
		if($post_vars['ccd_applies_to'] == 3){
			foreach ($get_vars['products_list'] as $product_id){
				$coupon_code_product = new CouponCodeProduct(NULL);
				$coupon_code_product->set('ccp_ccd_coupon_code_id', $coupon_code->key);
				$coupon_code_product->set('ccp_pro_product_id', $product_id);
				$coupon_code_product->save();
			}
		}

		return LogicResult::redirect('/admin/admin_coupon_code?ccd_coupon_code_id='.$coupon_code->key);
	}

	$page_vars = array(
		'coupon_code' => $coupon_code,
		'session' => $session,
		'currency_symbol' => $currency_symbol,
	);

	return LogicResult::render($page_vars);
}

?>
