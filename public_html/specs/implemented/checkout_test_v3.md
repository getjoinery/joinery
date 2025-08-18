# Stripe Checkout Test Implementation - Final Specification

## Executive Summary

Add Stripe Checkout testing to the existing ProductTester with minimal code changes. The implementation requires ONE new method to handle both payment modes efficiently.

## Key Technical Constraints

After thorough code analysis:
- `stripe_regular` requires `$_REQUEST['stripeToken']` (line 185 of cart_charge_logic.php)
- `stripe_checkout` requires `$_GET['session_id']` (line 173 of cart_charge_logic.php)
- These different requirements mean we cannot use the exact same test flow for both modes
- However, we can minimize code duplication with a single smart method

## Implementation Changes

### 1. Add New Method to ProductTester.php

**Location:** Add this new method anywhere in the ProductTester class (e.g., after the existing `simulateStripePayment()` method around line 1320)

```php
/**
 * Process payment based on checkout mode - handles both stripe_regular and stripe_checkout
 * This is the ONLY new method needed!
 */
private function processPaymentByMode($mode) {
    echo "Processing payment via {$mode}...<br>\n";
    
    if ($mode === 'stripe_checkout') {
        // Handle Stripe Checkout flow
        PathHelper::requireOnce('/includes/StripeHelper.php');
        PathHelper::requireOnce('/data/orders_class.php');
        
        $stripe_helper = new StripeHelper();
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        
        // Verify we have a billing user
        $billing_user = $this->test_billing_user ?? $session->get_user();
        if (!$billing_user) {
            throw new Exception("No billing user available for checkout");
        }
        
        // Create checkout session using existing helper
        $create_list = $stripe_helper->build_checkout_item_array($cart, $billing_user);
        $base_url = $this->settings->get_setting('test_base_url') ?? 'https://joinerytest.site';
        $create_list['success_url'] = $base_url . '/cart_charge?session_id={CHECKOUT_SESSION_ID}';
        $create_list['cancel_url'] = $base_url . '/cart';
        
        $stripe_session = $stripe_helper->create_stripe_checkout_session($create_list);
        echo "✓ Created Stripe session: " . $stripe_session->id . "<br>\n";
        
        // Simulate return from Stripe (user would be redirected here)
        $_GET['session_id'] = $stripe_session->id;
        $_POST = []; // Checkout doesn't use POST data
        $_REQUEST = ['session_id' => $stripe_session->id];
        
        // Process the return through cart_charge_logic
        ob_start();
        $get_vars = $_GET;
        $post_vars = $_POST;
        require_once(PathHelper::getRootDir() . '/logic/cart_charge_logic.php');
        cart_charge_logic($get_vars, $post_vars);
        $output = ob_get_clean();
        
        // Check for actual fatal errors (not just the word "error" in content)
        if (strpos($output, 'SystemDisplayableError') !== false || 
            strpos($output, 'Fatal error') !== false ||
            strpos($output, 'Exception') !== false) {
            throw new Exception("Checkout processing failed: " . strip_tags(substr($output, 0, 500)));
        }
        
        return Order::GetByStripeSession($stripe_session->id);
        
    } else {
        // Use existing method for stripe_regular
        $this->simulateStripePayment();
        
        // Find the order that was just created
        PathHelper::requireOnce('/data/orders_class.php');
        PathHelper::requireOnce('/data/multi_orders_class.php');
        
        // Get recent test mode orders
        $orders = new MultiOrder(
            array('ord_test_mode' => 1),
            array('ord_order_id' => 'DESC'),
            10  // Last 10 orders
        );
        $orders->load();
        
        // Find the most recent order (should be the one we just created)
        $order = null;
        $current_time = time();
        
        foreach ($orders as $test_order) {
            $order_time = strtotime($test_order->get('ord_timestamp'));
            $time_diff = $current_time - $order_time;
            
            // If order was created in the last 60 seconds, it's probably ours
            if ($time_diff < 60) {
                $order = $test_order;
                break;
            }
        }
        
        if (!$order) {
            throw new Exception("Could not find test order after payment");
        }
        
        return $order;
    }
}
```

### 2. Modify Existing testPaymentFlow() Method

**Location:** Lines 1226-1244 in ProductTester.php

**BEFORE:**
```php
/**
 * Test payment functionality with Stripe
 */
private function testPaymentFlow() {
    echo "<h3>Testing Payment Flow</h3>\n";
    
    // Store original test mode state
    $original_test_mode = $_SESSION['test_mode'] ?? false;
    
    try {
        // Enable Stripe test mode for this transaction only
        $_SESSION['test_mode'] = true;
        
        // Submit to cart_charge endpoint
        $this->simulateStripePayment();
    } catch (Exception $e) {
        echo "✗ <span style='color: red;'><strong>Payment test failed:</strong> " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
    } finally {
        // Always restore original test mode state
        $_SESSION['test_mode'] = $original_test_mode;
    }
}
```

**AFTER:**
```php
/**
 * Test payment functionality with Stripe
 */
private function testPaymentFlow() {
    echo "<h3>Testing Payment Flow</h3>\n";
    
    // Store original test mode state
    $original_test_mode = $_SESSION['test_mode'] ?? false;
    
    try {
        // Enable Stripe test mode for this transaction only
        $_SESSION['test_mode'] = true;
        
        // Get payment testing configuration
        $json_file = __DIR__ . '/products_to_test.json';
        $json_content = file_get_contents($json_file);
        $specifications = json_decode($json_content, true);
        $payment_config = $specifications['payment_testing'] ?? [];
        
        // Test multiple payment modes if configured
        $payment_modes = $payment_config['modes_to_test'] ?? ['stripe_regular'];
        
        foreach ($payment_modes as $mode) {
            echo "<h4>Testing {$mode} Payment Mode</h4>\n";
            
            // Set the checkout type for this test
            $original_type = $this->settings->get_setting('checkout_type');
            $this->settings->set_setting('checkout_type', $mode);
            
            try {
                // Clear cart and re-add products for clean test if not first mode
                if ($mode !== $payment_modes[0]) {
                    $session = SessionControl::get_instance();
                    $cart = $session->get_shopping_cart();
                    $cart->clear_cart();
                    
                    // Re-add first successful product
                    if (!empty($this->successful_products)) {
                        $product_info = $this->successful_products[0];
                        $this->addProductToCart($product_info['id'], $product_info['spec']);
                        echo "Re-added " . htmlspecialchars($product_info['spec']['pro_name']) . " to cart<br>\n";
                        
                        // Reset billing info
                        if (isset($payment_config['billing_info'])) {
                            $this->setupBillingInfo($payment_config['billing_info']);
                        }
                    }
                }
                
                // Process payment using the appropriate mode
                $order = $this->processPaymentByMode($mode);
                
                // Verify the order
                if (!$order) {
                    throw new Exception("No order returned from payment processing");
                }
                echo "Order created: #" . $order->key . "<br>\n";
                
                // Use existing verification methods
                $this->verifyOrderCreation($order);
                $this->verifyOrderItems($order);
                
                // Verify with Stripe
                if ($this->verifyStripePayment($order)) {
                    echo "✓ <span style='color: green;'><strong>{$mode} verification successful!</strong></span><br>\n";
                } else {
                    echo "✗ <span style='color: red;'><strong>{$mode} verification failed!</strong></span><br>\n";
                }
                
            } catch (Exception $e) {
                echo "✗ <span style='color: red;'><strong>{$mode} test failed:</strong> " . 
                     htmlspecialchars($e->getMessage()) . "</span><br>\n";
            } finally {
                // Restore original checkout type
                $this->settings->set_setting('checkout_type', $original_type);
            }
        }
        
    } catch (Exception $e) {
        echo "✗ <span style='color: red;'><strong>Payment test failed:</strong> " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
    } finally {
        // Always restore original test mode state
        $_SESSION['test_mode'] = $original_test_mode;
    }
}
```

### 3. Update Configuration File

**File:** `/tests/functional/products/products_to_test.json`

**BEFORE:** (typical existing structure)
```json
{
  "products": [
    {
      "pro_name": "Test Product",
      "pro_price": 29.99,
      // ... other product fields
    }
  ],
  "payment_testing": {
    "enabled": true,
    "billing_info": {
      "billing_first_name": "Test",
      "billing_last_name": "User",
      "billing_email": "test@example.com",
      "password": "TestPass123!"
    }
  }
}
```

**AFTER:** (add modes_to_test array)

```json
{
  "products": [
    {
      "pro_name": "Test Product",
      "pro_price": 29.99,
      // ... other product fields
    }
  ],
  "payment_testing": {
    "enabled": true,
    "modes_to_test": ["stripe_regular", "stripe_checkout"],  // ← ADD THIS LINE
    "billing_info": {
      "billing_first_name": "Test",
      "billing_last_name": "User",
      "billing_email": "test@example.com",
      "password": "TestPass123!"
    }
  }
}
```

## Why This Works

### For `stripe_regular`:
1. Existing `simulateStripePayment()` sets `$_POST['stripeToken'] = 'tok_visa'`
2. `cart_charge_logic.php` line 185 checks for `$_REQUEST['stripeToken']` ✓
3. Order is created and we find it by timestamp

### For `stripe_checkout`:
1. We create a real Stripe session via API
2. Set `$_GET['session_id']` with valid session ID
3. `cart_charge_logic.php` line 173 checks for `$_GET['session_id']` ✓
4. Order is created and we retrieve it using `Order::GetByStripeSession()`

## What We're Testing

### Backend Processing (✓ Tested ~70%)
- Session creation and validation
- Order creation and status
- Order items creation
- Payment recording
- Database integrity
- Error handling

### Not Tested (Requires Manual/E2E Testing)
- Actual Stripe redirect flow
- Browser-based payment form
- Webhook reception (happens async)
- Customer receipt emails
- UI/UX elements

## Complete File Change Summary

### Files Modified:

1. **`/tests/functional/products/ProductTester.php`**
   - **Add:** New method `processPaymentByMode()` after line ~1320 (adds ~80 lines)
   - **Modify:** Method `testPaymentFlow()` at lines 1226-1244 (replaces ~18 lines with ~70 lines)
   - **Total impact:** ~130 lines of code changes

2. **`/tests/functional/products/products_to_test.json`**
   - **Add:** One line `"modes_to_test": ["stripe_regular", "stripe_checkout"],` to existing `payment_testing` object
   - **Total impact:** 1 line addition

### No Other Files Modified:
- No production code changes required
- No new files created
- No database schema changes
- No configuration file changes outside the test directory

## Total Code Impact

- **Lines added**: ~100
- **Files modified**: 2
- **New methods**: 1
- **Production changes**: NONE

## Webhook Considerations

For `stripe_checkout`, webhooks are optional because:
1. In test mode, Stripe automatically marks sessions as 'paid'
2. `cart_charge_logic.php` processes the session synchronously
3. The order is created without waiting for webhooks

To test webhooks (optional):
- Configure endpoint in Stripe Dashboard: `https://joinerytest.site/ajax/stripe_webhook`
- Webhooks will be received but are not required for basic functionality testing

## Success Criteria

✓ Test passes = Backend payment processing works correctly
✓ Order created with correct status
✓ Order items properly linked
✓ Stripe verification successful

For complete confidence, also perform:
- Manual browser testing of actual checkout flow
- Webhook endpoint verification
- Email receipt validation

## Prerequisites

Before implementing:
1. **Test Stripe keys** configured in settings (`stripe_api_key_test`, `stripe_api_pkey_test`)
2. **Test database** configured and accessible
3. **Admin user session** with appropriate permissions
4. **Test products** created in the system (or will be created by the test)
5. **$this->test_billing_user** property exists in ProductTester (or falls back to session user)

## Error Handling Notes

1. **Session Creation Failure**: If `create_stripe_checkout_session()` fails, check:
   - Stripe API keys are valid test keys
   - Product prices are properly configured
   - Cart has valid items

2. **Order Not Found**: If order retrieval fails:
   - For stripe_checkout: Verify session was processed successfully
   - For stripe_regular: Check that order has `ord_test_mode = 1`

3. **Include Errors**: The cart_charge_logic inclusion may fail if:
   - PathHelper is not properly initialized
   - Required dependencies are not loaded

## Summary

This minimal implementation:
- Adds ONE method to handle both payment modes
- Reuses ALL existing verification code
- Requires NO production code changes
- Provides ~70% confidence in checkout functionality
- Takes ~5 minutes to implement

The remaining 30% (UI flow, redirects, webhooks) requires manual or E2E browser testing, which is beyond the scope of this unit test but is acceptable for verifying core payment processing logic.