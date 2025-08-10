# Stripe Order Verification Specification

## Overview
This document outlines how to implement Stripe order verification using the existing StripeHelper class for ProductTester payment flow validation.

## Current StripeHelper Capabilities

### Charge Verification Methods
The StripeHelper class already provides several methods for verifying payments:

1. **`get_charge($stripe_charge_id)`** - Retrieves a specific charge by ID
2. **`get_charge_from_order($order)`** - Gets charge from an Order object
3. **`get_charge_from_payment_intent($stripe_payment_intent_id)`** - Gets charge from payment intent
4. **`get_payment_intent($stripe_payment_intent_id)`** - Retrieves payment intent details

### Session Verification (Missing Method)
Currently, StripeHelper **does not have** a method to retrieve checkout sessions. This would need to be added to `/includes/StripeHelper.php`:
```php
// ADD TO: /includes/StripeHelper.php
public function get_checkout_session($session_id) {
    $session = $this->stripe->checkout->sessions->retrieve($session_id);
    return $session;
}
```

### Test Mode Handling
StripeHelper automatically handles test mode:
- Uses test API keys when `$_SESSION['test_mode']` is true or debug mode is enabled
- Methods like `get_charge_from_order()` include test mode validation

## Order Verification Strategy

### For Regular Stripe Orders (stripe_regular)
Use existing StripeHelper methods to verify charges:

```php
// Get the Stripe charge from the order
$stripe_helper = new StripeHelper();
$charge = $stripe_helper->get_charge_from_order($order);

if ($charge) {
    // Verify charge details
    $verification_results = [
        'charge_id' => $charge->id,
        'amount' => $charge->amount / 100, // Convert from cents
        'currency' => $charge->currency,
        'status' => $charge->status, // 'succeeded', 'pending', 'failed'
        'paid' => $charge->paid,
        'refunded' => $charge->refunded,
        'amount_refunded' => $charge->amount_refunded / 100,
        'created' => $charge->created,
        'description' => $charge->description
    ];
    
    // Compare with order data
    $order_total = $order->get('ord_total_cost');
    $amounts_match = abs($verification_results['amount'] - $order_total) < 0.01;
    $is_successful = $charge->paid && $charge->status === 'succeeded';
}
```

### For Stripe Checkout Orders (stripe_checkout)
Add session verification method to StripeHelper:

```php
// ADD TO: /includes/StripeHelper.php
public function get_checkout_session($session_id) {
    try {
        $session = $this->stripe->checkout->sessions->retrieve($session_id);
        return $session;
    } catch (\Stripe\Exception $e) {
        return false;
    }
}

// ADD TO: /includes/StripeHelper.php
public function verify_checkout_session($order) {
    // Test/prod mode validation (following existing pattern from get_charge_from_order)
    if ($order->get('ord_test_mode')) {
        if (!$this->test_mode) {
            // DON'T VERIFY TEST MODE ORDERS IF NOT IN TEST MODE
            return false;
        }
    } else {
        if ($this->test_mode) {
            // DON'T VERIFY LIVE MODE ORDERS IF IN TEST MODE
            return false;
        }
    }
    
    $session_id = $order->get('ord_stripe_session_id');
    if (!$session_id) {
        return false;
    }
    
    try {
        $session = $this->get_checkout_session($session_id);
        if (!$session) {
            return false;
        }
        
        return [
            'session_id' => $session->id,
            'payment_status' => $session->payment_status, // 'paid', 'unpaid', 'no_payment_required'
            'amount_total' => $session->amount_total / 100,
            'currency' => $session->currency,
            'status' => $session->status, // 'complete', 'expired', 'open'
            'payment_intent' => $session->payment_intent
        ];
    } catch (\Stripe\Exception $e) {
        return false;
    }
}
```

### Usage in ProductTester

```php
// ADD TO: /utils/ProductTester.php
private function verifyStripePayment($order) {
    $stripe_helper = new StripeHelper();
    
    // Determine verification method based on order type
    if ($order->get('ord_stripe_session_id')) {
        // Stripe Checkout verification
        $verification = $stripe_helper->verify_checkout_session($order);
        if ($verification && $verification['payment_status'] === 'paid') {
            echo "✓ Stripe Checkout session verified: " . $verification['session_id'] . "\n";
            return true;
        }
    } elseif ($order->get('ord_stripe_charge_id') || $order->get('ord_stripe_payment_intent_id')) {
        // Regular Stripe verification
        $charge = $stripe_helper->get_charge_from_order($order);
        if ($charge && $charge->paid && $charge->status === 'succeeded') {
            echo "✓ Stripe charge verified: " . $charge->id . "\n";
            echo "  Amount: $" . ($charge->amount / 100) . " " . strtoupper($charge->currency) . "\n";
            return true;
        }
    }
    
    echo "✗ Stripe payment verification failed\n";
    return false;
}
```

## Implementation Plan

### Phase 1: Add Missing StripeHelper Methods
1. Add `get_checkout_session($session_id)` method to StripeHelper
2. Add `verify_checkout_session($order)` method to StripeHelper
3. Test both methods in test mode

### Phase 2: Integrate into ProductTester
1. Add `verifyStripePayment($order)` method to ProductTester
2. Call verification after successful payment simulation
3. Compare Stripe data with local order data
4. Report verification results

### Phase 3: Enhanced Verification
1. Verify payment amounts match exactly
2. Check payment timestamps
3. Verify test mode consistency
4. Validate currency codes

## Benefits of Using StripeHelper

1. **Consistent API Usage**: Uses existing Stripe client configuration
2. **Test Mode Handling**: Automatically uses correct API keys
3. **Error Handling**: Built-in Stripe exception handling
4. **Code Reuse**: Leverages existing patterns and methods
5. **Maintainability**: Centralizes Stripe API interactions

## Data Points to Verify

### Order vs Stripe Charge Comparison
- Amount charged vs order total
- Currency consistency
- Payment status (paid/succeeded)
- Test mode flag consistency
- Timestamp proximity (payment should be recent)

### Session vs Order Comparison (for Stripe Checkout)
- Session payment_status should be 'paid'
- Session amount_total should match order total
- Session status should be 'complete'

## Error Scenarios to Handle

1. **Charge Not Found**: Stripe charge ID exists in order but not found in Stripe
2. **Amount Mismatch**: Stripe amount differs from order total
3. **Status Mismatch**: Order marked as paid but Stripe charge failed
4. **Test Mode Mismatch**: Order is test mode but Stripe data is live (or vice versa)
5. **Network Errors**: Stripe API unavailable during verification

This approach maximizes use of existing StripeHelper functionality while adding minimal new code for comprehensive payment verification.