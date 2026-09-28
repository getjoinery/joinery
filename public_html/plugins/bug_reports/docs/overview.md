# Bug Reports plugin

Receives problem reports from the sites that upgrade from this one. Install it on an
upgrade source only: getjoinery.com for customer sites, and dev for our own fleet.

A member on any Joinery site can press **Report this problem** on an error page or an
error message. Their site builds a report, shows them all of it, and sends it to its
`upgrade_source` (core: the `ProblemReport` model, see
[docs/error_handling.md](../../../docs/error_handling.md)). This plugin is the other
end: it files each report, checks that the sender is a Joinery site, groups the same
error across sites, and shows the reports to administrators.

## Intake

`POST /api/v1/action/bug_reports/report_submit`, multipart form data:

| Field | Contents |
|---|---|
| `bundle` | The report as JSON, at most 256 KiB. Must be an object whose `site` section names a `host` and a `version`. |
| `comment` | The reporter's words, at most 5,000 characters. |
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
4. **Image.** Kept privately (`File::SOURCE_BUG_REPORT_IMAGE`) when it passes the size
   and type checks. A refused image never refuses the report; the row says why it was
   not kept.
5. **Save and notify.** The row is saved as `new`. When `bug_reports_notify_email` is
   set, one email goes out for an error not reported in the last day, carrying only the
   host, version, error kind and place.

Everything in a report came from another machine. It is stored as text, escaped on
display, and never executed, resolved or used as a path. The claimed host is used for
the callback only.

## Admin pages

- **Bug Reports** (`/plugins/bug_reports/admin/admin_bug_reports`, permission 9). Grouped
  by error hash by default: how many sites and reports, how many open, the newest
  version seen, and when first and last reported. **Every report** lists them singly
  and filters by host, version, verdict, status and hash. **Mark all seen** clears the
  notice.
- **One report** (`admin_bug_report`). The member's words, the image, the verdict and
  its reason, and every bundle section. **Mark seen**, **Mark closed** and **Reopen**
  are POST buttons.

A notice above every admin page says how many reports are `new`, for admins at
permission 9.

## Data

`rbr_received_bug_reports` (`ReceivedBugReport`): received time, sender address (the one
personal datum kept, for abuse handling), claimed host and version, verdict and reason,
error hash, kind, place and message, comment, bundle, image, and status
(`new | seen | closed`) with who closed it and when.

Closed reports older than `bug_reports_retention_days` (default 365) are deleted with
their images by the daily retention sweep. Open reports are kept.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `bug_reports_rate_limit_requests` | 20 | Reports accepted per hour from one address. |
| `bug_reports_notify_email` | empty | Address for the daily new-error email; empty sends none. |
| `bug_reports_retention_days` | 365 | Days to keep closed reports; 0 keeps them. |
