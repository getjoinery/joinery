# Disk Headroom, Unit Diagnosis, and the Backup That Could Not Say It Failed

**Status:** WP1, WP8 and WP11 built 2026-09-22 — the three that answer a
question rather than raise one, brought forward so this incident can be
finished with the fleet's own words instead of a login. Tests green (the two
new gates, the host-report gate, the builder and processor suites, the whole
agent suite). Awaiting the owner's commit of both repos and a release. WP2–WP7,
WP9 and WP10 not started.
Written 2026-09-22 from the incident report at
`/var/www/html/joinerytest/incidents/2026-09-22-jeremytunnell-full-backup-enospc.md`.
**Date:** 2026-09-22

## For the executor — read this first

This section is the working brief. The design sections are the reasons; the
work packages at the end are the checklist. Do the packages in order: WP1–WP7
are platform-only and ship in one release; WP8–WP11 need an agent release;
WP12 is the paper.

**House rules that bind this work** (CLAUDE.md and project memory):

- **Never commit, never `git add`.** The index is shared with other sessions;
  the owner runs git.
- **One schema change in the whole spec** (`mgn_disk_history`, WP2), made the
  only way schema changes are made: `$field_specifications` plus
  `update_database`. No migration for it.
- **Docs describe the current state only.** No "now", "previously",
  "replaces", no narration of what changed.
- **Bump the `@version` header** of every PHP file touched, one line saying
  what. Shell scripts carry `# Version:` lines; bump those too. Go files carry
  no version — the agent's version is in its own repo.
- **After every PHP edit:** `php -l <file>`, then
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`
  — on class and function files only (`includes/`, `data/`, `logic/`,
  `tasks/`, plugin equivalents). The validator *includes* the file: never run
  it on `utils/*.php` or a script. Shell: `bash -n`.
- **Tests use the shared harness** (`tests/lib/harness.php`) and carry the
  `@joinery-test` header. Working loop `php tests/run.php --changed`; before
  handing back, `php tests/run.php db --changed`. Never run the runner as root.
- **Never touch a live node.** Every live gate in this spec is the owner's.
- **The agent ships inside the platform release.** "Release the agent" means:
  commit the agent repo, then publish a platform release — `AgentDistPublisher`
  rebuilds when the source version differs from the manifest.
- **Identifiers:** questions Q1…, bugs found on the way B1…, actions A1….

**Decisions already made — do not reopen:**

- The streamed backup engine (0.8.416) stays as it is. This spec does not
  reintroduce a local archive and does not change how a backup is uploaded.
- The plane never gains a way to name a path, a file, or a command on a node.
  Every new word here takes a value from a compiled list.
- Headroom is watched in two places for two reasons, and both are wanted: on
  the **plane**, because it sees the fleet and the history; on the **node**,
  because an unpaired node has no plane and still deserves the warning.

**What this is not.** It is not a monitoring system. There is no time-series
store, no graph, no alert routing, no thresholds an operator configures. Every
rule here is compiled, every number comes from a fact the fleet already
records, and the whole output is an admin-header line and a case.

---

## What this is

On 2026-09-22 a node ran out of disk while building its weekly full backup.
Three things broke in the same fifteen minutes — the backup, PostgreSQL, and
the nightly man-page index — and by the time anyone looked, the only survivor
was the man-page index, which is the one that did not matter. The cause was
visible in stored facts for four days beforehand and nobody had written the
line that reads them.

This spec builds the missing lines. Eleven items, in three groups:

| # | What | Where | WP |
|---|---|---|---|
| 1 | `host_report` carries what a writer can actually use: available bytes, inode use, and 24h counts of OOM / ENOSPC / I/O kernel events | Platform script | WP1 |
| 2 | The plane watches disk headroom and the rate it is falling, and says so in the admin header | Plane | WP2 |
| 3 | A failed backup is an admin-header fact, not a node-page detail | Plane | WP3 |
| 4 | A backup run reports its level and its size as numbers, not prose | Platform + plane | WP4 |
| 5 | A run refuses politely when the space it needs is not there | Engine | WP5 |
| 6 | **B1** A run that loses its database still records that it failed, and a run that vanishes is not mistaken for one still going | Engine + notice | WP6 |
| 7 | **B2** "Every 7 days" means seven days, not eight | Engine | WP7 |
| 8 | `unit_journal` — the plane can ask *why* a unit failed | Agent | WP8 |
| 9 | `reset_failed_unit` — and can clear it once the answer is known | Agent | WP9 |
| 10 | `disk_headroom` recipe — an unpaired node warns itself | Agent | WP10 |
| 11 | `disk_usage` — what is actually using the space | Agent | WP11 |

## The incident, in the two sentences that matter here

The site tree gained ~10 GiB between 09-15 and 09-18 (disk used 24.0 → 34.0
GiB of 48.6). On 09-22 the 7-day chain roll made the run a full, the 0.8.415
engine built it on local disk, it needed ~16 GB against ~15.5 GB free, and the
filesystem filled — taking PostgreSQL's connection and `mandb` with it.

Read the report for the evidence. This spec assumes it.

---

## 1. `host_report`: three fields that would have named it

`maintenance_scripts/sysadmin_tools/host_report.sh` is a **script word**: the
agent registers the name and the script decides the answer
(`primitives/observe_host_report.go`). **A change here needs a platform
release and no agent release** — the node verifies the script against the
signed manifest and runs it.

Three fields, all bounded, no new parameters:

**`disk.avail_bytes`.** Today the script runs `df -B1 --output=used,size`, so
free space has to be inferred as `size - used`, and that inference silently
includes the root-reserved blocks — 2.4 GiB on the node in question. Add
`avail` to the same `df` call and report it. `used` and `total` stay: the Host
card renders them and the fold test pins them.

**`disk.inodes_used_pct`.** `df -i`. A full inode table fails writes on a disk
with free space and a different errno, and the report should be able to tell
the two apart.

**`kernel_events_24h`: `{oom, enospc, io_error}`.** Three integers, from one
capped `journalctl -k --since -24h` pass. **Counts and nothing else** — no
lines, no process names, no addresses — exactly the rule the SSH auth-failure
count already obeys, and for the same reason: a count answers "did this
happen" without carrying anything that needs redacting. Any one of the three
would have named this morning outright. A machine with no kernel journal (a
container) answers `unknown` for the object, the way every other unreadable
fact does.

The three greps, compiled in the script and nowhere else:

| Key | Matches |
|---|---|
| `oom` | `Out of memory: Kill`, `oom-kill:`, `oom_reaper:` |
| `enospc` | `No space left on device` |
| `io_error` | `I/O error`, `Buffer I/O error`, `EXT4-fs error`, `Remounting filesystem read-only` |

The plane's intake (`JobResultProcessor::sanitise_host_report`) gains the same
three keys as non-negative integers or `unknown`, and `avail_bytes` /
`inodes_used_pct` as bounded numbers. The Host card on the node page renders
them: available beside used, and a line naming any non-zero kernel count.

## 2. The plane watches the headroom it already stores

Every host report already carries the disk figures and the plane keeps them.
Nothing reads them twice.

**The rolling sample.** `JobResultProcessor::process_host_report` appends one
sample a day per node to a new bounded column, `mgn_disk_history`: a JSON list
of at most 30 `{d: "YYYY-MM-DD", a: <avail bytes>, t: <total bytes>}` entries,
newest last, one per calendar day (a second report on the same day replaces
that day's entry). Bounded, self-contained, one row to read.

A column rather than a query over `mjb_management_jobs`, because this is read
on **every admin page render** and a scan of the job table is not a thing to
do there.

**The two rules**, compiled, in a new `NodeDiskTrend` class:

- **Floor.** `avail < 10% of total`, or `avail < 5 GiB`.
- **Slope.** Over the newest 7 samples (at least 3 required), the least-squares
  fall in available bytes per day. If it is positive and
  `avail / fall < 14 days`, the node is on course to fill.

Either rule fires the notice. The wording says which: *"jeremytunnell.com —
14.6 GB free, falling 2.5 GB a day: full in about 6 days."*

**Why the slope and not only the floor.** The floor would not have caught this
incident. On 09-16 the node had 17 GB free on a 48.6 GB disk — 35%, no floor
anywhere near it — and was four days from the wall. The rate was the signal,
and the rate is computable from facts already in hand.

**The notice** is a fourth `FleetAttentionNotice` beside failed units, failing
recipes and open cases, registered the same way in the plugin's
`bootstrap.php`, permission 10, reading stored facts only, rendering `''` for
a healthy fleet. A node is named once; five named, then "and N more".

The same two figures render on the node page's Host card, so a node that is
fine still shows its headroom and its direction.

## 3. A failed backup belongs in the header

`mgn_last_backup_outcome` sits on the node row and is rendered only on that
node's own page. `SiteBackupNotice` already states the rule for a single site:
*a backup is the one thing that must not be quietly wrong.* The fleet gets the
same rule — a fifth notice naming every node whose last scheduled backup
failed, with the reason and a link to the run.

A `MultiManagedNode` option (`reports_failed_backup`) answers it in the
database, the way `reports_failed_units` does, so a healthy fleet costs one
empty query.

## 4. A run says its level and its size as numbers

`utils/run_backup.php` prints a small contract the plane parses:
`BACKUP_RESULT`, `BACKUP_TIME`, `BACKUP_WARNING`. Add two lines:

```
BACKUP_LEVEL=0
BACKUP_BYTES=17230000000
```

the level of the files artifact and its byte count.
`JobResultProcessor::parse_backup_run_verdict` reads both into `mjb_result`
(`level`, `bytes`), and the node page's Backups tab shows them per run.

Reconstructing this incident's §3.2 table meant parsing English out of
`"Full backup (5.8 GB of files) in chain-…"`. A number belongs in a field.

## 5. The engine refuses rather than discovers

`BackupRunner` checks free space before it writes in three places already
(`BackupFetch`, `BackupObjectRestore`, `BackupVerifier`) and not in the one
that writes the most. Streaming removed the archive from local disk, which
removes most of the exposure — but "most" is not a design, staging and restore
still write locally, and a run should be able to say what it needs.

Before a run, in `execute_chain()` and the standalone paths:

- Work out the **expected size**: for a level 0, the newest full in the local
  chain manifest, or 0 when there is none; for an incremental, the newest run.
  The manifest is on disk beside the snapshot and already holds artifact bytes.
- Work out what the run will **land locally**: with a streaming target, the
  manifest and envelope only — kilobytes. Without one, the whole archive.
- Refuse when `avail` is under `local_need × 1.2 + 1 GiB`, with a message
  naming **both figures**: *"This run needs about 17 GB on disk and 14.6 GB is
  free."* A refusal is a recorded failure, not a crash, and it reaches the two
  notices above.

A run that streams will essentially never refuse, which is correct. The check
exists so that the one that would fill the disk says so first.

## 6. B1 — a run that loses its database still records that it failed

`BackupRunner::fail()` writes the history row through the connection the
failure may have just killed, catches the throw, and writes a line to the
error log that nobody reads. The row stays `running`; `SiteBackupNotice`
deliberately ignores a run still recorded as running; the node's own admin
says nothing about a failed backup. That is the exact case the notice exists
for. Two halves:

**Reconnect once.** Add `DbConnector::reconnect()` — rebuild the link for the
current mode from `Globalvars`, nothing else — documented as what it is: for a
long-running CLI process whose connection died under it. `BackupRunner::fail()`
calls it once on the first throw and retries the save. Nothing else calls it.

**Age out the stale row.** `SiteBackupNotice::lastFinishedRun()` treats a row
still marked `running` whose start is older than the run window (compiled: 6
hours) as a failure, worded as what it is — *"a backup started at 04:00 and
never finished"*. This is the backstop reconnect can never be: a process the
kernel kills writes nothing at all.

## 7. B2 — "every 7 days" means seven days

`BackupChain::should_start_new()` rolls the chain when
`now - chain_created >= 7 days`. The chain was created at 04:00:**21** and the
scheduled tick runs at 04:00:**09**, so the comparison misses by twelve
seconds and every chain on every node runs eight days, not seven.

Compare whole days, or subtract a one-hour grace from the interval. One line,
one test, one sentence in `docs/backups.md`.

## 8. `unit_journal {unit, lines}` — ask why

The plane could name a failed unit and had no way to ask why. The word is
already named in `agent_recipes_and_vocabulary.md` § First words; this incident
is the second ask and the running list should record it.

**Shape** (script word, `observe`):

- `unit`: enum, compiled list — the five `host_report` expected units
  (`fail2ban`, `apache2`, `php-fpm`, `cron`, `postgresql`), plus
  `joinery-agent`, plus the housekeeping units that turn up failed on an
  ordinary Debian box (`man-db`, `unattended-upgrades`, `logrotate`,
  `apt-daily`, `apt-daily-upgrade`, `fstrim`, `e2scrub_all`).
- `lines`: int, 1–200, default 100.
- The script is `maintenance_scripts/sysadmin_tools/unit_journal.sh`, shipped
  in the platform tree and manifest-verified like `host_report.sh`. It runs
  `systemctl show` for the unit's state, result and exit status, and
  `journalctl -u <unit> -n <lines> --no-pager`, and prints a bounded object.
  The script **re-validates the unit against its own compiled list**: the Go
  enum and the script's list are mirrors, and either alone refuses.

**Two small framework additions**, both general and both gate-tested:

- `Primitive.RequiresLogAccess bool` — the owner's `agent_log_access` switch,
  checked by the dispatcher before a word runs rather than inside each word's
  body. `site_log` and `log_table_tail` adopt it and drop their in-body call,
  so there is one rule in one place. `unit_journal` declares it.
- `ScriptSpec.Redact bool` — run `redact.Text` over a script word's `output`
  before it is returned. Script words return raw stdout today, and a journal
  carries addresses, mail recipients and tokens. `unit_journal` is the only
  word that sets it.

**Hostile-caller review** (rule 5): the worst a compromised plane can do is
read 200 redacted journal lines of one unit from a closed list, on a node whose
owner left log access on. It cannot name a unit outside the list, cannot reach
another unit's journal, cannot read the whole journal, and starts no process
but the one compiled script.

**On the plane:** beside each failed unit on the Host card, a *Why?* button
that dispatches `unit_journal` for that unit and shows the result on the job
page. Buttons, not links — it is a POST.

## 9. `reset_failed_unit {unit}` — and clear it

The counterpart. `man-db.service` will keep being named until a human logs in
or the box reboots, and logging in is the thing this fleet is built to avoid.

**Shape** (script word, `operate`): the same compiled unit list as
`unit_journal`; runs `systemctl reset-failed <unit>` and reports the unit's
state before and after. It clears a *record* of failure and changes nothing
running — a unit that is still broken fails again on its next start, which is
the honest outcome. Ledgered like every operate word; refuses fail-closed when
the unit is not in the list.

Alternative considered and rejected: folding it into `host_converge`. Clearing
a failure should be deliberate, not a side effect of housekeeping.

**On the plane:** a *Clear* button beside the failed unit, POST, with a
confirm that says what it does and does not do.

## 10. `disk_headroom` recipe — the unpaired node warns itself

Everything in §2 needs a management node. A self-hosted owner with no plane
has the same disk and no notice. The recipe is the node's own copy of the
floor rule.

- **Check:** `host_report` (the word it already has), `disk.avail_bytes`
  against the floor — `< 10%` or `< 5 GiB`. Unknown when the figures are
  unknown; never a repair.
- **Repair:** **none.** There is no safe automatic answer to a full disk;
  deleting things unattended is worse than the disease.
- **Escalate:** on the first failed check, open a case. The case closes itself
  when the check passes, as every case does.

**This does not fit the recipe contract as written**, which requires a repair
word (`recipes/registry.go` refuses a recipe without one). The change is small
and honest: a `NoRepair bool` on `Recipe`, permitted only with an empty
`RepairWord`, and a loop that escalates on the first fail instead of spending a
retry budget it has no use for.

**Q1 for the owner.** Is a check-only recipe a recipe? Two answers:

| | Pro | Con |
|---|---|---|
| **Allow it** (`NoRepair`) | An unpaired node gets the same warning as a paired one, through the machinery that already exists — the check loop, the ledger, the case, the notice | The contract's "check, repair, verify" becomes "check, sometimes repair"; the next check-only recipe will be easier to add than it should be |
| **Refuse it** | The contract stays exact: a recipe is a thing that fixes something | Headroom on an unpaired node then has no home at all, and §2 only helps people who run a management node |

My recommendation: allow it, narrowly, with `NoRepair` spelled out in the
registry's gate test so the set of check-only recipes is visible in one place.
The alternative is not "keep it clean", it is "unpaired nodes get nothing".

**The node's rule is deliberately dumber than the plane's.** No slope, no
history: the node holds no series and this is not the place to build one. The
plane, which does hold the series, keeps the smarter rule. Stated so nobody
"fixes" the asymmetry later.

## 11. `disk_usage` — what is actually using the space

Added 2026-09-22, after the investigation itself ran into it: reconstructing
where ten gigabytes went took a ratio argument between two series, because the
plane holds a total and nothing else. "An ingest ran for four days" is as far
as the evidence goes from here. The §2 notice will say a node is filling; the
first question anybody asks next is *with what*, and there is no word for it.

**Shape** (script word, `observe`, **no parameters** — so it cannot be pointed
anywhere):

- The site tree's top directories to **depth 2**: name and bytes, biggest 20.
- The machine's usual suspects from a **compiled list** — `/var/log`,
  `/var/lib/postgresql`, `/var/cache`, the node's backup directory — each as
  one total.
- Totals only. **No file names, no counts, no modification times.** A
  directory size says where the space went without describing anyone's
  content, which is what keeps this an observe word an owner can leave on.
- `maintenance_scripts/sysadmin_tools/disk_usage.sh`, manifest-verified like
  `host_report.sh`. `du -x` so it never walks off the filesystem; its own
  timeout, and a `nice`/`ionice` prefix, because `du` over a large tree on a
  small box is the one read here that costs something.

**Hostile-caller review:** the worst a compromised plane learns is the shape
of the disk — that `uploads` is 12 GB and `logs` is 40 MB. It cannot name a
directory, cannot see a file name, and cannot reach outside the two compiled
roots.

**On the plane:** the headroom notice's node link lands on the Host card,
where a *What is using it?* button dispatches the word. The answer renders as
a list beside the headroom figures.

---

---

## Tests

Every test carries the `@joinery-test` header and uses the shared harness.

| Test | Tier | Pins |
|---|---|---|
| `tests/integration/host_report_gate.sh` (extend) | safe | The new keys exist, are integers or `unknown`, `avail <= total - used` is not assumed, the kernel counts are **numbers and never text**, a machine with no kernel journal answers `unknown` |
| `plugins/server_manager/tests/job_result_processor_test.php` (extend) | db | `sanitise_host_report` keeps the new fields, bounds them, and answers `unknown` for junk; `parse_backup_run_verdict` reads `BACKUP_LEVEL` / `BACKUP_BYTES`; `process_host_report` appends one sample a day and caps at 30 |
| `plugins/server_manager/tests/node_disk_trend_test.php` (new) | safe | **The replay test.** Node 176's real stored samples (09-10 → 09-22, copied into a fixture) must raise the notice on 09-16 and stay silent on 09-10. Slope maths, the 3-sample minimum, a flat disk, a disk that is emptying |
| `plugins/server_manager/tests/agent_case_intake_test.php` (extend) | db | The two new notices: named, escaped, capped at five, silent for a healthy fleet, silent below permission 10 |
| `tests/backup/backup_preflight_test.php` (new) | db | A run refuses when the need exceeds the space, names both figures, records a failure; a streaming run does not refuse |
| `tests/backup/backup_failure_recording_test.php` (new) | db | B1: `fail()` retries after a reconnect; a `running` row older than the window reads as failed to the notice; a fresh one does not |
| `tests/backup/backup_chain_test.php` (extend) | db | B2: a chain created at `04:00:21` rolls on the tick at `04:00:09` seven days later |
| `primitives/observe_unit_journal_test.go` (new) | agent | Enum refusal, cap, redaction applied, log-access switch off refuses with the pinned reason |
| `primitives/operate_reset_failed_unit_test.go` (new) | agent | Enum refusal, ledger entry, before/after state reported |
| `primitives/gate_test.go` (extend) | agent | Every log-reading word declares `RequiresLogAccess`; `Redact` is set on exactly the words that need it |
| `primitives/observe_disk_usage_test.go` (new) | agent | Depth and row caps, the compiled root list, no file names in the output, a missing root skipped rather than failing |
| `recipes/disk_headroom_test.go` (new) | agent | Floor verdicts, unknown never repairs, a case on the first fail, the case closing on a pass |
| `recipes/registry_test.go` (extend) | agent | `NoRepair` is the only way to register without a repair word |
| `vocabulary_test.go` (extend) | agent | The two new words are reported at poll |

## Docs

- `docs/backups.md` — the pre-flight refusal, the level/size lines, the chain
  interval.
- `plugins/server_manager/docs/overview.md` — the new host-report fields, the
  two new notices, the *Why?* and *Clear* buttons, the two new words.
- `specs/agent_recipes_and_vocabulary.md` — a running-list row for this
  incident (wanted: why a unit failed, and a way to clear it; done instead:
  reconstructed from job rows, and nothing), and `unit_journal` marked BUILT
  when WP8 lands.
- The incident report's §7 gains one line per item pointing here. **The report
  itself is not rewritten** — it records what was known on the day.

## Out of scope

- Any time-series store, graph, or alerting path. The notice is the output.
- Reintroducing a local archive, or changing the streamed engine.
- `file_head`, `schema_probe`, `page_probe` — other words on the running list,
  not asked for by this incident.
- Automatic remediation of a full disk. The case is a human's to answer.
- The node-side record of what the plane read (deferred to the first
  customer-owned node, `managed_hosting_and_services.md` E0).

## Work packages

Platform first: WP1–WP7 ship in one release and need no agent.

| WP | Scope | Done when |
|----|-------|-----------|
| WP1 | **BUILT.** `host_report.sh` 1.2: `disk.avail_bytes`, `disk.inodes_used_pct`, `kernel_events_24h`. Intake in `sanitise_host_report` (+ `host_report_percent`, `host_report_kernel_events`). Host card renders free space, inode use and any non-zero kernel count. `host_report_gate.sh` extended to 63 checks | The gate passes on dev. The container case (no kernel journal → `unknown`) is the owner's live gate |
| WP2 | `mgn_disk_history` column; `process_host_report` writes one sample a day; `NodeDiskTrend`; `FleetAttentionNotice::render_disk_headroom`; registered in `bootstrap.php`; Host card shows headroom and direction | The replay test raises the notice on 09-16 and not on 09-10 |
| WP3 | `MultiManagedNode` option `reports_failed_backup`; `FleetAttentionNotice::render_failed_backups`; registered | A node whose last backup failed is named in the header; a healthy fleet renders nothing |
| WP4 | `BACKUP_LEVEL` / `BACKUP_BYTES` from `run_backup.php`; parsed into `mjb_result`; shown per run on the Backups tab | A run's level and size are fields, not prose |
| WP5 | Pre-flight headroom in `BackupRunner` (chain and standalone) | A run that cannot fit refuses, names both figures, and records a failure; a streaming run is unaffected |
| WP6 | **B1**: `DbConnector::reconnect()`; `BackupRunner::fail()` retries once; `SiteBackupNotice` ages out a stale `running` row | Both halves under test; the node's own admin names a failed backup whose process died |
| WP7 | **B2**: the chain interval grace | Seven days means seven days |
| WP8 | **BUILT.** `Primitive.RequiresLogAccess` (the dispatcher's check; `site_log` and `log_table_tail` adopted it and dropped their in-body call), `ScriptSpec.Redact`, `observe_unit_journal.go`, `unit_journal.sh` 1.0, `unit_journal_gate.sh` (53 checks), the builder, the action, `process_unit_journal`, the job-page render, the *Why?* button, and `unit_journal` in `LOG_EXCERPT_TYPES`. Two new agent gates pin the flags: every log-reading word declares the switch, and `Redact` is set on exactly the word that needs it | Green. The live gate — a real `man-db.service` on node 176 — is the owner's |
| WP9 | **Agent**: `reset_failed_unit` + plane-side *Clear* button | A failed unit can be cleared from the dashboard |
| WP10 | **Agent**: `Recipe.NoRepair`; `disk_headroom` recipe — **after Q1 is answered** | An unpaired node opens a case on its own full disk |
| WP11 | **BUILT.** `observe_disk_usage.go`, `disk_usage.sh` 1.0, `disk_usage_gate.sh` (35 checks), the builder, the action, `process_disk_usage`, the job-page render, the *What is using it?* button | Green. The live gate is the owner's |
| WP12 | Docs, the running-list row, the incident report's pointers, spec to `implemented/` | — |

**Built out of order, and why.** WP1, WP8 and WP11 shipped first because the
incident that produced this spec is not finished: the plane can name a failed
unit and cannot ask why, and can say a disk is filling and cannot say with
what. Those three are the ones that answer a question. Everything else here
raises one — a notice, a refusal, a case — and none of it is urgent in the same
way. The agent is 1.39.0.

**Stop points.** Hand back after WP7 (platform release, and the owner's live
gate on the two notices), and again after WP11 (agent release, and the owner's
live gate on `unit_journal` against node 176's `man-db.service` — which, if it
is still failed by then, is the first real use of both new words).

## Open questions

**Q1.** Is a check-only recipe a recipe? (§10. Recommendation: yes, narrowly,
via `NoRepair`.)

**Q2.** Should the headroom notice be suppressible per node? A node that is
*meant* to run at 92% full will name itself every day for ever. The hold
marker (`/etc/joinery-agent/hold/<recipe>`) already answers this for the
recipe; the plane-side notice has no equivalent. Options: reuse the hold
marker, which the node reports; add a per-node "acknowledged until" stamp; or
do nothing until a node actually nags. Recommendation: do nothing yet — ten
nodes, none of them chronically full, and an acknowledgement stamp that
silences a disk warning is a thing to add on purpose rather than in advance.
