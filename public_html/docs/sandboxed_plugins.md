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

**Its code runs in a fence, one process per call.** `src/plugin.php` is PHP,
started by core under `joinery-jail` as the plugin's own Unix user, with no
network, no database, no config file and nothing writable; it reaches the
site only by asking core over its standard input and output, and core carries
each ask out with the plugin's approved reach (the spec
`sandboxed_php_plugins.md`). See [Calls](#calls). Where the fence is not
installed, or the plugin's user is not made yet, a call is unavailable and
says why.

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

## Writing one

Start from the sample, `docs/examples/notes_sample/`: a model of its own
(`Note`, with a class of its own in `src/data/Note.php` adding a method and a
rule), a plain admin page that lists and adds notes (`src/admin/notes.php`), a
member page using a setting and an own-rows grant (`src/views/mine.php`), a
framed board with its own script calling one handler (`src/views/board.php`),
and `src/plugin.php` answering the signal, the schedule and the board's
call. `tests/functional/sandbox/sandbox_sample_plugin_test.php` runs it.

```
plugins/notes_sample/
  manifest.json          what it is and may do (the manifest, below)
  src/plugin.php         on_ui(), on_signal(), on_schedule()
  src/admin/{slug}.php   an admin page it draws
  src/views/{slug}.php   a member page it draws (or a framed page's document)
  src/data/, src/includes/   its classes, found by name
  src/assets/            images its pages show (assets/NAME)
```

Write it the way a native plugin is written: models (`new Note()`,
`new MultiNote([...])`), `Globalvars::get_instance()->get_setting()` for its
own settings, `SessionControl::get_instance()` for who is viewing, FormWriter
or plain HTML for forms. What it cannot do (raw SQL, a session write, the
config file, the network beside an approved host) fails with a message that
says so; each part is under [Calls](#calls).

The author's command, `utils/sandbox_plugin.php`:

```
php utils/sandbox_plugin.php validate docs/examples/notes_sample      any site: shape, manifest, this site
php utils/sandbox_plugin.php load docs/examples/notes_sample          a dev site: install, approve, activate
php utils/sandbox_plugin.php call notes_sample page mine --as=USER_ID a page, and its body as core shows it
php utils/sandbox_plugin.php call notes_sample ui pin '{"id":1}' --from=frame --page=board --as=USER_ID
                                                                      a framed page's handler, as its script calls it
php utils/sandbox_plugin.php call notes_sample signal account.signup '{"user_id":1}'
php utils/sandbox_plugin.php call notes_sample schedule tidy
php utils/sandbox_plugin.php log notes_sample                         its last calls, with each ask
```

On a dev site the plugin's code runs in the fence once its user exists (a
minute after `load` puts the folder in `plugins/`); until then a call says
it is being set up.

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

**A shared host refuses them whatever the setting says.** Where the host's
restrictions file refuses unsigned packages (`HostRestrictions::has('unsigned_packages')`,
a multi-tenant host), `SandboxPlugins::enabled()` is false: nothing is
uploaded, installed, approved or activated, every call is unavailable
(`SandboxRunner`), and the refusals and the upload box say the server is
shared (`SandboxPlugins::offMessage()`). Every sandboxed plugin is unsigned.

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

**Each member's own records.** `permissions.own_records`, beside
`records`, asks for each member's own rows of a personal model:
`{"Order": ["read"]}`. The model must be one the API reads, not public
content, with an owner column (`SandboxCompatibility::ownRecordsProblem()`).
The key gets `Order:read:own`. When the plugin's code asks for such a model
during a page or form call, core's relay names the member that call is for
(header `X-Joinery-Sandbox-Viewer`, from core's envelope, never from the
plugin), and the API acts as that member at member level whatever their own
level: rows and lists are theirs alone (a list is narrowed in the query, so
its count is theirs too), and a row it creates is theirs. An admin viewing
the page is treated the same way. A signal or a schedule has no member
viewing, so an own-rows ask is refused there. The API accepts the header only
on a sandboxed plugin's key from this machine, and with it admits only the
plugin's own-rows entries. A plugin that also holds `records` on the model
reads all rows instead.

An admin page of a plugin that holds every row of a personal model is shown
only to a viewer who may read that model at its own level.

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
| Each member's own records | `permissions.own_records` |
| Read this program code | the native plugins whose models it reads (the fence reads their `includes/` and `data/`) |
| Run these site actions | `permissions.actions`, with each action's description |
| Be told when these happen | `signals`, with each signal's label |
| Run on a schedule | `schedules` |
| Send data to these websites | `outbound_hosts` |
| Add these pages to members' menus | `profile_pages`, saying which have a form members fill in and which the plugin draws |
| Warning | personal records (all rows or own) beside outside websites: it could send those records there |

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

A page with `sections` in the manifest is drawn by the platform from them.
A page with none is drawn by the plugin's own view (a plain page; below).
Admin pages are at
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

**Plain pages, drawn by the plugin.** A page declared with only `slug` and
`title` is the plugin's own view: `src/admin/{slug}.php` for an admin page,
`src/views/{slug}.php` for a member's, run in the fence as an `on_page` call
(`SandboxHost::page()`, the UI call's bounds; logged as kind `page`) after
`src/plugin.php` when there is one. What the view echoes is the page's body.
`SandboxPage` gives it the request: `isPost()`, `field($name)`,
`query($name)`, `$input` (`{page, kind, method, query, fields, user}`), and
lets it set `title($text)` or ask for `redirect($page, $query)`, honoured only
to another of the plugin's own pages of the same kind.

Core cleans the body before showing it (`SandboxHtmlCleaner`, run in the
parser jail through `DocumentText::parseWith()`), with PHP's HTML5 parser
and an allowlist: text structure, tables, lists, links, images, forms and
their controls, `title`, `lang`, `dir`, each element's own attributes, and
`class` only from the kit's content classes (`SandboxHtmlCleaner::CLASSES`:
buttons, tables, form rows, alerts, badges, text). `autocomplete` keeps `on`
and `off` only. Everything else is dropped with its contents, script and style
among them, and no `id`, `style`, `on*`, `data-*`, `form`, `formaction`,
`srcset` or `name` outside a control survives. Links keep `http:`, `https:`,
`mailto:` and relative addresses. Every form posts back to the page itself,
by GET or POST, and the post reaches the view as `fields`; a GET form is
given the page's own `plugin` and `page` as hidden fields (a GET submit
replaces the action's query). No password or file input survives, nor a
control named `_csrf_token`, `plugin`, `page`, `action` or `section`, and
the page logic takes the plugin and page from the address only, never from
a posted body. An image is the plugin's own file,
`assets/NAME` (served by core from `src/assets/` at
`/sandbox_asset?plugin=&file=`, PNG, JPEG, GIF or WebP, to anyone (a
framed page's document loads them with no cookies), no link and no path out), or `https` on one of its approved hosts; any
other is dropped. The cleaner parses its own output again and refuses the
page unless nothing changes. The page carries a line naming the plugin that
drew it (`SandboxPages::provenance()`), and the body sits in a box that asks the browser to keep everything in it
(`SandboxPages::bodyOpen()`: `contain: layout paint`, `overflow: hidden`, so
even a fixed element stays inside), whatever class its markup carries; held
as far as each browser honours containment. The API action
`sandbox_page` refuses a page the plugin draws: an app would show the body
without the site's script policy.

A plain page is shown only where the strict script policy is enforced
(`enable_csp` on, `csp_report_only` off, `csp_strict_scripts` `enforce`):
elsewhere it says so and draws nothing. A member page drawn by the plugin is
named that way on the approval screen.

**Framed pages: its own script, in a sealed frame.** A page with
`"frame": true` (and no sections) is the plugin's whole document, drawn by
the same view, script and styles inline, served uncleaned in a frame. The
outer page (the admin or member page) holds an `<iframe sandbox="allow-scripts">`
of `/sandbox_frame?plugin=&kind=admin|member&page=` and core's bridge
`assets/js/sandbox-frame.js`, and its `frame-src` names that route alone
(`PublicPageBase::restrict_frames_to()`): every policy the page sends is
narrowed, and one more is sent, enforced whatever the site's own policy
settings say (`Content-Security-Policy: frame-src` the route), so the frame
can be navigated nowhere else. The route serves only a same-origin frame load
(`Sec-Fetch-Dest: iframe`, `Sec-Fetch-Site: same-origin`; opened directly it
answers nothing), to a viewer who may open the page
(`SandboxPages::framedPage()`), with `SandboxPages::frameHeaders()`:
`sandbox allow-scripts` (another site to the browser, with no cookies of this
one), `default-src 'none'`, inline script and style, images from `data:` and
`/sandbox_asset`, the kit stylesheet `assets/css/joinery-styles.css` as the one
file it may load, no connect, form, frame or worker source,
`frame-ancestors 'self'`, `webrtc 'block'`, no referrer, no cache. A
document may be 4 MB. Core writes a doctype and one script of its own ahead
of the document (`SandboxPages::framePrelude()`); it runs before any of the
plugin's and removes the WebRTC constructors (`RTCPeerConnection` and its
kin), which a browser that does not know the `webrtc` directive (Chromium)
would otherwise let reach any STUN or TURN host. The frame has no other window
to fetch them back from: it may not load a frame, open a popup or start a
worker, and the outer page's `frame-src` stops it navigating itself anywhere
but this route. The wall is held against the frame reaching this site (its
cookies, pages, storage, API, the outer page) and against it sending data
out, except DNS prefetch, which is not covered in every browser.

The frame's one way out is a message to the bridge, which acts only on
messages from that frame's window and answers it alone:
`{type: 'sbx-call', id, handler, data}` calls the plugin's own handler as the
viewer (the action `sandbox_frame_call`, browser session only, answered by
`on_ui` with `values` = `data` and `source` `frame`, `kind` and `page`), but
only a handler the framed page lists in the manifest's `handlers`; an admin
form's handler is never reachable from a member's frame and comes back as
`{type: 'sbx-answer', id, ok, output, message}`; `{type: 'sbx-height', height}`
sets the frame's height (100 to 20000 pixels). Nothing else is understood.
A framed member page is named so on the approval screen, and a framed admin
page is a grant of its own ("Run its own script in admins' browsers"), so a
page that becomes framed waits on approval. Assets at `/sandbox_asset` need
no sign-in: the frame loads them without the site's cookies.

## Calls

Core never includes a sandboxed plugin's code. `SandboxHost` builds one
envelope per call, carrying everything the call may use except a credential,
hands it to the runner, and records the answer:

```json
{
  "call_id": "c_…", "plugin": "notifier",
  "export": "on_ui | on_signal | on_schedule",
  "input": {},
  "limits": {"memory_mb": 16, "timeout_ms": 5000},
  "settings": {"webhook_url": "…"},
  "outbound_hosts": ["hooks.slack.com"]
}
```

`outbound_hosts` are the approved ones. The plugin's API key is never in it:
core's relay opens the key when it carries out an ask. `input` per export: `on_ui` `{handler, values, user: {id,
permission}}`; `on_signal` `{signal, message_id, timestamp, data}`;
`on_schedule` `{schedule, time}`.

**The runner.** `SandboxHost::$runner` is a callable that takes the envelope
and returns `{ok, output, error, timed_out, duration_ms, calls: [{door,
target, status, detail}]}`, or `{unavailable: "why"}`. Null (the platform)
means `SandboxRunner::run()`. A runner that throws or returns something that
is not an answer is an `error`. Tests set a stand-in
(`sandbox_stand_in_runner()` in `tests/lib/sandbox.php`).

`SandboxRunner` refuses (`unavailable`) on a shared host, where the launcher
with the allowlist is not installed, where `src/` is not a real folder, and
until the plugin's user `jpx-{plugin}` exists (the root step makes it within
a minute of a sandboxed folder arriving). Otherwise it starts

```
joinery-jail --user=jpx-NAME --watch-fd=3 --timeout=S --rlimit-as=B --read=<runner> --read=<src/>
    -- php -n -d extension=... -d memory_limit=NM utils/sandbox_php_runner.php <src/>
```

with the manifest's limits (address space: the memory limit plus 96 MB for
PHP itself) and a fixed environment, and talks to it one JSON object per
line. The first line it writes is the envelope. The runner script loads
`src/plugin.php` and calls the function the export names, `on_ui($input)`,
`on_signal($input)` or `on_schedule($input)`; what it returns is the call's
output, and what it echoes is dropped. Plugin code asks core for things with
`SandboxPipe::ask($ask, $args)`, which writes one line and waits for core's
answer (`{ok: true, ...}`; a refusal throws `SandboxPipeException` with the
reason). The call ends with `{"ask": "done", "ok": true, "output": ...}`.

**What the plugin's code finds.** Before `src/plugin.php` loads, the runner
boots core the way the document parser does (the real `PathHelper`,
`ClassAutoloader::restrictToCore()`: core classes from `includes/` and
`data/`, no settings, no database) and fills three singletons with
stand-ins (`utils/sandbox_runtime.php`, `utils/sandbox_runtime_standins*.php`):

- `Globalvars::get_instance()->get_setting()` answers the plugin's own
  settings by their full `sbx_{plugin}__{name}` names, and by their manifest
  names too unless core declares a setting of that name (a plugin's `debug`
  answers only as `sbx_{plugin}__debug`), and the site's `site_name`,
  `webDir` and `default_timezone`. Every
  other name is unset (null), a secret or not; `config_file_setting()` gives
  its default.
- `SessionControl::get_instance()` answers who is viewing: `get_user_id()`,
  `is_logged_in()`, `get_permission()`, `get_timezone()`, and
  `check_permission($level)`, which throws `SandboxRuntimeException` below
  that level instead of redirecting. The PHP session is the process's own,
  stored nowhere: nothing written to it outlives the call. A signal or a
  schedule has no viewer.
- `DbConnector::get_instance()` gives an object whose every use throws
  "A sandboxed plugin has no database; use a model."

The plugin's own classes resolve from `src/includes/` and `src/data/`,
after core's, by their full names where a file declares a namespace: a class
named like a core one never replaces it. Core code that catches an exception
answers quietly in the fence, so a false from it is not an answer about the
site: `PluginHelper::isPluginActive()` is false for every plugin there. Core classes
that need only these work: `FormWriterV2HTML5` draws a form (its database
`unique` rule throws). Page classes (`PublicPage`) are not for the fence:
core draws the page around what the plugin returns.

**Models.** In the fence `SystemBase::$remote_store` is set
(`SandboxRemoteStore`, `utils/sandbox_runtime_models.php`), so model code
reads like native code and each load, save, trash and list is an ask:

```php
$event = new Location(12, TRUE);                                   // record.get
$note = new Note(); $note->set('text', 'hi'); $note->save();       // record.create
foreach (new MultiNote(['text' => 'hi'], ['qty' => 'DESC'], 50) as $row) { … }   // record.list
```

The plugin's own models go by their short names (`Note`, `MultiNote`,
declared in the fence from the envelope's `models`) or core's
(`Sbx_{plugin}_Note`), and a field by its manifest name or full column name.
A class of the plugin's in `src/data/` named like its model and extending
the core name adds methods (`class Note extends Sbx_myplugin_Note { … }`); it
declares no fields, and its collection yields it. A core or native plugin's
model is read only if the plugin was approved for it; asking for another
throws, saying so. Its native plugin's `includes/` and `data/` `.php` files
are granted to the fence for that, so the approval screen lists that
plugin's program code under "Read this program code". A model name that a
core or native class already has (`Post`, `Page`) is refused at install
(`SandboxPluginManager::modelCollisions()`): in the plugin's code it would
mean the site's model. A collection takes column filters (one
value each, as `[value, PDO::PARAM_*]` too), `deleted`, one sort column, a
limit up to 200 and an offset that is a whole number of pages; with no limit
it is read 200 rows at a time. `count()` and `count_all()` work. Refused,
each saying why: raw SQL, a string condition (`"IS NULL"`), a
split-parenthesis OR, a NULL filter, an OR collection, two sort columns,
`undelete()`, `permanent_delete()`, and a collection of the plugin's own that
writes `getMultiResults()`. Core runs every save with the checks any API
caller gets. The plugin's own rules run in the fence first: a `prepare()` its
code declares runs before the ask and may refuse the save, and an overridden
`save()` runs as written; they bind only the plugin.
On the site `SystemBase::$remote_store` is null and none of this applies.

| Ask | Arguments | Core does |
|---|---|---|
| `record.get` | `model`, `id` | `GET /api/v1/{Class}/{id}`; answers `record` |
| `record.list` | `model`, `filters`, `sort`, `direction`, `limit` (1-200), `offset` (a multiple of the limit) | `GET /api/v1/{Class}s`; answers `records`, `total` |
| `record.create` | `model`, `fields` | `POST /api/v1/{Class}`; answers `record` |
| `record.update` | `model`, `id`, `fields` | `PUT /api/v1/{Class}/{id}`; answers `record` |
| `record.delete` | `model`, `id` | `DELETE /api/v1/{Class}/{id}`: to the trash, restorable; answers `record` |
| `action` | `name`, `input` | `POST /api/v1/action/{name}`; answers `data` |
| `http` | `method`, `url`, `headers`, `body` | an HTTPS request to an approved outbound host; answers `status`, `headers`, `body` (or `body_base64`) |
| `log` | `level`, `message` | a line in the call's log |

`model` is the plugin's own model by its short name (`SentLog`) or a core
model it was approved for (`Event`). Record and action asks go to the site's
own API over this machine's loopback, signed with the plugin's key, so every
API rule applies as for any caller: the scope, the model's opt-in, the
unreadable-column floor, per-row checks, the page cap, rate limits and the
locked answer for sealed rows. The address is built from names checked
against the key's scope and an integer id. A filter, a sort and a written
field must each be a declared column of the model that the API shows (by its
full name or without the model's prefix), or the `deleted` filter (true or
false); a filter value is one string, number or true/false. Anything else is
refused before the API is called.

An `http` ask is `https` on 443 only, to a host the plugin declared and an
admin approved, through `SafeHttpClient`: public addresses only, no
redirects, answers up to 1 MB. No header that steers the connection (`Host`,
`Connection`, `Content-Length`, `Transfer-Encoding`, `User-Agent` and the
like) and no name or value with CR, LF or NUL. The call log names its host,
never the rest of the address.

Caps on a call: one line up to 4 MB (a framed page's document; a plain page's body is cleaned to 1 MB), at most 200 asks, its time limit with
the asks inside it. The conversation goes in strict turns: while an answer is
unwritten, nothing more is read from the plugin. Core ends a run, at its
deadline, at a refusal or just after its answer, by closing the launcher's
watch pipe (descriptor 3), since it cannot signal the supervisor; a call
that answered is answered whatever the plugin does after. The launcher must
carry `JOINERY_JAIL_FEATURES=watch-fd` (2.1.0), or every call is unavailable.
A plugin's API requests are logged with `rql_key` `sandbox:{plugin}` and
metered on a budget of their own (`api_rate_limit_requests` per
`api_rate_limit_window`, per plugin), and left out of the loopback address's
count, so one plugin cannot spend it for the rest. Over a container's plain
HTTP the relay says `X-Forwarded-Proto: https`. What the process writes to standard error goes to the
error log, capped and on one line. Signal and schedule calls run at most
`SandboxHost::BACKGROUND_SLOTS` (2) at a time per site, beside the UI slots.
A signal reaches the plugin through the delivery queue
(`SignalDeliveryQueue`): one it is no longer approved for ends unsent; a
busy or unavailable call is tried again without counting as an attempt; one
plugin's calls may use `SignalDeliveryQueue::$sandbox_share_seconds` (10) of
a drain pass before its rows wait for the next.

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
