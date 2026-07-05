# Plugin Rename: `inbound_email` → `mailbox`

## Status: ready to implement

## Version: 1.0

## Summary

The `inbound_email` plugin outgrew its name. It began as receive-and-forward and is
now the platform's full self-hosted mailbox: receiving, webmail reading, sending as
the mailbox, two-way IMAP sync, labels, filters, full-text search — and the email
security architecture (encryption at rest, security levels, hardened relay) specs
against it. This spec renames the plugin's **identity** to `mailbox` everywhere,
while leaving names that merely **describe inbound mail flow** alone.

The platform is pre-launch (no production users), so this is the cheapest the rename
will ever be. The dev box (`dev.getjoinery.com`) has live inbound mail flowing
through Postfix, so the spec includes the server-side steps to keep it flowing.

This spec is written for mechanical execution. Every judgment call has already been
made; every rename is enumerated. Where the executor must verify something, the spec
says exactly what to check and where. **Do not improvise beyond what is written
here. If reality contradicts this spec (a file is missing, a count is different, a
verification fails), stop and report rather than adapting silently.**

There is direct precedent in-repo: the `scrolldaddy` → `dns_filtering` plugin rename.
Its two core migrations — `migrations/rename_scrolldaddy_to_dns_filtering.php` and
`migrations/rename_scrolldaddy_settings.php` — are the pattern Phase 1 follows.

## The Naming Rule (already applied — do not re-judge)

**`inbound_email` as the plugin's name goes away. "Inbound email" as a description
of mail direction stays.**

Concretely:

- Renamed: the plugin directory, plugin display name, URLs, the `/api/v1` action
  namespace, setting-key prefix, admin page basenames, plugin-identity helper
  functions, docs, active specs, storage-key prefix for local files.
- Kept: PHP class names (`InboundEmailRouter` routes *inbound email* — accurate),
  data-class filenames and their tables/column prefixes (`iem_`, `ied_`, `iea_`,
  `ieg_`, `ilb_`, `ilm_`, `iia_`, `iif_`, `ima_` — schema identifiers, opaque),
  task class names (`ApplyInboundEmailFilters` applies *inbound* filters),
  test basenames, `utils/inbound_email_handler.php` (handles *inbound email* —
  and its basename appearing in Postfix `master.cf` is descriptive),
  `ajax/inbound_email_webhook.php` (the *inbound* webhook; its flat URL
  `/ajax/inbound_email_webhook` is configured in external provider dashboards
  and stays stable).

## What Does NOT Change (verify none of these were touched at the end)

1. **Database table names and column prefixes.** All `iem_*`, `ied_*`, `iea_*`,
   `ieg_*`, `iif_*`, `ilb_*`, `ilm_*`, `iia_*`, `imf_*`, `ima_*` tables and columns.
   No schema change of any kind in this spec.
2. **PHP class names.** `InboundEmailRouter`, `InboundEmailHealth`,
   `InboundEmailSetupCheck`, `InboundProviderRegistry`, `InboundImapOAuthConsumer`,
   `RawMessageStore`, `MailboxSender`, `MailboxService`, `MailboxViewer`,
   `ImapClient`, `ImapIngestor`, `ImapSyncer`, `AuthenticationResults`,
   `SRSRewriter`, all data classes (`InboundEmailMessage`, etc.), all task classes.
3. **File basenames** under `data/`, `includes/`, `tasks/`, `tests/`, `utils/`,
   `ajax/`, `provisioning/` inside the plugin. Only `admin/`, `logic/`, and one CSS
   file get basename renames (Phase 2 table).
4. **`specs/implemented/`** — never modified, per standing rule. Old paths inside
   them are accurate history.
5. **GET/POST parameter names** derived from column names
   (e.g. `?iem_inbound_email_message_id=N`) — they follow tables, which don't change.
6. **The flat webhook URL** `/ajax/inbound_email_webhook` (external providers post
   here; resolution is by basename, not plugin directory, so the directory rename
   does not move it).
7. **Core `includes/email_providers/*.php` basenames and class names** — only the
   path strings and setting-key strings inside them change.
8. **`mailgun_*`, `sendgrid_*`, `ses_*` setting keys** — provider-scoped, not
   plugin-prefixed. Untouched.
9. **Git history.** Use `git mv` so renames are tracked; never rewrite history.

## Decisions of Record

- **New directory name:** `plugins/mailbox/`. Display name: `Mailbox`.
- **Why not `mail`:** the core platform already has an outbound email system
  (`EmailSender`, `docs/email_system.md`). "Mailbox" keeps the two unambiguous:
  core sends email; the mailbox plugin *is* the user's mail home.
- **API namespace** becomes `mailbox/{action}` automatically (the namespace is the
  plugin directory name — see `api/apiv1.php`). Consumers (native apps, docs) are
  updated in this spec; the platform is pre-launch so no external API consumers
  exist.
- **Setting keys** MUST be renamed: PluginManager enforces that declared setting
  names start with the plugin directory name (see the comment in
  `migrations/rename_scrolldaddy_settings.php`). Old keys would stop syncing.
- **Two setting keys collapse** rather than gaining a doubled prefix (see Phase 3.2).
- **Storage keys**: `RawMessageStore` writes raw messages under keys like
  `inbound_email/{yyyy}/{mm}/{id}.eml` (local tier: `{site_root}/storage/`).
  New writes use `mailbox/...`; existing **local** rows and files are migrated;
  rows with other drivers (`inline`, `remote`, any future `cloud`) are left alone —
  reads always use the stored `iem_raw_storage_key`, so unmigrated keys stay valid.

---

# Execution Phases

Execute strictly in order. Do not start a phase until the previous phase's
verification passes.

## Phase 0 — Preflight

1. Work on a branch: `git checkout -b rename-inbound-email-to-mailbox` (branch from
   `main` with a clean tree; if the tree is dirty with unrelated changes, stop and
   report).
2. Confirm current state matches this spec's inventory:
   ```bash
   test -d /var/www/html/joinerytest/public_html/plugins/inbound_email || echo "STOP: plugin dir missing"
   psql -U postgres -d joinerytest -t -c "SELECT plg_name FROM plg_plugins WHERE plg_name='inbound_email';"
   # expected: one row, 'inbound_email'
   ```
3. Read `includes/PluginManager.php` and find the admin/profile menu sync method.
   Confirm how it matches plugin.json menu entries to existing `amu_admin_menus`
   rows. This spec assumes matching is by `amu_slug` (the table has a UNIQUE
   constraint on `amu_slug`). If matching is actually by something else, adjust the
   two `amu_slug` UPDATEs in the Phase 1 migration to whatever key sync uses, so
   the renamed menu entries update **in place** instead of creating duplicates and
   orphaning the old rows.

## Phase 1 — Core data migration

Schema is untouched; this is data-only, which is exactly what core migrations are
for. The migration must run during `update_database` **before** the plugin
filesystem sync (core migrations do run before the plugin-sync step, which is last
in the pipeline — this is why the registry rename lives here and not in a plugin
migration: by the time sync scans `plugins/mailbox/`, the registry row must already
say `mailbox`, or sync would treat it as a brand-new plugin and re-run all 13
already-applied plugin migrations).

Create `migrations/rename_inbound_email_to_mailbox.php` (chmod 666):

```php
<?php
function rename_inbound_email_to_mailbox() {
    $dblink = DbConnector::get_instance()->get_db_link();
    $total = 0;

    // 1. Plugin registry row (must precede PluginManager::sync()).
    $stmt = $dblink->prepare("UPDATE plg_plugins SET plg_name='mailbox' WHERE plg_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plg_plugins: renamed $n row(s)\n";
    $total += $n;

    // 2. Applied-migration ledger. Without this, sync under the new name sees
    //    zero applied migrations for 'mailbox' and re-runs all 13.
    $stmt = $dblink->prepare("UPDATE plm_plugin_migrations SET plm_plugin_name='mailbox' WHERE plm_plugin_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plm_plugin_migrations: rebound $n row(s)\n";
    $total += $n;

    // 3. Version tracking (row may not exist on every deployment; 0 rows is fine).
    $stmt = $dblink->prepare("UPDATE plv_plugin_versions SET plv_plugin_name='mailbox' WHERE plv_plugin_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plv_plugin_versions: rebound $n row(s)\n";
    $total += $n;

    // 4. Scheduled-task plugin binding (rows exist only where tasks were registered).
    $stmt = $dblink->prepare("UPDATE sct_scheduled_tasks SET sct_plugin_name='mailbox' WHERE sct_plugin_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  sct_scheduled_tasks: rebound $n task(s)\n";
    $total += $n;

    // 5. Menu slugs, so plugin.json menu sync updates the existing rows in place.
    //    (Adjust per the Phase 0 step 3 finding if sync matches by another key.)
    $stmt = $dblink->prepare("UPDATE amu_admin_menus SET amu_slug='mailbox' WHERE amu_slug='inbound-email-mailbox'");
    $stmt->execute();
    $total += $stmt->rowCount();
    $stmt = $dblink->prepare("UPDATE amu_admin_menus SET amu_slug='mailbox-reader' WHERE amu_slug='incoming'");
    $stmt->execute();
    $total += $stmt->rowCount();

    // 6. Setting keys. Two keys collapse (mailbox_mailbox_* would be wrong),
    //    so they are renamed explicitly BEFORE the generic prefix swap.
    $stmt = $dblink->prepare("UPDATE stg_settings SET stg_name='mailbox_retention_days' WHERE stg_name='inbound_email_mailbox_retention_days'");
    $stmt->execute();
    $total += $stmt->rowCount();
    $stmt = $dblink->prepare("UPDATE stg_settings SET stg_name='mailbox_max_per_window' WHERE stg_name='inbound_email_mailbox_max_per_window'");
    $stmt->execute();
    $total += $stmt->rowCount();
    $stmt = $dblink->prepare("
        UPDATE stg_settings
        SET stg_name = 'mailbox_' || substring(stg_name from 15)
        WHERE stg_name LIKE 'inbound\\_email\\_%'
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    echo "  stg_settings: renamed $n setting key(s)\n";
    $total += $n;

    // 7. Local raw-message storage: rewrite keys and move the directory.
    //    Only driver='local' rows reference files under {site_root}/storage/.
    //    inline/remote/cloud rows keep their stored keys (reads use the stored
    //    key verbatim, so old-prefix keys on other drivers remain valid).
    $stmt = $dblink->prepare("
        UPDATE iem_inbound_email_messages
        SET iem_raw_storage_key = 'mailbox/' || substring(iem_raw_storage_key from 15)
        WHERE iem_raw_storage_driver = 'local'
          AND iem_raw_storage_key LIKE 'inbound\\_email/%'
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  iem raw storage keys: rewrote $n row(s)\n";
    $total += $n;

    $site_root = rtrim(PathHelper::getSiteRoot(), '/');
    $old_dir = $site_root . '/storage/inbound_email';
    $new_dir = $site_root . '/storage/mailbox';
    if (is_dir($old_dir) && !is_dir($new_dir)) {
        if (rename($old_dir, $new_dir)) {
            echo "  storage: moved inbound_email/ -> mailbox/\n";
        } else {
            echo "  WARNING: could not move $old_dir to $new_dir - move it manually\n";
        }
    }

    echo "Rename migration: $total row(s) updated.\n";
    return true;
}
?>
```

Register it in `migrations/migrations.php`, appended at the end following the exact
pattern of the existing entries (see the `rename_scrolldaddy_to_dns_filtering.php`
entry around line 745 for the shape). The function name must match the filename.

**Note on substring offsets:** `'inbound_email_'` is 14 characters, so
`substring(stg_name from 15)` keeps everything after the prefix. `'inbound_email/'`
is also 14 characters — same offset. Do not change these numbers.

**Do not run the migration yet** — it runs in Phase 7 via `update_database`, after
the filesystem changes are in place. (Reminder: database WRITE operations require
explicit user confirmation; Phase 7 flags this.)

## Phase 2 — File and directory renames (all via `git mv`)

### 2.1 The plugin directory

```bash
cd /var/www/html/joinerytest/public_html
git mv plugins/inbound_email plugins/mailbox
```

### 2.2 Admin page files (13) — inside `plugins/mailbox/admin/`

| Old | New |
|---|---|
| `admin_inbound_email.php` | `admin_mailbox.php` |
| `admin_inbound_email_accounts.php` | `admin_mailbox_accounts.php` |
| `admin_inbound_email_alias.php` | `admin_mailbox_alias.php` |
| `admin_inbound_email_attachment.php` | `admin_mailbox_attachment.php` |
| `admin_inbound_email_domains.php` | `admin_mailbox_domains.php` |
| `admin_inbound_email_filters.php` | `admin_mailbox_filters.php` |
| `admin_inbound_email_imap.php` | `admin_mailbox_imap.php` |
| `admin_inbound_email_imap_edit.php` | `admin_mailbox_imap_edit.php` |
| `admin_inbound_email_logs.php` | `admin_mailbox_logs.php` |
| `admin_inbound_email_message.php` | `admin_mailbox_message.php` |
| `admin_inbound_email_reader.php` | `admin_mailbox_reader.php` |
| `admin_inbound_email_settings.php` | `admin_mailbox_settings.php` |
| `admin_inbound_email_setup.php` | `admin_mailbox_setup.php` |

### 2.3 Logic files (12) — inside `plugins/mailbox/logic/`

| Old | New |
|---|---|
| `admin_inbound_email_logic.php` | `admin_mailbox_logic.php` |
| `admin_inbound_email_accounts_logic.php` | `admin_mailbox_accounts_logic.php` |
| `admin_inbound_email_alias_logic.php` | `admin_mailbox_alias_logic.php` |
| `admin_inbound_email_attachment_logic.php` | `admin_mailbox_attachment_logic.php` |
| `admin_inbound_email_domains_logic.php` | `admin_mailbox_domains_logic.php` |
| `admin_inbound_email_filters_logic.php` | `admin_mailbox_filters_logic.php` |
| `admin_inbound_email_imap_logic.php` | `admin_mailbox_imap_logic.php` |
| `admin_inbound_email_imap_edit_logic.php` | `admin_mailbox_imap_edit_logic.php` |
| `admin_inbound_email_message_logic.php` | `admin_mailbox_message_logic.php` |
| `admin_inbound_email_reader_logic.php` | `admin_mailbox_reader_logic.php` |
| `admin_inbound_email_settings_logic.php` | `admin_mailbox_settings_logic.php` |
| `admin_inbound_email_setup_logic.php` | `admin_mailbox_setup_logic.php` |

The other logic files (`mailboxes_logic.php`, `send_logic.php`, `thread_logic.php`,
`thread_list_logic.php`, `thread_action_logic.php`, `profile_mailbox_logic.php`,
`profile_attachment_logic.php`) already carry the right names — do not touch their
basenames. Their public API action names change implicitly and only via the
namespace (`inbound_email/send` → `mailbox/send`).

### 2.4 Asset

```bash
git mv plugins/mailbox/assets/css/inbound_email.css plugins/mailbox/assets/css/mailbox.css
```

`assets/mailbox_reader.css`, `assets/mailbox_reader.js` — already correctly named.

### 2.5 Everything else keeps its basename

`data/*`, `includes/*`, `tasks/*`, `tests/*`, `utils/inbound_email_handler.php`,
`ajax/*` (including `inbound_email_webhook.php`), `provisioning/*`,
`views/profile/mailbox.php`, `views/profile/attachment.php`, `migrations/migrations.php`,
`settings_form.php`.

## Phase 3 — Content replacements

Apply in this exact order. **Never run a blanket replace of the bare string
`inbound_email`** — it would corrupt kept names (`inbound_email_handler.php`,
`inbound_email_webhook`, `iem_inbound_email_messages`, class-describing comments).
Each rule below is scoped to a longer, unambiguous pattern.

Scope for all rules: `public_html/` (excluding `.git/` and `specs/implemented/`),
`maintenance_scripts/`, `ios/`, `android/`. Includes `.php`, `.js`, `.css`, `.json`,
`.md`, `.sh`, `.swift`, `.kt` files.

### 3.1 Path and URL strings

| # | Old string | New string | Notes |
|---|---|---|---|
| 1 | `plugins/inbound_email/` | `plugins/mailbox/` | Covers `PathHelper::getIncludePath(...)`, requires, script paths, doc links. |
| 2 | `/profile/inbound_email/` | `/profile/mailbox/` | Profile-side URLs (plugin view auto-discovery namespace). |
| 3 | `admin_inbound_email` | `admin_mailbox` | Applies the Phase 2.2/2.3 renames to every reference: URLs (`/plugins/mailbox/admin/admin_mailbox_reader`), logic function names (`admin_inbound_email_reader_logic()` → `admin_mailbox_reader_logic()`), `_logic_api` variants, requires, `admin_tabs.php` link map, `InboundImapOAuthConsumer::ACCOUNTS_URL`, `utils/email_send_test.php` links. Safe as a bare substring: every occurrence of `admin_inbound_email` is plugin identity. |
| 4 | `inbound_email/mailboxes`, `inbound_email/thread_list`, `inbound_email/thread_action`, `inbound_email/thread`, `inbound_email/send` | `mailbox/mailboxes`, `mailbox/thread_list`, `mailbox/thread_action`, `mailbox/thread`, `mailbox/send` | API action names. Replace the *specific* five action strings (longest first, so `thread_list`/`thread_action` before `thread`), not a generic `inbound_email/` — that generic pattern also matches storage-key literals and doc prose handled elsewhere. Main consumers: `ios/joinery-kit/Sources/JoineryMailKit/*.swift`, `android/joinery-android-mail/src/**/*.kt`, fixtures (`navigation.json`, `thread.json`, `thread_list.json`, `mailboxes.json` in both apps' test trees), and any web JS. |

### 3.2 Setting keys (exact list — no pattern replace)

Replace each quoted key everywhere it appears (PHP, JS, JSON, shell, docs):

| Old key | New key |
|---|---|
| `inbound_email_enabled` | `mailbox_enabled` |
| `inbound_email_from_show_via` | `mailbox_from_show_via` |
| `inbound_email_log_retention_days` | `mailbox_log_retention_days` |
| `inbound_email_forwarding_max_destinations` | `mailbox_forwarding_max_destinations` |
| `inbound_email_forwarding_rate_limit_per_alias` | `mailbox_forwarding_rate_limit_per_alias` |
| `inbound_email_forwarding_rate_limit_per_domain` | `mailbox_forwarding_rate_limit_per_domain` |
| `inbound_email_forwarding_rate_limit_window` | `mailbox_forwarding_rate_limit_window` |
| `inbound_email_srs_enabled` | `mailbox_srs_enabled` |
| `inbound_email_srs_secret` | `mailbox_srs_secret` |
| `inbound_email_mail_hostname` | `mailbox_mail_hostname` |
| `inbound_email_public_ip` | `mailbox_public_ip` |
| `inbound_email_forwarding_smtp_host` | `mailbox_forwarding_smtp_host` |
| `inbound_email_forwarding_smtp_port` | `mailbox_forwarding_smtp_port` |
| `inbound_email_forwarding_smtp_username` | `mailbox_forwarding_smtp_username` |
| `inbound_email_forwarding_smtp_password` | `mailbox_forwarding_smtp_password` |
| `inbound_email_mailbox_retention_days` | `mailbox_retention_days` ← **collapsed, not prefixed** |
| `inbound_email_mailbox_max_per_window` | `mailbox_max_per_window` ← **collapsed, not prefixed** |
| `inbound_email_provider` | `mailbox_provider` |
| `inbound_email_spam_filtering_enabled` | `mailbox_spam_filtering_enabled` |
| `inbound_email_content_spam_filtering_enabled` | `mailbox_content_spam_filtering_enabled` |
| `inbound_email_rspamd_controller_url` | `mailbox_rspamd_controller_url` |

Apply the two collapsed keys FIRST (they contain other keys' prefixes as
substrings). Known consumers outside the plugin: `includes/email_providers/*.php`,
`utils/email_send_test.php`, `tests/integration/inbound_forwarding_relay_test.php`,
`assets/mailbox_reader.js` (carries `inbound_email_content_spam_filtering` — confirm
the full key string there and update it).

### 3.3 Plugin-identity helper function names

Rename every PHP function whose name starts with `inbound_email_` to start with
`mailbox_`, plus all call sites and `function_exists()` guards. Find them all:

```bash
grep -rnE "function[[:space:]]+inbound_email_[a-z_]+" plugins/mailbox/ includes/ --include="*.php"
```

Known: `inbound_email_admin_tabs()` (in `includes/admin_tabs.php`, called from every
admin page), `inbound_email_setup_write_setting()`, and an
`inbound_email_settings_write_setting()` referenced via a quoted string — resolve
each one the grep finds. The Phase 3.1 rule 3 already renamed the
`admin_inbound_email_*_logic()` family.

### 3.4 `plugin.json` edits (in `plugins/mailbox/plugin.json`)

- `"name"`: `"Inbound Email"` → `"Mailbox"`
- `"description"`: → `"Self-hosted email: receiving, forwarding, local mailboxes, webmail reader, IMAP sync, DKIM/SRS"`
- `"version"`: bump `1.27.0` → `1.28.0`
- `"tags"`: `["email", "mailbox", "inbound", "forwarding", "smtp"]`
- `"styles"`: `"assets/css/inbound_email.css"` → `"assets/css/mailbox.css"`
  (`"assets/mailbox_reader.css"` unchanged)
- `adminMenu[0]`: `"slug": "incoming"` → `"mailbox-reader"`, `"title": "Incoming"`
  → `"Mailbox"`, `"url"` → `/plugins/mailbox/admin/admin_mailbox_reader`
  (parent `emails`, permission, order unchanged)
- `profileMenu[0]`: `"slug": "inbound-email-mailbox"` → `"mailbox"`, `"url"` →
  `/profile/mailbox/mailbox` (title `Email`, `nativeScreen: "mailbox"`, icon,
  visibility, permission, order all unchanged)
- `settings[*].name`: apply the Phase 3.2 table (21 keys)
- `storage_profiles`, `provisioners`, `requires`, `receives_upgrades`,
  `included_in_publish`: unchanged (`RawMessageStore` is a class name;
  provisioner `check.call` values are class methods; `script` paths are
  plugin-relative)

### 3.5 Storage-key prefix in code

In `plugins/mailbox/includes/RawMessageStore.php`: the key-construction literal
(near line 237) `'inbound_email/' . $yyyy . '/' . $mm . ...` →
`'mailbox/' . ...`, and the two doc-comment lines showing the key format
(near lines 23 and 224). Bump the file's `@version` if present.

**Verify while editing:** confirm reads resolve via the stored
`iem_raw_storage_key` column (they do — see the `SELECT iem_raw_storage_key`
around line 275) and that nothing re-derives a key for an *existing* row from the
key-construction helper. If something does, stop and report.

### 3.6 Shell scripts and provisioning

- `plugins/mailbox/provisioning/install_email.sh`: `PLUGIN_DIR`/`PIPE_SCRIPT`
  paths and header comments (rule 3.1#1 largely covers it; hand-check the file).
- `plugins/mailbox/includes/InboundEmailSetupCheck.php` (~line 895): the pipe-path
  suffix string `plugins/inbound_email/utils/inbound_email_handler.php` →
  `plugins/mailbox/utils/inbound_email_handler.php` (note: only the directory
  segment changes; the basename stays).
- `maintenance_scripts/install_tools/_mail_stack_start.sh`: the `INSTALL_EMAIL`
  path, the `plg_name = 'inbound_email'` SQL literal → `'mailbox'`, and the echo
  strings.
- `maintenance_scripts/install_tools/default_agents_template.md`: the docs-index
  line for the plugin (path + label) — this template seeds new sites' CLAUDE.md.

### 3.7 Test fixtures and gates

`tests/functional/ios/menu_probe.php` (`const PLUGIN = 'inbound_email'` →
`'mailbox'`, and the URL), `phase2_gate.sh`, `phase3_gate.sh`,
`phase3_fixtures.php` — rules 3.1/3.2 cover most; hand-check each for leftover
plugin-identity strings (table/column names in them stay).

## Phase 4 — Documentation (current-state-only rewrite)

Per the docs rule: after this lands, every doc reads as though the plugin was
always called `mailbox`. No "formerly", "renamed", "previously known as".

1. `plugins/mailbox/docs/overview.md` — full pass: title, path references, URLs,
   the Postfix `master.cf` example (`argv=... plugins/mailbox/utils/inbound_email_handler.php`),
   the directory-tree diagram, admin page names, setting keys. Table names stay.
2. Core docs that reference the plugin (verified list):
   `docs/index.md`, `docs/api.md`, `docs/deletion_system.md`,
   `docs/mobile_apps.md`, `docs/scheduled_tasks.md`, `docs/email_system.md`,
   `docs/oauth2.md`, `docs/plugin_developer_guide.md`.
3. **CLAUDE.md and GEMINI.md are NOT edited on disk.** They are generated from the
   `agf_agent_files` table. Update the "Internal CLAUDE.md" record via the admin
   interface at `/admin/admin_agent_files` (and the GEMINI record if it has its own):
   the docs-index line (`plugins/inbound_email/docs/overview.md` →
   `plugins/mailbox/docs/overview.md`, label "Inbound Email Plugin" → "Mailbox
   Plugin"), and in the "Inbound email testing" bullet, the phrase
   "in the inbound_email admin" → "in the mailbox admin". The `iem_*` table/column
   references in that bullet stay exactly as they are.

## Phase 5 — Specs (active specs only; `specs/implemented/` untouched)

### 5.1 Rename the five active spec files (git mv, chmod 666 preserved)

| Old | New |
|---|---|
| `specs/inbound_email_encryption_at_rest.md` | `specs/mailbox_encryption_at_rest.md` |
| `specs/inbound_email_outbound_send_protection.md` | `specs/mailbox_outbound_send_protection.md` |
| `specs/inbound_email_hardened_ingest_relay.md` | `specs/mailbox_hardened_ingest_relay.md` |
| `specs/inbound_email_security_levels.md` | `specs/mailbox_security_levels.md` |
| `specs/inbound_email_group_collaboration.md` | `specs/mailbox_group_collaboration.md` |

### 5.2 Update references inside all active specs

Every active spec that mentions the plugin, the old spec filenames, old paths, old
setting keys, or the old API namespace (verified list):
`test_infrastructure_unification.md`, `external_scheduling_integrations.md`,
`sms_messaging.md`, `mobile_native_email.md`, `passkeys_core.md`,
`plugin_ajax_namespace_collision.md`, `cold_email_system.md`, `git_hosting.md`,
`joinery_ai_email_security_scan.md`, `declarative_admin_tabs.md`,
`file_blob_layer.md`, `drive_core.md`, `system_features.md`,
`joinery_ai_email_triage.md`, plus the five renamed files themselves and this spec's
own inventory lists (leave this file's Old/New tables intact — they are the
instructions).

References **to** `specs/implemented/inbound_email_*.md` files (e.g. in
`mailbox_group_collaboration.md`) keep their old paths — those files are not
renamed, so links to them must not change.

## Phase 6 — Native apps

Files (verified list — apply rules 3.1#4 for action names, 3.1#1 for repo paths in
comments; fixtures contain action names and doc URLs):

iOS (`/var/www/html/joinerytest/ios/`):
- `joinery-kit/Sources/JoineryMailKit/MailAPI.swift` (the five `submitAction` names)
- `joinery-kit/Sources/JoineryMailKit/MailModels.swift` (doc comments)
- `joinery-kit/Sources/JoineryMailKit/ThreadDetailView.swift`
- `joinery-kit/Sources/JoineryMailKit/ComposeSheet.swift` (comments reference
  `specs/implemented/...` — those paths stay)
- `joinery-kit/Tests/JoineryMailKitTests/MailParsingTests.swift`
- `joinery-kit/Tests/JoineryMailKitTests/Fixtures/{thread,thread_list,mailboxes}.json`
- `joinery-kit/Tests/JoineryKitTests/Fixtures/navigation.json`
- `joinery-member-ios/UITests/PasswordResetUITests.swift`

Android (`/var/www/html/joinerytest/android/`):
- `joinery-android-mail/src/main/java/com/getjoinery/mail/MailApi.kt`
- `joinery-android-mail/src/main/java/com/getjoinery/mail/MailModels.kt`
- `joinery-android-mail/src/test/java/com/getjoinery/mail/MailParsingTest.kt`
- `joinery-android-mail/src/test/resources/fixtures/{thread,thread_list,mailboxes}.json`

The `navigation.json` fixture may carry the profile-menu slug
(`inbound-email-mailbox`) and URL — update to `mailbox` / `/profile/mailbox/mailbox`.
`nativeScreen: "mailbox"` is already correct and must not change.

**Native verification:** iOS source lives in the main repo (`{repo root}/ios/`);
builds run on the Mac mini (`ssh macmini`; the mini's `~/dev/joinery-ios` is a
disposable rsync'd build area — never edit it directly). Run the two unit-test
suites (`swift test` for JoineryMailKit; `./gradlew :joinery-android-mail:test`
with `source ~/.android-env`). Do not run emulator/Gradle/Simulator alongside
Ollama on the mini; shut build tooling down when done.

## Phase 7 — Database and server operations (dev box)

Order matters. **Steps 2 and 3 are database writes / sudo operations — get explicit
user confirmation before each.**

1. Reload PHP so nothing caches the old plugin list mid-change
   (user1 has no passwordless sudo — ask the user to run):
   `sudo systemctl reload php8.*-fpm` (the dev box runs mpm_event + php-fpm).
2. Run `update_database` (from the admin utilities page, or
   `php utils/update_database.php` if it supports CLI — check the file header
   first). This executes the Phase 1 migration and then the plugin sync, which
   re-seeds the renamed setting keys and menu entries. Confirm the migration's
   echo lines report the expected row counts (1 plugin row, 13 migration rows,
   21 setting keys, 2 storage-key rows per the dev counts at spec time).
3. Re-render the Postfix pipe config — `master.cf` still points at
   `plugins/inbound_email/utils/inbound_email_handler.php`, so **live inbound mail
   is broken from the moment of the directory rename until this step completes**.
   Minimize the window: do it immediately after step 2. Ask the user to run:
   `sudo bash plugins/mailbox/provisioning/install_email.sh` (it asserts the full
   postfix/opendkim config and reloads services). If the installer prompts for
   anything unexpected, the narrower manual fix is editing the `argv=` line in
   `/etc/postfix/master.cf` to the new path and `sudo systemctl reload postfix`.
4. Verify Postfix is healthy: `systemctl status postfix` and send the end-to-end
   test in Phase 8.

**Other deployments:** any managed node running the colocated mail stack needs step
3 after it receives this upgrade (Server Manager node Updates tab → then re-run the
mail provisioner from the node's provisioning checks). The migration itself rides
the normal upgrade (`utils/upgrade.php` runs `update_database`). If any node uses
the Mailgun provider with routes pointed at `/ajax/inbound_email_webhook`, nothing
changes (the webhook URL is stable by design).

## Phase 8 — Verification (all must pass)

### 8.1 Static checks

```bash
# Syntax on every changed PHP file:
git diff --name-only main | grep '\.php$' | while read f; do php -l "$f" || echo "FAIL: $f"; done

# Method/function existence on every changed PHP file (catches missed call-site renames):
git diff --name-only main | grep '\.php$' | while read f; do
  php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php "$f"
done
```

Investigate every flag before proceeding.

### 8.2 Forbidden-pattern greps (each must return NOTHING)

```bash
cd /var/www/html/joinerytest/public_html
G='grep -rn --exclude-dir=.git --exclude-dir=implemented'
$G "plugins/inbound_email" . ../ios ../android ../../maintenance_scripts 2>/dev/null | grep -v "specs/implemented"
$G "/profile/inbound_email/" . ../ios ../android 2>/dev/null | grep -v "specs/implemented"
$G "admin_inbound_email" . ../ios ../android 2>/dev/null | grep -v "specs/implemented"
$G -E "'inbound_email_(enabled|from_show_via|provider|srs_|mail_hostname|public_ip|forwarding_|log_retention|mailbox_|spam_filtering|content_spam|rspamd)" . 2>/dev/null | grep -v "specs/implemented"
$G -E "inbound_email/(mailboxes|thread|send)" . ../ios ../android 2>/dev/null | grep -v "specs/implemented" | grep -v "storage/inbound_email"
test -d plugins/inbound_email && echo "FAIL: old dir still exists"
```

Also, this spec file itself is excluded from the greps' expectations — its tables
intentionally contain the old strings. Add `| grep -v "plugin_rename_inbound_email"`
to each if needed.

### 8.3 Expected remaining occurrences (do NOT "fix" these)

`grep -rn "inbound_email" public_html --exclude-dir=.git` will still match:
- `specs/implemented/**` (historical, immutable)
- table/column identifiers: `iem_inbound_email_messages`,
  `ied_inbound_email_domains`, `iea_inbound_email_aliases`,
  `ieg_inbound_email_mailbox_grants`, `iem_inbound_email_message_id`, etc.
- kept basenames: `utils/inbound_email_handler.php`, `ajax/inbound_email_webhook.php`,
  `data/inbound_email_*_class.php`, test files
- kept class-name mentions in comments (`InboundEmailRouter` etc.)
- plugin migration IDs in `plugins/mailbox/migrations/migrations.php`
  (`iem_001_...` — historical IDs, immutable)
- this spec file and the Phase 1 migration file

Anything outside those categories is a miss — fix it.

### 8.4 Database state (READ queries)

```sql
SELECT plg_name, plg_status FROM plg_plugins WHERE plg_name IN ('inbound_email','mailbox');
-- expect exactly one row: mailbox | active
SELECT count(*) FROM plm_plugin_migrations WHERE plm_plugin_name='inbound_email';  -- 0
SELECT count(*) FROM plm_plugin_migrations WHERE plm_plugin_name='mailbox';        -- 13
SELECT count(*) FROM stg_settings WHERE stg_name LIKE 'inbound\_email\_%';         -- 0
SELECT count(*) FROM stg_settings WHERE stg_name LIKE 'mailbox\_%';                -- >= 21
SELECT count(*) FROM iem_inbound_email_messages
 WHERE iem_raw_storage_driver='local' AND iem_raw_storage_key LIKE 'inbound\_email/%';  -- 0
```

Plus: no duplicate menu rows —
`SELECT amu_slug, count(*) FROM amu_admin_menus GROUP BY 1 HAVING count(*) > 1;`
returns nothing, and the two renamed slugs exist exactly once.

### 8.5 Browser checks (Playwright MCP, as the admin)

1. `/plugins/mailbox/admin/admin_mailbox_reader` — reader loads, threads list,
   opening a thread works (exercises the JS + logic + tab menu).
2. `/plugins/mailbox/admin/admin_mailbox_setup` — setup page renders with settings
   populated (proves the setting-key migration carried values over: the mail
   hostname and provider fields must NOT be empty on dev).
3. `/profile/mailbox/mailbox` — profile webmail loads for a user with a grant.
4. Admin sidebar shows "Mailbox" under Emails; the profile dropdown still shows
   "Email".
5. `/admin/plugins` (or the plugins admin page) — plugin listed as "Mailbox",
   active, version 1.28.0, no sync errors.

### 8.6 End-to-end inbound mail (dev)

Send a message to `test@dev.getjoinery.com` (from any external account), then:

```sql
SELECT iem_inbound_email_message_id, iem_recipient, iem_subject, iem_received_time
FROM iem_inbound_email_messages ORDER BY iem_received_time DESC LIMIT 1;
```

The new message must appear (proves the Postfix pipe → handler → router path
survived the rename). Then open it in the reader and send a reply (proves
`mailbox/send` and MailboxSender).

### 8.7 API smoke

`POST /api/v1/action/mailbox/mailboxes` with a browser session (or from the reader
JS) returns the grant list; `POST /api/v1/action/inbound_email/mailboxes` now
returns the standard unknown-action 404.

## Rollback

Pre-launch, so simple: `git checkout main` restores the tree. The migration is
reversed by running its SQL with names swapped (plg/plm/plv/sct rows, setting keys
including re-expanding the two collapsed keys, slugs, storage keys) and moving
`storage/mailbox` back — write that reverse SQL only if rollback is actually
needed. Re-run `install_email.sh` afterward to restore the Postfix pipe path.

## Explicitly Out of Scope

- Renaming PHP classes, database tables, or column prefixes.
- Touching `specs/implemented/` in any way.
- The `/ajax/` → `/api/v1` endpoint migration (own spec:
  `plugin_ajax_namespace_collision.md`).
- Renaming the five legacy `ajax/mailbox_*.php` endpoints (they die in that
  migration anyway).
- Any git commit — file changes only; the user commits.
