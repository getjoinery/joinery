# Routing Documentation Spec

**Purpose:** Create a `docs/routing.md` that documents how the routing system works. Currently the most complete documentation lives in `serve.php` header comments (lines 8-92). There's no standalone doc, and the key behaviors aren't obvious without reading the code.

**Last Updated:** 2026-03-21

---

## Problem

Routing behavior is spread across:
- `serve.php` header comments (most complete, but it's code comments)
- `docs/plugin_developer_guide.md` (view resolution chain only)
- `CLAUDE.md` (brief RouteHelper mention)

Key things that aren't clearly documented anywhere:

### 1. View Directory Fallback (most important gap)

Placing `views/foo.php` (and optionally `logic/foo_logic.php`) automatically creates a `/foo` route with no `serve.php` changes. This is the primary way simple pages are added, but it's only documented in a code comment at `serve.php:143-146`:

```
// NOTE: Simple routes like '/login', '/register', '/logout', '/products' ...
// are now UNNECESSARY - handled by view directory fallback.
// They will automatically resolve to views/login.php, views/products.php, etc.
```

This should be front and center in the routing docs.

### 2. Logic File Auto-Loading Convention

The convention that `logic/foo_logic.php` is automatically loaded for `views/foo.php` (via `getThemeFilePath`) isn't documented outside of code. Developers need to know that creating both files is all that's needed for a new page with business logic.

### 3. Route Processing Order

Documented in `serve.php:13` but should be in a standalone doc:
```
static → plugins → custom → dynamic → view fallback → 404
```

### 4. Theme Override Chain for Views

Documented in the plugin developer guide but should also appear in routing docs:
```
theme/{theme}/views/{view}.php → plugins/{plugin}/views/{view}.php → views/{view}.php → 404
```

### 5. When You DO Need a serve.php Route

Not obvious when the fallback is sufficient vs. when you need an explicit route. Cases that need explicit routes:
- Model-based routes (`/post/{slug}` — needs model loading)
- Feature-flag-gated routes (`check_setting`)
- Wildcard/placeholder routes (`/admin/*`, `/profile/*`)
- Routes with non-standard view paths
- Custom closure routes with complex logic

Simple view pages (`/login`, `/cart`, `/notifications`) do NOT need explicit routes.

### 6. Admin Routes

`/admin/*` maps to `adm/{path}` — admin files live in `/adm/` not `/admin/`. Plugin admin routes auto-discover at `/plugins/{plugin}/admin/*`.

---

## Suggested Doc Structure

```
docs/routing.md

1. Quick Start (adding a new page)
   - Just create views/foo.php → /foo works
   - Add logic/foo_logic.php for business logic → auto-loaded
   - That's it. No route config needed.

2. Route Processing Order
   - static → plugins → custom → dynamic → view fallback → 404

3. View Resolution Chain
   - theme → plugin → base views → 404

4. When to Add Explicit Routes
   - Model routes, feature flags, wildcards, custom logic

5. Route Configuration Reference
   - Static route options
   - Dynamic route options (view, model, model_file, check_setting, etc.)
   - Custom route closures

6. Common Patterns
   - Simple page, model page, admin page, AJAX endpoint, API endpoint
```

---

## Source Material

All content can be derived from:
- `serve.php` lines 8-92 (comprehensive code comments)
- `serve.php` route definitions (lines 95-170, working examples)
- `includes/RouteHelper.php` (implementation details)
- `docs/plugin_developer_guide.md` (view resolution chain section)
