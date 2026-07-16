<?php

require_once(PathHelper::getIncludePath('plugins/store/admin/logic/admin_store_product_mappings_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

$page_vars = process_logic(admin_store_product_mappings_logic(array_merge($_GET, $_POST)));

$session = $page_vars['session'];
$mappings = $page_vars['mappings'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'mobile-store-products',
	'breadcrumbs' => array(
		'Products'=>'/plugins/store/admin/admin_products',
		'Mobile Store Products'=>'',
	),
	'session' => $session,
)
);

$headers = array("Store Product ID", "Store", "Sells", "Active");
$altlinks = array('New Mapping'=>'/plugins/store/admin/admin_store_product_mapping_edit');
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Mobile Store Products',
);
$page->tableheader($headers, $table_options, $pager);

$store_labels = array('app_store' => 'App Store', 'play_store' => 'Google Play');

foreach ($mappings as $mapping){
	$product = new Product($mapping->get('msp_pro_product_id'), TRUE);
	$product_label = $product->key ? $product->get('pro_name') : '(missing product)';
	if ($mapping->get('msp_prv_product_version_id')) {
		$version = new ProductVersion($mapping->get('msp_prv_product_version_id'), TRUE);
		if ($version->key) {
			$product_label .= ' (' . $version->get('prv_version_name') . ')';
		}
	}

	$rowvalues = array();
	array_push($rowvalues, "<a href='/plugins/store/admin/admin_store_product_mapping_edit?msp_mobile_store_product_id=$mapping->key'>".htmlspecialchars($mapping->get('msp_store_product_id'))."</a>");
	array_push($rowvalues, $store_labels[$mapping->get('msp_store')] ?? $mapping->get('msp_store'));
	array_push($rowvalues, htmlspecialchars($product_label));
	array_push($rowvalues, $mapping->get('msp_is_active') ? 'Active' : 'Inactive');

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
