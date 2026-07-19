<?php
// Store plugin settings — included from /admin/admin_settings
// $formwriter, $settings, and $session are already available.

require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

$store_donation_options = array('' => '-- Off --');
$store_donation_products = new MultiProduct(array('is_active' => true), array('pro_name' => 'ASC'), 500, NULL);
$store_donation_products->load();
foreach ($store_donation_products as $store_donation_product) {
	$store_donation_options[(string)$store_donation_product->key] = $store_donation_product->get('pro_name');
}

$formwriter->dropinput('store_optional_donation_product_id', 'Optional donation product', [
	'options'  => $store_donation_options,
	'value'    => $settings->get_setting('store_optional_donation_product_id'),
	'helptext' => 'Added to the cart when a buyer enters an optional donation amount alongside another purchase. Off means donation amounts entered at checkout are not charged.',
]);
