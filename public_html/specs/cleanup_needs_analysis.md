# Codebase Cleanup: Needs Discussion or Analysis

## Overview

These items were found during the codebase audit but require investigation, design decisions, or discussion before they can be fixed. Each section describes what is known and what questions remain.

---

## Production Bugs Needing Investigation

### `PublicPage::get_logo()` Undefined Method

**Problem:** `views/login.php` line 32 (and `register.php`) call `$page->get_logo()`, but `PublicPage` does not implement this method. This **breaks the login and register pages** on 5 production sites: empoweredhealthtn, phillyzouk, jeremytunnell, galactictribune, scrolldaddy.

**What needs analysis:**
- Where did `get_logo()` come from? Was it removed during a refactor, or was it never implemented?
- What should it return? A URL to the site logo image? An HTML `<img>` tag?
- Should it be added to `PublicPageBase` (available to all themes), or is it theme-specific?
- Does the joinerytest default theme have a working `get_logo()` that these other themes are missing?

**Files to investigate:**
- `views/login.php`, `views/register.php`
- `includes/PublicPageBase.php`
- All theme-specific `PublicPage.php` files — check if any theme already implements this
- Git history: `git log -p --all -S 'get_logo' -- '*.php'` to find when/where it was added or removed

---

### scrolldaddy `FormWriter::begin_form()` Incompatible Signature

**Problem:** The controld plugin's `FormWriter.php` overrides `begin_form()` with a signature incompatible with `FormWriterV2Base::begin_form()`. Fatal error on every form page on scrolldaddy (374 occurrences).

**What needs analysis:**
- What is the controld plugin's `begin_form()` signature vs the parent's?
- Does the controld plugin add parameters that it needs, or is it an outdated copy that fell behind after a refactor?
- Can the controld override be updated to match the parent, or does it need the different signature for functionality?
- Is the controld plugin FormWriter still necessary at all, or can it be removed?

**Files to investigate:**
- `plugins/controld/includes/FormWriter.php`
- `includes/FormWriterV2Base.php` — current `begin_form()` signature

---

### `FormWriterV2Bootstrap::hidden()` Undefined Method

**Problem:** `FormWriterV2Base::antispam_question_input()` calls `$this->hidden()` but `FormWriterV2Bootstrap` does not implement it. Breaks blog comment forms on 4 sites (1,800+ errors combined).

**What needs analysis:**
- Does the old `FormWriterHTML5` or `FormWriterBootstrap` (V1) have a `hidden()` method that was never ported to V2?
- What should `hidden()` do — just render `<input type="hidden" name="..." value="...">`?
- Should it go in `FormWriterV2Base` (available to all V2 FormWriters) or only in `FormWriterV2Bootstrap`?
- Are there other places that call `hidden()` besides `antispam_question_input()`?

**Files to investigate:**
- `includes/FormWriterV2Base.php` — `antispam_question_input()` method
- `includes/FormWriterHTML5.php` and `includes/FormWriterBootstrap.php` — check if V1 had `hidden()`
- Grep for `->hidden(` across the codebase

**Documentation:** If `hidden()` is added, update `docs/formwriter.md`.

---

### `FormWriter::set_validate()` Undefined Method

**Problem:** Form code calls `set_validate()` but it does not exist. Affects getjoinery and mapsofwisdom.

**What needs analysis:**
- What is `set_validate()` supposed to do? Enable/disable form validation?
- Was this a V1 FormWriter method that was dropped in V2?
- What are the callers trying to accomplish — can they use a different approach?

**Files to investigate:**
- Grep for `set_validate(` to find all callers
- V1 FormWriter classes to check if the method existed there

---

### galactictribune Missing `points_class.php`

**Problem:** The `/explorer` page includes `data/points_class.php` which doesn't exist. Fatal error (10 occurrences).

**What needs analysis:**
- Is the points system a planned feature for galactictribune that was never built?
- Should the explorer page be removed/disabled until the feature is complete?
- Or does the data class just need to be created — and if so, what's the schema?

**Files to investigate:**
- galactictribune theme views for `/explorer`
- Any specs or docs mentioning a points system

---

### empoweredhealthtn `get_setting()` on Null in `register.php`

**Problem:** `$settings` is null in `views/register.php` line 29, causing `get_setting()` to fail (46 occurrences).

**What needs analysis:**
- Is this the base `views/register.php` or an empoweredhealthtn theme override?
- Why would `Globalvars::get_instance()` return null? Is it a load-order issue specific to this theme?
- Does the same register page work on joinerytest and other sites?

**Files to investigate:**
- `views/register.php` and any theme overrides
- Check if empoweredhealthtn has its own `register.php` in its theme views

---

### joinerydemo Null `vse_visitor_event_id`

**Problem:** `SessionControl::save_visitor_event()` inserts into `vse_visitor_events` with a null primary key (158 occurrences on joinerydemo).

**What needs analysis:**
- How is `vse_visitor_event_id` supposed to be generated — auto-increment, UUID, sequence?
- Is this a schema issue (missing sequence/serial) or a code issue (missing ID assignment)?
- Does this error only occur on joinerydemo, or is it masked on other sites?

**Files to investigate:**
- `includes/SessionControl.php` — `save_visitor_event()` method
- The data class for `vse_visitor_events` — check `$field_specifications` and `$pkey_column`

---

## Security Items Needing Discussion

### Temporarily Disabled IP Change Checking

**Problem:** `SessionControl.php` has two security features marked as temporarily disabled:
- Line 772: `//TODO REMOVED TEMPORARILY`
- Line 784: `//TEMPORARILY DISABLE IP CHANGE CHECKING ON ADMIN`

**What needs analysis:**
- Why was this disabled? Was there a specific incident (false positives, proxy issues)?
- Git history: `git log -p -S 'TEMPORARILY DISABLE IP CHANGE' -- includes/SessionControl.php`
- Is the underlying issue resolved, or does the same problem still exist?
- Should this be re-enabled, re-designed, or permanently removed?

---

## Infrastructure Decisions

### Composer Dependencies Missing on 4 Docker-Prod Sites

**Problem:** `Composer autoload.php not found` on jeremytunnell, mapsofwisdom, galactictribune, joinerydemo.

**What needs discussion:**
- Is the `composerAutoLoad` setting wrong on these sites, or are the packages actually not installed?
- What features are broken because of this (Stripe, calendar links, etc.)?
- Should Composer install be part of the deploy process, or is it a one-time setup issue?
- What is the correct vendor path inside the containers?

---

### Log Rotation on Docker-Prod

**Problem:** Error logs total 3.9 GB across 8 containers on a 79 GB drive (76% full).

**What needs discussion:**
- Should logrotate run inside each container or as a host-level cron?
- What rotation policy? Weekly with 4 rotations? Daily?
- Should we also reduce the verbosity of routing debug output that constitutes most of the log volume?
- Should the existing logs be truncated now, or archived first?

---

## Code Quality Items Needing Review

### `$_SERVER['DOCUMENT_ROOT']` Usage

**Problem:** Two files use `$_SERVER['DOCUMENT_ROOT']` for paths:
- `utils/update_database.php` (lines 4-5) — sets and uses DOCUMENT_ROOT for bootstrap
- `data/plugins_class.php` (lines 139, 153, 652) — uses DOCUMENT_ROOT for plugin directory scanning

**What needs analysis:**
- `update_database.php` runs outside the normal request flow — it may need DOCUMENT_ROOT to bootstrap before PathHelper is available. Is this a valid exception?
- `plugins_class.php` uses it for filesystem scanning. Can `PathHelper::getIncludePath()` replace this cleanly, or does the scanning logic need a different approach?

---

### Empty Catch Blocks in `admin_user.php`

**Problem:** Two empty catch blocks (lines 210, 225) silently swallow exceptions when loading tier names and user names for the change history display.

**What needs analysis:**
- These catch blocks are in the admin user change history UI. The intent may be: "if the referenced tier/user was deleted, just skip displaying the name."
- Is that the right behavior, or should it show "deleted tier" / "deleted user" instead?
- Should these at minimum log the exception?

---

### Error Suppression Operator (`@`) Usage

**Problem:** 20+ uses of `@` across the codebase.

**What needs analysis:**
- `includes/UploadHandler.php` (14+ uses) — this is a third-party/complex upload handler. How many of these are intentional vs sloppy?
- `theme/tailwind/views/register.php` (3 uses) — these are simple `@$form_fields->property` that can be replaced with `??`, but need to verify the variable structure first
- `includes/EmailTemplate.php` — `@$dom->loadHTML()` is standard practice and should stay

---

### Temporarily Disabled Tests and Features

**Problem:** Several items are marked "TEMPORARILY DISABLED":
- `tests/functional/products/ProductTester.php:185` — "hanging issue"
- `tests/integration/calendly_test.php:16` — "Calendly integration under review"
- `utils/calendly_synchronize.php:15` — "Calendly integration under review"

**What needs discussion:**
- Is Calendly integration still a planned feature, or has it been abandoned?
- What was the "hanging issue" in ProductTester? Is the underlying cause fixed?
- Should these be re-enabled, properly fixed, or removed entirely?

---

## Implementation Notes

- Each item here needs investigation or a decision before coding begins
- Some may turn out to be quick fixes once analyzed; others may need their own specs
- The docker-prod production bugs (get_logo, begin_form, hidden) are the highest priority since they affect live sites
