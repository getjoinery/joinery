# Plugin Docs Auto-Discovery

## Summary

Allow each plugin to ship its own `docs/` directory that is automatically merged into the documentation viewer — both the admin help page (`/admin/admin_help`) and the public docs page (`/documentation`) — with no configuration required. Mirrors the convention already used for plugin views and logic files.

## Current State

`DocsScanner::scan($docs_dir)` accepts a single directory. Both `admin_help_logic.php` and `documentation_logic.php` pass `PathHelper::getIncludePath('docs')` as the only source. Plugin documentation currently lives in the core `docs/` directory alongside platform docs, or is not documented at all.

`DocsScanner::load_doc($doc_key, $docs_dir)` validates the resolved path against a single `$docs_dir` root for path-traversal protection. This is the main structural constraint: loading a doc requires knowing which root dir the key belongs to.

## Goal

Drop a `docs/` folder inside any plugin directory and it appears in the docs viewer automatically — grouped under the plugin name — in both the admin and public viewers. No route config, no registration, no changes to the viewer pages.

## Key Design Decision: Doc Key Format

Current core doc keys: `routing`, `admin_pages`, `subfolder/filename` (max one subfolder level).

Plugin doc keys must be namespaced to avoid collision and to let `load_doc()` resolve the correct root. The format is:

```
plugin/{plugin_name}/{slug}
```

Examples: `plugin/bookings/overview`, `plugin/server_manager/api`

This is clean in URLs (`?doc=plugin/bookings/overview`), readable, backward-compatible (existing keys don't start with `plugin/`), and trivial to parse. Three-segment keys are currently rejected by `load_doc()` — that restriction is lifted only for the `plugin/` prefix.

## Changes Required

### 1. `DocsScanner` — two new static methods, update `load_doc()`

**`DocsScanner::discover_plugin_docs()`**

Scans `plugins/*/docs/` and returns an array of `[plugin_name => absolute_dir_path]` for each plugin that has a readable `docs/` directory.

```php
public static function discover_plugin_docs() {
    $sources = [];
    $plugins_dir = PathHelper::getIncludePath('plugins');
    if (!is_dir($plugins_dir)) return $sources;
    foreach (scandir($plugins_dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $docs_path = $plugins_dir . '/' . $entry . '/docs';
        if (is_dir($docs_path) && is_readable($docs_path)) {
            $sources[$entry] = $docs_path;
        }
    }
    return $sources;
}
```

**`DocsScanner::scan_all($core_dir)`**

Calls `scan($core_dir)` for core docs, then discovers and scans each plugin docs dir. Plugin docs are merged into the tree under a group key of `plugin/{plugin_name}`, and each doc's `key` is prefixed with `plugin/{plugin_name}/`.

```php
public static function scan_all($core_dir) {
    $tree = self::scan($core_dir);  // existing method, unchanged

    foreach (self::discover_plugin_docs() as $plugin_name => $plugin_docs_dir) {
        $plugin_tree = self::scan($plugin_docs_dir);
        // Remap keys and groups with plugin prefix
        foreach ($plugin_tree as $group => $docs) {
            $new_group = 'plugin/' . $plugin_name;
            if (!isset($tree[$new_group])) $tree[$new_group] = [];
            foreach ($docs as $doc) {
                $doc['key'] = 'plugin/' . $plugin_name . '/' . $doc['key'];
                $doc['group'] = $new_group;
                $tree[$new_group][] = $doc;
            }
        }
    }
    return $tree;
}
```

**`DocsScanner::load_doc($doc_key, $docs_dir)` — updated**

The current method validates `$doc_key` against a single `$docs_dir`. Update it to also handle the `plugin/{name}/{slug}` format:

- If the key starts with `plugin/`, split into `[plugin_name, slug]`, resolve the plugin's docs dir via `PathHelper::getIncludePath("plugins/{plugin_name}/docs")`, and validate against that dir.
- Validation rules for plugin keys: `plugin_name` must match `^[a-zA-Z0-9_-]+$`; `slug` may be one or two segments (to support subdirs inside plugin docs), each matching `^[a-zA-Z0-9_-]+$`; realpath must resolve inside the plugin's docs dir.
- Core key behavior unchanged.

Signature stays `load_doc($doc_key, $docs_dir)` — `$docs_dir` is used for core keys; plugin keys self-resolve their dir.

The return shape gains a `description` field for both branches:

```php
return array(
    'content' => file_get_contents($filepath),
    'title' => self::extract_title($filepath, $basename),
    'description' => self::extract_description($filepath),
    'error' => '',
);
```

This lets the logic files drop their separate `extract_description()` call, which currently builds a core-docs path that doesn't exist for plugin keys (and so produces an empty meta description for plugin docs).

### 2. `MarkdownRenderer::rewrite_doc_links()` — add an optional key prefix

Today's signature: `rewrite_doc_links($html, $docs_dir, $base_url = '/admin/admin_help')`. The rewriter resolves bare `.md` filenames against `$docs_dir` and emits `href="$base_url?doc=$doc_key"`. For plugin docs we want the same ergonomics — `[see api](api.md)` should rewrite cleanly — but resolving against the *plugin's* docs dir, and with the resulting key prefixed by `plugin/{plugin_name}/` so the viewer routes correctly.

Add a fourth optional parameter:

```php
public static function rewrite_doc_links($html, $docs_dir, $base_url, $key_prefix = '') {
    // ...existing logic, unchanged, until the final href construction...
    return 'href="' . $base_url . '?doc=' . htmlspecialchars($key_prefix . $doc_key) . $anchor . '"';
}
```

Core-doc callers pass no prefix → behavior is unchanged. Plugin-doc callers pass the plugin's docs dir and `plugin/{plugin_name}/` as the prefix → bare `.md` references inside the plugin's docs resolve against the plugin's own files and produce correct viewer URLs.

What still doesn't work after this change: cross-plugin links (a doc in plugin A linking to a doc in plugin B by bare filename), and `../` traversal out of one plugin's docs dir. Those remain out of scope; the convention for those is to use a full viewer URL.

### 3. `admin_help_logic.php` and `documentation_logic.php`

Three small changes in each:

1. Replace `DocsScanner::scan($docs_dir)` with `DocsScanner::scan_all($docs_dir)`.
2. Drop the separate `DocsScanner::extract_description($docs_dir . '/' . $selected_doc . '.md')` call after `load_doc()` — read `$result['description']` from the return instead. The old call builds a core-docs path that doesn't exist for plugin keys.
3. When `$selected_doc` starts with `plugin/`, call `rewrite_doc_links()` with the plugin's docs dir and a `plugin/{plugin_name}/` key prefix; otherwise call it as today. A small helper inside each logic file is sufficient — no new method on `DocsScanner` needed.

### 4. Sidebar and landing display

Both `DocsScanner::render_viewer()` (sidebar) and `DocsScanner::render_landing()` (overview page when no doc is selected) format group labels by running `ucwords(str_replace(...))` on the group key. For `plugin/bookings` this renders as "Plugin/Bookings" in both places. The fix is the same one-line change, applied in both call sites:

```php
$display_label = preg_replace('/^plugin\//', '', $group);
$group_title = ucwords(str_replace(['_', '-'], ' ', $display_label));
```

This shows "Bookings" in the sidebar and on the auto-generated landing page.

Optionally, plugin groups can be visually separated from core groups in the sidebar (e.g. a "Plugins" section header before the first plugin group). Not required for the initial implementation.

## Security

- Plugin docs dirs are resolved via `PathHelper::getIncludePath()` — the same trusted mechanism used for all plugin file access.
- `load_doc()` applies `realpath()` confinement separately per source dir. A key containing `plugin/bookings/../../server_manager/secret` will fail the realpath check against the bookings docs dir.
- Plugin name validation (`^[a-zA-Z0-9_-]+$`) prevents traversal through the plugin name segment itself.
- Max depth: plugin doc slug may be one or two segments (matching the existing core limit of one subfolder). Three-segment slugs after the `plugin/{name}` prefix are rejected.

## What Plugin Authors Do

Create `plugins/{plugin_name}/docs/` and add `.md` files. That's it. No registration, no serve.php entry, no CLAUDE.md update needed. The docs appear automatically in both the admin and public viewers grouped under the plugin's name.

### Conventions

- **Sibling links use bare `.md` filenames, just like core docs.** Inside a plugin doc, `[see api](api.md)` rewrites correctly to the viewer URL for that sibling. Cross-plugin and parent-traversal links are not rewritten — use a full viewer URL (`[see X](/documentation?doc=plugin/other_plugin/foo)` or `[see routing](/documentation?doc=routing)`) for those.
- **Plugin docs are visible regardless of activation state.** A plugin's docs appear in both viewers as soon as the directory is on disk; deactivating the plugin does not hide them. If a doc shouldn't be visible yet, don't ship it in the directory.
- **Don't create a `docs/plugin/` subdirectory in the core docs root.** That path would collide with the `plugin/` key namespace and the file would not be reachable through the viewer.

## Out of Scope

- Per-plugin index pages (the existing auto-generated landing page already lists all groups including plugins)
- Cross-plugin and parent-traversal `.md` link rewriting (only bare-filename sibling links inside the same plugin's docs are auto-rewritten; everything else uses a full viewer URL — see Conventions)
- Preserving plugin subfolder structure as nested sidebar groups (subfolders inside a plugin's `docs/` are flattened into one group; revisit if any plugin grows enough docs to need this)
- Plugin docs appearing on a different URL than core docs (all docs stay at `/documentation` and `/admin/admin_help`)
- Access control per plugin docs (all plugin docs are visible to anyone who can see the viewer, including docs from deactivated plugins)

## Implementation Plan

1. Add `discover_plugin_docs()` and `scan_all()` to `DocsScanner`
2. Update `load_doc()` in `DocsScanner` to handle `plugin/` keys, and to return `description` alongside `content` and `title`
3. Update `render_viewer()` and `render_landing()` to strip the `plugin/` prefix from group labels
4. Update `MarkdownRenderer::rewrite_doc_links()` to accept an optional `$key_prefix` parameter prepended to the resolved doc key
5. Update `admin_help_logic.php`: switch `scan` → `scan_all`, use `$result['description']` from `load_doc()` instead of the separate `extract_description()` call, and pass the plugin's docs dir + `plugin/{name}/` prefix to `rewrite_doc_links()` when rendering a plugin doc
6. Update `documentation_logic.php`: same three changes
7. Validate PHP syntax (`php -l`) on all modified files; run `validate_php_file.php` on each
8. Test: drop a sample doc into any plugin's `docs/` dir and verify it (a) appears in both viewers grouped under the plugin name, (b) renders without error, (c) has a non-empty meta description, (d) a bare-filename link to a sibling doc inside the plugin rewrites to the correct viewer URL, and (e) a traversal attempt (e.g. `?doc=plugin/{name}/../escape`) is rejected

## Phase 2: Unified Path-to-Key Mapping (cross-viewer cross-doc links)

### Problem

Phase 1 left one rough edge: a core doc linking to a plugin doc (or a plugin doc linking to another plugin's doc) currently has no rewriter support. The author has to write a hardcoded `[X](/documentation?doc=plugin/{name}/{slug})` URL — which works, but always lands the user in the public viewer, even when they clicked the link from inside `/admin/admin_help`. The viewer switches under the user's feet.

The Phase 1 rewriter was deliberately scoped to sibling links inside the current doc's own directory, which is why this gap exists.

### Goal

Phase 2 collapses author rules to a single one: **write a markdown link to a `.md` file by whatever path you'd use to open that file**. Sibling filename, absolute path from the web root, `..`-relative — all are accepted as long as they resolve to a real doc file. The rewriter normalizes everything to the active viewer's URL. No convention table to memorize, no "always use absolute paths" footnote, no awareness of the viewer at all.

### Design: one place that knows the path↔key mapping

Today the path↔key knowledge is duplicated. `DocsScanner::load_doc()` decodes keys back into filesystem paths (with its `plugin/` branch). `MarkdownRenderer::rewrite_doc_links()` encodes filesystem paths into keys (with its `$key_prefix` workaround). Adding more recognized input shapes to the rewriter inline would deepen the duplication.

Factor the mapping into a single static method on `DocsScanner` that accepts *any* path shape and uses realpath confinement for safety:

```php
public static function path_to_key($href, $current_doc_dir) {
    // Returns a canonical doc key (e.g. 'routing', 'plugin/bookings/api'),
    // or null if $href doesn't resolve to a doc file inside an allowed root.
    //
    // Resolution:
    //   - $href is resolved against $current_doc_dir (handles bare filenames,
    //     relative paths with .., and absolute paths from the web root)
    //   - The resolved path is passed through realpath() for canonicalization
    //   - The canonical path must live inside public_html/docs/ or
    //     public_html/plugins/{name}/docs/ — anything else returns null
    //   - The key is derived from the canonical path's location in the tree
    //
    // External URLs (http://, https://, mailto:, etc.) return null untouched.
}
```

The rewriter collapses to:

```php
public static function rewrite_doc_links($html, $current_doc_dir, $base_url = '/admin/admin_help') {
    return preg_replace_callback('/href="([^"]*\.md(?:#[^"]*)?)"/', function($matches) use ($current_doc_dir, $base_url) {
        // ...split off anchor...
        $key = DocsScanner::path_to_key($href, $current_doc_dir);
        if ($key === null) return $matches[0];
        return 'href="' . $base_url . '?doc=' . htmlspecialchars($key) . $anchor . '"';
    }, $html);
}
```

The `$key_prefix` parameter introduced in Phase 1 goes away — `path_to_key()` derives the right key from where the resolved file actually lives. The rewriter no longer knows about plugin/core layout rules; it just asks "is this a known doc?" and emits.

**Why `..` is safe now:** `realpath()` canonicalizes any path (resolving `..`, symlinks, double-slashes) into a single absolute path. After canonicalization the method checks whether that path starts with `public_html/docs/` or matches `public_html/plugins/{name}/docs/` where `{name}` matches `^[a-zA-Z0-9_-]+$`. A traversal attempt like `../../../etc/passwd` canonicalizes to `/etc/passwd`, fails the root check, and returns null. The link is left untouched.

### Changes Required

**1. `DocsScanner::path_to_key($href, $current_doc_dir)` — new method**

- If `$href` matches `^[a-z]+://`, return null (external).
- Strip an `#anchor` suffix if present (defensive — the rewriter strips it too).
- Build a candidate path: if `$href` starts with `/`, treat as web-root absolute (`public_html . $href`); otherwise concatenate `$current_doc_dir . '/' . $href`.
- `$canonical = realpath($candidate)`. If false, return null.
- Confirm `$canonical` ends in `.md`. If not, return null.
- Determine which root it lives in:
  - If under `realpath(PathHelper::getIncludePath('docs'))`, return the relative path with `.md` stripped (e.g. `subfolder/foo`). Reject if more than one subfolder deep (matches `load_doc()`'s core limit).
  - If under `realpath(PathHelper::getIncludePath('plugins'))` and matches `plugins/{name}/docs/{rest}` where `{name}` is `^[a-zA-Z0-9_-]+$`, return `plugin/{name}/{rest-with-.md-stripped}`. Reject if `{rest}` is more than one subfolder deep.
  - Otherwise return null.

**2. `MarkdownRenderer::rewrite_doc_links()` — simplify**

- Drop the `$key_prefix` parameter (introduced in Phase 1).
- Drop the inline `..`-skip check.
- Drop the inline `/docs/` strip, segment validation, and file-exists check.
- Body becomes "ask `path_to_key()`, emit if non-null."

**3. `admin_help_logic.php` and `documentation_logic.php` — drop the prefix-handling branch**

The Phase 1 "if `$selected_doc` starts with `plugin/`, pass the plugin dir + prefix" conditional collapses to a single call: `rewrite_doc_links($rendered_html, $current_doc_dir, $base_url)` where `$current_doc_dir` is the directory the currently-loaded doc lives in. The logic file still computes that directory (core docs dir vs. specific plugin's docs dir), but no prefix is passed.

**4. Revert Phase 1's hardcoded `/documentation` URLs**

Phase 1 left 8 cross-doc links pointing to absolute `/documentation?doc=plugin/...` URLs to avoid bouncing through the rewriter. After Phase 2 they can be real paths in whatever form is natural:

- `docs/index.md` — 4 lines
- `docs/deploy_and_upgrade.md` — 1 line
- `docs/installation.md` — 1 line
- `docs/email_system.md` — 1 line
- `docs/plugin_developer_guide.md` — 1 line

Each `(/documentation?doc=plugin/{name}/overview)` becomes a real filesystem path — `(/plugins/{name}/docs/overview.md)` is one valid form; `(../plugins/{name}/docs/overview.md)` from within `docs/` is another; both resolve identically.

The 7 `../../../specs/...` links inside `plugins/joinery_ai/docs/overview.md` (fixed in Phase 1 to point at specs files in the editor) are unaffected — they target `specs/`, which isn't a docs root, so `path_to_key()` returns null and the rewriter leaves them untouched.

### Author rules after Phase 2

One: **write a markdown link to the `.md` file by its path**. The viewer figures out the rest.

### Out of Scope for Phase 2

- Unifying `load_doc()` through the same mapping (it's the inverse direction — key → path — and works fine today). Could be a Phase 3 if the duplication becomes painful.
- Linking to non-doc files (e.g., a spec file or a source file). Those continue to pass through the rewriter untouched and behave as normal hrefs.

### Implementation Plan

1. Add `DocsScanner::path_to_key()` with realpath-based root confinement
2. Simplify `MarkdownRenderer::rewrite_doc_links()`: drop `$key_prefix` and the inline path logic, call `path_to_key()`
3. Update `admin_help_logic.php` and `documentation_logic.php`: pass `$current_doc_dir` only (no prefix branch)
4. Revert the 8 hardcoded `/documentation?doc=plugin/...` cross-references to filesystem-path form
5. Validate PHP syntax and run `validate_php_file.php` on all modified files
6. Test: from `/admin/admin_help?doc=email_system`, click the Email Forwarding cross-doc link and confirm it lands at `/admin/admin_help?doc=plugin/email_forwarding/overview` (not `/documentation?...`); same test mirrored from the public viewer; sibling links inside core docs and inside plugin docs still rewrite correctly; a `..`-relative path between two docs rewrites correctly; a `..`-relative path that escapes both docs roots (e.g. `../../../etc/passwd`) is left untouched; external `http://` and `mailto:` links untouched
