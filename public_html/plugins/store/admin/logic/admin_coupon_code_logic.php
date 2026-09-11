<?php

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

function admin_coupon_code_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$coupon_code = new CouponCode($input['ccd_coupon_code_id'], TRUE);

	if($input['action'] == 'remove'){
		$coupon_code->assert_can_write($session);
		$coupon_code->permanent_delete();

		//$returncoupon_code = $session->get_return();
		return LogicResult::redirect("/plugins/store/admin/admin_coupon_codes");
	}

	$settings = Globalvars::get_instance();
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));

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
