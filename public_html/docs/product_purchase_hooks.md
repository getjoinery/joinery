# Product Purchase Hooks

When a product is purchased, the system calls any hook functions registered against that product. Hooks are named PHP functions defined in hook library files; function names are stored on the product and selected via the admin UI.

## How it works

1. `cart_charge_logic.php` calls `$product->run_product_scripts($user, $order_item, $order)` after a successful charge.
2. `run_product_scripts()` requires all hook library files — the core file and every installed plugin's hook file — loading their functions into scope.
3. It then calls each function name stored in `pro_product_scripts` on the product, passing the purchase context.

## Hook library locations

| Scope | Path |
|-------|------|
| Core | `public_html/hooks/product_purchase.php` |
| Plugin | `plugins/{plugin_name}/hooks/product_purchase.php` |

Both files are loaded on every purchase. Functions from either file can be assigned to any product.

## Writing a hook function

Hook functions must:
- Have a name ending in `_product_script`
- Accept exactly `($user, $product, $order_item, $order)`
- Return nothing (return value is ignored)

```php
<?php
// plugins/myplugin/hooks/product_purchase.php

function provision_account_product_script($user, $product, $order_item, $order) {
    // $user       — User object who purchased
    // $product    — Product object that was purchased
    // $order_item — OrderItem line item (quantity, price, etc.)
    // $order      — Order object ($order may be null; guard if needed)

    require_once(PathHelper::getIncludePath('plugins/myplugin/includes/MyAPI.php'));
    $api = new MyAPI();

    try {
        $api->createAccount($user->get('usr_email'), $order_item->key);
    } catch (Exception $e) {
        error_log("provision_account_product_script failed for order_item {$order_item->key}: " . $e->getMessage());
        // Do not rethrow — let the purchase complete even if provisioning fails.
    }
}
```

## Assigning hooks to products

The admin product edit page lists all discovered `_product_script` functions in a checkbox UI. Select the functions that should run when a given product is purchased; the names are stored in `pro_product_scripts` as a comma-separated list.

## When to use hooks

Use hooks for actions that can't be expressed through the built-in product config fields:

- Provisioning an account in an external system
- Calling a third-party API to allocate a resource
- Custom business logic tied to a specific product

### When NOT to use hooks

| Need | Use instead |
|------|-------------|
| Assign a subscription tier | `pro_sbt_subscription_tier_id` on the product |
| Add user to a group | Product group assignment |
| Send a confirmation email | Email template system |
| Grant feature access | Subscription tier features |

## Best practices

- Catch exceptions and log them — do not let a hook failure break the purchase.
- Design hooks to be idempotent (safe if called more than once).
- Avoid slow external calls in the hot path; prefer background jobs for long-running work.
- Log meaningful context (`order_item->key`, `user->key`) to aid debugging.

## Debugging

```bash
# Check hook file syntax
php -l public_html/hooks/product_purchase.php
php -l public_html/plugins/myplugin/hooks/product_purchase.php

# Check error log for failures
grep "product_script" /var/www/html/joinerytest/logs/error.log
```

If a hook function doesn't appear in the admin UI dropdown, verify:
1. The function name ends in `_product_script`
2. The file is in the correct location (`hooks/product_purchase.php` under core or plugin)
3. PHP syntax is valid (`php -l`)
