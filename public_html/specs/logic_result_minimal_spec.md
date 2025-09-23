# LogicResult Minimal Architecture Specification

## Overview
Establish `LogicResult` as the standard return type for all logic files. This is a pure architectural change - no validation, no fancy features. Just consistent return types and redirect handling.

## Phase 1: Core Architecture Only

### The Minimal LogicResult Class

Create `/includes/LogicResult.php`:

```php
<?php
/**
 * Standard result object for logic functions
 * Provides consistent return format and redirect handling
 */
class LogicResult {
    public $redirect = null;    // URL to redirect to (if any)
    public $data = [];          // Data to pass to view
    public $error = null;       // Error message (if any)

    // Convenience factory methods
    public static function redirect($url, $data = []) {
        $result = new self();
        $result->redirect = $url;
        $result->data = $data;
        return $result;
    }

    public static function render($data = []) {
        $result = new self();
        $result->data = $data;
        return $result;
    }

    public static function error($message, $data = []) {
        $result = new self();
        $result->error = $message;
        $result->data = $data;
        return $result;
    }
}
```

### Router Update for Backward Compatibility

Since logic functions are called from both the router AND directly from view files, we need a simpler approach that doesn't require modifying all calling locations.

In `serve.php` or `RouteHelper::handleDynamicRoute()`:

```php
// Call the logic function
$result = some_logic($_GET, $_POST, $page);

// Handle both new LogicResult pattern and old array pattern
if ($result instanceof LogicResult) {
    // New pattern - structured result
    if ($result->redirect) {
        LibraryFunctions::redirect($result->redirect);
        exit();
    }

    // Note: In Phase 1, we don't use $result->error
    // Exceptions are still thrown directly

    $page_vars = $result->data;

} elseif (is_array($result)) {
    // Old pattern - backward compatibility
    $page_vars = $result;

} else {
    // No return value (some old logic files)
    $page_vars = [];
}

// Continue with view rendering...
```

### Logic File Conversion Pattern

#### Example: Simple Display Logic

```php
// BEFORE:
function product_logic($get, $post, $page) {
    $product = new Product($get['id'], TRUE);
    $cart = $session->get_shopping_cart();

    return array(
        'product' => $product,
        'cart' => $cart
    );
}

// AFTER:
function product_logic($get, $post, $page) {
    $product = new Product($get['id'], TRUE);
    $cart = $session->get_shopping_cart();

    return LogicResult::render([
        'product' => $product,
        'cart' => $cart
    ]);
}
```

#### Example: Redirect Logic

```php
// BEFORE:
function logout_logic($get, $post, $page) {
    $session->logout();
    LibraryFunctions::redirect('/');
    exit();
}

// AFTER:
function logout_logic($get, $post, $page) {
    $session->logout();
    return LogicResult::redirect('/');
}
```

#### Example: Error Handling (Keep As-Is in Phase 1)

```php
// BEFORE:
function protected_logic($get, $post, $page) {
    if (!$session->is_logged_in()) {
        throw new DisplayableException('You must be logged in');
    }
    // ... rest of logic
}

// AFTER (Phase 1 - NO CHANGE to exceptions):
function protected_logic($get, $post, $page) {
    if (!$session->is_logged_in()) {
        throw new DisplayableException('You must be logged in');  // KEEP AS-IS
    }
    // ... rest of logic
    return LogicResult::render($data);  // Only change returns
}
```

### Mechanical Conversion Rules (Phase 1 - Truly Safe)

1. **Array return** → `LogicResult::render($array)`
2. **LibraryFunctions::redirect() + exit()** → `return LogicResult::redirect($url)`
3. **header("Location: ") + exit()** → `return LogicResult::redirect($url)`
4. **No return statement** → Add `return LogicResult::render()` at end
5. **throw statements** → **KEEP AS-IS** (don't convert in Phase 1)

**Important:** By NOT converting exceptions in Phase 1, we avoid the complexity of handling logic calls from multiple locations (router and views). Exceptions continue to work exactly as before.

### Implementation Priority

1. Create LogicResult class (5 minutes)
2. Update router to handle both patterns (30 minutes)
3. Convert ONE logic file as proof of concept (10 minutes)
4. Test manually that it works (10 minutes)
5. Batch convert remaining files (2-3 hours of mechanical work)

## Phase 2: Future Enhancements (Not Now)

These can be added later without breaking anything:

### Exception Conversion
- Convert `throw new DisplayableException()` to `return LogicResult::error()`
- Update the ~33 view files that call logic functions to handle LogicResult
- Remove throw statements from logic files entirely

### Additional Features
- Validation support
- Field-specific error messages
- HTTP status codes
- JSON response mode for AJAX
- Success messages
- Debugging information
- Response caching hints
- Breadcrumb data
- Meta tags for SEO

## Benefits of Phase 1 Alone

1. **Consistent return type** - Every logic file returns LogicResult
2. **No more exit() calls** - Cleaner control flow
3. **Testable** - Can test return values instead of intercepting redirects
4. **Future-proof** - Easy to add features to LogicResult class later
5. **Type-hintable** - IDEs and static analysis understand the return type

## Migration Approach

### Option A: Big Bang (Recommended)
- Convert all 40 files in one session
- Test critical paths
- Done in one day

### Option B: Gradual
- Router supports both patterns permanently
- Convert files as you touch them
- May take months to complete

## Why This Is Safe

1. **Exceptions Unchanged:** By not converting throw statements, all exception handling continues to work exactly as before
2. **Fully Mechanical:** Only converting returns and redirects - simple find-and-replace
3. **No Behavior Changes:** The application behaves identically after conversion
4. **No View Changes Required:** Since exceptions still work, the 33+ view files that call logic functions don't need any changes
5. **Backward Compatible:** Old logic files (if any missed) still work

## Automated Conversion Script

```bash
#!/bin/bash
# Phase 1: Only convert returns and redirects, NOT exceptions

# Add LogicResult include at top of each file
for file in logic/*_logic.php; do
    sed -i '3i\PathHelper::requireOnce('"'"'includes/LogicResult.php'"'"');' "$file"
done

# Convert return arrays
sed -i 's/return array(/return LogicResult::render([/g' logic/*.php
sed -i 's/return \[/return LogicResult::render([/g' logic/*.php

# Convert redirects with exit
perl -0777 -i -pe 's/LibraryFunctions::redirect\((.*?)\);\s*exit\(\);/return LogicResult::redirect($1);/gs' logic/*.php
perl -0777 -i -pe 's/header\(["'"'"']Location:\s*(.*?)["'"'"']\);\s*exit\(\);/return LogicResult::redirect($1);/gs' logic/*.php

# DO NOT convert exceptions in Phase 1 - they stay as throw statements

# Files that need manual review will be those with:
# - No explicit return (need to add return LogicResult::render())
# - Multiple return paths
# - Non-standard patterns
```

## Summary

Phase 1 is a purely mechanical change that establishes a consistent return pattern with zero risk:

1. Add a 30-line LogicResult class
2. Update router to handle LogicResult (simple if-statement)
3. Convert ONLY returns and redirects (NOT exceptions)
4. Test critical paths

Total time: ~3 hours for entire codebase

By leaving exceptions unchanged, we avoid ALL complexity:
- No need to modify view files
- No need to track down try-catch blocks
- No need to add re-throw logic anywhere
- 100% backward compatible

Phase 2 (future) can add error handling, validation, and other features when we have time to properly handle the view file calls.