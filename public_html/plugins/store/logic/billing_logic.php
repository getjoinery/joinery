<?php
/**
 * The billing page: the card on file, each active subscription with its own
 * billing cycle, and the history.
 *
 * A buyer may hold more than one subscription at once (a tier and a hosted
 * site, say), so every subscription gets its own section, and a cycle change
 * names the subscription it changes (odi_order_item_id) and is checked to be
 * one of the buyer's own active ones. The card is one per Stripe customer,
 * shown once.
 *
 * @version 1.2 - each subscription carries what its line was bought for (a site's name); the history
 *                lists one-time purchases beside the subscription invoices
 * @version 1.1 - one section per active subscription; change_billing_cycle names its subscription
 *                (hosting_paid_launch B12)
 */

/**
 * What a requirement recorded about a bought line (a domain, a configured
 * site), as the cart and checkout showed it: 'Site: example.com'. It tells two
 * subscriptions to the same product apart.
 *
 * @return string[]
 */
function billing_line_details(OrderItem $item): array {
    $lines = array();
    foreach ($item->get_data() as $value) {
        if (is_array($value) && isset($value['question'], $value['answer'])
                && empty($value['internal']) && (string)$value['answer'] !== '') {
            $lines[] = $value['question'] . ': ' . $value['answer'];
        }
    }
    return $lines;
}

/**
 * One active subscription as the page shows it: the line, how it is paid, its
 * product and price, and the other billing cycles it could switch to.
 */
function billing_subscription_section(OrderItem $item): array {
    $section = array(
        'item'            => $item,
        'payment_system'  => $item->get('odi_stripe_subscription_id') ? 'stripe'
            : ($item->get('odi_paypal_subscription_id') ? 'paypal' : null),
        'product'         => null,
        'version'         => null,
        'alternatives'    => array(),
        'show_switcher'   => false,
        'details'         => billing_line_details($item),
    );
    $product = new Product($item->get('odi_pro_product_id'), TRUE);
    $section['product'] = $product->key ? $product : null;
    $version = new ProductVersion($item->get('odi_prv_product_version_id'), TRUE);
    $section['version'] = $version->key ? $version : null;
    if ($section['payment_system'] !== 'stripe' || !$section['product'] || !$section['version']) {
        return $section;
    }
    $type = $version->get('prv_price_type');
    if ($type && $type !== 'single') {
        foreach (($product->get_product_versions(true) ?: array()) as $alt) {
            $alt_type = $alt->get('prv_price_type');
            if ($alt_type && $alt_type !== 'single' && $alt_type !== $type) {
                $section['alternatives'][] = $alt;
            }
        }
        $section['show_switcher'] = !empty($section['alternatives']) && !$item->get('odi_subscription_cancelled_time');
    }
    return $section;
}

function billing_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
    require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
    require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
    require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));

    $page_vars = array();
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    if (!$session->is_logged_in()) {
        $session->set_return('/profile/billing');
        return LogicResult::redirect('/login');
    }

    if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
        return LogicResult::redirect('/profile');
    }

    $user_id = $session->get_user_id();
    $user = new User($user_id, TRUE);
    $page_vars['user'] = $user;
    $page_vars['settings'] = $settings;

    // Every active subscription, newest first, each with its own section.
    $page_vars['subscriptions'] = array();
    foreach (new MultiOrderItem(
        array('user_id' => $user_id, 'is_active_subscription' => true),
        array('order_item_id' => 'DESC')
    ) as $item) {
        $page_vars['subscriptions'][] = billing_subscription_section($item);
    }
    $systems = array_column($page_vars['subscriptions'], 'payment_system');
    $page_vars['has_stripe'] = in_array('stripe', $systems, true);
    $page_vars['has_paypal'] = in_array('paypal', $systems, true);
    // Change tier manages a tier subscription; hosting is managed elsewhere.
    $page_vars['has_tier_subscription'] = count(new MultiOrderItem(
        array('user_id' => $user_id, 'is_active_subscription' => true, 'grants_tier' => true))) > 0;

    // Get Stripe customer ID (identity lives in the store's stc_stripe_customers table)
    require_once(PathHelper::getIncludePath('plugins/store/data/stripe_customers_class.php'));
    $stripe_cust = StripeCustomer::GetForUser($user->key);
    $stripe_customer_id = null;
    $stripe_helper = null;
    if ($page_vars['has_stripe'] || $stripe_cust->get('stc_customer_id') || $stripe_cust->get('stc_customer_id_test')) {
        try {
            $stripe_helper = new StripeHelper();
            $stripe_customer_id = $stripe_helper->get_stripe_customer_id($user);
        } catch (Exception $e) {
            // Stripe not configured or customer doesn't exist
        }
    }
    $page_vars['stripe_customer_id'] = $stripe_customer_id;

    // The card on file: one per Stripe customer, whatever it pays for.
    $page_vars['payment_method'] = null;
    if ($stripe_customer_id && $page_vars['has_stripe']) {
        try {
            $methods = $stripe_helper->get_payment_methods($stripe_customer_id);
            if ($methods && count($methods->data) > 0) {
                $pm = $methods->data[0];
                $page_vars['payment_method'] = array(
                    'brand' => ucfirst($pm->card->brand),
                    'last4' => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year' => $pm->card->exp_year,
                );
            }
        } catch (Exception $e) {
            // Payment method lookup failed
        }
    }

    // Load invoices
    $page_vars['invoices'] = array();
    if ($stripe_customer_id) {
        try {
            $stripe_invoices = $stripe_helper->get_customer_invoices($stripe_customer_id, 10);
            foreach ($stripe_invoices as $inv) {
                $page_vars['invoices'][] = array(
                    'time' => (int)$inv->created,
                    'date' => date('M j, Y', $inv->created),
                    'description' => $inv->lines->data[0]->description ?? 'Subscription payment',
                    'amount' => number_format($inv->amount_paid / 100, 2),
                    'status' => $inv->status,
                    'pdf_url' => $inv->invoice_pdf,
                );
            }
        } catch (Exception $e) {
            // Invoice lookup failed — fall back to local orders
        }
    }

    // A one-time purchase (a licence, a domain's first year) is charged on its
    // own and has no invoice, so the history takes it from the order.
    if (!empty($page_vars['invoices'])) {
        foreach (new MultiOrderItem(
            array('user_id' => $user_id, 'status' => OrderItem::STATUS_PAID),
            array('order_item_id' => 'DESC'),
            20
        ) as $bought) {
            if ($bought->get('odi_is_subscription') || (float)$bought->get('odi_price') <= 0) {
                continue;
            }
            $bought_product = new Product($bought->get('odi_pro_product_id'), TRUE);
            $bought_time = strtotime((string)$bought->get('odi_status_change_time') . ' UTC');
            $page_vars['invoices'][] = array(
                'time' => (int)$bought_time,
                'date' => $bought->get_local('odi_status_change_time', 'M j, Y'),
                'description' => trim((string)$bought_product->get('pro_name') . ' '
                    . implode(', ', billing_line_details($bought))),
                'amount' => number_format((float)$bought->get('odi_price'), 2),
                'status' => $bought->get('odi_refund_time') ? 'refunded' : 'paid',
                'pdf_url' => null,
            );
        }
        usort($page_vars['invoices'], function ($a, $b) { return ($b['time'] ?? 0) <=> ($a['time'] ?? 0); });
    }

    // Try PayPal transaction history if no Stripe invoices
    // PayPal history, only when Stripe has none: the first PayPal
    // subscription's (PayPal checkout is off at launch, B8).
    $paypal_item = null;
    foreach ($page_vars['subscriptions'] as $section) {
        if ($section['payment_system'] === 'paypal') { $paypal_item = $section['item']; break; }
    }
    if (empty($page_vars['invoices']) && $paypal_item) {
        try {
            require_once(PathHelper::getIncludePath('plugins/store/includes/PaypalHelper.php'));
            $paypal = new PaypalHelper();
            $transactions = $paypal->get_subscription_transactions(
                $paypal_item->get('odi_paypal_subscription_id'),
                date('Y-m-d\TH:i:s\Z', strtotime('-1 year')),
                date('Y-m-d\TH:i:s\Z')
            );
            if (!empty($transactions['transactions'])) {
                foreach ($transactions['transactions'] as $txn) {
                    $page_vars['invoices'][] = array(
                        'date' => date('M j, Y', strtotime($txn['time'] ?? $txn['create_time'] ?? 'now')),
                        'description' => $txn['payer_name']['given_name'] ?? 'Subscription payment',
                        'amount' => number_format(floatval($txn['amount_with_breakdown']['gross_amount']['value'] ?? 0), 2),
                        'status' => strtolower($txn['status'] ?? 'completed'),
                        'pdf_url' => null,
                    );
                }
            }
        } catch (Exception $e) {
            // PayPal transaction lookup failed
        }
    }

    // Fall back to local order history if no Stripe or PayPal invoices
    if (empty($page_vars['invoices'])) {
        $orders = new MultiOrder(
            array('user_id' => $user_id),
            array('ord_order_id' => 'DESC'),
            10
        );
        $orders->load();
        foreach ($orders as $order) {
            if ($order->get('ord_status') == Order::STATUS_PAID) {
                $page_vars['invoices'][] = array(
                    'date' => $order->get_local('ord_timestamp', 'M j, Y'),
                    'description' => 'Order #' . $order->key,
                    'amount' => number_format($order->get('ord_total_cost'), 2),
                    'status' => 'paid',
                    'pdf_url' => null,
                );
            }
        }
    }

    // Handle POST actions
    if (isset($input['action'])) {
        try {
            switch ($input['action']) {
                case 'update_payment_method':
                    if (!$stripe_customer_id) {
                        throw new Exception('No Stripe account found.');
                    }
                    $return_url = LibraryFunctions::get_absolute_url('/profile/billing?payment_updated=1');
                    $portal_session = $stripe_helper->create_billing_portal_session($stripe_customer_id, $return_url);
                    return LogicResult::redirect($portal_session->url);

                case 'change_billing_cycle':
                    // The subscription this changes, named by the form and
                    // found among the buyer's own active ones.
                    $current_subscription = null;
                    $named = null;
                    $wanted = intval($input['odi_order_item_id'] ?? 0);
                    foreach ($page_vars['subscriptions'] as $section) {
                        if ((int)$section['item']->key === $wanted && $section['payment_system'] === 'stripe') {
                            $named = $section;
                            $current_subscription = $section['item'];
                        }
                    }
                    if (!$current_subscription || !$stripe_helper) {
                        throw new Exception('Billing cycle changes are only available for your own Stripe subscriptions.');
                    }
                    // Only a cycle the page offers this subscription: another
                    // active recurring version of its product, while it runs.
                    $new_version = null;
                    foreach ($named['show_switcher'] ? $named['alternatives'] : array() as $alt) {
                        if ((int)$alt->key === intval($input['new_version_id'] ?? 0)) {
                            $new_version = $alt;
                        }
                    }
                    if ($new_version === null) {
                        throw new Exception('Invalid billing option selected.');
                    }

                    // The version's own price, looked up rather than read from the
                    // stored id: an id stored before 10-26 may be a coupon's
                    // discounted price, and the lookup replaces it.
                    $new_price_id = $stripe_helper->get_or_create_price($new_version)->id;

                    // The item that bills this line: one subscription may pay
                    // for several lines, one item each.
                    $stripe_sub = $stripe_helper->get_subscription($current_subscription->get('odi_stripe_subscription_id'));
                    $item_id = $stripe_helper->subscription_item_for_line($stripe_sub, $current_subscription)->id;

                    $stripe_helper->change_subscription(
                        $current_subscription->get('odi_stripe_subscription_id'),
                        $item_id,
                        $new_price_id
                    );

                    // Update local record
                    $current_subscription->set('odi_prv_product_version_id', $new_version->key);
                    $current_subscription->set('odi_price', $new_version->get('prv_version_price'));
                    $current_subscription->save();

                    $session->save_message(new DisplayMessage(
                        'Your billing cycle has been updated to ' . $new_version->get('prv_version_name') . '.',
                        'Billing Updated',
                        null,
                        DisplayMessage::MESSAGE_ANNOUNCEMENT
                    ));
                    return LogicResult::redirect('/profile/billing');
            }
        } catch (Exception $e) {
            error_log("Billing page error: " . $e->getMessage());
            $page_vars['error_message'] = $e->getMessage();
        }
    }

    // Success messages from redirects
    if (isset($input['payment_updated'])) {
        $page_vars['success_message'] = 'Your payment method has been updated.';
    }


    return LogicResult::render($page_vars);
}
?>
