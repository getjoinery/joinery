# Page Permanent Delete & Component Ownership

**Status:** Active — decision made (Option C), ready to implement.

---

## Current Bugs

### 1. 404 on Permanent Delete
`admin_page.php:100` links to `/admin/admin_page_permanent_delete?pag_page_id=N` but `adm/admin_page_permanent_delete.php` has never been created. Every click on "Permanent Delete" produces a 404.

### 2. Component Orphan Risk (discovered during investigation)
When the missing handler is eventually built, it would silently orphan `pac_page_contents` rows. The `Page` model's `$foreign_key_actions` only handles `pag_fil_file_id` (set to NULL). There is no deletion rule for PageContent records.

---

## Root Cause: Component Ownership Is Undefined

`PageContent` records have no parent-page foreign key. Membership is stored exclusively in `pag_component_layout` (a JSON array of `pac_page_content_id` values on each page). This means:

- A single `PageContent` row can appear in the layout of multiple pages simultaneously.
- The system currently allows this — `get_test_contexts()` in `page_contents_class.php` already queries for all pages containing a given component.
- There is no enforcement that a component belongs to exactly one page.

This creates the cascade ambiguity: if page A is permanently deleted, should its components be deleted too? Not if they're also on page B.

---

## Decision: Option C — Keep sharing, smart cascade on delete

**Components remain shareable. Page deletion only deletes components exclusive to that page.**

- No schema change required.
- On page permanent delete: for each `pac_page_content_id` in the layout, query how many other pages include that same ID. If count == 1 (this page is the sole user), permanently delete the component. If count > 1, leave it.
- The confirmation screen surfaces this distinction explicitly using `get_test_contexts()`, so admins see before confirming: which components will be deleted vs. which will be kept because they're shared elsewhere.

**Why not Option A (copy-not-share)?** Option A eliminates the core value of the component system — "edit once, appears everywhere." Forcing copies converts a shared-reference model into a duplication model, which defeats the feature.

**Why Option C isn't as odd as it sounds:** This is reference counting on delete — the same pattern as filesystem hard links. A component's row is removed when the last page referencing it is removed. That's correct and well-understood behavior, not a special case.

**Remaining trade-off:** There's no persistent "owner" field, so the component list has no column showing primary ownership. This is acceptable; `get_test_contexts()` already answers "what pages use this component" on demand.

---

## Implementation Plan

### Phase 1: Fix the 404
Create `adm/admin_page_permanent_delete.php`. It should:

1. Require permission level 8 or 10 (superadmin).
2. Load the page by `pag_page_id`.
3. Show a confirmation form listing:
   - Page title and URL slug
   - Attached file, if any (will be unlinked per existing `$foreign_key_actions`)
   - Two component lists derived from `get_test_contexts()`:
     - **Will be permanently deleted** — components whose only referencing page is this one
     - **Will be kept** — components also used on other pages (list the other page names)
4. On POST confirm:
   a. For each `pac_page_content_id` in `pag_component_layout`: call `get_test_contexts()`. If the only result is this page, load the `PageContent` and call `permanent_delete()` on it.
   b. Call `$page->permanent_delete()` (handles `pag_fil_file_id` nulling via existing FK action).
5. Redirect to `/admin/admin_pages` with a success flash message.

### Phase 2: No schema changes needed
Option C requires no new columns, no migration, and no UI changes to the page editor. The entire feature lives in the delete handler.

---

## Files Affected

| File | Change |
|------|--------|
| `adm/admin_page_permanent_delete.php` | Create (new file) — includes smart cascade logic |
