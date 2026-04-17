# Unified Payment Mode Testing Specification

## Executive Summary

This specification outlines the requirements for extending the existing ProductTester framework to support multiple payment modes through a unified testing approach. The current test suite only tests `stripe_regular` mode (on-page payment collection). This spec details how to extend the same test to also cover `stripe_checkout` mode (redirect to Stripe-hosted checkout page) and potentially other payment methods, using configuration-driven mode selection rather than separate test implementations.

## Current Testing Architecture

### Existing Test Flow (stripe_regular)
1. **Product Creation**: Creates test products via `/adm/admin_product_edit`
2. **Cart Management**: Adds products to cart via `/product` endpoint
3. **Payment Processing**: Submits payment directly to `/cart_charge` with test token
4. **Verification**: Validates order creation and Stripe charge

### Key Components
- **ProductTester.php**: Main test orchestration class
- **products_to_test.json**: Product specifications
- **Test Database**: Isolated test environment
- **Test Mode Detection**: Uses `$_SESSION['test_mode']` or debug setting

## Stripe Checkout Mode Differences

### stripe_regular vs stripe_checkout

| Aspect | stripe_regular | stripe_checkout |
|--------|---------------|-----------------|
| **Payment UI** | On-site form | Stripe-hosted page |
| **Token Generation** | JavaScript on client | Handled by Stripe |
| **Session Creation** | Not required | Required before redirect |
| **Return URL** | None needed | Success/cancel URLs required |
| **Order Creation** | During payment | After return from Stripe |
| **Session Validation** | Not applicable | Critical for security |

## Implementation Requirements

### 1. Configuration Changes

#### Test Environment Setup
```php
// Required settings for stripe_checkout testing
$test_settings = [
    'checkout_type' => 'stripe_checkout',
    'stripe_api_key_test' => 'pk_test_...',  // Publishable key
    'stripe_api_pkey_test' => 'sk_test_...', // Secret key
    'stripe_endpoint_secret' => 'whsec_...',  // Webhook secret (same endpoint for test & live)
    'test_base_url' => 'https://joinerytest.site'
];
```

#### Webhook Endpoint Configuration
**IMPORTANT**: Stripe sends real webhooks for test mode transactions to the URL YOU configure!

**Option 1: Configure Test Server Endpoint in Stripe Dashboard**
1. Log into Stripe Dashboard (test mode)
2. Go to Developers → Webhooks
3. Add endpoint with YOUR test server URL:
   - For public test server: `https://joinerytest.site/ajax/stripe_webhook`
   - For local development: Use ngrok or Stripe CLI (see below)
4. Select events: `checkout.session.completed`, `payment_intent.succeeded`
5. Copy the signing secret to `stripe_endpoint_secret` setting

**Option 2: Use Stripe CLI for Local Development**
```bash
# Forward webhooks to your local test server
stripe listen --forward-to localhost:8080/ajax/stripe_webhook

# This will show a webhook signing secret like:
# whsec_test_xxxxx
# Add this to your test configuration
```

**Option 3: Use ngrok for Local Testing**
```bash
# Expose your local server to the internet
ngrok http 8080

# This gives you a public URL like:
# https://abc123.ngrok.io

# Add webhook endpoint in Stripe Dashboard:
# https://abc123.ngrok.io/ajax/stripe_webhook
```

**Option 4: Programmatically Set Webhook Endpoint**
```php
// You can create webhook endpoints via API for each test run
$stripe = new \Stripe\StripeClient('sk_test_...');

$endpoint = $stripe->webhookEndpoints->create([
    'url' => 'https://joinerytest.site/ajax/stripe_webhook',
    'enabled_events' => [
        'checkout.session.completed',
        'payment_intent.succeeded'
    ]
]);

// Store the endpoint secret for this test run
$_SESSION['test_webhook_secret'] = $endpoint->secret;
```

**Option 5: Skip Webhook Testing**
```php
// If webhook endpoint isn't accessible, you can skip waiting
$this->payment_config['stripe_checkout']['wait_for_webhook'] = false;

// The test will still work but won't verify webhook handling
```

**Important Notes:**
- Stripe only sends webhooks to the URLs you configure
- Each Stripe account can have multiple webhook endpoints
- Test mode and live mode can have different endpoints
- The endpoint URL must be publicly accessible (unless using Stripe CLI)

### 2. Unified Testing Approach

#### Core Architecture: Single Test, Multiple Modes

Instead of creating separate test classes, extend the existing ProductTester with mode-aware methods:

```php
class ProductTester {
    
    /**
     * Test payment flow with configurable payment mode
     * @param string|null $mode Payment mode to test (null = use config default)
     */
    private function testPaymentFlow($mode = null) {
        // Determine mode from parameter or configuration
        $mode = $mode ?? $this->payment_config['default_mode'] ?? 'stripe_regular';
        
        echo "<h3>Testing Payment Flow ({$mode})</h3>\n";
        
        try {
            // Common setup for all payment modes
            $this->setupBillingInfo($this->payment_config['billing_info']);
            $this->addProductsToCart();
            $this->displayCartSummary("Cart before payment ({$mode})");
            
            // Apply coupons if configured
            if (!empty($this->payment_config['test_coupons'])) {
                $this->applyCouponsForTesting();
            }
            
            // Mode-specific payment processing
            $order = $this->processPaymentByMode($mode);
            
            // Common verification for all modes
            $this->verifyOrderCreation($order);
            $this->verifyPaymentStatus($order, $mode);
            $this->displayOrderSummary($order);
            
            echo "✓ <span style='color: green;'><strong>{$mode} payment test completed successfully!</strong></span><br>\n";
            
        } catch (Exception $e) {
            echo "✗ <span style='color: red;'><strong>{$mode} payment test failed:</strong> " . 
                 htmlspecialchars($e->getMessage()) . "</span><br>\n";
            throw $e;
        } finally {
            // Cleanup
            $this->clearCart();
        }
    }
    
    /**
     * Process payment based on configured mode
     */
    private function processPaymentByMode($mode) {
        $settings = Globalvars::get_instance();
        
        // Temporarily set checkout type for this test
        $original_type = $settings->get_setting('checkout_type');
        $settings->set_setting('checkout_type', $mode);
        
        try {
            switch($mode) {
                case 'stripe_regular':
                    return $this->processStripeRegularPayment();
                    
                case 'stripe_checkout':
                    return $this->processStripeCheckoutPayment();
                    
                case 'paypal':
                    return $this->processPayPalPayment();
                    
                default:
                    throw new Exception("Unsupported payment mode: {$mode}");
            }
        } finally {
            // Restore original checkout type
            $settings->set_setting('checkout_type', $original_type);
        }
    }
}
```

### 3. Mode-Specific Payment Methods

#### A. Stripe Regular (Existing)
```php
/**
 * Process payment using stripe_regular mode (existing implementation)
 */
private function processStripeRegularPayment() {
    // Use existing implementation
    $this->simulateStripePayment();
    
    // Return the created order
    return $this->findMostRecentTestOrder();
}
```

#### B. Stripe Checkout (New)
```php
/**
 * Process payment using stripe_checkout mode
 */
private function processStripeCheckoutPayment() {
    echo "Processing Stripe Checkout payment...<br>\n";
    
    // Step 1: Create checkout session
    $session_id = $this->createStripeCheckoutSession();
    
    // Step 2: Validate session (uses new security improvements)
    $stripe_helper = new StripeHelper();
    $validated_session_id = $stripe_helper->validate_session_id($session_id);
    echo "✓ Session validation passed<br>\n";
    
    // Step 3: Wait for webhook (Stripe sends real webhooks in test mode)
    if ($this->payment_config['stripe_checkout']['wait_for_webhook'] ?? true) {
        $timeout = $this->payment_config['stripe_checkout']['webhook_timeout'] ?? 10;
        $this->waitForCheckoutWebhook($session_id, $timeout);
    }
    
    // Step 4: Simulate return from Stripe (as if user was redirected back)
    $this->simulateCheckoutReturn($session_id);
    
    // Step 5: Return the created order
    return Order::GetByStripeSession($session_id);
}
```

### 4. Supporting Methods for Stripe Checkout

```php
/**
 * Create a Stripe Checkout session
 */
private function createStripeCheckoutSession() {
    $session = SessionControl::get_instance();
    $cart = $session->get_shopping_cart();
    
    if ($cart->count_all_items() == 0) {
        throw new Exception("Cart is empty - cannot create checkout session");
    }
    
    $stripe_helper = new StripeHelper();
    $billing_user = $this->test_billing_user ?? $session->get_user();
    
    // Build checkout session parameters
    $create_list = $stripe_helper->build_checkout_item_array($cart, $billing_user);
    
    // Add test-specific URLs
    $base_url = $this->settings->get_setting('test_base_url');
    $create_list['success_url'] = $base_url . '/cart_charge?session_id={CHECKOUT_SESSION_ID}';
    $create_list['cancel_url'] = $base_url . '/cart';
    
    // Create session
    $stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
    
    if (!$stripe_session || !$stripe_session->id) {
        throw new Exception("Failed to create Stripe Checkout session");
    }
    
    echo "✓ Created Stripe Checkout session: " . $stripe_session->id . "<br>\n";
    return $stripe_session->id;
}

/**
 * Simulate successful checkout return (can't actually redirect in tests)
 */
private function simulateCheckoutReturn($session_id) {
    echo "Simulating return from Stripe Checkout...<br>\n";
    
    // Manually call cart_charge_logic with session_id
    $_GET['session_id'] = $session_id;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    // Include the charge logic directly
    ob_start();
    require(__DIR__ . '/../../../logic/cart_charge_logic.php');
    $output = ob_get_clean();
    
    // Check for errors in output
    if (strpos($output, 'error') !== false || strpos($output, 'Error') !== false) {
        throw new Exception("Error processing checkout return: " . strip_tags($output));
    }
    
    echo "✓ Checkout return processed successfully<br>\n";
}
```

#### C. Webhook Reception Verification
```php
/**
 * Wait for and verify real Stripe webhook for checkout.session.completed
 * Stripe sends real webhooks in test mode to configured endpoints
 */
private function waitForCheckoutWebhook($session_id, $timeout = 10) {
    echo "Waiting for real Stripe webhook...<br>\n";
    
    $start_time = time();
    $webhook_received = false;
    
    // Poll for webhook completion (check if order status updated via webhook)
    while (time() - $start_time < $timeout) {
        // Check if webhook has been processed by looking for webhook timestamp
        $order = Order::GetByStripeSession($session_id);
        
        if ($order && $order->get('ord_webhook_processed')) {
            $webhook_received = true;
            break;
        }
        
        // Wait 1 second before checking again
        sleep(1);
        echo ".";
        flush();
    }
    
    if (!$webhook_received) {
        echo "<br>⚠ Warning: Webhook not received within {$timeout} seconds<br>\n";
        echo "This may indicate webhook endpoint configuration issues<br>\n";
        return false;
    }
    
    echo "<br>✓ Webhook received and processed successfully<br>\n";
    return true;
}

/**
 * Alternative: Directly check webhook logs if available
 */
private function verifyWebhookInLogs($session_id) {
    // Check system logs for webhook receipt
    $log_file = '/var/log/apache2/error.log';
    $recent_logs = shell_exec("tail -n 100 $log_file | grep 'webhook.*$session_id'");
    
    if ($recent_logs) {
        echo "✓ Webhook activity found in logs<br>\n";
        return true;
    }
    
    return false;
}
```


### 5. Test Data Requirements

#### Updated JSON Configuration for Unified Testing
```json
{
  "payment_testing": {
    "enabled": true,
    "test_all_modes": true,
    "default_mode": "stripe_checkout",
    "modes_to_test": ["stripe_regular", "stripe_checkout"],
    
    "stripe_regular": {
      "enabled": true,
      "test_token": "tok_visa"
    },
    
    "stripe_checkout": {
      "enabled": true,
      "wait_for_webhook": true,
      "webhook_timeout": 10
    },
    
    "billing_info": {
      "billing_first_name": "Test",
      "billing_last_name": "User",
      "billing_email": "test@example.com",
      "password": "TestPass123!"
    },
    
    "test_coupons": ["TESTCODE10", "TESTCODE50"]
  }
}
```

#### Running Tests with Different Modes
```php
// In the main test runner
public function run() {
    // ... existing product creation tests ...
    
    // Test payment functionality
    if ($this->payment_config['enabled']) {
        
        if ($this->payment_config['test_all_modes']) {
            // Test all configured modes
            foreach ($this->payment_config['modes_to_test'] as $mode) {
                if ($this->payment_config[$mode]['enabled'] ?? false) {
                    $this->testPaymentFlow($mode);
                }
            }
        } else {
            // Test only the default mode
            $this->testPaymentFlow();
        }
    }
}
```

## Security Considerations

### 1. Test Mode Enforcement
- **CRITICAL**: Must verify test keys are in use before any payment operations
- Check for `pk_test_` and `sk_test_` prefixes
- Abort immediately if live keys detected

### 2. Session Validation
- Utilize the new `validate_session_id()` method from checkout_improvements
- Verify session environment (test vs live) matches current mode
- Implement replay protection

### 3. Database Isolation
- All tests must run on test database
- Implement cleanup after each test run
- Never allow test data in production database

## Implementation Checklist

- [ ] Extend ProductTester class with unified `testPaymentFlow($mode)` method
- [ ] Add `processPaymentByMode()` method with mode switching logic
- [ ] Implement `processStripeCheckoutPayment()` method
- [ ] Add `createStripeCheckoutSession()` helper method
- [ ] Add `waitForCheckoutWebhook()` method for real webhook verification
- [ ] Add `simulateCheckoutReturn()` method for return handling
- [ ] Update existing `processStripeRegularPayment()` to return order
- [ ] Update products_to_test.json with unified payment configuration
- [ ] Add mode-specific verification in `verifyPaymentStatus()`
- [ ] Test both modes in single test run
- [ ] Add cleanup that works for both payment modes
- [ ] Update documentation for unified testing approach

## Benefits of Unified Approach

1. **Code Reuse**: ~80% of test code is shared between modes
2. **Consistent Testing**: All modes tested with same products and scenarios
3. **Easy Comparison**: Can compare results between different payment modes
4. **Maintainability**: Single codebase to maintain and debug
5. **Extensibility**: Easy to add new payment modes (PayPal, Apple Pay, etc.)
6. **Configuration-Driven**: Change test behavior via JSON without code changes
7. **Regression Testing**: Can quickly test all payment modes after changes

## Testing Scenarios

### Positive Tests
1. **Simple Purchase**: Single product, successful payment
2. **Multiple Products**: Cart with multiple items
3. **Subscription**: Recurring product checkout
4. **Mixed Cart**: Subscription + one-time (should fail per business rules)
5. **With Coupon**: Apply discount before checkout
6. **Zero-Cost Order**: After 100% discount coupon

### Negative Tests
1. **Empty Cart**: Attempt checkout with no items
2. **Invalid Session**: Use malformed session ID
3. **Expired Session**: Use session older than 24 hours
4. **Wrong Environment**: Test session in live mode
5. **Replay Attack**: Use same session ID twice
6. **Missing Billing**: No billing information provided

## Success Metrics

- All positive test scenarios pass
- All negative test scenarios fail with appropriate errors
- No test data persists in production
- Test execution time < 60 seconds
- Zero false positives
- Clear error messages for failures

## Future Enhancements

1. **Webhook Testing**: Full webhook endpoint testing
2. **Payment Method Testing**: Multiple card types and payment methods
3. **Currency Testing**: Multi-currency scenarios
4. **SCA Testing**: Strong Customer Authentication flows
5. **Subscription Upgrades**: Test plan changes
6. **Refund Testing**: Automated refund scenarios

## Notes

- The current `simulateStripePayment()` method only works for `stripe_regular` mode
- Stripe Checkout requires actual HTTP redirects which can't be fully simulated in unit tests
- **Stripe sends real webhooks for test mode transactions** - no simulation needed
- Webhooks in test mode behave identically to production (just with test event IDs)
- The Stripe CLI can be used to forward webhooks to localhost during development:
  ```bash
  stripe listen --forward-to localhost:8080/ajax/stripe_webhook
  ```
- May need to implement headless browser testing for full end-to-end checkout flow with actual redirects
- Test webhooks can be resent from the Stripe Dashboard for debugging