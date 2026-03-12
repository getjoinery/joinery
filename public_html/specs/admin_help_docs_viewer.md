# Admin Help Page: Documentation Viewer

## Summary

Replace the stale hardcoded HTML help page (`adm/admin_help.php`) with a documentation viewer that renders the markdown files from the `docs/` directory.

## Current State

The current help page (`/admin/admin_help`) contains a hardcoded Vimeo video walkthrough and manually written HTML describing the system architecture, events, and products. This content is outdated and unmaintained. Meanwhile, the `docs/` directory contains 21 up-to-date markdown files covering every major subsystem.

## Goal

When an admin visits `/admin/admin_help`, they should see:
- A sidebar or nav listing all available documentation files from `docs/`
- The selected markdown file rendered as HTML
- Default to an index/overview page (could render CLAUDE.md's documentation index or a dedicated index)

## Requirements

### 1. Markdown Rendering

- Use a PHP markdown parser (Parsedown is lightweight and has no dependencies, or use league/commonmark if already available via Composer)
- Render GitHub-flavored markdown: headings, code blocks with syntax highlighting, tables, links, lists, bold/italic
- Sanitize output to prevent XSS from any markdown content

### 2. File Discovery

- Scan `docs/` directory for `*.md` files
- Generate human-readable titles from filenames (e.g., `email_system.md` → "Email System")
- Sort alphabetically or group by category

### 3. Navigation

- URL pattern: `/admin/admin_help?doc=filename` (without .md extension)
- Sidebar or tab list showing all available docs
- Highlight the currently selected doc
- Default doc when none specified (suggest an overview or the first alphabetically)

### 4. Page Layout

- Use standard AdminPage layout (header, box, footer)
- Nav/sidebar for doc list on the left, rendered content on the right
- Responsive — content should be readable on smaller screens
- Apply reasonable styling to rendered markdown (code blocks, tables, etc.)

### 5. Remove Stale Content

- Remove the hardcoded HTML help content entirely
- Remove the Vimeo video embed (or make it a separate "Getting Started" doc if still relevant)
- Keep `admin_help_logic.php` minimal — just session check and page vars

### 6. Security

- Only serve files from the `docs/` directory — do not allow path traversal (e.g., `?doc=../../config/Globalvars_site`)
- Validate that the requested filename matches `^[a-zA-Z0-9_-]+$` before appending `.md`
- Files must exist and be readable

## Implementation Notes

- Check if Parsedown or league/commonmark is already available via the Composer autoload path
- If not, Parsedown is a single PHP file that can be dropped into `includes/` — no Composer required
- The `docs/` directory currently has 21 files; the page should handle growth gracefully
- Consider caching rendered HTML if performance becomes a concern (unlikely with 21 small files)

## Out of Scope

- Editing documentation from the admin UI
- Search across documentation files (could be a future enhancement)
- Rendering specs or CLAUDE.md (only `docs/` directory)
