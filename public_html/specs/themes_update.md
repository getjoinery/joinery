# Theme Update Requirements

## Issue Date: 2025-09-08

## Problem Summary
Several legacy themes are not compatible with the current theme system architecture, causing errors when users try to use them. The main issues include:

1. **Missing PublicPage.php files** - Some themes don't have their own PublicPage.php implementation
2. **Method signature incompatibilities** - Older themes have methods that don't match parent class signatures
3. **Incorrect file paths** - Some themes use $_SERVER['DOCUMENT_ROOT'] instead of PathHelper

## Affected Themes

### Themes Missing PublicPage.php:
- galactictribune
- devonandjerry  
- plugin (special case - used for plugin-provided themes)
- zoukphilly

### Themes With Known Issues:
- **jeremytunnell** - Had `endtable()` method signature mismatch (now fixed)
- **galactictribune** - Minimal theme that only shows blog, missing PublicPage.php

## Specific Error Examples

### GalacticTribune Error:
```
Theme file not found: PublicPage.php for theme directory 'theme/galactictribune'
Searched paths:
- /var/www/html/joinerytest/public_html/theme/galactictribune/includes/PublicPage.php
- /var/www/html/joinerytest/public_html/includes/PublicPage.php
```

### Root Cause:
The theme's index.php includes `/views/blog.php`, which calls:
```php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
```
But galactictribune has no includes directory or PublicPage.php file.

## Required Updates

### 1. For Themes Missing PublicPage.php
Each theme needs either:
- A minimal PublicPage.php that extends PublicPageBase
- Or to be marked as deprecated/unsupported

### 2. Method Signature Compatibility
All theme classes that extend base classes need to have matching method signatures. Common issues:
- `endtable($pager = null)` - parameter was missing in older themes
- Other PublicPageBase methods that may have been updated

### 3. Path Updates
Replace all instances of:
- `require_once($_SERVER['DOCUMENT_ROOT'] . '/...')` 
With:
- `PathHelper::requireOnce('...')`

### 4. Theme Structure Requirements
Modern themes should have:
```
theme/[theme-name]/
├── includes/
│   ├── PublicPage.php (required)
│   └── FormWriter.php (optional)
├── views/
│   └── (theme-specific view overrides)
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
└── theme.json (metadata)
```

## Proposed Solutions

### Option 1: Create Minimal PublicPage for Legacy Themes
Create a basic PublicPage.php that just extends PublicPageBase without customization:
```php
<?php
require_once(__DIR__ . '/../../includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {
    // Implement required abstract methods
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table',
            'header' => 'thead-light'
        ];
    }
}
?>
```

### Option 2: Create Compatibility Layer
Add fallback logic to PathHelper::getThemeFilePath() to use a default PublicPage.php when theme doesn't provide one.

### Option 3: Deprecate Non-Compliant Themes
Mark themes as deprecated if they can't be easily updated, and prevent them from being selected in admin settings.

## Testing Requirements
After updates, each theme needs to be tested for:
1. Homepage loads without errors
2. Navigation works
3. Forms render correctly
4. Admin pages work (if theme affects admin)
5. Assets load properly

## Priority
- **High**: galactictribune, zoukphilly (if actively used)
- **Medium**: devonandjerry
- **Low**: Other legacy themes that may not be in use

## Notes
- The 'plugin' theme is a special case used when plugins provide the complete theme
- Some themes may be intentionally minimal (like galactictribune for blog-only sites)
- Consider whether all themes need full PublicPage implementations or if some can share a common basic version