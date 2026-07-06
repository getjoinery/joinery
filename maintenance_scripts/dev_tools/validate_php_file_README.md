# PHP File Validator

## Overview

The PHP file validator (`validate_php_file.php`) analyzes a PHP file and reports:

1. **Data model contract** — structural validation of `SystemBase` data classes (hard errors)
2. **Blacklist findings** — known incorrect function/method/property usage patterns
3. **Method existence** — calls to functions/methods that don't exist
4. **Style policy & descriptor contract** — advisories

## Exit Status

The exit code is `1` when the file has any confirmed error: a **data model contract** violation, a **logic file contract** violation, or a **missing call** (nonexistent function, method, class, or a constructor called without its required arguments). This makes the validator usable as a hard gate for any file type. Blacklist findings and ⚠️ advisories are reported in the output but do not change the exit status. Calls the analyzer cannot verify are counted as *unknown* and never fail the run — only provable errors gate.

## Logic File Contract

When the validated file lives in a `logic/` directory and is named `*_logic.php`, it is checked against the logic-layer contract (`docs/logic_architecture.md` is the reference).

**Errors (exit code 1):**

- A core `logic/` file must define its entry function, named exactly after the file basename (`profile_logic.php` → `profile_logic()`). Plugin logic functions carry the plugin name in the route-derived position (`plugins/joinery_ai/logic/profile_chat_logic.php` → `profile_joinery_ai_chat_logic()`); a plugin file with no entry function gets an advisory instead, since shared-helper logic files are legitimate.
- The entry function takes a single required parameter (`$input`).
- `exit()` / `die()` are forbidden anywhere in a logic file — every code path returns a `LogicResult`.

**Advisories:**

- `throw` outside any lexical `try` block (uncontained throws should become `LogicResult::error()`; locally caught throws are accepted practice)
- Entry function missing the `array` parameter type or the `: LogicResult` return type

## Analysis Environment

The validator verifies calls against the real codebase: it loads all core and plugin data models, autoloads core classes from `includes/` (including subdirectories) and plugin `includes/` directories on demand, and loads the composer vendor autoloader. Functions defined in sibling `logic/` files that the router loads at runtime are recognized through a side-effect-free definition index. Calls whose target class cannot be identified (e.g. `$var::method()`, or an object whose type was never established in the file) are counted as *unknown*, not reported as errors — only provable mismatches produce ✗ lines.

Method calls on objects whose class was established in the file (assignment, factory return, or `foreach` over a Multi collection) are verified with `method_exists` directly — the per-class whitelist applies only to classes the validator cannot load, and a method documented for one class never excuses a call on another.

## Data Model Contract

When the validated file declares one or more `SystemBase` subclasses, each is checked via reflection against the platform model contract (`docs/example_class.php` is the annotated reference).

**Errors (exit code 1):**

- `$prefix` declared and exactly 3 lowercase letters
- `$tablename` declared, lowercase snake_case, starting with `{prefix}_`
- `$pkey_column` declared, starting with `{prefix}_`, present in `$field_specifications`, and flagged `'serial' => true` (int8 + serial is the platform primary-key convention)
- `$field_specifications` declared and non-empty
- Every column key lowercase snake_case and starting with `{prefix}_`
- Every column spec declares a `'type'` accepted by `DatabaseUpdater::acceptedColumnTypeRegex()` (the single authority shared with `update_database` and the scaffold generator)
- A `SystemMultiBase` collection class in the same file with `$model_class` pointing back at the model and a `getMultiResults()` implementation

**Advisories (reported, exit code unaffected):**

- `$permanent_delete_actions` not declared on the class itself (see `docs/deletion_system.md`)
- Collection class not named `Multi{Model}`
- `$prefix` shared with another loaded model class — new models should pick a unique prefix
- A `$foreign_key_actions` key that doesn't resolve to a known source table by naming convention and doesn't declare an explicit `source_table`/`source_class` override — the rule will not register (see `docs/deletion_system.md`)

**Example Detection:**

```
DATA MODEL CONTRACT
--------------------------------------------------------------------------------
Errors:
  ✗ CalEntryException: $tablename 'cal_entry_exceptions' must start with 'cex_' (the class prefix)

✗ Contract errors: 1  ⚠️  Advisories: 0
```

Files that declare no `SystemBase` subclass skip this section entirely.

## Blacklist Feature

The validator includes a **blacklist** feature to flag known incorrect function/method/property usage patterns. This helps catch common mistakes before they cause runtime errors.

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
    'Pager::get_param' => 'Method does not exist - use Pager::current_page() instead',
]
```

`Class::method` entries match exactly; an entry ending in `::` covers every method of that class. These entries also apply to *instance* calls when the object's class is known (e.g. `$pager->get_param()` is flagged via `Pager::get_param`). Bare method-name entries match exactly, so `start_buttons` cannot flag an innocent `restart_buttons()`.

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

🚫 Total pattern violations: 2
```

### 5. Constructor Argument Check
Every `new X()` call with zero arguments is verified via reflection: when `X::__construct` requires parameters, the call is flagged. This covers every `SystemBase` model automatically (their constructors require `$key`) — no per-class pattern list.

**Example Detection:**
```
CONSTRUCTOR CALLS (3 total)
Issues found:
  ✗ Line   14: new Product() requires 1 argument(s) - use new Product(NULL) for new, new Product($id, TRUE) to load
```

## How It Works

1. **Property Access Detection**: Tracks `$var->property` patterns (not followed by `()`)
2. **Exact Matching**: Method and static entries match exactly; only entries ending in `::` act as class-wide prefixes
3. **Code Patterns**: The `code_pattern` list is the one substring-matched category — it scans raw source lines
4. **Clear Messages**: Provides helpful replacement suggestions

## Adding to the Blacklist

Edit `maintenance_scripts/dev_tools/validate_php_file.php`:

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
php maintenance_scripts/dev_tools/validate_php_file.php /path/to/file.php
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
- `get_formwriter_object()` → Removed, use `$page->getFormWriter()`
- `start_buttons()` / `end_buttons()` / `new_form_button()` → Not in FormWriter V2, use `submitbutton()`

### Static Calls (also matched on instance calls when the class is known)
- `CtldAccount::*` → Obsolete class, use `SubscriptionTier` instead
- `LogicResult::data` → Use `LogicResult::render()`
- `Pager::get_param_string` / `Pager::get_param` / `Pager::get_limit` → Use `get_url()` / `current_page()` / `num_per_page()`

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

**Empty Constructors:** handled by the reflection-based Constructor Argument Check (see above) — every class with required constructor parameters is covered, no list to maintain.

**Field Specification Anti-Patterns:**
- `'type'=>'serial'` → Use `'type'=>'int8'` with `'serial'=>true` (PostgreSQL serial is pseudo-type)
- `'type' => 'serial'` → Use `'type'=>'int8'` with `'serial'=>true` (PostgreSQL serial is pseudo-type)

## Future Enhancements

Potential additions to the blacklist:
- Deprecated PHP functions (e.g., `mysql_*` functions)
- Security anti-patterns (e.g., `eval()`, `exec()` without validation)
- Common typos in method names
- Framework-specific deprecated methods
