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
$formwriter = $page->getFormWriter('form1', [
	'model' => $product,
	'edit_primary_key_value' => $product->key,
	'values' => $override_values
]);

?>
<?php

$formwriter->begin_form();

$formwriter->textinput('pro_name', 'Product Name', [
	'validation' => ['required' => true, 'maxlength' => 255]
]);

$formwriter->dropinput('pro_is_active', 'Active?', [
	'options' => [0 => 'Disabled', 1 => 'Active'],
]);

$formwriter->textinput('pro_short_description', 'Short Description');

$formwriter->textbox('pro_description', 'Description', [
	'rows' => 5,
	'cols' => 80,
	'htmlmode' => 'yes'
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

if($has_product_groups){
	$optionvals = $pgs->get_dropdown_array();
	$formwriter->dropinput('pro_prg_product_group_id', 'Product Group', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);
}

// Build checked values from existing pri instances
$checked_system = [];
$checked_questions = [];
foreach ($instances as $instance) {
	$class_name = $instance->get('pri_class_name');
	if ($class_name === 'QuestionRequirement') {
		$config = json_decode($instance->get('pri_config'), true) ?: [];
		if (!empty($config['question_id'])) {
			$checked_questions[] = $config['question_id'];
		}
	} else {
		$checked_system[] = $class_name;
	}
}

// System requirements (Tier 2)
if (!empty($grouped_requirements['system'])) {
	$formwriter->checkboxList('system_requirements', 'Info to collect before purchase', [
		'options' => $grouped_requirements['system'],
		'checked' => $checked_system,
	]);
}

// Advanced options
$is_new_product = empty($product->key);
$advanced_style = $is_new_product ? 'display: none;' : '';
$toggle_style = $is_new_product ? '' : 'display: none;';

echo '<div id="advanced-toggle" class="mb-3" style="' . $toggle_style . '">
	<a href="#" onclick="document.getElementById(\'advanced-fields\').style.display=\'block\'; document.getElementById(\'advanced-toggle\').style.display=\'none\'; return false;" class="btn btn-outline-secondary btn-sm">
		Show Advanced Options
	</a>
</div>';

echo '<div id="advanced-fields" style="' . $advanced_style . '">';

// Subscription Tier dropdown
$tier_options = ['' => '-- None --'];
foreach ($subscription_tiers as $tier) {
	$display_name = sprintf(
		'%s (Level %d)',
		$tier->get('sbt_display_name'),
		$tier->get('sbt_tier_level')
	);
	$tier_options[$tier->key] = $display_name;
}
$formwriter->dropinput('pro_sbt_subscription_tier_id', 'Activates Subscription', [
	'options' => $tier_options,
	'help_text' => 'Select a tier this product grants when purchased',
	'empty_option' => '-- Select --'
]);

// Tier Gating - restrict who can VIEW/purchase this product
$view_tier_options = ['' => 'Anyone can view'];
foreach ($subscription_tiers as $tier) {
	$view_tier_options[$tier->get('sbt_tier_level')] = htmlspecialchars($tier->get('sbt_display_name')) . ' (Level ' . $tier->get('sbt_tier_level') . ')';
}
$formwriter->dropinput('pro_tier_min_level', 'Minimum Tier to View', [
	'options' => $view_tier_options,
	'helptext' => 'Restrict viewing/purchasing this product to users with this subscription tier or higher'
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

$formwriter->textinput('pro_digital_link', 'Digital item link', [
	'validation' => ['maxlength' => 255]
]);

// Question requirements (Tier 1)
if (!empty($grouped_requirements['questions'])) {
	$formwriter->checkboxList('question_requirements', 'Questions to ask before purchase', [
		'options' => $grouped_requirements['questions'],
		'checked' => $checked_questions,
	]);
}

echo '</div>';

// Product Scripts
if(!empty($product_scripts_optionvals)){
	$optionvals = array_combine($product_scripts_optionvals, $product_scripts_optionvals);
	$checkedvals = array_filter(explode(',', $product->get('pro_product_scripts')));

	$formwriter->checkboxList('product_scripts', 'Run these scripts upon purchase', [
		'options' => $optionvals,
		'checked' => $checkedvals
	]);
}

// Additional Product Requirements section removed — now handled via unified checkbox list above

$formwriter->submitbutton('btn_submit', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();

?>
