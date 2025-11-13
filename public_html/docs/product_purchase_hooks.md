# Product Purchase Hooks Documentation

## Overview

Product purchase hooks allow plugins to execute custom code when a product is purchased. This enables plugins to perform additional setup, API integrations, or custom business logic beyond what the core system provides.

## How Hooks Work

### Location
Hooks are stored in: `/plugins/{plugin_name}/hooks/product_purchase.php`

### Execution
When a product is purchased (via `cart_charge_logic.php`), the system:
1. Completes the core purchase process (order creation, payment, etc.)
2. Assigns subscription tiers if configured (`pro_sbt_subscription_tier_id`)
3. Looks for and executes plugin hooks
4. Passes purchase data to the hook

### Hook Structure

```php
<?php
// /plugins/{plugin_name}/hooks/product_purchase.php

// Data passed to hook
$user = $data['user'];           // User object who made purchase
$product = $data['product'];     // Product object that was purchased
$order = $data['order'];         // Order object created
$order_item = $data['order_item']; // OrderItem object created

// Your custom logic here
if ($product->key == YOUR_PRODUCT_ID) {
    // Perform custom actions
    // - API account creation
    // - External service provisioning
    // - Custom database updates
    // - Email notifications
    // - etc.
}
```

## When to Use Hooks

### Use hooks when you need to:
- **Initialize external API accounts** (e.g., create account in third-party service)
- **Provision resources** (e.g., spin up a server, allocate storage)
- **Custom tracking** (e.g., analytics, affiliate tracking)
- **Complex business logic** that doesn't fit the standard model
- **Integration with external systems**

### DON'T use hooks for:
- **Subscription tier assignment** - Use `pro_sbt_subscription_tier_id` field instead
- **Simple database updates** - Use data model relationships
- **Email notifications** - Use the email template system
- **Permission changes** - Use groups or subscription tiers

## Example: External API Setup

```php
<?php
// /plugins/cloudservice/hooks/product_purchase.php

$user = $data['user'];
$product = $data['product'];
$order_item = $data['order_item'];

// Only process our cloud service products
$cloud_products = [101, 102, 103]; // Product IDs

if (in_array($product->key, $cloud_products)) {

    // Initialize API client
    require_once(PathHelper::getIncludePath('plugins/cloudservice/includes/CloudAPI.php'));
    $api = new CloudAPI();

    try {
        // Create account in external service
        $api_response = $api->createAccount([
            'email' => $user->get('usr_email'),
            'plan' => $product->get('pro_name'),
            'reference_id' => $order_item->key
        ]);

        // Store API account ID for future reference
        $cloud_account = new CloudAccount(NULL);
        $cloud_account->set('cla_usr_user_id', $user->key);
        $cloud_account->set('cla_api_account_id', $api_response['account_id']);
        $cloud_account->set('cla_api_secret', $api_response['api_secret']);
        $cloud_account->save();

        error_log("Cloud account created for user {$user->key}: {$api_response['account_id']}");

    } catch (Exception $e) {
        // Log error but don't break the purchase
        error_log("Failed to create cloud account: " . $e->getMessage());

        // Optionally notify admin
        SystemMailer::sendAdminAlert(
            'Cloud Account Creation Failed',
            "Failed to create cloud account for order {$order->key}: " . $e->getMessage()
        );
    }
}
```

## Example: Subscription Tiers (Not Needed Anymore!)

**Before subscription tier system:**
```php
// OLD WAY - Manual tier assignment via hook
if ($product->key == 19) {
    $account->set('plan', 'basic');
    $account->set('max_devices', 1);
    $account->save();
}
```

**After subscription tier system:**
```php
// NEW WAY - No hook needed!
// Just set pro_sbt_subscription_tier_id on the product
// Tier assignment happens automatically
```

## Best Practices

1. **Error Handling**: Always wrap external API calls in try-catch blocks
2. **Don't Break Purchases**: Log errors but let purchase complete
3. **Idempotency**: Design hooks to be safe if called multiple times
4. **Performance**: Avoid long-running operations (use background jobs if needed)
5. **Logging**: Log important actions for debugging
6. **Security**: Validate all data before using it

## Testing Hooks

```bash
# Test hook syntax
php -l /plugins/yourplugin/hooks/product_purchase.php

# Test with a sample purchase
php /utils/test_product_hook.php --product_id=123 --user_id=456
```

## Migration from Hooks to Core Features

As the system evolves, consider migrating from hooks to core features:

| Use Case | Old (Hook) | New (Core) |
|----------|------------|------------|
| Subscription tiers | Manual assignment in hook | `pro_sbt_subscription_tier_id` field |
| Group membership | Add to group in hook | Product group assignment |
| Email notifications | Send email in hook | Email templates |
| Feature access | Update flags in hook | Subscription tier features |

## Debugging

If a hook isn't executing:
1. Check file location: `/plugins/{plugin}/hooks/product_purchase.php`
2. Verify PHP syntax: `php -l {file}`
3. Check error logs: `/var/www/html/joinerytest/logs/error.log`
4. Add debug logging to verify data:
   ```php
   error_log("Hook called for product: " . $product->key);
   error_log("User: " . $user->key);
   ```

## Security Considerations

1. **Never trust user input** - Even though data comes from the system, validate it
2. **Check permissions** - Verify user should have access to purchased product
3. **Sanitize for external APIs** - Escape/validate data sent to external services
4. **Store secrets securely** - Use encryption for API keys/secrets
5. **Rate limiting** - Prevent abuse if hook calls external services

## Future Enhancements

The hook system could be extended to support:
- Pre-purchase hooks (validation before purchase)
- Post-cancellation hooks (cleanup when subscription ends)
- Upgrade/downgrade hooks (when tier changes)
- Refund hooks (reverse actions on refund)
- Async hooks (queue for background processing)