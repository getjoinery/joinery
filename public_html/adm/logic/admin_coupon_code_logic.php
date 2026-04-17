<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));
require_once(PathHelper::getIncludePath('data/products_class.php'));

function admin_coupon_code_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$coupon_code = new CouponCode($get_vars['ccd_coupon_code_id'], TRUE);

	if($get_vars['action'] == 'remove'){
		$coupon_code->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$coupon_code->permanent_delete();

		//$returncoupon_code = $session->get_return();
		return LogicResult::redirect("/admin/admin_coupon_codes");
	}

	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	$stackable = '(Not stackable)';
	if($coupon_code->get('ccd_is_stackable')){
		$stackable = '(Stackable)';
	}

	$page_vars = array(
		'session' => $session,
		'coupon_code' => $coupon_code,
		'settings' => $settings,
		'currency_symbol' => $currency_symbol,
		'stackable' => $stackable,
	);

	return LogicResult::render($page_vars);
}
