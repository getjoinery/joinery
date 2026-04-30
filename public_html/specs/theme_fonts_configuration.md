# Theme Fonts Configuration

## Goal

Move hardcoded Google Fonts URLs out of theme `PublicPage.php` files and into each theme's `theme.json`. Changing fonts, self-hosting them, or omitting external fonts entirely should be a config edit, not a PHP edit.

This is one of three specs split out from the prior `external_service_abstraction.md`. The other two — scheduling provider abstraction and mailing list provider abstraction — are independent and unrelated.

---

## Open Decisions

### D1: CSS-embedded font URLs

Three themes import Google Fonts via `@import` or `url()` inside `assets/css/style.css`, which is invisible to the `theme.json` approach:
- `theme/jeremytunnell-html5/assets/css/style.css`
- `theme/phillyzouk-html5/assets/css/style.css`
- `theme/linka-reference-html5/assets/css/style.css`

Options:
- **A.** Migrate them: edit each `style.css` to remove the import, add a `fonts` block to that theme's `theme.json`.
- **B.** Scope them out. Note as a known issue. These themes keep their CSS-embedded approach.

Recommendation: **B**. Removing CSS imports may shift specificity or load order in subtle ways; not worth the risk for themes that may already be tuned around the current behavior.

---

## Current State

Every theme's `PublicPage.php` (or the shared `includes/PublicPage*.php` it extends) hardcodes `fonts.googleapis.com` URLs and family names. There is no configuration. The `theme.json` config exists but has no font field.

**Files with hardcoded Google Fonts URLs (verified against current source):**
- `includes/PublicPage.php` — Inter (used by the `default` theme; theme has no override)
- `includes/PublicPageJoinerySystem.php` — Open Sans + Poppins (admin and `joinery-system` theme)
- `includes/PublicPageFalcon.php` — Open Sans + Poppins (used by deprecated `devonandjerry` theme)
- `theme/empoweredhealth-html5/includes/PublicPage.php` — Poppins
- `theme/zoukroom-html5/includes/PublicPage.php` — Nunito Sans
- `theme/getjoinery/includes/PublicPage.php` — Inter + Manrope
- `plugins/scrolldaddy/includes/PublicPage.php` — DM Sans + Poppins

---

## Design

### `theme.json` schema

Add an optional `fonts` block:

```json
{
  "name": "Default Theme",
  "cssFramework": "html5",
  "fonts": {
    "url": "https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap",
    "preconnect": [
      "https://fonts.googleapis.com",
      "https://fonts.gstatic.com"
    ]
  }
}
```

- `url` — single stylesheet URL (multi-family fonts are loaded via Google's `&family=` combined URL)
- `preconnect` — array of origins to emit `<link rel="preconnect">` tags for. URLs containing `gstatic` get `crossorigin`.

Omitting `fonts` entirely means no external fonts are loaded. Themes using self-hosted or system fonts simply leave it out.

### `render_font_links()` method on `PublicPageBase`

```php
protected function render_font_links() {
    $fonts = ThemeHelper::config('fonts');
    if (!is_array($fonts)) return;

    if (!empty($fonts['preconnect']) && is_array($fonts['preconnect'])) {
        foreach ($fonts['preconnect'] as $url) {
            $attr = (strpos($url, 'gstatic') !== false) ? ' crossorigin' : '';
            echo '<link rel="preconnect" href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $attr . '>' . "\n";
        }
    }
    if (!empty($fonts['url'])) {
        echo '<link href="' . htmlspecialchars($fonts['url'], ENT_QUOTES) . '" rel="stylesheet">' . "\n";
    }
}
```

`ThemeHelper::config('fonts')` already works — it reads any top-level `theme.json` key via the existing `ComponentBase::get()` accessor.

---

## Implementation

### Phase A: Plumbing
1. Add `render_font_links()` to `PublicPageBase`.
2. No theme migrations yet — `render_font_links()` is callable but no theme uses it.

### Phase B: Migrate themes one at a time
For each theme:
1. Add a `fonts` block to its `theme.json`.
2. Replace the hardcoded `<link>` tags in its `PublicPage.php` (or the shared `includes/PublicPage*.php` it extends) with `<?php $this->render_font_links(); ?>`.
3. Verify in a browser: load a page using that theme; confirm the Network tab shows only the expected font requests and the rendered font-family is unchanged.

Suggested order (simplest to most complex):
1. `default` theme (uses `includes/PublicPage.php`)
2. `getjoinery`
3. `empoweredhealth-html5`
4. `zoukroom-html5`
5. `joinery-system` (admin theme — uses `includes/PublicPageJoinerySystem.php`)
6. `devonandjerry` (uses `includes/PublicPageFalcon.php`)
7. `plugins/scrolldaddy` (plugin-provided `PublicPage.php`)

Each theme migration is one self-contained change.

---

## Edge Cases

### Themes without `theme.json`
If a theme has no `theme.json` or no `fonts` key, `render_font_links()` outputs nothing. This is the correct behavior for themes using self-hosted or system fonts.

### Admin interface
The admin always uses the `joinery-system` theme via `PublicPageJoinerySystem`. Its font config goes in `theme/joinery-system/theme.json`.

### CSS-embedded fonts
Per Open Decision D1: scoped out. The three affected themes continue using `@import` inside their `style.css`.

---

## File Changes Summary

| File | Action |
|---|---|
| `includes/PublicPageBase.php` | **Modify** — add `render_font_links()` |
| `includes/PublicPage.php` | **Modify** — remove hardcoded font links |
| `includes/PublicPageJoinerySystem.php` | **Modify** — remove hardcoded font links |
| `includes/PublicPageFalcon.php` | **Modify** — remove hardcoded font links |
| `theme/default/theme.json` | **Modify** — add `fonts` config |
| `theme/getjoinery/theme.json` + `includes/PublicPage.php` | **Modify** |
| `theme/empoweredhealth-html5/theme.json` + `includes/PublicPage.php` | **Modify** |
| `theme/zoukroom-html5/theme.json` + `includes/PublicPage.php` | **Modify** |
| `theme/joinery-system/theme.json` | **Modify** — add `fonts` config |
| `theme/devonandjerry/theme.json` | **Modify** — add `fonts` config (consumed by Falcon) |
| `plugins/scrolldaddy/theme.json` (or equivalent) + `includes/PublicPage.php` | **Modify** |

---

## Testing

For each migrated theme:
- Load a public page rendered by that theme; confirm the rendered font-family is unchanged.
- Open the browser Network tab; confirm only the expected `fonts.googleapis.com` request is made (no duplicates, no missing fonts).
- Temporarily remove the `fonts` key from `theme.json`; confirm no external font requests are made.
- For the admin theme: verify the admin interface still loads correct fonts.
