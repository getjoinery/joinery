# Venmo Checkout Integration via PayPal

## Overview

Add Venmo as a visible, properly supported payment option at checkout by leveraging the existing PayPal Checkout integration. Venmo payments flow through PayPal's Orders API — the same server-side capture and verification used for regular PayPal payments — so the backend changes are minimal. The focus is on ensuring the Venmo button renders reliably, tracking which payment method was used, and handling Venmo-specific eligibility constraints gracefully.

## Prerequisites (Account Configuration — Not Code)

Before any code changes take effect, the PayPal Business account must be configured:

1. **PayPal Business Account** — required to accept Venmo. Personal accounts cannot accept Venmo merchant payments.
2. **Venmo enabled in PayPal Dashboard** — Log into PayPal Business account → Account Settings → Payment Preferences → ensure Venmo is enabled as an accepted payment method.
3. **US-based business entity** — Venmo is US-only.
4. **Currency must be USD** — Venmo does not support other currencies.

These are one-time account setup steps, not code changes.

## Current State

The codebase already includes `&enable-funding=venmo` in the PayPal SDK script tags (`PaypalHelper.php` lines 83 and 114). However, several gaps exist:

1. **No payment method tracking** — When a Venmo payment completes, it's recorded identically to a PayPal card payment. There's no way to know which orders were paid via Venmo.
2. **No PayPal order ID stored** — Unlike Stripe (which stores session ID, payment intent, and charge ID), PayPal orders have no persistent reference in the database after verification.
3. **No eligibility handling** — If Venmo isn't eligible (non-US buyer, desktop without Venmo app, non-USD currency), the button silently doesn't render with no explanation to the user.
4. **Subscription limitation undocumented** — Venmo does not support PayPal billing agreements/subscriptions, but the subscription SDK script still includes `enable-funding=venmo`.

## Implementation

### Part 1: Store PayPal Order ID and Payment Method

**Why:** Currently PayPal payments leave no traceable reference in the database. For refunds, disputes, or simply knowing how a customer paid, the order ID and payment method should be stored.

**File: `data/orders_class.php`**

Add two new fields to `$field_specifications`:

```php
'ord_paypal_order_id' => array('type' => 'varchar(64)', 'is_nullable' => true),
'ord_payment_method' => array('type' => 'varchar(32)', 'is_nullable' => true),
```

Also add to `$fields` array:

```php
'ord_paypal_order_id',
'ord_payment_method',
```

The `ord_payment_method` field will store values like `paypal`, `venmo`, `stripe`, `stripe_checkout`, or `free` — providing a unified way to identify payment method across all providers.

### Part 2: Pass Payment Method from Client to Server

**File: `includes/PaypalHelper.php`**

Update `output_paypal_checkout_code()` to capture the funding source selected by the buyer and pass it to the server on redirect.

Change the `onApprove` handler (around line 93-97) from:

```javascript
onApprove: function(data, actions) {
    return actions.order.capture().then(function(details) {
        window.location.href = "[return_url]?id=" + details.id;
    });
},
```

To:

```javascript
onApprove: function(data, actions) {
    return actions.order.capture().then(function(details) {
        window.location.href = "[return_url]?id=" + details.id + "&funding=" + data.paymentSource;
    });
},
```

Also add an `onClick` callback to track the selected funding source as a fallback:

```javascript
onClick: function(data) {
    selectedFundingSource = data.fundingSource;
},
```

And modify `onApprove` to use the fallback if `data.paymentSource` is unavailable:

```javascript
var fundingSource = data.paymentSource || selectedFundingSource || 'paypal';
window.location.href = "[return_url]?id=" + details.id + "&funding=" + fundingSource;
```

**For subscriptions** (`output_paypal_subscription_checkout_code()`): Remove `&enable-funding=venmo` from the subscription SDK script tag (line 114) since Venmo does not support PayPal billing agreements. The parameter is harmless (PayPal will just not show the button) but removing it avoids confusion.

### Part 3: Store Payment Details Server-Side

**File: `logic/cart_charge_logic.php`**

In the PayPal payment verification block (around lines 151-176), after confirming the payment status is COMPLETED:

1. Store the PayPal order ID:
   ```php
   $order->set('ord_paypal_order_id', $payment_id);
   ```

2. Store the payment method:
   ```php
   $funding_source = isset($_GET['funding']) ? $_GET['funding'] : 'paypal';
   // Whitelist valid values
   $valid_sources = array('paypal', 'venmo', 'card', 'paylater');
   $payment_method = in_array($funding_source, $valid_sources) ? $funding_source : 'paypal';
   $order->set('ord_payment_method', $payment_method);
   ```

3. Also store the full PayPal response for reference:
   ```php
   $order->set('ord_raw_response', json_encode($payment));
   ```

Also update the Stripe payment paths to set `ord_payment_method`:
- Stripe Checkout path: `$order->set('ord_payment_method', 'stripe_checkout');`
- Stripe Regular path: `$order->set('ord_payment_method', 'stripe');`
- Free order path (zero total): `$order->set('ord_payment_method', 'free');`
- PayPal subscription path: `$order->set('ord_payment_method', 'paypal');`

### Part 4: Admin Visibility

**File: `adm/admin_order.php`** (or equivalent order detail page)

Display the payment method on the order detail view. Where the order details are shown, add:

```php
// Show payment method if set
$payment_method_display = $order->get('ord_payment_method');
if ($payment_method_display) {
    $method_labels = array(
        'paypal' => 'PayPal',
        'venmo' => 'Venmo',
        'stripe' => 'Stripe',
        'stripe_checkout' => 'Stripe',
        'card' => 'PayPal (Card)',
        'free' => 'Free',
    );
    $label = isset($method_labels[$payment_method_display]) ? $method_labels[$payment_method_display] : ucfirst($payment_method_display);
    // Display $label in the order detail section
}
```

Also display the PayPal order ID (if present) alongside the existing Stripe reference fields.

### Part 5: Admin Setting to Enable/Disable Venmo

Add a `use_venmo_checkout` setting that controls whether the Venmo funding source is included in the PayPal SDK script tag. The toggle is only relevant when PayPal is enabled.

**File: `migrations/migrations.php`**

Add a migration to insert the new setting with a default value of `0` (off):

```php
$migration = array();
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'use_venmo_checkout'";
$migration['migration_sql'] = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('use_venmo_checkout', '0');";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

**File: `adm/admin_settings_payments.php`**

Add a Venmo toggle immediately after the PayPal enable toggle (after line 401). It should be visually nested under PayPal to make the dependency clear:

```php
$formwriter->dropinput('use_venmo_checkout', 'Enable Venmo at Checkout', [
    'options' => array(1 => "Yes", 0 => 'No'),
    'value' => $settings->get_setting('use_venmo_checkout'),
    'empty_option' => false
]);
```

This toggle should be shown/hidden with JavaScript based on the PayPal enable toggle — when PayPal is off, the Venmo toggle should be hidden. Follow the existing show/hide pattern already used on this page.

**File: `includes/PaypalHelper.php`**

In `output_paypal_checkout_code()`, conditionally include `enable-funding=venmo` based on the setting AND currency:

```php
$settings = Globalvars::get_instance();
$venmo_param = '';
if ($settings->get_setting('use_venmo_checkout') && strtoupper($settings->get_setting('site_currency')) === 'USD') {
    $venmo_param = '&enable-funding=venmo';
}
// Use $venmo_param in the script tag instead of hardcoding &enable-funding=venmo
```

When the setting is off or currency is not USD, the `&enable-funding=venmo` parameter is omitted and PayPal renders without a Venmo button.

## Testing

### Sandbox Testing
1. Use PayPal sandbox credentials (already supported via `paypal_api_key_test` / `paypal_api_secret_test`)
2. Add `&buyer-country=US` to the SDK script URL in test mode to simulate Venmo eligibility
3. Verify the Venmo button appears on mobile Safari (iOS) or Chrome (Android)
4. Complete a test purchase via Venmo and confirm:
   - `ord_paypal_order_id` is stored
   - `ord_payment_method` = `venmo`
   - `ord_raw_response` contains the PayPal response
5. Complete a test purchase via regular PayPal and confirm `ord_payment_method` = `paypal`

### Live Testing
1. Verify Venmo button appears when setting is enabled (US buyers, eligible devices)
2. Verify Venmo button does NOT appear when setting is disabled
3. Verify Venmo button does NOT appear for non-USD currency sites (even when enabled)
4. Verify Venmo toggle is hidden in admin when PayPal is disabled
5. Verify subscription checkout does NOT show Venmo button
6. Verify order admin page shows correct payment method

## Venmo Eligibility Notes (for documentation, not code)

The Venmo button will only render when ALL of these conditions are met:
- Merchant has a US PayPal Business account with Venmo enabled
- Transaction currency is USD
- Buyer is in the US
- Buyer is on mobile (iOS Safari or Android Chrome) OR desktop with Venmo app
- Transaction is a one-time payment (not a subscription/billing agreement)

If any condition is not met, PayPal's SDK silently hides the Venmo button. This is standard PayPal behavior and does not require error handling on our side.

## Files Changed

| File | Change |
|------|--------|
| `data/orders_class.php` | Add `ord_paypal_order_id` and `ord_payment_method` fields |
| `includes/PaypalHelper.php` | Capture funding source, conditional Venmo param based on setting, remove from subscriptions |
| `logic/cart_charge_logic.php` | Store order ID, payment method, and raw response |
| `adm/admin_order.php` | Display payment method and PayPal order ID |
| `adm/admin_settings_payments.php` | Add "Enable Venmo at Checkout" toggle under PayPal section |
| `migrations/migrations.php` | Add `use_venmo_checkout` setting (default off) |

## Out of Scope

- **Venmo-specific branding/styling** — PayPal's SDK handles the button appearance automatically
- **Venmo for subscriptions** — Not supported by PayPal; intentionally excluded
- **Separate Venmo API integration** — Not needed; PayPal handles everything
- **Refund via Venmo** — Refunds through PayPal's API work regardless of original funding source
