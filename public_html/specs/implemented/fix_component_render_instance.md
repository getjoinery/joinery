# Fix: Restore Instance-Based Component Rendering

## Bug Summary

Page content is invisible on every page that uses the component system. The page title, breadcrumbs, header, and footer all render correctly, but the actual body content is blank. This affects **100% of component-based pages** across all deployed sites.

**Observed on:** phillyzouk.org/page/about, joinerytest.site/page/testpage
**Scope:** Every component-based page on every site. Zero out of 8 components across two sites have slugs.

## Root Cause

Commit `1f2c4a45` ("Add variable overrides and direct instantiation for components") consolidated `render()`, `render_component()`, and `render_by_type()` into a single `render($slug, $type_key, $overrides)` method. As part of this consolidation, the call in `Page::get_filled_content()` was changed from:

```php
// BEFORE (worked) — passes the loaded PageContent object directly
$output .= ComponentRenderer::render_component($component);
```

to:

```php
// AFTER (broken) — passes the slug string, which is almost always empty
$output .= ComponentRenderer::render($component->get('pac_location_name'));
```

The `pac_location_name` field (slug) is **optional** — the admin UI labels it "Slug (optional)" and it is only needed when a developer wants to embed a component directly in a template via `ComponentRenderer::render('my-slug')`. Components created through the normal page editor never have a slug set.

When `render()` receives an empty string for `$slug` and null for `$type_key`, it hits this branch and silently returns nothing:

```php
} else {
    return '';  // <-- empty slug, no type_key = silent empty output
}
```

## Why the Bug Wasn't Caught

1. **The spec's own migration example was wrong.** The implemented spec (`component_render_overrides.md`) shows the migration as safe:
   > `$output .= ComponentRenderer::render($component->get('pac_location_name'));`

   This assumes all components have slugs. They don't.

2. **No error output.** When both `$slug` and `$type_key` are empty, `render()` returns `''` without even a debug comment. The page renders a valid HTML structure with an empty content area — it doesn't 500 or show any visible error.

3. **The refactoring was tested against slug-based components only.** The new `render(null, 'type_key', $config)` mode (programmatic rendering from view templates) works correctly. The slug-based `render('my-slug')` mode also works. The only broken path is the one called by `get_filled_content()` for page-editor components that have no slug.

## What the Original Design Got Right

The original `render_component($component_instance)` method (from commit `00cf3c77`) existed specifically because `get_filled_content()` already has loaded PageContent objects. It needs to render them directly — not look them up again by a slug that may not exist.

The two entry points served different purposes:
- **`render($slug)`** — For templates: "render the component with this slug"
- **`render_component($instance)`** — For the page system: "render this already-loaded component"

The consolidation in `1f2c4a45` collapsed these into one method but only preserved the slug-based path, losing the instance-based path entirely.

## Fix Options

### Option A: Restore `render_component()` (Recommended)

Add back `render_component()` as a separate public method. Extract the shared rendering pipeline into a protected method so both entry points use the same code.

**Changes to `ComponentRenderer.php`:**

1. Extract everything after instance/type resolution in `render()` (lines 99-200: availability check, config build, logic function, template resolution, layout, template rendering) into a protected `_render_resolved($component_instance, $component_type, $slug, $overrides)` method.

2. `render()` resolves instance/type from slug or type_key, then calls `_render_resolved()`.

3. Add new public method:

```php
/**
 * Render a pre-loaded component instance
 *
 * Used by Page::get_filled_content() and other callers that already
 * have a loaded PageContent object. Skips the slug-based DB lookup.
 *
 * @param PageContent $component_instance The loaded component instance
 * @param array $overrides Config values merged on top of stored config
 * @return string Rendered HTML
 */
public static function render_component($component_instance, $overrides = []) {
    $slug = $component_instance->get('pac_location_name') ?: '';
    $debug_label = $slug ?: ('id:' . $component_instance->key);

    if (!$component_instance->is_component()) {
        return self::debug_output("Record is not a component", $debug_label);
    }
    if (!$component_instance->is_visible()) {
        return self::debug_output("Component is deleted", $debug_label);
    }

    $component_type = $component_instance->get_component_type();
    if (!$component_type) {
        return self::debug_output("Component type not found", $debug_label);
    }

    return self::_render_resolved($component_instance, $component_type, $slug, $overrides);
}
```

**Changes to `pages_class.php`:**

```php
// Revert get_filled_content() from:
$output .= ComponentRenderer::render($component->get('pac_location_name'));

// To:
$output .= ComponentRenderer::render_component($component);
```

**Pros:**
- Clean separation of concerns: slug lookup vs. direct rendering
- No redundant DB lookup for components already in memory
- `render()` API stays unchanged — no new parameter overloading
- Matches the original design intent

**Cons:**
- Two public entry points instead of one (more API surface)
- Need to extract shared pipeline method to avoid duplication

---

### Option B: Keep Consolidated `render()`, Fix the Caller

Keep the single `render()` method but fix `get_filled_content()` to always provide a usable identifier. Since components without slugs have no way to be looked up by slug, the caller falls back to the component's type key with the instance config as overrides.

**Changes to `pages_class.php` only:**

```php
// In get_filled_content(), replace:
$output .= ComponentRenderer::render($component->get('pac_location_name'));

// With:
$slug = $component->get('pac_location_name');
if (!empty($slug)) {
    $output .= ComponentRenderer::render($slug);
} else {
    // No slug — render by type key with instance config
    $component_type = $component->get_component_type();
    if ($component_type) {
        $output .= ComponentRenderer::render(
            null,
            $component_type->get('com_type_key'),
            $component->get_config()
        );
    }
}
```

**Pros:**
- No changes to ComponentRenderer — single unified API preserved
- Smaller diff

**Cons:**
- Loses instance context: `$component` is null in the template (type_key mode sets `$component = null`), so templates that check `$component->get(...)` would break
- Loses layout settings: type_key mode skips the layout wrapper (`$skip_wrapper = true`), so `pac_max_width`, `pac_max_height`, and `pac_vertical_margin` are ignored
- Redundant work: the instance is already loaded but we discard it, extract its config, look up the type by key, then rebuild everything from scratch
- Pushes rendering logic into the caller — `get_filled_content()` now needs to understand ComponentRenderer internals (type keys, config extraction)
- Every future caller with a loaded instance would need this same slug-or-fallback pattern

## Recommendation

**Option A** is the better fix. Option B technically works for the immediate bug but introduces subtle regressions (lost instance context, lost layout settings) and puts the wrong responsibility in the wrong place. The original two-method design was correct — `render()` is for "render by identifier" and `render_component()` is for "render this object I already have." The consolidation was an over-simplification that lost a valid use case.

## Files to Modify

**Option A:**
1. `includes/ComponentRenderer.php` — Extract `_render_resolved()`, add `render_component()`
2. `data/pages_class.php` — Revert caller in `get_filled_content()`

**Option B:**
1. `data/pages_class.php` — Update caller with slug-or-type-key fallback

## Documentation Updates (Either Option)

The following docs contain the broken pattern or describe the now-incorrect "single unified method" API. They must be updated to reflect the restored `render_component()` method.

### 1. `docs/component_system.md`

- **Line 45:** Currently describes two rendering modes (Slug-Based and Programmatic). Add a third: Instance-Based, used by `Page::get_filled_content()` for page-editor components.
- **Line 86:** Signature table only shows `render()`. Add `render_component()` signature and parameter table.
- **Line 108-112:** The "Get Page Components" example uses the broken pattern:
  ```php
  // BROKEN — slug may be empty
  echo ComponentRenderer::render($component->get('pac_location_name'));
  ```
  Replace with:
  ```php
  echo ComponentRenderer::render_component($component);
  ```
- **Line 1047:** Same broken pattern in the Page integration section. Replace with `render_component()`.
- **Quick Reference section (~line 959):** Add `render_component()` to the quick reference examples.

### 2. `docs/creating_components_from_themes.md`

- **Line 376:** States "Components attached to a page render automatically when using `ComponentRenderer::get_page_components()`." Update to mention `render_component()` as the correct way to render loaded instances.

### 3. `specs/implemented/component_render_overrides.md`

This is the spec that introduced the bug. Update it to reflect reality:
- **Line 20:** Says methods "were consolidated into a single `render()` method" — update to note that `render_component()` was restored in this fix because the consolidation broke instance-based rendering.
- **Lines 46-53:** The "Caller migration" section shows the broken migration. Add a note that this was reverted and why.
- **Line 73:** Says "removed `render_component()`" — update to reflect it was restored.
- **Line 80:** Claims "Fully backward compatible" — add a correction noting the slug assumption was wrong.

## Testing

1. Navigate to `https://joinerytest.site/page/testpage` — should show component content
2. Navigate to any page with components — content should be visible
3. Verify `ComponentRenderer::render('slug')` still works for components that DO have slugs
4. Verify `ComponentRenderer::render(null, 'type_key', $config)` still works for programmatic rendering
5. Deploy to phillyzouk and verify `https://phillyzouk.org/page/about` shows content

## Deployment

After fixing locally, deploy updated files to the phillyzouk container:
- `includes/ComponentRenderer.php` (Option A only)
- `data/pages_class.php`
