# Specification: Remove jQuery Dependency - Phase 2 Cleanup

**Status:** Pending - Ready for implementation
**Priority:** High
**Estimated Effort:** 45 minutes - 1 hour
**Date Created:** 2025-11-01
**Related Specification:** Phase 1 Complete - See `/specs/implemented/remove_jquery_dependency.md`

---

## 1. Overview

Phase 2 removes jQuery from the global application after Phase 1 migration is complete. This involves:

1. **Removing jQuery CDN includes** from page template files
2. **Deleting bundled jQuery files** from theme directories
3. **Documenting theme/plugin jQuery requirements** for independent jQuery loading

**Important:** Themes and plugins that require jQuery must load it independently after Phase 2 cleanup.

**Prerequisites:** Phase 1 migration must be complete - See `/specs/implemented/remove_jquery_dependency.md`

---

## 2. Themes and Plugins Requiring jQuery

### 2.1 Plugins Requiring jQuery

**ControlD Plugin** - Requires jQuery loading:
- `assets/js/controld-plugin.js` - Event binding, device management (uses `$(document).ready`, `$(document).on`, `.click`, `.change`)
- `assets/js/main.js` - Extensive DOM manipulation, sliders, mobile menu, animations, form validation (uses `$.ajax`, custom jQuery methods)
- `views/login.php` - Input focus management (uses `$().focus`)
- `views/cart.php` - Form field visibility (hide/show)
- `includes/FormWriter.php` - Validation error styling (addClass/removeClass)
- `assets/js/swiper-bundle.min.js` - Third-party carousel library (not jQuery dependent)

### 2.2 Themes Requiring jQuery

**Canvas Theme:**
- `views/cart.php` - Prevent duplicate form submissions, disable buttons during submission
- `views/post.php` - Comment toggle with animation (`.toggle()`)

**Tailwind Theme:**
- `views/events.php` - Category selector navigation with redirect (uses `$(location).attr`)
- `views/cart.php` - Prevent duplicate form submissions

**Default Theme:**
- `includes/FormWriter.php` - Form validation error styling (addClass/removeClass for error containers)

**Devon & Jerry Theme:**
- `includes/FormWriter.php` - Form validation error styling

**Zouk Philly Theme:**
- `includes/FormWriter.php` - Form validation error styling

**Themes with NO jQuery Dependency:**
- Galactic Tribune
- Falcon
- Plugin
- Zouk Room

---

## 3. Phase 2 - Cleanup Tasks

### 3.1 Remove jQuery CDN from Page Templates

Remove jQuery script tags that load jQuery globally on every page. These appear in the page header includes.

**Files to Modify:**

1. **`/includes/PublicPageFalcon.php`**
   - Remove jQuery 3.7.1 CDN script tag
   - This jQuery loads on all public pages using Falcon theme

2. **`/includes/PublicPageTailwind.php`**
   - Remove jQuery 3.7.1 CDN script tag
   - This jQuery loads on all public pages using Tailwind theme

3. **`/includes/AdminPage-uikit3.php`**
   - Remove both jQuery 3.4.1 CDN script tags (appears twice in this file)
   - This jQuery loads on all admin pages

### 3.2 Delete Bundled jQuery Files from Themes

Remove locally bundled jQuery files that are no longer needed globally. These files were included as backups but are no longer used after Phase 1 migration.

**Files to Delete:**

1. `/theme/galactictribune/assets/js/jquery-3.4.1.min.js`
2. `/theme/default/assets/js/jquery-3.4.1.min.js`
3. `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js`
4. `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js`

### 3.3 Remove jQuery Validate Plugin Files

Remove jQuery Validate plugin files that are no longer used after Phase 1 migration.

**Search for and Remove:**
- Any remaining jQuery Validate plugin files in theme directories
- Any legacy jQuery Validate references from utility files
- Check for `.validate()` method calls in form files

---

## 4. Post-Cleanup Requirements for Themes and Plugins

After Phase 2 cleanup, themes and plugins that require jQuery **must include jQuery themselves**.

### 4.1 ControlD Plugin - Action Required

The ControlD plugin uses jQuery extensively and must load jQuery independently:

**Recommended Approach:**
1. Add jQuery CDN to ControlD plugin's main includes or asset loading
2. OR use the same jquery-loader.js pattern for conditional loading

**Files to Update:**
- `/plugins/controld/` - Add jQuery loading to plugin initialization

### 4.2 Canvas Theme - Action Required

Canvas theme uses jQuery in:
- `views/cart.php` - Form submission handling
- `views/post.php` - Comment toggle

**Recommended Approach:**
1. Add jQuery CDN to Canvas theme's PublicPage template
2. OR convert to vanilla JavaScript (preferred long-term)

### 4.3 Tailwind Theme - Action Required

Tailwind theme uses jQuery in:
- `views/events.php` - Category selector navigation
- `views/cart.php` - Form submission handling

**Recommended Approach:**
1. Add jQuery CDN to Tailwind theme's PublicPage template
2. OR convert to vanilla JavaScript (preferred long-term)

### 4.4 Default, Devon & Jerry, Zouk Philly Themes - Action Required

These themes use jQuery in FormWriter.php for validation error styling:
- `includes/FormWriter.php` - addClass/removeClass for error containers

**Current Implementation:**
- jQuery is used only for CSS class manipulation
- Can be easily converted to vanilla JavaScript: `classList.add()` and `classList.remove()`

**Recommended Approach:**
1. Convert FormWriter.php to use `classList` API (vanilla JavaScript)
2. OR add jQuery CDN to each theme's PublicPage template

---

## 5. Implementation Workflow

### Step 1: Remove jQuery CDN from Templates (3 files)
1. Edit `/includes/PublicPageFalcon.php` - Find and remove jQuery script tag
2. Edit `/includes/PublicPageTailwind.php` - Find and remove jQuery script tag
3. Edit `/includes/AdminPage-uikit3.php` - Find and remove both jQuery script tags

### Step 2: Delete Bundled jQuery Files (4 files)
1. Delete `/theme/galactictribune/assets/js/jquery-3.4.1.min.js`
2. Delete `/theme/default/assets/js/jquery-3.4.1.min.js`
3. Delete `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js`
4. Delete `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js`

### Step 3: Verify Cleanup (Optional - Recommended)
1. Search entire codebase for remaining jQuery references
   ```bash
   grep -r "jquery" /path/to/codebase --exclude-dir=node_modules --exclude-dir=.git
   ```
2. Should only find jQuery in plugins and themes that need it

### Step 4: Document Changes (Recommended)
1. Update theme and plugin documentation
2. Note jQuery requirements in each plugin/theme README
3. Add instructions for jQuery loading if not automated

---

## 6. Testing Strategy

After Phase 2 cleanup:

1. **Admin Interface** - Test all admin pages function correctly
2. **Public Pages** - Test all public pages render and function correctly
3. **AJAX Functionality** - Verify AJAX calls still work (should use Fetch API from Phase 1)
4. **Form Submissions** - Test form submissions work correctly
5. **Plugin Functionality** - Test ControlD plugin functionality
6. **Theme Rendering** - Test all theme options render correctly

---

## 7. Rollback Plan

If issues occur:
1. Restore jQuery CDN in page templates (3 files from Step 1)
2. This will restore global jQuery loading to the application
3. Phase 1 code conversions to vanilla JavaScript are still valid

---

## 8. Success Metrics

After Phase 2 completion, verify:

1. ✅ Zero jQuery in main application files (except plugins/themes that require it)
2. ✅ All admin pages still function correctly without global jQuery
3. ✅ All public pages still function correctly without global jQuery
4. ✅ AJAX functionality works via Fetch API from Phase 1
5. ✅ Form validation still works
6. ✅ Plugin/theme jQuery functionality documented
7. ✅ Page load time improvement (no unnecessary jQuery load)

---

## 9. Related Specifications

- **Phase 1 Migration:** `/specs/implemented/remove_jquery_dependency.md`
- **Phase 0 (Select2):** `/specs/implemented/replace_select2_with_native_dropdown.md`
- **Project Guide:** `/CLAUDE.md`
- **FormWriter Documentation:** `/docs/formwriter.md`

---

## 10. Notes

- jQuery-loader.js is kept as it provides conditional jQuery loading for plugins/themes that need it
- FormWriter V2 visibility_rules feature eliminates need for custom JavaScript in most cases
- All vanilla JavaScript conversions from Phase 1 remain unchanged and functional
- Themes and plugins are responsible for jQuery loading after Phase 2
