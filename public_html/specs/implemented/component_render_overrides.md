# Component Render Overrides & Programmatic Rendering

## Overview

Extended `ComponentRenderer::render()` to support two new use cases beyond slug-based database lookups:

1. **Config overrides** — Render a database component instance but override some config values at render time
2. **Programmatic rendering** — Render a component template by type key with config passed directly from code, no database instance needed

## Motivation

Components were previously only renderable from database instances (by slug or PageContent object). This limited their use to CMS-configured contexts. Many reusable UI patterns (image galleries, entity displays) need to be rendered programmatically from PHP code with runtime data. Rather than creating separate helper classes for each, the component system now supports direct rendering by type key.

## Implementation

### Unified `render()` method

**File:** `includes/ComponentRenderer.php` (v1.3.0 → v1.4.0)

The old `render($slug)`, `render_component($instance)`, and `render_by_type($type_key)` methods were consolidated into a single `render()` method:

```php
public static function render($slug, $type_key = null, $overrides = [])
```

**Three modes:**

| Call | Behavior |
|------|----------|
| `render('slug')` | Loads PageContent by slug from database, renders with stored config |
| `render('slug', null, $overrides)` | Loads by slug, merges overrides on top of stored config |
| `render(null, 'type_key', $config)` | Looks up Component type by type_key, renders template with provided config as full `$component_config`. No database instance. `$component` is null. Layout wrapper skipped. |

### Template variables in type_key mode

When rendered programmatically (no database instance):
- `$component_config` = the `$overrides` array passed by the caller
- `$component_data` = data from logic function (if any)
- `$component` = `null` (no PageContent instance)
- `$component_type_record` = the Component type object
- `$component_slug` = `''`
- Layout wrapper is skipped (the calling view controls layout)

### Caller migration

`Page::get_filled_content()` in `data/pages_class.php` was initially updated from:
```php
$output .= ComponentRenderer::render_component($component);
```
to:
```php
$output .= ComponentRenderer::render($component->get('pac_location_name'));
```

**⚠️ This migration was incorrect and was subsequently reverted.** See `specs/implemented/fix_component_render_instance.md` for the full post-mortem. The `pac_location_name` field is optional — page-attached components created through the admin UI never have a slug set, so passing it to `render()` returned empty string for 100% of real-world components. `render_component()` was restored as a separate public method in v1.6.0.

## Usage Examples

```php
// Render by slug (existing behavior, unchanged)
echo ComponentRenderer::render('homepage-hero');

// Render by slug with config overrides
echo ComponentRenderer::render('homepage-hero', null, ['heading' => 'Custom Title']);

// Programmatic: render by type key, no database instance
echo ComponentRenderer::render(null, 'image_gallery', [
    'photos' => $post->get_photos(),
    'primary_file_id' => $post->get('pst_fil_file_id'),
]);
```

## Files Modified

1. `includes/ComponentRenderer.php` — Consolidated to single `render()` method, removed `render_component()` and `render_by_type()`
2. `data/pages_class.php` — Updated caller to use `render()` with slug string (later reverted — see note above)
3. `docs/component_system.md` — Added programmatic rendering documentation
4. `docs/creating_components_from_themes.md` — Added override and type_key rendering examples

## Backward Compatibility

The `render($slug)` and `render(null, 'type_key', $config)` calls work correctly. However, removing `render_component()` broke `Page::get_filled_content()` for all page-attached components without slugs (which is the normal case). This was fixed in a subsequent commit — see `specs/implemented/fix_component_render_instance.md`.
