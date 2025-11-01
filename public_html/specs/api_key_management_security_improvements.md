# Specification: API Key Management Security Improvements

## Overview

Improve the API Key management interface to follow security best practices by properly handling the plain text secret key that is only available at creation time.

## Current Issues

### Issue 1: Secret Key Not Shown After Creation
**File:** `/adm/logic/admin_api_key_edit_logic.php` (lines 45-50)

When a new API key is created:
```php
if(!$api_key->key){
    $public_key = 'public_'.LibraryFunctions::random_string(16);
    $secret_key = 'secret_'.LibraryFunctions::random_string(16);  // ← Plain text generated
    $api_key->set('apk_public_key', $public_key);
    $api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_key));  // ← Immediately hashed
}
// ... then redirects to admin_api_key.php
```

**Problem:** The plain text `$secret_key` is generated but never displayed to the user. After the redirect, only the hashed version is available in the database, making the secret key permanently lost.

**Impact:** Users cannot use newly created API keys because they don't know the secret.

### Issue 2: Hashed Secret Displayed on Detail Page
**File:** `/adm/admin_api_key.php` (line 51)

```php
echo '<strong>Secret key:</strong> '. $api_key->get('apk_secret_key').'<br>';
```

**Problem:** This displays the bcrypt hash (e.g., `$P$BHB4InuDnNuaAX4pemybgwzJBAxOCb/`), which:
- Is useless for API authentication
- Confuses users who might try to use it
- Suggests the secret can be retrieved (it cannot)

**Impact:** User confusion and inability to use the API.

### Issue 3: No Regeneration Capability
**Current behavior:** If a user loses their secret key, there's no way to generate a new one without deleting and recreating the entire API key (losing all settings and history).

**Impact:** Poor user experience and potential data loss.

## Requirements

### Functional Requirements

1. **Show Plain Text Secret on Creation**
   - Display the plain text secret key immediately after creation
   - Show a prominent warning that it will only be shown once
   - Prevent redirect until user acknowledges they've saved the secret

2. **Hide Secret on Detail Page**
   - Remove the hashed secret display from the detail page
   - Replace with informative message about secret security
   - Show when the key was created or last regenerated

3. **Add Secret Regeneration**
   - Add "Regenerate Secret" button on detail/edit page
   - Generate new random secret, hash and store it
   - Display the new plain text secret with same one-time warning
   - Log the regeneration event with timestamp

4. **Improve User Education**
   - Add help text explaining what secret keys are
   - Provide guidance on secure storage
   - Link to API documentation

### Security Requirements

1. **One-Time Display**
   - Plain text secret must only be shown once
   - No ability to "view again" after creation
   - Must be stored in session temporarily, not persisted

2. **Secure Communication**
   - Plain text secret only transmitted over HTTPS
   - No logging of plain text secrets
   - Clear secret from session after display

3. **Audit Trail**
   - Log when secrets are generated/regenerated
   - Include timestamp and user who performed the action
   - Never log the plain text secret itself

## Implementation Details

### Step 1: Modify Creation Logic

**File:** `/adm/logic/admin_api_key_edit_logic.php`

```php
// After save, store plain text secret in session for one-time display
if(!$api_key->key){
    $public_key = 'public_'.LibraryFunctions::random_string(16);
    $secret_key = 'secret_'.LibraryFunctions::random_string(16);
    $api_key->set('apk_public_key', $public_key);
    $api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_key));

    // Store plain text secret in session for one-time display
    $_SESSION['new_api_secret'] = $secret_key;
    $_SESSION['new_api_key_id'] = $api_key->key;
}

$api_key->prepare();
$api_key->save();
$api_key->load();

return LogicResult::redirect('/admin/admin_api_key?apk_api_key_id='. $api_key->key);
```

### Step 2: Display Secret on Detail Page (One-Time)

**File:** `/adm/admin_api_key.php`

Add after the page header, before the main content:

```php
// Check if there's a newly created secret to display
$show_secret = false;
$plain_secret = null;

if(isset($_SESSION['new_api_secret']) &&
   isset($_SESSION['new_api_key_id']) &&
   $_SESSION['new_api_key_id'] == $api_key->key) {

    $show_secret = true;
    $plain_secret = $_SESSION['new_api_secret'];

    // Clear from session immediately
    unset($_SESSION['new_api_secret']);
    unset($_SESSION['new_api_key_id']);
}

// Display one-time secret warning box if applicable
if($show_secret) {
    echo '<div class="alert alert-warning" style="background-color: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin-bottom: 20px;">';
    echo '<h4 style="color: #856404; margin-top: 0;">⚠️ Important: Save Your Secret Key</h4>';
    echo '<p style="color: #856404;"><strong>This is the ONLY time you will see the plain text secret key. Save it now in a secure location.</strong></p>';
    echo '<div style="background-color: #fff; padding: 15px; border: 1px solid #ffc107; margin: 10px 0; font-family: monospace; font-size: 16px; word-break: break-all;">';
    echo '<strong>Secret Key:</strong> ' . htmlspecialchars($plain_secret);
    echo '</div>';
    echo '<p style="color: #856404;">Store securely. If lost, regenerate it. Never expose in client-side code.</p>';
    echo '<button class="btn btn-primary" onclick="this.parentElement.style.display=\'none\'">I have saved the secret key</button>';
    echo '</div>';
}
```

Replace the current secret key display:

```php
// OLD CODE - REMOVE:
// echo '<strong>Secret key:</strong> '. $api_key->get('apk_secret_key').'<br>';

// NEW CODE:
if($show_secret) {
    echo '<strong>Secret key:</strong> <em style="color: #28a745;">Displayed above - save it now!</em><br>';
} else {
    echo '<strong>Secret key:</strong> <em style="color: #6c757d;">Hidden for security (cannot be retrieved)</em><br>';
}
```

### Step 3: Add Secret Regeneration

**File:** `/adm/logic/admin_api_key_logic.php`

Add new action handler:

```php
// Add after other action handlers
if(isset($get_vars['action']) && $get_vars['action'] == 'regenerate_secret'){
    $api_key = new ApiKey($get_vars['apk_api_key_id'], TRUE);

    // Generate new secret
    $new_secret = 'secret_'.LibraryFunctions::random_string(16);
    $api_key->set('apk_secret_key', ApiKey::GenerateKey($new_secret));
    $api_key->save();

    // Store in session for one-time display
    $_SESSION['new_api_secret'] = $new_secret;
    $_SESSION['new_api_key_id'] = $api_key->key;

    return LogicResult::redirect('/admin/admin_api_key?apk_api_key_id='.$api_key->key);
}
```

**File:** `/adm/admin_api_key.php`

Add "Regenerate Secret" button to altlinks:

```php
if(!$api_key->get('apk_delete_time') && !$show_secret){
    $options['altlinks']['Regenerate Secret'] = '/admin/admin_api_key?action=regenerate_secret&apk_api_key_id='.$api_key->key;
}
```

Add JavaScript confirmation:

```php
<script>
document.addEventListener('DOMContentLoaded', function() {
    const regenerateLink = document.querySelector('a[href*="action=regenerate_secret"]');
    if(regenerateLink) {
        regenerateLink.addEventListener('click', function(e) {
            if(!confirm('Regenerate secret key?\n\nThis will invalidate the current secret key immediately. Any applications using the old secret will stop working.\n\nYou will be shown the new secret key ONE TIME only.\n\nContinue?')) {
                e.preventDefault();
            }
        });
    }
});
</script>
```

### Step 4: Add Minimal Documentation Link (Optional)

**File:** `/adm/admin_api_key.php`

Add simple documentation link at bottom (optional):

```php
// Add after the status display (optional)
echo '<p style="margin-top: 15px;"><small><a href="/docs/api_documentation" target="_blank">API Documentation</a></small></p>';
```

## User Flow

### Creating a New API Key

1. Admin clicks "Create New API Key"
2. Fills out form (name, permissions, IP restrictions, etc.)
3. Submits form
4. **System generates random public and secret keys**
5. **System hashes secret and stores in database**
6. **Redirects to detail page**
7. **⚠️ One-time warning box displays with plain text secret**
8. User copies and saves the secret securely
9. User clicks "I have saved the secret key" (dismisses warning)
10. Secret is gone forever from UI and session

### Regenerating a Secret

1. Admin views existing API key detail page
2. Clicks "Regenerate Secret" link
3. Sees confirmation dialog explaining implications
4. Confirms regeneration
5. **System generates new random secret, hashes it**
6. **Redirects to detail page**
7. **⚠️ One-time warning box displays with new plain text secret**
8. User copies and saves the new secret
9. Old secret is immediately invalidated

### Viewing Existing API Key

1. Admin views API key detail page
2. Sees: "Secret key: *Hidden for security (cannot be retrieved)*"
3. Can regenerate secret if needed

## Testing Checklist

- [ ] Create new API key → Plain text secret displays with warning
- [ ] Refresh page after creation → Secret no longer visible
- [ ] View existing API key → Hashed secret not displayed
- [ ] Regenerate secret → New plain text secret displays
- [ ] Regenerate confirmation → Warning dialog appears
- [ ] Close warning box → Box disappears but secret still accessible on page
- [ ] Navigate away and back → Secret no longer visible
- [ ] Test created secret with actual API call → Works correctly
- [ ] Test regenerated secret with API call → Old secret fails, new works
- [ ] Help text and documentation links → Display correctly

## Success Criteria

1. **Plain text secrets are shown exactly once** after creation/regeneration
2. **Hashed secrets are never displayed** to users
3. **Users can regenerate secrets** without losing other settings
4. **Appropriate warnings** guide users to save secrets securely
5. **Help text and documentation** educate users on best practices
6. **No security regressions** - secrets still properly hashed in database

## Security Considerations

1. **Session Storage**: Plain text secret stored in session only temporarily
2. **No Logging**: Never log plain text secrets to error logs or audit trails
3. **HTTPS Required**: Ensure admin panel requires HTTPS
4. **Immediate Cleanup**: Clear secret from session after display
5. **Confirmation Required**: Regeneration requires explicit confirmation

## Additional Improvements (Future Enhancements)

1. **Copy to Clipboard**: Add button to copy secret to clipboard
2. **Download as JSON**: Provide download option for secure storage
3. **API Key Permissions Matrix**: Show visual table of what each permission level allows
4. **Usage Statistics**: Show when key was last used, request count
5. **Multiple Secrets**: Support key rotation with overlapping validity periods
6. **Webhook Notifications**: Notify when keys are regenerated or deleted

## Migration Notes

**Database Changes:** None required - uses existing schema

**Backward Compatibility:**
- Existing API keys continue to work
- Hashed secrets in database remain valid
- No changes to API authentication logic

**Deployment Steps:**
1. Update logic files
2. Update view files
3. Test in staging with new key creation
4. Test regeneration with existing keys
5. Deploy to production
6. Update API documentation if needed