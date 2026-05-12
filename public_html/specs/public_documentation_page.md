# Public Documentation Page

## Summary

Expose the existing markdown documentation under `docs/` at a public URL `/documentation`, mirroring the admin viewer at `/admin/admin_help` but without a permission check and rendered through the public theme. All files in `docs/` are exposed — no allowlisting or curation.

As part of this work, the existing admin viewer's Bootstrap-style markup (`row`, `col-lg-3`, `col-md-6`, `card`, `nav-link`) is removed and replaced with semantic HTML + vanilla CSS, so the same markup can be used in both the admin and public viewers (both themes are vanilla HTML5). This is an explicit deliverable, not a side effect.

## Current State

- `adm/admin_help.php` and `adm/logic/admin_help_logic.php` already scan `docs/`, render markdown via `includes/MarkdownRenderer.php`, and present a sidebar + content layout. Permission gate is level 5.
- Both the admin theme (`joinery-system`) and the default public theme are vanilla HTML5 — no Bootstrap, no jQuery. Neither viewer should be using framework classes.
- However, the existing admin viewer leaks Bootstrap-style class names (`col-lg-3`, `card`, `nav-link`, `row`) into a vanilla theme, and its auto-generated landing markup uses `row`/`col-md-6`/`card`. This is a pre-existing violation of the project's "no Bootstrap in HTML5 views" rule and should be cleaned up as part of this work so the shared scanner output is vanilla everywhere.
- `MarkdownRenderer::rewrite_doc_links()` hardcodes the rewrite target to `/admin/admin_help?doc=...` (see lines 102–152). It cannot currently be reused for a non-admin caller.

## Goal

When a logged-out (or any) visitor navigates to `/documentation`, they see:
- A sidebar listing every file under `docs/`, grouped by subdirectory, matching the admin viewer's structure.
- The selected markdown rendered as HTML in the content area.
- A landing page (rendered from `docs/index.md` if present, otherwise auto-generated).
- Internal cross-doc links (`[Routing](routing.md)`, etc.) rewritten to `/documentation?doc=routing` instead of the admin URL.

## Requirements

### 1. Routing and View

- URL: `/documentation` and `/documentation?doc=<key>` (matching the admin viewer's `?doc=` convention).
- Create `views/documentation.php` and `logic/documentation_logic.php`. The view auto-discovers — no `serve.php` entry required.
- View uses `PublicPage` (theme-aware), not `AdminPage`. Render through the active public theme's header/footer.
- No permission check. Anyone, including anonymous visitors, can read.

### 2. Reuse Shared Scanning Logic

The doc-tree scanner, title extractor, description extractor, and doc loader currently live as private helpers (`_help_scan_docs`, `_help_load_doc`, `_help_generate_landing`, etc.) in `adm/logic/admin_help_logic.php`. Extract these into a shared helper — `includes/DocsScanner.php` (or equivalent) — and update both the admin logic and the new public logic to call into it. The two logic files should differ only in:

- Which page object they instantiate (AdminPage vs PublicPage).
- Permission check (level 5 vs none).
- The link-rewrite base URL (see Requirement 4).

Do not duplicate the scanning logic between the two files.

### 3. Vanilla HTML5 Markup (Applies to Both Viewers)

Both viewers run inside vanilla themes. Neither should use Bootstrap class names (`col-lg-3`, `card`, `nav-link`, `row`, `col-md-6`).

- Replace the existing admin viewer's Bootstrap-style classes with semantic HTML (`<aside>`, `<main>`, `<nav>`, `<article>`) and view-scoped CSS. The public view uses the same markup.
- Refactor `_help_generate_landing()` (now in the shared scanner) to emit framework-neutral markup — e.g., a CSS-grid `<ul>` of `<li>` cards — reused by both viewers.
- The sidebar should remain sticky on desktop and collapse above the content on mobile (single CSS media query is sufficient).
- `MarkdownRenderer::get_css()` is reused as-is for the rendered content area — it already styles headers, code blocks, tables, etc. independently of any framework.
- The view-scoped CSS for the sidebar/layout can live alongside the markdown CSS (e.g., extend `MarkdownRenderer::get_css()` or add a small companion stylesheet) so both viewers share it.

### 4. Internal Doc Link Rewriting

`MarkdownRenderer::rewrite_doc_links()` currently hardcodes `/admin/admin_help?doc=...`. Change the signature to accept a base URL:

```php
public static function rewrite_doc_links($html, $docs_dir, $base_url = '/admin/admin_help')
```

The admin viewer passes nothing (keeps the existing default). The public viewer passes `'/documentation'`. Anchors (`#section`) are preserved as today.

### 5. SEO and Metadata

- Page title: `Documentation` on the landing page, `<H1 title> | Documentation` when a doc is selected.
- Meta description: use the doc's auto-extracted description (already extracted by `_help_extract_description`) when one is selected; a static "Platform documentation" string on the landing page.
- Follow the conventions in `docs/seo_metadata.md` (canonical URL, OG tags) for both states.

### 6. Security

The existing admin scanner already enforces:
- Path segments must match `^[a-zA-Z0-9_-]+$`.
- Maximum one level of subdirectory.
- `realpath()` check confirms the resolved path is inside `docs/`.

These constraints apply unchanged to the public viewer. No additional access controls are added — the goal is full public read access to `docs/`.

### 7. Linking from the Site

Add a footer link (or otherwise surface `/documentation` from the public site) so visitors can find it. Exact placement is a small follow-up; the spec covers the page itself.

## Out of Scope

- Search across docs.
- Editing docs from the public viewer.
- Per-doc visibility controls / allowlisting (explicitly decided: expose everything).
- Rendering specs, CLAUDE.md, or anything outside `docs/`.
- Public versions of admin spec viewer or other admin-only content.

## Implementation Plan

1. Extract scanner helpers from `adm/logic/admin_help_logic.php` into `includes/DocsScanner.php` (scanner, title/description extractors, doc loader, landing-page generator). Update `admin_help_logic.php` to call the shared helper.
2. Replace Bootstrap-style classes in `adm/admin_help.php` and the landing-page generator with semantic HTML and view-scoped CSS. Confirm `/admin/admin_help` still renders cleanly.
3. Add `$base_url` parameter to `MarkdownRenderer::rewrite_doc_links()` with the existing admin URL as the default. Confirm existing admin behavior unchanged.
4. Create `logic/documentation_logic.php` (no permission check, uses shared scanner, passes `/documentation` as the link base).
5. Create `views/documentation.php` using `PublicPage` and the same semantic markup as the cleaned-up admin view. Reuse `MarkdownRenderer::get_css()` for the content area and the shared sidebar CSS.
6. Browse `/documentation` and `/documentation?doc=routing` in the test site browser; spot-check a few cross-doc links to confirm they rewrite to `/documentation?doc=...`.
7. Add a link to `/documentation` from the public footer (or appropriate public nav).

## Notes for Future Curation

If at some later point individual docs need to be hidden from the public viewer, the cleanest extension is a `public: false` frontmatter flag honored by the shared scanner — leaving the admin viewer's view of the same files unchanged. This is explicitly not part of the initial scope.
