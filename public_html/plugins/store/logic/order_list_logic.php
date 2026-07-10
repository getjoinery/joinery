<?php
/**
 * API action: order_list — the owner's paginated order history as JSON.
 *
 * POST /api/v1/action/order_list (session key). Params: offset (10/page,
 * matching the web order history page). Returns each order with its line
 * item summaries, sharing orders_profile_logic.php's query path.
 *
 * @version 1.0.0
 */


function order_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$user_id = $session->get_user_id();
	$numperpage = 10;
	$page_offset = isset($input['offset']) ? max(0, (int)$input['offset']) : 0;

	$orders = new MultiOrder(
		array('user_id' => $user_id),
		array('ord_order_id' => 'DESC'),
		$numperpage,
		$page_offset
	);
	$total = $orders->count_all();
	$orders->load();

	$out = array();
	foreach ($orders as $order) {
		$items = new MultiOrderItem(array('order_id' => $order->key));
		$items->load();
		$item_summaries = array();
		foreach ($items as $item) {
			$product = new Product($item->get('odi_pro_product_id'), TRUE);
			$item_summaries[] = array(
				'product_name' => $product ? $product->get('pro_name') : '',
				'price'        => $item->get('odi_price'),
			);
		}

		$out[] = array(
			'order_id' => (int)$order->key,
			'number'   => (int)$order->key,
			'total'    => $order->get('ord_total_cost'),
			'date'     => $order->get('ord_timestamp'),
			'items'    => $item_summaries,
		);
	}

	return LogicResult::render(array(
		'orders'      => $out,
		'total_count' => $total,
		'offset'      => $page_offset,
		'per_page'    => $numperpage,
	));
}

function order_list_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Paginated order history for the signed-in owner, with line item summaries',
	];
}

?>
