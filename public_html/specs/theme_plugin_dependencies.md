# Spec: Theme-to-Plugin Dependency Declaration

**Status:** Pending implementation
**Area:** Theme system, plugin lifecycle (ThemeHelper, PluginManager)

---

## Problem

Themes can depend on plugins for backend functionality (data models, helpers, widgets) and for plugin-provided pages (linked via namespaced URLs). If a required plugin is deactivated, the theme breaks — either with fatal errors (missing classes) or silently (404s on plugin URLs, missing widgets).

There is no mechanism for a theme to declare which plugins it requires, and no check to prevent deactivating a plugin that the active theme depends on.

---

## Examples

- A theme renders a newsletter signup widget in its footer using `NewsletterHelper::renderSignupForm()` from the newsletter plugin
- A theme shows "upcoming events" on its homepage using the bookings plugin's data models
- A theme's navigation links to `/profile/scrolldaddy/devices` — a page provided by the scrolldaddy plugin
- A theme uses a plugin's helper classes to format or display data

In all cases, deactivating the plugin breaks the theme with no warning.

---

## Changes

### Change 1: `requires_plugins` field in `theme.json`

Themes can declare plugin dependencies:

```json
{
    "display_name": "ScrollDaddy Theme",
    "version": "1.0.0",
    "requires_plugins": ["scrolldaddy"]
}
```

The field is optional. When present, it is an array of plugin directory names.

### Change 2: Block plugin deactivation when active theme requires it

**File:** `includes/PluginManager.php`, `onDeactivate()` (around line 592)

The method already checks `isActiveThemeProvider()` and `getDependents()`. Add a parallel check: does the active theme declare this plugin in `requires_plugins`?

```
Load active theme's theme.json
If requires_plugins contains the plugin being deactivated:
    throw Exception("Cannot deactivate '{plugin}': the active theme '{theme}' requires it.
    Switch to a different theme first.")
```

This mirrors the existing `isActiveThemeProvider()` pattern at line 597.

### Change 3: Validate theme requirements at theme activation

When a theme is activated (via the `theme_template` setting change), validate that all plugins listed in `requires_plugins` are installed and active. If any are missing, block activation with a clear message:

```
"Cannot activate theme '{theme}': required plugin '{plugin}' is not active.
Activate the plugin first."
```

---

## Files to modify

| File | Change |
|------|--------|
| `includes/PluginManager.php` | `onDeactivate`: check active theme's `requires_plugins` |
| Theme activation logic | Validate required plugins are active |
| `docs/plugin_developer_guide.md` | Document `requires_plugins` field in theme.json |

---

## Out of scope

- Plugin-to-plugin dependencies (already implemented via `depends` in plugin.json)
- Automatic plugin activation when a theme is activated
- UI for managing theme-plugin dependencies
