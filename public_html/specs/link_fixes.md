# Link Fixes for Pure PHP Routing

## Overview

This document provides step-by-step instructions to fix all .php links found in the codebase before deploying the Pure PHP routing system. **All 23 high priority links must be fixed** before deployment to prevent broken user-facing URLs.

## Summary of Required Fixes

- **High Priority (Must Fix)**: 23 links - User-facing URLs that will break if not converted
- **Medium Priority (Should Fix)**: 2 links - Backend/AJAX calls for consistency
- **Low Priority (Review)**: 0 links - Internal references

## HIGH PRIORITY FIXES - Must Complete Before Deployment

### 1. Admin User Phone/Address Management Links

**File**: `adm/admin_user.php`

#### Fix 1: Phone Number Edit Links (Lines 390, 394)

**Current Code (Line 390):**
```php
echo 'Phone: '.$phone_number->get_phone_string() . ' [<a class="sortlink" href="/admin/admin_phone_edit.php?phn_phone_number_id='. $phone_number->key. '&usr_user_id='. $user->key . '">edit</a>]<br />';
```

**Fixed Code:**
```php
echo 'Phone: '.$phone_number->get_phone_string() . ' [<a class="sortlink" href="/admin/admin_phone_edit?phn_phone_number_id='. $phone_number->key. '&usr_user_id='. $user->key . '">edit</a>]<br />';
```

**Current Code (Line 394):**
```php
echo ' [<a class="sortlink" href="/admin/admin_phone_edit.php?usr_user_id='. $user->key . '">Add Phone Number</a>]<br />';
```

**Fixed Code:**
```php
echo ' [<a class="sortlink" href="/admin/admin_phone_edit?usr_user_id='. $user->key . '">Add Phone Number</a>]<br />';
```

#### Fix 2: Address Edit Links (Lines 404, 411)

**Current Code (Line 404):**
```php
echo 'Address: '.$address->get_address_string(' ') . ' [<a class="sortlink" href="/admin/admin_address_edit.php?usa_address_id='. $address->key .'">edit</a>]<br />' ;
```

**Fixed Code:**
```php
echo 'Address: '.$address->get_address_string(' ') . ' [<a class="sortlink" href="/admin/admin_address_edit?usa_address_id='. $address->key .'">edit</a>]<br />' ;
```

**Current Code (Line 411):**
```php
echo ' [<a class="sortlink" href="/admin/admin_address_edit.php?usr_user_id='. $user->key . '">Add address</a>]<br />';
```

**Fixed Code:**
```php
echo ' [<a class="sortlink" href="/admin/admin_address_edit?usr_user_id='. $user->key . '">Add address</a>]<br />';
```

#### Fix 3: User Profile Phone Edit Link (Line 700)

**Current Code:**
```php
array_push($rowvalues, $phone_number->get_phone_string() . '[<a class="sortlink" href="phone_numbers_edit.php?phn_phone_number_id='. $phone_number->key. '&usr_user_id='. $user->key . '">edit</a>]');
```

**Fixed Code:**
```php
array_push($rowvalues, $phone_number->get_phone_string() . '[<a class="sortlink" href="phone_numbers_edit?phn_phone_number_id='. $phone_number->key. '&usr_user_id='. $user->key . '">edit</a>]');
```

### 2. Registration Logic Password Reset Link

**File**: `logic/register_logic.php`

#### Fix 4: Password Reset Link (Line 118)

**Current Code:**
```php
check the email you entered or <a href="/password-reset-1.php">click here</a> if you forgot
```

**Fixed Code:**
```php
check the email you entered or <a href="/password-reset-1">click here</a> if you forgot
```

### 3. Utility Scripts Form Actions

**File**: `utils/upload_csv_step1.php`

#### Fix 5: CSV Upload Form Action (Line 85)

**Current Code:**
```php
printf ('<form method="post" class="form" action="upload_csv_step2.php%s" accept-charset="ISO-8859-1">', $getvars);
```

**Fixed Code:**
```php
printf ('<form method="post" class="form" action="upload_csv_step2%s" accept-charset="ISO-8859-1">', $getvars);
```

### 4. Test Suite Navigation Links

**File**: `tests/integration/phpmailer_test.php`

#### Fix 6: PHPMailer Test Form (Line 562)

**Current Code:**
```php
<form name="phpmailer_unit" action="phpmailer_test.php" method="get">
```

**Fixed Code:**
```php
<form name="phpmailer_unit" action="phpmailer_test" method="get">
```

**File**: `tests/models/run_multi.php`

#### Fix 7: Test Navigation Link (Line 142)

**Current Code:**
```php
echo "<li><strong>Regular tests + Multi tests:</strong> <a href='run_all.php?test_multi=1'>run_all.php?test_multi=1</a></li>\n";
```

**Fixed Code:**
```php
echo "<li><strong>Regular tests + Multi tests:</strong> <a href='run_all?test_multi=1'>run_all?test_multi=1</a></li>\n";
```

**File**: `tests/models/index.php`

#### Fix 8-13: Test Index Navigation (Lines 76, 77, 86, 87, 95)

**Current Code (Line 76):**
```php
<a href="run_all.php">Run All Single Tests</a>
```

**Fixed Code:**
```php
<a href="run_all">Run All Single Tests</a>
```

**Current Code (Line 77):**
```php
<a href="run_all.php?verbose=1" class="secondary">Run with Verbose Output</a>
```

**Fixed Code:**
```php
<a href="run_all?verbose=1" class="secondary">Run with Verbose Output</a>
```

**Current Code (Line 86):**
```php
<a href="run_multi.php">Run All Multi Tests</a>
```

**Fixed Code:**
```php
<a href="run_multi">Run All Multi Tests</a>
```

**Current Code (Line 87):**
```php
<a href="run_multi.php?verbose=1" class="secondary">Run with Verbose Output</a>
```

**Fixed Code:**
```php
<a href="run_multi?verbose=1" class="secondary">Run with Verbose Output</a>
```

**Current Code (Line 95):**
```php
<a href="run_all.php?test_multi=1">Single + Multi Tests</a>
```

**Fixed Code:**
```php
<a href="run_all?test_multi=1">Single + Multi Tests</a>
```

**File**: `tests/models/run_all.php`

#### Fix 14: Multi Test Link (Line 22)

**Current Code:**
```php
echo '<p><em>Running single model tests (CRUD, validation, constraints). For Multi class tests, use <a href="run_multi.php">run_multi.php</a></em></p><br>';
```

**Fixed Code:**
```php
echo '<p><em>Running single model tests (CRUD, validation, constraints). For Multi class tests, use <a href="run_multi">run_multi</a></em></p><br>';
```

### 5. Theme-Specific Links

**File**: `theme/sassa/views/profile/subscription_edit.php`

#### Fix 15: Mail Form Action (Line 208)

**Current Code:**
```php
<form action="mail.php" method="POST" class="consultation-form">
```

**Fixed Code:**
```php
<form action="mail" method="POST" class="consultation-form">
```

**File**: `theme/tailwind/logic/register_logic.php`

#### Fix 16: Password Reset Link (Line 120)

**Current Code:**
```php
check the email you entered or <a href="/password-reset-1.php">click here</a> if you forgot
```

**Fixed Code:**
```php
check the email you entered or <a href="/password-reset-1">click here</a> if you forgot
```

### 6. Utility Script Examples (Can be ignored - these are just documentation)

**Files**: `utils/find_php_links.php` (Lines 482, 483, 484)

These are just example code in the PHP link scanner documentation and don't need fixing as they're not actual functional links.

## MEDIUM PRIORITY FIXES - Recommended for Consistency

### 1. AJAX Call Examples

**File**: `utils/find_php_links.php`

#### Fix 17: AJAX Example (Line 485)

This is just documentation in the link scanner - can be ignored.

**File**: `utils/scratch.php`

#### Fix 18: AJAX URL (Line 327)

**Current Code:**
```javascript
url: "ajax.php",
```

**Fixed Code:**
```javascript
url: "ajax",
```

## Implementation Checklist

### Pre-Implementation

- [ ] **Backup your codebase** before making any changes
- [ ] **Test current functionality** to establish baseline
- [ ] **Set up development environment** for testing changes

### Phase 1: Critical Admin Links (Fixes 1-3)

- [ ] Fix `adm/admin_user.php` phone edit links (lines 390, 394)
- [ ] Fix `adm/admin_user.php` address edit links (lines 404, 411)  
- [ ] Fix `adm/admin_user.php` user profile phone edit (line 700)
- [ ] **Test admin user management functionality**

### Phase 2: Registration & Authentication (Fix 4, 16)

- [ ] Fix `logic/register_logic.php` password reset link (line 118)
- [ ] Fix `theme/tailwind/logic/register_logic.php` password reset link (line 120)
- [ ] **Test registration and password reset flows**

### Phase 3: Utility Scripts (Fix 5, 6)

- [ ] Fix `utils/upload_csv_step1.php` form action (line 85)
- [ ] Fix `tests/integration/phpmailer_test.php` form action (line 562)
- [ ] **Test CSV upload functionality**
- [ ] **Test PHPMailer test suite**

### Phase 4: Test Suite Navigation (Fixes 7-14)

- [ ] Fix all test suite navigation links in `tests/models/` directory
- [ ] **Verify test suite can navigate between different test runners**

### Phase 5: Theme-Specific Links (Fix 15)

- [ ] Fix `theme/sassa/views/profile/subscription_edit.php` mail form (line 208)
- [ ] **Test subscription form submission in Sassa theme**

### Phase 6: Medium Priority (Fix 18)

- [ ] Fix `utils/scratch.php` AJAX URL (line 327)
- [ ] **Test any functionality that uses scratch.php**

### Phase 7: Final Validation

- [ ] **Run the PHP link scanner again** to verify all fixes
- [ ] **Test critical user paths**: registration, login, admin functions
- [ ] **Deploy Pure PHP routing system**
- [ ] **Monitor for any missed links** in error logs

## Testing Guidelines

### After Each Phase

1. **Functional Testing**
   - Navigate to each changed page
   - Click each modified link
   - Submit each modified form
   - Verify expected behavior

2. **Error Monitoring**
   - Check Apache error logs for 404 errors
   - Monitor application error logs
   - Test with browser developer tools open

3. **Cross-Theme Testing**
   - Test functionality in all active themes
   - Verify theme-specific fixes work correctly

### Rollback Plan

If issues occur:

1. **Immediate Issues**
   ```bash
   # Restore backup
   cp -r /path/to/backup/* /var/www/html/joinerytest/public_html/
   ```

2. **Identify Problem**
   - Check which specific link is causing issues
   - Revert just that change temporarily
   - Fix and test in isolation

3. **Incremental Re-deployment**
   - Apply fixes in smaller batches
   - Test each batch thoroughly before proceeding

## Expected Impact

### Before Pure PHP Routing

- Users can access both `/login.php` and `/login`
- Inconsistent URL formats throughout application
- Some complexity in Apache configuration

### After Pure PHP Routing + Link Fixes

- Clean URLs only: `/login`, `/admin/users`, `/password-reset-1`
- Consistent user experience
- Simplified Apache configuration
- Better SEO and user-friendly URLs

## Verification Command

After completing all fixes, run the link scanner again:

```bash
php utils/find_php_links.php
```

**Expected Result**: 0 high priority links, 0 medium priority links

This confirms your codebase is ready for Pure PHP routing deployment.

## Notes

- **Query Parameters**: Links with query parameters (like `?id=123`) keep their parameters - only the `.php` extension is removed
- **Relative vs Absolute**: Both relative (`admin.php`) and absolute (`/admin.php`) links need the same fix
- **Form Actions**: Both `action=""` attributes and `<form>` duplicate matches are the same fix
- **Testing Critical**: Each change should be tested as broken links will result in 404 errors for users