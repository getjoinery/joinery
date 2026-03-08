# Spec: Rename Falcon Button Classes to Joinery System Names

## Background

The joinery-system theme was built from the earlier Falcon Bootstrap theme. Several CSS class names
and code strings still carry the "falcon" prefix, most visibly the button utilities
(`btn-falcon-default`, `btn-falcon-danger`, etc.). These references are spread across the CSS file,
two core PHP classes, and ~25 admin page files.

## Goal

Remove all `btn-falcon-*` references from the joinery-system theme and replace them with neutral,
theme-agnostic names. Admin pages should continue to work without requiring changes to each file.

## Scope of Changes

### 1. CSS — `theme/joinery-system/assets/css/style.css`

Rename the two existing rules and add backward-compat aliases for all four variants used in admin
pages. Currently only `btn-falcon-default` is defined; the others fall through to browser defaults.

**New canonical names:**

| Old name              | New name          | Meaning                                   |
|-----------------------|-------------------|-------------------------------------------|
| `btn-falcon-default`  | `btn-soft`        | White/outlined secondary button (current default style) |
| `btn-falcon-primary`  | `btn-soft-primary`| Soft blue tint                            |
| `btn-falcon-secondary`| `btn-soft-secondary`| Soft grey tint                          |
| `btn-falcon-danger`   | `btn-soft-danger` | Soft red tint                             |

**CSS changes:**
- Rename `.btn-falcon-default` → `.btn-soft` (same styles, no visual change)
- Define `.btn-soft-primary`, `.btn-soft-secondary`, `.btn-soft-danger` with appropriate soft-tint
  styles to match the intent of those class names (currently unstyled in joinery-system CSS)
- Add CSS aliases so all four old `btn-falcon-*` names still render correctly:
  ```css
  /* Backward-compat aliases — allows admin pages to keep old class names */
  .btn-falcon-default   { /* same as .btn-soft */ }
  .btn-falcon-primary   { /* same as .btn-soft-primary */ }
  .btn-falcon-secondary { /* same as .btn-soft-secondary */ }
  .btn-falcon-danger    { /* same as .btn-soft-danger */ }
  ```

### 2. Core PHP — `includes/PublicPageJoinerySystem.php`

Four hardcoded `btn-falcon-default` occurrences in `renderDropdown` and `renderToolbar`.
Update all four to `btn-soft`.

### 3. Core PHP — `includes/PublicPageBase.php`

One hardcoded `btn-falcon-default` occurrence in `action_button()`.
Update to `btn-soft`.

### 4. Admin pages — NOT changed

The ~25 admin files that reference `btn-falcon-*` are **not touched**. The CSS aliases in step 1
ensure they continue to render correctly. Cleaning up those files is a separate, lower-priority task.

## Files Changed

| File | Change |
|------|--------|
| `theme/joinery-system/assets/css/style.css` | Rename class, add 3 new soft variants, add 4 aliases |
| `includes/PublicPageJoinerySystem.php` | 4 occurrences: `btn-falcon-default` → `btn-soft` |
| `includes/PublicPageBase.php` | 1 occurrence: `btn-falcon-default` → `btn-soft` |

## Out of Scope

- The string `'falcon'` used as a default theme name in `PublicPageBase` line 325 — this refers to
  the *theme identifier*, not a button class, and is a separate concern.
- References to `falcon` in `ImageSizeRegistry.php` and `PathHelper.php` — these are theme
  resolution logic, not UI styling.
- The `PublicPageFalcon` class and `theme/falcon-html5/` — these are the legacy Bootstrap theme,
  kept for reference.
- Admin page files — covered by CSS aliases, cleanup deferred.

## Visual Impact

None — the CSS aliases ensure identical rendering before and after. The only change is that new
code written against the joinery-system theme should use `btn-soft` instead of `btn-falcon-default`.

## Decision Points

1. **Alias approach vs. full rename**: This spec proposes aliases to avoid touching 25+ admin files.
   Alternative: do a full find-and-replace across all admin files in one pass. Tradeoff is scope
   vs. cleanliness.

2. **New name `btn-soft`**: Open to alternatives (`btn-outline`, `btn-secondary-soft`, `btn-ghost`).
   The current style is white background with a light border — "soft" or "ghost" both describe it.

3. **Define the missing variants now**: `btn-falcon-primary/secondary/danger` have no styles in the
   current joinery CSS (they fall through to unstyled). This spec proposes defining them with
   appropriate soft-tint styles. If you'd prefer to leave them undefined (let old pages look however
   they look), that's also fine.
