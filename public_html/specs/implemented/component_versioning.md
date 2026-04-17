# Component Versioning System

## Overview

Add version history support to the component system, allowing users to view and restore previous versions of component configurations. This uses the existing `ContentVersion` system already in place for blog posts, pages, and other content types.

## Current State

- `PageContent` class already calls `ContentVersion::NewVersion()` on save
- However, it only versions `pac_body` (legacy field), not `pac_config` (JSON config used by components)
- `admin_component_edit.php` has no UI to view or restore versions
- Component type changes are not tracked

## Requirements

### 1. Update PageContent Versioning

Modify `PageContent::save()` to version the component config JSON instead of (or in addition to) the legacy body field.

**File:** `/data/page_contents_class.php`

```php
function save($debug = false) {
    // ... existing duplicate check ...

    if ($this->key) {
        // Version the config JSON for components
        $config_json = $this->get('pac_config');
        $version_content = $config_json ?: $this->get('pac_body');

        ContentVersion::NewVersion(
            ContentVersion::TYPE_PAGE_CONTENT,
            $this->key,
            $version_content,
            $this->get('pac_title'),
            $this->get('pac_title')
        );
    }

    parent::save($debug);
}
```

### 2. Add Version History UI to Component Editor

Add a version dropdown in the sidebar of `admin_component_edit.php`, following the pattern used in `admin_post_edit.php`.

**Location:** Right sidebar, below the "Publishing" card

**UI Elements:**
- Card titled "Version History"
- Dropdown showing available versions (timestamp + optional description)
- "Load" button to load selected version
- Only shown for existing components (not new)

### 3. Version Loading Logic

When a version is selected via GET parameter:

1. Skip normal POST processing (prevent accidental overwrite)
2. Load the `ContentVersion` record
3. Parse the JSON config from `cnv_content`
4. Override form field values with historical data
5. Display notice: "Viewing version from [timestamp]. Save to restore this version."

**Implementation Pattern:**
```php
// Check if loading a version
$loading_version = false;
$version_notice = '';
if (isset($_GET['cnv_content_version_id']) && $_GET['cnv_content_version_id']) {
    $loading_version = true;
    $content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);

    // Parse the versioned config
    $versioned_config = json_decode($content_version->get('cnv_content'), true);
    if ($versioned_config) {
        // Override current config with historical version
        $content->set_config($versioned_config);
    }

    $version_notice = 'Viewing version from ' .
        LibraryFunctions::convert_time($content_version->get('cnv_create_time'), $session) .
        '. Save to restore this version.';
}

// Skip POST processing if loading version
if ($_POST && !$loading_version) {
    // ... existing save logic ...
}
```

### 4. Version Dropdown in Sidebar

```php
// Version history (only for existing components)
if ($content->key) {
    require_once(PathHelper::getIncludePath('data/content_versions_class.php'));

    $content_versions = new MultiContentVersion(
        array('type' => ContentVersion::TYPE_PAGE_CONTENT, 'foreign_key_id' => $content->key),
        array('create_time' => 'DESC')
    );
    $content_versions->load();

    $version_options = $content_versions->get_dropdown_array($session, FALSE);

    if (count($version_options)) {
        echo '<div class="card mt-3"><div class="card-body">';
        echo '<h6 class="card-title">Version History</h6>';

        $version_form = $page->getFormWriter('form_load_version', []);
        $version_form->begin_form();
        $version_form->hiddeninput('pac_page_content_id', ['value' => $content->key]);
        $version_form->dropinput('cnv_content_version_id', 'Version', [
            'options' => $version_options
        ]);
        $version_form->submitbutton('btn_load', 'Load');
        $version_form->end_form();

        echo '</div></div>';
    }
}
```

### 5. Version Notice Display

When viewing a historical version, display an alert at the top of the form:

```php
if ($version_notice) {
    echo '<div class="alert alert-info">';
    echo '<i class="fas fa-history me-2"></i>';
    echo htmlspecialchars($version_notice);
    echo '</div>';
}
```

## Edge Cases

### Component Type Changes

When a component's type is changed:
- The old config is versioned before save (as usual)
- The new config (with different fields) is saved
- Restoring an old version will restore the old config JSON
- The component type dropdown should NOT be auto-changed when loading a version
- User must manually change type if restoring to a version with different type

**Recommendation:** Store `pac_com_component_id` in the version metadata to track type changes. Add a warning if restoring a version from a different component type.

### Empty Config

- If `pac_config` is empty/null, fall back to versioning `pac_body` for legacy compatibility
- When loading a version, check if content is valid JSON; if not, treat as legacy body content

### Version Cleanup

No automatic cleanup is needed. Versions follow the same soft-delete pattern as other content. Administrators can manually delete old versions if storage becomes a concern.

## Files to Modify

| File | Changes |
|------|---------|
| `/data/page_contents_class.php` | Update `save()` to version `pac_config` |
| `/adm/admin_component_edit.php` | Add version dropdown UI, version loading logic, version notice |

## Testing Checklist

- [ ] Create new component, verify no version UI shown
- [ ] Edit existing component, verify version UI appears in sidebar
- [ ] Save component multiple times, verify versions appear in dropdown
- [ ] Select and load a previous version, verify form populates with old data
- [ ] Verify "Viewing version" notice appears when loading old version
- [ ] Save after loading old version, verify it creates new current state
- [ ] Change component type, save, then restore old version - verify config loads correctly
- [ ] Verify POST is skipped when loading a version (prevents accidental save)

## Future Enhancements (Out of Scope)

- Side-by-side diff view between versions
- Version descriptions/comments when saving
- Bulk version cleanup tools
- Version restore without page reload (AJAX)
