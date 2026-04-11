# Server Manager UI Consolidation

**Created:** 2026-04-11
**Status:** Active

## Problem

The server manager admin UI has 7 sidebar items and 8 view files, but the UX is fragmented:

- **Dashboard and Nodes are redundant** -- both show the same nodes with the same information, one as cards and one as a table
- **Backups, Database, and Updates each require picking a node from a dropdown** -- you navigate to a page, then select which node to operate on, which is backwards
- **Jobs is a top-level sidebar item** but is only useful as a drill-down from other pages

The mental model should be: see your fleet, pick a node, do things to it. Instead, the current UI is organized by operation type, requiring the user to context-switch between pages and re-select the node each time.

## Solution

Consolidate from 7 sidebar pages to a node-centric flow with 3 effective pages:

1. **Dashboard** -- fleet overview (existing, enhanced)
2. **Node Detail** -- everything you do to a single node, in one tabbed page (new)
3. **Jobs** -- global job history (existing, demoted from sidebar)

Plus two supporting pages that stay mostly unchanged:
- **Node Add** -- add new nodes with auto-detect (extracted from nodes_edit)
- **Job Detail** -- individual job output viewer (existing, minor update)

## Page Specifications

### 1. Dashboard (`/admin/server_manager`)

**File:** `plugins/server_manager/views/admin/index.php` (modify)

Keep the existing layout with these changes:

- **Node cards**: The node name in each card header becomes a link to `/admin/server_manager/node_detail?mgn_id=N`. Card footer changes from three buttons (Check Status / Jobs / Edit) to two (Check Status / Manage). "Manage" links to node_detail.
- **Add Node button**: Add an "Add Node" link in the node cards section, styled as a button. Links to `/admin/server_manager/node_add`. Also update the empty-state message link.
- **Publish Upgrade section**: Move the Publish Upgrade form from updates.php to here. It is node-independent (builds archives from the control plane source code), so the dashboard is its natural home. Place it between the node cards and the recent jobs table. Contains a release notes textarea and a "Publish Upgrade" submit button.
- **Recent Jobs table**: No changes. "All Jobs" link continues to point to `/admin/server_manager/jobs`.

### 2. Node Detail (`/admin/server_manager/node_detail`)

**File:** `plugins/server_manager/views/admin/node_detail.php` (new)

**URL pattern:** `/admin/server_manager/node_detail?mgn_id=N&tab=overview`

Requires `mgn_id` parameter. If missing or invalid, redirect to dashboard.

Uses **URL-based tab navigation** -- Bootstrap `nav-tabs` where each tab is a link with a different `?tab=` value. All tabs render in the same PHP file using a simple conditional. This avoids form state loss, works with browser back/forward, and allows bookmarking specific tabs.

**Breadcrumbs:** `Server Manager > {Node Name}`

**Tab bar** (rendered on all tabs):
- Overview (default)
- Backups
- Database
- Updates
- Jobs

**All POST action handlers** live at the top of the file before any output. Each action creates a job via `ManagementJob::createJob()` and redirects to `/admin/server_manager/job_detail?job_id=N`. The node's `mgn_id` is always known from the URL -- no dropdown needed (except for copy_database which requires a second node).

#### Overview Tab (`?tab=overview`)

**Status summary card** at the top showing:
- Health status dot (same logic as dashboard cards)
- Last check time
- Key metrics if available: disk %, memory, load, postgres status, version

**Connection settings form** below (extracted from nodes_edit.php edit mode):
- All FormWriter fields: name, slug, host, SSH user, SSH key path, SSH port, container name, container user, web root, site URL, enabled checkbox, notes
- Save Changes button

**Action buttons** below the form:
- Test Connection (POST, creates test_connection job)
- Check Status (POST, creates check_status job)
- Delete Node (GET with confirm dialog, soft deletes, redirects to dashboard)

#### Backups Tab (`?tab=backups`)

Extracted from backups.php but scoped to this node (no node dropdown).

**Run Backup section:**
- Two side-by-side forms: "Database Backup" and "Full Project Backup"
- Each has an encryption checkbox and a submit button
- Hidden `node_id` field pre-filled from `mgn_id`

**Fetch Backup File section:**
- Remote path text input + submit button
- Hidden `node_id` field pre-filled

**Recent Backup Jobs table:**
- Filtered to this node and backup job types (backup_database, backup_project, fetch_backup)
- Columns: ID (links to job_detail), Type, Status badge, Created, Duration
- Limited to 10 most recent

#### Database Tab (`?tab=database`)

Extracted from database.php but scoped to this node.

**Copy Database section:**
- This node is pre-selected as the **target** (most common use case: refreshing from production)
- Source node dropdown lists all other enabled nodes (excluding the current node)
- Direction label: "Copy from {source dropdown} to {this node name}"
- Confirmation checkbox: "I confirm I want to overwrite this node's database"
- Submit button (red/danger, with onclick confirm)

**Restore Database from Backup section:**
- No node dropdown needed -- restoring to this node
- Backup path text input
- Confirmation checkbox
- Submit button (red/danger, with onclick confirm)

**Recent Database Operations table:**
- Filtered to this node and database job types (copy_database, restore_database)
- Same column format as backup jobs table

#### Updates Tab (`?tab=updates`)

Extracted from updates.php but scoped to this node (and without the publish form, which moves to dashboard).

**Version comparison card:**
- Current version (from node record `mgn_joinery_version`)
- Control plane version (from local `stg_settings.system_version`)
- Status badge: "Up to date" (green) or "Update available" (yellow)

**Action buttons:**
- Apply Update (with onclick confirm)
- Dry Run
- Refresh & Apply (with onclick confirm)
- Each POSTs with this node's `mgn_id`

#### Jobs Tab (`?tab=jobs`)

Job history filtered to this node (extracted from jobs.php).

**Filter bar:**
- Status dropdown (pending, running, completed, failed, cancelled)
- Type dropdown (all job types)
- Filter / Clear buttons
- Node filter is implicit (always this node) -- not shown as a dropdown

**Paginated jobs table:**
- Columns: ID, Type, Status, Progress, Created, Duration
- Node column omitted (redundant -- we're already on the node page)
- Default sort: newest first

**Link:** "View All Jobs" at top, linking to `/admin/server_manager/jobs`

### 3. Node Add (`/admin/server_manager/node_add`)

**File:** `plugins/server_manager/views/admin/node_add.php` (new, extracted from nodes_edit.php)

**Breadcrumbs:** `Server Manager > Add Node`

Contains only the add-node flow:
- Auto-detect panel (scan a host via SSH for Joinery instances, one-click add)
- Manual add form (same FormWriter fields as the Overview tab, but for a new node)
- After successful save, redirect to `/admin/server_manager/node_detail?mgn_id=N`

### 4. Jobs (`/admin/server_manager/jobs`)

**File:** `plugins/server_manager/views/admin/jobs.php` (minor modifications)

Stays as the global job history page. Changes:
- Remains in the sidebar (as the second item under Server Manager)
- Breadcrumb update if needed

### 5. Job Detail (`/admin/server_manager/job_detail`)

**File:** `plugins/server_manager/views/admin/job_detail.php` (minor modification)

Update breadcrumbs to include the node name when the job has a node:
- Current: `Server Manager > Jobs > Job #N`
- New: `Server Manager > {Node Name} > Job #N` (where Node Name links to node_detail)

## Redirect Stubs

Old URLs must continue to work (bookmarks, browser history). Each deprecated page file becomes a short redirect:

| Old URL | Redirect To |
|---------|------------|
| `/admin/server_manager/nodes` | `/admin/server_manager` (dashboard) |
| `/admin/server_manager/nodes_edit?mgn_id=N` | `/admin/server_manager/node_detail?mgn_id=N` |
| `/admin/server_manager/nodes_edit` (no mgn_id) | `/admin/server_manager/node_add` |
| `/admin/server_manager/backups` | `/admin/server_manager` (dashboard) |
| `/admin/server_manager/database` | `/admin/server_manager` (dashboard) |
| `/admin/server_manager/updates` | `/admin/server_manager` (dashboard) |

## Migration

Add `sm_003_consolidate_admin_menus` to `plugins/server_manager/migrations/migrations.php`:

**Delete** these sidebar menu entries (now tabs on node_detail or eliminated):
- `server-manager-nodes`
- `server-manager-backups`
- `server-manager-database`
- `server-manager-updates`

**Keep** these sidebar entries:
- `server-manager` (parent)
- `server-manager-dashboard`
- `server-manager-jobs`

**Result:** Sidebar shows "Server Manager" with two children: "Dashboard" and "Jobs".

## Files Summary

| File | Action | Description |
|------|--------|-------------|
| `views/admin/node_detail.php` | CREATE | Core new page -- 5-tab node management |
| `views/admin/node_add.php` | CREATE | Add node form + auto-detect panel |
| `views/admin/index.php` | MODIFY | Update card links, add Publish Upgrade, add Add Node button |
| `views/admin/job_detail.php` | MODIFY | Breadcrumb update to include node name |
| `views/admin/jobs.php` | MODIFY | Minor breadcrumb cleanup |
| `views/admin/nodes_edit.php` | MODIFY | Replace with redirect stub |
| `views/admin/nodes.php` | MODIFY | Replace with redirect stub |
| `views/admin/backups.php` | MODIFY | Replace with redirect stub |
| `views/admin/database.php` | MODIFY | Replace with redirect stub |
| `views/admin/updates.php` | MODIFY | Replace with redirect stub |
| `migrations/migrations.php` | MODIFY | Add sm_003 migration |

## Developer Documentation

After implementation, update `/docs/server_manager.md` with the new page structure, URL patterns, and tab navigation details.
