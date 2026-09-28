# Problem Reports: a node reports a bug to its upgrade source

**Status:** Part 1 (member reports) implemented 2026-09-28: committed (b77cb344, ddd52a62), released in 0.8.436, WP6 passed live dev → getjoinery.com (`verified`). Part 2 (automatic reports and duplicates, D10–D14, WP7–WP9) implemented 2026-09-28: committed (e4046bec), released in 0.8.437, live check passed dev → getjoinery.com. D1–D4 and D6 decided 2026-09-25; D5, D7 and D8 2026-09-27; D9–D14 2026-09-28.
**Date:** 2026-09-25.
**Related:** `specs/implemented/agent_on_node_architecture.md` §3.5 (what may leave a
node), `specs/agent_recipes_and_vocabulary.md` rule 8 (what private means),
`specs/implemented/error_handling_consolidation.md` (the error handler this hangs off).
**Fixes on the way:** B1–B4 below, found while investigating. They are work packages
here, not side notes.

## What this does for the owner

**Today:** when something breaks on a customer's site, the only way we hear about it is
the customer writing an email, and the only way to see what happened is to pull logs
over the agent, which only connected nodes have. A member who hits an error sees "Our
team has been notified", which is not true: nobody is.

**After this:**
- Every error page, every error the API answers, and every error flash carries **Report
  this problem**. There is also a standing **Report a problem** entry in the member
  menu, the admin menu, and both mobile apps.
- The report page shows exactly what will be sent, in a panel the user can read before
  pressing Send, with a comment box and an optional image.
- The report goes to the site's **upgrade source** (getjoinery.com for customers, dev
  for our own fleet), where a plugin files it, groups it with the same error from other
  sites, and shows it on an admin page with a notice.
- Nothing private leaves: no member data, no secrets, no addresses. Masking runs on the
  node, with the same rules the agent already uses for logs.

## Decisions

| # | Decision | Decided |
|---|---|---|
| D1 | Reports go to `upgrade_source`. Management nodes are not equipped to handle bugs. | 2026-09-25 |
| D2 | The receiving action is open (sessionless) and rate-limited. The receiver calls the claimed site back to confirm it is a Joinery site. Reports are untrusted input either way (§3.5 rule 8). | 2026-09-25 |
| D3 | Any signed-in member may send. Guests may not. | 2026-09-25 |
| D4 | One report page, no modal. Error pages and JSON envelopes link to it. It renders in an app webview unchanged. | 2026-09-25 |
| D6 | The Request section keeps the full URL path. A member's name in a path segment is not a shape the redactor catches; the reporter is that member and sees it in the preview. | 2026-09-25 |
| D7 | The admin menu's **Report a problem** sits beside **Admin Help** (top level, just after it), since it is for people, not operators. | 2026-09-27 |
| D8 | B5: remove the management API's `backups/fetch` endpoint rather than restrict it to sealed files. Nothing calls it, and a future download button belongs on backup storage, where every copy is sealed. | 2026-09-27 |
| D9 | Manual reports keep one description box, with two sentences above it saying what to include (what they were doing and the steps, what they expected, what happened instead, the page, how often). No separate title, steps, expected/actual, frequency or area fields: the reports are read by AI, which does not need them split out. | 2026-09-28 |
| D5 | Settings and secrets go in as **counts and names**, never values. There is no "everything except `secret:true`" dump; that flag is an incomplete blocklist (`clone_export_key`, `mailjet_api_key`, `cloud_storage_access_key` and others are unflagged). A hand-picked list of settings safe to send by value may be added later if debugging keeps needing values. | 2026-09-27 |

## Today (checked 2026-09-25)

**No error reference exists.** `ErrorManager::handleException()` (`includes/ErrorHandler.php:289`)
saves an `err_general_errors` row through `GeneralError::logError()` and discards the id.
The page that renders the error never learns which row it is (B3).

**Five error surfaces**, four with one choke point each:

| Surface | Where | Choke point |
|---|---|---|
| Web error page | `includes/ErrorClasses.php:402` `WebErrorHandler::renderActions()` | yes |
| Admin error page | `:641` `AdminErrorHandler::renderActions()` | yes |
| API JSON envelope | `:766` `ApiErrorHandler`, `api/apiv1.php:85` `api_error()`, `includes/ApiLogicEndpoint.php:308` | yes |
| AJAX JSON envelope | `:662` `AjaxErrorHandler` | yes |
| Flash messages | `includes/AdminPage.php:260` `renderFlashMessages()`; `includes/PublicPageBase.php:1322` `render_messages()` | admin yes; public views call it by hand |
| Page JS notices | seven ad-hoc helpers (`drive.js`, `vault-manager.js`, `mailbox_reader.js`, chat, calendar, FormWriter `showToast`) | none |

The 404 view (`views/404.php:59`) already links "Contact Support" to `/contact`.

**Transport precedent:** `ServiceClient` (`includes/ServiceClient.php`) posts JSON to
`{url}/api/v1/action/{plugin}/{action}`; the receiver is an ordinary plugin logic action
with a descriptor. Joinery Direct is ruled out by its own docs: it addresses people, not
machines, and needs the mailbox plugin and published DNS on both ends.

**What a node knows about its upgrade source:** the `upgrade_source` setting (default
`https://getjoinery.com`, vault-gated), used today with no credential for upgrades and the
marketplace. Every Joinery site answers every page with an `X-Joinery-Version` header
(`serve.php:531`), which is the callback D2 uses.

**Masking:** the agent's Go `redact` package masks credential pairs, bearer tokens, URL
userinfo, the personal half of an email, IPv4/IPv6 literals and 32+ character opaque
runs. Core PHP has only `SmSecretRedactor` (a server_manager class, secrets only), kept in
step with the Go key list by `tests/unit/agent_redactor_parity_test.php`. There is no
core PHP masker for personal data.

**Defects found:**
- **B1** `includes/management_api/errors/recent_handler.php` returns raw error-log lines
  to a management node with no masking, against §3.5 rule 2.
- **B2** `includes/ApiLogicEndpoint.php:308` turns any exception from a logic file into
  `LogicResult::error($e->getMessage())`: raw exception text reaches callers, and the
  exception is logged only to `rql_request_logs`, never to `err_general_errors`.
- **B3** `GeneralError::logError()` returns void; nothing can reference an error.
- **B4** §3.5 rule 4 promises a gate test that fails when a management-API endpoint is
  added. None exists.
- **B5** (found 2026-09-27 by the B4 gate) `includes/management_api/backups/fetch_handler.php`
  streams any `.sql.gz`, `.sql.gz.enc` or `.tar.gz` under `/backups/` to a management-key
  holder, including an unencrypted database dump. That is content, not status, against
  §3.5 rule 4. Nothing in the platform or the agent calls it. **Fixed 2026-09-27 (D8):**
  removed. It was added 2026-04-20 for the SCP-replacing `fetch_backup` job, which was
  deleted 2026-07-22; sites now seal their own backups into backup storage, and the
  management node never pulls a file off a machine. With it went the router's streaming
  path (`ManagementApiRouter` 1.3): every management handler returns an array.

## Design

### The error reference (core)

`GeneralError::logError()` returns the saved row id. `ErrorContext` gains `error_id` and
`error_hash` (the existing grouping hash from `FileErrorLogger::generateErrorHash()`,
moved to a shared static so both loggers and the context use one function).

Every surface carries the reference:
- Web and admin error pages: a **Report this problem** button in `renderActions()`,
  linking to `/report_problem?ref={error_id}`. Shown only when the context has a user id
  (D3). The mailto and the "team has been notified" sentence go; the page says what is
  true: "You can report this to the people who maintain this software."
- JSON envelopes (`api_error()`, `ApiErrorHandler`, `AjaxErrorHandler`, and the
  `ApiLogicEndpoint` catch): a top-level `error_ref: {id, hash, report_url}`.
  `joinery-api.js` copies it onto the rejection as `err.errorRef`.
- Flash messages: `DisplayMessage` gains an optional error reference. `AdminPage::renderFlashMessages()`
  and `PublicPageBase::render_messages()` append a small **Report a problem** link to every
  `MESSAGE_ERROR` alert, with `?ref=` when one is set. An expected validation error has no
  reference and still gets the link, since "every error" is the ask; the report page then
  captures the URL and message instead of an error row.
- The 404 view gets the same button beside Contact Support.
- The seven ad-hoc JS notice helpers are a sweep: each appends the link when `err.errorRef`
  is present. Listed in WP1 so none is missed.

**B2 fix, same package:** the `ApiLogicEndpoint` catch logs through `GeneralError::logError()`,
keeps the `RequestLogger` row, and answers a generic message plus `error_ref` unless the
exception is a `Displayable*` one, matching the web path.

### What a report contains (core: `ProblemReportBundle`)

An explicit allowlist. Each item is a named collector with a fixed output shape (§3.5
rule 3); there is no free-form dump.

| Section | Contents | Source |
|---|---|---|
| Site | host name, platform version, schema version, active theme, install age in days | `ServicesClient::host()`, `get_joinery_version()`, `schema_version`, `ThemeHelper::getActive()` |
| Plugins | every installed plugin with version and active/inactive | `Plugin::get_all_plugins_with_status()` |
| Runtime | PHP version, PostgreSQL server version (`SELECT version()`, new), OS name and release, web server, whether the agent is installed and connected | `PHP_VERSION`, `php_uname`, `ManagementNodeStatus::is_managed()` |
| Request | URL path and query with values masked, method, whether admin/API/app, browser and OS family, timezone | the error row's context, `SessionControl::getBrowser()/getOS()/get_timezone()` |
| Who | user id, permission level, whether "logged in as" | opaque references only; never name, email, IP |
| Error | class, file:line, masked message, first five trace frames, hash, when | the `err_general_errors` row named by `ref`, or none |
| Recent errors | up to 40 masked lines from `logs/error.log` matching fatal/exception/error, newest first, plus the count of `err_general_errors` rows sharing the hash in the last 7 days | `ErrorLogParser`, through the redactor |
| Settings | names of settings whose value differs from its declared default, and the count of sealed secrets and how many are dead | `SettingsDeclarations`, `SecretReconciler::attention_verdict()` counts |
| Health | disk free %, memory %, load, cron last run age | the same reads `stats_handler` does |
| Comment | the user's words | the form |
| Image | one image, PNG/JPEG/WebP/GIF, at most 5 MB | the form |

**Never included:** member names or addresses, email bodies or subjects, file names from
Drive or mail, form submissions, raw request bodies, session contents, IPs, any setting
value, any file under `config/`, anything sealed. The user id is a number that means
nothing off the site.

**Masking on the node.** New core class `LogRedactor` is a PHP port of the agent's
`redact.Text()`: credential pairs (the shared key list), bearer tokens, URL userinfo,
`<email>@domain`, `<ip>`, `<token>` for 32+ character hex/base64 runs. `SmSecretRedactor`
keeps its name and delegates its secret list and `redact()` to it, so the plane-side
callers are untouched. The parity test compares the Go key list against the core class.
Every text field in the bundle passes through `LogRedactor::text()` before it is stored,
shown, or sent. The agent's stated limit holds here too: a member's name inside an
exception message is not a shape and passes. The preview lets the user see it before
sending.

**B1 fix, same package:** `recent_handler.php` passes its lines through `LogRedactor::text()`.
**B4 fix:** `tests/unit/management_api_surface_test.php` pins the list of handler files under
`includes/management_api/`; a new one fails the test until it is listed.

**Sealed egress.** The report page is its own request that opens no sealed content, so the
bundle is built and saved cold. The scheduled sender runs cold by construction. No
`isolate()` anywhere.

### The report page (core)

`views/report_problem.php` with `logic/report_problem_logic.php`. Permission: signed in
(`check_permission(1)`); a guest is sent to sign in and back. Renders under the core
member chrome, so it looks the same in every theme and in the app webview.

The page has three parts, top to bottom:
1. **What will be sent**: the bundle rendered as a readable list, built live for this
   request and `?ref=`. This is the consent: the user reads what goes, then decides.
2. **The form** (FormWriter, multipart): comment (textarea, required, 5,000 characters),
   image (`imageinput`, `accept="image/*"`, optional).
3. **Where it goes**: "Sent to `{upgrade_source host}`, the site this software is upgraded
   from. Nothing private is included; addresses and secrets are masked here before
   sending." plus the operator's switch state if sending is off ("This site's
   administrator has switched off problem reports. Your report will be kept here only.").

Submit is the API action `report_problem_submit` (descriptor: `requires_session`,
`mutates`, `requires_browser_session`, `input` comment + ref; the file arrives as
`$_FILES`), called by the page's JS with `FormData`. It saves the local row and tries one
send at once; the answer names the outcome (sent, queued, or kept locally).

### The local record and the sender (core)

`data/problem_reports_class.php`, table `prr_problem_reports` (`prr_`), one row per report:
user id, error id and hash, comment, bundle JSON, image `fil_file_id` (private, new
`File::SOURCE_PROBLEM_REPORT`, a row in `docs/photo_system.md`), status
(`queued | sent | failed | kept`), attempts, last attempt time and reason, remote report
id, create time. Soft delete with the file cascading.

`ProblemReport::send()` posts a multipart body (`bundle` JSON, `comment`,
`image`) to `{upgrade_source}/api/v1/action/bug_reports/report_submit` with
`SafeHttpClient` (`allow_redirects=false`, 20 s, 64 KiB response cap). A 2xx marks the
row `sent` with the remote id; anything else marks `failed` with the reason. A
scheduled task `ProblemReportSend` (hourly) retries `queued` and `failed` rows up to 5
attempts over 24 hours, then leaves them `failed` with the reason shown on the admin
list. The sender never runs when `problem_reports_send` is off; rows are `kept`.

Settings (core `settings.json`, group `problem_reports`):
- `problem_reports_send` (bool, default on): "Send problem reports to the upgrade
  source. Members and administrators can report an error from any error page. A report
  carries versions, the error, masked log lines, and the reporter's comment and image;
  never member data, addresses or secrets. Off keeps reports on this site only."
- `problem_reports_retention_days` (int, default 90): local rows and images older than
  this are deleted by the send task.

Entry points:
- `admin_menus.json`: **Report a problem** as a top-level item directly after **Admin Help** (D7) → `/report_problem`;
  **Problem Reports** under System → `/admin/admin_problem_reports` (permission 5): the
  local list with status, reason, and a link to the reported error in `admin_errors`.
- `profileMenu`: **Report a problem** → `/report_problem`. This also appears in both
  mobile apps' navigation with no app release; the webview's native picker handles the
  image.
- `settingsMenu` is not used; the switch lives on the settings page under its group.

### The receiver plugin `bug_reports`

Installed on upgrade sources only (getjoinery.com, dev). Setting prefix `bug_reports_`.

**Intake:** `plugins/bug_reports/logic/report_submit_logic.php`, descriptor
`requires_session => false` (sessionless, runs pre-auth), `mutates`, `input`: `bundle`
(text, 256 KiB), `comment` (text, 5,000). The file arrives as `$_FILES['image']`.

Order inside the action:
1. Own rate bucket: `RequestLogger::rate_limit_state('bug_reports', ...)`, setting
   `bug_reports_rate_limit_requests` default 20 per hour per IP, on top of the general
   1,000/h bucket the front controller already applies. Over the limit: 429, nothing
   stored.
2. Parse the bundle as JSON; refuse (422) anything that is not an object with a `site`
   section naming a host and a version. Every string is stored as text and escaped on
   display; nothing in it is ever executed, resolved, or used as a path (§3.5 rule 8).
3. **Callback (D2):** `SafeHttpClient` `GET https://{claimed host}/` (5 s, no redirects,
   4 KiB cap) and read `X-Joinery-Version`. Header present and equal to the claimed
   version → `verified`; present but different → `version_mismatch`; absent, refused,
   private address or timeout → `unverified`. The report is stored in every case with
   the verdict on the row; an `unverified` report from an IP that already has 3
   unverified reports in the last hour is refused (429) instead of stored. A site behind
   an IP allowlist (dev is one) files as `unverified`, which the list shows plainly.
4. Store the image with `File::createFromBytes(..., ['fil_private' => true, 'fil_source' => File::SOURCE_BUG_REPORT_IMAGE])`
   after the same checks `MessengerUploads::store()` makes (size, type by content, not
   by name). A refused image does not refuse the report; the row notes it.
5. Save `rbr_received_bug_reports` (`rbr_`): received time, sender IP (the one personal
   datum kept, for abuse handling, pruned with the row), claimed host, claimed version,
   verdict, error hash, error class and file:line, comment, bundle JSON, image file id,
   status (`new | seen | closed`), closed by and when.
6. Answer `{report_id}`. The node stores it as the remote id.

**Admin pages** (`plugins/bug_reports/admin/`, permission 5):
- `admin_bug_reports`: grouped view by error hash (count of sites, count of reports,
  newest version seen, oldest and newest time) and a flat view with filters for host,
  version, verdict and status. The plugin's declared admin menu.
- `admin_bug_report`: one report. Every bundle section rendered as a table, the comment,
  the image (served through the private file path), the verdict with the callback's
  reason, and **Mark closed** / **Reopen** as POST buttons (`action_button`). A link
  "Other reports with this error" back to the grouped view.
- `AdminNotices::register('bug_reports_new', ...)`: "N new problem reports" when any row
  is `new`.
- Optional email: `bug_reports_notify_email`; when set, one email per new *hash* (not per
  report) per day, content-free apart from host, version, class and file:line.

**Retention:** `bug_reports_retention_days` default 365; a task prunes closed reports
past it, images cascading.

### Why not the alternatives

- **Joinery Direct:** people-to-people, needs mailbox and DNS on both ends, and a
  Private-tier receiver would hold the report until a vault unlock. Its docs put machine
  traffic on machine channels.
- **The agent channel:** only the root agent can sign; the web tier cannot, and most
  customer sites have no connected management node.
- **A per-node key:** unconnected sites would need a new enrolment step for a report
  that is untrusted input regardless. The callback gives most of the assurance at no
  enrolment cost.

## Work packages

| WP | What | Fixes |
|---|---|---|
| WP1 | Error reference: `logError()` returns id, `ErrorContext` fields, shared hash, buttons on web/admin/404 pages, `error_ref` in all four JSON paths, `joinery-api.js` `errorRef`, flash link in both renderers, `DisplayMessage` reference, the seven JS helper sweep, `ApiLogicEndpoint` catch | B2, B3 |
| WP2 | `LogRedactor` core port; `SmSecretRedactor` delegates; parity test retargeted; `recent_handler` masked; management-API surface gate test | B1, B4 |
| WP3 | `ProblemReportBundle` collectors, `prr_problem_reports`, `File::SOURCE_PROBLEM_REPORT`, report page + logic + `report_problem_submit` action, sending on the `ProblemReport` model, `ProblemReportSend` task, settings, menus, `admin_problem_reports` | |
| WP4 | Plugin `bug_reports`: data class, intake action with bucket + callback, admin list/detail, notice, email, prune task, plugin.json, docs/overview.md | |
| WP5 | Docs: `docs/error_handling.md` (or the consolidation doc's home) gains the reference and the report link; `docs/api.md` gains `error_ref`; `docs/photo_system.md` two source rows; `docs/settings.md` if the group needs a mention. Current-state wording only. | |
| WP6 | Live gate: a real report crosses sites to a receiver running the plugin, and its verdict is checked. Run dev → getjoinery.com, the direction every node uses (see Build status) | |

## Tests

- `tests/unit/error_reference_test.php` (safe): `logError()` returns the id; each handler's
  output carries `error_ref`; the web page shows the button only with a user id; a
  `Displayable*` exception through `ApiLogicEndpoint` keeps its message and a plain one
  does not.
- `tests/unit/log_redactor_test.php` (safe): the Go package's own fixture lines, ported,
  produce the same output; key-list parity.
- `tests/unit/management_api_surface_test.php` (safe): the handler list.
- `tests/core/problem_report_bundle_test.php` (db): a fixture user and error row produce
  a bundle with every section present and no email, IP or setting value anywhere in the
  JSON (assert by regex over the whole document); the local row saves; the sender marks
  `sent` / `failed` against a stub `SafeHttpClient` response; the task retries and stops
  at 5; retention prunes.
- `plugins/bug_reports/tests/report_intake_test.php` (db): a well-formed post stores
  `verified` against a stub callback, a mismatch stores `version_mismatch`, a timeout
  stores `unverified`, the 4th unverified from one IP is refused, an oversize image is
  noted not fatal, a non-object bundle is 422, and nothing in a hostile bundle
  (`<script>`, a path, an `@` address) reaches the page unescaped.
- `tests/unit/core_api_mechanical_test.php` is not touched: every write here is a POST or
  a task.

## Build status (2026-09-27)

WP1–WP5 are built and their suites pass: `error_reference` 36/36, `log_redactor` 71/71,
`agent_redactor_parity` 6/6, `management_api_surface` 3/3, `problem_report_bundle` 41/41.
The existing suites the change touches pass too (`error_handling`, `core_api_mechanical`,
`retention_registry`, `declared_settings`, `secret_redactor`, and others).

**Where the build differs from the design above, and why:**

- **Operator-only sections.** Recent errors, runtime, plugins, settings and health go only
  into a report from permission 9 and above. D3 lets any member report, and the preview
  shows every line, so a member's preview would otherwise show other members' errors and
  the site's configuration.
- **A member attaches only their own error.** `?ref=` naming another account's error row
  is dropped, and the report falls back to the message the member saw. Otherwise any
  member could read any error by number.
- **No `err_hash` column.** "Errors sharing the hash in 7 days" became "errors at the same
  file and line in 7 days". Adding a column to `err_general_errors` breaks error logging
  on any box until `update_database` runs there. The hash is computable from the row
  instead (`ErrorReference::hashForRow()`), and it no longer includes the exception class.
  It uses the file path relative to `public_html`, so installs in different directories
  group together.
- **Retention through `RetentionSweep`**, by `$retention_policy` on both models, not in the
  send task.
- **The callback is a HEAD request.** A GET of a full front page exceeds the 4 KiB cap and
  loses the header. `SafeHttpClient` gained `head()`.
- **Fewer core files (owner, 2026-09-28).** Making, sending and retrying a report are
  methods of the `ProblemReport` model, not separate `ProblemReports` and
  `ProblemReportSender` classes; `ErrorReference` lives in `includes/ErrorHandler.php` with
  the classes that record errors; the bundle is `ProblemReportBundle`, and its health
  section reads the management API's `stats_handler()`. The plugin's new-reports banner is
  `ReceivedBugReport::admin_notice()`.
- **The screenshot field is a file input.** FormWriter's `imageinput` picks existing site
  images and does not upload.
- **Menu placement (D7).** Admin Help is a `profileMenu` item, not an admin-menu group, so
  **Report a problem** is one `profileMenu` item at order 115 for every signed-in member.
  It sits after Admin Help for admins and before Sign out for everyone else. **Problem
  Reports** is under System at permission 9, matching the error log.
- **Flash links also go into the six plugin pages that render messages by hand**
  (server_manager ×5, store checkout).
- **Page order.** Top to bottom the page is: where the report goes, the form, then what
  will be sent. The description is what the reporter came to write; the preview below it
  is still the whole of what leaves.
- **Guidance above the description (D9).** Two sentences name what to include, and the
  example in the box is a full report. A manual report (from the menu, with no error
  attached) uses the same page.
- **FormWriter's upload `showToast` is not swept.** It posts to a legacy admin endpoint,
  never through `joineryApi`, so no error reference can reach it.

**Owner steps:**

1. ~~Run `update_database` on dev.~~ Done 2026-09-27: menu entries seeded,
   `ProblemReportSend` active.
2. ~~Install and activate the `bug_reports` plugin on dev.~~ Done 2026-09-27;
   `models_crud`, `multi_models_crud` and `bug_reports_intake` pass.
3. ~~Commit.~~ Done b77cb344, except the report page (B8).
4. ~~Commit `views/report_problem.php` with the B9 fix, release.~~ Done ddd52a62, 0.8.436
   deployed to every node 2026-09-28.
5. ~~Install and activate `bug_reports` on getjoinery.com.~~ Done 2026-09-28.
6. Add `docs/error_handling.md` to the CLAUDE.md docs index (`/admin/admin_agent_files`).

**WP6 live gate: passed (2026-09-28).** The design named getjoinery.com → dev; it ran the
other way, dev → getjoinery.com, because every node's `upgrade_source` is getjoinery.com
(dev's too), so that is the path real reports take. Dev made an operator report through
`ProblemReport::submit()`, attached to a real error row, and sent it. getjoinery.com filed
it as report 1 and dev's row reads `sent` with that remote id. The owner read report 1 on
getjoinery.com: verdict **verified**.

- The design expected `unverified`, on the reasoning that dev's IP allowlist would block
  the callback. It does not: the allowlist (`usr_allowed_ips`) is per user and applies at
  password sign-in only, so dev's front page answers anyone and carries
  `X-Joinery-Version`. `unverified` is still what a site whose front page is blocked gets.
- The first two tries answered 404 `Unknown action`: the plugin was installed but not yet
  active, and an inactive plugin's actions do not exist. Once activated, the retry was
  accepted. Dev's hourly task would have retried on its own (3 of 5 tries used).
- The release itself hit two faults on dev, both from dev's own Postgres login rules, not
  from this feature. A peer map set 2026-09-27 locked root and www-data out of the local
  socket: `sudo publish_upgrade.php` failed generating the install SQL, and the 2026-09-28
  site backup failed its dump. Dev's rules are back to the fleet's (password login). The
  publish failure also showed that `utils/create_install_sql.php` exited 0 on every error,
  so the publisher reported a missing file instead of the reason; fixed (1.3, every failure
  exits 1, and the publisher prints the generator's output).

**WP6 rehearsal on dev (2026-09-28).** A real operator bundle with a PNG, posted over
HTTPS from dev to dev's own `report_submit`, answered 200 with a report id. The row
stored `verified`, the image attached, and
the plugin's list grouped it and its detail page showed every section. A member's
`/report_problem` page, opened from a real error page's button, showed the four member
sections and none of the operator ones. Two faults found and fixed:

- **B8:** b77cb344 left out `views/report_problem.php`, so every report link led to a
  404. Committed in ddd52a62.
- **B9:** the bundle's schema version was always blank: it read a `schema_version`
  setting that does not exist. It is now the highest applied migration
  (`mig_migrations`), and `problem_report_bundle` checks it.

## Part 2: automatic reports and duplicates (2026-09-28)

**What it does for the owner.** A site can send a report on its own whenever something
breaks, without a member pressing anything. The same bug firing a thousand times is one
report with a count of 1,000, not a thousand reports.

### Decisions

| # | Decision | Decided |
|---|---|---|
| D10 | A checkbox setting, `problem_reports_auto_send`, default **off**, sends a report automatically when an unexpected error is recorded. Turning it on is the operator agreeing to send without a preview. | 2026-09-28 |
| D11 | **Same fault** = the error's kind, the file it was thrown in, and the files and function names of its first five stack frames, with every line number dropped (so small edits do not split a group). With no stack frames, the message with numbers, quoted text and long hex runs blanked stands in for the frames. The receiver computes it from the bundle, so the rule can change on the receiver alone. | 2026-09-28 |
| D12 | **Not saved** = an automatic report of the same fault, from the same site, on the same version, as one already stored. The stored one's count grows and its last-seen time moves. A newer version, a different site, or anything a person wrote is saved. A closed one that recurs reopens. | 2026-09-28 |
| D13 | **Counts are kept on both ends.** The site keeps one automatic report per fault and version, counts every recurrence, and sends only what the receiver has not heard yet: the first time as a new report, later as a count update on the hourly task. The receiver adds each update to the stored report. | 2026-09-28 |
| D14 | **Unexpected errors only**: not permission refusals, sign-in requirements, validation failures, or any error whose message is marked safe to show (the `Displayable*` family, a displayable `BaseException`). Those are people meeting a wall, not bugs. | 2026-09-28 |

### What an automatic report carries

Nobody reads an automatic report before it goes, so it carries less than an operator's:

| Section | In an automatic report |
|---|---|
| Site, Runtime, Plugins, Settings, Health | As in an operator's report |
| Request | Surface, browser, OS, app. The path is masked to its shape: a segment that is not a number or a lowercase word (letters, digits, underscores) becomes `…`, and every query value except a plain number too. No time zone. |
| Error | As from the recorded row, plus the exception's class, with quoted text in the message masked (`'…'`) |
| Who is reporting | Not sent. `scope` is `automatic` |
| Recent errors | Not sent: other requests' log lines, which nobody reviews |
| `occurrences` | How many times the fault happened since the site last told the receiver |

It is built from the saved `err_general_errors` row, never the live exception, so an error
recorded while sealed content was open (`SealedEgressGuard`) carries only the row's
withheld reference.

### The sending site (core)

- `ErrorReference::recorded()` runs after an error row is saved on both paths (the
  uncaught-exception handler and `ErrorReference::log()`), and calls
  `ProblemReport::noteError()`. That does nothing unless both switches are on, the error
  is unexpected (D14), and the row was saved. It never sends from the failing request:
  it saves or updates a row, and the hourly `ProblemReportSend` task sends. It cannot
  throw, and it does not run inside itself (an error while noting an error is dropped).
- `prr_problem_reports` gains `prr_automatic`, `prr_fingerprint`, `prr_version`,
  `prr_occurrences`, `prr_occurrences_sent` and `prr_last_seen_time`;
  `prr_usr_user_id` becomes nullable (an automatic report has no reporter).
- One automatic row per fingerprint and version. A recurrence adds 1 and moves
  last-seen. A new fault beyond `AUTO_DAILY_LIMIT` (20) new automatic rows in a day is
  dropped; recurrences of existing rows are always counted.
- `send()` puts `occurrences` = `prr_occurrences - prr_occurrences_sent` into the bundle
  it posts, and on success records what it sent and resets the tries. The task treats an
  automatic row that has been sent but has counted more since as due again.
- The site's **Problem Reports** list shows automatic rows as "Automatic" with their count.

### The receiving site (`bug_reports`)

- `rbr_received_bug_reports` gains `rbr_fingerprint` (indexed), `rbr_automatic`,
  `rbr_occurrences` (default 1) and `rbr_last_seen_time`.
- Intake: a report with `scope: automatic` may have no description. After the callback
  and the unverified cap, an automatic report whose fingerprint, host and version match a
  stored automatic report adds its `occurrences` (1 to 1,000,000, 1 when missing) to that
  report, moves last-seen, reopens it if closed, and answers with that report's id. It is
  not saved as a new row and sends no email.
- Every report gets a fingerprint when it has an error; the grouped view groups by it,
  falling back to the Part 1 hash for older rows, and shows **Times seen** (the sum of the
  counts). The detail page shows the count, last seen, and whether a member or the site
  sent it.

### Work packages

| WP | What |
|---|---|
| WP7 | Core: setting, `ProblemReportBundle::fingerprint()` and the automatic bundle, `ProblemReport::noteError()` and the count-update send, the hook in `ErrorReference`, the admin list |
| WP8 | Plugin: columns, intake merge, grouped view and detail page |
| WP9 | Tests (fingerprint cases, D14 filter, the merge and count), docs (`docs/error_handling.md`, the plugin overview) |


### Build status, Part 2 (2026-09-28)

WP7–WP9 built. `update_database --upgrade` applied the columns on dev (the reporter column
became nullable only in the `--upgrade` pass, which every deploy runs). Suites:
`problem_report_bundle` 73/73 (31 new: the fault key, which errors count, the automatic
bundle, one row per fault with its count, the count update on send), `bug_reports_intake`
38/38 (the merge, reopen on recurrence, a newer version and a member's report stored
separately, an unreadable count counts as one, the grouped view's times seen).
The receiver pages were checked in the browser on dev.

**Where the build differs from the design above:**

- **The site's count survives a send in flight.** The model's `save()` writes every column,
  so `ProblemReport::save()` reloads an automatic report's count first; `noteError()`
  counts with its own UPDATE from other requests.
- **The receiver's comment rule moved into the intake.** The action's `comment` input is
  no longer required at the API layer; `BugReportIntake::receive()` still refuses a
  member's report without one.
- **Old rows group by their hash.** The grouped view and its filter key on the fingerprint,
  else the Part 1 hash, so reports filed before fingerprints stay grouped.

**Live check, passed 2026-09-28** (dev → getjoinery.com, both on 0.8.437, with
`problem_reports_auto_send` switched on for the check and off after):

1. The same unexpected error raised twice on dev made one automatic report (#94) seen
   twice, not two reports.
2. The send was accepted as getjoinery.com's report #2, carrying a count of 2.
3. A third occurrence counted to 3 on dev; the next send carried the one new occurrence
   and getjoinery.com answered with report #2 again, the stored report it added to.
   Dev shows 3 counted and 3 sent.

## Open questions

None. Q1 (admin menu placement) is decided as D7; Q2 (B5) as D8, remove the endpoint.
