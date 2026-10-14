# Sandboxed Plugins

A sandboxed plugin is a WebAssembly module that reaches the platform only
through the doors its manifest declares. It ships no PHP, runs outside the
PHP pool, and can do nothing its manifest does not say. Native plugins (PHP,
`plugin.json`) are described in the [Plugin Developer Guide](plugin_developer_guide.md);
this page is the other kind. The design and its decisions are in
`specs/plugin_tiers.md`.

## What one is

A folder in `plugins/` holding exactly two files and nothing else:

```
plugins/notifier/
  manifest.json      what the plugin is and may do
  notifier.wasm      the module (any one *.wasm name)
```

The kind is always read from the folder's contents (`SandboxPlugins::isSandboxedDir()`),
never from what a package or a row says about itself: one more file, a
folder, or a `plugin.json` makes it a native plugin, judged as one. Native
plugin loading skips a sandboxed folder (`PluginHelper::getAvailablePlugins()`),
so nothing in it is ever included.

A sandboxed plugin is a row in `plg_plugins` like any plugin and moves through
the same lifecycle on the Plugins page (install, activate, deactivate,
uninstall). `PluginManager` hands the steps that would read a native plugin's
PHP or `plugin.json` to `SandboxPluginManager`.

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
| `platform` | yes | The platform API version the module was built for (`SandboxManifest::PLATFORM_VERSION` is this site's) |
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

**How the classes exist.** A sandboxed plugin ships no PHP, so
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
native one is. The zip holds the two files, at its top or in one folder.
`PluginManager` and the root installer (`utils/install_extension.php`) read the
kind from the contents (`SandboxPlugins::isSandboxedDir()`):

- the manifest is validated on root's own copy before anything moves, and its
  `name` must be the folder's;
- it installs with no warning and on a host that refuses unsigned packages,
  since nothing in it runs in the site's PHP; the row records
  `plg_trust = 'sandboxed'` and the event log `sandboxed_package_installed`;
- it carries no fork mark: no release ships a sandboxed plugin, so a deploy
  always keeps one (`DeploymentHelper::preserveReason()`), and the code check
  passes over it (`PackageSignature::verifyTree()`, reported by
  `utils/verify_package.php --tree` as `sandboxed plugin: <name>`).

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
disabled and not deleted: demoting or disabling them switches the plugin's key
off at once (`SandboxCredentials::lapseApprovalsOf()`, from `User::save()`),
and it waits on another admin's approval, which mints a key as them. Their
account cannot be deleted while the key is live.
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
sealed secret whose recipe mints a new key) and handed to the host with every
call. Each approval mints a new key and deletes the old. Deactivation
switches it off; uninstall deletes it. The key pages show it as the plugin's
and refuse to delete it or reset its secret, and the approving admin's
account cannot be deleted while it is live.

**Its signals (door 2).** One `SignalReceiver` of kind `sandbox` naming the
plugin (`sgr_plugin`) carries the approved signals. Signals queue and retry on
the same delivery queue as webhooks (`SignalDeliveryQueue`), and the drain
hands each to the host as an `on_signal` call.

**Its schedules (door 3).** One `SandboxScheduledRun` row per approved
schedule, tagged with the plugin's name (so it is suspended and resumed with
the plugin and removed at uninstall), at the manifest's frequency and time in
the site's time zone, configured `{plugin, schedule}`. The task is never
offered for activation on the Scheduled Tasks page (`"activated_by_hand":
false` in its `.json`).

**Its settings and pages (door 4)**, below.

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
field. The module is handed them by their manifest names.

**Forms.** A form posts its fields as `sbx_<field>`; only the declared fields
are sent. The module's `on_ui` answers `{message, errors: {field: message},
status}`; the page shows the message, or the errors, as text. A page waits
on a module at most `SandboxHost::UI_TIMEOUT_MS` (5 s, or less if the
manifest's limit is lower); at most `SandboxHost::UI_SLOTS` (2) such calls
run at once per site, and one per person. Past any of those, or with no
host, the page says the plugin did not respond.

## The plugin host

Core never runs a module. It sends the plugin host, a separate process on the
site, one request per call over a Unix socket
(`{site root}/run/sandbox-host/host.sock`) and waits for the answer, bounded
(`SandboxHost`). Each request carries everything the call may use, so the host
keeps no configuration and reads no database:

```json
{
  "call_id": "c_…", "plugin": "notifier", "module": "/…/plugins/notifier/notifier.wasm",
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
`on_schedule` `{schedule, time}`. The host answers `{ok, output, error,
timed_out, duration_ms, calls: [{door, target, status, detail}]}`. Core reads
at most `SandboxHost::MAX_ANSWER_BYTES` (1 MB) of an answer; a larger one
fails the call.

**The program.** The host is `sandbox-host`
(`maintenance_scripts/install_tools/sandbox_host`, Go on wazero through
Extism's Go library), shipped prebuilt for x86_64 and aarch64 in `bin/`
(`SandboxHostPublisher` builds them at publish, like the parser jail's
launcher). It keeps no configuration, no queue and no clock: core's signal
drain and scheduler call it, and it reads no database. Each call gets a fresh
instance of the module, compiled once per module version and memory cap,
under the call's memory cap and deadline, in wazero's interpreter (never its
compiler, so the process runs with `MemoryDenyWriteExecute`). At most 4 calls
run at once (`-max-calls`); another is refused as busy. It refuses an
envelope whose module is outside the plugin's own folder, whose export is not
one of the three, whose outbound hosts are not host names, or whose API
address is not this machine's loopback. `GET /health` answers `{ok, version,
calls_running}`.

**Where it runs.** `install_sandbox_host.sh`, a core host installer, runs on
every converge. While the site has a sandboxed plugin on disk it keeps the
host running as the system user `joinery-sbx` (no shell, no home, no groups),
its socket in `{site root}/run/sandbox-host/` (owned by that user, group
`www-data`, mode 2750, the socket 0660, so the web server can call it and no
one else can): under a systemd unit `joinery-sandbox-host-{site}` where systemd
is PID 1 (no new privileges, no capabilities, a read-only system, the site's
`config/`, `uploads/`, `backups/`, `storage/`, `static_files/`, `logs/` and
`cache/` hidden, 512 MB), a cron keepalive otherwise (a site container). With
no sandboxed plugin left it stops the host and removes its supervision. A
sandboxed plugin's `manifest.json` is part of what the converger watches, so
one arriving or leaving converges at the next tick.

## Writing a module

A module is any WebAssembly module built with an Extism plugin development kit
(Rust, Go, JavaScript, C, Zig and others). It exports the calls it answers, by
name, each returning 0 for success (set an error and return 1 to fail):

| Export | Called for |
|---|---|
| `on_ui` | a form on one of its pages |
| `on_signal` | a signal it was approved for |
| `on_schedule` | one of its approved schedules |

Its input is one JSON object, `{"input": …, "settings": {name: value}}`, with
`input` as in the table above (The plugin host) and its settings by their
manifest names, secrets opened. Its output is JSON (or text, which is passed on
as a string); for `on_ui`, `{message, errors, status}`. For `on_signal` the
answer decides whether the site tries the signal again: an export that fails
(returns 1 with an error) or does not finish in time is retried on the
delivery queue's backoff, and one that answers is done with it. So a module
fails a signal it could not act on for a reason that may pass (a service it
posts to was down) and answers one it never can (an address that refused
it). WASI is available
without files, arguments or environment; Extism's own HTTP and file functions
are shut. The module reaches out only through three host functions in the
`extism:host/user` namespace, each taking and returning one JSON value in
Extism memory:

| Function | Request | Answer |
|---|---|---|
| `joinery_api` | `{method, path, body}`: GET, POST, PUT or DELETE on an `/api/v1/` path | `{status, body}`, or `{error}` |
| `joinery_http` | `{method, url, headers, body}`: `https://` on port 443 to an approved host | `{status, headers, body}`, or `{error}` |
| `joinery_log` | `{level, message}`: `debug`, `info`, `warn` or `error` | `{}` |

`joinery_api` calls the site as the plugin (its credential is added by the
host; the module never sees it). `joinery_http` goes only to a public address,
checked at the moment it is dialled, never follows a redirect, and drops
headers that steer the connection. Requests and answers are capped at 1 MB,
and every door honours the call's deadline. What a module asked of a door, and
whether it was done, refused or failed, is in the call log.

**The author kit** is `sandbox_sdk/` at the top of the repository: a Rust
crate (`rust/joinery-plugin`) and a JavaScript kit (`js/`) wrapping the calls
and the doors, a real plugin in each (`examples/notifier`, Rust;
`examples/greeter-js`), and a README on writing, building and packaging one.
`utils/sandbox_plugin.php` is the author's command: `validate` (the manifest,
the module against it, and this site's compatibility, from a folder, a
manifest or a zip, on any site), and on a dev site (`debug` on) `load` (copy a
folder into `plugins/`, install or update it, approve it as a superadmin,
activate it), `call` (one `ui`, `signal` or `schedule` call through the host,
printing the answer and each door's use) and `log`.

**The module is checked at install.** `SandboxModule` reads a module's export
and import sections from its bytes: a manifest whose pages have forms needs
`on_ui`, one asking for signals `on_signal`, one with schedules
`on_schedule`; a module importing anything but Extism's runtime functions,
the three doors and WASI is refused, naming the import; a module larger than
32 MB is refused (the host refuses one too). The root installer reads it the
same way on its own copy.

**The call log.** Every call is a `SandboxLog` row: the plugin, the kind (`ui`,
`signal`, `schedule`, `test`), the handler, signal or schedule, the outcome
(`ok`, `error`, `timeout`, `unavailable`, `busy`), its message, how long it
took, who asked (for a UI call) and what the module reached (`calls`, at
most 200, each field cut to a set length). Rows are kept
`sandbox_log_retention_days` days (default 14). An admin reads them on the
plugin's call log page (`/admin/admin_sandbox_log?plugin=<name>`, permission
8, from the Plugins page), filtered by kind or outcome.
