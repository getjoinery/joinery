# Brand Token Settings Spec

## Overview

Expose the five highest-value CSS custom properties from the default UI kit (`joinery-styles.css`,
formerly `custom.css`) as admin-editable settings. Site admins set them once; the values are stored
in `stg_settings` and injected as `:root` overrides at render time — ahead of any per-theme
hardcoded overrides so branded themes can still take precedence when they choose to.

This replaces the current pattern of hardcoding token overrides directly in theme `PublicPage.php`
files (e.g. the block we added to `jeremytunnell-html5`).

---

## Prerequisite: Rename custom.css → joinery-styles.css

Before or alongside implementing the settings UI, rename the default UI kit stylesheet:

| Action | Detail |
|---|---|
| Rename file | `/assets/css/custom.css` → `/assets/css/joinery-styles.css` |
| Update href in `render_base_assets()` | Change `custom.css?v=8` → `joinery-styles.css?v=1` (reset version on rename) |

`render_base_assets()` is at line 588 of `PublicPageBase.php`. It currently outputs:
```php
echo '<link rel="stylesheet" href="/assets/css/custom.css?v=8">' . "\n";
```
Change to:
```php
echo '<link rel="stylesheet" href="/assets/css/joinery-styles.css?v=1">' . "\n";
```

No other files reference `custom.css` by that path (the admin "Custom CSS" setting is unrelated —
it stores arbitrary CSS entered by an admin, not a file reference).

---

## Settings

Five new entries in `stg_settings`:

| Setting name | Token overridden | Default (blank = use kit default) |
|---|---|---|
| `jy_color_primary` | `--jy-color-primary` | `#5b7a99` |
| `jy_color_primary_hover` | `--jy-color-primary-hover` | `#4a6886` |
| `jy_color_primary_text` | `--jy-color-primary-text` | `#ffffff` |
| `jy_color_surface` | `--jy-color-surface` | `#f7f8fa` |
| `jy_color_bg` | `--jy-color-bg` | `#ffffff` |

Blank value = setting not set = token falls back to the `joinery-styles.css` default. Never store an empty
string as an override.

---

## Admin UI

### Location

New section in `/adm/admin_settings.php`, placed immediately after the **General Settings** `<h3>`
block (after `logo_link`) and before the Composer section. Title: **"Brand & Appearance"**.

### Fields

All five fields use `$formwriter->colorpicker()` from `FormWriterV2Base` (exists at line 1738).
The colorpicker auto-scans the active theme's CSS for swatches using `'sort' => 'frequency'` — most-used
colors in the theme's CSS appear first, surfacing signature brand colors ahead of one-off accents.

```php
echo '<h3>Brand &amp; Appearance</h3>';
echo '<p class="text-muted">These override the default UI kit tokens used on login, signup,
    and other base pages. Leave blank to use the kit default.</p>';

$formwriter->colorpicker('jy_color_primary', 'Primary / Button Color', [
    'value'    => $settings->get_setting('jy_color_primary'),
    'helptext' => 'Buttons, checkboxes, links, focus rings.',
    'sort'     => 'frequency',
]);

$formwriter->colorpicker('jy_color_primary_hover', 'Primary Hover Color', [
    'value'    => $settings->get_setting('jy_color_primary_hover'),
    'helptext' => 'Button hover state. Typically a darker shade of the primary color.',
    'sort'     => 'frequency',
]);

$formwriter->colorpicker('jy_color_primary_text', 'Primary Button Text Color', [
    'value'    => $settings->get_setting('jy_color_primary_text'),
    'helptext' => 'Text on filled primary buttons. Usually white; change for light primaries.',
    'sort'     => 'frequency',
]);

$formwriter->colorpicker('jy_color_surface', 'Surface / Card Background', [
    'value'    => $settings->get_setting('jy_color_surface'),
    'helptext' => 'Background of auth cards, panels, table rows. White removes the gray tint.',
    'sort'     => 'frequency',
]);

$formwriter->colorpicker('jy_color_bg', 'Page Background', [
    'value'    => $settings->get_setting('jy_color_bg'),
    'helptext' => 'Overall page background behind cards.',
    'sort'     => 'frequency',
]);
```

### Save logic

`admin_settings_logic.php` already iterates all POST fields and upserts them into `stg_settings`
automatically. No new logic needed — the five `jy_color_*` fields are handled identically to every
other setting on the page.

---

## PHP Implementation

### Files involved and what changes in each

#### 1. `/includes/PublicPageBase.php` — two changes

This is the base class that all public-facing theme `PublicPage` classes extend. It already contains
`global_includes_top()` (line 517) and `render_base_assets()` (line 588). Neither is modified
except for adding one line and one new method.

**Change A — add one line to `global_includes_top()`**

`global_includes_top()` calls `render_base_assets()` at line 568. Add the new method call
immediately after it:

```php
// existing line:
$this->render_base_assets();
// add this line:
$this->render_brand_token_overrides();
```

That's the only change to `global_includes_top()`.

**Change B — add new method `render_brand_token_overrides()`**

This method does not exist yet. Add it as a new `protected` method in the same class, near
`render_base_assets()`:

```php
protected function render_brand_token_overrides() {
    $settings = Globalvars::get_instance();
    $map = [
        'jy_color_primary'       => '--jy-color-primary',
        'jy_color_primary_hover' => '--jy-color-primary-hover',
        'jy_color_primary_text'  => '--jy-color-primary-text',
        'jy_color_surface'       => '--jy-color-surface',
        'jy_color_bg'            => '--jy-color-bg',
    ];
    $overrides = [];
    foreach ($map as $setting => $token) {
        $val = $settings->get_setting($setting, false, true);
        if ($val !== '' && $val !== null && preg_match('/^#[0-9a-fA-F]{3,6}$/', $val)) {
            $overrides[] = '  ' . $token . ': ' . htmlspecialchars($val, ENT_QUOTES) . ';';
        }
    }
    if (empty($overrides)) return;
    echo '<style id="jy-brand-tokens">:root {' . "\n" . implode("\n", $overrides) . "\n" . '}</style>' . "\n";
}
```

What it does: reads the five `jy_color_*` settings, validates each as a CSS hex color, and if any
are set outputs a single `<style>` block that overrides those `:root` token values. If none are set
it outputs nothing.

#### 2. `/adm/admin_settings.php` — one new HTML block

This file already contains many sections using the same pattern (an `<h3>` heading followed by
`$formwriter->*()` field calls). Add a new "Brand & Appearance" section after the General Settings
`<h3>` block (after the `logo_link` field) and before the Composer section.

No new logic needed — `admin_settings_logic.php` already iterates all POST fields and upserts
them into `stg_settings` automatically. The five new fields are handled identically to every other
field on the page.

#### 3. `/theme/jeremytunnell-html5/includes/PublicPage.php` — remove hardcoded block

Once the settings UI ships, remove the inline `<style>` block added during Phase 6 (lines 89–96):

```html
<style>
/* Joinery default UI kit — brand token overrides */
:root {
    --jy-color-primary:       #c62641;
    --jy-color-primary-hover: #a81e36;
    --jy-color-surface:       #ffffff;
}
</style>
```

Replace with nothing. The admin settings become the single source of truth. The values that were
hardcoded here should be entered in the admin "Brand & Appearance" fields instead.

### CSS cascade order (loaded in this order, later wins)

1. `joinery-styles.css` — kit defaults (`:root` token declarations)
2. **Settings-based override** — `<style id="jy-brand-tokens">` output by `render_brand_token_overrides()`
3. Theme CSS (`style.css`, `output.css`, etc.) — loaded after `global_includes_top`
4. Per-theme hardcoded overrides (inline `<style>` blocks in theme `PublicPage.php`) — last, highest priority

This means:
- Settings act as the site-wide brand baseline above the kit defaults.
- Themes that want to enforce their own palette simply keep their own override block (it wins by
  source order).
- Themes that want to inherit the site settings remove their hardcoded block.

---

## Migration: Remove Hardcoded Overrides from Themes

Once this feature ships, any theme whose `PublicPage.php` contains a hardcoded brand token block
(like the one added to `jeremytunnell-html5` during Phase 6) should have that block removed. The
admin settings become the single source of truth.

**Themes audited and cleaned up:**

| Theme | Action |
|---|---|
| `jeremytunnell-html5` | Hardcoded block removed — values should be entered in admin Brand & Appearance settings |
| `empoweredhealth-html5` | No token overrides found — only layout fixes; no action needed |

---

## FormWriterV2Base: Frequency Sort

A `'frequency'` sort option was added to `extractThemeColors()` in `FormWriterV2Base` alongside
the existing `'dark_first'`, `'light_first'`, and `'none'` options. All four remain available.

**How it works:** The color extraction pass already collects one array entry per CSS mention
(duplicates included). The existing `'dark_first'`/`'light_first'` path discards duplicates with
`array_unique()` before sorting by luminance. The `'frequency'` path instead calls
`array_count_values()` to tally mentions, sorts descending by count (`arsort`), then takes the
keys — which are already deduplicated and ordered by frequency.

**Why frequency for brand token pickers:** Colors used most often in a theme's CSS tend to be its
signature brand colors (primary, background, headings) rather than one-off accents. Surfacing these
first reduces scanning time for admins trying to match the active theme's palette.

**Performance:** No additional file I/O. `array_count_values()` is O(n) and cheaper than the
luminance `usort`. The result is cached in a `static` variable — runs once per page load regardless
of how many colorpickers are present.

---

## Validation

- Accept only valid CSS hex colors: `#rgb` or `#rrggbb` (6-char normalized).
- The colorpicker widget already enforces this pattern on the input field.
- In `render_brand_token_overrides()`, skip any value that doesn't match `/^#[0-9a-fA-F]{3,6}$/`
  to prevent CSS injection even though settings are admin-only.

---

## Out of Scope

- **Font settings** — No existing Google Fonts loader infrastructure. Fonts require both a
  `font-family` token override AND a `<link>` to load the font file. Defer to a follow-on spec.
- **Radius / shadow tokens** — Lower value; defer.
- **Per-theme settings** — These settings are site-wide. Theme-specific palette enforcement remains
  a per-theme concern handled in the theme's own `PublicPage.php`.

---

## Files Touched

| File | Change |
|---|---|
| `/assets/css/custom.css` | Renamed to `joinery-styles.css`; old file deleted |
| `/includes/PublicPageBase.php` | Updated `render_base_assets()` href to `joinery-styles.css?v=1`; added new `render_brand_token_overrides()` method; added one call to it in `global_includes_top()` |
| `/adm/admin_settings.php` | Added "Brand & Appearance" section with 5 colorpicker fields using `'frequency'` sort |
| `/includes/FormWriterV2Base.php` | Added `'frequency'` as a new sort option in `extractThemeColors()`; updated docblock |
| `/theme/jeremytunnell-html5/includes/PublicPage.php` | Removed hardcoded token override block |
