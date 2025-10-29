# Specification: Convert admin_subscription_tier_edit.php to FormWriter V2

## Related Specifications
- **[Subscription Tiers Phase 1](/specs/implemented/subscription_tiers_phase1.md)**: Core tier implementation details
- **[Subscription Tiers Phase 2](/specs/implemented/subscription_tiers_phase2.md)**: Feature system and expiration handling

## Current State Analysis

### File Structure
- **Location**: `/adm/admin_subscription_tier_edit.php`
- **Current FormWriter**: Mixed V1 and raw HTML
- **POST Processing**: Inline (lines 36-114)
- **Redirects**: Using relative paths with `.php` extensions

### Key Components
1. **FormWriter V1 instance** (line 12): Not fully utilized
2. **Raw HTML form** (lines 139-260): Manual form with mixed input types
3. **Dynamic features section** (lines 159-240): Complex PHP-generated inputs from JSON config
   - Features defined in `/includes/core_tier_features.json` and plugin `tier_features.json` files
   - Auto-discovery system implemented per Phase 2 spec
   - Renders different input types based on feature type (boolean, integer, string)
4. **Tier member section** (lines 264-283): Read-only information display

### Current Issues
1. Form posts to `<form method="POST">` with empty action
2. Redirects use relative paths: `admin_subscription_tiers.php?success=1`
3. Mixed FormWriter V1 calls within raw HTML form
4. No separation between logic and presentation

## Migration Strategy

### REQUIRED: Logic File Separation Approach
Create `/adm/logic/admin_subscription_tier_edit_logic.php` to handle all POST processing and initial data loading.

**Why this is required:**
- FormWriter V1 MUST be converted to V2 (migration requirement)
- Clean separation of concerns (consistent with migrated pages)
- Proper redirect handling via LogicResult
- Resolves the redirect issues seen in previous attempts

**Implementation Steps:**
1. Create logic file with tier loading and POST processing
2. Return LogicResult with redirects or data
3. **REQUIRED: Convert ALL FormWriter V1 calls to V2**
   - Replace `$formwriter->textinput()` with V2 syntax
   - Replace `$formwriter->textbox()` with V2 syntax
   - Use model binding for tier data
4. Keep ONLY the features section as raw HTML (lines 159-240)

### ❌ NOT ACCEPTABLE: Keeping FormWriter V1
The current mixed V1/raw HTML approach is NOT acceptable because:
- FormWriter V1 is deprecated and must be migrated
- Inconsistent with other migrated admin pages
- Creates maintenance burden with mixed patterns

## Detailed Conversion Plan

### Step 1: Create Logic File
```php
// /adm/logic/admin_subscription_tier_edit_logic.php
function admin_subscription_tier_edit_logic($get, $post) {
    // Permission check
    $session = SessionControl::get_instance();
    $session->check_permission(5);

    // Load tier if editing
    $tier_id = isset($get['id']) ? intval($get['id']) : null;
    $tier = null;
    $is_edit = false;

    if ($tier_id) {
        $tier = new SubscriptionTier($tier_id, TRUE);
        if (!$tier || $tier->get('sbt_delete_time')) {
            return LogicResult::redirect('/admin/admin_subscription_tiers?error=tier_not_found');
        }
        $is_edit = true;
    }

    // Handle POST
    if (isset($post['action']) && $post['action'] == 'save') {
        // Process features array
        // Save tier
        // Log change tracking
        return LogicResult::redirect('/admin/admin_subscription_tiers?success=1');
    }

    // Return data for view
    return LogicResult::render([
        'tier' => $tier,
        'is_edit' => $is_edit,
        'session' => $session
    ]);
}
```

### Step 2: Update View File

#### Form Section Conversion
```php
// Replace lines 139-260
$formwriter = $page->getFormWriter('admin_tier_edit', 'v2', [
    'model' => $tier,
    'edit_primary_key_value' => $is_edit ? $tier->key : null
]);

$formwriter->begin_form();
$formwriter->hiddeninput('action', ['value' => 'save']);

// Basic fields
$formwriter->textinput('sbt_name', 'Tier Name', [
    'placeholder' => 'Internal name (e.g., premium)',
    'validation' => ['required' => true, 'maxlength' => 100]
]);

$formwriter->textinput('sbt_display_name', 'Display Name', [
    'placeholder' => 'User-facing name (e.g., Premium Member)',
    'validation' => ['required' => true, 'maxlength' => 100]
]);

$formwriter->textinput('sbt_tier_level', 'Tier Level', [
    'placeholder' => 'Higher number = higher tier (e.g., 30)',
    'validation' => ['required' => true, 'pattern' => '^[0-9]+$']
]);

$formwriter->textbox('sbt_description', 'Description', [
    'placeholder' => 'Optional description',
    'rows' => 3
]);
?>

<!-- Keep features section as raw HTML -->
<div class="card mt-4 mb-4">
    <!-- Features HTML remains unchanged (lines 159-240) -->
</div>

<?php
// Active checkbox (edit mode only)
if ($is_edit) {
    $formwriter->checkboxinput('sbt_is_active', 'Active');
} else {
    $formwriter->hiddeninput('sbt_is_active', ['value' => '1']);
}

$formwriter->submitbutton('submit_button', $is_edit ? 'Update Tier' : 'Create Tier');
$formwriter->end_form();
```

### Step 3: Fix Navigation Links
- Change `admin_subscription_tiers.php` → `/admin/admin_subscription_tiers`
- Change `admin_subscription_tier_members.php?id=X` → `/admin/admin_subscription_tier_members?id=X`

## Special Considerations

### 1. Features Section
**DO NOT convert to FormWriter** because:
- Dynamically generated from JSON configuration files (as per Phase 2 implementation)
- Uses `SubscriptionTier::getAllAvailableFeatures()` auto-discovery system
- Variable input types (checkbox for boolean, number for integer, text for string)
- Complex conditional rendering logic with min/max validation
- Would require custom FormWriter field type to handle dynamic schema
- Features can be added by plugins at runtime via `tier_features.json` files

### 2. POST Data Handling
The features array requires special processing:
```php
// Must preserve existing logic for type conversion
if ($definition && $definition['type'] === 'integer') {
    $features[$key] = intval($value);
} elseif ($definition && $definition['type'] === 'boolean') {
    $features[$key] = ($value === '1' || $value === 'true' || $value === true);
}
```

### 3. Redirect Paths
All redirects must use absolute paths without `.php`:
- ❌ `header('Location: admin_subscription_tiers.php?success=1')`
- ✅ `return LogicResult::redirect('/admin/admin_subscription_tiers?success=1')`

## Testing Checklist

1. [ ] Create new tier successfully redirects
2. [ ] Edit existing tier successfully redirects
3. [ ] Features are saved correctly with proper type conversion
4. [ ] Feature values persist in `sbt_features` JSONB column
5. [ ] Change tracking records created in `cht_change_tracking` table
6. [ ] Validation errors display properly
7. [ ] Cancel button navigates correctly
8. [ ] View Members button works (when applicable)
9. [ ] Active checkbox only shows in edit mode
10. [ ] Deleted or non-existent tier IDs redirect with error
11. [ ] Core features from `/includes/core_tier_features.json` display
12. [ ] Plugin features from `/plugins/*/tier_features.json` display

## Known Issues to Address

1. **Form Action**: FormWriter V2 needs explicit action or will use current page
2. **Redirect Method**: Must use LogicResult::redirect() not header()
3. **URL Format**: Remove all `.php` extensions from URLs
4. **Features Processing**: Keep existing type conversion logic intact

## Implementation Context

### Database Structure (from Phase 1 spec)
- **`sbt_subscription_tiers`** table with `sbt_features` JSONB column
- **`cht_change_tracking`** table for audit trail
- **`grp_groups`** integration with `grp_category = 'subscription_tier'`

### Feature System (from Phase 2 spec)
- Core features defined in `/includes/core_tier_features.json`
- Plugin features in `/plugins/{plugin}/tier_features.json`
- Auto-discovery via `SubscriptionTier::getAllAvailableFeatures()`
- Type system: boolean, integer, string with validation

## Required Conversion Approach

**The FormWriter V1 portions MUST be converted to V2** as part of the migration effort. Only the dynamic features section should remain as raw HTML.

### What MUST be converted:
1. All FormWriter V1 calls (`$formwriter->textinput()`, `$formwriter->textbox()`)
2. Form initialization and submission handling
3. Hidden fields and submit buttons
4. All standard tier fields (name, display_name, tier_level, description, is_active)

### What remains as raw HTML:
- ONLY the dynamic features section (lines 159-240)
- This section uses auto-discovery and runtime generation that isn't suitable for FormWriter

## Recommendation

**Use Option 1 (Logic File Separation)** with hybrid approach:
1. **Convert all FormWriter V1 to V2** - This is REQUIRED
2. **Keep features section as raw HTML** - This is the ONLY exception
3. **Move POST processing to logic file** - For consistency and proper redirects

This provides:
- **Compliance with V2 migration requirements**
- Clean architecture consistent with other pages
- Proper redirect handling via LogicResult
- Model binding for standard fields
- Flexibility for the complex features section only where needed

**This is NOT optional** - the FormWriter V1 portions must be migrated to V2.