<?php
/**
 * OrdersPanel — the admin user-detail "Orders" panel (store-owned).
 *
 * Read-only: lists the user's orders with their items and status. Registered
 * from the store's serve.php, so it appears on /admin/admin_user only when the
 * store plugin is active.
 */

require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));

class OrdersPanel implements AdminUserPanel {

	public function id(): string {
		return 'store_orders';
	}

	public function actions(): array {
		return array();
	}

	public function handle(string $action, User $user, array $input): LogicResult {
		return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
	}

	public function render(User $user, AdminPage $page, array $context = []): string {
		require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
		require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
		require_once(PathHelper::getIncludePath('includes/Pager.php'));

		$session = SessionControl::get_instance();

		// Page-level list state, derived once by admin_user_logic and passed in.
		$show_all = !empty($context['show_all']);
		$list_limit = array_key_exists('list_limit', $context) ? $context['list_limit'] : 10;
		$show_all_url = $context['show_all_url'] ?? null;

		$orders = new MultiOrder(
			array('user_id' => $user->key),
			array('ord_order_id' => 'DESC'),
			$list_limit,
			NULL);
		$numorders = $orders->count_all();
		$orders->load();
		$orders_pager = new Pager(array('numrecords' => $numorders, 'numperpage' => $list_limit ?: $numorders));

		ob_start();
		$headers = array('Order ID', 'Order Time', 'Products', 'Status', 'Total');
		$table_options = array('title' => 'Orders', 'card' => true);
		$page->tableheader($headers, $table_options, $orders_pager);

		$PRODUCT_ID_TO_NAME_CACHE = array();
		foreach ($orders as $order):
			$order_items = $order->get_order_items();
			$order_items_out = array();
			foreach ($order_items as $order_item):
				if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
					$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
				} else {
					$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
					$title = $product->get('pro_name');
					$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
				}

				$this_out = htmlspecialchars($title) . ' ($' . number_format($order_item->get('odi_price'), 2) . ')';

				if ($order_item->get('odi_subscription_cancelled_time')) {
					$status_words = $order_item->get('odi_subscription_status') ? $order_item->get('odi_subscription_status') : 'canceled';
					$this_out .= '<br><span class="fs-11 text-600">' . htmlspecialchars($status_words) . ' at ' . LibraryFunctions::convert_time($order_item->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone()) . '</span>';
				} else if ($order_item->get('odi_subscription_status')) {
					$this_out .= '<br><span class="fs-11 text-600">STATUS: ' . htmlspecialchars($order_item->get('odi_subscription_status')) . '</span>';
				}

				$order_items_out[] = $this_out;
			endforeach;

			$order_id_cell = '<a href="/plugins/store/admin/admin_order?ord_order_id=' . $order->key . '">Order ' . $order->key . '</a>';
			$order_time_cell = LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone());
			$products_cell = implode('<br>', $order_items_out);

			$status_cell = '';
			if ($order->get('ord_status') == Order::STATUS_UNPAID) {
				$status_cell = '<span class="badge badge-subtle-warning">Unpaid</span>';
			} elseif ($order->get('ord_status') == Order::STATUS_PAID) {
				$status_cell = '<span class="badge badge-subtle-success">Paid</span>';
			} elseif ($order->get('ord_status') == Order::STATUS_ERROR) {
				$status_cell = '<span class="badge badge-subtle-danger">Error</span>';
			}

			$total_cell = '$' . number_format($order->get('ord_total_cost'), 2);

			$page->disprow(array($order_id_cell, $order_time_cell, $products_cell, $status_cell, $total_cell));
		endforeach;

		$page->endtable($orders_pager);
		return ob_get_clean();
	}
}
