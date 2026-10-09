# Server Manager: one tab per cloud account

**Status: IMPLEMENTED 2026-10-09 (WP1-WP4 built). Verified: `cloud_account_tabs_test` 25/25 (the stamping checks failed before the stamp moved into save()); `db --changed` suite passes; the dashboard rendered for both tabs as the claude user matches the database (Main: 13 nodes, no test boxes; Test: 10 nodes, 8 hosts, the 2 pending joins); backfill marked 12 nodes and 8 hosts on dev, exactly the scratch and retire-* fixtures. The live checks are in the live verification queue.**

## What this is for

The dashboard at `/admin/server_manager` lists every box this plane knows, whichever Linode account it lives in.
Since the test account was added, dev's board carries throwaway boxes (scratch boxes, `retire-*.example.com`
fixtures, provisioning rehearsals) beside the real fleet, and their incidents, jobs, joins and provisions mix into
the same lists. After this, the page has a tab for each account, **Joinery Main Linode** and **Joinery Test
Linode**, and everything on the board shows only what belongs to the selected tab.

## What exists today (verified 2026-10-08)

- Neither `mgn_managed_nodes` nor `mgh_managed_hosts` records an account. The only account facts are
  `server_manager_operator_cloud_account` (the operator token's account name, written when the hosted card on
  Provisioning Setup is saved) and `TestCloudCleanup::COMPANY` ('Joinery Test disposable', the company name that
  marks a disposable account, read from the provider).
- The board loads hosts, nodes, recent jobs, cloud provisions and pending joins separately in `views/admin/index.php`;
  none is scoped.
- Dev's operator token is the test account's. Main-account boxes (getjoinery, docker-prod, ...) were added
  before that and are managed from this plane too.

## Design

**An account is one of two tabs: `main` or `test`.** Not a free-form provider name, because the page has exactly
these two tabs and the test account is already defined by one fact (its company name).

**Where it is recorded.** A nullable `mgh_cloud_account` on hosts and `mgn_cloud_account` on nodes. A node on a
host takes its host's account; any other node (a bare machine, a one-site install, a relay) uses its own. Empty
means `main`: an unmarked box is never hidden from the main tab.

**How a new box gets it.**
- A box this plane creates (a provision, a hosted order, an adopted server, a site copy, a join creating a node)
  gets the plane's own account: `test` when the operator token's company is `TestCloudCleanup::COMPANY`, else
  `main`. The company is read from the provider when the hosted card is saved and kept in
  `server_manager_operator_cloud_company`. No company on record means `main`.
- A box added by hand (Add Host, Connect Site) asks, with Joinery Main Linode preselected.
- It is stamped once, on first save, in each model's `save()` (`prepare()` is not called by every path that creates a box). Code that creates boxes needs no change.

**Backfill (once, by migration).** On a plane whose own account is the test one, every host and node with no
account that was created on or after 2026-10-07 (the day dev's token became the test account's) becomes `test`.
Everything older stays unmarked, so it shows under Main. On any other plane nothing is touched.

**The tabs.**
- `?account=main|test` selects; the choice is remembered in a cookie and defaults to Main.
- Each tab shows its count of nodes, and a dot when it holds something needing attention (open incident or
  pending join) so the other tab is never silent.
- Filtered by the tab: host groups and machine bars, the find box and node count, Agents asking to join, Cloud
  provisions, Recent Jobs.
  - A join belongs to the account of the box whose address it comes from. A join from an address no box or
    provision has appears on both tabs, because it needs a person wherever they are looking.
  - A provision belongs to its node's account, else the plane's own.
  - A job belongs to its node's account.
- Not filtered in this version: the notices at the top (recovery, readiness, cron) and the Incidents inbox.

## Work packages

- **WP1 Record and stamp.** Fields, `CloudAccounts` helper (labels, effective account of a node or host, the
  plane's account), `prepare()` stamping, the company setting written on setup save, the Add Host and Connect Site
  choice.
- **WP2 Backfill.** Migration `sm_016_cloud_account_backfill`.
- **WP3 Tabs and filtering.** `views/admin/index.php` and its CSS.
- **WP4 Tests and docs.** `cloud_account_tabs_test`, overview.md.

## Testing

- `cloud_account_tabs_test` (db tier): stamping by plane account, host-over-node inheritance, hand-added choice,
  backfill rule (cutoff, other planes untouched, run twice), tab filtering of hosts, joins, provisions and jobs,
  the both-tabs rule for unknown joins.
- Render the page for both tabs as the claude user and compare against the database.
- Live: on getjoinery (account = main) a new box is marked `main` and the Test tab is empty.
