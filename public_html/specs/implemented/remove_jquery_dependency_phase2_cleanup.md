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

**Themes with NO jQuery Dependency (After FormWriter V2 Migration):**
- Default Theme - FormWriter.php validation error styling now handled by FormWriter V2 (no jQuery needed)
- Devon & Jerry Theme - FormWriter.php validation error styling now handled by FormWriter V2 (no jQuery needed)
- Zouk Philly Theme - FormWriter.php validation error styling now handled by FormWriter V2 (no jQuery needed)
- Galactic Tribune
- Falcon
- Plugin
- Zouk Room

**Note:** FormWriter V2 classes (`FormWriterV2Base`, `FormWriterV2Bootstrap`, `FormWriterV2Tailwind`) handle all validation error display with pure HTML/CSS. The `is-invalid` CSS class and `.invalid-feedback` divs are added directly to field HTML without requiring jQuery. This eliminates FormWriter jQuery usage entirely once V2 migration is complete.

---

## 3. Phase 2 - Cleanup Tasks

### 3.1 Remove jQuery CDN from Page Templates

Remove jQuery script tags that load jQuery globally on every page. These appear in the page header includes.

**Files to Modify:**

1. **`/includes/PublicPageFalcon.php`** ✅ DONE
   - Remove jQuery 3.7.1 CDN script tag
   - This jQuery loads on all public pages using Falcon theme

2. **`/includes/PublicPageTailwind.php`** ⚠️ SKIPPED - Tailwind Theme Requires jQuery
   - **DO NOT REMOVE** - Tailwind theme inherently requires jQuery for its menu interactions and DOM manipulation
   - jQuery 3.7.1 CDN remains at line 184
   - Tailwind uses jQuery for interactive components and will be kept as-is

3. **`/includes/AdminPage-uikit3.php`** ✅ DONE
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

## 4. Theme and Plugin jQuery Migration Guide

After Phase 1 (FormWriter V2 migration) and Phase 2 cleanup, ControlD plugin, Canvas theme, and Tailwind theme have jQuery requirements.

**Themes and Plugins After Migration:**
- ControlD Plugin: Must load jQuery independently (extensive jQuery usage) ✅ DONE - has own jQuery loading
- Canvas Theme: Migrated to vanilla JavaScript ✅ DONE
- Tailwind Theme: Kept jQuery loading (inherent theme requirement) ✅ DONE - jQuery remains active
- All other themes: jQuery eliminated automatically by FormWriter V2

**Legacy FormWriter (V1) Note:** If V1 FormWriter is still in use anywhere, it requires jQuery only for Trumbowyg editor (not validation).

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
Convert to vanilla JavaScript (preferred approach)

#### Canvas Theme - `views/cart.php` - Prevent Duplicate Form Submissions

**Location:** `/theme/canvas/views/cart.php` Lines 393-415

**Current jQuery Code:**
```javascript
$(document).ready(function() {
	// Disable all submit buttons after first click to prevent duplicate submissions
	$('form').on('submit', function() {
		var $form = $(this);
		var $submitButtons = $form.find('button[type="submit"], input[type="submit"]');

		// Disable buttons and show loading state
		$submitButtons.prop('disabled', true);
		$submitButtons.each(function() {
			var $btn = $(this);
			$btn.data('original-text', $btn.html());
			$btn.html('<span class="spinner-border spinner-border-sm me-2"></span>Processing...');
		});

		// Re-enable after 10 seconds as failsafe (in case of network issues)
		setTimeout(function() {
			$submitButtons.prop('disabled', false);
			$submitButtons.each(function() {
				var $btn = $(this);
				if ($btn.data('original-text')) {
					$btn.html($btn.data('original-text'));
				}
			});
		}, 10000);
	});
});
```

**Vanilla JavaScript Replacement:**
```javascript
document.addEventListener('DOMContentLoaded', function() {
	// Disable all submit buttons after first click to prevent duplicate submissions
	const forms = document.querySelectorAll('form');

	forms.forEach(form => {
		form.addEventListener('submit', function() {
			const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

			// Disable buttons and show loading state
			submitButtons.forEach(btn => {
				btn.disabled = true;
				btn.dataset.originalText = btn.innerHTML;
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
			});

			// Re-enable after 10 seconds as failsafe (in case of network issues)
			setTimeout(function() {
				submitButtons.forEach(btn => {
					btn.disabled = false;
					if (btn.dataset.originalText) {
						btn.innerHTML = btn.dataset.originalText;
					}
				});
			}, 10000);
		});
	});
});
```

**Key Changes:**
- `$(document).ready()` → `document.addEventListener('DOMContentLoaded', ...)`
- `$('form').on('submit', ...)` → `form.addEventListener('submit', ...)`
- `$(this)` → `form` (from callback parameter)
- `$form.find()` → `form.querySelectorAll()`
- `.prop('disabled', true)` → `.disabled = true`
- `.each(function() { var $btn = $(this); ... })` → `.forEach(btn => ...)`
- `$btn.data('key')` → `btn.dataset.key`
- `$btn.html()` → `btn.innerHTML`

**Testing:**
- [ ] Load cart.php and submit a form multiple times rapidly
- [ ] Verify buttons are disabled after first click
- [ ] Verify loading state displays correctly
- [ ] Verify buttons re-enable after 10 seconds

---

#### Canvas Theme - `views/post.php` - Comment Toggle with Animation

**Location:** `/theme/canvas/views/post.php` Lines 192-197

**Current jQuery Code:**
```javascript
$(document).ready(function(){
	$('.commentbutton').click(function(){
		var cid = $(this).attr('id');
		$('#' + cid + 'container').toggle(500);
	});
});
```

**Vanilla JavaScript Replacement:**
```javascript
document.addEventListener('DOMContentLoaded', function() {
	const commentButtons = document.querySelectorAll('.commentbutton');

	commentButtons.forEach(btn => {
		btn.addEventListener('click', function() {
			const cid = this.id;
			const container = document.getElementById(cid + 'container');

			if (container) {
				container.classList.toggle('d-none');
			}
		});
	});
});
```

**CSS to Add:**
Add this to your theme CSS file to provide the animation transition:
```css
/* Comment container animation */
.comment-container {
	transition: opacity 0.5s ease-in-out;
	opacity: 1;
}

.comment-container.d-none {
	opacity: 0;
	display: none !important;
}
```

**Key Changes:**
- `$(document).ready()` → `document.addEventListener('DOMContentLoaded', ...)`
- `$('.commentbutton').click()` → `.querySelectorAll()` + `.addEventListener('click', ...)`
- `$(this).attr('id')` → `this.id`
- `$('#id').toggle(500)` → `.classList.toggle()` with CSS transitions

**Testing:**
- [ ] Load post.php and click comment buttons
- [ ] Verify containers toggle visibility correctly
- [ ] Verify animation displays smoothly
- [ ] Verify multiple toggles work correctly

### 4.3 Tailwind Theme - No Action Required ✅

**Status:** KEPT AS-IS with jQuery

Tailwind theme inherently requires jQuery for its interactive components:
- `views/events.php` - Category selector navigation with redirect (uses jQuery `$(location).attr`)
- `views/cart.php` - Form submission handling and field visibility

**Decision:** Tailwind theme continues to use jQuery as it is a core dependency of the theme design. The jQuery 3.7.1 CDN remains loaded in `/includes/PublicPageTailwind.php` at line 184. No migration is required for this theme.

**Migration Details Below (For Reference Only - Not Applied):**

#### Tailwind Theme - `views/events.php` - Category Selector Navigation with Redirect

**Location:** `/theme/tailwind/views/events.php` Lines 25-34

**Current jQuery Code:**
```javascript
$(document).ready(function() {
	$('#tab_select').change(function() {
		<?php
		foreach($page_vars['tab_menus'] as $id => $name){
			?>
			if($('#tab_select').val() == "<?php echo htmlspecialchars($name); ?>"){
				$(location).attr("href","/events?type=<?php echo htmlspecialchars($id); ?>");
			}
			<?php
		}
		?>
	});
});
```

**Vanilla JavaScript Replacement:**
```javascript
document.addEventListener('DOMContentLoaded', function() {
	const tabSelect = document.getElementById('tab_select');

	if (tabSelect) {
		tabSelect.addEventListener('change', function() {
			const selectedValue = this.value;
			<?php
			foreach($page_vars['tab_menus'] as $id => $name){
				?>
				if(selectedValue == "<?php echo htmlspecialchars($name); ?>"){
					window.location.href = "/events?type=<?php echo htmlspecialchars($id); ?>";
				}
				<?php
			}
			?>
		});
	}
});
```

**Key Changes:**
- `$(document).ready()` → `document.addEventListener('DOMContentLoaded', ...)`
- `$('#tab_select').change()` → `getElementById().addEventListener('change', ...)`
- `$(this).val()` → `this.value`
- `$(location).attr("href", url)` → `window.location.href = url`
- `$('#id').val()` → `document.getElementById('id').value`

**Testing:**
- [ ] Load events.php with category selector
- [ ] Verify dropdown renders correctly
- [ ] Verify selecting a category redirects to correct URL
- [ ] Verify URL parameter matches selected category
- [ ] Test all categories in dropdown

---

#### Tailwind Theme - `views/cart.php` - Prevent Duplicate Form Submissions and Field Visibility

**Location:** `/theme/tailwind/views/cart.php` Lines 120-128 and 297-315

**Current jQuery Code (Field Visibility):**
```javascript
$(document).ready(function() {
	$("#usr_first_name").focus();
	$('#new_billing').hide();
	$('#existing_billing_email').change(function () {
		if ($('#existing_billing_email option:selected').text() == 'A different person') {
			$('#new_billing').show();
		}
		else $('#new_billing').hide(); // hide div if value is not "custom"
	});
});
```

**Vanilla JavaScript Replacement (Field Visibility):**
```javascript
document.addEventListener('DOMContentLoaded', function() {
	const usrFirstName = document.getElementById('usr_first_name');
	const newBilling = document.getElementById('new_billing');
	const existingBillingEmail = document.getElementById('existing_billing_email');

	// Set initial focus and hide new_billing
	if (usrFirstName) {
		usrFirstName.focus();
	}
	if (newBilling) {
		newBilling.style.display = 'none';
	}

	// Handle change event
	if (existingBillingEmail) {
		existingBillingEmail.addEventListener('change', function() {
			const selectedOption = this.options[this.selectedIndex];
			const selectedText = selectedOption.textContent;

			if (newBilling) {
				newBilling.style.display = selectedText == 'A different person' ? 'block' : 'none';
			}
		});
	}
});
```

**Current jQuery Code (Form Submission Prevention):**
```javascript
$(document).ready(function() {
	$('form').on('submit', function() {
		var $form = $(this);
		var $submitButtons = $form.find('button[type="submit"], input[type="submit"]');

		// Disable buttons and show loading state
		$submitButtons.prop('disabled', true);
		$submitButtons.each(function() {
			var $btn = $(this);
			$btn.data('original-text', $btn.html());
			$btn.html('<span class="spinner-border spinner-border-sm me-2"></span>Processing...');
		});

		// Re-enable after 10 seconds as failsafe
		setTimeout(function() {
			$submitButtons.prop('disabled', false);
			$submitButtons.each(function() {
				var $btn = $(this);
				if ($btn.data('original-text')) {
					$btn.html($btn.data('original-text'));
				}
			});
		}, 10000);
	});
});
```

**Vanilla JavaScript Replacement (Form Submission Prevention):**
```javascript
document.addEventListener('DOMContentLoaded', function() {
	const forms = document.querySelectorAll('form');

	forms.forEach(form => {
		form.addEventListener('submit', function() {
			const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

			// Disable buttons and show loading state
			submitButtons.forEach(btn => {
				btn.disabled = true;
				btn.dataset.originalText = btn.innerHTML;
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
			});

			// Re-enable after 10 seconds as failsafe
			setTimeout(function() {
				submitButtons.forEach(btn => {
					btn.disabled = false;
					if (btn.dataset.originalText) {
						btn.innerHTML = btn.dataset.originalText;
					}
				});
			}, 10000);
		});
	});
});
```

**Combined Complete Replacement:**
For Tailwind's cart.php, combine both features:
```javascript
document.addEventListener('DOMContentLoaded', function() {
	// ===== FIELD VISIBILITY =====
	const usrFirstName = document.getElementById('usr_first_name');
	const newBilling = document.getElementById('new_billing');
	const existingBillingEmail = document.getElementById('existing_billing_email');

	if (usrFirstName) {
		usrFirstName.focus();
	}
	if (newBilling) {
		newBilling.style.display = 'none';
	}

	if (existingBillingEmail) {
		existingBillingEmail.addEventListener('change', function() {
			const selectedText = this.options[this.selectedIndex].textContent;
			if (newBilling) {
				newBilling.style.display = selectedText == 'A different person' ? 'block' : 'none';
			}
		});
	}

	// ===== PREVENT DUPLICATE FORM SUBMISSIONS =====
	const forms = document.querySelectorAll('form');
	forms.forEach(form => {
		form.addEventListener('submit', function() {
			const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

			submitButtons.forEach(btn => {
				btn.disabled = true;
				btn.dataset.originalText = btn.innerHTML;
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
			});

			setTimeout(function() {
				submitButtons.forEach(btn => {
					btn.disabled = false;
					if (btn.dataset.originalText) {
						btn.innerHTML = btn.dataset.originalText;
					}
				});
			}, 10000);
		});
	});
});
```

**Key Changes:**
- `$(document).ready()` → `document.addEventListener('DOMContentLoaded', ...)`
- `$('#id').hide()` → `element.style.display = 'none'`
- `$('#id').show()` → `element.style.display = 'block'`
- `$('#existing_billing_email option:selected').text()` → `this.options[this.selectedIndex].textContent`
- `$('form').on('submit', ...)` → `form.addEventListener('submit', ...)`
- `.prop('disabled', true)` → `.disabled = true`
- `.data('key')` → `.dataset.key`
- `.each()` → `.forEach()`

**Testing:**
- [ ] Load cart.php in Tailwind theme
- [ ] Verify input fields focus correctly
- [ ] Verify "new billing" section hidden initially
- [ ] Select "A different person" in billing email dropdown
- [ ] Verify "new billing" section shows
- [ ] Select different option, verify section hides again
- [ ] Submit form and verify buttons disable
- [ ] Verify loading state displays
- [ ] Verify buttons re-enable after 10 seconds
- [ ] Test multiple form submissions rapidly

### 4.4 FormWriter jQuery Dependency - RESOLVED ✅

**Status:** No action required - FormWriter V2 eliminates this dependency

**Previous Issue (V1 FormWriter):**
- Default, Devon & Jerry, and Zouk Philly themes used jQuery in FormWriter.php for validation error styling
- Used `addClass/removeClass` for error containers

**Resolution:**
FormWriter V2 classes (`FormWriterV2Base`, `FormWriterV2Bootstrap`, `FormWriterV2Tailwind`) handle all validation error display with **pure HTML/CSS**, eliminating the need for jQuery entirely:

**How FormWriter V2 Works:**
```php
// FormWriter V2 automatically adds CSS classes to field HTML
if ($has_errors) {
    echo ' class="form-check-input is-invalid"';  // Pure HTML, no jQuery
}

// Error messages are displayed with simple HTML divs
echo '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
```

**Benefits:**
- ✅ No jQuery dependency
- ✅ No JavaScript needed for error styling
- ✅ CSS handles all visual feedback
- ✅ Works in all themes automatically

**Action Required:** None - FormWriter V2 migration already solves this problem

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
