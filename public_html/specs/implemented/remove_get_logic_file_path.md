# Specification: Remove get_logic_file_path Function

## Implementation Status: ✅ COMPLETE (2025-09-10)

### Migration Summary
- **Files Updated**: 59 files total
  - 56 files via automated script
  - 3 special cases handled manually
- **Backups Created**: /var/www/html/joinerytest/public_html/backups/logic_migration_2025-09-10_152558
- **Verification**: All deprecated usage has been removed

## Overview
This specification outlines the complete removal of the deprecated `LibraryFunctions::get_logic_file_path()` method and its replacement with the modern `ThemeHelper::includeThemeFile()` approach.

## Background
The `get_logic_file_path()` function has several limitations:
1. It only searches in `/theme/{theme}/logic/` and `/logic/` directories
2. It is not plugin-aware, causing failures when plugins try to load their own logic files
3. It doesn't follow the modern override chain pattern (theme → plugin → base)
4. It creates inconsistency in how files are loaded across the system

## Goals
1. Remove all uses of `LibraryFunctions::get_logic_file_path()`
2. Replace with `ThemeHelper::includeThemeFile()` for consistency
3. Ensure all logic files can be overridden by themes
4. Maintain backward compatibility during migration
5. Provide clear migration path for developers

## Technical Design

### Current Pattern (Deprecated)
```php
require_once(LibraryFunctions::get_logic_file_path('example_logic.php'));
```

### New Pattern
```php
ThemeHelper::includeThemeFile('logic/example_logic.php');
```

### For Plugin-Specific Logic
```php
// When called from a plugin view, specify the plugin
ThemeHelper::includeThemeFile('logic/example_logic.php', null, [], 'plugin_name');
```

### Override Chain
The new pattern will search in this order:
1. `/theme/{current_theme}/logic/example_logic.php` - Theme override
2. `/plugins/{plugin}/logic/example_logic.php` - Plugin version (if plugin specified)
3. `/logic/example_logic.php` - System default

## Migration Strategy

### Phase 1: Update Core Files
1. Update all files in `/views/` directory
2. Update all files in `/plugins/*/views/` directories
3. Update any other references in the codebase

### Phase 2: Deprecation
1. Add deprecation warning to `get_logic_file_path()` method
2. Log usage to help identify any missed files
3. Keep method functional for 1 release cycle

### Phase 3: Removal
1. Remove the `get_logic_file_path()` method entirely
2. Update documentation
3. Update CLAUDE.md with new patterns

## File Updates Required

### Core Views (40 files)
All files in `/views/` and `/views/profile/` that use the deprecated method

### Plugin Views (19 files)
All files in `/plugins/controld/views/` that use the deprecated method

### Data Classes (2 files)
- `/data/page_contents_class.php`
- `/data/pages_class.php`

### Total: 61 files to update

## Implementation Details

### Pattern Transformations

#### Basic Logic File
```php
// OLD
require_once(LibraryFunctions::get_logic_file_path('survey_logic.php'));

// NEW
ThemeHelper::includeThemeFile('logic/survey_logic.php');
```

#### With Parentheses Variations
```php
// OLD (with space)
require_once (LibraryFunctions::get_logic_file_path('page_logic.php'));

// NEW
ThemeHelper::includeThemeFile('logic/page_logic.php');
```

#### Plugin Logic Files
```php
// OLD (in plugin view)
require_once(LibraryFunctions::get_logic_file_path('devices_logic.php'));

// NEW (with plugin context)
$current_plugin = basename(dirname(dirname(__DIR__))); // Detect plugin from path
ThemeHelper::includeThemeFile('logic/devices_logic.php', null, [], $current_plugin);
```

## Testing Requirements

1. **Unit Tests**
   - Verify ThemeHelper correctly resolves logic files
   - Test override chain (theme → plugin → base)
   - Test plugin context detection

2. **Integration Tests**
   - Test all updated views load correctly
   - Verify logic functions are accessible
   - Test with different themes active

3. **Regression Tests**
   - Ensure no functionality is broken
   - Test both with and without theme overrides
   - Test plugin functionality with different themes

## Rollback Plan

If issues are discovered:
1. The migration script creates backups of all modified files
2. A rollback script can restore from backups
3. The deprecated method can be temporarily restored

## Success Criteria

1. All 61 files updated successfully
2. No errors when loading any view
3. Logic file override chain works correctly
4. Plugin logic files load properly regardless of active theme
5. All tests pass

## Timeline

- **Day 1**: Run migration script, test core views
- **Day 2**: Test plugin views, fix any issues
- **Day 3**: Add deprecation warning, monitor logs
- **Week 2**: Remove deprecated method if no issues

## Documentation Updates

1. Update CLAUDE.md with new file inclusion patterns
2. Update plugin developer guide
3. Add migration guide for custom code
4. Update code examples throughout documentation