# Problem Reports: a node reports a bug to its upgrade source

**Status:** Spec, 2026-09-25. D1–D6 decided the same day; Q1 open. Not started.
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
| D5 | Settings and secrets go in as **counts and names**, never values. There is no "everything except `secret:true`" dump; that flag is an incomplete blocklist (`clone_export_key`, `mailjet_api_key`, `cloud_storage_access_key` and others are unflagged). | proposed |

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

### What a report contains (core: `BugReportBundle`)

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

`includes/ProblemReportSender.php` posts a multipart body (`bundle` JSON, `comment`,
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
- `admin_menus.json`: **Report a problem** under Help (or System) → `/report_problem`;
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
| WP3 | `BugReportBundle` collectors, `prr_problem_reports`, `File::SOURCE_PROBLEM_REPORT`, report page + logic + `report_problem_submit` action, `ProblemReportSender`, `ProblemReportSend` task, settings, menus, `admin_problem_reports` | |
| WP4 | Plugin `bug_reports`: data class, intake action with bucket + callback, admin list/detail, notice, email, prune task, plugin.json, docs/overview.md | |
| WP5 | Docs: `docs/error_handling.md` (or the consolidation doc's home) gains the reference and the report link; `docs/api.md` gains `error_ref`; `docs/photo_system.md` two source rows; `docs/settings.md` if the group needs a mention. Current-state wording only. | |
| WP6 | Live gate: getjoinery.com (`upgrade_source` = dev) sends a report to dev running the plugin; dev's IP allowlist makes the callback file it `unverified`, which is the expected verdict and is checked | |

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

## Open questions

- **Q1** Should the standing menu entry be under Help or System in the admin menu?
  Proposed: Help, next to the docs link, since it is for people, not operators.
