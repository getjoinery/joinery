<?php
/**
 * Store plugin purchase hooks.
 *
 * Functions here are discovered by the admin product edit page (names ending
 * in _product_script) and run by Product::run_product_scripts() after a
 * successful charge.
 *
 * Version: 2.0.0
 */

/**
 * Generate a license key string: JNRY- followed by four groups of four
 * characters from an unambiguous uppercase alphabet (no 0/O/1/I).
 *
 * Fulfillment vocabulary, not ownership vocabulary — a key string is one
 * operator's way of proving an ownership row to a machine elsewhere.
 */
function store_generate_key_string() {
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	$groups = array();
	for ($g = 0; $g < 4; $g++) {
		$group = '';
		for ($c = 0; $c < 4; $c++) {
			$group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		$groups[] = $group;
	}
	return 'JNRY-' . implode('-', $groups);
}

/**
 * Stamp a license key onto the ownership the store recorded for this purchase
 * and email it to the owner.
 *
 * Ownership itself is core's job: tagging the product is what makes the buyer
 * an owner. This optional script is for operators who additionally need a key
 * string the buyer can paste somewhere. A product with no ownership tag has no
 * ownership row, so nothing is minted.
 *
 * Idempotent: an ownership that already carries a key keeps it.
 */
function mint_license_key_product_script($user, $product, $order_item, $order) {
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

	$ownerships = new MultiOwnership(array('order_item_id' => $order_item->key));
	$ownership = NULL;
	foreach ($ownerships as $row) {
		$ownership = $row;
		break;
	}

	if (!$ownership) {
		error_log("mint_license_key_product_script: no ownership recorded for order_item {$order_item->key} "
			. "(product {$product->key} has no ownership tag) — no key minted");
		return;
	}

	if (trim((string)$ownership->get('own_license_key')) !== '') {
		return;
	}

	$ownership->set('own_license_key', store_generate_key_string());
	$ownership->save();

	// The key belongs to whoever the ownership was recorded for, which on a
	// buy-for-someone-else line is the recipient rather than the buyer.
	$owner = new User($ownership->get('own_usr_user_id'), TRUE);

	try {
		$body = '<p>Thank you for purchasing <strong>' . htmlspecialchars($product->get('pro_name')) . '</strong>.</p>'
			. '<p>Your license key:</p>'
			. '<p style="font-size: 1.25em;"><code>' . htmlspecialchars($ownership->get('own_license_key')) . '</code></p>'
			. '<p>This license covers <strong>one production instance</strong>; staging and development copies are included. Need an additional instance? Contact us.</p>'
			. '<p>The key is also listed with your order history on your profile.</p>';
		EmailSender::quickSend($owner->get('usr_email'), 'Your ' . $product->get('pro_name') . ' license key', $body);
	} catch (Exception $e) {
		// The key is stamped and visible on the profile; a failed email must
		// not fail the purchase. Never log the key itself.
		error_log("mint_license_key_product_script: key email failed for order_item {$order_item->key}: " . $e->getMessage());
	}
}
