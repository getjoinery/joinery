/*
 * The PayPal buttons (PaypalHelper::output_paypal_checkout_code and
 * output_paypal_subscription_checkout_code). The page's
 * #paypal-checkout-config carries the mode ('order' or 'subscription'), the
 * cart checksum, the return URL and, for an order, the order itself.
 *
 * The cart is recorded for this payment first (store/checkout_record_paypal);
 * the order or subscription carries that record's reference, which the
 * return completes from.
 *
 * Loaded, not deferred, after the PayPal SDK.
 *
 * @version 1.0
 */
(function () {
	'use strict';
	var CFG = (window.joineryPageData && joineryPageData('paypal-checkout-config')) || null;
	if (!CFG || !window.paypal || !document.getElementById('paypal-button-container')) return;

	function fail(err) {
		// The record action refuses in a sentence of its own (the cart changed,
		// log in first); PayPal errors say nothing a buyer can use.
		alert((err && err.status && err.message) ? err.message : 'An error occurred during the payment. Please try again.');
	}
	function record() {
		return joineryApi.post('store/checkout_record_paypal', { cart_cs: CFG.cart_cs || '' });
	}

	if (CFG.mode === 'subscription') {
		paypal.Buttons({
			createSubscription: function (data, actions) {
				return record().then(function (recorded) {
					return actions.subscription.create({ plan_id: recorded.plan_id, custom_id: recorded.custom_id });
				});
			},
			onApprove: function (data) {
				window.location.href = CFG.return_url + '?subscription=1&paypal_subscription_id=' + encodeURIComponent(data.subscriptionID);
			},
			onError: fail
		}).render('#paypal-button-container');
		return;
	}

	var selectedFundingSource = 'paypal';
	var paypalData = CFG.order;
	paypal.Buttons({
		createOrder: function (data, actions) {
			return record().then(function (recorded) {
				paypalData.purchase_units[0].custom_id = recorded.custom_id;
				return actions.order.create(paypalData);
			});
		},
		onClick: function (data) {
			selectedFundingSource = data.fundingSource || 'paypal';
		},
		onApprove: function (data, actions) {
			return actions.order.capture().then(function (details) {
				var fundingSource = data.paymentSource || selectedFundingSource || 'paypal';
				window.location.href = CFG.return_url + '?id=' + encodeURIComponent(details.id) + '&funding=' + encodeURIComponent(fundingSource);
			});
		},
		onError: fail
	}).render('#paypal-button-container');
})();
