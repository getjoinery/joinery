# Fix legacy logic files with top-level executable code

## Problem

`public_html/logic/` contains 49 files matching the `*_logic.php` convention. 46 follow the modern pattern (a single `{name}_logic($get, $post)` function returning a `LogicResult`); 3 do not:

| File | Shape | Callers |
|------|-------|---------|
| `get_subscriptions_logic.php` | Top-level script: instantiates `StripeHelper`, calls `$session->check_permission(0)`, queries Stripe, echoes `<li>` rows for a "Recurring Donations" sidebar widget. 92 lines. | **None.** Orphaned in the LogicResult migration (commit `0f374711`). |
| `get_appointments_logic.php` | Top-level script: instantiates `AcuityScheduling`, calls `$session->check_permission(0)`, hits the Acuity API, echoes table rows for an upcoming-appointments widget. 58 lines. | **None.** Same orphan history. |
| `product_scripts_logic.php` | Function-library stub. All real code commented out. Functions follow a `_product_script` suffix (not `_logic`). 14 lines. | `data/products_class.php:125` (require_once at purchase time); `adm/logic/admin_product_edit_logic.php:257` (reads function names via `LibraryFunctions::getFunctionNamesFromFile` to populate an admin dropdown). |

`require_once`-ing the first two has visible side effects: permission checks, Stripe/Acuity API calls, HTML emission. Tools that scan `/logic/` (the REST API's action discovery, the planned AI action discovery in `specs/joinery_ai.md`) currently use `file_get_contents` + regex to inspect the file *without* including it, specifically because of these three.

This spec removes the hazard so future scanners can `require_once` freely.

## Goal

After this work, every file in `public_html/logic/*_logic.php` is safe to include — no side effects, no echoed output, no DB queries on include. Either the file defines functions only, or it doesn't exist.

## Plan

### 1. Delete `get_subscriptions_logic.php`

Zero callers. The "Recurring Donations" sidebar widget it once powered is no longer wired up. Verified:

```bash
grep -rn --include="*.php" "get_subscriptions_logic" public_html/
# (no hits outside logic/ itself)
```

If a future feature wants the same widget, it should be re-built as a proper view + logic pair following current conventions.

### 2. Delete `get_appointments_logic.php`

Same situation. Zero callers, orphaned widget. Acuity integration code (`includes/AcuityScheduling.php`) stays — it's referenced elsewhere — but this consumer is dead.

### 3. Move `product_scripts_logic.php` → `hooks/product_purchase.php`

The file is not a logic file — it's the core hook library for product purchase scripts. It belongs in a `hooks/` directory that mirrors the plugin convention (`plugins/{name}/hooks/product_purchase.php`).

**Create `public_html/hooks/`** — the core-side parallel of plugin hook directories.

**Move the file:**
```
public_html/logic/product_scripts_logic.php  →  public_html/hooks/product_purchase.php
```

**Update the two callers:**

- `data/products_class.php:125` — change the require path:
  ```php
  // Before
  require_once(PathHelper::getIncludePath('logic/product_scripts_logic.php'));
  // After
  require_once(PathHelper::getIncludePath('hooks/product_purchase.php'));
  ```

- `adm/logic/admin_product_edit_logic.php:257` — change the scan path:
  ```php
  // Before
  LibraryFunctions::getFunctionNamesFromFile(PathHelper::getRootDir() . '/logic/product_scripts_logic.php')
  // After
  LibraryFunctions::getFunctionNamesFromFile(PathHelper::getIncludePath('hooks/product_purchase.php'))
  ```

Delete `product_scripts_logic.php` after both callers are updated.

### 4. Fix the hook design discrepancy

Two inconsistencies exist between the code and its documentation that must be resolved at the same time as the move, since we're touching these files anyway.

**Discrepancy A — mechanism mismatch:** `docs/product_purchase_hooks.md` documents plugin hooks as plain scripts that receive data via `$data` variables and execute on include. The actual `run_product_scripts()` method works differently: it requires all hook files (core + plugin) to load named functions into scope, then calls those functions by name. The function names are stored on the product in `pro_product_scripts` and selected via the admin UI. The docs are wrong; the named-function pattern is the correct one.

**Discrepancy B — function signature mismatch:** The stub in `product_scripts_logic.php` documents the function signature as `($user, $product, $order, $order_item, $cart)` but `run_product_scripts()` only calls `$product_script($user, $order_item)`. The signature is impoverished — `$product` is `$this` inside `run_product_scripts` and `$order` is in scope at the call site in `cart_charge_logic.php`. Both can be added at no cost.

**Fix for discrepancy B — enrich the function signature:**

In `data/products_class.php`, change the method signature and the call:
```php
// Before
function run_product_scripts($user, $order_item) {
    ...
    $product_script($user, $order_item);
}

// After
function run_product_scripts($user, $order_item, $order = null) {
    ...
    $product_script($user, $this, $order_item, $order);
}
```

In `logic/cart_charge_logic.php:707`, pass `$order`:
```php
// Before
$product->run_product_scripts($user, $order_item);

// After
$product->run_product_scripts($user, $order_item, $order);
```

**Canonical hook function signature after fix:**
```php
function my_hook_product_script($user, $product, $order_item, $order) {
    // $user       — User object who purchased
    // $product    — Product object that was purchased
    // $order_item — OrderItem line item (quantity, price, etc.)
    // $order      — Order object (null if unavailable, guard with isset)
}
```

**Update `hooks/product_purchase.php`** with the corrected sample:
```php
<?php
/* Core product purchase hook library.
 *
 * Add functions here to make them available in the admin product editor
 * under "Run these scripts upon purchase." Function names must end with
 * _product_script and accept ($user, $product, $order_item, $order).
 *
 * Plugin-specific hooks go in plugins/{name}/hooks/product_purchase.php
 * following the same convention.
 */

/*
function sample_product_script($user, $product, $order_item, $order) {
    // Your logic here. Return value is ignored.
}
*/
```

**Fix for discrepancy A — update the docs:** Rewrite `docs/product_purchase_hooks.md` to document the named-function pattern correctly, replacing the plain-script examples with the function-based pattern.

## Verification

After the changes:

```bash
# All remaining logic files should have a function definition near the top
php -r '
$count_ok = $count_bad = 0;
foreach (glob("public_html/logic/*_logic.php") as $f) {
    $contents = file_get_contents($f);
    $tokens = token_get_all($contents);
    $found_function = false;
    foreach ($tokens as $t) {
        if (is_array($t) && in_array($t[0], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            $found_function = true;
            break;
        }
    }
    if ($found_function) $count_ok++;
    else { $count_bad++; echo "MISSING: $f\n"; }
}
echo "OK: $count_ok, top-level scripts: $count_bad\n";
'
# Expected: OK: 46, top-level scripts: 0
```

Plus a smoke test: load `/admin/admin_product_edit?pro_product_id=N` and confirm the "Product script" dropdown still populates (validates that the `getFunctionNamesFromFile` caller still finds the file).

## Out of scope

- Adding real product purchase hook functions to `hooks/product_purchase.php`. The file stays as a documented stub.
- Auditing other directories (`adm/logic/`, `plugins/*/logic/`) for the same hazard. v1 of this fix targets `public_html/logic/` only because that's what `joinery_ai.md` and `apiv1.php` scan today.

## Acceptance

- [ ] `public_html/logic/get_subscriptions_logic.php` deleted
- [ ] `public_html/logic/get_appointments_logic.php` deleted
- [ ] `public_html/hooks/` directory created
- [ ] `public_html/hooks/product_purchase.php` created with corrected sample and signature
- [ ] `public_html/logic/product_scripts_logic.php` deleted
- [ ] `data/products_class.php:125` require path updated to `hooks/product_purchase.php`
- [ ] `data/products_class.php` `run_product_scripts()` signature changed to `($user, $order_item, $order = null)` and call changed to `$product_script($user, $this, $order_item, $order)`
- [ ] `adm/logic/admin_product_edit_logic.php:257` scan path updated to `hooks/product_purchase.php`
- [ ] `logic/cart_charge_logic.php:707` call updated to pass `$order`
- [ ] Verification script reports 46 OK files, 0 top-level scripts
- [ ] Admin product edit page still populates the script-name dropdown
- [ ] `docs/product_purchase_hooks.md` rewritten to document named-function pattern
- [ ] `joinery_ai.md` updated to assume direct `require_once` is safe (separate edit, lands with this fix)
