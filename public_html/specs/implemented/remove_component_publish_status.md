# Remove Component Publish Status

## Summary

Remove the publish/unpublish functionality from page components. Component visibility should be controlled by the parent page's publish status (for page-attached components) or soft-delete (for standalone components).

## Current State

### Database Fields
The `pac_page_contents` table has two publish-related fields:
- `pac_is_published` (boolean) - publish flag
- `pac_published_time` (timestamp) - when published

### Current Behavior
1. **Slug-based components** (`ComponentRenderer::render('slug')`) - checks `is_visible()` which uses `pac_is_published`
2. **Page-attached components** (`Page::get_components_content()`) - does NOT check visibility, renders all non-deleted components

### Admin UI
- `admin_component_edit.php` has a "Published" checkbox
- `admin_page_content_edit.php` has publish controls
- `admin_page_contents.php` shows publish status in listing

## Problems

1. **Inconsistent enforcement** - Page-attached components ignore publish status entirely
2. **Redundant controls** - Page publish + component publish is confusing
3. **Two fields for same concept** - `pac_is_published` and `pac_published_time` is redundant
4. **Unnecessary complexity** - Adds admin UI elements that serve little purpose
5. **Soft-delete exists** - Already have a mechanism to hide components without permanent deletion

## Proposed Solution

Remove component-level publish functionality entirely.

### Visibility Rules (After Change)
1. **Page-attached components** - Visible when parent page is published and component is not deleted
2. **Standalone components (by slug)** - Visible when not deleted
3. **Hiding a component** - Use soft-delete instead of unpublish

### Database Changes
Remove columns from `pac_page_contents`:
- `pac_is_published`
- `pac_published_time`

### Code Changes

#### 1. Data Model (`data/page_contents_class.php`)
- Remove `pac_is_published` and `pac_published_time` from `$field_specifications`
- Update `is_visible()` to only check `pac_delete_time`
- Remove `get_content()` publish check (or remove method if unused)

#### 2. Multi Class (`data/page_contents_class.php`)
- Remove `published` option from `getMultiResults()`

#### 3. ComponentRenderer (`includes/ComponentRenderer.php`)
- Update `render()` to check only for deletion, not publish status
- Debug message should say "deleted" not "not published"

#### 4. Admin Edit Page (`adm/admin_component_edit.php`)
- Remove "Published" checkbox/toggle
- Remove any publish-related form handling

#### 5. Admin Edit Page (`adm/admin_page_content_edit.php`)
- Remove publish controls if present

#### 6. Admin Listings
- `adm/admin_page_contents.php` - Remove publish status column
- `adm/admin_page.php` - Remove publish indicators from component list if present

### Migration
Create a data migration that:
1. Drops `pac_is_published` column
2. Drops `pac_published_time` column

Note: No data preservation needed - unpublished components will become visible (acceptable since this feature was inconsistently enforced anyway).

## Testing

1. Create a page with attached components - verify all render when page is published
2. Soft-delete a component - verify it no longer renders
3. Undelete a component - verify it renders again
4. Create standalone component by slug - verify it renders
5. Soft-delete standalone component - verify `ComponentRenderer::render('slug')` returns empty
6. Verify admin UI no longer shows publish controls
7. Verify admin listings no longer show publish status

## Benefits

1. **Simpler mental model** - One visibility control per layer (page publish, component delete)
2. **Consistent behavior** - All components behave the same way
3. **Cleaner admin UI** - Fewer controls to confuse users
4. **Less code** - Remove unused/inconsistent functionality
