# Falcon HTML5 Conversion Spec

## Goal

Create a vanilla HTML5+CSS static conversion of the Falcon admin theme at `/home/user1/theme-sources/falcon-html5/`, with one representative HTML file per admin page pattern. This conversion eliminates all Bootstrap, FontAwesome, jQuery, Simplebar, and Popper dependencies, replacing them with a single `style.css` (~29KB) and `script.js` (~4KB).

**Result: ~98.5% reduction in CSS+JS payload** (from ~2.2MB to ~33KB).

## Background

The canvas-html5 conversion proved this approach works for the public-facing Canvas theme (93% CSS reduction, 99.9% JS reduction). This spec applies the same methodology to the Falcon admin theme. The admin interface is structurally simpler than a public-facing theme (fixed layout patterns, no hero sections or sliders) but has more interactive components (sidebar nav, dropdowns, sortable tables).

## Existing Files

The conversion has been started at `/home/user1/theme-sources/falcon-html5/`:

| File | Size | Status |
|------|------|--------|
| `style.css` | 29KB | Complete - comprehensive vanilla CSS |
| `script.js` | 4KB | Complete - sidebar, dropdowns, collapsible nav, alerts |
| `admin_users.html` | 19KB | Complete - list page demo |

## Architecture

### Asset Strategy

All pages share the same three assets:
```html
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script src="script.js"></script>  <!-- at end of body -->
```

No other CSS or JS files. Zero vendor dependencies.

### Falcon Original Assets (what we eliminate)

| Asset | Size | Replacement |
|-------|------|-------------|
| `theme.css` (Bootstrap+Falcon) | 884KB | `style.css` (29KB) |
| `fontawesome/all.min.js` | 1,200KB | Inline SVG icons |
| `bootstrap.min.js` | 60KB | `script.js` (4KB) |
| `popper.min.js` | 20KB | Eliminated |
| `simplebar.min.js` + CSS | 32KB | Native scrollbars + CSS `::-webkit-scrollbar` |
| `theme-admin.js` | 8KB | Merged into `script.js` |
| **Total** | **~2,200KB** | **~33KB** |

### Design Tokens (CSS Custom Properties)

The Falcon visual identity is captured in CSS custom properties in `style.css`:

```css
--primary: #2A7BE4;         /* Falcon blue */
--primary-dark: #1a5bbf;
--body-color: #344050;       /* Main text */
--lighter: #edf2f9;          /* Page background */
--border: #d8e2ef;           /* Borders */
--font-sans: 'Open Sans';    /* Body font */
--font-heading: 'Poppins';   /* Heading font */
--sidebar-width: 250px;
--topbar-height: 69px;
```

## Admin Page Patterns

There are 6 distinct UI patterns across ~143 admin pages. Only 4 need HTML templates (action pages have no UI, utility pages are rare one-offs).

### Pattern 1: List Page (56 pages)

**Examples:** `admin_users.php`, `admin_events.php`, `admin_orders.php`, `admin_products.php`

**Structure:**
```
admin-layout
  sidebar (vertical nav)
  main-content
    topbar (hamburger, breadcrumbs, right nav icons)
    page-body
      page-header (h2 title + breadcrumb + action buttons)
      card
        card-header (title + toolbar: sort/filter/search)
        table-responsive > table (thead + tbody rows)
        pagination-wrapper (record count + page links)
    page-footer
```

**Bootstrap classes eliminated and their replacements:**

| Bootstrap | Falcon HTML5 | Notes |
|-----------|-------------|-------|
| `navbar navbar-vertical` | `aside.sidebar` | Fixed left sidebar |
| `navbar-glass navbar-top` | `header.topbar` | Sticky top bar with backdrop-filter |
| `card` / `card-header` / `card-body` | `.card` / `.card-header` / `.card-body` | Same class names, custom CSS |
| `table table-sm fs-10` | `.table` | Simplified, no size variants needed |
| `btn btn-falcon-default btn-sm` | `.btn .btn-falcon-default .btn-sm` | Same names, custom CSS |
| `badge rounded-pill badge-subtle-success` | `.badge .badge-subtle-success` | Simplified |
| `fas fa-plus` / `fas fa-filter` | Inline `<svg>` | See Icon Reference below |
| `d-flex align-items-center` | `.d-flex .align-items-center` | Same utility names |
| `row col-md-6` | `.row .col-md-6` | Simplified grid |
| `form-control form-select` | `.form-control` + select styling | Native select with CSS arrow |
| `data-bs-toggle="collapse"` | `.has-children` + JS click handler | Sidebar collapsibles |
| `data-bs-toggle="dropdown"` | `data-toggle="dropdown"` + JS | Top nav dropdowns |
| `dropdown-menu dropdown-caret` | `.dropdown-menu` | CSS-only positioning |

**File:** `admin_users.html` (already created)

### Pattern 2: Edit/Form Page (40 pages)

**Examples:** `admin_event_edit.php`, `admin_user_add.php`, `admin_product_edit.php`

**Structure:**
```
admin-layout
  sidebar
  main-content
    topbar
    page-body
      page-header (h2 title + breadcrumb)
      card
        card-header (form title)
        card-body
          form
            form-group (label + form-control) ...
            form-group (label + select) ...
            form-group (label + textarea) ...
            form-check (checkbox/radio) ...
            submit button
    page-footer
```

**Key form elements and their HTML5 equivalents:**

| FormWriter Output (Bootstrap) | Falcon HTML5 |
|-------------------------------|-------------|
| `<div class="mb-3">` | `<div class="form-group">` |
| `<label class="form-label">` | `<label class="form-label">` |
| `<input class="form-control">` | `<input class="form-control">` |
| `<select class="form-select">` | `<select class="form-control">` |
| `<textarea class="form-control">` | `<textarea class="form-control">` |
| `<div class="form-check">` | `<div class="form-check">` |
| `<button class="btn btn-primary">` | `<button class="btn btn-primary">` |
| `<div class="row"><div class="col-md-8">` | `<div class="row"><div class="col-8">` |

**File to create:** `admin_edit.html`

### Pattern 3: Detail/View Page (16 pages)

**Examples:** `admin_order.php`, `admin_product.php`, `admin_post.php`

**Structure:**
```
admin-layout
  sidebar
  main-content
    topbar
    page-body
      page-header (title + breadcrumb + action dropdown)
      row (2-column layout)
        col-6: card (detail table - key/value pairs)
        col-6: card (related info)
      card (related items table)
    page-footer
```

**Key patterns:**

| Bootstrap | Falcon HTML5 |
|-----------|-------------|
| `<table class="table table-borderless fs-9">` | `<table class="table detail-table">` |
| `<span class="badge badge-subtle-success">` | `<span class="badge badge-subtle-success">` |
| `<div class="row g-3"><div class="col-xxl-6">` | `<div class="row g-3"><div class="col-6">` |
| `<span class="fas fa-shopping-cart me-2">` | Inline `<svg>` |
| `bg-body-tertiary` on card-header | `.card-header.bg-light` |
| `no_page_card` option (BeginPageNoCard) | No outer card wrapper, just page-header |

**File to create:** `admin_detail.html`

### Pattern 4: Dashboard/Settings Page (9 pages)

**Examples:** `admin_settings.php`, `admin_analytics_stats.php`, `admin_utilities.php`

**Structure:**
```
admin-layout
  sidebar
  main-content
    topbar
    page-body
      page-header
      nav-tabs (tab navigation)
      stats-grid (stat cards)
      card (settings form or custom content)
    page-footer
```

**Key patterns:**

| Bootstrap | Falcon HTML5 |
|-----------|-------------|
| `<ul class="nav nav-tabs">` | `<ul class="nav-tabs">` |
| `<li class="nav-item"><a class="nav-link active">` | Same structure, custom CSS |
| Stats with `<div class="d-flex">` in cards | `.stat-card` with `.stat-value`, `.stat-label` |

**File to create:** `admin_dashboard.html`

## Icon Reference

All FontAwesome icons are replaced with inline SVGs. Common admin icons:

```html
<!-- Users -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>

<!-- Calendar/Events -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>

<!-- Settings/Gear -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>

<!-- Wrench/Tools -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>

<!-- Plus (add) -->
<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>

<!-- Search -->
<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>

<!-- Cart -->
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>

<!-- Bell (notifications) -->
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>

<!-- Chart/pie (dashboard) -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>

<!-- Home -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V21H15v-7H9v7H3z"/></svg>

<!-- Edit/Pencil -->
<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>

<!-- Trash -->
<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>

<!-- Chevron left (pagination) -->
<svg width="12" height="12" fill="currentColor" viewBox="0 0 320 512"><path d="M34.52 239.03L228.87 44.69c9.37-9.37 24.57-9.37 33.94 0l22.67 22.67c9.36 9.36 9.37 24.52.04 33.9L131.49 256l154.02 154.75c9.34 9.38 9.32 24.54-.04 33.9l-22.67 22.67c-9.37 9.37-24.57 9.37-33.94 0L34.52 272.97c-9.37-9.37-9.37-24.57 0-33.94z"/></svg>

<!-- Chevron right (pagination) -->
<svg width="12" height="12" fill="currentColor" viewBox="0 0 320 512"><path d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"/></svg>

<!-- Nine-dots (admin grid menu) -->
<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="2" cy="2" r="2" fill="#6C6E71"/><circle cx="2" cy="8" r="2" fill="#6C6E71"/><circle cx="2" cy="14" r="2" fill="#6C6E71"/><circle cx="8" cy="8" r="2" fill="#6C6E71"/><circle cx="8" cy="14" r="2" fill="#6C6E71"/><circle cx="14" cy="8" r="2" fill="#6C6E71"/><circle cx="14" cy="14" r="2" fill="#6C6E71"/><circle cx="8" cy="2" r="2" fill="#6C6E71"/><circle cx="14" cy="2" r="2" fill="#6C6E71"/></svg>

<!-- Ellipsis (row actions) -->
<svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>

<!-- Email/Envelope -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 4L12 13 2 4"/></svg>

<!-- File/Document -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>

<!-- Tag/Product -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>

<!-- Dollar/Payment -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>

<!-- Help/Question -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>

<!-- Bar chart (analytics) -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>

<!-- List/Menu -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>

<!-- Globe/World -->
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
```

## Shared HTML Structure (All Pages)

Every admin page follows this exact structure. The sidebar, topbar, and footer are identical across all pages — only the `<main class="page-body">` content varies.

```html
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{Page Title} -- Admin</title>
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="admin-layout">
    <div class="sidebar-overlay"></div>
    <aside class="sidebar">
        <!-- See admin_users.html for complete sidebar markup -->
        <!-- Sidebar is IDENTICAL across all pages -->
    </aside>
    <div class="main-content">
        <header class="topbar">
            <!-- See admin_users.html for complete topbar markup -->
            <!-- Topbar is IDENTICAL across all pages (except breadcrumb text) -->
        </header>
        <main class="page-body">

            <!-- === PAGE-SPECIFIC CONTENT GOES HERE === -->

        </main>
        <footer class="page-footer"><span>v0.5.0</span></footer>
    </div>
</div>
<script src="script.js"></script>
</body>
</html>
```

## Instructions for Converting a Single Page

An agent creating one HTML page should follow these steps:

### Step 1: Copy the shell

Start from `admin_users.html`. Copy the entire file. Keep the sidebar, topbar, and footer unchanged. Only modify:
- The `<title>` tag
- The breadcrumb text in the topbar
- The active nav item in the sidebar (move `class="active"` to the correct link)
- Everything inside `<main class="page-body">...</main>`

### Step 2: Determine the page pattern

Look at the PHP source in `/var/www/html/joinerytest/public_html/adm/` to determine which pattern:

- **List page**: Uses `tableheader()`, `disprow()`, `endtable()`, `Pager`
- **Edit page**: Uses `getFormWriter()`, `begin_form()`, `textinput()`, `submitbutton()`
- **Detail page**: Uses `no_page_card => true`, has `<div class="card">` with detail tables, often two-column `row g-3`
- **Dashboard**: Uses tabs (`nav-tabs`), stats cards, or custom HTML

### Step 3: Build the page body

**For a LIST page:** Follow `admin_users.html` exactly. Change table headers, sample row data, and toolbar options.

**For an EDIT page:**
```html
<div class="page-header">
    <div>
        <h2>Edit Event</h2>
        <ol class="breadcrumb">...</ol>
    </div>
</div>
<div class="card">
    <div class="card-header bg-light"><h5 class="mb-0">Edit Event</h5></div>
    <div class="card-body">
        <form method="post" action="/admin/admin_event_edit">
            <div class="row">
                <div class="col-8">
                    <div class="form-group">
                        <label class="form-label" for="evt_name">Event name</label>
                        <input type="text" class="form-control" id="evt_name" name="evt_name" value="Sample Event">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="evt_description">Description</label>
                        <textarea class="form-control" id="evt_description" name="evt_description" rows="4">...</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="evt_location">Location</label>
                        <select class="form-control" id="evt_location" name="evt_loc_location_id">
                            <option value="">-- Select --</option>
                            <option value="1" selected>Main Hall</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="evt_active" name="evt_active" value="1" checked>
                            <label class="form-check-label" for="evt_active">Active</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>
```

**For a DETAIL page:**
```html
<div class="page-header">
    <div>
        <h2>Order #1234</h2>
        <ol class="breadcrumb">...</ol>
    </div>
    <div class="dropdown">
        <button class="btn btn-falcon-default btn-sm" data-toggle="dropdown">Actions</button>
        <div class="dropdown-menu">
            <a class="dropdown-item" href="#">Edit</a>
            <a class="dropdown-item" href="#">Refund</a>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-6">
        <div class="card">
            <div class="card-header bg-light"><h6 class="mb-0">Order Information</h6></div>
            <div class="card-body">
                <table class="table detail-table">
                    <tr><td>Order ID:</td><td><strong>1234</strong></td></tr>
                    <tr><td>Total:</td><td><strong>$45.00</strong></td></tr>
                    <tr><td>Status:</td><td><span class="badge badge-subtle-success">Complete</span></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card">
            <div class="card-header bg-light"><h6 class="mb-0">Customer</h6></div>
            <div class="card-body">
                <table class="table detail-table">
                    <tr><td>Name:</td><td><a href="#">John Doe</a></td></tr>
                    <tr><td>Email:</td><td>john@example.com</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
```

**For a DASHBOARD/SETTINGS page:**
```html
<div class="page-header">
    <div><h2>Settings</h2></div>
</div>
<ul class="nav-tabs">
    <li><a class="nav-link active" href="/admin/admin_settings">General</a></li>
    <li><a class="nav-link" href="/admin/admin_settings_email">Email</a></li>
    <li><a class="nav-link" href="/admin/admin_settings_payments">Payments</a></li>
</ul>
<div class="card">
    <div class="card-body">
        <!-- Settings form or stat cards -->
    </div>
</div>
```

### Step 4: Use sample data

Populate tables and forms with realistic sample data (3-5 rows for tables, plausible values for forms). This is a static HTML demo, not a working application.

### Step 5: Validate

Open the file in a browser at `https://joinerytest.site/theme-sources/falcon-html5/{filename}.html` and verify:
- Sidebar renders with correct active item
- Topbar has correct breadcrumbs
- Cards, tables, forms look correct
- Mobile responsive (sidebar collapses at <1200px)
- Dropdowns work (click user avatar, nine-dots)

## Pages to Create

Create one representative HTML file per admin page pattern:

| File | Pattern | Modeled After | Priority |
|------|---------|---------------|----------|
| `admin_users.html` | List | admin_users.php | Done |
| `admin_edit.html` | Edit/Form | admin_event_edit.php | Next |
| `admin_detail.html` | Detail/View | admin_order.php | Next |
| `admin_dashboard.html` | Dashboard | admin_settings.php | Next |

These 4 templates cover all ~143 admin pages. Action pages (15) have no UI. Utility pages (7) are one-offs that follow the card pattern.

## CSS Details: What's in style.css

The CSS is organized in these sections (in order):

1. **CSS Custom Properties** (~85 lines) - All design tokens
2. **Reset** (~20 lines) - Minimal box-sizing reset
3. **Admin Layout** - Sidebar + main content flexbox
4. **Sidebar** - Fixed left nav, brand, nav links, collapsible sub-navs, labels
5. **Main Content + Topbar** - Sticky top bar with glassmorphism
6. **Dropdowns** - Click-toggled menus for topbar
7. **Page Content** - Page body padding
8. **Cards** - Header, body, footer
9. **Buttons** - Primary, success, danger, warning, secondary, falcon-default, outline variants
10. **Forms** - Controls, labels, groups, checkboxes, selects, input groups
11. **Tables** - Headers, rows, striped, responsive wrapper
12. **Badges** - Solid and subtle variants
13. **Alerts** - Success, danger, warning, info with icon + close
14. **Pagination** - Page links, active state, disabled
15. **Tabs** - nav-tabs with bottom border active indicator
16. **Auth Layout** - Centered card for login/register (if needed)
17. **Breadcrumbs** - Inline list with separators
18. **Stats Cards** - Value + label + icon layout
19. **Utilities** - Flex, spacing, text, background helpers
20. **Grid** - Simple row/col system
21. **Footer** - Bottom bar
22. **Responsive** - Sidebar collapse at 1200px, mobile adjustments

## JS Details: What's in script.js

Six features, all using vanilla `addEventListener`:

1. **Sidebar toggle** - Toggles `.sidebar-collapsed` (desktop) or `.sidebar-open` (mobile)
2. **Sidebar overlay** - Closes sidebar on overlay click (mobile)
3. **Collapsible nav** - Toggles `.open` on `.sidebar-subnav` elements
4. **Dropdown menus** - Toggles `.open` on `.dropdown-menu` elements, closes on outside click
5. **Alert dismiss** - Hides `.alert` on close button click
6. **Auto-open active section** - Opens sidebar subnav containing the active link on page load

## Key Lessons from the Conversion

1. **Bootstrap's grid is overkill for admin layouts.** The sidebar + main content is a simple flexbox. The responsive grid (`row`/`col-*`) covers every layout with ~20 lines of CSS.

2. **FontAwesome is the single biggest payload.** At 1.2MB, it's larger than everything else combined. Inline SVGs from Feather Icons (or similar) are ~200 bytes each and look identical.

3. **Bootstrap JS is only needed for 3 things:** collapse (sidebar nav), dropdown (topbar menus), and tooltip (optional). All three are trivially implemented in ~80 lines of vanilla JS.

4. **Simplebar is cosmetic.** Native scrollbars with `::-webkit-scrollbar` CSS styling look fine and save 32KB.

5. **The Falcon `theme.css` is 884KB because it includes ALL of Bootstrap.** The actual Falcon-specific styles are maybe 50KB. A custom CSS needs only the components actually used in the admin interface.

6. **CSS custom properties eliminate the need for SCSS.** The Falcon color scheme, spacing, and typography are fully captured in ~30 CSS variables.

7. **The `backdrop-filter: blur(8px)` on the topbar replicates the Falcon "glass" navbar effect** without any JavaScript or special CSS classes.
