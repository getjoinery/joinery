# Specification: Remove jQuery Dependency

**Status:** Phase 1 ✅ COMPLETE | Phase 2 Pending
**Priority:** High
**Estimated Effort:** 4-6 hours (Phase 1: 2-3 hours ✅ DONE, Phase 2: 2-3 hours)
**Date Created:** 2025-10-23
**Phase 1 Completed:** 2025-10-24

---

## 1. Overview

Remove jQuery as a global dependency from the codebase by:
1. **Replacing Select2** with native HTML5 `<input>` + `<datalist>` elements (~80 lines JavaScript, zero CSS)
2. **Loading jQuery conditionally** only when Trumbowyg rich text editor is used
3. **Removing jQuery from default page templates**
4. **Refactoring validation logic** to eliminate jQuery Validate dependencies
5. **Cleaning up jQuery files** from theme directories

The approach uses native HTML5 features - Select2 will be replaced with `<input>` + `<datalist>` which provides autocomplete functionality with zero custom CSS and minimal JavaScript.

---

## 2. Current State Analysis

### 2.1 jQuery Usage Map

**Global jQuery Loading Points:**
- `/includes/PublicPageFalcon.php` - jQuery 3.7.1 CDN (all public pages)
- `/includes/PublicPageTailwind.php` - jQuery 3.7.1 CDN (all public pages)
- `/includes/AdminPage-uikit3.php` - jQuery 3.4.1 CDN (all admin pages, loaded twice)
- `/theme/galactictribune/assets/js/jquery-3.4.1.min.js` - Bundled locally
- `/theme/default/assets/js/jquery-3.4.1.min.js` - Bundled locally
- `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js` - Bundled locally
- `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js` - Bundled locally

**jQuery Used For:**
- Select2 AJAX dropdown enhancement
- Trumbowyg rich text editor
- Form validation (jQuery Validate plugin - being phased out)
- Miscellaneous view file interactions (subscriptions.php)
- controld plugin initialization

### 2.2 Select2 Current Usage

**Files Using Select2:**
- `/includes/FormWriterBootstrap.php`
- `/includes/FormWriterHTML5.php`
- `/includes/FormWriterUIKit.php`
- `/includes/FormWriterTailwind.php`

**Features Currently Used:**
1. **AJAX Data Loading** - Remote endpoint loads options based on search
2. **Search/Filter** - User types 3+ characters to trigger AJAX
3. **Result Caching** - Client-side cache to avoid redundant requests
4. **Static Placeholder** - Always "None"
5. **Debouncing** - 250ms delay before AJAX request
6. **Standard Single Selection** - Not multi-select

**Features NOT Used:**
- Multi-select mode
- Tags mode
- Custom templates
- Pagination
- Customizable placeholders
- Event callbacks
- Formatting functions

**Initialization Code Pattern (identical across all FormWriter classes):**
```javascript
$(document).ready(function() {
  $("#[FIELD_ID]").select2({
    placeholder: "None",
    ajax: {
      url: "[AJAX_ENDPOINT_URL]",
      dataType: "json",
      delay: 250,
      processResults: function (data) {
        return { results: data };
      },
      minimumInputLength: 3,
      cache: true
    }
  });
});
```

**Expected AJAX Response Format:**
```json
[
  { "id": "value1", "text": "Display Text 1" },
  { "id": "value2", "text": "Display Text 2" }
]
```

### 2.3 Trumbowyg Rich Text Editor

- **File:** `/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js`
- **jQuery Dependency:** REQUIRED - Trumbowyg is a jQuery plugin
- **Current Approach:** jQuery loaded globally for all pages
- **Proposed Approach:** Load jQuery conditionally when Trumbowyg is initialized

### 2.4 Form Validation

**Current State:**
- Pure JavaScript `JoineryValidator` is the primary validation system
- Already independent of jQuery
- Form validation works through `set_validate()` which calls `JoineryValidation.init()`

**Status:**
- ✅ No jQuery dependency in validation layer
- ✅ JoineryValidator uses vanilla JS for error handling (classList, createElement, appendChild)
- ✅ Legacy `validate_style_info` properties in FormWriter classes removed (dead code)

### 2.5 Other jQuery Usage

**View Files:**
- `/views/profile/subscriptions.php` - Uses `$(document).ready()` and `$("#appointments").load()`

**Admin Pages:**
- `/adm/admin_settings_payments.php` - jQuery Validator custom methods for Stripe validation

**Plugins:**
- `/plugins/controld/assets/js/main.js` - Uses jQuery for plugin initialization

---

## 3. Implementation Phases

## PHASE 1: Replace Select2 with Custom Dropdown Component

### 3.1 Custom Vanilla JS Dropdown Component (PHASE 1)

**Create a custom dropdown component that replaces Select2 with equivalent functionality.**

**Requirements:**
- Works with existing `<select>` elements
- Support AJAX data loading with search
- Minimum 3 characters to trigger search
- Maintain client-side result caching
- 250ms debounce on search
- Zero custom CSS required
- No jQuery dependency

**Key Characteristics:**
- Inline JavaScript (~80 lines, only loaded when AJAX dropdown exists)
- Zero CSS needed - uses native HTML5 elements
- No separate files to load
- Works with existing select elements

**File Locations:**
- No new files needed! JavaScript is embedded inline in FormWriter output

### 3.2 Refactor FormWriter Classes for Custom Dropdown (PHASE 1)

**Update all FormWriter implementations to use custom dropdown instead of Select2.**

**Files to Modify:**
- `/includes/FormWriterHTML5.php` - Replace Select2 with custom dropdown
- `/includes/FormWriterBootstrap.php` - Replace Select2 with custom dropdown
- `/includes/FormWriterTailwind.php` - Replace Select2 with custom dropdown
- `/includes/FormWriterUIKit.php` - Replace Select2 with custom dropdown

**Changes in dropinput() method:**
- Replace Select2 CSS/JS includes with custom dropdown CSS/JS
- Replace Select2 initialization script with custom dropdown initialization
- Update script to call dropdown component instead of Select2

### 3.3 Test Custom Dropdown (PHASE 1)

**Verify custom dropdown works across all themes and AJAX endpoints.**

---

## PHASE 2: Remove Global jQuery Dependency

### 3.4 Conditional jQuery Loading for Trumbowyg (PHASE 2)

**Create a utility that loads jQuery only when Trumbowyg is initialized.**

**Implementation:**
- Add a function `ensureJQueryLoaded()` that:
  1. Checks if jQuery is already loaded (window.$)
  2. If not, dynamically loads jQuery 3.7.1 from CDN
  3. Waits for jQuery to fully load before proceeding
  4. Calls callback once jQuery is ready
- Modify FormWriter Trumbowyg initialization to call this function first
- Cache the jQuery loading promise to avoid loading multiple times

**File Locations:**
- Utility: `/assets/js/jquery-loader.js` (new)

### 3.5 Remove jQuery from Default Templates (PHASE 2)

**Remove jQuery CDN/script tags from all page template files.**

**Files to Modify:**
- `/includes/PublicPageFalcon.php` - Remove jQuery script tag
- `/includes/PublicPageTailwind.php` - Remove jQuery script tag
- `/includes/AdminPage-uikit3.php` - Remove both jQuery script tags
- Delete local jQuery files from theme directories

### 3.6 Clean Up jQuery Files (PHASE 2)

**Remove jQuery from themes and utilities.**

**Delete:**
- `/theme/galactictribune/assets/js/jquery-3.4.1.min.js`
- `/theme/default/assets/js/jquery-3.4.1.min.js`
- `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js`
- `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js`
- All theme jQuery Validate files
- Legacy jQuery references from utility files

### 3.7 Fix View Layer jQuery (PHASE 2)

**Remove jQuery usage from view templates.**

**Files to Modify:**
- `/views/profile/subscriptions.php` - Replace `$(document).ready()` and jQuery AJAX with fetch API

### 3.8 Update Admin and Utility Pages (PHASE 2)

**Refactor remaining jQuery dependencies.**

**Files to Modify:**
- `/adm/admin_settings_payments.php` - Replace jQuery Validator methods with JoineryValidator equivalents
- `/utils/scratch.php` - Remove jQuery examples or update to vanilla JS
- `/plugins/controld/assets/js/main.js` - Remove jQuery dependency or load jQuery conditionally

---

## 4. Implementation Tasks

### PHASE 1: Custom Dropdown Implementation

#### 4.1 Update FormWriter Classes for Inline AJAX Dropdown
- Modify FormWriter classes to output inline JavaScript for AJAX dropdowns:
  - Update `/includes/FormWriterHTML5.php`:
    - Remove Select2 CSS/JS includes
    - Modify dropinput() method to output inline JavaScript when `ajaxendpoint` is present
  - Update `/includes/FormWriterBootstrap.php` with same changes
  - Update `/includes/FormWriterTailwind.php` with same changes
  - Update `/includes/FormWriterUIKit.php` with same changes

Implementation details:
  - Replace Select2 CSS/JS includes with inline dropdown code
  - JavaScript only outputs when `ajaxendpoint` parameter is used
  - Uses native HTML5 `<input>` + `<datalist>` elements
  - AJAX data loading with search (3+ character minimum)
  - Client-side result caching
  - 250ms debounce on search
  - No CSS file needed

#### 4.2 Test AJAX Dropdown (PHASE 1)
- Test all dropdown AJAX endpoints with custom dropdown
- Comprehensive browser testing (Chrome, Firefox, Safari)
- Mobile device testing
- Test keyboard navigation and accessibility
- Verify dropdown functionality across all themes

---

### PHASE 2: Remove Global jQuery

#### 4.4 jQuery Conditional Loading
- Create jQuery loader utility (`/assets/js/jquery-loader.js`) that:
  - Checks if jQuery is already loaded
  - Dynamically loads jQuery 3.7.1 from CDN if needed
  - Returns promise for async handling
  - Caches to avoid loading multiple times
- Modify FormWriter to load jQuery before Trumbowyg initialization

#### 4.5 Remove jQuery from Templates
- Remove jQuery CDN script tag from `/includes/PublicPageFalcon.php`
- Remove jQuery CDN script tag from `/includes/PublicPageTailwind.php`
- Remove jQuery CDN script tags (appears twice) from `/includes/AdminPage-uikit3.php`
- Delete bundled jQuery files from theme directories:
  - `/theme/galactictribune/assets/js/jquery-3.4.1.min.js`
  - `/theme/default/assets/js/jquery-3.4.1.min.js`
  - `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js`
  - `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js`

#### 4.6 Fix View Layer jQuery
- Refactor `/views/profile/subscriptions.php`:
  - Replace `$(document).ready()` with vanilla JS
  - Replace `$("#appointments").load()` with fetch API
  - Update DOM manipulation to vanilla JS methods

#### 4.7 Clean Up jQuery References
- Update `/utils/api_example_js_create.php` to use fetch instead of jQuery AJAX
- Update `/utils/api_example_js_list.php` to use fetch instead of jQuery AJAX
- Update `/utils/api_example_js_single.php` to use fetch instead of jQuery AJAX
- Remove jQuery examples from `/utils/scratch.php`
- Update `/plugins/controld/assets/js/main.js` to remove jQuery dependency or load conditionally

#### 4.8 Testing (PHASE 2)
- Test Trumbowyg rich text editor with conditional jQuery loading
- Confirm jQuery is not loaded on pages without Trumbowyg
- Verify all previously jQuery-dependent features work

#### 4.9 Documentation
- Create API documentation for custom dropdown component in `/docs/`
- Update `/CLAUDE.md` with notes on conditional jQuery loading

---

## 4.10 Custom Dropdown Component - Inline JavaScript Implementation

### Inline JavaScript (loaded only for AJAX dropdowns)

The JavaScript is loaded **inline in the HTML** only when an AJAX dropdown is present (no separate file needed).

```javascript
// Inline script - only loaded when AJAX dropdown exists
(function() {
  class AjaxSearchSelect {
    constructor(selectEl, ajaxUrl) {
      this.select = selectEl;
      this.ajaxUrl = ajaxUrl;
      this.cache = {};
      this.debounceTimer = null;

      // Create and insert search input
      const input = document.createElement('input');
      input.type = 'text';
      input.className = selectEl.className;
      input.placeholder = 'Type to search...';

      // Create datalist
      const list = document.createElement('datalist');
      list.id = selectEl.id + '_list';
      input.setAttribute('list', list.id);

      // Hide select, insert input and datalist
      selectEl.style.display = 'none';
      selectEl.parentNode.insertBefore(input, selectEl);
      selectEl.parentNode.insertBefore(list, selectEl);

      this.input = input;
      this.list = list;
      this.data = [];

      // Set initial value
      if (selectEl.value) {
        input.value = selectEl.options[selectEl.selectedIndex].text;
      }

      // Event listeners
      input.addEventListener('input', (e) => this.search(e.target.value));
      input.addEventListener('change', (e) => {
        if (!e.target.value) {
          selectEl.value = '';
          selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    }

    search(query) {
      clearTimeout(this.debounceTimer);

      if (query.length < 3) {
        this.list.innerHTML = '';
        this.data = [];
        return;
      }

      if (this.cache[query]) {
        this.updateList(this.cache[query]);
        return;
      }

      this.debounceTimer = setTimeout(() => {
        fetch(`${this.ajaxUrl}?q=${encodeURIComponent(query)}`)
          .then(r => r.json())
          .then(data => {
            this.cache[query] = data;
            this.updateList(data);
          });
      }, 250);
    }

    updateList(data) {
      this.data = data;
      this.list.innerHTML = '';
      data.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.text;
        opt.dataset.id = item.id;
        this.list.appendChild(opt);
      });
    }
  }

  // Initialize when DOM is ready
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[data-ajax-endpoint]').forEach(select => {
      new AjaxSearchSelect(select, select.dataset.ajaxEndpoint);
    });
  });
})();
```

### No CSS or separate files needed!

The implementation:
- Uses native HTML5 `<input>` + `<datalist>` elements
- Browser provides built-in dropdown UI and styling
- Zero CSS required
- JavaScript loaded inline only for AJAX dropdowns
- No separate JavaScript file to load

### Usage in FormWriter Classes

**Example: Update FormWriterBootstrap::dropinput() method**

```php
public function dropinput($field_id, $field_label, $field_value, $field_options,
                         $readonly = false, $required = false, $ajaxendpoint = '', $placeholder = '')
{
    // Only add data-ajax-endpoint if AJAX is needed
    $ajax_attr = $ajaxendpoint ? 'data-ajax-endpoint="' . htmlspecialchars($ajaxendpoint) . '"' : '';

    // Create standard select element
    $html = '<select id="' . $field_id . '" name="' . $field_id . '"
                    class="form-control" ' . $ajax_attr . '>';

    // Add options
    $html .= '<option value="">None</option>';
    if (is_array($field_options)) {
        foreach ($field_options as $value => $label) {
            $selected = ($value == $field_value) ? 'selected' : '';
            $html .= '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' .
                    htmlspecialchars($label) . '</option>';
        }
    }
    $html .= '</select>';

    // Include inline JavaScript only for AJAX dropdowns (no separate file!)
    if ($ajaxendpoint) {
        static $ajax_script_loaded = false;
        if (!$ajax_script_loaded) {
            $html .= <<<'JS'
<script>
(function() {
  class AjaxSearchSelect {
    constructor(selectEl, ajaxUrl) {
      this.select = selectEl;
      this.ajaxUrl = ajaxUrl;
      this.cache = {};
      this.debounceTimer = null;

      const input = document.createElement('input');
      input.type = 'text';
      input.className = selectEl.className;
      input.placeholder = 'Type to search...';

      const list = document.createElement('datalist');
      list.id = selectEl.id + '_list';
      input.setAttribute('list', list.id);

      selectEl.style.display = 'none';
      selectEl.parentNode.insertBefore(input, selectEl);
      selectEl.parentNode.insertBefore(list, selectEl);

      this.input = input;
      this.list = list;
      this.data = [];

      if (selectEl.value) {
        input.value = selectEl.options[selectEl.selectedIndex].text;
      }

      input.addEventListener('input', (e) => this.search(e.target.value));
      input.addEventListener('change', (e) => {
        if (!e.target.value) {
          selectEl.value = '';
          selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    }

    search(query) {
      clearTimeout(this.debounceTimer);
      if (query.length < 3) {
        this.list.innerHTML = '';
        this.data = [];
        return;
      }

      if (this.cache[query]) {
        this.updateList(this.cache[query]);
        return;
      }

      this.debounceTimer = setTimeout(() => {
        fetch(this.ajaxUrl + '?q=' + encodeURIComponent(query))
          .then(r => r.json())
          .then(data => {
            this.cache[query] = data;
            this.updateList(data);
          });
      }, 250);
    }

    updateList(data) {
      this.data = data;
      this.list.innerHTML = '';
      data.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.text;
        opt.dataset.id = item.id;
        this.list.appendChild(opt);
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[data-ajax-endpoint]').forEach(select => {
      new AjaxSearchSelect(select, select.dataset.ajaxEndpoint);
    });
  });
})();
</script>
JS;
            $ajax_script_loaded = true;
        }
    }

    return $this->wrap_input($html, $field_id, $field_label, $required);
}
```

---

## 5. Success Criteria

### 5.1 Functional Requirements
- [ ] All form dropdown fields work identically to Select2 (AJAX, search, caching, keyboard nav)
- [ ] Trumbowyg rich text editor initializes and functions correctly
- [ ] jQuery is NOT loaded on pages without Trumbowyg
- [ ] jQuery loads automatically and transparently when Trumbowyg is needed
- [ ] All existing AJAX endpoints work with custom dropdown

### 5.2 Code Quality Requirements
- [ ] Zero jQuery usage in codebase except conditional loading for Trumbowyg
- [ ] Custom dropdown component has no external dependencies
- [ ] Custom dropdown uses semantic HTML (`<select>` element preserved)
- [ ] All FormWriter classes updated consistently
- [ ] No console errors in any browser

### 5.3 Performance Requirements
- [ ] Custom dropdown performs as well as or better than Select2
- [ ] Page load time improved due to no global jQuery
- [ ] AJAX requests use same debounce delay (250ms)
- [ ] Caching works efficiently

### 5.4 Compatibility Requirements
- [ ] Works with Bootstrap theme (PublicPageFalcon)
- [ ] Works with Tailwind theme (PublicPageTailwind)
- [ ] Works with UIKit admin interface (AdminPage-uikit3)
- [ ] Works with other installed themes
- [ ] Keyboard accessible (WCAG 2.1 Level AA)

### 5.5 Testing Requirements
- [ ] All forms pass manual testing
- [ ] All dropdown AJAX endpoints tested
- [ ] All themes tested with forms and dropdowns
- [ ] Rich text editor works on all pages with forms
- [ ] No memory leaks (jQuery loaded/unloaded correctly)
- [ ] Mobile/touch navigation works

---

## 6. Related Files

### 6.1 Files to Modify
- `/includes/PublicPageFalcon.php` - Remove jQuery CDN
- `/includes/PublicPageTailwind.php` - Remove jQuery CDN
- `/includes/AdminPage-uikit3.php` - Remove jQuery CDN
- `/includes/FormWriterHTML5.php` - Replace Select2 with custom dropdown
- `/includes/FormWriterBootstrap.php` - Replace Select2 with custom dropdown
- `/includes/FormWriterTailwind.php` - Replace Select2 with custom dropdown
- `/includes/FormWriterUIKit.php` - Replace Select2 with custom dropdown
- `/views/profile/subscriptions.php` - Replace jQuery with fetch API
- `/plugins/controld/assets/js/main.js` - Remove jQuery dependency
- `/utils/api_example_js_create.php` - Replace jQuery AJAX with fetch
- `/utils/api_example_js_list.php` - Replace jQuery AJAX with fetch
- `/utils/api_example_js_single.php` - Replace jQuery AJAX with fetch
- `/utils/scratch.php` - Remove jQuery examples

### 6.2 Files Already Modified
- `/includes/FormWriterBase.php` - ✅ Removed validate_style_info (dead code)
- `/includes/FormWriterHTML5.php` - ✅ Removed validate_style_info (dead code)
- `/includes/FormWriterBootstrap.php` - ✅ Removed validate_style_info (dead code)
- `/includes/FormWriterUIKit.php` - ✅ Removed validate_style_info (dead code)

### 6.3 Files to Create
- `/assets/js/jquery-loader.js` - Conditional jQuery loader (for Phase 2)
- No other files needed! AJAX dropdown uses inline JavaScript

### 6.4 Files to Delete
- `/theme/galactictribune/assets/js/jquery-3.4.1.min.js`
- `/theme/default/assets/js/jquery-3.4.1.min.js`
- `/theme/devonandjerry/assets/js/jquery-3.4.1.min.js`
- `/theme/zoukphilly/assets/js/jquery-3.4.1.min.js`
- Multiple jQuery Validate plugin references in theme directories

### 6.5 Related Documentation
- `/CLAUDE.md` - Project instructions
- `/docs/plugin_developer_guide.md` - Plugin architecture (for controld plugin)
- `/docs/admin_pages.md` - Admin page documentation

---

## 7. Implementation Notes

### 7.1 Custom Dropdown Design Considerations

The custom dropdown component should:
1. **Preserve native `<select>` element** - All form values should come from the hidden select, not from JavaScript state
2. **Apply theme-specific CSS** - Bootstrap, UIKit, and Tailwind all have different class conventions
3. **Support data attributes for configuration** - Allow markup-based initialization without JavaScript
4. **Cache results efficiently** - Store results in a Map to avoid AJAX duplication
5. **Handle edge cases**:
   - Rapid typing (debounce prevents requests)
   - Network errors (graceful fallback)
   - Empty results (show "no results" message)
   - Mobile keyboards (work with touch events)

### 7.2 jQuery Conditional Loading

The jQuery loader should:
1. **Check if jQuery is available** - `window.$ && window.jQuery`
2. **Load from same CDN as before** - jQuery 3.7.1 from CDN.jsdelivr.net
3. **Handle timing** - Return a Promise to ensure jQuery is ready before use
4. **Cache the promise** - Only load once even if multiple components request it
5. **Fail gracefully** - Log error if jQuery fails to load

### 7.3 Validation Migration

JoineryValidator already exists, so:
1. **Verify it covers all needs** - Check if custom Stripe validation exists
2. **Maintain configuration compatibility** - If jQuery Validate config exists, document how it maps to JoineryValidator
3. **Support both temporarily** - During transition, both can coexist without conflict

### 7.4 Testing Strategy

Test in this order:
1. **Unit tests** - Custom dropdown in isolation
2. **Integration tests** - Dropdown in FormWriter context
3. **End-to-end tests** - Full forms across all themes
4. **Performance tests** - Verify no degradation
5. **Accessibility tests** - Keyboard navigation, screen readers

### 7.5 Backward Compatibility

This is a breaking change because:
1. Select2 API disappears - Any custom JavaScript that called Select2 will break
2. jQuery no longer globally available - Custom scripts relying on `$` won't work
3. Validation configuration changes - jQuery Validate won't initialize

**Mitigation:**
- Search entire codebase for custom Select2 usage before implementation
- Document breaking changes in CLAUDE.md
- Provide migration guide for plugins/themes using jQuery

---

## 8. Risk Assessment

### 8.1 High Risk
- **Custom dropdown not feature-complete** - Could break existing functionality
  - *Mitigation*: Comprehensive testing, direct comparison with Select2
- **Validation not working correctly** - Forms might accept invalid data
  - *Mitigation*: Verify JoineryValidator capabilities before refactoring

### 8.2 Medium Risk
- **Theme incompatibility** - Custom dropdown styling breaks in unexpected themes
  - *Mitigation*: Test all installed themes
- **AJAX endpoint changes** - Response format might differ between endpoints
  - *Mitigation*: Audit all AJAX endpoints before implementation

### 8.3 Low Risk
- **jQuery conflicts** - Multiple jQuery loads could cause issues
  - *Mitigation*: Load script checks version and waits for ready state
- **Mobile compatibility** - Touch events might not work correctly
  - *Mitigation*: Test on actual mobile devices

---

## 9. Success Metrics

After implementation is complete, verify:
1. **Zero jQuery references** in main codebase (except jquery-loader.js)
2. **Page load time improvement** - Measure with and without change
3. **Form functionality parity** - All existing forms work identically
4. **Test coverage** - 100% of dropdown functionality tested
5. **Browser compatibility** - Works on all supported browsers
6. **Accessibility** - Keyboard navigation and screen reader support
7. **Performance** - AJAX performance equal to or better than Select2

---

## 10. Future Considerations

After jQuery removal:
1. **Evaluate other jQuery plugins** - Check if any other plugins require jQuery
2. **Monitor Trumbowyg updates** - Watch for jQuery-free version
3. **Consider replacing rich text editor** - TinyMCE (Canvas theme) doesn't need jQuery
4. **Modernize remaining JavaScript** - Consider ES6 modules and bundling
5. **Performance monitoring** - Track metrics to ensure improvements are maintained
