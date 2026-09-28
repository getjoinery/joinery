# Error Handling and Problem Reports

How an error becomes something a person can report, and how the report leaves the
site without carrying anything private.

## The error handler

`ErrorManager` (`includes/ErrorHandler.php`) is the uncaught-exception handler for every
request. For each error it:

1. **records it** through two loggers: `DatabaseErrorLogger` saves an
   `err_general_errors` row (`GeneralError::logError()`), and `FileErrorLogger` writes
   one JSON line to `logs/error.log`;
2. **renders the response** for the kind of request, with one handler each
   (`includes/ErrorClasses.php`):

| Request | Handler | Response |
|---|---|---|
| Web page | `WebErrorHandler` | An HTML error page |
| `/admin` page | `AdminErrorHandler` | The admin variant of the page |
| `/api/` | `ApiErrorHandler` | The `/api/v1` error envelope (`docs/api.md`) |
| AJAX (`/ajax/`, JSON requests) | `AjaxErrorHandler` | `{success: false, error: {message, type}}` |
| Command line | `CliErrorHandler` | Message, file, line and trace on stderr |

Recording comes first, so the response can refer to the row it was saved as.

What a person is told: a `Displayable*` exception's own message, a displayable
`BaseException`'s user message, the raw message when the `show_errors` setting is on,
and a generic sentence otherwise. The page never claims that anyone was notified.

An exception that escapes an API action's logic file is caught by `ApiLogicEndpoint`,
recorded the same way (`ErrorReference::log()`), and answered with the same user-safe
message rules. An exception marked `NoLog` is an expected refusal and is not recorded.

## Error references

A recorded error has a reference: its `err_general_errors` row id and a grouping hash.
`ErrorReference` (in `includes/ErrorHandler.php`, beside the classes that record errors) holds the reference for the request and
builds everything that points at it.

**The grouping hash** is the same for the same fault on any site: the error code, the
message with its digits folded to `N`, and the file and line relative to `public_html`.
Every part is stored on the row, so a hash computed later from the row
(`ErrorReference::hashForRow()`) matches the one computed when the error was thrown.

**Where the reference appears:**

| Surface | What carries it |
|---|---|
| Web and admin error pages | A **Report this problem** button and an "Error reference: N" line |
| `/api/v1` error envelopes | `error_ref: {id, hash, report_url}` (`api_error()`, a logic action's error, `ApiErrorHandler`) |
| AJAX error envelopes | The same `error_ref` |
| Page JavaScript | `err.errorRef` on a rejected `joineryApi` call; `joineryApi.reportLink(err)` makes the link |
| Error flash messages | A **Report a problem** link after every `MESSAGE_ERROR` alert; `DisplayMessage::$error_ref` names the row when there is one |
| The 404 page | A **Report a problem** button beside Contact Support |

Report links are shown to signed-in members only. A guest cannot send a report, so a
link would lead only to a sign-in page. An error with no recorded row (a flash message,
a 404) links with the page and the message the member saw instead of a row id.

**A page script's notice helper** takes the rejected error as a second argument and
appends `joineryApi.reportLink(err)` when it returns an element, keeping the notice up
long enough to reach the link. `drive.js`, `vault-manager.js`, `messenger.js`,
`mailbox_reader.js` and the calendar page do this.

## Problem reports

`/report_problem` (`views/report_problem.php`, `logic/report_problem_logic.php`) is where
the links lead. A guest is sent to sign in and brought back.

The page shows, top to bottom:

1. **Where the report goes**: the host of the `upgrade_source` setting, the site this
   software is upgraded from. When the operator has switched sending off, it says the
   report stays on this site.
2. **The form**: a description (required, up to 5,000 characters) and an optional
   screenshot (PNG, JPEG, WebP or GIF, up to 5 MB).
3. **What will be sent**: the bundle, built live for this member and this error, shown in
   full. The reporter reads what leaves before deciding to send it.

The form posts to the `report_problem_submit` API action (browser session only), which
rebuilds the bundle from the same inputs, saves it with the description and image in
`prr_problem_reports` (`ProblemReport`), and tries one send at once. A member may send at
most ten reports an hour.

### What a report contains

`ProblemReportBundle` (`includes/ProblemReportBundle.php`) is an explicit list of named sections
with fixed shapes. There is no free-form dump.

| Section | Contents | In whose report |
|---|---|---|
| Site | Host, platform version, schema version, theme, days since install | Everyone's |
| Request | The path where it happened, with query values masked except plain numbers; web, admin or API; whether in the app; browser, OS and time zone | Everyone's |
| Who is reporting | User id, permission level, whether an administrator is acting as this user | Everyone's |
| Error | The recorded row's kind, code, file and line, message, the first five stack frames with their arguments dropped, the hash, the time, and how many errors at the same place in seven days. With no row, the message the member saw | Everyone's |
| Recent errors | Up to 40 PHP error lines from `logs/error.log`, newest first | Operators' only |
| Runtime | PHP, PostgreSQL, OS and web server versions; whether the agent is installed and connected | Operators' only |
| Plugins | Every installed plugin, its version, and whether it is active | Operators' only |
| Settings | Names of the settings changed from their defaults (never a value); how many credentials are set; how many sealed secrets exist and how many no longer open | Operators' only |
| Health | Disk free, memory used, load, minutes since scheduled tasks last ran | Operators' only |

The site-wide sections go only into a report from an operator (permission 9, the level
that reads the error log). Any member may report, and the reporter reads every line of
the bundle, so a member's report must not show them other members' errors or the site's
configuration. For the same reason a member can attach only an error recorded on their
own account; a reference to anyone else's error is dropped and the report falls back to
the message they saw.

**Never included:** member names or addresses, email bodies or subjects, file names from
Drive or mail, form submissions, request bodies, session contents, IP addresses, any
setting value, anything under `config/`, anything sealed. Building a bundle opens no
sealed content: settings are compared as stored, never decrypted.

**Masking.** Every text value passes through `LogRedactor::text()` and has the site's own
directory stripped from paths. `LogRedactor` (`includes/LogRedactor.php`) is the PHP copy
of the agent's `redact` package, rule for rule: credential values by key name and shape,
URL passwords, bearer tokens, the personal half of email addresses (`<email>@domain`),
IP literals (`<ip>`) and opaque tokens (`<token>`). It masks shapes, so a name inside an
exception message passes; that is why the reporter sees everything first.
`tests/unit/agent_redactor_parity_test.php` holds its key list equal to the agent's, and
`tests/unit/log_redactor_test.php` runs the agent's own test cases against it.
`SmSecretRedactor` (server manager) masks with `LogRedactor::secrets()`.

### Sending

`ProblemReport::send()` posts the bundle, description and image as multipart form data to
`{upgrade_source}/api/v1/action/bug_reports/report_submit` through `SafeHttpClient` (no
redirects, 20 s, 64 KiB answer). The receiving end is the `bug_reports` plugin
([its overview](../plugins/bug_reports/docs/overview.md)).

| Status | Meaning |
|---|---|
| `queued` | Saved, not sent yet |
| `sent` | The upgrade source accepted it; its report id is kept |
| `failed` | A try did not succeed; the reason is kept |
| `kept` | Sending is switched off; the report stays on this site |

The hourly `ProblemReportSend` task retries `queued` and `failed` reports, up to five
tries in all. **System › Problem Reports** (`/admin/admin_problem_reports`, permission 9)
lists every report with its status and reason, opens one to its full bundle, and has a
**Send now** button for one that has not gone through.

### Settings

| Setting | Default | Meaning |
|---|---|---|
| `problem_reports_send` | on | Send reports to the upgrade source. Off keeps them on this site. |
| `problem_reports_retention_days` | 90 | Days to keep reports and their images; the daily retention sweep deletes older ones. 0 keeps them. |

Both are under **Settings › Problem reports**. The menu entry **Report a problem** is in
the signed-in user menu, next to Admin Help, and reaches the mobile apps through the same
menu.
