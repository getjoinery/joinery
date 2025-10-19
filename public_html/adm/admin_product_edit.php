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

// Editing an existing product
$formwriter = $page->getFormWriter('form1');

$validation_rules = array();

$validation_rules['pro_name']['required']['value'] = 'true';
$validation_rules['pro_name']['maxlength']['value'] = 255;
$validation_rules['pro_link']['required']['value'] = 'true';
$validation_rules['pro_max_cart_count']['required']['value'] = 'true';
$validation_rules['pro_max_purchase_count']['required']['value'] = 'true';
$validation_rules['pro_requirements']['required']['value'] = 'true';

echo $formwriter->set_validate($validation_rules);

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

echo $formwriter->begin_form('form1', 'POST', '/admin/admin_product_edit');

if($product->key){
	$action = 'edit';
	echo $formwriter->hiddeninput('p', $product->key);
	echo $formwriter->hiddeninput('action', 'edit');
	$product_status = $product->get('pro_is_active');
}
else{
	$action = 'add';
	echo $formwriter->hiddeninput('action', 'add');
	$product_status = 1;
}

$optionvals = array("Active"=>1, "Disabled"=>0 );
echo $formwriter->dropinput("Active?", "pro_is_active", "ctrlHolder", $optionvals, $product_status, '', FALSE);
echo $formwriter->textinput('Product Name', 'pro_name', NULL, 100, $product->get('pro_name'), '', 255, '');

echo $formwriter->textbox('Short Description', 'pro_short_description', 'ctrlHolder', 5, 80, $product->get('pro_short_description'), '', 'yes');
echo $formwriter->textbox('Description', 'pro_description', 'ctrlHolder', 5, 80, $product->get('pro_description'), '', 'yes');

echo $formwriter->textinput('Digital item link', 'pro_digital_link', NULL, 100, $product->get('pro_digital_link'), '', 255, '');

if($numevents){
	$optionvals = $events->get_dropdown_array();
	echo $formwriter->dropinput("Event registration", "pro_evt_event_id", "ctrlHolder", $optionvals, $product->get('pro_evt_event_id'), '', TRUE);
}

if($numbundles){
	$optionvals = $groups->get_dropdown_array();
	echo $formwriter->dropinput("Event Bundle", "pro_grp_group_id", "ctrlHolder", $optionvals, $product->get('pro_grp_group_id'), '', TRUE);
}

// Add Subscription Tier dropdown
$tier_options = array('-- None --' => '');

foreach ($subscription_tiers as $tier) {
	$display_name = sprintf(
		'%s (Level %d)',
		$tier->get('sbt_display_name'),
		$tier->get('sbt_tier_level')
	);
	$tier_options[$display_name] = $tier->key;
}

echo $formwriter->dropinput(
	"Subscription Tier",
	"pro_sbt_subscription_tier_id",
	"ctrlHolder",
	$tier_options,
	$product->get('pro_sbt_subscription_tier_id'),
	'Select a tier this product grants when purchased',
	TRUE
);

if(!$pro_max_purchase_count_fill = $product->get('pro_max_purchase_count')){
	$pro_max_purchase_count_fill = 0;
}
echo $formwriter->textinput('Total Number available for purchase (0 for unlimited):', 'pro_max_purchase_count', 'ctrlHolder', 100, $pro_max_purchase_count_fill, '', 3, '');

if(!$pro_max_cart_count_fill = $product->get('pro_max_cart_count')){
	$pro_max_cart_count_fill = 0;
}
echo $formwriter->textinput('Max Number that can be added to cart per user (0 for unlimited):', 'pro_max_cart_count', 'ctrlHolder', 100, $pro_max_cart_count_fill, '', 3, '');

if(!$pro_expires_fill = $product->get('pro_expires')){
	$pro_expires_fill = 0;
}
echo $formwriter->textinput('Purchase expires after (days, 0 for never)', 'pro_expires', NULL, 100, $pro_expires_fill, '', 4, '');

if(!$product->get('pro_link') || $_SESSION['permission'] == 10){
	echo $formwriter->textinput('Link (optional): '.$settings->get_setting('webDir').'/product/', 'pro_link', NULL, 100, $product->get('pro_link'), '', 255, '');
}

if($has_product_groups){
	$optionvals = $pgs->get_dropdown_array();
	echo $formwriter->dropinput("Product Group", "pro_prg_product_group_id", "ctrlHolder", $optionvals, $product->get('pro_prg_product_group_id'), '', TRUE);
}

$optionvals = array(
	'Name' => 1,
	'Email' => 64,
	'Phone Number' => 2,
	'Date of Birth' => 4,
	'Address' => 8,
	//'GDPR Notice' => 16,
	'Consent to record' => 32,
	'Optional One-time Donation' => 128,
	'Newsletter Signup' => 256,
	'Comment' => 512
);
if ($product->key) {
	//FILL THE CHECKED VALUES AND DECLARE EMAIL AND NAME READ ONLY
	$checkedvals = $product->get_requirement_info('ids');
	$checkedvals[] = 1;
	$checkedvals[] = 64;
	$readonlyvals = array(1, 64); //DEFAULT
}
else{
	$checkedvals = array(1, 64);
	$readonlyvals = array(1, 64); //DEFAULT
}
$disabledvals = array();

echo $formwriter->checkboxList("Info to collect at purchase", 'pro_requirements', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);

//PRODUCT SCRIPTS
if(!empty($product_scripts_optionvals)){
	$optionvals = array_combine($product_scripts_optionvals, $product_scripts_optionvals);
	$readonlyvals = array();
	$checkedvals = explode(',', $product->get('pro_product_scripts'));
	$disabledvals = array();
	echo $formwriter->checkboxList("Run these scripts upon purchase", 'product_scripts', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
}

if($has_product_requirements){
	$optionvals = $product_requirements->get_dropdown_array();

	$readonlyvals = array();
	$checkedvals = array();
	$disabledvals = array();
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

	echo $formwriter->checkboxList("Additional Info to collect at purchase", 'additional_pro_requirements', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
}

//echo $formwriter->textinput('After Purchase Message', 'pro_after_purchase_message', 'ctrlHolder', 100, $product->get('pro_after_purchase_message'), '', 255);

/*
//REMOVED
$templates = new MultiEmailTemplateStore(
	array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
	NULL,		//SORT BY => DIRECTION
	NULL,  //NUM PER PAGE
	NULL);  //OFFSET
$templates->load();
$optionvals = $templates->get_dropdown_array();
echo $formwriter->dropinput("Receipt template", "pro_receipt_template", "ctrlHolder", $optionvals, $product->get('pro_receipt_template'), '', TRUE);

echo $formwriter->textinput('Receipt subject (if no template chosen)', 'pro_receipt_subject', NULL, 100, $product->get('pro_receipt_subject'), '', 255, '');
echo $formwriter->textbox('Receipt body  (if no template chosen)', 'pro_receipt_body', 'ctrlHolder', 10, 80, $product->get('pro_receipt_body'), '');
*/

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();
echo $formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
