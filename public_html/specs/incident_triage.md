# Incident Triage — One Inbox for Everything a Node Needs a Person For

**Status:** Draft (2026-10-01), unbuilt. Owner direction 2026-10-01: incidents of many types, noticed
without stacking banners, a list page, states a person sets (resolved, ignored and the like), and
room for an AI to analyze one, now or later. All four questions answered 2026-10-01: **one inbox for
every source**, built in steps (Q1); problem reports from sites stay on their own page, counted in
the header line (Q2); analysis on a button first (Q3); critical incidents by email, every incident to
the bell (Q4).

**Related:**
- `specs/sentinel_managed_recovery.md` §10 and §14.B: this is the operator's half of its incident
  record. The agent's case store (`inc_incident_records`) was built as that record's first piece
  (`specs/agent_tier1_recipes.md`); this spec adds the plane's own sources, triage and the pages.
  Sentinel's driver (acting on an incident) and its customer-facing view build on this later.
- `specs/agent_recipes_and_vocabulary.md`: the read-only observe words an analysis may use.

---

## What this does for the owner

Today a node raises trouble in six unconnected ways: four red and amber banners on every admin page
(failed units, failing recipes, open cases, failed backups), dashboard panels (monitoring not
reporting, backups not happening, nodes that can no longer be managed), emails to one address (site
down, certificates), the node page itself (an agent not checking in shows nowhere else), and a
separate problem-report page. Each banner names up to five nodes and grows with the fleet. Nothing
says "I've seen this" except one case's read stamp, and nothing can be put aside.

After this, there is **one inbox**:
- Every condition that needs a person becomes an **incident**: one condition, on one node, from the
  moment it starts until it is over.
- Every admin page shows **one line** when something needs you ("3 incidents need you, 1 still
  happening. Review"), not a banner per source.
- A person **triages** each incident: looking at it, resolved, ignored, or snoozed until a date.
- The **Incidents page** lists them all; each has its own page with what happened, in order.
- An **Analyze** button (third step) asks an AI to read the incident and the node's read-only
  evidence and say, in plain words, what it thinks is wrong and what to do. It recommends; a person
  acts.

---

## The model

### Two questions per incident, kept apart

| Question | Who answers it | Values |
|---|---|---|
| **Is the condition still there?** | the source: the node's agent for a case, this management node's detectors for everything else | **active**, **cleared** |
| **What are we doing about it?** | a person | **new**, **looking**, **snoozed** (until a time), **resolved**, **ignored** |

The two never overwrite each other. A person can resolve an incident whose condition is still
active (they fixed it elsewhere, or accept it); a condition can clear while nobody has looked.

Rules:
- **A new incident starts new.** It needs a person until someone triages it.
- **What needs you** = triage `new` or `looking`, plus `snoozed` whose time has come (it returns to
  `new`). The header line and the menu count read this, and say how many are still active.
- **Cleared while new.** An incident that cleared on its own before anyone looked still needs a look
  (a site that was down for ten minutes at 3 a.m. is worth knowing about), but it is shown as
  cleared and never counts as "still happening". Resolving it is one click; the list offers
  **Resolve all cleared**.
- **A condition that comes back is a new incident**, so it is news again, with one exception against
  flapping: a plane-detected condition that returns within **one hour** of clearing reopens the
  same incident (its condition goes back to active; its triage goes back to `new` unless it was
  `ignored`). An agent case follows the node's own ids, as it does today: a new case id is a new
  incident.
- **Ignore covers this incident only.** The next occurrence is news. A standing mute ("never tell me
  about X on node Y") is out of scope: it is how real outages go unseen, and per-incident ignore
  plus snooze covers the known cases (a dormant copy's expected noise, planned work).

### Types (sources)

Each type is a **source**: a self-contained detector that says, for each node, whether its condition
holds, with a plain-language title and the detail behind it. Sources register themselves (as admin
notices do with `AdminNotices::register`); the inbox, pages and notifications never learn what any
one source means. A plugin can add a source.

| Source | Condition | Clears when | Severity | Step |
|---|---|---|---|---|
| `recipe:<name>`, `classifier:*` (agent case) | the node's agent gave up on a recipe and opened a case | the node reports the case closed | warning | 1 |
| `plane:site_down` | uptime monitoring says down (two failures in a row, as today) | monitoring says up | **critical** | 1 |
| `plane:backup_failed` | the last fleet backup run failed | a later run succeeds | **critical** | 2 |
| `plane:backups_stopped` | backups are not landing, have stopped, never ran, or cannot run (`NodeMonitorHealth::fleet_backup_health`) | backups land again | **critical** | 2 |
| `plane:backup_unverified` | a stored backup is incomplete (shelf problem) or verification failed | the next shelf check or verify passes | warning | 2 |
| `plane:failed_units` | the host report names failed systemd units | the next host report names none | warning | 2 |
| `plane:certificate` | a served certificate expires within the warning window, or renewal is overdue, or a name is not covered | the certificate is renewed or covers the name | warning (**critical** under 7 days) | 2 |
| `plane:agent_silent` | the agent has not checked in for two hours (and its owner did not switch it off) | it checks in | **critical** | 2 |
| `plane:unmanageable` | the node can no longer verify its scripts (`mgn_script_trust`) | a later job verifies | **critical** | 2 |
| `plane:monitoring_broken` | monitoring cannot conclude (misconfigured, stale) | a check concludes | warning | 2 |

Not sources, on purpose:
- **A failing recipe check** (`render_failing_recipes`) is the step before a case: the node is
  already repairing it on its own clock, and if it cannot, the case it opens is the incident. Its
  banner goes; the node page's Agent box still shows the check.
- **A node in an install state** (installing, a dormant copy, switching, retired) is skipped by every
  plane source, as every automation already skips it. An agent case is kept from any node: the node
  chose to raise it.

Problem reports from sites stay on their own page (Q2): they are about a release, not a node. The
header line counts them beside incidents, and links there.

### Severity

Two levels. **Critical**: visitors or data are at risk now (site down, backups not happening, a node
that cannot be managed or reached). **Warning**: everything else. Severity colors the header line
(red when any critical incident is active) and decides email (below).

### Noticing

- **The header line** (superadmin, every admin page): one `AdminNotices` renderer, replacing
  `fleet_open_cases`, `fleet_failed_backups`, `fleet_failed_units` and `fleet_failing_recipes` as their
  sources move in. Red when a critical incident is active, amber otherwise, absent when nothing
  needs you.
- **The menu count** on Server Manager → Incidents.
- **The bell and email**, through the platform's signals (`SignalBus` → `Notify`): one signal,
  `incident.opened`, on topic subscription, so each superadmin chooses. Default: every incident to
  the bell; **critical ones also by email**. This replaces the site-down and certificate emails to the
  single alert address (`resolve_alert_recipient`), which today reach one person and leave no record.
  An incident notifies once when it opens and once more if it reopens; never per tick.
- **The dashboard** gets an Incidents panel at the top (the newest needing you), and its other
  problem panels stay as the fleet's state at a glance.

### Pages

- **Incidents** (`/admin/server_manager/incidents`, superadmin):
  - views: **Needs you** (default), New, Looking, Snoozed, Resolved, Ignored, All;
  - filters: node, type, still active;
  - each row: severity, node (#number and name), title, started (as an age), still active or
    cleared, triage, and who triaged it;
  - select several for **Looking**, **Resolve**, **Ignore**, **Snooze**; **Resolve all cleared**.
- **An incident** (`/admin/server_manager/incident?id=N`):
  - the title, node, severity, both states, started, cleared;
  - the **timeline**, oldest first: opened, what the node tried (an agent case's attempts), cleared,
    reopened, each triage change and who made it, each note, each analysis;
  - the evidence the source attached (an agent case's body: its host report and attempts; a
    plane source's detail);
  - a note box and the triage buttons; **Analyze** (step 3).
- **The node page**: the Cases card becomes **Incidents**, this node's incidents with links, and
  its red "open case" alert reads from the same rule as the header line.

---

## Data

Extend the case store rather than add a second one: it is already the incident record, keyed by
node and source.

`inc_incident_records` gains:
- `inc_title` (text): the plain-language line, composed by the source. An agent case's is composed
  from its recipe and reason, on this side.
- `inc_severity` (`critical` | `warning`).
- `inc_triage` (`new` | `looking` | `snoozed` | `resolved` | `ignored`, default `new`),
  `inc_triage_time`, `inc_triage_usr_user_id`, `inc_snooze_until`.
- `inc_detail` (jsonb): what a plane source saw (an agent case keeps `inc_body`).

`inc_status` keeps its values and its owner (the source): `open` is active, `closed` is cleared. A
plane source mints its own `inc_node_case_id` (the next per node and source), so the existing
unique key holds.

A new table, `ine_incident_events`: incident, time, kind (`opened`, `cleared`, `reopened`,
`triage`, `note`, `analysis`), the person (when one acted), text, data (jsonb). It records changes,
never ticks: an agent case's repeated failing ticks stay folded in `inc_note_count` and
`inc_last_note`, as today.

Carried over in place:
- `inc_human_note` becomes a `note` event; `inc_read_time` / `inc_read_by` become triage:
  read and cleared → `resolved`, read and active → `looking`, unread → `new`. Both columns retire
  (the retirement registry) once nothing reads them.
- `case_note` and `case_read` on the node page become the incident page's note and triage actions.

Who writes what:
- **The source** writes the condition: opens, appends, clears, reopens. The agent intake
  (`AgentChannelEndpoint::record_case`) stays the agent's; a plane source writes through one
  reconciler (below).
- **A person** writes triage and notes, superadmin only, as POST actions.
- **Nothing on this side** ever closes an agent case: the node's check is the truth about its fault
  (unchanged).

### The reconciler

`IncidentReconciler::run()`, from a scheduled task every tick (cheap: each source reads stored state,
never probes). For each registered plane source and each operational node:
- condition holds, no active incident of that source → **open** one (or reopen one cleared within the
  hour), notify;
- condition holds, incident active → refresh its title and detail when they changed, no event;
- condition gone, incident active → **clear** it, with an event;
- one advisory lock, so the task tick and a page load never race (as staged rollouts and site
  copies do).

A source answers from what is already stored (`mgn_uptime_last_status`, `mgn_last_backup_outcome`,
`mgn_last_host_report`, `mgn_cert_expiry_ts`, `mgn_agent_last_poll`, `mgn_script_trust`, the
`NodeMonitorHealth` verdicts). The detectors that write those columns are unchanged; what changes is
how their result reaches a person.

---

## AI analysis (step 3; designed now)

**Analyze** on an incident asks a model to read the incident and say what it thinks:
- **Input:** the incident record (title, timeline, the source's evidence), the node's facts
  (release, OS, recipes and their checks, recent jobs' types and outcomes), and evidence it asks for.
- **Evidence it may ask for:** only the agent's read-only observe words, from a fixed menu
  (`host_report`, `disk_usage`, `unit_journal` and `site_log` where the node's owner allows log
  access, `check_status`), within a budget (three requests, ten minutes). Never a command it wrote;
  never a destructive or operate word. The node redacts logs before they leave it, as it does today.
- **Output,** stored as an `analysis` event: the likely cause in plain words, how sure it is (low,
  medium, high), the evidence it used, and one suggested next step: a named action a person can take
  from the page (restart a service, run a backup, read a log), or "a person needs to look".
- **It never acts.** Acting is Sentinel's driver, built later on this record.
- **Runs in the background** (a CLI worker, as joinery_ai's recipes do), never in a page request;
  the incident page shows it running and then its answer.
- **The model** comes from joinery_ai's resolver (`AiModelResolver`, purpose `incident_analysis`,
  the site's selection policy, local first), with its cost guard. Without joinery_ai active the
  button is not shown.

Analysis runs when a person presses the button (Q3). Analyzing new critical incidents on their own,
after a delay so a blip that clears in minutes costs nothing, is a later step.

---

## Work packages

- **WP1 — Triage, pages and the agent's cases.** The new columns and `ine_incident_events`, the
  carry-over of today's cases, the Incidents page, the incident page, the node page's Incidents card,
  the header line (replacing `fleet_open_cases`), the menu count. Plain titles for agent cases.
- **WP2 — The reconciler and the first plane source.** `IncidentSources` (registration),
  `IncidentReconciler` and its task, `plane:site_down`, the `incident.opened` signal (bell; email for
  critical), retiring the site-down email.
- **WP3 — The other plane sources**, one at a time, each retiring the banner or email it replaces:
  backups (three sources, retiring `fleet_failed_backups`), failed units (retiring
  `fleet_failed_units`), certificates (retiring the certificate emails), agent silent, unmanageable,
  monitoring broken. `fleet_failing_recipes` retires here too.
- **WP4 — AI analysis.**
- The header line counts new problem reports from sites beside incidents (Q2), from WP1.

## Test plan

- **Triage (WP1):** every allowed transition, and the refused ones (a non-superadmin; triage of a
  missing incident); snooze returns to `new` at its time; the carry-over maps read and unread cases
  exactly; the header line's count and color for each mix of severity, triage and condition.
- **The reconciler (WP2–3):** for each source, from fixture node rows: opens once, does not reopen
  per tick, clears when the stored state clears, reopens within the hour and opens a new one after
  it, skips a node in an install state; a page load and the task never open twice (the lock).
- **Notifications:** one signal per open and per reopen, none per tick; critical to email by default.
- **Agent cases unchanged:** the existing intake suite (`agent_case_intake`) passes untouched.
- **AI (WP4):** the analysis can reach only the menu's words (a request for any other is refused and
  recorded); the budget ends it; its output is stored and escaped; nothing it returns is executed.

## Open questions for the owner

- **Q1 — Answered 2026-10-01: one inbox for every source (b),** built in steps.
- **Q2 — Answered 2026-10-01: problem reports from sites stay separate,** on their own page; the
  header line counts them beside incidents. They are about the release, not a node, and keyed by the
  host they claim.
- **Q3 — Answered 2026-10-01: analysis on the button first.** Automatic analysis of new critical
  incidents, after a delay, is a later step.
- **Q4 — Answered 2026-10-01: critical incidents by email, every incident to the bell.**
