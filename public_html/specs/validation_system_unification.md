# Validation System Unification Specification

**Status:** Ready for Implementation
**Priority:** High
**Impact:** Consolidates 3 validation systems into 1

## Overview

Consolidate the validation framework from 3 separate systems (`field_constraints`, orphaned `'validation'` property, and FormWriter) into a single unified `'validation'` property in `field_specifications`.

## Current State

### What Exists
- **field_constraints**: Callback-based validation (FieldConstraints.php)
  - Functions: NoSymbols, NoCaps, NoWebsite, NoEmailAddress, NoPhoneNumber, WordLength
  - Status: **Completely unused** (all empty/commented out in 66 models)
  - Location: `/includes/FieldConstraints.php`

- **field_specifications properties**: Direct validation rules
  - `'required'` (boolean) - In use
  - `'unique'` (boolean) - In use
  - `'unique_with'` (array) - In use
  - `'validation'` (array) - **Never implemented**, only in users_class.php

- **FormWriter v2**: Client-side validation (new)
  - Reads from model's `'validation'` property
  - Supports: required, email, phone, zip, url, number, minlength, maxlength, pattern, etc.

### The Problem
- `field_constraints` is dead code
- `'validation'` property exists but unused on backend
- No unified source of truth

## Solution - Single Implementation

All steps are executed together in one implementation cycle.

### Step 1: Delete FieldConstraints infrastructure

**Delete:**
1. `/includes/FieldConstraints.php` - entire file (82 lines)
2. `require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));` from 55 data models
3. Field constraints check from SystemBase.php (lines 1001-1017)

**Result:** Remove all field_constraints infrastructure - they're not used anyway

### Step 2: Clean up field_constraints declarations in all models

**Remove from all 66 models:**
```php
public static $field_constraints = array(
    // Empty or commented out declarations
);
```

**Result:** Field definitions are now cleaner - one less property per model

### Step 3: Update field_specifications with 'validation' property

**Consolidate validation into one place:**
```php
// OLD (split across multiple properties):
public static $field_specifications = array(
    'evt_name' => array('type'=>'varchar(255)', 'required'=>true),
);
public static $field_constraints = array(
    'evt_name' => array(array('WordLength', 2, 255)),
);

// NEW (unified):
public static $field_specifications = array(
    'evt_name' => array(
        'type' => 'varchar(255)',
        'validation' => array(
            'required' => true,
            'minlength' => 2,
            'maxlength' => 255,
            'messages' => array(
                'required' => 'Event name is required',
                'minlength' => 'Must be at least 2 characters',
            )
        )
    ),
);
```

**Supported validation rules in 'validation' property:**
- `'required'` (boolean) - Field must not be empty
- `'email'` (boolean) - Must be valid email
- `'url'` (boolean) - Must be valid URL
- `'phone'` (boolean) - Must be valid phone number
- `'number'` (boolean) - Must be numeric
- `'minlength'` (int) - Minimum string length
- `'maxlength'` (int) - Maximum string length
- `'pattern'` (regex string) - Regular expression match
- `'unique'` (boolean) - Value must be unique in table
- `'unique_with'` (array) - Composite unique constraint
- `'messages'` (array) - Custom error messages per rule

### Step 4: Integrate validation into SystemBase.save()

**Update SystemBase.php `save()` method to:**
1. Read validation from field_specifications['validation']
2. Validate each rule before save
3. Throw appropriate exception on validation failure
4. Keep existing `'required'`, `'unique'`, `'unique_with'` functionality working

**No breaking changes** - existing save() behavior is preserved

### Step 5: Verify FormWriter v2 integration

**FormWriter v2 already:**
- Reads 'validation' property from models
- Passes validation rules to client-side JoineryValidator
- Generates JavaScript validation dynamically
- No changes needed - already complete

## Examples

### Before (fragmented)
```php
public static $field_specifications = array(
    'evt_name' => array('type'=>'varchar(255)', 'required'=>true),
);

public static $field_constraints = array(
    'evt_name' => array(
        array('WordLength', 2, 255),
        'NoCaps',
    ),
);
```

### After (unified)
```php
public static $field_specifications = array(
    'evt_name' => array(
        'type' => 'varchar(255)',
        'validation' => array(
            'required' => true,
            'minlength' => 2,
            'maxlength' => 255,
            'messages' => array(
                'required' => 'Event name is required',
                'minlength' => 'Must be at least 2 characters',
            )
        )
    ),
);
```

## Implementation Order

1. Delete FieldConstraints.php and all require statements (55 models)
2. Remove field_constraints check from SystemBase.php (lines 1001-1017)
3. Remove field_constraints declarations from all 66 models
4. Add validation rule support to SystemBase.save() method
5. Test all models still save/validate correctly
6. Verify FormWriter v2 still generates validation correctly

## Benefits

✅ Single source of truth for validation
✅ Validation rules defined once, used everywhere (backend + FormWriter)
✅ Cleaner, more maintainable code
✅ Removes dead code (field_constraints)
✅ Better error handling (ValidationException)
✅ Easier to add new validation types

## Testing Requirements

- [ ] Unit tests for each validation rule in SystemBase.save()
- [ ] All models still validate correctly after migration
- [ ] FormWriter v2 still validates client-side correctly
- [ ] Error messages display properly
- [ ] Unique constraints still work
- [ ] Default values still populate
- [ ] zero_on_create still works

## Detailed Removal Code & Locations

### 1. Delete `/includes/FieldConstraints.php` (entire file)

**File:** `/var/www/html/joinerytest/public_html/includes/FieldConstraints.php`

**Contents to delete (lines 1-82):**
```php
<?php

require_once('SystemBase.php');


class FieldConstraintError extends SystemBaseException implements DisplayableErrorMessage {}

function NoSymbols($field, $value) {
	$allowed_check = '/^[A-Za-z0-9\.\*&,\- @\':\(\)+?!#%]+$/';
	if (!preg_match($allowed_check, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field can only contain letters, numbers and the ' .
			'following characters: . * & , - @ \' ( ) + ? ! # %');
	}

	$double_check = '/[\.\*&,\-@\':]{3}/';
	if (preg_match($double_check, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field cannot contain repeated symbols.');
	}
}

function NoCaps($field, $value) {
	$value = preg_replace('/\s+/', '', $value);
	$lower = strtolower($value);
	$count = 0;
	$value_length = strlen($value);
	for($i=0;$i<$value_length;$i++) {
		if ($value[$i] != $lower[$i]) {
			$count++;
		}
	}

	if (($count * 2) > strlen($value)) {
		throw new FieldConstraintError(
			'Please use fewer capital letters in the ' . $field . ' field.');
	}
}

function NoWebsite($field, $value) {
	$website = '/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i';
	if (preg_match($website, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field contains a website URL.  Please move your ' .
			'website address to the "Business Website" section.');
	}
}

function NoEmailAddress($field, $value) {
	$email = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}/i';
	if (preg_match($email, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field contains an email address.  For your privacy ' .
			'please do not include this information in this section.  We will automatically send all ' .
			'requests from prospective clients to your email address.');
	}
}

function NoPhoneNumber($field, $value) {
	$phone = '/(1[-\. ]?)?(\([2-9]\d{2}\)|[2-9]\d{2})[-\. ]?[2-9]\d{2}[-\. ]?\d{4}/';

	if (preg_match($phone, $value)) {
		throw new FieldConstraintError(
			'The ' . $field . ' field contains a phone number.  Please move your ' .
			'phone number to the "Phone" section.');
	}
}

function WordLength($field, $value, $min, $max) {
	$len = strlen($value);
	if ($len < $min) {
		throw new FieldConstraintError(
			'Field "' . $field . '" needs to be at least ' . $min . ' characters.');
	}
	if ($len > $max) {
		throw new FieldConstraintError(
			'Field "' . $field . '" needs to be at most ' . $max . ' characters.');
	}
}

?>
```

### 2. Remove FieldConstraints.php require statements from 55 data models

**Affected models:** (55 files in `/data/`)
```
survey_questions_class.php
event_waiting_lists_class.php
email_recipients_class.php
queued_email_class.php
videos_class.php
urls_class.php
visitor_events_class.php
public_menus_class.php
products_class.php
settings_class.php
... and 45 more
```

**Pattern to remove from each file (line varies by file):**
```php
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
```

**Bash command to find exact line numbers:**
```bash
grep -n "FieldConstraints.php" /var/www/html/joinerytest/public_html/data/*.php
```

### 3. Remove field_constraints check from SystemBase.php

**File:** `/var/www/html/joinerytest/public_html/includes/SystemBase.php`

**Lines to delete:** 1001-1017

**Code to remove:**
```php
		//CHECK FIELD CONSTRAINTS
		foreach (static::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = $field;
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				}
				else {
					call_user_func($constraint, $field, $this->get($field));
				}
			}
		}
```

**Result:** Lines will shift up, and unique constraint check (currently line 1019) becomes line 1001

### 4. Remove field_constraints declarations from all models

**Pattern in each model file (all empty/commented out):**

Example from `/data/users_class.php` lines 78-87:
```php
	public static $field_constraints = array(
	/*
		'usr_first_name' => array(
			array('WordLength', 2, 64)
			),
		'usr_last_name' => array(
			array('WordLength', 2, 64)
			),
		*/
	);
```

**Remove these entirely from 66 models** (all models have this declaration)

**Example of clean result:**
Before:
```php
	public static $field_specifications = array(...);

	public static $field_constraints = array(
	/*...commented out...*/
	);

	private static function UcName($string) {
```

After:
```php
	public static $field_specifications = array(...);

	private static function UcName($string) {
```

## Files to Modify

**Delete:**
- `/includes/FieldConstraints.php` (entire file)

**Modify:**
- `/includes/SystemBase.php` - Remove field_constraints check (lines 1001-1017)
- `/data/*_class.php` - Remove require statement (55 files) and field_constraints declaration (66 files)

**No changes needed:**
- FormWriter v2 - Already reads 'validation'
- JoineryValidator - Already validates

## Implementation Checklist

- [ ] Delete `/includes/FieldConstraints.php`
- [ ] Remove FieldConstraints.php require from 55 data models
- [ ] Remove field_constraints check from SystemBase.php lines 1001-1017
- [ ] Remove field_constraints declarations from all 66 models
- [ ] Add validation rule checking to SystemBase.save()
- [ ] Run syntax check on all modified files: `php -l filename.php`
- [ ] Run method existence test on all modified files
- [ ] Test model save() with various validation rules
- [ ] Verify FormWriter v2 client-side validation still works
- [ ] Test all existing unit tests still pass

## Rollback Plan

If critical issues occur:
1. Git revert all changes
2. FieldConstraints are only data files, no business logic
3. Safe to revert without data loss

## Success Criteria

✅ Completed when:
- [ ] FieldConstraints.php deleted
- [ ] All 55 require statements removed from models
- [ ] All 66 field_constraints declarations removed from models
- [ ] SystemBase.php field_constraints check removed (lines 1001-1017)
- [ ] SystemBase.save() reads and validates 'validation' property
- [ ] All models save successfully with and without validation errors
- [ ] FormWriter v2 client-side validation still works
- [ ] No breaking changes - existing save() behavior preserved
- [ ] All PHP syntax checks pass
- [ ] All unit tests pass
