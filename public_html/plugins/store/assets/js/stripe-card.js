/*
 * The card form (StripeHelper::output_stripe_regular_form). Stripe.js turns
 * the card into a PaymentMethod in the browser and the form posts only its
 * id to /cart_charge; no card data reaches this server. The form carries the
 * public key (data-stripe-key) and, when a checkout is waiting on the buyer's
 * bank, the payment's client secret (data-pending-secret): the bank's
 * confirmation runs as the page loads and the checkout carries on at
 * /cart_charge whatever the bank answered.
 *
 * Loaded after https://js.stripe.com/v3/.
 *
 * @version 1.1 - Pay is drawn disabled and enabled here, so it cannot post before the card field exists
 * @version 1.0
 */
(function () {
	'use strict';
	function start() {
		var form = document.getElementById('payment-form');
		if (!form || !window.Stripe) return;
		var warn = document.getElementById('nojavascript');
		if (warn) warn.style.display = 'none';

		var stripe = Stripe(form.getAttribute('data-stripe-key') || '');
		var pendingSecret = form.getAttribute('data-pending-secret') || '';
		var elements = stripe.elements();
		var style = {
			base: {
				color: '#32325d',
				fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
				fontSmoothing: 'antialiased',
				fontSize: '24px',
				'::placeholder': { color: '#aab7c4' }
			},
			invalid: { color: '#fa755a', iconColor: '#fa755a' }
		};
		var card = elements.create('card', { style: style });
		card.mount('#card-element');

		var displayError = document.getElementById('card-errors');
		var submitButton = document.getElementById('payment-submit');
		// The button is drawn disabled: before this file runs, the form would
		// post with no card and the order would fail.
		if (!pendingSecret) submitButton.disabled = false;
		card.on('change', function (event) {
			displayError.textContent = event.error ? event.error.message : '';
		});

		// The card becomes a PaymentMethod here; only its id is posted.
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			submitButton.disabled = true;
			stripe.createPaymentMethod({ type: 'card', card: card }).then(function (result) {
				if (result.error) {
					displayError.textContent = result.error.message;
					submitButton.disabled = false;
					return;
				}
				var hiddenInput = document.createElement('input');
				hiddenInput.setAttribute('type', 'hidden');
				hiddenInput.setAttribute('name', 'stripePaymentMethod');
				hiddenInput.setAttribute('value', result.paymentMethod.id);
				form.appendChild(hiddenInput);
				form.submit();
			});
		});

		// A checkout waiting on the buyer's bank: Stripe shows the bank's
		// confirmation, then the checkout carries on at /cart_charge.
		if (pendingSecret) {
			submitButton.disabled = true;
			displayError.textContent = 'Confirming the payment with your bank...';
			stripe.confirmCardPayment(pendingSecret).then(function () {
				document.getElementById('card-confirm-form').submit();
			});
		}
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
