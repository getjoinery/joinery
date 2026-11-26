<?php
/** @joinery-admin class=money */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_product_version_edit_logic.php'));

$page_vars = process_logic(admin_product_version_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'products-list',
	'page_title' => 'Products Version Edit',
	'readable_title' => 'Product Version Edit',
	'breadcrumbs' => array(
		'Products'=>'/plugins/store/admin/admin_products',
		$breadcrumb => '',
		'Product Version Edit'=>'',
	),
	'session' => $session,
)
);

$page->begin_box($pageoptions);

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', [
	'model' => $product_version,
	'edit_primary_key_value' => $product_version->key
]);

$formwriter->begin_form();

$formwriter->hiddeninput('product_id', '', ['value' => $product->key]);

// The amounts a buyer may type when the buyer chooses the price.
$range_fields = function ($formwriter) use ($product_version, $currency_symbol) {
	$formwriter->textinput('prv_min_price', 'Minimum amount ('.$currency_symbol.')', [
		'value' => $product_version->get('prv_min_price'),
		'helptext' => 'The least a buyer may pay. Leave empty for any amount above zero.'
	]);
	$formwriter->textinput('prv_max_price', 'Maximum amount ('.$currency_symbol.')', [
		'value' => $product_version->get('prv_max_price'),
		'helptext' => 'The most a buyer may pay. Leave empty for no limit.'
	]);
};

$formwriter->textinput('version_name', 'Label', [
	'validation' => ['required' => true, 'maxlength' => 255],
	'value' => $product_version->get('prv_version_name')
]);

if(!$product_version->key || !$has_orders){
	// New version, or existing with no orders - show editable price fields
	$formwriter->textinput('version_price', 'Price ('.$currency_symbol.')', [
		'validation' => ['required' => true],
		'value' => $product_version->get('prv_version_price')
	]);

	$optionvals = array('single'=>"One-time charge", 'user' => 'User Chooses', 'day'=>'Daily Subscription', 'week'=>'Weekly Subscription', 'month'=>'Monthly Subscription', 'year'=>'Yearly Subscription');
	$formwriter->dropinput('prv_price_type', 'Pricing', [
		'options' => $optionvals,
		'validation' => ['required' => true],
		'value' => $product_version->get('prv_price_type'),
		'visibility_rules' => [
			'single' => ['show' => [], 'hide' => ['prv_trial_period_days', 'prv_min_price', 'prv_max_price']],
			'user' => ['show' => ['prv_min_price', 'prv_max_price'], 'hide' => ['prv_trial_period_days']],
			'day' => ['show' => ['prv_trial_period_days'], 'hide' => ['prv_min_price', 'prv_max_price']],
			'week' => ['show' => ['prv_trial_period_days'], 'hide' => ['prv_min_price', 'prv_max_price']],
			'month' => ['show' => ['prv_trial_period_days'], 'hide' => ['prv_min_price', 'prv_max_price']],
			'year' => ['show' => ['prv_trial_period_days'], 'hide' => ['prv_min_price', 'prv_max_price']]
		]
	]);

	$formwriter->textinput('prv_trial_period_days', 'Subscription trial period (days):', [
		'value' => $product_version->get('prv_trial_period_days') ?: 0
	]);

	$range_fields($formwriter);
}
else{
	// Existing version with orders - show price as read-only
	$formwriter->hiddeninput('version_price', '', ['value' => '']);
	$formwriter->hiddeninput('prv_price_type', '', ['value' => '']);
	$formwriter->hiddeninput('prv_trial_period_days', '', ['value' => '']);

	echo '<div class="ctrlHolder"><p class="label">Current Price</p>';
	echo '<div class="textInput"><strong>'.$currency_symbol . $product_version->get('prv_version_price') . ' / ' . $product_version->get('prv_price_type') . '</strong>';
	echo '<br><em style="color: #666;">Price cannot be edited because orders reference this version. To change pricing, create a new version and make this one inactive.</em></div></div>';

	// The range a buyer may type stays editable: it changes no order already placed.
	if ($product_version->get('prv_price_type') == 'user') {
		$range_fields($formwriter);
	}
}

// Display priority - for pricing page
if($settings->get_setting('pricing_page')){
	$formwriter->textinput('prv_display_priority', 'Display Order (0 for private)', [
		'value' => $product_version->get('prv_display_priority'),
		'helptext' => 'Set to 0 to hide from public /pricing page. Higher values show first when multiple versions exist.'
	]);
}

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
