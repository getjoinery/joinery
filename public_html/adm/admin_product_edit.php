<?php

require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_product_edit_logic.php'));

$page_vars = process_logic(admin_product_edit_logic($_GET, $_POST));
extract($page_vars);

if ($product->key) {
	$options['title'] = 'Product Edit - '. $product->get('pro_name');
}
else{
	$options['title'] = 'New Product';
}

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'products-list',
	'page_title' => 'Products',
	'readable_title' => 'Products',
	'breadcrumbs' => array(
		'Products'=>'/admin/admin_products',
		$breadcrumb => '',
		'Product Edit'=>'',
	),
	'session' => $session,
)
);

$page->begin_box($options);

// Set default product status for new products
if (!$product->key) {
	$override_values = ['pro_is_active' => 1];
} else {
	$override_values = [];
}

// FormWriter V2 with model and edit_primary_key_value
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $product,
	'edit_primary_key_value' => $product->key,
	'values' => $override_values
]);

?>
<script type="text/javascript">
/*
	function set_pricing_choices(){
		var value = $("#pro_price_type").val();
		if(value == 1){  //ONE PRICE
			$("#pro_price_container").show();
		}
		else if(value == 2){  //MULTIPLE PRICES
			$("#pro_price_container").hide();
		}
		else if(value == 3){  //USER CHOOSES PRICE
			$("#pro_price_container").hide();
		}
	}

	$(document).ready(function() {
		set_pricing_choices();
		$("#pro_price_type").change(function() {
			set_pricing_choices();
		});

	});
*/

</script>
<?php

$formwriter->begin_form();

$formwriter->dropinput('pro_is_active', 'Active?', [
	'options' => ['Disabled' => 0, 'Active' => 1],
	'value' => !$product->key ? 1 : NULL
]);

$formwriter->textinput('pro_name', 'Product Name', [
	'validation' => ['required' => true, 'maxlength' => 255]
]);

$formwriter->textbox('pro_short_description', 'Short Description', [
	'rows' => 5,
	'cols' => 80,
	'htmlmode' => 'yes'
]);

$formwriter->textbox('pro_description', 'Description', [
	'rows' => 5,
	'cols' => 80,
	'htmlmode' => 'yes'
]);

$formwriter->textinput('pro_digital_link', 'Digital item link', [
	'validation' => ['maxlength' => 255]
]);

if($numevents){
	$optionvals = $events->get_dropdown_array();
	$formwriter->dropinput('pro_evt_event_id', 'Event registration', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
}

if($numbundles){
	$optionvals = $groups->get_dropdown_array();
	$formwriter->dropinput('pro_grp_group_id', 'Event Bundle', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
}

// Subscription Tier dropdown
$tier_options = ['-- None --' => ''];
foreach ($subscription_tiers as $tier) {
	$display_name = sprintf(
		'%s (Level %d)',
		$tier->get('sbt_display_name'),
		$tier->get('sbt_tier_level')
	);
	$tier_options[$display_name] = $tier->key;
}
$formwriter->dropinput('pro_sbt_subscription_tier_id', 'Subscription Tier', [
	'options' => $tier_options,
	'help_text' => 'Select a tier this product grants when purchased',
	'empty_option' => '-- Select --'
]);

$formwriter->textinput('pro_max_purchase_count', 'Total Number available for purchase (0 for unlimited)', [
	'validation' => ['required' => true]
]);

$formwriter->textinput('pro_max_cart_count', 'Max Number that can be added to cart per user (0 for unlimited)', [
	'validation' => ['required' => true]
]);

$formwriter->textinput('pro_expires', 'Purchase expires after (days, 0 for never)');

if(!$product->get('pro_link') || $_SESSION['permission'] == 10){
	$formwriter->textinput('pro_link', 'Link (optional): '.$settings->get_setting('webDir').'/product/', [
		'validation' => ['required' => true, 'maxlength' => 255]
	]);
}

if($has_product_groups){
	$optionvals = $pgs->get_dropdown_array();
	$formwriter->dropinput('pro_prg_product_group_id', 'Product Group', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
}

// Info to collect at purchase - with readonly handling
$optionvals = array(
	'Name' => 1,
	'Email' => 64,
	'Phone Number' => 2,
	'Date of Birth' => 4,
	'Address' => 8,
	'Consent to record' => 32,
	'Optional One-time Donation' => 128,
	'Newsletter Signup' => 256,
	'Comment' => 512
);

if ($product->key) {
	//FILL THE CHECKED VALUES
	$checkedvals = $product->get_requirement_info('ids');
	$checkedvals[] = 1;
	$checkedvals[] = 64;
} else {
	$checkedvals = array(1, 64);
}

$formwriter->checkboxList('pro_requirements', 'Info to collect at purchase', [
	'options' => $optionvals,
	'checked' => $checkedvals,
	'validation' => ['required' => true]
]);

// Product Scripts
if(!empty($product_scripts_optionvals)){
	$optionvals = array_combine($product_scripts_optionvals, $product_scripts_optionvals);
	$checkedvals = array_filter(explode(',', $product->get('pro_product_scripts')));

	$formwriter->checkboxList('product_scripts', 'Run these scripts upon purchase', [
		'options' => $optionvals,
		'checked' => $checkedvals
	]);
}

// Additional Product Requirements
if($has_product_requirements){
	$optionvals = $product_requirements->get_dropdown_array();

	$checkedvals = array();
	foreach ($product_requirements as $product_requirement){
		if($product_requirement->get('prq_is_default_checked')){
			$checkedvals[] = $product_requirement->key;
		}

		foreach($instances as $instance){
			if($product_requirement->key == $instance->get('pri_prq_product_requirement_id')){
				$checkedvals[] = $instance->get('pri_prq_product_requirement_id');
			}
		}
	}

	$formwriter->checkboxList('additional_pro_requirements', 'Additional Info to collect at purchase', [
		'options' => $optionvals,
		'checked' => $checkedvals
	]);
}

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
