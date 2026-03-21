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

## Recommendation: Standalone `docs/routing.md`

### Decision: Create a standalone doc, not a section in existing docs

After auditing all existing documentation, the recommendation is to create `docs/routing.md` as a standalone document. Here's the analysis:

### What's Missing That Developers Actually Need

A developer who wants to add a page, wire up an AJAX endpoint, or debug why a route isn't matching has **no doc to open**. Here's what they'd find if they went looking:

**The most common task is undocumented.** Creating `views/foo.php` to get a `/foo` route — the view directory fallback — is how most pages are added. It's mentioned in exactly one place: a code comment buried at `serve.php:143-146`. A developer who doesn't already know this convention will instead add an explicit route to serve.php every time, because that's what the existing docs imply they should do. CLAUDE.md step 5 says "Add route to `serve.php` if needed" without ever saying when it's NOT needed.

**Logic file auto-loading is undocumented.** The convention that `logic/foo_logic.php` is automatically loaded when `views/foo.php` renders is nowhere in any doc. A dev has to read the view files and notice the pattern, or read RouteHelper/PublicPage source code.

**When to use serve.php vs. not is undocumented.** There's no decision guide. Model routes, feature-flagged routes, wildcard routes, and permission-gated routes all require explicit serve.php entries. Simple view pages don't. This distinction is critical and exists only in tribal knowledge.

**Route option reference is scattered.** The full set of dynamic route options (`view`, `model`, `model_file`, `check_setting`, `min_permission`, `default_view`, `valid_page`, `var_name`) is documented in `serve.php` code comments but not in any developer-facing doc. A dev would need to know to read lines 8-92 of serve.php to find this.

**Existing docs cover routing only in passing:**
- `docs/plugin_developer_guide.md` has route processing order and view resolution chain, but framed entirely for plugin/theme developers — buried in a 980+ line doc that a dev adding a simple page would never open
- `CLAUDE.md` has 3 lines on routing (entry point, theme override, plugin admin) and RouteHelper method signatures — no usage guidance
- The 3 implemented routing specs (`min_permission`, `routehelper_improvements`, `content_route_unification`) are implementation records, not developer guidance

### Why Standalone (not adding to an existing doc)

| Option | Pros | Cons |
|--------|------|------|
| **Add to plugin_developer_guide.md** | Routing info already partially there | Already 980+ lines; routing is a core concept, not plugin-specific; devs adding a simple page won't open a plugin guide |
| **Add to CLAUDE.md** | Central reference | CLAUDE.md is already very long; routing deserves navigable sections with examples |
| **Add to admin_pages.md** | Admin routes are common | Only covers admin context; misses public pages, AJAX, API |
| **Standalone `docs/routing.md`** | Focused, findable, linkable; matches existing doc pattern (`docs/email_system.md`, `docs/formwriter.md`, etc.) | One more file (but fits the established pattern) |

**The strongest argument for standalone:** Every other major subsystem has its own doc (email, forms, deletion, components, photos, scheduled tasks, validation, settings). Routing is more fundamental than most of these and handles every single request. It deserves the same treatment.

### What the Doc Should Cover (developer-facing only)

The doc should answer the questions devs actually ask, in priority order:

1. **"How do I add a new page?"** — View fallback + logic auto-loading (the #1 gap)
2. **"Do I need to touch serve.php?"** — Decision tree: fallback vs. explicit route
3. **"What's the route processing order?"** — For debugging "why isn't my route matching?"
4. **"How do views resolve across themes/plugins?"** — Override chain
5. **"What options can I put on a route?"** — Reference table of all dynamic route options
6. **"How do model-based routes work?"** — `/post/{slug}` pattern with examples
7. **"How do admin/ajax/API routes work?"** — Common patterns with correct URL format
8. **"How do I debug routing issues?"** — `?debug_routes=1`, error logs, common mistakes

### What the Doc Should NOT Cover

- RouteHelper internals (method implementations, regex building, etc.)
- How route unification was implemented (that's in the implemented spec)
- Performance optimizations inside RouteHelper
- How `processRoutes()` works step by step

### Cross-Reference Plan

Once `docs/routing.md` exists:
- **`CLAUDE.md`**: Update "Routing & Theme System" section to link to `docs/routing.md`, keep only the critical one-liner rules (no .php in URLs, entry point)
- **`docs/plugin_developer_guide.md`**: Keep existing routing sections but add a note: "For complete routing documentation, see [Routing](routing.md)" — don't duplicate
- **`CLAUDE.md` docs index**: Add `- [Routing](docs/routing.md) - URL routing, view fallback, route configuration` to the documentation index

---

## Source Material

All content can be derived from:
- `serve.php` lines 8-92 (comprehensive code comments)
- `serve.php` route definitions (lines 95-170, working examples)
- `includes/RouteHelper.php` (implementation details — for accuracy, not for inclusion)
- `docs/plugin_developer_guide.md` (view resolution chain, route processing order, theme routing examples)
- `specs/implemented/min_permission_routing_spec.md` (min_permission feature)
- `specs/implemented/content_route_unification.md` (unified dynamic route type, route configuration reference)
