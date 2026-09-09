# Security inventory closures, September 2026

**Status:** Implemented 2026-09-09. The record of the first rows closed from
`security_inventory.md` (the 1.0 bar): S1, S2, S3, S15 and S20. S12 closed the
same day and keeps its own spec, `vault_exposure_quick_fixes.md`, open for its
live gates. The inventory keeps the S-numbers and points here.

Each section says what the door was, what closes it, and where the closure is
pinned. The reasoning that led to each shape is in the inventory's
architecture section; this file is the end state.

## S1 — the agent-files editor writes only Markdown

**The door.** The editor writes a database row to disk in the project root
under a name the row carries. The name check refused slashes, `..` and NUL
and nothing else, so an admin session could name a target `serve.php` or
`.htaccess` and overwrite it with the row's content: a stolen session was
code.

**The close.** `AgentFile::validate_target_filename()` accepts one plain
basename ending in `.md` and nothing else: `^[A-Za-z0-9][A-Za-z0-9_-]*\.md`.
One dot only, because Apache's classic `AddHandler` matches an extension
anywhere in a name, so `CLAUDE.php.md` is not a Markdown file to every
server. The edit form's help text says so. The one live row targets
`CLAUDE.md` and `GEMINI.md`.

**Pinned by** `tests/security/agent_file_target_name_test.php` (safe tier):
the front controller, `.htaccess`, an uppercase extension, a trailing
newline, NUL, traversal, a second dot, empty and non-string input are all
refused.

Committed 2429131f.

## S2 — a setting cannot become a require path or a shell command

**The door.** A handful of settings are paths the server requires, executes,
or matches uploads against. The settings writer validated each against the
rules its declaration carried, and these carried none: the upload extension
list accepted `php`, the composer path accepted anything, the active theme
was a select the writer never held to its options, and the Galactic Tribune
theme spliced `node_dir` into a shell command unquoted.

**The close, at the setting.** Each declaration in `settings.json` (and the
server manager's `plugin.json`) carries a `validation` pattern and, where the
value decides what code runs, `vault_gated`, so changing it needs an open
unlock window when the acting account holds a vault — the same rule the
mail-routing settings use.

| Setting | Accepts | Gated |
|---|---|---|
| `allowed_upload_extensions` | lowercase extensions, comma separated, never one the server executes (`php*`, `phtml`, `phar`, `pht`, `phps`, `phpt`, `inc`, `cgi`, `pl`, `py`, `sh`, `bash`, `shtml`, `htaccess`, `htpasswd`) | yes |
| `composerAutoLoad` | `../vendor/` or `vendor/` | yes |
| `theme_template` | a folder name: `[A-Za-z0-9_-]+` | yes |
| `active_theme_plugin` | a folder name | yes |
| `node_dir` | a plain absolute path: `(/[A-Za-z0-9._-]+)+/?` | yes |
| `apache_error_log` | a plain absolute path. A read oracle, not code | no |
| `server_manager_agent_source_path` | a plain absolute path. Decides which tree `go build` turns into the signed fleet agent; management node only | yes |

**The close, at the sink.** `PathHelper::assertThemeName()` refuses a theme
name that is not a folder name (or the `theme/x` / `plugins/x` form
`getActiveThemeDirectory()` returns) whatever the row says, in
`getThemeFilePath()` and `getActiveThemeDirectory()`. A plugin name is a URL
segment the router guessed, never a setting, so a name that could not be a
folder is simply not a plugin and the chain falls through to core
(`/sitemap.xml` names no plugin; refusing it loudly was a 500). The Galactic
Tribune view quotes `node_dir` once with `escapeshellarg` before any `exec()`.

**Not done, on purpose.** The inventory row asked for `lockAll` as well. The
gate already demands presence; ending every other session on top of that
would punish a routine edit.

**Pinned by** `tests/security/setting_path_refusals_test.php` (safe tier):
each pattern's accepts and refusals, the gating flag on each declaration, and
the sink's refusals.

## S3 — the sweep: every value that becomes a path or a command

Every `require`/`include` with a non-literal path, every `exec`,
`shell_exec`, `system`, `passthru`, `popen` and `proc_open`, every
`ZipArchive`/`PharData` open, outside vendor and tests; then each argument
traced to where it comes from. Three sources matter: a setting an admin can
edit, a value an admin page stores, and a file a stranger sends.

**Settings whose value reaches a sink.** All bounded by S2 unless noted.

| Setting | Sink | Was | Now |
|---|---|---|---|
| `allowed_upload_extensions` | the upload accept pattern (`admin_file_upload_process_logic`) | any string; `php` made uploads code | lowercase list, executable extensions refused; gated |
| `composerAutoLoad` | `require $path . 'autoload.php'` (`security_logic`, `users_class`, `PathHelper`) | any string | `../vendor/` or `vendor/`; gated |
| `theme_template` | the prefix of every file the theme chain requires (`PathHelper::getThemeFilePath`) | a select the writer never held to its options; `..` passed through, only the filename was checked | folder name at the setting and at the sink; gated |
| `active_theme_plugin` | same, as `plugins/{name}` | same | same |
| `node_dir` | `exec('node ' . $node_dir . '/x.js')` in `theme/galactictribune-html5/views/explorer.php`, unquoted | shell injection from a settings row | quoted at the sink; plain absolute path at the setting; gated |
| `apache_error_log` | `tail -n 500 <path>` shown to admins (`admin_apache_errors`) | any readable file, quoted | plain absolute path. A read oracle, not code; not gated |
| `server_manager_agent_source_path` | `go build` of that tree becomes the signed fleet agent (`AgentDistPublisher`) | any path, quoted | plain absolute path; gated. Management node only |
| `baseDir`, `webDir`, `site_template`, `upload_web_dir`, `siteDir`, `upload_dir`, `static_files_dir` | every include path | — | pinned in `Globalvars_site.php`; the file value is cached before the row is read, so the row is inert |
| `default_email_template`, `*_inner_template` | `EmailMessage::fromTemplate()` by row name | — | a database row, never a path. Not a sink |
| `mailbox_*_url`, `joinery_ai_local_base_url`, `dns_filtering_*_url` and the other URL settings | HTTP clients | — | not this class (a request destination, not a path). SafeHttpClient scope covers them |

**Admin-page values whose value reaches a sink.**

| Value | Sink | State |
|---|---|---|
| agent file target names | written to the project root | S1: one folder name ending in `.md` |
| `pag_template` (a CMS page's template) | `getThemeFilePath()` | bounded: slashes and `..` refused |
| `sct_task_class` (scheduled task) | `require` of `tasks/{class}.php` | bounded: matched against the discovered task files before it is stored |
| `com_logic_function` (component type) | `require logic/components/{name}.php` | read-only in the admin; set by code that seeds component types. No edit path |
| plugin and theme archives | `tar -xzf`, `ZipArchive` (`AbstractExtensionManager`, `PluginManager`) | S9: what an archive may contain is the signing question, not a path question |
| install job forms (site name, hostname, admin email) | commands sent to the node (`JobCommandBuilder`) | bounded: each value is validated as a slug, hostname or address before it is spliced, and the executor quotes the whole command |
| test runner (tier, test name, filter) | `proc_open` (`tests_run_logic`) | bounded: quoted, and a test name must match discovery |
| the management API endpoint | `require includes/management_api/{path}_handler.php` | bounded: every segment `[a-zA-Z0-9_]+` |

**Files a stranger sends.** Attachments and inbound archives are the S5/S6/S7
jail question. For the path class specifically: the mailbox import readers
name every extracted member by `sha1(member)` (`ZipReader`) or hand the
archive to `PharData::extractTo`, which refuses traversal (`TarReader`);
`DeliverabilityReportIngest` streams the first member and never writes it by
name; `DocumentText` runs the extractor on a temp copy.

**Every other spawn** is a literal command or an internal path through
`escapeshellarg`: the backup runner, the deployment helper (one internal
`cp -r $source_dir/*` built from the site root, not from input), the
publishers, the worker spawners, the mailbox helpers (`sudo -n` on a fixed
helper with a quoted argument), the setup checks (`postconf`, `pgrep`,
`which`), and `VaultHealth`.

## S15 — memory, note and workspace writes wait for the owner

**The door.** In chat every mutating tool call is queued for the owner's
approval, but the mutating predicate named only the generic model writes.
`remember`, `forget`, `save_note` and `set_workspace` ran inline in every
mode, so a message the model had just read could plant a memory or a note
the next conversation would act on.

**The close.** `RiskHeuristic::STATE_WRITE_TOOLS` names all seven state
writes and `isMutating()` reads it. A mutating tool without a card renderer is
refused rather than queued, so the four tools implement
`QueueableToolInterface`: each renders its card from its literal arguments,
with remembered and note content verbatim (newlines shown) so smuggled
instructions are visible to the approver.

**Pinned by** `plugins/joinery_ai/tests/untrusted_envelope_test.php`
(classification and all four cards) and the existing action-queue suite.

Committed 2429131f.

## S20 — a message cannot close the untrusted envelope

**The door.** Untrusted content reaches the model inside
`<<UNTRUSTED_nonce>>…<</UNTRUSTED_nonce>>`. The nonce is 32 random bits per
run and never shown to the sender, so closing the envelope from inside was a
guess against 2^32. Adequate, and cheap to make structural. Nine places
built the markers by hand.

**The close.** `UntrustedEnvelope` is the one place the markers are built
(`open()`, `close()`, `wrap()`, `wrapBlock()`). Before wrapping it rewrites
any `<<UNTRUSTED_` or `<</UNTRUSTED_` token found inside the content — any
case, whitespace tolerated — to `[marker removed]`, so nothing a stranger
writes can close the envelope or open a fake one, whatever nonce it carries.
All nine sites call it: query results, pipeline digests, memory recall and
index, attachments, the chat fetch result, the workspace reader, the recall
tool, and the prompt contract text.

**Pinned by** `plugins/joinery_ai/tests/untrusted_envelope_test.php`, which
also fails on a hand-built marker anywhere in the plugin, and the taint-gate
suite, which pins that a fake closer is rewritten and only the real closer
survives.

Committed 2429131f.
