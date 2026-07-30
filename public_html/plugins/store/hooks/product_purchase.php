<?php
/**
 * Store plugin purchase hooks.
 *
 * Functions here are discovered by the admin product edit page (names ending
 * in _product_script) and run by Product::run_product_scripts() after a
 * successful charge.
 *
 * Version: 1.0.0
 */

/**
 * Mint a license key for the plugin this product licenses.
 *
 * The plugin name comes from the product's "Licenses plugin" setting
 * (pro_licensed_plugin), so any future paid plugin is admin config on the
 * product — no new code. The key is recorded against the buyer, order, and
 * plugin, emailed to the buyer, and listed on their profile orders page.
 *
 * Idempotent: re-running for the same order item never mints a second key.
 */
function mint_license_key_product_script($user, $product, $order_item, $order) {
	require_once(PathHelper::getIncludePath('plugins/store/data/license_keys_class.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

	$plugin_name = trim((string)$product->get('pro_licensed_plugin'));
	if ($plugin_name === '') {
		error_log("mint_license_key_product_script: product {$product->key} has no licensed plugin set — no key minted (order_item {$order_item->key})");
		return;
	}

	$existing = new MultiLicenseKey([
		'order_item_id' => $order_item->key,
		'plugin_name' => $plugin_name,
	]);
	$existing->load();
	if ($existing->count() > 0) {
		return;
	}

	$license = new LicenseKey(NULL);
	$license->set('lck_key', LicenseKey::generate_key_string());
	$license->set('lck_usr_user_id', $user->key);
	$license->set('lck_ord_order_id', $order ? $order->key : NULL);
	$license->set('lck_odi_order_item_id', $order_item->key);
	$license->set('lck_plugin_name', $plugin_name);
	$license->save();

	try {
		$body = '<p>Thank you for purchasing <strong>' . htmlspecialchars($product->get('pro_name')) . '</strong>.</p>'
			. '<p>Your license key:</p>'
			. '<p style="font-size: 1.25em;"><code>' . htmlspecialchars($license->get('lck_key')) . '</code></p>'
			. '<p>This license covers <strong>one production instance</strong>; staging and development copies are included. A second production site needs a second purchase.</p>'
			. '<p>The key is also listed with your order history on your profile.</p>';
		EmailSender::quickSend($user->get('usr_email'), 'Your ' . $product->get('pro_name') . ' license key', $body);
	} catch (Exception $e) {
		// The key is minted and visible on the profile; a failed email must
		// not fail the purchase. Never log the key itself.
		error_log("mint_license_key_product_script: key email failed for order_item {$order_item->key}: " . $e->getMessage());
	}
}
