# Plugin and Theme Migration Specification - Action Items Only

## Overview

This specification focuses exclusively on the remaining non-compliant components that require migration. All compliant plugins and themes have been removed from scope.

## New Core File Guarantees

### Files to Add as Core Guarantees

Based on usage analysis, the following files will be added to the core guarantees (always available without requiring):

**Current Core Guarantees:**
- PathHelper.php
- Globalvars.php
- SessionControl.php

**New Core Guarantees to Add:**
1. **LibraryFunctions.php** - Used in 90%+ of all theme files
2. **ErrorHandler.php** - Essential error handling in most logic files
3. **DbConnector.php** - Singleton database access needed everywhere

### Implementation Location

These files need to be added to the core loading mechanism in **RouteHelper.php**:

```php
// File: /includes/RouteHelper.php
// Location: In the initialization section where PathHelper, Globalvars, and SessionControl are loaded
// Add after existing core file loads:

require_once(__DIR__ . '/LibraryFunctions.php');
require_once(__DIR__ . '/ErrorHandler.php');
require_once(__DIR__ . '/DbConnector.php');
```

### Files That Will NO LONGER Need to be Required

With the expanded core guarantees, the following require statements should be REMOVED from all theme and plugin files:

```php
// These lines should be COMPLETELY REMOVED from all files:
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PathHelper.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');

// Also remove PathHelper versions:
PathHelper::requireOnce('includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/ErrorHandler.php');
PathHelper::requireOnce('includes/DbConnector.php');
```

## Executive Summary

**Non-Compliant Components Requiring Migration:**
- 🔄 **8 themes need fixes**: File naming, missing implementations, deprecated paths (sassa and default deleted)
- 🎯 **High Priority**: tailwind (most complex with 55 files to fix)
- 📋 **Common Issues**: FormWriter paths, missing PublicPage implementations, naming standardization

**Themes Being Deleted (No Migration Needed):**
- ❌ **sassa** - Assets migrated to ControlD plugin, theme ready for deletion
- ❌ **default** - System no longer uses default themes

**ControlD Plugin Status:**
- ✅ **ControlD plugin theme** - Already migrated to use self-contained assets
- ✅ Assets copied from sassa to `/plugins/controld/assets/`
- ✅ All 52 asset references updated to plugin paths

## Required Theme Migrations

### Critical Priority Themes

#### 1. **tailwind Theme** (Comprehensive views, Tailwind CSS)
**Issues:**
- ❌ Has `/includes/PublicPage.php` but extensive `$_SERVER['DOCUMENT_ROOT']` usage
- ❌ Uses deprecated `$_SERVER['DOCUMENT_ROOT']` in 55 files (logic/views/includes)

**Specific Lines to Change - Sample (55 total files need fixing):**
```php
// File: /includes/FormWriter.php, Line 2:
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
// Should be REMOVED entirely (DbConnector now guaranteed available)

// File: /includes/FormWriter.php, Line 3:
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
// Should be REMOVED entirely (Globalvars guaranteed available)

// File: /logic/post_logic.php, Line 4:
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
// Should be REMOVED entirely (SessionControl guaranteed available)

// File: /logic/post_logic.php, Line 5:
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
// Should become:
PathHelper::requireOnce('includes/EmailTemplate.php');

// File: /views/survey.php, Line 2:
require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
// Should be REMOVED entirely (LibraryFunctions now guaranteed available)

// Note: Remove ALL requires for: PathHelper, Globalvars, SessionControl, LibraryFunctions, ErrorHandler, DbConnector
```

**Required Actions:**
```bash
# Create missing PublicPage.php
cat > /home/user1/joinery/joinery/theme/tailwind/includes/PublicPage.php << 'EOF'
<?php
PathHelper::requireOnce('includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'overflow-x-auto',
            'table' => 'table-auto w-full border-collapse',
            'header' => 'bg-gray-100 text-gray-700'
        ];
    }
}
?>
EOF

# Fix all deprecated paths - convert $_SERVER['DOCUMENT_ROOT'] to PathHelper  
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i 's|require_once($_SERVER\[.DOCUMENT_ROOT.\] \. .|PathHelper::requireOnce(|g' {} \;

# Remove ALL guaranteed includes (expanded list)
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i '/PathHelper::requireOnce.*PathHelper\.php/d' {} \;
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i '/PathHelper::requireOnce.*Globalvars\.php/d' {} \;
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i '/PathHelper::requireOnce.*SessionControl\.php/d' {} \;
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i '/PathHelper::requireOnce.*LibraryFunctions\.php/d' {} \;
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i '/PathHelper::requireOnce.*ErrorHandler\.php/d' {} \;
find /home/user1/joinery/joinery/theme/tailwind -name "*.php" -exec \
    sed -i '/PathHelper::requireOnce.*DbConnector\.php/d' {} \;
```

### Standard Priority Themes

#### 2. **jeremytunnell Theme** (WordPress CSS)
**Issues:**
- ❌ Uses `FormWriterPublic.php` instead of `FormWriter.php`
- ❌ Class defined as `FormWriterPublic` instead of `FormWriter`

**Specific Lines to Change:**
```php
// File: /includes/FormWriterPublic.php, Line 9:
class FormWriterPublic extends FormWriterMaster{
// Should become:
class FormWriter extends FormWriterMaster{
```

**Required Actions:**
```bash
cd /home/user1/joinery/joinery/theme/jeremytunnell/includes/

# Update class name in the file
sed -i 's/class FormWriterPublic/class FormWriter/g' FormWriterPublic.php

# Rename the file
mv FormWriterPublic.php FormWriter.php
```

#### 3. **galactictribune Theme** (Custom Tailwind)
**Issues:**
- ❌ Uses `FormWriterPublic.php` instead of `FormWriter.php`
- ❌ Class defined as `FormWriterPublic` instead of `FormWriter`
- ❌ Views reference `FormWriterPublicTW.php` (doesn't exist)
- ❌ Code uses `new FormWriterPublic()` and `FormWriterPublic::` static calls

**Specific Lines to Change:**
```php
// File: /includes/FormWriterPublic.php, Line 9:
class FormWriterPublic extends FormWriterBase {
// Should become:
class FormWriter extends FormWriterBase {

// File: /views/explorer.php, Line 6:
ThemeHelper::includeThemeFile('includes/FormWriterPublicTW.php');
// Should become:
ThemeHelper::includeThemeFile('includes/FormWriter.php');

// File: /views/explorer.php, Line 18:
if(!FormWriterPublic::honeypot_check($_REQUEST)){
// Should become:
if(!FormWriter::honeypot_check($_REQUEST)){

// File: /views/explorer.php, Line 23:
if(!FormWriterPublic::antispam_question_check($_REQUEST)){
// Should become:
if(!FormWriter::antispam_question_check($_REQUEST)){

// File: /views/explorer.php, Line 28:
$captcha_success = FormWriterPublic::captcha_check($_REQUEST);
// Should become:
$captcha_success = FormWriter::captcha_check($_REQUEST);

// File: /views/explorer.php, Line 153:
$formwriter = new FormWriterPublic("form1", TRUE);
// Should become:
$formwriter = new FormWriter("form1", TRUE);

// File: /views/explorer.php, Line 157:
$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules);
// Should become:
$validation_rules = FormWriter::antispam_question_validate($validation_rules);
```

**Required Actions:**
```bash
cd /home/user1/joinery/joinery/theme/galactictribune/

# Update class name in the file
sed -i 's/class FormWriterPublic/class FormWriter/g' includes/FormWriterPublic.php

# Fix view file references
sed -i "s/FormWriterPublicTW.php/FormWriter.php/g" views/explorer.php

# Fix instantiation and static calls in views
sed -i 's/new FormWriterPublic/new FormWriter/g' views/explorer.php
sed -i 's/FormWriterPublic::/FormWriter::/g' views/explorer.php

# Rename the file
cd includes/
mv FormWriterPublic.php FormWriter.php
```

#### 4. **zoukroom Theme** (UIKit)
**Issues:**
- ❌ Uses `FormWriterPublic.php` instead of `FormWriter.php`
- ❌ Class defined as `FormWriterPublic` instead of `FormWriter`
- ❌ Views include `FormWriterPublic.php` file references

**Required Actions:**
```bash
cd /home/user1/joinery/joinery/theme/zoukroom/

# Update class name in the file
sed -i 's/class FormWriterPublic/class FormWriter/g' includes/FormWriterPublic.php

# Fix include references in views and index
sed -i "s/FormWriterPublic.php/FormWriter.php/g" index.php
sed -i "s/FormWriterPublic.php/FormWriter.php/g" views/events.php
sed -i "s/FormWriterPublic.php/FormWriter.php/g" views/event.php

# Rename the file
cd includes/
mv FormWriterPublic.php FormWriter.php
```

### Minimal Priority Themes

#### 5. **devonandjerry Theme** (Tailwind)
**Issues:**
- ❌ Missing `/includes/PublicPage.php` implementation
- ❌ Uses `FormWriterPublic.php` instead of `FormWriter.php`
- ❌ Class defined as `FormWriterPublic` instead of `FormWriter`
- ❌ Index references `FormWriterPublicTW.php` (doesn't exist)
- ❌ Uses deprecated `$_SERVER['DOCUMENT_ROOT']` in FormWriter
- ❌ Uses `displayName` instead of `display_name` in theme.json

**Specific Lines to Change:**
```php
// File: /includes/FormWriterPublic.php, Line 8:
class FormWriterPublic extends FormWriterMaster {
// Should become:
class FormWriter extends FormWriterMaster {

// File: /index.php, Line 6:
ThemeHelper::includeThemeFile('includes/FormWriterPublicTW.php');
// Should become:
ThemeHelper::includeThemeFile('includes/FormWriter.php');
```

```json
// File: /theme.json, Line 3:
"displayName": "Devon and Jerry Theme",
// Should become:
"display_name": "Devon and Jerry Theme",
```

**Required Actions:**
```bash
cd /home/user1/joinery/joinery/theme/devonandjerry/

# Update class name in the file
sed -i 's/class FormWriterPublic/class FormWriter/g' includes/FormWriterPublic.php

# Fix index.php reference
sed -i "s/FormWriterPublicTW.php/FormWriter.php/g" index.php

# Fix FormWriter.php paths
sed -i 's|require_once($_SERVER\[.DOCUMENT_ROOT.\] . .|PathHelper::requireOnce(|g' includes/FormWriterPublic.php

# Rename FormWriter file
cd includes/
mv FormWriterPublic.php FormWriter.php
cd ..

# Create missing PublicPage.php
cat > includes/PublicPage.php << 'EOF'
<?php
PathHelper::requireOnce('includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'overflow-x-auto',
            'table' => 'table-auto w-full border-collapse',
            'header' => 'bg-gray-100 text-gray-700'
        ];
    }
}
?>
EOF

# Fix theme.json naming
sed -i 's|"displayName"|"display_name"|g' theme.json
```

#### 6. **zoukphilly Theme** (Tailwind)
**Issues:**
- ❌ Missing `/includes/PublicPage.php` implementation
- ❌ Uses `FormWriterPublic.php` instead of `FormWriter.php`
- ❌ Class defined as `FormWriterPublic` instead of `FormWriter`
- ❌ Index references `FormWriterPublicTW.php` (doesn't exist)
- ❌ Index uses `PublicPageTW` class (should be `PublicPage`)

**Specific Lines to Change:**
```php
// File: /includes/FormWriterPublic.php, Line 8:
class FormWriterPublic extends FormWriterMaster {
// Should become:
class FormWriter extends FormWriterMaster {

// File: /index.php, Line 6:
ThemeHelper::includeThemeFile('includes/FormWriterPublicTW.php');
// Should become:
ThemeHelper::includeThemeFile('includes/FormWriter.php');

// File: /index.php, Line 5:
ThemeHelper::includeThemeFile('includes/PublicPageTW.php');
// Should become:
ThemeHelper::includeThemeFile('includes/PublicPage.php');

// File: /index.php, Line 15:
$page = new PublicPageTW();
// Should become:
$page = new PublicPage();

// File: /index.php, Line 22:
echo PublicPageTW::BeginPage('');
// Should become:
echo PublicPage::BeginPage('');

// File: /index.php, Line 447:
echo PublicPageTW::EndPage();
// Should become:
echo PublicPage::EndPage();
```

**Required Actions:**
```bash
cd /home/user1/joinery/joinery/theme/zoukphilly/

# Update class name in the file
sed -i 's/class FormWriterPublic/class FormWriter/g' includes/FormWriterPublic.php

# Fix FormWriter class name and file references
sed -i 's/class FormWriterPublic/class FormWriter/g' includes/FormWriterPublic.php
sed -i "s/FormWriterPublicTW.php/FormWriter.php/g" index.php

# Fix PublicPage class name and file references  
sed -i "s/PublicPageTW.php/PublicPage.php/g" index.php
sed -i 's/new PublicPageTW/new PublicPage/g' index.php
sed -i 's/PublicPageTW::/PublicPage::/g' index.php

# Rename FormWriter file
cd includes/
mv FormWriterPublic.php FormWriter.php
cd ..

# Create missing PublicPage.php
cat > includes/PublicPage.php << 'EOF'
<?php
PathHelper::requireOnce('includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'overflow-x-auto',
            'table' => 'table-auto w-full border-collapse',
            'header' => 'bg-gray-100 text-gray-700'
        ];
    }
}
?>
EOF
```

### Special Case Themes

#### 7. **plugin Theme** (Plugin delegation)
**Issues:**
- ❌ No `/includes/` directory for fallback implementations

**Required Actions:**
```bash
# Create includes directory and fallback implementations
mkdir -p /home/user1/joinery/joinery/theme/plugin/includes/

# Create fallback PublicPage.php
cat > /home/user1/joinery/joinery/theme/plugin/includes/PublicPage.php << 'EOF'
<?php
PathHelper::requireOnce('includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped',
            'header' => 'thead-light'
        ];
    }
}
?>
EOF

# Create fallback FormWriter.php
cat > /home/user1/joinery/joinery/theme/plugin/includes/FormWriter.php << 'EOF'
<?php
PathHelper::requireOnce('includes/FormWriterMasterDefault.php');

class FormWriter extends FormWriterMasterDefault {
    // Fallback FormWriter for plugin theme delegation
}
?>
EOF
```

## Minor Issues Requiring Fixes

### falcon Theme
**Issues:**
- ❌ Uses `displayName` instead of `display_name` in theme.json

**Specific Lines to Change:**
```json
// File: /theme.json, Line 3:
"displayName": "Falcon Theme",
// Should become:
"display_name": "Falcon Theme",
```

**Required Actions:**
```bash
sed -i 's|"displayName"|"display_name"|g' /home/user1/joinery/joinery/theme/falcon/theme.json
```

## Implementation Order

### Phase 1: Critical Fixes (Week 1)
1. **tailwind theme** - Fix 55 files with deprecated paths
2. **File naming standardization** - Rename all FormWriterPublic.php → FormWriter.php

### Phase 2: Standard Fixes (Week 2)
3. **jeremytunnell, galactictribune, zoukroom** - File renames and fixes
4. **devonandjerry, zoukphilly** - Multiple fixes per theme
5. **falcon** - Minor theme.json fix

### Phase 3: Special Cases (Week 3)
6. **plugin theme** - Create fallback implementations
7. **Comprehensive testing** of all fixes

### Themes to Delete (ControlD Already Fixed)
- **sassa** - ✅ Assets migrated to ControlD plugin - Ready for safe deletion
- **default** - Delete entirely (system no longer uses default themes)

**Pre-Migration Completed:**
1. ControlD plugin assets copied from sassa theme
2. All ControlD asset references updated to plugin paths 
3. ControlD now self-contained and independent from sassa theme
4. Migration can now proceed safely without breaking ControlD

## Validation Commands

**Verify File Naming Standardization:**
```bash
# Should return empty (no FormWriterPublic.php files)
find /home/user1/joinery/joinery/theme -name "FormWriterPublic.php"
```

**Verify All Themes Have Required Files:**
```bash
# Should list 10 files (all themes have PublicPage.php)
ls /home/user1/joinery/joinery/theme/*/includes/PublicPage.php | wc -l

# Should list 10 files (all themes have FormWriter.php)
ls /home/user1/joinery/joinery/theme/*/includes/FormWriter.php | wc -l
```

**Verify No Deprecated Paths:**
```bash
# Should return empty (no $_SERVER['DOCUMENT_ROOT'] usage)
grep -r "\$_SERVER\['DOCUMENT_ROOT'\]" /home/user1/joinery/joinery/theme/*/includes/
```

## Success Criteria

- [ ] Core file guarantees expanded in RouteHelper.php
- [ ] All themes have `PublicPage.php` in `/includes/`
- [ ] All themes have `FormWriter.php` (not FormWriterPublic.php) in `/includes/`
- [ ] No themes use deprecated `$_SERVER['DOCUMENT_ROOT']` paths
- [ ] All theme.json files use consistent naming (`display_name` not `displayName`)
- [ ] Plugin theme has fallback implementations
- [ ] All theme switches work without errors
- [ ] CSS frameworks render correctly in all themes
- [ ] CLAUDE.md updated with new core file guarantees

## Documentation Updates Required

### Update CLAUDE.md Core File Guarantees Section

```markdown
## CRITICAL: File Include Rules

### Core File Guarantees
**Always available without requiring:** PathHelper, Globalvars, SessionControl, LibraryFunctions, ErrorHandler, DbConnector
- Loaded by RouteHelper for all non-static requests
- Use directly in themes/plugins/views without require_once
- NOT loaded for static assets (CSS/JS/images) for performance
```

This focused specification contains only the specific actions needed to complete the plugin/theme migration, with all compliant components removed from scope.