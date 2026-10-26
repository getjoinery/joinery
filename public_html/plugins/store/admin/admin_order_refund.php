<?php
/** @joinery-admin class=personal */
	
	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('/plugins/store/includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('/data/users_addrs_class.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));

	$session = SessionControl::get_instance();
	
	$settings = Globalvars::get_instance();
	$currency_symbol = CurrencyHelper::symbol($settings->get_setting('site_currency'));

	$stripe_helper = new StripeHelper();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'orders-list',
		'page_title' => 'Order Refunds',
		'readable_title' => 'Order Refunds',
		'breadcrumbs' => array(
			'Orders'=>'/plugins/store/admin/admin_orders', 
			'Refund order' => '',
		),
		'session' => $session,
	)
	);

	if (!empty($_POST['confirm'])){
		// A refund is money leaving: the order items' level, asked before Stripe
		// is (the row's owner is the buyer, who does not refund themselves).
		Access::demand(OrderItem::access_level('write'), $session);
		$order_item = new OrderItem($_REQUEST['order_item_id'] ?? null, TRUE);
		$order_item->assert_found();

		// The payment is found again here from the line, never taken from the
		// form, and the amount is checked against what that line may refund.
		$result = $order_item->refund_payment($_POST['refund_amount'] ?? 0, (string)($_POST['odi_refund_note'] ?? ''),
			!empty($_POST['cancel_subscription']));
		$order = $order_item->get_order();

		$pageoptions['title'] = 'Refund confirm';
		$page->begin_box($pageoptions);

		echo $currency_symbol . number_format($result['refunded'], 2) . ' was refunded from ' . htmlspecialchars($result['what'])
			. ' on order <a href="/plugins/store/admin/admin_order?ord_order_id=' . $order->key . '">' . $order->key . '</a>.';
		if ($result['cancelled']) {
			echo ' The subscription is cancelled.';
		}
		else if ($result['cancel_error'] !== null) {
			echo ' <strong>The subscription was not cancelled:</strong> ' . htmlspecialchars($result['cancel_error']);
		}

	}
else{
	
	if(empty($_REQUEST['oi'])){
		throw new SystemDisplayableError("Order item is not present.");
	}
	
	$order_item = new OrderItem($_REQUEST['oi'], TRUE);
	$order_item->assert_found();
	$product = new Product($order_item->get('odi_pro_product_id'), TRUE);

	try {
		$found = $stripe_helper->refundable_charge_for_item($order_item);
	} catch (StripeHelperException $e) {
		throw new SystemDisplayableError('The payment cannot be read: ' . $e->getMessage());
	}

	$session = SessionControl::get_instance();
	$session->set_return("/plugins/store/admin/admin_order_refund");

	$pageoptions['title'] = 'Refund charge';
	$page->begin_box($pageoptions);

	echo '<fieldset><h4>Confirm Refund ('.htmlspecialchars($product->get('pro_name')).')</h4>';
	echo '<div class="fields full">';

	if (empty($found['charge'])) {
		echo '<p>' . htmlspecialchars($found['reason']) . '</p>';
	}
	else {
		$charge = $found['charge'];
		$amount_left = $order_item->refundable_amount($charge);
		echo '<p>Refunding from ' . htmlspecialchars($found['what']) . ': ' . $currency_symbol . number_format($charge->amount / 100, 2)
			. ', ' . $currency_symbol . number_format($charge->amount_refunded / 100, 2) . ' refunded so far.</p>';

		if ($amount_left > 0) {
			$formwriter = $page->getFormWriter('form1', [
				'action' => '/plugins/store/admin/admin_order_refund',
				'method' => 'POST'
			]);
			$formwriter->begin_form();
			$formwriter->textinput('refund_amount', 'Amount to refund ('.$currency_symbol.number_format($amount_left, 2). ' maximum)', [
				'value' => number_format($amount_left, 2, '.', ''),
				'validation' => ['required' => true, 'max' => $amount_left]
			]);
			$formwriter->textinput('odi_refund_note', 'Refund description or reason', [
				'value' => $order_item->get('odi_refund_note')
			]);
			if ($order_item->get('odi_is_subscription') && !$order_item->get('odi_subscription_cancelled_time')) {
				$formwriter->checkboxinput('cancel_subscription', 'Also cancel the subscription now (billing and service stop today)', [
					'value' => 1,
					'checked' => false,
				]);
			}
			$formwriter->hiddeninput('confirm', '', ['value' => 1]);
			$formwriter->hiddeninput('order_item_id', '', ['value' => $order_item->key]);
			$formwriter->submitbutton('submit_button', 'Submit');
			$formwriter->end_form();
		}
		else {
			echo '<p>You cannot refund any more.</p>';
		}
	}

	echo '</div>';
	echo '</fieldset>';

}
	$page->end_box();
$page->admin_footer();
?>

