# Method Existence Test - Blacklist Feature

## Overview

The method existence test now includes a **blacklist** feature to flag known incorrect function/method/property usage patterns. This helps catch common mistakes before they cause runtime errors.

## Blacklist Categories

### 1. Property Access Blacklist
Flags incorrect property access patterns (e.g., using wrong property names):

```php
'property' => [
    '$this->sorts' => 'Use $this->order_by instead (SystemMultiBase stores order in $order_by property)',
]
```

**Example Detection:**
```
🚫 Line   11: $this->sorts - BLACKLISTED: Use $this->order_by instead
```

### 2. Method Blacklist
Flags obsolete or incorrect method calls:

```php
'method' => [
    'getUserAccount' => 'Method is obsolete, use getUserTier() or SubscriptionTier::GetUserTier() instead',
]
```

**Example Detection:**
```
🚫 Line   28: $this->getUserAccount() - BLACKLISTED: Method is obsolete, use getUserTier() instead
```

### 3. Static Call Blacklist
Flags obsolete classes or static method patterns:

```php
'static' => [
    'CtldAccount::' => 'CtldAccount class is obsolete, use SubscriptionTier instead',
]
```

**Example Detection:**
```
🚫 Line   25: CtldAccount::getByUserId() - BLACKLISTED: CtldAccount class is obsolete
```

### 4. Code Pattern Blacklist (NEW!)
Scans the entire source code for anti-patterns using **substring matching**. This is extremely powerful as it can catch ANY string pattern, not just function calls:

```php
'code_pattern' => [
    "require_once(PathHelper::getIncludePath('includes/PathHelper.php'))" => 'PathHelper is always loaded - never require it',
    '$_SERVER[\'DOCUMENT_ROOT\']' => 'Never use $_SERVER[\'DOCUMENT_ROOT\'] - use PathHelper::getIncludePath() instead',
    '__DIR__ . \'/../' => 'Avoid __DIR__ navigation - use PathHelper::getIncludePath() for proper path resolution',
    'new Product()' => 'Product constructor requires parameter: new Product(NULL) for new, new Product($id, TRUE) to load',
]
```

**Example Detection:**
```
CODE PATTERN ANALYSIS
Issues found:
  🚫 Line    6: Contains 'require_once(PathHelper::getIncludePath('includ...'
           → PathHelper is always loaded - never require it
  🚫 Line   14: Contains '$_SERVER['DOCUMENT_ROOT']'
           → Never use $_SERVER['DOCUMENT_ROOT'] - use PathHelper::getIncludePath() instead
  🚫 Line   20: Contains 'new Product()'
           → Product constructor requires parameter: new Product(NULL) for new, new Product($id, TRUE) to load

🚫 Total pattern violations: 3
```

## How It Works

1. **Property Access Detection**: Tracks `$var->property` patterns (not followed by `()`)
2. **Pattern Matching**: Uses `strpos()` to match blacklist patterns flexibly
3. **Partial Matching**: Can match either full patterns or prefixes (e.g., `CtldAccount::` matches any method)
4. **Clear Messages**: Provides helpful replacement suggestions

## Adding to the Blacklist

Edit `/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php`:

```php
private $blacklist = [
    'property' => [
        '$this->wrong_property' => 'Explanation and correct usage',
    ],
    'method' => [
        'obsoleteMethod' => 'Explanation and replacement',
    ],
    'static' => [
        'ObsoleteClass::' => 'Explanation and replacement',
        'SomeClass::specificMethod' => 'Specific method deprecation',
    ],
];
```

## Usage

```bash
php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /path/to/file.php
```

## Real-World Example

The blacklist feature would have caught the `$this->sorts` bug in `subscription_tiers_class.php`:

**Bug Code:**
```php
if (!empty($this->sorts)) {  // WRONG: Property doesn't exist
    $sorts = $this->sorts;
}
```

**Detection Output:**
```
PROPERTY ACCESSES (2 total)
Issues found:
  🚫 Line   11: $this->sorts - BLACKLISTED: Use $this->order_by instead (SystemMultiBase stores order in $order_by property)

✓ Safe: 0  🚫 Blacklisted: 2
```

## Benefits

1. **Proactive Error Prevention**: Catches mistakes before deployment
2. **Migration Support**: Helps identify obsolete code during refactoring
3. **Team Knowledge Transfer**: Documents deprecated patterns in code
4. **Zero False Positives**: Only flags explicitly blacklisted patterns
5. **Helpful Guidance**: Suggests correct replacements

## Current Blacklist Entries

### Property Access
- `$this->sorts` → Should use `$this->order_by` in SystemMultiBase classes

### Methods
- `getUserAccount()` → Obsolete, use `getUserTier()` or `SubscriptionTier::GetUserTier()`

### Static Calls
- `CtldAccount::*` → Obsolete class, use `SubscriptionTier` instead

### Code Patterns
**Core Files (always loaded, never require):**
- `require_once(PathHelper::getIncludePath('includes/PathHelper.php'))`
- `require_once(PathHelper::getIncludePath('includes/Globalvars.php'))`
- `require_once(PathHelper::getIncludePath('includes/DbConnector.php'))`
- `require_once(PathHelper::getIncludePath('includes/SessionControl.php'))`
- `require_once(PathHelper::getIncludePath('includes/ThemeHelper.php'))`
- `require_once(PathHelper::getIncludePath('includes/PluginHelper.php'))`

**Path Anti-Patterns:**
- `$_SERVER['DOCUMENT_ROOT']` → Use `PathHelper::getIncludePath()` instead
- `__DIR__ . '/../` → Avoid directory navigation, use `PathHelper::getIncludePath()`

**Empty Constructors:**
- `new Product()` → Requires parameter
- `new User()` → Requires parameter
- `new Order()` → Requires parameter
- `new Event()` → Requires parameter

**Field Specification Anti-Patterns:**
- `'type'=>'serial'` → Use `'type'=>'int8'` with `'serial'=>true` (PostgreSQL serial is pseudo-type)
- `'type' => 'serial'` → Use `'type'=>'int8'` with `'serial'=>true` (PostgreSQL serial is pseudo-type)

## Future Enhancements

Potential additions to the blacklist:
- Deprecated PHP functions (e.g., `mysql_*` functions)
- Security anti-patterns (e.g., `eval()`, `exec()` without validation)
- Common typos in method names
- Framework-specific deprecated methods
