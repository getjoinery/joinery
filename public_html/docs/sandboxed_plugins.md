# Sandboxed Plugins

> **Experimental, under active development.** Use sandboxed plugins for
> experiments only, never for anything a site depends on: the manifest, the
> call contract and what a plugin can reach may all change without a
> compatible path. They are switched off on every site until a superadmin
> turns on **Allow sandboxed plugins** (`sandboxed_plugins_enabled`, on the
> Settings page); see [The switch](#the-switch).

A sandboxed plugin reaches the platform only through the doors its manifest
declares: nothing in its folder is ever included by the site's PHP, and it
can do nothing its manifest does not say. Native plugins (PHP, `plugin.json`)
are described in the [Plugin Developer Guide](plugin_developer_guide.md); this
page is the other kind. The design and its decisions are in the spec
`plugin_tiers.md`.

**Nothing runs a sandboxed plugin's code.** A plugin can be installed,
reviewed, approved and activated, and everything the platform does from its
manifest works: its tables and their API, its settings, its menus and pages,
its key, its signal receiver and its scheduled runs. Each call to its code is
answered "No runner for this plugin." and logged as unavailable; see
[Calls](#calls).

## What one is

A folder in `plugins/` holding exactly two entries and nothing else:

```
plugins/notifier/
  manifest.json      what the plugin is and may do
  src/               the plugin's code
```

`manifest.json` is a regular file and `src/` a real directory; neither may be
a link. The platform reads nothing from `src/`. The kind is always read from
the folder's contents (`SandboxPlugins::isSandboxedDir()`), never from what a
package or a row says about itself: a third entry, or a `plugin.json` in place
of the manifest, makes it a native plugin, judged as one. Native
plugin loading skips a sandboxed folder (`PluginHelper::getAvailablePlugins()`),
so nothing in it is ever included.

A sandboxed plugin is a row in `plg_plugins` like any plugin and moves through
the same lifecycle on the Plugins page (install, activate, deactivate,
uninstall). `PluginManager` hands the steps that would read a native plugin's
PHP or `plugin.json` to `SandboxPluginManager`.

## The switch

The setting `sandboxed_plugins_enabled` is off by default
(`SandboxPlugins::enabled()`). While it is off:

- an uploaded sandboxed zip is refused, by the Plugins page and by the root
  installer, and the upload box does not mention the kind;
- a sandboxed folder already in `plugins/` cannot be installed, approved or
  activated; each refusal says the kind is switched off and where to allow it;
- `utils/sandbox_plugin.php load` is refused the same way.

A plugin that is already active keeps running, and can be deactivated or
uninstalled; turning the setting off stops new use, it does not stop a
running plugin.

## The manifest

`SandboxManifest` parses and validates it. Every name that becomes a class,
table, column, URL or setting is held to a strict pattern there, so nothing a
manifest says can be code.

```json
{
  "name": "notifier",
  "title": "Notifier",
  "version": "1.0.0",
  "description": "Posts chosen signals to a chat webhook.",
  "author": "Example Ltd",
  "platform": 1,
  "permissions": {
    "actions": ["notification_unread_count"],
    "records": {"EventRegistrant": ["read"]}
  },
  "signals": ["event.registered"],
  "schedules": [{"name": "digest", "frequency": "daily", "time": "08:00"}],
  "outbound_hosts": ["hooks.slack.com"],
  "settings": [
    {"name": "webhook_url", "label": "Webhook URL", "type": "url", "secret": true}
  ],
  "models": [
    {"name": "SentLog", "label": "Sent messages", "fields": [
      {"name": "signal", "type": "string", "max_length": 100, "index": true},
      {"name": "message", "type": "text", "required": true}
    ]}
  ],
  "admin_pages": [{"slug": "log", "title": "Sent messages", "sections": [
    {"type": "table", "model": "SentLog", "columns": ["create_time", "signal", "message"], "order": "-create_time"}
  ]}],
  "menu": {"title": "Notifier"},
  "limits": {"memory_mb": 16, "timeout_ms": 5000}
}
```

| Key | Required | Rule |
|---|---|---|
| `name` | yes | The folder's name. 2-20 characters of `a-z` and `0-9`, starting with a letter, words joined by a single `_` (no `__`, none at the end); not one of `PluginManager::RESERVED_NAMES` (`core` and the system's URL segments) |
| `title` | yes | At most 80 characters |
| `version` | yes | `x.y.z` |
| `platform` | yes | The platform API version the plugin was built for (`SandboxManifest::PLATFORM_VERSION` is this site's) |
| `description`, `author` | no | Text, at most 1000 and 120 characters |
| `permissions` | no | `actions`: action names it may call. `records`: model class → verbs (`read`, `create`, `update`, `delete`) it may use on models outside the plugin |
| `signals` | no | Signal names delivered to it |
| `schedules` | no | `name`, `frequency` (`hourly`, `daily`, `weekly`), `time` (`HH:MM`, daily and weekly), `day_of_week` (0-6, weekly) |
| `outbound_hosts` | no | Hostnames it may call over HTTPS: no scheme, port, address or wildcard |
| `settings` | no | `name`, `label`, `type` (`text`, `textarea`, `number`, `boolean`, `select`, `url`, `email`), `secret` (text and url only, no default), `default`, `required`, `options` (select), `help` |
| `models` | no | Up to 20; see [Data](#data) |
| `admin_pages`, `profile_pages` | no | `slug`, `title`, `sections` (`text`, `table`, `form`, `settings`); a profile page holds no table or settings |
| `menu` | no | `title`: one admin menu entry holding a link to each admin page |
| `limits` | no | `memory_mb` (1-64, default 16) and `timeout_ms` (100-10000, default 5000) for each call |

Any key not listed is refused, so a misspelling is an error rather than a
silently ignored declaration.

## Data

Each model a manifest declares is a real table, made and migrated by the same
schema builder native plugins use, and a record endpoint on `/api/v1` like
any model. Its names are built from the plugin's and the model's names in a
way that cannot collide: a plugin name has no `__` and no trailing `_`, a
model name has no `_`, so `__` (and, in a class name, the last `_`) splits
them exactly one way; and every class starts `Sbx_` and every table and
setting `sbx_`, which no core or native name does (native plugins may not be
named `sbx…`). PHP compares class names without case, and these hold without
case too, so a sandboxed model can never be, or shadow, another class.

| | Plugin `notifier`, model `SentLog` |
|---|---|
| Class | `Sbx_notifier_SentLog` |
| Table | `sbx_notifier__sent_log` |
| Columns | `notifier__sent_log_{field}`, plus `notifier__sent_log_id`, `_create_time`, `_update_time`, `_delete_time` |
| Endpoints | `GET/POST /api/v1/Sbx_notifier_SentLog`, `GET /api/v1/Sbx_notifier_SentLogs`, `PUT/DELETE /api/v1/Sbx_notifier_SentLog/{id}` |
| Setting | `sbx_notifier__{name}` |

The validator refuses a plugin and model whose names together would make a
database name (the table's id sequence is the longest) past 63 characters,
which Postgres would otherwise cut short silently. Two models in one manifest
may not differ only by case.

Field types and the column each becomes:

| Type | Column |
|---|---|
| `string` | `varchar(max_length)`, default 255, at most 2000 |
| `text` | `text` |
| `integer` | `int8` |
| `decimal` | `numeric(14,4)` |
| `boolean` | `bool` |
| `datetime` | `timestamp(6)` |
| `date` | `date` |
| `json` | `jsonb` |

`required` is enforced when a record is saved; the column itself stays
nullable, so a field made required in a later version cannot fail on rows
already stored. `index` adds an index. A field may not be named `id`,
`create_time`, `update_time` or `delete_time` (the platform adds those), nor
end in `_id`: a sandboxed model holds no foreign keys, and a name ending in
`_id` would read as one.

**Who may use them.** A model is readable and writable through the API, with
SystemBase's default per-record rule: a table with no owner column is staff
only. A member's request for a list of one is refused outright, since it
could never return them a row and its count would still answer for rows they
cannot see. The plugin reaches its own models through its credential, which
carries every verb on them.

**Filters.** A list request filters on any declared field, with or without
its prefix. A filter on a field the model does not declare, a value that is
not the field's kind (a word for an integer, `maybe` for a boolean, a
non-date for a date), or any filter on a JSON field is refused with a 400,
never ignored or turned into something else.

**Lifecycle.** Install and activation make the tables and bring them up to
the manifest as it stands; a column a newer manifest stops declaring is kept,
as for a native plugin. The tables made are recorded on the plugin's row
(`plg_metadata._sandbox_tables`). Deactivation keeps the data. Uninstall drops
every table the manifest names or the row recorded, with each one's id
sequence (made without `OWNED BY`, so `CASCADE` would leave it), and the
deletion rules that named them.

**How the classes exist.** No file of a sandboxed plugin is included, so
`SandboxModels` declares each model's class and its `Multi` collection at run
time from one fixed template, with only the class name inserted, after it has
been held to `SandboxModels::CLASS_PATTERN` twice. This is the one `eval` in
the codebase; `tests/unit/sandbox_models_test.php` fails if another appears,
or if any class or table in the tree starts with the sandboxed prefix. An active plugin's models resolve by name through `ClassAutoloader`
like any class; one plugin's resolve through `SandboxModels::classesFor()`
(its install and uninstall), and model discovery over many plugins (schema
and deletion work) takes those with a registry row, so a folder merely
sitting in `plugins/` declares nothing. The collection
refuses a filter that names no field of the model, rather than ignoring it.

## Installing

A sandboxed plugin is uploaded as a zip on the Plugins page, exactly as a
native one is. The zip holds `manifest.json` and `src/`, at its top or in one
folder. `PluginManager` and the root installer (`utils/install_extension.php`)
read the kind from the contents (`SandboxPlugins::isSandboxedDir()`):

- the manifest is validated on root's own copy before anything moves, and its
  `name` must be the folder's;
- it is handled as code. A package our release key did not sign is refused on
  a host that refuses unsigned packages (`HostRestrictions`), and elsewhere
  installs only on the owner's acknowledgement of the unsigned warning, with
  every superadmin emailed. Its row records `plg_trust = 'sandboxed'` and the
  event log `sandboxed_package_installed`, naming who approved it;
- it is listed as code that is not ours: on the Updates page under
  **Installed with Install anyway**, and in the vault health row for unsigned
  plugins and themes (`VaultHealth::checkUnsignedExtensions()`);
- it carries no fork mark: no release ships a sandboxed plugin, so a deploy
  always keeps one (`DeploymentHelper::preserveReason()`);
- the code check counts an unsigned one as unsigned code
  (`PackageSignature::verifyTree()`, `utils/verify_package.php --tree`).

Uploading a new version of an installed plugin replaces it like any package.
When the plugin is active, everything it owns follows the new manifest at
once, within what was approved (below).

## Approval

A sandboxed plugin can do nothing until a superadmin approves what it asks
for. Until then the Plugins page offers **Review and activate** in place of
Activate, and `PluginManager::activate()` refuses it
(`SandboxApprovalNeededException`). The review page
(`/admin/admin_sandbox_approve?plugin=<name>`) lists, in plain words
(`SandboxConsent::describe()`):

| Group | From the manifest |
|---|---|
| Records it may use, one group per feature that owns the model | `permissions.records` |
| Run these site actions | `permissions.actions`, with each action's description |
| Be told when these happen | `signals`, with each signal's label |
| Run on a schedule | `schedules` |
| Send data to these websites | `outbound_hosts` |
| Add these pages to members' menus | `profile_pages`, saying which have a form members fill in |

and, as its own, its models, its admin pages and its settings. A record grant
reaches every record of the model ("All users: read only"), since the
plugin's credential acts as an admin; each signal says what personal data it
carries. **Approve and activate** records the approval
(`SandboxConsent::approve()`, kept on the plugin's `SandboxInstall` row with
who approved it and when) and activates the plugin, in one transaction. The
button carries a digest of what the page showed (`reviewDigest()`): a plugin
replaced between showing and approving is refused, to be reviewed again.

What a plugin may do is what its manifest asks for **and** the approval covers
(`SandboxConsent::effective()`). A newer version asking for more keeps what
was approved and nothing more (a member page it adds is not shown); the
Plugins page marks it **Waiting for approval**, and the review page lists what
is new. An approval stands while the admin who gave it is a superadmin, not
disabled and not deleted: demoting, disabling or deleting them switches the
plugin's key off at once and ends the approval
(`SandboxCredentials::lapseApprovalsOf()`, from `User::save()` and
`User::soft_delete()`; a permanent delete takes the key with the account). The
plugin then waits on an admin's approval, which mints a key as that admin.
Restoring the admin does not bring the ended approval back; they may approve
it again like anyone else.
Another superadmin may approve an approved plugin as themselves (**Approve as
me**), which moves its credential to them.

### Compatibility

The API actions, the signal catalog and the manifest's page and field types
are a promise to plugin authors: additions are always allowed, and a change
that breaks an existing one raises `SandboxManifest::PLATFORM_VERSION`.
Install and activation refuse a plugin built for a later platform, or one
that needs an action (or a machine-callable one), a model, a record verb the
API allows, or a signal this site does not have, naming each
(`SandboxCompatibility::problems()`).

They also refuse a sandboxed plugin that has a native plugin's name: one in
the publisher's catalog (as far as the site knows it, from its day-old copy)
or a `plugin.json` folder on disk. Core checks a native plugin by name
(`PluginHelper::isPluginActive()`) meaning its PHP is there, and a sandboxed
plugin never answers that question; the few questions about a plugin of
either kind (its declared settings, its API models, its secrets) ask
`PluginHelper::isActiveOfEitherKind()`.

## What an active plugin owns

`SandboxPluginManager::reconcile()` brings all of these up to the manifest,
within the approval, on activation, on each approval, and when an active
plugin's files are replaced.

**Its credential (door 1).** A machine API key (`SandboxCredentials`) scoped
to exactly the approved actions and record verbs plus every verb on its own
models, owned by the approving admin and acting as them, usable from this
machine only (`127.0.0.1`, `::1`). Its secret is sealed on the
`SandboxInstall` row (`sbi_sandbox_installs.sbi_api_secret`, a regenerable
sealed secret whose recipe mints a new key) and put in the envelope of every
call. Each approval mints a new key and deletes the old. Deactivation
switches it off; uninstall deletes it. The key pages show it as the plugin's
and refuse to delete it or reset its secret.

**Its signals (door 2).** One `SignalReceiver` of kind `sandbox` naming the
plugin (`sgr_plugin`) carries the approved signals. Signals queue and retry on
the same delivery queue as webhooks (`SignalDeliveryQueue`), and the drain
makes each an `on_signal` call. A call that comes back unavailable, busy or
timed out is retried on the queue's backoff, and the plugin is not tried again
in the same pass.

**Its schedules (door 3).** One `SandboxScheduledRun` row per approved
schedule, tagged with the plugin's name (so it is suspended and resumed with
the plugin and removed at uninstall), at the manifest's frequency and time in
the site's time zone, configured `{plugin, schedule}`. Each run is an
`on_schedule` call; one that does not come back `ok` is the run's error. The
task is never
offered for activation on the Scheduled Tasks page (`"activated_by_hand":
false` in its `.json`).

**Its settings and pages (door 4)**, below.

**Its Unix user.** Every sandboxed plugin folder on the machine has a user of
its own, `jpx-NAME`, kept by the parser jail's installer
(`install_parser_jail.sh`) at each root moment: no shell, no home, no file
anywhere, primary group `joinery-jpx`, a uid above a high-water mark in
`/var/lib/joinery-jail/jpx_uid_mark` so no uid is ever handed out twice, and
removed a week after no site on the machine has the plugin's folder. At most
20 are made per run and 200 exist at once. The launcher runs a plugin
as that user (`joinery-jail --user=jpx-NAME`) inside the allowlist fence
described in `docs/document_text.md` § The parser jail, with no scratch folder.

## Pages and settings

The plugin's pages are drawn by the platform from its manifest; nothing the
plugin ships is rendered. Admin pages are at
`/admin/admin_sandbox_page?plugin=<name>&page=<slug>` (permission 8), linked
from one admin menu entry titled by `menu.title`. Profile pages are at
`/profile/sandbox_page?plugin=<name>&page=<slug>`, each an entry in the
signed-in member's menu (slug `sbx-<plugin>-p-<page>`, apart from the admin
entries' `sbx-<plugin>-<page>`). A member page's logic is
`logic/sandbox_page_logic.php`, which is also the API action `sandbox_page`:
an app submits a form with `{plugin, page, section, values: {field: value}}`
and gets the plugin's answer back as `result` `{ok, status, lines,
errors}`. Sections:

| Section | Shows |
|---|---|
| `text` | Its words |
| `table` | Rows of one of the plugin's own models: `columns`, `order` (a field, `-` for descending), `limit` |
| `form` | Its `fields`; a submission is the plugin's `handler` to answer |
| `settings` | The plugin's settings, saved by `SettingsWriter` |

**Settings.** Each manifest setting is a declaration like any other, named
`sbx_<plugin>__<name>` (`SandboxSettings`), grouped under the plugin's title,
seeded on activation and removed at uninstall. Only an installed plugin's
manifest declares settings; a folder alone declares nothing. `url` and `email` settings are
validated as such, `number` as a number, and a `secret` one is a credential
field. They go in each call's envelope by their manifest names, secrets opened.

**Forms.** A form posts its fields as `sbx_<field>`; only the declared fields
are sent, as an `on_ui` call. Its answer is `{message, errors: {field:
message}, status}`; the page shows the message, or the errors, as text. A UI
call is given at most `SandboxHost::UI_TIMEOUT_MS` (5 s, or less if the
manifest's limit is lower); at most `SandboxHost::UI_SLOTS` (2) such calls
run at once per site, and one per person (locks in
`{site root}/cache/sandbox/`). Past any of those the page says the plugin is
busy or did not respond; a call that could not be run shows why.

## Calls

Core never includes a sandboxed plugin's code. `SandboxHost` builds one
envelope per call, carrying everything the call may use, hands it to the
runner, and records the answer:

```json
{
  "call_id": "c_…", "plugin": "notifier",
  "export": "on_ui | on_signal | on_schedule",
  "input": {},
  "limits": {"memory_mb": 16, "timeout_ms": 5000},
  "settings": {"webhook_url": "…"},
  "outbound_hosts": ["hooks.slack.com"],
  "api": {"base_url": "https://127.0.0.1:443", "hostname": "site.example", "public_key": "…", "secret_key": "…"}
}
```

`outbound_hosts` are the approved ones; `api` is null when the plugin has no
credential. `input` per export: `on_ui` `{handler, values, user: {id,
permission}}`; `on_signal` `{signal, message_id, timestamp, data}`;
`on_schedule` `{schedule, time}`.

**The runner.** `SandboxHost::$runner` is a callable that takes the envelope
and returns `{ok, output, error, timed_out, duration_ms, calls: [{door,
target, status, detail}]}`. The platform sets none. With none, `call()`
returns status `unavailable` with the message "No runner for this plugin."
(`SandboxHost::NO_RUNNER_MESSAGE`) and writes the call's log row; it never
does anything else in the plugin's name. A runner that throws or returns
something that is not an answer is an `error`. Tests set a stand-in
(`sandbox_stand_in_runner()` in `tests/lib/sandbox.php`).

**The author's command.** `utils/sandbox_plugin.php`: `validate` (the
folder's shape, the manifest, and this site's compatibility, from a folder, a
manifest or a zip, on any site), and on a dev site (`debug` on) `load` (copy a
folder into `plugins/`, install or update it, approve it as a superadmin,
activate it), `call` (one `ui`, `signal` or `schedule` call, printing the
answer and each door's use) and `log`.

**The call log.** Every call is a `SandboxLog` row: the plugin, the kind (`ui`,
`signal`, `schedule`, `test`), the handler, signal or schedule, the outcome
(`ok`, `error`, `timeout`, `unavailable`, `busy`), its message, how long it
took, who asked (for a UI call) and what the plugin reached (`calls`, at
most 200, each field cut to a set length). Rows are kept
`sandbox_log_retention_days` days (default 14). An admin reads them on the
plugin's call log page (`/admin/admin_sandbox_log?plugin=<name>`, permission
8, from the Plugins page), filtered by kind or outcome.
