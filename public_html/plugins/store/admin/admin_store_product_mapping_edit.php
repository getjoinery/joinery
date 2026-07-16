<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_store_product_mapping_edit_logic.php'));

$page_vars = process_logic(admin_store_product_mapping_edit_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'mobile-store-products',
	'breadcrumbs' => array(
		'Products'=>'/plugins/store/admin/admin_products',
		'Mobile Store Products'=>'/plugins/store/admin/admin_store_product_mappings',
		'Edit Mapping' => '',
	),
	'session' => $session,
)
);

$pageoptions['title'] = $mapping->key ? 'Edit Mapping' : 'New Mapping';
$page->begin_box($pageoptions);

$override_values = array('plan' => $plan_value);
if (!$mapping->key) {
	$override_values['msp_is_active'] = 1;
}

$formwriter = $page->getFormWriter('form1', [
	'model' => $mapping,
	'values' => $override_values,
	'edit_primary_key_value' => $mapping->key
]);

echo $formwriter->begin_form();

$formwriter->dropinput('msp_store', 'Store', [
	'options' => ['app_store' => 'App Store (iOS)', 'play_store' => 'Google Play (Android)'],
]);

$formwriter->textinput('msp_store_product_id', 'Store product ID', [
	'helptext' => 'The product identifier configured in App Store Connect or the Play Console',
]);

$formwriter->dropinput('plan', 'Sells plan', [
	'options' => $plan_options,
]);

$formwriter->dropinput('msp_is_active', 'Active?', [
	'options' => [0 => 'Inactive', 1 => 'Active']
]);

$formwriter->textinput('msp_notes', 'Notes');

$formwriter->submitbutton('btn_submit', 'Save');
echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
