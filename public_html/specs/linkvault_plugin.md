# LinkVault Plugin Specification

> **Note on provenance:** This spec was written as a documentation exercise — it simulates a
> new third-party developer designing a plugin using ONLY the distributable agent file
> (`default_agents_template.md`) and the `/docs/` guides, without reading any platform source
> code. Every design decision below cites the doc that motivated it. Where the docs were
> ambiguous or contradictory, the choice made is recorded in **Appendix A: Assumptions**, which
> feeds a companion documentation-audit spec.

## Overview

LinkVault lets members save bookmarks (URL + title + notes), organize them into categories,
and view them on a personal page in the member area. Admins can browse all bookmarks, moderate
(soft-delete) entries, and configure limits. A weekly scheduled task emails each member a digest
of bookmarks they added that week. A mobile app can add bookmarks through the plugin action API.

**Plugin directory:** `plugins/linkvault/`

**Naming rationale:** Per the Plugin Developer Guide, the directory name appears in user-facing
URLs and must be distinctive, lowercase, and must not match reserved segments. A two-word name
like `link_vault` was rejected: `profileMenu` slugs must match `[a-z0-9-]` *and* must start with
`<plugin-name>-`, which is unsatisfiable when the plugin name contains an underscore. A single
word avoids the conflict entirely.

## What this plugin registers (per the "Where does each piece go?" table)

| Piece | Mechanism |
|---|---|
| Tables `lvb_linkvault_bookmarks`, `lvc_linkvault_categories` | `$field_specifications` in data classes — created automatically on install/sync |
| Admin menu group + children | `adminMenu` in `plugin.json` |
| Profile dropdown entry | `profileMenu` in `plugin.json` |
| Default settings | `settings` array in `plugin.json` |
| Seed categories | `migrations/001_seed_categories.sql` (idempotent) |
| Weekly digest | `tasks/LinkvaultWeeklyDigest.php` + `.json` |
| Activate/deactivate hooks | Not needed — no external state |
| `uninstall.php` | Not needed — the system drops tables, settings, menus, and task records automatically |
| Provisioners | Not needed — no external runtime dependency |

## Directory Structure

```
plugins/linkvault/
├── plugin.json
├── serve.php                          # one gated route (see Routing)
├── settings_form.php                  # admin settings page integration
├── data/
│   ├── linkvault_bookmarks_class.php  # LinkvaultBookmark / MultiLinkvaultBookmark
│   └── linkvault_categories_class.php # LinkvaultCategory / MultiLinkvaultCategory
├── logic/
│   ├── bookmark_edit_logic.php        # shared by web view + API action + form builder
│   └── bookmarks_logic.php            # member list page logic
├── views/
│   ├── index.php                      # /linkvault — public landing/explainer page
│   └── profile/
│       ├── index.php                  # /profile/linkvault — my bookmarks
│       └── bookmark_edit.php          # /profile/linkvault/bookmark_edit
├── admin/
│   ├── admin_linkvault.php            # /plugins/linkvault/admin/admin_linkvault
│   ├── admin_linkvault_categories.php
│   └── logic/
│       ├── admin_linkvault_logic.php
│       └── admin_linkvault_categories_logic.php
├── tasks/
│   ├── LinkvaultWeeklyDigest.php
│   └── LinkvaultWeeklyDigest.json
├── migrations/
│   └── 001_seed_categories.sql
└── docs/
    └── overview.md                    # developer docs for the plugin
```

## Data Model

Both classes follow the Table Creation (Automatic) section of the Plugin Developer Guide:
single-row classes extend `SystemBase`, collections extend `SystemMultiBase`, and tables are
created from `$field_specifications` on install — no CREATE TABLE statements anywhere.

### LinkvaultBookmark — `plugins/linkvault/data/linkvault_bookmarks_class.php`

```php
class LinkvaultBookmark extends SystemBase {
    public static $prefix = 'lvb';
    public static $tablename = 'lvb_linkvault_bookmarks';
    public static $pkey_column = 'lvb_linkvault_bookmark_id';

    public static $field_specifications = array(
        'lvb_linkvault_bookmark_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'lvb_usr_user_id'           => array('type'=>'int8', 'is_nullable'=>false),
        'lvb_lvc_linkvault_category_id' => array('type'=>'int8'),
        'lvb_url'   => array('type'=>'varchar(2048)', 'required'=>true,
                             'validation'=>array('url'=>true, 'maxlength'=>2048)),
        'lvb_title' => array('type'=>'varchar(255)', 'required'=>true,
                             'validation'=>array('minlength'=>2, 'maxlength'=>255)),
        'lvb_notes' => array('type'=>'text'),
        'lvb_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'lvb_delete_time' => array('type'=>'timestamp(6)'),
    );

    // Deletion strategy (child-centric, per docs/deletion_system.md):
    protected static $foreign_key_actions = [
        // User permanently deleted → their bookmarks go too
        'lvb_usr_user_id' => ['action' => 'cascade'],
        // Category permanently deleted → bookmarks survive, uncategorized
        'lvb_lvc_linkvault_category_id' => ['action' => 'null'],
    ];
}

class MultiLinkvaultBookmark extends SystemMultiBase {
    // Filter option keys exposed via getMultiResults() (NOT raw column names):
    //   'user_id'     => [$id, PDO::PARAM_INT]   → lvb_usr_user_id = ?
    //   'category_id' => [$id, PDO::PARAM_INT]   → lvb_lvc_linkvault_category_id = ?
    //   'deleted'     => false                   → lvb_delete_time IS NULL
    //   'since'       => [$ts, PDO::PARAM_STR]   → lvb_create_time >= ?
    //   'search'      => term                    → title/notes ILIKE
}
```

The table prefix `lvb`/`lvc` is ≥3 chars and an abbreviation of the plugin name, per the
"Choosing a prefix" guidance. The system blocks installation on prefix/class collisions.

### LinkvaultCategory — `plugins/linkvault/data/linkvault_categories_class.php`

```php
class LinkvaultCategory extends SystemBase {
    public static $prefix = 'lvc';
    public static $tablename = 'lvc_linkvault_categories';
    public static $pkey_column = 'lvc_linkvault_category_id';

    public static $field_specifications = array(
        'lvc_linkvault_category_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'lvc_name' => array('type'=>'varchar(64)', 'required'=>true, 'unique'=>true),
        'lvc_sort_order' => array('type'=>'int4', 'default'=>'0'),
        'lvc_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'lvc_delete_time' => array('type'=>'timestamp(6)'),
    );
}
```

### Soft-delete cascading

Per docs/deletion_system.md, `$foreign_key_actions` only governs `permanent_delete()`.
Soft-deleting a category from the admin page must manually null out (or leave) references —
LinkVault leaves bookmarks pointing at a soft-deleted category and the views simply skip
rendering a category label whose row is soft-deleted. No undelete flow in v1.

## plugin.json

```json
{
    "name": "LinkVault",
    "version": "1.0.0",
    "description": "Member bookmark collections with categories, weekly digest, and mobile API",
    "author": "Example Developer",
    "license": "MIT",
    "receives_upgrades": false,
    "included_in_publish": false,
    "requires": {
        "php": ">=8.0",
        "joinery": ">=1.0",
        "extensions": ["pdo", "json"]
    },
    "settings": [
        { "name": "linkvault_enabled", "default": "1" },
        { "name": "linkvault_max_bookmarks_per_user", "default": "500" },
        { "name": "linkvault_digest_enabled", "default": "1" }
    ],
    "adminMenu": [
        {
            "slug": "linkvault",
            "title": "LinkVault",
            "icon": "bookmark",
            "permission": 5,
            "order": 40,
            "items": [
                { "slug": "linkvault-bookmarks", "title": "Bookmarks",
                  "url": "/plugins/linkvault/admin/admin_linkvault", "order": 1 },
                { "slug": "linkvault-categories", "title": "Categories",
                  "url": "/plugins/linkvault/admin/admin_linkvault_categories", "order": 2 }
            ]
        }
    ],
    "profileMenu": [
        {
            "slug": "linkvault-bookmarks",
            "title": "My Bookmarks",
            "url": "/profile/linkvault",
            "icon": "bookmark",
            "visibility": "in",
            "permission": 1,
            "order": 70,
            "settingActivate": "linkvault_enabled"
        }
    ]
}
```

Notes:
- All setting names start with the plugin directory name (`linkvault_*`) — required by manifest
  validation and by the settings_form convention.
- Defaults are strings (`"1"`, `"500"`); JSON-native booleans/numbers are rejected.
- `settingActivate` hides the profile entry when the feature is switched off.
- Distribution flags are both `false`: this plugin is authored for one site and should not ship
  in upgrade archives (per the theme.json/plugin.json distribution-flags section).
- `adminMenu` child slugs and the `profileMenu` slug start with `linkvault-` per the slug rules.

## Settings

Three settings, all seeded from the manifest on activate (seed-only — existing values never
overwritten). Rendered on the main admin settings page by `settings_form.php`, which is
auto-discovered (no registration):

```php
<?php
// plugins/linkvault/settings_form.php
// $formwriter, $settings, and $session are already in scope (per docs/settings.md)

$formwriter->dropinput('linkvault_enabled', 'Enable LinkVault', [
    'options' => [1 => 'Yes', 0 => 'No'],
    'value' => $settings->get_setting('linkvault_enabled'),
]);

$formwriter->textinput('linkvault_max_bookmarks_per_user', 'Max bookmarks per user', [
    'value' => $settings->get_setting('linkvault_max_bookmarks_per_user'),
    'helptext' => 'Members cannot save more than this many bookmarks (default 500)',
]);

$formwriter->dropinput('linkvault_digest_enabled', 'Send weekly digest emails', [
    'options' => [1 => 'Yes', 0 => 'No'],
    'value' => $settings->get_setting('linkvault_digest_enabled'),
]);
```

The settings page handles the form submit; fields save automatically alongside core settings.

## Routing

Per the Plugin URL Namespace section, the plugin owns `/linkvault`, `/linkvault/*`,
`/profile/linkvault`, `/profile/linkvault/*`, `/admin/linkvault`, `/admin/linkvault/*` — and
view files auto-discover with **no serve.php entries**:

| URL | File | Notes |
|---|---|---|
| `/linkvault` | `views/index.php` | public explainer page |
| `/profile/linkvault` | `views/profile/index.php` | member bookmark list |
| `/profile/linkvault/bookmark_edit` | `views/profile/bookmark_edit.php` | add/edit form |
| `/plugins/linkvault/admin/admin_linkvault` | `admin/admin_linkvault.php` | admin moderation |

One explicit route is declared, because the profile pages need a login gate and the feature
needs a kill switch — the two documented reasons to add a serve.php route:

```php
// plugins/linkvault/serve.php
$routes = [
    'dynamic' => [
        '/profile/linkvault' => [
            'view'           => 'views/profile/index',
            'min_permission' => 1,
            'check_setting'  => 'linkvault_enabled',
        ],
        '/profile/linkvault/bookmark_edit' => [
            'view'           => 'views/profile/bookmark_edit',
            'min_permission' => 1,
            'check_setting'  => 'linkvault_enabled',
        ],
    ],
];
```

Routes are inside the plugin namespace (routes outside it are dropped with a warning). All
internal links use namespaced, extension-less URLs (`/profile/linkvault/bookmark_edit?...`,
never `/profile/bookmark_edit` and never `.php`).

## Business Logic

One logic file per page, single canonical signature (per the Plugin Developer Guide: *"Every
logic file in the codebase — core or plugin — uses one signature:
`function foo_logic(array $input): LogicResult`. There is no second variant."*). All paths
return a `LogicResult`; no `exit()`, no `throw` escaping, no direct `header()` calls.

### bookmark_edit_logic — `plugins/linkvault/logic/bookmark_edit_logic.php`

```php
<?php
function bookmark_edit_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/linkvault/data/linkvault_bookmarks_class.php'));
    require_once(PathHelper::getIncludePath('plugins/linkvault/data/linkvault_categories_class.php'));

    $settings = Globalvars::get_instance();
    $session  = SessionControl::get_instance();

    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login');
    }
    $user_id = $session->get_user_id();

    // Load-or-create. FormWriter posts the PK as edit_primary_key_value; the
    // page-open link carries it in GET. Check POST field first (per
    // docs/formwriter.md "Edit Forms with edit_primary_key_value").
    if (isset($input['edit_primary_key_value'])) {
        $bookmark = new LinkvaultBookmark($input['edit_primary_key_value'], TRUE);
    } elseif (isset($input['lvb_linkvault_bookmark_id'])) {
        $bookmark = new LinkvaultBookmark($input['lvb_linkvault_bookmark_id'], TRUE);
    } else {
        $bookmark = new LinkvaultBookmark(NULL);
    }

    // Ownership check — a member may only edit their own bookmarks
    if ($bookmark->key && $bookmark->get('lvb_usr_user_id') != $user_id) {
        return LogicResult::error('Bookmark not found');
    }

    if (LibraryFunctions::isFormSubmission()) {   // POST only — never if($input)
        // Per-user cap from settings (empty-string-safe default handling)
        $cap = (int)($settings->get_setting('linkvault_max_bookmarks_per_user') ?: 500);
        if (!$bookmark->key) {
            $existing = new MultiLinkvaultBookmark(['user_id' => [$user_id, PDO::PARAM_INT],
                                                    'deleted' => false]);
            if ($existing->count_all() >= $cap) {
                return LogicResult::error('Bookmark limit reached (' . $cap . ')');
            }
        }

        try {
            $bookmark->set('lvb_usr_user_id', $user_id);
            $bookmark->set('lvb_url',   $input['lvb_url'] ?? '');
            $bookmark->set('lvb_title', $input['lvb_title'] ?? '');
            $bookmark->set('lvb_notes', $input['lvb_notes'] ?? '');
            $bookmark->set('lvb_lvc_linkvault_category_id',
                           $input['lvb_lvc_linkvault_category_id'] ?: NULL);
            $bookmark->prepare();   // server-side validation from $field_specifications
            $bookmark->save();
        } catch (DisplayableUserException $e) {
            return LogicResult::error($e->getMessage(), $input);
        }
        return LogicResult::redirect('/profile/linkvault');
    }

    // GET → render the form
    $categories = new MultiLinkvaultCategory(['deleted' => false], ['lvc_sort_order' => 'ASC']);
    $categories->load();

    return LogicResult::render([
        'bookmark'   => $bookmark,
        'categories' => $categories,
        'settings'   => $settings,
        'session'    => $session,
    ]);
}

// ---- API companion: makes this a mobile-app action (per docs/api.md) ----
// POST /api/v1/action/linkvault/bookmark_edit
function bookmark_edit_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Create or update a bookmark for the authenticated user',
    ];
}

// ---- Form builder companion: server-driven form (per docs/formwriter.md §11) ----
// GET /api/v1/form/linkvault/bookmark_edit
function bookmark_edit_logic_form($formwriter, $user = null, $input = []) {
    require_once(PathHelper::getIncludePath('plugins/linkvault/data/linkvault_categories_class.php'));

    $categories = new MultiLinkvaultCategory(['deleted' => false], ['lvc_sort_order' => 'ASC']);
    $categories->load();
    $options = [];
    foreach ($categories as $cat) {
        $options[$cat->key] = $cat->get('lvc_name');
    }

    $formwriter->textinput('lvb_url', 'URL', ['required' => true, 'validation' => 'url']);
    $formwriter->textinput('lvb_title', 'Title', ['required' => true, 'maxlength' => 255]);
    $formwriter->dropinput('lvb_lvc_linkvault_category_id', 'Category', [
        'options' => $options,
        'empty_option' => '-- No category --',
    ]);
    $formwriter->textarea('lvb_notes', 'Notes', ['rows' => 4]);
    $formwriter->submitbutton('btn_submit', 'Save Bookmark');
}
```

The builder contains only serializable constructs (no `custom_script`, no bot defences, no file
inputs), so `FormWriterV2JSON` can serve it to native apps unchanged. The web view calls the
same builder between `begin_form()`/`end_form()` — one definition, two renderers.

### bookmarks_logic — member list page

Standard render-only logic: loads the member's bookmarks (paged, newest first) plus categories
for the filter dropdown; returns `LogicResult::render()`. Delete is a POST action handled here:

```php
if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') === 'delete') {
    $bookmark = new LinkvaultBookmark($input['bookmark_id'], TRUE);
    if ($bookmark->get('lvb_usr_user_id') == $session->get_user_id()) {
        $bookmark->soft_delete();
    }
    return LogicResult::redirect('/profile/linkvault');
}
```

## Views

### Member views (theme-integrated)

Plugin views render inside the active theme. Per the Profile/Member Area section of the Plugin
Developer Guide, profile pages use the active theme's `PublicPage` directly and wrap content in
the `.jy-ui` scope with jy-ui kit components:

```php
<?php
// plugins/linkvault/views/profile/index.php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/linkvault/logic/bookmarks_logic.php'));

$page_vars = process_logic(bookmarks_logic(array_merge($_GET, $_POST)));

$page = new PublicPage();
$page->public_header(['title' => 'My Bookmarks']);
?>
<div class="jy-ui">
  <div class="jy-page-header"><h1>My Bookmarks</h1></div>
  <div class="jy-panel">
    <p><a href="/profile/linkvault/bookmark_edit">Add a bookmark</a></p>
    <?php foreach ($page_vars['bookmarks'] as $bm): ?>
      <div class="card">
        <a href="<?= htmlspecialchars($bm->get('lvb_url')) ?>" rel="noopener">
          <?= htmlspecialchars($bm->get('lvb_title')) ?></a>
        <p><?= htmlspecialchars($bm->get('lvb_notes')) ?></p>
        <a href="/profile/linkvault/bookmark_edit?lvb_linkvault_bookmark_id=<?= $bm->key ?>">Edit</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php $page->public_footer(); ?>
```

The edit view builds its form by calling the shared builder, so web and API stay in lockstep:

```php
$formwriter = $page->getFormWriter('bookmark_form', 'v2', [
    'model' => $page_vars['bookmark'],
    'edit_primary_key_value' => $page_vars['bookmark']->key,
]);
$formwriter->begin_form();
bookmark_edit_logic_form($formwriter, null, array_merge($_GET, $_POST));
$formwriter->end_form();
```

All markup is vanilla HTML5/CSS — no Bootstrap, no jQuery (Theme Framework Rules). The delete
button on the list page is a single-button action form (hidden inputs + submit only), the one
documented exception to the FormWriter-for-every-form rule.

### Public landing view

`views/index.php` — static explainer with a login CTA; honors `linkvault_enabled` by rendering
a "not available" message when the setting is falsy (the auto-discovered route has no
`check_setting`, so the view checks).

## Admin Interface

Two admin pages following docs/admin_pages.md exactly: logic in `admin/logic/`, view in
`admin/`, `AdminPage` chrome, LogicResult + `process_logic()`, POST→redirect with
DisplayMessage flash, JoineryModal for delete confirmation, extension-less URLs.

```php
<?php
// plugins/linkvault/admin/admin_linkvault.php  (view — display only)
require_once(PathHelper::getIncludePath('plugins/linkvault/admin/logic/admin_linkvault_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

$page_vars = process_logic(admin_linkvault_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];

$page = new AdminPage();
$page->admin_header([
    'menu-id'        => 'linkvault-bookmarks',
    'page_title'     => 'LinkVault',
    'readable_title' => 'All Bookmarks',
    'breadcrumbs'    => ['Bookmarks' => ''],
    'session'        => $session,
]);

$pager = new Pager(['numrecords' => $page_vars['numrecords'], 'numperpage' => 30]);
$page->tableheader(['Title', 'User', 'Added', 'Actions'],
                   ['title' => 'Bookmarks', 'search_on' => TRUE], $pager);
foreach ($page_vars['bookmarks'] as $bm) {
    $row = [];
    array_push($row, htmlspecialchars($bm->get('lvb_title')));
    array_push($row, (int)$bm->get('lvb_usr_user_id'));
    array_push($row, LibraryFunctions::convert_time($bm->get('lvb_create_time'), 'UTC',
                                                    $session->get_timezone(), 'M j, Y'));
    array_push($row, '<a href="/plugins/linkvault/admin/admin_linkvault?action=delete&bookmark_id='
                     . $bm->key . '">Delete</a>');
    $page->disprow($row);
}
$page->endtable($pager);
$page->admin_footer();
```

Logic file: `check_permission(5)` first, actions before display data, `count_all()` before
`load()`, sort/search/pagination via `fetch_variable_local`. The GET-action delete link opts in
to the GET-mutation tripwire:

```php
case 'delete':
    $bookmark = new LinkvaultBookmark($input['bookmark_id'], TRUE);
    SystemBase::$allow_get_mutation = true;
    try { $bookmark->soft_delete(); }
    finally { SystemBase::$allow_get_mutation = false; }
    return LogicResult::redirect('/plugins/linkvault/admin/admin_linkvault');
```

## Scheduled Task — Weekly Digest

`tasks/LinkvaultWeeklyDigest.json`:

```json
{
    "name": "LinkVault Weekly Digest",
    "description": "Emails each member a digest of bookmarks they added in the last 7 days",
    "default_frequency": "weekly",
    "default_day_of_week": 1,
    "default_time": "09:00:00",
    "config_fields": {
        "subject_line": {"type": "text", "label": "Email subject", "required": false}
    }
}
```

`tasks/LinkvaultWeeklyDigest.php` implements `ScheduledTaskInterface` **and**
`ScheduledTaskDryRunnable` (email task → dry run is the documented best practice):

```php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class LinkvaultWeeklyDigest implements ScheduledTaskInterface, ScheduledTaskDryRunnable {
    public function run(array $config) {
        $settings = Globalvars::get_instance();
        if (!$settings->get_setting('linkvault_digest_enabled')) {
            return ['status' => 'skipped', 'message' => 'Digest disabled in settings'];
        }
        $sent = $this->process($config, $send = true);
        return ['status' => 'success', 'message' => "Sent {$sent} digests"];
    }

    public function dryRun(array $config) {
        $preview = $this->process($config, $send = false);
        return ['status' => 'success',
                'message' => "Would send {$preview['count']} digests",
                'html' => $preview['html']];
    }

    // process(): MultiLinkvaultBookmark with 'since' = 7 days ago, grouped by user,
    // one SystemMailer send per user with bookmarks added that week.
}
```

Lifecycle is automatic: discovered from `plugins/linkvault/tasks/`, activated by the admin on
the Scheduled Tasks page, suspended on plugin deactivate, resumed on reactivate, records
deleted on uninstall. The runner's per-task advisory lock means no re-entrancy handling is
needed in the task.

## Seed Migration

`migrations/001_seed_categories.sql` — initial data only (never schema), idempotent, runs once
at install (tracked in `plm_plugin_migrations`):

```sql
INSERT INTO lvc_linkvault_categories (lvc_name, lvc_sort_order)
SELECT v.name, v.ord FROM (VALUES
    ('Reading', 1), ('Tools', 2), ('Reference', 3)
) AS v(name, ord)
WHERE NOT EXISTS (SELECT 1 FROM lvc_linkvault_categories WHERE lvc_name = v.name);
```

## API Surface

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /api/v1/action/linkvault/bookmark_edit` | session or machine key (write perm) | add/edit a bookmark as the key's user |
| `GET /api/v1/form/linkvault/bookmark_edit` | key headers | server-driven form definition for the app renderer |
| `GET /api/v1/actions` | key headers | lists `linkvault/bookmark_edit` with `has_form: true` |

The action runs under session simulation, so the logic's `$session->get_user_id()` and
ownership checks work identically to the web path. Inputs arrive merged (GET + JSON body) in
`$input`; the logic never reads `$_REQUEST`. Validation failures surface as the standard 422
`validation_errors` map from `prepare()`.

## Install / Activate Procedure

1. Place the plugin directory at `plugins/linkvault/`.
2. Admin > System > Plugins → **Install** (creates both tables from `$field_specifications`,
   runs the seed migration, records the plugin as inactive).
3. **Activate** (seeds the three settings, creates admin + profile menus, registers deletion
   rules).
4. Activate "LinkVault Weekly Digest" on Admin > System > Scheduled Tasks.
5. Verify `/profile/linkvault` renders for a logged-in member and 404s/redirects for a guest.

Schema changes later in life: edit `$field_specifications`, then run **Sync with Filesystem**
on the admin Plugins page (or `update_database`, whose final step syncs plugins).

## Validation & QA Checklist

- [ ] `php -l` on every PHP file
- [ ] `php maintenance_scripts/dev_tools/validate_php_file.php` on every created file
- [ ] Guest hitting `/profile/linkvault` → login redirect (route `min_permission`)
- [ ] `linkvault_enabled = 0` → profile routes 404, profile menu entry hidden
- [ ] Member cannot edit/delete another member's bookmark (ownership check)
- [ ] Refresh after save does not resubmit (POST→redirect)
- [ ] Bookmark cap enforced at the documented setting value and at the 500 fallback
- [ ] `prepare()` rejects bad URL / short title; web form shows field errors
- [ ] API action creates a bookmark; form endpoint serves the definition; both respect the cap
- [ ] Dry run of the digest shows the preview HTML and sends nothing
- [ ] Uninstall removes tables, settings, menus, and task records; reinstall works clean

## Documentation

Per the docs-in-specs rule: ship `plugins/linkvault/docs/overview.md` describing the data
model, the action surface, and the digest task, and add a line to the Documentation Index in
the agent file (via the admin agent-files editor, not by editing CLAUDE.md on disk).

---

## Appendix A: Assumptions Forced by the Documentation

Recorded while writing this spec; each is a place where the docs were silent, ambiguous, or
self-contradictory and a choice had to be made. These feed the companion audit spec.

1. **Logic signature** — The Plugin Developer Guide says there is exactly one signature
   (`array $input`), but docs/logic_architecture.md and docs/admin_pages.md demonstrate
   `($get_vars, $post_vars)` in nearly every example, including the "Complete Template".
   *Chose:* single `$input` with `array_merge($_GET, $_POST)` at the call site.
2. **Session user id** — No doc states the method for getting the logged-in user's id.
   `SessionControl` examples show `is_logged_in()`, `check_permission()`, `get_timezone()`,
   `get_permission_level()`. *Assumed:* `$session->get_user_id()` exists.
3. **`min_permission` semantics** — Route option takes an integer; docs never define what
   level an ordinary logged-in member has. The plugin-routes example uses `min_permission => 0`
   for a profile page, which reads as "no permission required" — yet the page is clearly
   member-only. *Assumed:* 1 = any logged-in user; 0 = public.
4. **Field-spec dialect** — The guide's first data-model example uses
   `['required'=>true,'type'=>'int']` / `['type'=>'varchar','length'=>255]` with pkey `mdt_id`;
   the Table Creation section uses `'type'=>'int8','serial'=>true` / `'varchar(255)'` with a
   long pkey name. *Chose:* the Table Creation dialect (matches docs/example_class.php style).
5. **`$prefix` static** — Shown in plugin examples, never listed as required, never explained
   (is it what maps form-field prefixes to models for FormWriter auto-validation?). *Assumed:*
   required, and used for FormWriter prefix→model mapping.
6. **Multi-class option keys** — Docs say each Multi class defines its own filter keys in
   `getMultiResults()` but never document how to *write* one (what the base class needs, how
   keys map to SQL). The spec's `MultiLinkvaultBookmark` key list is invented by analogy.
7. **CSRF on the server** — FormWriter docs say CSRF is automatic for POST forms but the only
   server-side check shown is a manual `validateCSRF($_POST)` call. *Assumed:* the framework
   verifies it automatically somewhere in the POST path; the spec's logic does not call
   `validateCSRF()`. If that's wrong, every form handler in this spec is missing a check.
8. **`getFormWriter()` second argument** — Some examples pass `'v2'`, some don't. *Assumed:*
   `'v2'` selects the FormWriterV2 family and is required when passing the options array.
9. **Plugin admin URL form** — Two admin surfaces are documented:
   `/plugins/{plugin}/admin/{page}` (admin discovery route, AdminPage-based) and
   `/admin/{plugin}/*` → `views/admin/*.php` (namespace table). When to use which is not
   stated. *Chose:* `admin/` directory with the discovery route, because docs/admin_pages.md
   says plugin admin pages mirror `/adm/` + `/adm/logic/` there.
10. **`process_logic()` availability** — Assumed globally available in plugin views (it is
    never required in any doc example).
11. **`DisplayableUserException` availability** — Assumed loadable without an explicit
    require (validation docs catch it without one).
12. **Settings empty-value type** — docs/settings.md says missing settings return `''`; the
    Plugin Developer Guide says `get_setting()` returns `null` before first save. *Chose:*
    falsy-check (`?:`) so either behavior works.
13. **SystemMailer** — Named in the template's Key Integration Points with zero usage
    documentation. The digest task hand-waves `SystemMailer` usage.
14. **Scheduled task `since`-style queries** — `time_shift()` is documented for display;
    assumed acceptable for computing "7 days ago" as a UTC string for a query bound.
15. **Plugin asset serving** — `/plugins/{plugin}/assets/*` appears in the static-routes
    example, so plugin CSS/JS would be served from `plugins/linkvault/assets/`; not needed in
    v1 but the cache-busting story (ThemeHelper::asset for plugins?) is undocumented.
16. **Sessionless API caveat** — Whether plugin actions may be `requires_session => false`
    is implied but never shown for plugins. Not needed here.
17. **CRUD exposure of plugin models** — docs/api.md says "Any SystemBase model class is
    available via the API" — taken at face value, `GET /api/v1/LinkvaultBookmark/{id}` would
    expose bookmarks to any read-capable key with no ownership scoping. The spec ignores this
    surface but flags it as a likely security footgun or a doc overstatement.
