<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_product_version_edit_logic.php'));

$page_vars = process_logic(admin_product_version_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'products-list',
	'page_title' => 'Products Version Edit',
	'readable_title' => 'Product Version Edit',
	'breadcrumbs' => array(
		'Products'=>'/admin/admin_products',
		$breadcrumb => '',
		'Product Version Edit'=>'',
	),
	'session' => $session,
)
);

$page->begin_box($pageoptions);

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $product_version,
	'edit_primary_key_value' => $product_version->key
]);

?>
<script type="text/javascript">

	function set_subscription_choices(){
		var value = $("#prv_price_type").val();
		if(value == 'single' || value == 'user'){
			$("#prv_trial_period_days_container").hide();
		}
		else {
			$("#prv_trial_period_days_container").show();
		}

	}

	$(document).ready(function() {

		set_subscription_choices();
		$("#prv_price_type").change(function() {
			set_subscription_choices();
		});
	});

</script>
<?php

$formwriter->begin_form();

$formwriter->textinput('version_name', 'Label', [
	'validation' => ['required' => true, 'maxlength' => 255],
	'value' => $product_version->get('prv_version_name')
]);

if(!$product_version->key){
	// New version - show price fields
	$formwriter->textinput('version_price', 'Price ('.$currency_symbol.')', [
		'validation' => ['required' => true]
	]);

	$optionvals = array("One price"=>'single', 'User Chooses' => 'user', 'Daily Subscription'=>'day', 'Weekly Subscription'=>'week', 'Monthly Subscription'=>'month', 'Yearly Subscription'=>'year',);
	$formwriter->dropinput('prv_price_type', 'Pricing', [
		'options' => $optionvals,
		'validation' => ['required' => true]
	]);

	$formwriter->textinput('prv_trial_period_days', 'Subscription trial period (days):', [
		'value' => 0
	]);
}
else{
	// Existing version - show price as read-only
	$formwriter->hiddeninput('version_price', ['value' => '']);
	$formwriter->hiddeninput('prv_price_type', ['value' => '']);
	$formwriter->hiddeninput('prv_trial_period_days', ['value' => '']);

	echo '<div class="ctrlHolder"><p class="label">Current Price</p>';
	echo '<div class="textInput"><strong>'.$currency_symbol . $product_version->get('prv_version_price') . ' / ' . $product_version->get('prv_price_type') . '</strong>';
	echo '<br><em style="color: #666;">Price cannot be edited. To change pricing, create a new version and make this one inactive.</em></div></div>';
}

// Display priority - for pricing page
if($settings->get_setting('pricing_page')){
	$formwriter->textinput('prv_display_priority', 'Display Priority (0=private, >0=public, higher=preferred):', [
		'value' => $product_version->get('prv_display_priority'),
		'help_text' => 'Set to 0 to hide from public /pricing page. Higher values show first when multiple versions exist.'
	]);
}

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
