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
- **Property:** `'required' => true|false` (Phase 1 - no array support)
- **Replaces:** `$required_fields` array elements
- **Runtime behavior:** Validation during `save()` method (exact current behavior)
- **Schema impact:** None
- **Usage:**
  - `true`: Field must have non-null, non-empty string value (checks `is_null() || === ''`)
  - `false` or omitted: Field is optional
  - Note: Array support (alternative fields) exists in current code but not migrated in Phase 1

### Default Values
- **Property:** `'default' => mixed`
- **Replaces:** `$initial_default_values`
- **Runtime behavior:** Applied during `save()` when creating new records (INSERT only)
- **Schema impact:** None (database defaults are separate)
- **Usage:**
  - Sets initial value when `get($field)` returns NULL (maintains current behavior)
  - Note: Due to PHP's isset() behavior, this includes both unset fields AND fields explicitly set to NULL
  - Database functions like `'now()'`, `'uuid_generate_v4()'` are passed as-is to the database
  - Only applied to new records (`$this->key === NULL`)
  - Applied BEFORE zero_on_create
  - Works with all field types including JSON

### Zero Variables
- **Property:** `'zero_on_create' => true|false`
- **Replaces:** `$zero_variables`
- **Runtime behavior:** Applied during `save()` when creating new records (INSERT only)
- **Schema impact:** None
- **Usage:**
  - Sets field to 0 when creating new records if field is NULL
  - Applied AFTER defaults (maintains current order)
  - Both `default` and `zero_on_create` CAN coexist (current behavior allows this)
  - No type validation - passes through to database (maintains current behavior)

### Timestamp Auto-Detection
- **Auto-detected when:** `'type'` contains 'timestamp', 'datetime', or 'date'
- **Replaces:** `$timestamp_fields`
- **Runtime behavior:** Used in `smart_get()` and `export_as_array()`
- **Schema impact:** None (uses existing `'type'` property)
- **Usage:**
  - Automatically returns DateTime objects
  - No explicit declaration needed

## Implementation Clarifications

### Key Design Decisions - NO BEHAVIOR CHANGES

This is a pure refactoring - we are ONLY changing where the metadata is stored, not how it works.

1. **Operation Order:** Defaults → zero_on_create → required validation (EXACT current order)
2. **No Backwards Compatibility:** Clean break, all models migrated at once
3. **Required Fields:** Phase 1 only migrates simple required fields (not array alternatives)
4. **Empty Values:** EXACT current behavior - `is_null() || === ''` for required validation
5. **SQL Functions:** EXACT current behavior - passed as-is to database
6. **Type Checking:** EXACT current behavior - no validation, database handles it
7. **Default + Zero:** EXACT current behavior - both can coexist, zero can override default
8. **INSERT vs UPDATE:** EXACT current behavior - defaults/zeros only on INSERT
9. **Error Messages:** EXACT current format - field name in quotes
10. **NULL Handling:** EXACT current behavior - defaults apply when `get()` returns NULL

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
            throw new SystemBaseException('One of ' . implode(', ', $display_names) . ' must be set.');
        }
    } else if (is_null($this->get($required_field)) || $this->get($required_field) === '') {
        throw new SystemBaseException('Required field "' . $required_field . '" must be set.');
    }
}
```

#### After: save() method
```php
// EXACT SAME BEHAVIOR AS CURRENT - just reading from field_specifications instead of separate arrays

if ($this->key === NULL) {
    // SET INITIAL DEFAULT VALUES (exact current logic)
    foreach (static::$field_specifications as $field_name => $spec) {
        if (isset($spec['default'])) {
            if ($this->get($field_name) === NULL) {
                $this->set($field_name, $spec['default']);
            }
        }
    }
    
    // SET ZERO VARIABLES (exact current logic)
    foreach (static::$field_specifications as $field_name => $spec) {
        if (isset($spec['zero_on_create']) && $spec['zero_on_create'] === true) {
            if ($this->key === NULL && $this->get($field_name) === NULL) {
                $this->set($field_name, 0);
            }
        }
    }
}

// CHECK REQUIRED FIELDS (exact current logic, minus array support for Phase 1)
foreach (static::$field_specifications as $field_name => $spec) {
    if (isset($spec['required']) && $spec['required'] === true) {
        if (is_null($this->get($field_name)) || $this->get($field_name) === '') {
            throw new SystemBaseException('Required field "' . $field_name . '" must be set.');
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
 * Optimized for performance with quick rejection of non-timestamp types
 */
protected function is_timestamp_field($field_name) {
    if (!isset(static::$field_specifications[$field_name])) {
        return false;
    }
    
    $type = strtolower(static::$field_specifications[$field_name]['type'] ?? '');
    
    // Quick rejection: if type starts with clearly non-timestamp types, return false immediately
    $first_char = $type[0] ?? '';
    if ($first_char === 'v' || $first_char === 'i' || $first_char === 'b' || 
        $first_char === 'n' || $first_char === 'f' || $first_char === 'c') {
        return false; // varchar, int*, bool*, numeric, float, char
    }
    
    // Additional optimization: check first two characters for "te" (text fields)
    if ($first_char === 't' && isset($type[1]) && $type[1] === 'e') {
        return false; // text, textarea - definitely not timestamps
    }
    
    // Final switch statement for complete type matching (no strpos() calls needed)
    switch ($type) {
        // Standard timestamp variants
        case 'timestamp':
        case 'timestamptz':
        case 'timestamp with time zone':
        case 'timestamp without time zone':
        
        // Timestamp with precision (0-6 fractional seconds)
        case 'timestamp(0)':
        case 'timestamp(1)':
        case 'timestamp(2)':
        case 'timestamp(3)':
        case 'timestamp(4)':
        case 'timestamp(5)':
        case 'timestamp(6)':
        
        // Timestamp with time zone and precision
        case 'timestamptz(0)':
        case 'timestamptz(1)':
        case 'timestamptz(2)':
        case 'timestamptz(3)':
        case 'timestamptz(4)':
        case 'timestamptz(5)':
        case 'timestamptz(6)':
        
        // Other date/time types
        case 'datetime':
        case 'date':
        case 'time':
        case 'time(0)':
        case 'time(1)':
        case 'time(2)':
        case 'time(3)':
        case 'time(4)':
        case 'time(5)':
        case 'time(6)':
            return true;
            
        default:
            // Fallback: check if type contains timestamp-related keywords (handles edge cases)
            if (strpos(strtolower($type), 'timestamp') !== false || 
                strpos(strtolower($type), 'datetime') !== false || 
                strpos(strtolower($type), 'date') === 0 || 
                strpos(strtolower($type), 'time') === 0) {
                return true;
            }
            return false;
    }
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
1. **`/includes/SystemBase.php`** or **`/includes/SystemBase.php`**
   - Update save() method to read from field_specifications
   - Update smart_get() method to use is_timestamp_field()
   - Update export_as_array() to use is_timestamp_field()
   - Add is_timestamp_field() helper method
   - Remove ALL references to legacy arrays

### Test Framework Files (Must Update)
2. **`/tests/models/ModelTester.php`**
   - Line 323, 587, 644, 938, 1156, 1370: References to `::$required_fields`
   - Update to read from field_specifications['field']['required']

3. **`/tests/models/MultiModelTester.php`**
   - Line 415: Reference to `::$required_fields`
   - Update to read from field_specifications

### Other Files with Legacy Array References
4. **`/logic/register_logic.php`**
   - Check for any references to legacy arrays
   
5. **`/data/products_class.php`**
   - Line 1119: Custom iteration over $required_fields (may need special handling)

### Data Model Files (67+ files in /data/)
All files must have their legacy arrays merged into field_specifications:
- **Required fields:** 74 files use `$required_fields`
- **Zero variables:** 69 files use `$zero_variables`  
- **Initial defaults:** 71 files use `$initial_default_values`
- **Timestamp fields:** 8 files use `$timestamp_fields`

Examples: activation_codes_class.php, comments_class.php, event_registrants_class.php, events_class.php, plugins_class.php, posts_class.php, products_class.php, settings_class.php, themes_class.php, users_class.php, and 57+ others

### Plugin Data Models
- `/plugins/*/data/*_class.php` - All plugin model files must be migrated

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
   - **Note:** No backwards compatibility - hard cutover, all models must be migrated at once

2. **Risk:** Performance impact from auto-detection
   - **Solution:** Simple string matching is fast enough for production use

3. **Risk:** Third-party plugin compatibility
   - **Solution:** Update all plugins as part of the implementation

4. **Risk:** Conflicting field properties
   - **Note:** Both `default` and `zero_on_create` can coexist (maintains current behavior)
   - **Execution order:** Defaults applied first, then zero_on_create can override

## Testing Requirements

1. **Unit Tests:** Test new field_specifications format in SystemBase
2. **Integration Tests:** Verify save(), load(), prepare() methods
3. **Model Tests:** Test each converted model thoroughly
4. **Performance Tests:** Ensure no significant performance degradation
5. **Test Framework Updates:** Update the model testing framework as shown below

### Test Framework Changes Required

#### Location: `/utils/test_model.php` or similar test utilities

**Before: Test Model Generation**
```php
// Old test model structure with separate arrays
class TestModel extends SystemBase {
    public static $prefix = 'tst';
    public static $tablename = 'tst_test_model';
    public static $pkey_column = 'tst_id';
    
    public static $field_specifications = array(
        'tst_id' => array('type'=>'bigserial'),
        'tst_name' => array('type'=>'varchar(255)', 'is_nullable'=>false),
        'tst_created' => array('type'=>'timestamp', 'is_nullable'=>false),
        'tst_count' => array('type'=>'integer', 'is_nullable'=>true),
        'tst_active' => array('type'=>'boolean', 'is_nullable'=>false),
    );
    
    public static $required_fields = array('tst_name');
    public static $zero_variables = array('tst_count');
    public static $initial_default_values = array(
        'tst_created' => 'now()',
        'tst_active' => true,
    );
    public static $timestamp_fields = array('tst_created');
}

// Test validation functions
function validate_required_fields($model) {
    foreach ($model::$required_fields as $field) {
        if (!$model->get($field)) {
            return false;
        }
    }
    return true;
}

function check_zero_variables($model) {
    foreach ($model::$zero_variables as $field) {
        if ($model->get($field) !== 0) {
            return false;
        }
    }
    return true;
}

function verify_defaults($model) {
    foreach ($model::$initial_default_values as $field => $default) {
        // Check if default was applied
    }
}

function is_timestamp_field($model, $field) {
    return in_array($field, $model::$timestamp_fields);
}
```

**After: Updated Test Model Generation**
```php
// New test model structure with consolidated field_specifications
class TestModel extends SystemBase {
    public static $prefix = 'tst';
    public static $tablename = 'tst_test_model';
    public static $pkey_column = 'tst_id';
    
    public static $field_specifications = array(
        'tst_id' => array('type'=>'bigserial'),
        'tst_name' => array(
            'type'=>'varchar(255)', 
            'is_nullable'=>false,
            'required'=>true
        ),
        'tst_created' => array(
            'type'=>'timestamp',  // Auto-detected as timestamp
            'is_nullable'=>false,
            'default'=>'now()'
        ),
        'tst_count' => array(
            'type'=>'integer', 
            'is_nullable'=>true,
            'zero_on_create'=>true
        ),
        'tst_active' => array(
            'type'=>'boolean', 
            'is_nullable'=>false,
            'default'=>true
        ),
    );
    
    // No more separate arrays - all merged into field_specifications
}

// Updated test validation functions
function validate_required_fields($model) {
    foreach ($model::$field_specifications as $field_name => $spec) {
        if (isset($spec['required']) && $spec['required'] === true) {
            if (!$model->get($field_name)) {
                return false;
            }
        }
    }
    return true;
}

function check_zero_variables($model) {
    foreach ($model::$field_specifications as $field_name => $spec) {
        if (isset($spec['zero_on_create']) && $spec['zero_on_create'] === true) {
            if ($model->get($field_name) !== 0) {
                return false;
            }
        }
    }
    return true;
}

function verify_defaults($model) {
    foreach ($model::$field_specifications as $field_name => $spec) {
        if (isset($spec['default'])) {
            // Check if default was applied
            $value = $model->get($field_name);
            if ($spec['default'] === 'now()') {
                // Check for valid timestamp
                if (!$value || strtotime($value) === false) {
                    return false;
                }
            } else if ($value !== $spec['default']) {
                return false;
            }
        }
    }
    return true;
}

function is_timestamp_field($model, $field) {
    // Use the new auto-detection method
    return $model->is_timestamp_field($field);
}
```

#### Test Case Updates

**Before: Legacy Array Tests**
```php
class ModelFieldTest extends PHPUnit\Framework\TestCase {
    
    public function testRequiredFields() {
        $model = new TestModel(NULL);
        
        // Test that required fields from $required_fields array are enforced
        $this->assertContains('tst_name', TestModel::$required_fields);
        
        try {
            $model->save();
            $this->fail('Should have thrown exception for missing required field');
        } catch (SystemBaseException $e) {
            $this->assertStringContainsString('Required field', $e->getMessage());
        }
    }
    
    public function testZeroVariables() {
        $model = new TestModel(NULL);
        
        // Verify field is in zero_variables array
        $this->assertContains('tst_count', TestModel::$zero_variables);
        
        $model->save();
        $this->assertEquals(0, $model->get('tst_count'));
    }
    
    public function testInitialDefaults() {
        $model = new TestModel(NULL);
        
        // Check initial_default_values array
        $this->assertArrayHasKey('tst_active', TestModel::$initial_default_values);
        $this->assertTrue(TestModel::$initial_default_values['tst_active']);
        
        $model->save();
        $this->assertTrue($model->get('tst_active'));
    }
    
    public function testTimestampFields() {
        $model = new TestModel(NULL);
        
        // Verify field is in timestamp_fields array
        $this->assertContains('tst_created', TestModel::$timestamp_fields);
        
        $model->set('tst_created', '2024-01-01 12:00:00');
        $result = $model->smart_get('tst_created');
        $this->assertInstanceOf(DateTime::class, $result);
    }
}
```

**After: Consolidated field_specifications Tests**
```php
class ModelFieldTest extends PHPUnit\Framework\TestCase {
    
    public function testRequiredFields() {
        $model = new TestModel(NULL);
        
        // Test that required property in field_specifications is enforced
        $this->assertTrue(TestModel::$field_specifications['tst_name']['required']);
        
        try {
            $model->save();
            $this->fail('Should have thrown exception for missing required field');
        } catch (SystemBaseException $e) {
            $this->assertStringContainsString('Required field', $e->getMessage());
        }
    }
    
    public function testZeroVariables() {
        $model = new TestModel(NULL);
        
        // Verify zero_on_create property
        $this->assertTrue(TestModel::$field_specifications['tst_count']['zero_on_create']);
        
        $model->save();
        $this->assertEquals(0, $model->get('tst_count'));
    }
    
    public function testInitialDefaults() {
        $model = new TestModel(NULL);
        
        // Check default property in field_specifications
        $this->assertArrayHasKey('default', TestModel::$field_specifications['tst_active']);
        $this->assertTrue(TestModel::$field_specifications['tst_active']['default']);
        
        $model->save();
        $this->assertTrue($model->get('tst_active'));
    }
    
    public function testTimestampAutoDetection() {
        $model = new TestModel(NULL);
        
        // Test auto-detection based on type
        $this->assertEquals('timestamp', TestModel::$field_specifications['tst_created']['type']);
        
        // Verify is_timestamp_field() method works
        $this->assertTrue($model->is_timestamp_field('tst_created'));
        $this->assertFalse($model->is_timestamp_field('tst_name'));
        
        $model->set('tst_created', '2024-01-01 12:00:00');
        $result = $model->smart_get('tst_created');
        $this->assertInstanceOf(DateTime::class, $result);
    }
    
    public function testLegacyArraysRemoved() {
        // Ensure legacy arrays no longer exist
        $this->assertFalse(property_exists('TestModel', 'required_fields'));
        $this->assertFalse(property_exists('TestModel', 'zero_variables'));
        $this->assertFalse(property_exists('TestModel', 'initial_default_values'));
        $this->assertFalse(property_exists('TestModel', 'timestamp_fields'));
    }
}
```

#### Mock Model Generator Updates

**Before: Generate test models with legacy arrays**
```php
function generate_test_model($fields) {
    $code = "class GeneratedModel extends SystemBase {\n";
    $code .= "    public static \$field_specifications = array(\n";
    
    $required = array();
    $zeros = array();
    $defaults = array();
    $timestamps = array();
    
    foreach ($fields as $name => $config) {
        $code .= "        '$name' => array('type'=>'{$config['type']}'";
        if (isset($config['is_nullable'])) {
            $code .= ", 'is_nullable'=>" . ($config['is_nullable'] ? 'true' : 'false');
        }
        $code .= "),\n";
        
        if (!empty($config['required'])) $required[] = $name;
        if (!empty($config['zero'])) $zeros[] = $name;
        if (isset($config['default'])) $defaults[$name] = $config['default'];
        if (strpos($config['type'], 'timestamp') !== false) $timestamps[] = $name;
    }
    
    $code .= "    );\n";
    $code .= "    public static \$required_fields = " . var_export($required, true) . ";\n";
    $code .= "    public static \$zero_variables = " . var_export($zeros, true) . ";\n";
    $code .= "    public static \$initial_default_values = " . var_export($defaults, true) . ";\n";
    $code .= "    public static \$timestamp_fields = " . var_export($timestamps, true) . ";\n";
    $code .= "}\n";
    
    return $code;
}
```

**After: Generate test models with consolidated structure**
```php
function generate_test_model($fields) {
    $code = "class GeneratedModel extends SystemBase {\n";
    $code .= "    public static \$field_specifications = array(\n";
    
    foreach ($fields as $name => $config) {
        $code .= "        '$name' => array(\n";
        $code .= "            'type' => '{$config['type']}'";
        
        if (isset($config['is_nullable'])) {
            $code .= ",\n            'is_nullable' => " . ($config['is_nullable'] ? 'true' : 'false');
        }
        if (!empty($config['required'])) {
            $code .= ",\n            'required' => true";
        }
        if (!empty($config['zero'])) {
            $code .= ",\n            'zero_on_create' => true";
        }
        if (isset($config['default'])) {
            $value = is_string($config['default']) ? "'{$config['default']}'" : var_export($config['default'], true);
            $code .= ",\n            'default' => $value";
        }
        
        $code .= "\n        ),\n";
    }
    
    $code .= "    );\n";
    $code .= "}\n";
    
    return $code;
}
```

## Verification Steps

After implementation, verify NO references to legacy arrays remain:

```bash
# These commands should return NO results (except in specs/docs):
grep -r "\$required_fields" --include="*.php" /var/www/html/joinerytest/public_html
grep -r "\$zero_variables" --include="*.php" /var/www/html/joinerytest/public_html  
grep -r "\$initial_default_values" --include="*.php" /var/www/html/joinerytest/public_html
grep -r "\$timestamp_fields" --include="*.php" /var/www/html/joinerytest/public_html
```

Key files to double-check:
- `/includes/SystemBase.php` - Core implementation
- `/tests/models/*.php` - Test framework
- `/data/*_class.php` - All model files
- `/plugins/*/data/*_class.php` - Plugin models

## Success Criteria

1. All models successfully converted to new structure
2. All existing functionality continues to work
3. No performance degradation in common operations
4. Form generation and validation work correctly with new structure
5. Database update system continues to function properly
6. **NO references to legacy arrays remain in PHP code**
7. All tests pass with new structure

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