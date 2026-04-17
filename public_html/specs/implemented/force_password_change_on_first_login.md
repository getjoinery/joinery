# Force Password Change on First Login

## Overview

Default admin users created during installation should be required to change their password on first login. This improves security by ensuring the well-known default password (`changeme123`) is never used in production.

## Implementation Approach

The simplest approach is to add the check to `SessionControl::check_permission()`, which is already called by all admin pages. This provides automatic blocking without needing complex middleware.

## Requirements

### 1. Database Changes

Add a new field to the `usr_users` table:

```
usr_force_password_change (boolean, default false)
```

### 2. Default Admin User

When the install SQL is generated (`create_install_sql.php`), set `usr_force_password_change = true` for the default admin user.

### 3. Access Control via check_permission()

Modify `SessionControl::check_permission()` to:

1. Check if the logged-in user has `usr_force_password_change = true`
2. If true, redirect to `/change-password-required`
3. This automatically blocks all admin pages since they all call `check_permission()`

### 4. Forced Password Change Page

Create a new page (`/change-password-required`) that:

- Does NOT call `check_permission()` (to avoid redirect loop)
- Verifies user is logged in via `SessionControl::is_logged_in()`
- Displays a form with:
  - New password field
  - Confirm new password field
- Validates:
  - Passwords match
  - New password is not empty
- On success:
  - Updates `usr_password` with the new hashed password
  - Sets `usr_force_password_change = false`
  - Redirects to admin dashboard

### 5. Welcome Page Update

Update `views/index.php` to show the default password (`changeme123`) since users need it to log in. The security is now handled by forcing the change after login.

## Files to Modify

1. **`data/users_class.php`** - Add `usr_force_password_change` to `$field_specifications`
2. **`utils/create_install_sql.php`** - Set flag true for default admin
3. **`includes/SessionControl.php`** - Add check in `check_permission()`
4. **`serve.php`** - Add route for password change page
5. **New: `views/change_password_required.php`** - The forced password change form
6. **New: `logic/change_password_required_logic.php`** - Handle form submission
7. **`views/index.php`** - Add default password to the credentials display

## User Experience

1. User installs Joinery
2. User navigates to site, sees welcome page with credentials: `admin@example.com` / `changeme123`
3. User clicks "Go to Admin Panel" and logs in
4. `check_permission()` detects forced password change flag, redirects to change page
5. User enters new password and confirms
6. User is redirected to admin dashboard

## Testing

- Fresh install: default admin should be forced to change password
- Existing users: should not be affected (flag defaults to false)
- After password change: user should have normal access
- Direct URL access to admin: redirects to password change page while flag is true
