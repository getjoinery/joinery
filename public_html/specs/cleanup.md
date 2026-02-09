# Cleanup: Pre-existing Pattern Violations

## Overview
These are pre-existing style/pattern violations flagged by the PHP file validator. They are not functional bugs but should be cleaned up for consistency with project conventions.

## Items

### 1. Remove unnecessary SessionControl require
- **File:** `data/events_class.php`
- **File:** `logic/events_logic.php`
- **Issue:** Both files have `require_once` for SessionControl, which is always pre-loaded by the framework.
- **Fix:** Remove the `require_once` lines for SessionControl.

### 2. Replace `__DIR__` navigation with PathHelper
- **File:** `data/events_class.php` (line 2)
- **File:** `logic/events_logic.php` (line 2)
- **Issue:** Uses `require_once(__DIR__ . '/../includes/PathHelper.php')` instead of the project convention. PathHelper is always available and never needs to be required.
- **Fix:** Remove the `require_once(__DIR__ . '/../includes/PathHelper.php')` line entirely.

### 3. Replace ctrlHolder with FormWriter V2 pattern
- **File:** `data/events_class.php` (line 445)
- **Issue:** Uses `ctrlHolder`, a FormWriter V1 class. FormWriter V2 applies Bootstrap classes automatically.
- **Fix:** Remove the `ctrlHolder` reference and use the V2 pattern instead.

## Checklist
- [ ] Remove SessionControl require from `data/events_class.php`
- [ ] Remove SessionControl require from `logic/events_logic.php`
- [ ] Remove `__DIR__` PathHelper require from `data/events_class.php`
- [ ] Remove `__DIR__` PathHelper require from `logic/events_logic.php`
- [ ] Replace ctrlHolder usage in `data/events_class.php` line 445
