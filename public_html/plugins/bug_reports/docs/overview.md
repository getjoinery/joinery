# Bug Reports plugin

Receives problem reports from the sites that upgrade from this one. Install it on an
upgrade source only: getjoinery.com for customer sites, and dev for our own fleet.

A member on any Joinery site can press **Report this problem** on an error page or an
error message. Their site builds a report, shows them all of it, and sends it to its
`upgrade_source` (core: the `ProblemReport` model, see
[docs/error_handling.md](../../../docs/error_handling.md)). This plugin is the other
end: it files each report, checks that the sender is a Joinery site, groups the same
error across sites, and shows the reports to administrators.

A site whose operator turns on `problem_reports_auto_send` also reports unexpected errors
on its own. Those arrive with `scope: automatic`, no description, and an `occurrences`
count; the same fault from the same site and version is filed once and its count grows.

## Intake

`POST /api/v1/action/bug_reports/report_submit`, multipart form data:

| Field | Contents |
|---|---|
| `bundle` | The report as JSON, at most 256 KiB. Must be an object whose `site` section names a `host` and a `version`. |
| `comment` | The reporter's words, at most 5,000 characters. Required unless the bundle's `scope` is `automatic`. |
| `image` | Optional. PNG, JPEG, WebP or GIF, at most 5 MB, judged by its bytes. |

The action is sessionless (`requires_session => false`): the sending site has no
account here. It answers `{"report_id": N}`, which the sender keeps.

In order, `BugReportIntake::receive()`:

1. **Rate limit.** Its own bucket, `bug_reports_rate_limit_requests` reports per hour
   per address (default 20), on top of the general API bucket. Over it: 429, nothing
   stored.
2. **Shape.** The bundle must parse as a JSON object naming a host and a version, or
   the request is refused with 422.
3. **Callback.** A HEAD request to `https://{claimed host}/` through `SafeHttpClient`
   (5 s, no redirects, private addresses refused) reads `X-Joinery-Version`, which every
   Joinery site sends on every page. The verdict is stored on the row:

   | Verdict | Meaning |
   |---|---|
   | `verified` | The site answered with the version the report claims. |
   | `version_mismatch` | The site answered with a different version. |
   | `unverified` | No Joinery answer: refused, timed out, a private address, or no header. A site behind an IP allowlist lands here too. |

   A report is stored whatever the verdict, except that an `unverified` report from an
   address that already sent three unverified reports in the hour is refused with 429.
4. **Same fault, already filed.** Every report with an error gets a fingerprint, computed
   here from its error section (`ProblemReportBundle::fingerprint()`: kind, the file it
   was thrown in, and the files and functions of its stack frames, line numbers dropped).
   An automatic report whose fingerprint, host and version match a stored automatic
   report is not stored again: its `occurrences` (1 to 1,000,000; 1 when missing) are
   added to that report in one UPDATE, last-seen moves, a resolved report becomes `new`
   (snoozed and ignored ones stay as they are), and the answer is that report's id. No
   email. A member's report is always stored, and always `new`. A new automatic report
   of a snoozed fault is stored `snoozed` until the same time, and of an ignored fault
   `ignored`, unless it comes from a version newer than the ignore covers: then every
   report of the fault becomes `new`, this one too.
5. **Image.** Kept privately (`BugReportIntake::FILE_SOURCE`, declared under `fileSources` in `plugin.json`) when it passes the size
   and type checks. A refused image never refuses the report; the row says why it was
   not kept.
6. **Save and notify.** The row is saved with the status above. When
   `bug_reports_notify_email` is set, one email goes out for a report saved `new` whose
   fault was not reported in the last day, carrying only the host, version, error kind
   and place.

Everything in a report came from another machine. It is stored as text, escaped on
display, and never executed, resolved or used as a path. The claimed host is used for
the callback only.

## Admin pages

- **Bug Reports** (`/plugins/bug_reports/admin/admin_bug_reports`, permission 9). Grouped
  by fingerprint by default (reports filed before fingerprints group by their error
  hash): how many sites and reports, **times seen** (the sum of the counts), how many
  need you, the newest version seen, when first and last seen, and how the fault stands.
  Its tabs are an incident list's: **Needs you**, **Snoozed**, **Resolved**, **Ignored**
  and **All**. Tick faults and apply one choice to every report of each:
  - **Resolve**: dealt with. A recurrence needs you again.
  - **Ignore**: a fault to live with. Its reports keep counting but stay quiet while it
    recurs on the versions already reported (the newest of them is kept as
    `rbr_ignored_through`); the first report from a newer version makes it `new`.
  - **Snooze** for 4 hours, 1 day, 3 days or 1 week: back in Needs you when it ends.
  - **Reopen**: back in Needs you now.

  Reports with no recorded error are each their own and are triaged on their pages.
  **Every report** lists them singly with their counts and filters by host, version,
  verdict, status and group.
- **One report** (`admin_bug_report`). Who sent it (a member, or the site
  automatically), times seen and when last, the member's words, the image, the verdict
  and its reason, and every bundle section. **Resolve**, **Ignore**, **Reopen** and
  **Snooze** apply to every report of its fault (to this report alone when it recorded
  no error).

A notice above every admin page says how many reports need you (`new`, or out of a
snooze), for admins at permission 9.

## Data

`rbr_received_bug_reports` (`ReceivedBugReport`): received time, sender address (the one
personal datum kept, for abuse handling), claimed host and version, verdict and reason,
error hash, fingerprint, kind, place and message, comment, bundle, image, whether it
came automatically, times seen and when last, and status (`new | snoozed | resolved |
ignored`) with who resolved it and when, the end of a snooze, and the version an ignore
covers.

Resolved reports older than `bug_reports_retention_days` (default 365) are deleted with
their images by the daily retention sweep. Others are kept.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `bug_reports_rate_limit_requests` | 20 | Reports accepted per hour from one address. |
| `bug_reports_notify_email` | empty | Address for the new-error email; empty sends none. |
| `bug_reports_retention_days` | 365 | Days to keep resolved reports; 0 keeps them. |
