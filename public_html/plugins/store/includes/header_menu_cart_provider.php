<?php
/**
 * Store header-menu cart provider.
 *
 * Contributes the cart link + item-count badge to the public page header. The
 * payload keeps the exact field names themes already consume, so themes that
 * read $menu_data['cart'] need no changes; themes that don't simply omit it.
 *
 * Registered under the key 'cart' from the store's serve.php.
 */
function store_header_menu_cart_provider($session) {
	$item_count = 0;
	try {
		$cart = ShoppingCart::current();
		if ($cart) {
			$item_count = $cart->count_items();
		}
	} catch (Exception $e) {
		$item_count = 0;
	}

	return array(
		'enabled'     => true,
		'count'       => $item_count,
		'item_count'  => $item_count,
		'total_items' => $item_count,
		'subtotal'    => null,
		'link'        => '/cart',
		'has_items'   => ($item_count > 0),
	);
}
