# Spec: Rename Falcon Button Classes to Soft Button Classes

## Background

The joinery-system theme was built from the earlier Falcon Bootstrap theme. CSS class names
in PHP files still carried the "falcon" prefix (`btn-falcon-default`, `btn-falcon-primary`,
`btn-falcon-danger`). Since joinery-system is its own theme, these were renamed to
theme-agnostic `btn-soft-*` names.

## What Was Done

Straight find-and-replace of `btn-falcon-` → `btn-soft-` across all files outside the
legacy Falcon theme. No backward-compatibility aliases were needed since all references
were updated in a single pass.

### Naming

| Old name              | New name            |
|-----------------------|---------------------|
| `btn-falcon-default`  | `btn-soft-default`  |
| `btn-falcon-primary`  | `btn-soft-primary`  |
| `btn-falcon-danger`   | `btn-soft-danger`   |

### Files Changed (21 files)

**CSS:**
- `theme/joinery-system/assets/css/style.css` — renamed `.btn-falcon-default` to `.btn-soft-default`

**Core PHP:**
- `includes/PublicPageJoinerySystem.php` — 4 occurrences in `renderDropdown` and `renderToolbar`
- `includes/PublicPageBase.php` — 1 occurrence in `action_button()`

**Admin pages and logic:**
- `adm/admin_event.php` (6 occurrences — default, primary, danger variants)
- `adm/admin_file.php`
- `adm/admin_location.php`
- `adm/admin_order.php`
- `adm/admin_page.php`
- `adm/admin_plugins.php`
- `adm/admin_post.php`
- `adm/admin_product.php`
- `adm/admin_survey.php`
- `adm/admin_themes.php`
- `adm/admin_video.php`
- `adm/logic/admin_event_logic.php`
- `adm/logic/admin_file_logic.php`
- `adm/logic/admin_mailing_list_logic.php`
- `adm/logic/admin_order_logic.php`
- `adm/logic/admin_product_logic.php`
- `adm/logic/admin_user_logic.php`
- `adm/logic/admin_video_logic.php`

## Out of Scope (unchanged)

- `PublicPageFalcon.php` and `theme/falcon-html5/` — legacy Falcon Bootstrap theme, kept as-is
- The string `'falcon'` used as a theme identifier in `PublicPageBase` — refers to the theme name, not a button class
- References to `falcon` in `ImageSizeRegistry.php` and `PathHelper.php` — theme resolution logic

## Visual Impact

None — identical rendering before and after.
