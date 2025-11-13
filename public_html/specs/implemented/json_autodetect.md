# JSON Field Auto-Detection Specification

## Overview

Replace the manual `$json_vars` array with automatic JSON field detection based on field_specifications, following the same pattern as timestamp field auto-detection. This eliminates boilerplate code and ensures consistency.

## Problem

Currently, developers must manually maintain `$json_vars` arrays to specify which fields should be included in JSON API output. This creates maintenance overhead and inconsistency across model classes.

## Solution

Implement optimized auto-detection of JSON fields based on field type specifications, similar to the existing `is_timestamp_field()` method.

## Files to Update: 7 Total

### 1. Core Implementation: SystemBase.php

**BEFORE:**
```php
static $json_vars = array('key');

function get_json() { 
    // build the json-ready PHP object (to be passed into json_encode) 
    $json = array();
    foreach (array_keys(static::$field_specifications) as $field) {
        if (in_array($field, static::$json_vars)) { 
            // make sanitary for display
            $json[$field] = htmlspecialchars($this->get($field));
        }
    }
    return $json;
}
```

**AFTER:**
```php
// Remove: static $json_vars = array('key');

/**
 * Auto-detect if a field is a JSON field based on its type specification
 * Optimized for performance with quick rejection of non-JSON types
 */
protected function is_json_field($field_name) {
    if (!isset(static::$field_specifications[$field_name])) {
        return false;
    }
    
    $type = static::$field_specifications[$field_name]['type'] ?? '';
    
    // Optimized: Quick rejection based on first character
    $first_char = $type[0] ?? '';
    if ($first_char !== 'j') {
        return false; // Not json/jsonb - immediate rejection
    }
    
    // Only perform exact comparison if starts with 'j'
    return $type === 'json' || $type === 'jsonb';
}

function get_json() { 
    // build the json-ready PHP object (to be passed into json_encode) 
    $json = array();
    foreach (array_keys(static::$field_specifications) as $field) {
        if ($this->is_json_field($field)) { 
            // make sanitary for display
            $json[$field] = htmlspecialchars($this->get($field));
        }
    }
    return $json;
}
```

### 2. Simple Removal: themes_class.php

**BEFORE:**
```php
public static $json_vars = array('thm_metadata');
```

**AFTER:**
```php
// Remove entire $json_vars declaration
// JSON fields auto-detected from field_specifications where type='jsonb'
```

**Field Specification (remains unchanged):**
```php
'thm_metadata' => array('type'=>'jsonb'),
```

### 3. Simple Removal: events_class.php

**BEFORE:**
```php
public static $json_vars = array(
    'evt_special_instructions', 'evt_special_requirements'
);
```

**AFTER:**
```php
// Remove entire $json_vars declaration
// JSON fields auto-detected from field_specifications
```

### 4. Simple Removal: event_sessions_class.php

**BEFORE:**
```php
public static $json_vars = array(
    'evs_description'
);
```

**AFTER:**
```php
// Remove entire $json_vars declaration
// JSON fields auto-detected from field_specifications
```

### 5. Simple Removal: event_registrants_class.php

**BEFORE:**
```php
public static $json_vars = array(
    'evr_notes', 'evr_special_dietary_requirements'
);
```

**AFTER:**
```php
// Remove entire $json_vars declaration  
// JSON fields auto-detected from field_specifications
```

### 6. Unused Legacy Case: address_class.php

This class has a custom `get_json()` implementation that appears to be unused legacy code.

**BEFORE:**
```php
public static $json_vars = array(
    'usa_address1', 'usa_address2', 'usa_city', 'usa_state', 'usa_zip_code_id',
);
public static $json_prefix = 'usa_';

function get_json() {
    // build the json-ready PHP object (to be passed into json_encode)
    $json = array();
    foreach(self::$json_vars as $field) {
        // strip out the prefix when shipping as JSON, also make sanitary for display
        $json[str_replace(self::$json_prefix, '', $field)] = htmlspecialchars($this->get($field));
    }
    return $json;
}
```

**AFTER (Remove Unused Code):**
```php
// Remove: public static $json_vars = array(...);
// Remove: public static $json_prefix = 'usa_';
// Remove: function get_json() { ... }

// All fields are VARCHAR types, not JSON types, so auto-detection will not include them
// If JSON API output is needed in the future, implement as needed
```

**Analysis:** 
- Custom `get_json()` method has no usage in codebase
- All fields in `$json_vars` are VARCHAR types, not JSON data types
- Prefix stripping logic suggests API usage that was never implemented or removed
- Safe to remove as unused legacy code

### 7. Documentation: docs/example_class.php

**Already Updated** - Contains documentation of the new auto-detection approach.

## Performance Benefits

### Before (Current):
- `in_array($field, static::$json_vars)` - O(n) linear search through array
- Performed for every field in every `get_json()` call

### After (Optimized):
- `$type[0] !== 'j'` - O(1) immediate rejection for 95%+ of fields
- Only 2 exact string comparisons for fields starting with 'j'
- Follows same optimization pattern as `is_timestamp_field()`

## Migration Path

1. **Update SystemBase.php** - Add `is_json_field()` method and update `get_json()`
2. **Remove simple $json_vars** from 4 model classes (themes, events, event_sessions, event_registrants)
3. **Handle address_class.php** custom logic (decide on Option 1 or 2)
4. **Test API endpoints** to ensure JSON output unchanged
5. **Update documentation** in CLAUDE.md

## Backward Compatibility

During transition:
- Classes without `$json_vars` will use auto-detection
- Classes with custom `get_json()` methods continue to work
- No API output changes for properly typed JSON fields

## Testing Requirements

- Verify JSON API output unchanged for all model classes
- Test performance improvement with large field_specifications
- Ensure auto-detection correctly identifies json/jsonb fields
- Validate custom get_json() implementations still work

## Benefits

- ✅ **Eliminates boilerplate** - No manual JSON field arrays
- ✅ **Performance optimized** - Fast field type detection  
- ✅ **Consistent pattern** - Follows timestamp field approach
- ✅ **Type-driven** - Uses field_specifications as single source of truth
- ✅ **Backward compatible** - Custom overrides still supported