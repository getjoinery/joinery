# Specification: Merge Field Arrays into field_specifications

## Overview
**Phase 1:** Consolidate `$required_fields`, `$zero_variables`, `$initial_default_values`, and `$timestamp_fields` arrays into the existing `$field_specifications` array structure. Additionally, auto-detect timestamp fields based on their field type rather than requiring explicit declaration.

**Phase 2 (Future Enhancement):** Merge `$fields` array into `$field_specifications` to create a single source of truth for all field metadata.

## Goals
1. **Simplify data model definitions** by reducing from 5+ arrays to a single comprehensive array
2. **Improve maintainability** by centralizing field metadata in one location
3. **Reduce redundancy** and potential inconsistencies between related field definitions
4. **Auto-detect timestamp fields** based on field type specifications
5. **Complete immediate replacement** with no transition period

## Current State

### Current Model Definition Structure
```php
class Example extends SystemBase {
    // Multiple arrays for field metadata
    public static $field_specifications = array(
        'ex_name' => array('type'=>'varchar(255)', 'is_nullable'=>false),
        'ex_created' => array('type'=>'timestamp', 'is_nullable'=>false),
        'ex_count' => array('type'=>'integer', 'is_nullable'=>true),
        'ex_active' => array('type'=>'boolean', 'is_nullable'=>false),
    );
    
    public static $required_fields = array('ex_name');
    public static $zero_variables = array('ex_count');
    public static $initial_default_values = array(
        'ex_created' => 'now()',
        'ex_active' => true,
    );
    public static $timestamp_fields = array('ex_created');
}
```

## Proposed State

### New Consolidated field_specifications Structure
```php
class Example extends SystemBase {
    // Complete field metadata: schema properties (type, is_nullable) + runtime behavior (required, default, zero_on_create)
    // Timestamp fields auto-detected from type. Replaces $required_fields, $zero_variables, $initial_default_values, $timestamp_fields
    public static $field_specifications = array(
        'ex_name' => array(
            'type' => 'varchar(255)', 
            'is_nullable' => false,
            'required' => true,  // Runtime: validation during save()
        ),
        'ex_created' => array(
            'type' => 'timestamp',  // Auto-detected as timestamp field for smart_get()
            'is_nullable' => false,
            'default' => 'now()',  // Runtime: applied when creating new records
        ),
        'ex_count' => array(
            'type' => 'integer', 
            'is_nullable' => true,
            'zero_on_create' => true,  // Runtime: set to 0 when creating if NULL
        ),
        'ex_active' => array(
            'type' => 'boolean', 
            'is_nullable' => false,
            'default' => true,  // Runtime: applied when creating new records
        ),
    );
    
    // Legacy arrays completely removed
    // No more: $required_fields, $zero_variables, $initial_default_values, $timestamp_fields
}
```

## New field_specifications Properties

**IMPORTANT:** These are all runtime properties that control data storage and retrieval behavior. They do NOT affect database schema generation, which only uses `'type'` and `'is_nullable'`.

### Required Fields
- **Property:** `'required' => true|false|array`
- **Replaces:** `$required_fields`
- **Runtime behavior:** Validation during `save()` method
- **Schema impact:** None
- **Usage:**
  - `true`: Field must have non-null, non-empty value
  - `false` or omitted: Field is optional
  - `array`: List of alternative fields (at least one must be set)

### Default Values
- **Property:** `'default' => mixed`
- **Replaces:** `$initial_default_values`
- **Runtime behavior:** Applied during `save()` when creating new records
- **Schema impact:** None (database defaults are separate)
- **Usage:**
  - Sets initial value when field is NULL
  - Supports database functions like `'now()'`
  - Only applied to new records (`$this->key === NULL`)

### Zero Variables
- **Property:** `'zero_on_create' => true|false`
- **Replaces:** `$zero_variables`
- **Runtime behavior:** Applied during `save()` when creating new records
- **Schema impact:** None
- **Usage:**
  - Sets field to 0 when creating new records if field is NULL
  - Only applies to numeric fields

### Timestamp Auto-Detection
- **Auto-detected when:** `'type'` contains 'timestamp', 'datetime', or 'date'
- **Replaces:** `$timestamp_fields`
- **Runtime behavior:** Used in `smart_get()` and `export_as_array()`
- **Schema impact:** None (uses existing `'type'` property)
- **Usage:**
  - Automatically returns DateTime objects
  - No explicit declaration needed

## Implementation Changes

### 1. SystemBase.php Changes

#### Before: save() method (lines 910-950)
```php
// SET INITIAL DEFAULT VALUES
foreach (static::$initial_default_values as $key => $value) {
    if ($this->get($key) === NULL) {
        $this->set($key, $value);
    }
}

// SET ZERO VARIABLES
foreach (static::$zero_variables as $variable) {
    if ($this->key === NULL && $this->get($variable) === NULL) {
        $this->set($variable, 0);
    }
}

// CHECK REQUIRED FIELDS
foreach (static::$required_fields as $required_field) {
    if (gettype($required_field) == 'array') {
        $one_true = FALSE;
        foreach($required_field as $element) {
            if ($this->get($element)) {
                $one_true = TRUE;
                break;
            }
        }
        if (!$one_true) {
            throw new SystemClassException('One of ' . implode(', ', $display_names) . ' must be set.');
        }
    } else if (is_null($this->get($required_field)) || $this->get($required_field) === '') {
        throw new SystemClassException('Required field "' . $required_field . '" must be set.');
    }
}
```

#### After: save() method
```php
// Process field specifications for defaults, zeros, and requirements
foreach (static::$field_specifications as $field_name => $spec) {
    // Handle default values (replaces $initial_default_values)
    if (isset($spec['default']) && $this->get($field_name) === NULL) {
        $this->set($field_name, $spec['default']);
    }
    
    // Handle zero_on_create (replaces $zero_variables)
    if (isset($spec['zero_on_create']) && $spec['zero_on_create'] === true) {
        if ($this->key === NULL && $this->get($field_name) === NULL) {
            $this->set($field_name, 0);
        }
    }
    
    // Handle required fields (replaces $required_fields)
    if (isset($spec['required'])) {
        if (is_array($spec['required'])) {
            // Check if at least one of the alternative fields is set
            $one_true = FALSE;
            foreach($spec['required'] as $alt_field) {
                if ($this->get($alt_field)) {
                    $one_true = TRUE;
                    break;
                }
            }
            if (!$one_true) {
                $display_names = array_map(function($f) { return static::$field_specifications[$f]['display_name'] ?? $f; }, $spec['required']);
                throw new SystemClassException('One of ' . implode(', ', $display_names) . ' must be set.');
            }
        } else if ($spec['required'] === true) {
            if (is_null($this->get($field_name)) || $this->get($field_name) === '') {
                $display_name = $spec['display_name'] ?? $field_name;
                throw new SystemClassException('Required field "' . $display_name . '" must be set.');
            }
        }
    }
}
```

#### Before: smart_get() method (lines 217-220)
```php
function smart_get($key) {
    if (in_array($key, static::$timestamp_fields)) {
        return new DateTime($this->get($key));
    }
    return $this->get($key);
}
```

#### After: smart_get() method
```php
function smart_get($key) {
    // Auto-detect timestamp fields from type
    if ($this->is_timestamp_field($key)) {
        $value = $this->get($key);
        if ($value) {
            return new DateTime($value);
        }
    }
    
    return $this->get($key);
}

/**
 * Auto-detect if a field is a timestamp based on its type specification
 */
protected function is_timestamp_field($field_name) {
    if (!isset(static::$field_specifications[$field_name])) {
        return false;
    }
    
    $type = strtolower(static::$field_specifications[$field_name]['type'] ?? '');
    return (strpos($type, 'timestamp') !== false || 
            strpos($type, 'datetime') !== false ||
            (strpos($type, 'date') !== false && strpos($type, 'update') === false));
}
```

#### export_as_array() method changes (lines 475-479)
```php
// Before
foreach(static::$timestamp_fields as $field) {
    if ($this->get($field)) {
        $out_array[$field] = new DateTime($this->get($field));
    }
}

// After
foreach(static::$field_specifications as $field_name => $spec) {
    if ($this->is_timestamp_field($field_name) && $this->get($field_name)) {
        $out_array[$field_name] = new DateTime($this->get($field_name));
    }
}
```

### 2. Data Model Updates

#### Example: users_class.php

**Before:**
```php
public static $field_specifications = array(
    'usr_id' => array('type'=>'bigserial'),
    'usr_first_name' => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'usr_last_name' => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'usr_email' => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'usr_timezone' => array('type'=>'varchar(255)', 'is_nullable'=>false),
    'usr_permission' => array('type'=>'integer', 'is_nullable'=>false),
    'usr_is_activated' => array('type'=>'boolean', 'is_nullable'=>false),
    'usr_is_disabled' => array('type'=>'boolean', 'is_nullable'=>false),
    'usr_email_is_verified' => array('type'=>'boolean', 'is_nullable'=>false),
    'usr_signup_date' => array('type'=>'timestamp', 'is_nullable'=>false),
    'usr_lastlogin_time' => array('type'=>'timestamp', 'is_nullable'=>true),
);

public static $required_fields = array('usr_first_name', 'usr_last_name', 'usr_email', 'usr_timezone');

public static $zero_variables = array('usr_permission');

public static $initial_default_values = array(
    'usr_timezone' => 'America/New_York',
    'usr_is_activated' => FALSE,
    'usr_is_disabled' => FALSE,
    'usr_email_is_verified' => FALSE,
    'usr_signup_date' => 'now()',
    'usr_lastlogin_time' => 'now()',
);

public static $timestamp_fields = array('usr_email_is_verified_time', 'usr_lastlogin_time', 'usr_admin_disabled_time', 'usr_signup_date');
```

**After:**
```php
public static $field_specifications = array(
    'usr_id' => array('type'=>'bigserial'),
    'usr_first_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
    'usr_last_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
    'usr_email' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true),
    'usr_timezone' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'required'=>true, 'default'=>'America/New_York'),
    'usr_permission' => array('type'=>'integer', 'is_nullable'=>false, 'zero_on_create'=>true),
    'usr_is_activated' => array('type'=>'boolean', 'is_nullable'=>false, 'default'=>false),
    'usr_is_disabled' => array('type'=>'boolean', 'is_nullable'=>false, 'default'=>false),
    'usr_email_is_verified' => array('type'=>'boolean', 'is_nullable'=>false, 'default'=>false),
    'usr_signup_date' => array('type'=>'timestamp', 'is_nullable'=>false, 'default'=>'now()'),  // Auto-detected as timestamp
    'usr_lastlogin_time' => array('type'=>'timestamp', 'is_nullable'=>true, 'default'=>'now()'), // Auto-detected as timestamp
);

// Legacy arrays removed - no longer exist in class definition
```


### 3. Database Update System

**Location:** `/includes/update_database.php` or similar

**Changes needed:**
- Ensure database schema generation continues to work with enhanced field_specifications
- No changes to actual schema generation logic needed (only reads type and is_nullable)

## Files Requiring Changes

### Core System Files
1. **`/includes/SystemBase.php`**
   - Update save() method (lines ~910-950)
   - Update smart_get() method (lines ~217-220)
   - Update export_as_array() method (lines ~475-479)
   - Add is_timestamp_field() helper method
   - Remove all references to $required_fields, $zero_variables, $initial_default_values, $timestamp_fields

2. **`/includes/SystemClass.php`** (if separate from SystemBase)
   - Remove static property declarations for legacy arrays

### Data Model Files (All files in /data/)
- activation_codes_class.php
- comments_class.php
- event_registrants_class.php
- events_class.php
- plugins_class.php
- posts_class.php
- products_class.php
- settings_class.php
- themes_class.php
- users_class.php
- Any other *_class.php files

### Plugin Data Models
- `/plugins/*/data/*_class.php` - All plugin model files

## Implementation Steps

### 1. Update SystemBase.php
- Modify save() method to process new field_specifications properties
- Update smart_get() and export_as_array() to auto-detect timestamp fields
- Remove all references to legacy arrays

### 2. Update All Data Models
- Merge existing arrays into field_specifications
- Remove legacy array declarations
- Test each model for correct behavior

### 3. Verify Related Systems
- Check for any other systems that might reference the legacy arrays
- Update if necessary
- **Note:** Database schema generation (`update_database`) is unaffected since it only uses `'type'` and `'is_nullable'`

## Benefits

1. **Reduced Complexity:** Single source of truth for field metadata
2. **Better IDE Support:** All field information in one place
3. **Fewer Errors:** No need to keep multiple arrays in sync
4. **Automatic Type Detection:** No manual timestamp field declaration
5. **More Intuitive:** Field properties are directly associated with field definitions
6. **Extensible:** Easy to add new field properties in the future

## Risks and Considerations

1. **Risk:** Breaking existing functionality
   - **Solution:** Thorough testing of all models after conversion

2. **Risk:** Performance impact from auto-detection
   - **Solution:** Simple string matching is fast enough for production use

3. **Risk:** Third-party plugin compatibility
   - **Solution:** Update all plugins as part of the implementation

## Testing Requirements

1. **Unit Tests:** Test new field_specifications format in SystemBase
2. **Integration Tests:** Verify save(), load(), prepare() methods
3. **Model Tests:** Test each converted model thoroughly
4. **Performance Tests:** Ensure no significant performance degradation

## Success Criteria

1. All models successfully converted to new structure
2. All existing functionality continues to work
3. No performance degradation in common operations
4. Form generation and validation work correctly with new structure
5. Database update system continues to function properly

## Phase 2: Merge $fields Array (Future Enhancement)

### Current Problem: Dual Field Definitions

Currently, every model maintains two arrays with the same field names:

```php
// Application-focused: field validation, serialization, display
public static $fields = array(
    'usr_user_id' => 'Primary key - User ID',
    'usr_email' => 'Email Address',
    'usr_password' => 'Password hash',
);

// Database-focused: schema generation, type detection  
public static $field_specifications = array(
    'usr_user_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
    'usr_email' => array('type'=>'varchar(64)'),
    'usr_password' => array('type'=>'varchar(255)'),
);
```

**Problems:**
- **Duplicate maintenance** - same field names in two places
- **Sync issues** - easy to add fields to one array but forget the other
- **Redundant definitions** - violates DRY principle

### Proposed Solution: Unified Field Definitions

```php
// Single comprehensive array for ALL field metadata
public static $field_specifications = array(
    'usr_user_id' => array(
        'type' => 'int8', 
        'serial' => true, 
        'is_nullable' => false,
        'description' => 'Primary key - User ID',  // From $fields value
        'required' => false,  // From Phase 1 
        'default' => null,
    ),
    'usr_email' => array(
        'type' => 'varchar(64)',
        'description' => 'Email Address',  // From $fields value
        'required' => true,  // From Phase 1
    ),
    'usr_password' => array(
        'type' => 'varchar(255)',
        'description' => 'Password hash',  // From $fields value
        'required' => true,  // From Phase 1
    ),
);

// $fields array completely eliminated - descriptions preserved in 'description' property
```

### Impact Analysis

**SystemBase Changes Required:**
- Update `set()` method: `array_key_exists($key, static::$field_specifications)` instead of `static::$fields`
- Update `export_as_array()`: `array_keys(static::$field_specifications)` instead of `static::$fields`
- Update `load_from_data()`: iterate over `static::$field_specifications` keys
- Update `set_all_to_null()`, `soft_delete()`, `undelete()`: use `static::$field_specifications`
- Add helper method: `static function get_field_names()` returning `array_keys(static::$field_specifications)`

**SystemMultiBase Changes Required:**
- Update collection loading logic to use `static::$field_specifications` keys
- Modify bulk operations to reference unified array

**Model File Changes:**
- ~60+ model files need `$fields` array removed
- Add `'description'` property to each field in `$field_specifications` (preserving the descriptive text from `$fields`)
- Ensure all field names exist in both current arrays (validation step)

**Benefits of Phase 2:**
1. **Single source of truth** - eliminates duplicate field definitions
2. **Reduced errors** - impossible to have mismatched field lists
3. **Enhanced metadata** - can add validation rules, form hints, display formatting, etc.
4. **Cleaner model classes** - one comprehensive array instead of multiple
5. **Future extensibility** - easy to add new field properties

**Implementation Complexity:**
- **High complexity** - touches core system functionality extensively  
- **High risk** - affects field validation, serialization, database loading
- **Large scope** - 60+ model files plus core system changes
- **Extensive testing required** - every model operation needs verification

**Recommendation:**
- Complete Phase 1 first and validate thoroughly
- Plan Phase 2 as separate project with dedicated testing phase
- Consider Phase 2 essential for long-term maintainability