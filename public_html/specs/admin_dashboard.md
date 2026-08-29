# Admin Dashboard — Setup Checklist + Silent-Failure Monitor

## Problem

`/admin` currently redirects to the users list — the platform has no landing
page that answers the two questions every operator actually has:

1. **Is this site set up?** A fresh install (especially a customer-cloud VPS
   birth) has invisible requirements — outbound email, payment keys, DNS/SSL —
   that today fail only at the moment of first use.
2. **Is this site healthy?** The platform's dangerous failures are silent
   ones: cron stops ticking, a scheduled task errors every run, the email
   queue accumulates forever, a cert quietly approaches expiry. The
   2026-07-18/19 VPS-A gate caught nine shipped bugs of exactly this species —
   each one invisible until a human happened to look.

The dashboard is the site-level generalization of the Provisioning Setup page,
whose pattern (live requirement badges + one-click actions, resumable, never
blocking) proved itself during the getjoinery activation.

## Design

### One seam: dashboard cards

A core registry (`AdminDashboard`, `includes/`) that core and plugins register
**cards** into at load time — same lifecycle as the existing registries
(FulfillmentRegistry, SEO metadata, profile-dashboard sections, admin-user
panels). Plugins register from their `serve.php`.

A card declares:

| Field | Purpose |
|-------|---------|
| `key` | stable identity (`email_queue`, `store_orders`) |
| `title` | card heading |
| `type` | `setup` \| `health` \| `pulse` |
| `min_permission` | 5 or 10 — card hidden below it |
| `order` | sort within its type band |
| `render()` | returns card HTML (badges/tables/buttons, `.jy-ui` kit) |
| `status()` | `green` \| `amber` \| `red` \| `none` — drives page summary and setup-completion logic |

The same seam carries all three page bands. No second mechanism for the
wizard: **setup cards ARE the wizard.** `specs/setup_wizard.md` adds a
first-login sequential presentation of this same registry (and two contract
fields: `scope` and `copy`); whichever spec lands first creates the registry.

### Page behavior (`/admin`, view `adm/admin_dashboard.php`)

- Replaces the current 302-to-users-list.
- **Setup band** renders first while any setup card reports non-green; when
  every setup card is green the band collapses to a single "Setup complete"
  line (expandable). First load on a fresh site is therefore the wizard;
  a mature site sees status only. No modal, nothing blocking, resumable
  forever.
- **Health band** always renders. Red cards sort first.
- **Pulse band** renders last.
- Self-documenting rules apply: no intro prose, no explainer paragraphs;
  badges, guided controls, minimal helptext.

### Core setup cards (initial inventory)

| Card | Green when | One-click action |
|------|-----------|------------------|
| Site identity | name, timezone, default email name set | inline form |
| Outbound email | provider selected + creds present + **test send succeeded** | "Send test email" button (records last success) |
| Admin security | acting admin has 2FA or passkey | link to security settings |
| Theme | active theme deliberately chosen (not factory default) | link to themes |
| DNS / SSL (hosted installs) | site's own domain resolves here + cert active | status only |

### Plugin-contributed setup cards (initial inventory)

- **store**: payment keys for the active checkout type; live-vs-test mode
  surfaced.
- **mailbox**: inbound domain + DKIM/SRS state.
- **server_manager** (management nodes): links the whole Provisioning page as
  one card whose status is the page's aggregate.

**Plugin cards aggregate — they never duplicate.** A plugin that has its own
setup/health surface (the mailbox Setup tab, the Provisioning page) remains the
single source of truth for its items: the detailed checks, the how-to-fix
guidance, and any one-click fix actions live there. Its dashboard card reports
only the aggregate ("Mail setup: 3 items remaining") and links into the owning
page. The dashboard tells you *that* something needs doing; the owning page
tells you *what* and offers the fix. No item may exist as a second copy on the
dashboard that can drift from the plugin's own checklist.

### Core health cards (the silent-failure monitor)

| Card | Red when |
|------|----------|
| Cron heartbeat | no scheduled-task tick in > 20 min (**the meta-monitor** — if this is red, every other task metric is stale) |
| Task errors | any active task's last run status = error |
| Email queue | ready-to-send older than 30 min, or any error/permanent-failure rows |
| Errors (24h) | error-log count over threshold |
| Upgrade channel | update available (amber), current (green) |
| SSL expiry | cert inside warn window (reuses `server_manager_cert_expiry_warn_days` semantics for the site's own cert) |
| Backups | newest backup older than configured expectation |

### Pulse cards (accrete per plugin; none blocking)

Core: new members (7d). store: orders/revenue. event_manager: upcoming
events + registrations. mailbox: inbound volume. joinery_ai: runs/tokens.

## Decisions made

- **Checklist over wizard-flow**: setup must be resumable, non-blocking, and
  keep working as a status display after day one — proven by the Provisioning
  page.
- **One card seam for all three bands** rather than a wizard mechanism plus a
  dashboard mechanism.
- **`status()` split from `render()`** so the page can order bands, collapse
  completed setup, and later feed an aggregate (e.g. fleet dashboards
  reading a site's overall color via API) without parsing HTML.
- **Test-send is the green condition for email**, not mere key presence —
  configured-but-broken is the failure mode that actually occurred.
- **Outbound email means a provider** (see the outbound doctrine in
  `docs/email_system.md`): the setup card is green when a provider is
  configured and a test send succeeded. Direct self-hosted delivery from the
  box's own port 25 (egress unblock, PTR, IP reputation) is advanced setup and
  never appears as a setup-card requirement.
- **Plugin cards aggregate, never duplicate** (see the plugin-contributed
  cards section): detailed items and fix actions stay on the owning page.

## Open questions (owner)

1. Should health reds also notify (email/notification to admins), or is the
   dashboard read-only in v1? (Notify would reuse the queue it monitors —
   needs care when the queue itself is the sick component.)
2. Does the users-list keep its role as the sidebar "Dashboard" link target,
   or does the sidebar entry repoint to `/admin`?
3. Pulse metrics: live queries per load, or cached by a scheduled task?

## Not in scope

- Fleet-wide dashboards (Server Manager already owns that view).
- Analytics depth — pulse cards link into the existing Statistics pages.
- Historical graphs.

## Docs to update on implementation

- `docs/admin_pages.md` — dashboard card registration (new section).
- `docs/plugin_developer_guide.md` — plugins: contributing dashboard cards.
- `docs/scheduled_tasks.md` — cron heartbeat surfacing.
- `plugins/server_manager/docs/overview.md` — Provisioning page as a
  dashboard setup card.
- `CLAUDE.md` routing example if `/admin` handling is referenced anywhere.
