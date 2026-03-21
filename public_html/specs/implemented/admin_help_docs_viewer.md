# Admin Help Page: Documentation Viewer

## Summary

Replace the stale hardcoded HTML help page (`adm/admin_help.php`) with a documentation viewer that renders the markdown files from the `docs/` directory.

## Current State

The current help page (`/admin/admin_help`) contains a hardcoded Vimeo video walkthrough and manually written HTML describing the system architecture, events, and products. This content is outdated and unmaintained. Meanwhile, the `docs/` directory contains 23 up-to-date markdown files covering every major subsystem.

### Existing Spec Viewer (Reusable Pattern)

The admin already has a markdown-based spec viewer at `adm/admin_specs.php` and `adm/admin_spec_view.php` that provides:

- **`render_markdown($text)`** — A custom regex-based markdown-to-HTML converter defined in `admin_spec_view.php` (lines 11-89). Supports: headers, bold, italic, code blocks with language classes, inline code, links, unordered/ordered lists, tables (Bootstrap-styled), horizontal rules, and paragraphs. Input is HTML-escaped first for XSS protection.
- **File discovery** — Scans a directory for `*.md` files, generates human-readable titles from filenames (underscores/hyphens to spaces, ucwords), sorts by modified time.
- **Security** — Uses `basename()` to prevent path traversal, validates `.md` extension, checks file existence and readability.
- **Styling** — CSS for `.spec-content` with dark code blocks, styled headers with bottom borders, table margins, and list padding.

This existing code should be extracted and reused rather than reimplemented. The `render_markdown()` function should be moved to a shared location (e.g., `includes/MarkdownRenderer.php`) so both the spec viewer and help page can use it. The CSS can similarly be shared.

**No external markdown libraries are installed** (no Parsedown, no league/commonmark in Composer). The custom parser handles the docs adequately since they primarily use headers, code blocks, lists, tables, and links.

### Existing Files to Modify

- **`adm/admin_help.php`** — Replace 150 lines of hardcoded HTML with the docs viewer
- **`adm/logic/admin_help_logic.php`** — Currently minimal (session check + page vars). Add doc file discovery and validation logic here.
- **`adm/admin_spec_view.php`** — Extract `render_markdown()` to shared location, update to use it

## Goal

When an admin visits `/admin/admin_help`, they should see:
- A sidebar or nav listing all available documentation files from `docs/`
- The selected markdown file rendered as HTML
- Default to an index/overview page (could render CLAUDE.md's documentation index or a dedicated index)

## Requirements

### 1. Markdown Rendering

- Extract the existing `render_markdown()` function from `adm/admin_spec_view.php` into a shared file (e.g., `includes/MarkdownRenderer.php`)
- Update `admin_spec_view.php` to use the shared version
- Use the same function in the new help page
- The existing renderer handles all markdown features used in the `docs/` files (headers, code blocks, tables, links, lists, bold/italic)
- XSS protection is already built in (htmlspecialchars before parsing)

#### Internal Doc Link Rewriting

The docs contain 25+ cross-references to each other using several link patterns. These must be rewritten so they navigate within the help viewer instead of to raw `.md` files.

**Patterns found in current docs (all must be handled):**

| Pattern in markdown | Example | Rewrite to |
|---|---|---|
| `(filename.md)` | `[Routing](routing.md)` | `?doc=routing` |
| `(/docs/filename.md)` | `[Admin Pages](/docs/admin_pages.md)` | `?doc=admin_pages` |
| `(filename.md#anchor)` | `[Validation](validation.md#overview)` | `?doc=validation#overview` |

**Links to leave alone (do not rewrite):**
- Links outside `docs/` — e.g., `(../../maintenance_scripts/...)`, `(../CLAUDE.md)`, `(/specs/implemented/...)`. These point to files the viewer can't render. Leave the href as-is; they'll be non-functional but harmless.
- External URLs (`https://...`) — already handled correctly by the existing renderer.

**Implementation:** Add a post-processing step after `render_markdown()` converts `[text](url)` to `<a href="url">`. Use a regex on the rendered HTML to find `href` values ending in `.md` (with optional `#anchor`), check if they resolve to a file within `docs/`, and rewrite the href to `?doc=name`. This is cleaner than trying to handle it during markdown parsing since the link patterns vary.

### 2. File Discovery & Titles

- Scan `docs/` directory recursively for `*.md` files, including subdirectories
- **Title extraction**: Read the first `# ` line from each file to use as the display title. Every existing doc already starts with a descriptive H1 (e.g., `# Email Forwarding`, `# Routing`, `# FormWriter Documentation`). Fall back to filename-based title generation (`email_system.md` → "Email System") only if no H1 is found.
- No frontmatter needed — the H1 heading is already authoritative and requires zero extra maintenance from doc authors
- Group files by subdirectory in the sidebar (top-level files first, then each subfolder as a collapsible group)
- Sort alphabetically within each group
- Skip non-markdown files (e.g., `example_class.php` currently lives in `docs/`)

### 3. Landing Page & Navigation

- URL pattern: `/admin/admin_help?doc=filename` (without .md extension)
- For subdirectory files: `/admin/admin_help?doc=subfolder/filename`
- Sidebar listing all available docs, grouped by folder with active highlighting
- **Landing page**: If `docs/index.md` exists, render it as the default page when no `?doc=` is specified
- **Auto-generated landing page**: If no `index.md` exists, generate a landing page that lists all available docs organized by folder — each doc shown with its H1 title and a brief description (first non-heading paragraph from the file, truncated). This gives admins a browsable overview of all documentation. Subfolder groups get their own section headings.
- The auto-generated landing page should remain useful as docs grow and subdirectories are added

### 4. Page Layout

- Use standard AdminPage layout (header, box, footer)
- Nav/sidebar for doc list on the left, rendered content on the right
- Responsive — content should be readable on smaller screens
- Reuse the `.spec-content` CSS from `admin_spec_view.php` (rename to something generic like `.markdown-content`)

### 5. Remove Stale Content

- Remove the hardcoded HTML help content entirely
- Remove the Vimeo video embed
- Move file discovery and validation logic into `admin_help_logic.php`

### 6. Security

- Only serve files from the `docs/` directory — do not allow path traversal (e.g., `?doc=../../config/Globalvars_site`)
- Validate that each path segment matches `^[a-zA-Z0-9_-]+$` — split on `/`, validate each part, then rejoin. This allows `?doc=subfolder/filename` while blocking `..` and other traversal.
- Maximum one level of subdirectory depth (reject paths with more than one `/`)
- Resolve the final path and confirm it starts with the `docs/` directory (belt-and-suspenders with `realpath()`)
- Files must exist and be readable
- Permission level 5 (standard admin) — unlike the spec viewer which requires level 10

## Implementation Plan

### Step 1: Extract shared markdown renderer
- Move `render_markdown()` from `admin_spec_view.php` to `includes/MarkdownRenderer.php`
- Move the associated CSS to the same file or a shared location
- Update `admin_spec_view.php` to require and use the shared version
- Verify spec viewer still works after refactor

### Step 2: Update admin_help_logic.php
- Recursive doc file scanning (scan `docs/` and subdirectories for `*.md` files)
- For each file, read the first `# ` line as the display title; fall back to filename-based title
- For the auto-generated landing page, also extract a description (first non-heading, non-empty paragraph, truncated to ~150 chars)
- Build a grouped structure: `{ 'top' => [...files], 'subfolder_name' => [...files] }`
- Validate `?doc=` parameter: split on `/`, validate each segment, max 1 subfolder deep, realpath check
- Read and parse selected doc content (or generate landing page if no doc specified and no `index.md`)
- Pass doc tree, selected doc key, and rendered HTML to page vars

### Step 3: Rewrite admin_help.php
- Replace hardcoded HTML with two-column layout
- Left sidebar: docs grouped by folder, each group collapsible, active doc highlighted
- Right content area: rendered markdown with shared CSS
- Landing page: render `docs/index.md` if it exists, otherwise auto-generated overview with doc titles and descriptions organized by folder
- Responsive layout using Bootstrap grid (sidebar collapses on mobile)

### Step 4: Test
- Verify all 23 docs render correctly
- Verify subfolder docs appear grouped in sidebar
- Verify landing page generates correctly (with and without `index.md`)
- Verify path traversal is blocked (test `../../`, `..`, extra slashes, etc.)
- Verify the spec viewer still works after the shared renderer extraction
- Test responsive layout

## Out of Scope

- Editing documentation from the admin UI
- Search across documentation files (could be a future enhancement)
- Rendering specs or CLAUDE.md (only `docs/` directory)
- Improving the markdown parser itself (current capabilities are sufficient)
