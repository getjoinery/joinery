# The agent's vocabulary and its two composers

**Status: UNIFIED DESIGN — owner-set 2026-09-13. Every spec in the agent
family complies with this one; where an older spec disagrees, this one wins
and the older spec carries a dated note saying so.** It reverses one owner
decision (A10, 2026-08-26: "the agent never initiates work of its own") and
records the reversal below. Nothing here is built yet except the parts it
inherits: the primitive registry and its three classes, the poll-time
vocabulary report, the destructive approval gate, the root-request queue and
the host timer. The first installer it needs ships with `post_release_fleet_defects.md` B2;
the tier 1 build is `agent_tier1_recipes.md`, sequenced after every other
defect package by the owner's order, because it is the one major piece.

## The goal, in one sentence

A node fixes its own routine failures with nothing else running, an AI on the
management node fixes the rest by asking the node questions and choosing
among things the node already knows how to do, and neither of them can ever
make a node do something a person did not compile and review.

## What a node must be able to do

The design is derived from these; nothing below is a line drawn for its own
sake.

1. **Repair itself with no plane and no human with a key.** Self-hosted is
   the default install, most Joinery boxes have no management node, and the
   programme destroys the fleet SSH key.
2. **Be repairable when the agent itself is the broken thing.** Whatever
   repairs must sit below whatever is repaired.
3. **Have every root action on it be reviewable in one place** and shipped in
   a signed release. Nothing that runs as root came from a database row, a
   file a web process wrote, or a network message.
4. **Let the plane see and ask, never command.** A compromised management
   node must not own the fleet. This is the accepted-limits table in
   `agent_management_first_principles.md`, unchanged.
5. **Use one definition of a correct host.** Install day and repair day run
   the same code, or they drift. The fail2ban defect was born from two copies
   of one idea.
6. **Detect problems continuously, with memory:** retries, backoff, a hold
   when an operator is working, escalation when repair fails.

## The principle

**The agent's vocabulary is the complete list of what can happen to a node.
Every word in it is compiled into the agent, reviewed, and safe against a
hostile caller.** Nothing on the wire and nothing on disk can add a word.
Data on disk may only make the agent do less.

The possibilities are therefore virtually anything, because the vocabulary
grows without limit, one small reviewed word at a time. The actual
possibilities are exactly what is compiled, because nothing else exists to
run.

A word is one of:

- an **observe** word: read something and return a bounded, shaped answer;
- an **operate** word: change the host in one named way, failing closed
  when it cannot prove its preconditions;
- a **destructive** word: the same, behind the approval the node issues and
  verifies itself (`implemented/restore_dispatch_approval_mechanism.md`).

Words are the registry in `joinery-agent/primitives` as it exists today:
`Name`, `Class`, `Params`, `Run`. Parameters come from closed sets compiled
into the agent (a unit name from a fixed list, a site slug validated by
pattern, a line count with a cap), never a free string that reaches a shell
or a path. Outputs are bounded so a read can never become an exfiltration
channel. Every call lands in the job ledger with who asked.

## The three actors on a node

**The installers** are the definition of a correct host: idempotent bash in
the signed tree, one aspect of the host each (`install_agent.sh`,
`install_parser_jail.sh`, `render_vhost.sh`, each plugin's `host_installer`,
and from B2 onward `host_housekeeping.sh`). Install runs them, the timer runs
them, a recipe runs them. One implementation, several clocks. An operate
word that means "make this aspect correct" is an installer run by name, and
the runner refuses a name that is not in `CORE_INSTALLERS` or a plugin's
declared `host_installer`.

**The host timer** (today called the host converger; the name suggests a
brain it does not have, and specs from now on say *the host timer*) is the
floor that requirement 2 demands. On a schedule, with nothing but the tree,
it runs every installer and carries out the root-request queue. It has no
judgment and must not grow any. It is what reinstalls the agent when the
agent is broken, and what serves a box that never pairs.

**The agent** is the judgment: it watches, decides, runs words, verifies,
escalates, and answers the plane. It never changes the host except through a
word, and the words that change the host mostly run installers by name.

Under this rule the question "is fail2ban repair a timer action or an agent
action" has one answer: the agent decides, the installer does, the timer is
the daily floor if the agent is not running.

## The two composers

Words are composed into sentences by exactly two things, and they are the
two tiers of support.

### Tier 1: compiled recipes in the agent

A recipe is a fixed sentence compiled into the agent: **check, repair,
verify, retry policy, hold, escalate.** It runs with no plane, takes no
parameters, is registered like a primitive and reported at poll beside the
vocabulary. When a recipe gives up it opens a **case**, the one thing the
agent now initiates. The contract, the case, the first recipes, the review
concerns and the burn-in are `agent_tier1_recipes.md`, which is sequenced
after every other package in `post_release_fleet_defects.md` because it is
the one major piece.

### Tier 2: the driver on the management node

The driver is the ladder driver and AI selection layer of
`sentinel_managed_recovery.md` (§5, §14.C, §14.F), unchanged in its rules:
the AI never authors a command, it chooses among named words and supplies
closed parameters, diagnosis is menu-driven through observe words, a budget
clock advances the ladder when cleverness runs out, and an action the
library does not have is an escalation to a human, never an improvisation.

The interface between the tiers is the **case** the agent originates when a
recipe gives up or the unexplained-root classifier fires; its shape and its
two delivery paths (paired: the poll; unpaired: local notice and superadmin
mail) are in `agent_tier1_recipes.md`. The AI service itself, its model,
consent and redaction, remain `sentinel_managed_recovery.md` §5, §11 and
§14.E. This spec only fixes what it may say to a node: words, and nothing
else.

## The reversal of A10, recorded

`implemented/agent_on_node_architecture.md` records A10 (owner, 2026-08-26):
*the agent gathers information and implements commands from its management
node; it never initiates work of its own; the diagnose-and-maintain
intelligence lives on the management node.* That file is implemented and is
not edited. **A10 is reversed by the owner on 2026-09-13, here:**

- The agent **does** initiate: it runs compiled recipes on its own clock
  and opens cases. What it initiates is bounded exactly as A10 bounded what
  the plane could dispatch: compiled words, no parameters on recipes, no
  local job source, no file it reads for instructions.
- A10's reason survives intact. The reason was that node-local *autonomy*
  meant a node reading instructions from somewhere local, which is what an
  attacker on the node edits first. A compiled recipe is not that; it is the
  agent's own source. Sovereignty is still "self-host the plane", because
  tier 2 judgment still lives on a management node.
- A10's consequences are revised one by one: *no self-scheduled job
  source* stands (recipes are not jobs and take no work from anywhere);
  *no local findings journal* falls (the recipe ledger and the case are
  local findings, and on an unpaired node they are the only findings); *the
  channel is the reporting path* stands for paired nodes.

`agent_management_first_principles.md`'s "No node autonomy" bullet is
rewritten to match, and its programme table gains this spec as an item.

## Rules every word obeys

Written once here; a word that breaks one is a defect.

1. **Closed parameters.** Every parameter is drawn from a set compiled into
   the agent or validated by a compiled pattern. A word never takes a path,
   a command, a URL, or a version source.
2. **Bounded output.** Reads cap what they return (lines, bytes, fields).
   A redaction pass (`sentinel_managed_recovery.md` §11) is only writable
   over output whose shape is known.
3. **Fail closed on missing proof.** An operate word that cannot prove its
   precondition refuses and says why (`restart_agent` is the model).
4. **Ledgered.** Every run records who asked (recipe, plane job, or local
   operator), what ran, and what came back, in the agent's ledger and, when
   paired, the job row.
5. **Reviewed as if the caller were hostile.** The review question for a new
   word is "what is the worst a compromised management node can do with
   this", and the answer must be acceptable before the word exists.
6. **Named, never composed on the wire.** A sentence is a sequence of
   separate calls. There is no word that takes a list of other words.
7. **Reported at poll.** The agent tells the plane its words and recipes;
   the plane routes by that report and never by a version guess
   (`vocabulary_test.go`).

## First words and first recipes

Named here so the vocabulary has a starting shape; built under
`agent_tier1_recipes.md` (the two words and two recipes it needs) and
`sentinel_managed_recovery.md` §15 (the rest).

**Words:** `host_report` (observe, no parameters); `unit_journal {unit, lines}`
— **BUILT** (agent 1.39.0), `disk_headroom_and_unit_diagnosis.md` § 8 — and its
counterpart `reset_failed_unit {unit}` (operate, the same list) — **BUILT** (agent
1.41.0), § 9; `file_head {file, lines}` (observe, closed lists, capped);
`host_converge` (operate, no parameters, runs `host_housekeeping.sh`);
`run_installer {name}`, `restart_unit {unit}`, `fail2ban_reset_config`
(operate, closed parameters).

**Recipes:** `fail2ban`, then `agent_supervision`, then `disk_headroom` — the
first check-only recipe (`Recipe.NoRepair`, a case on the first failing check,
`disk_headroom_and_unit_diagnosis.md` § 10) — then Sentinel's rungs 1 and 2
where the check is local and the repair deterministic.

## Words the fleet has asked for — a running list

What an operator reached for and did not have, recorded as it happens.
Each entry says what was wanted, what was done instead, and the word or
recipe that would close it. Every rule above applies: observe words are
read-only with closed parameters and capped output; nothing here takes
free text or a path.

**2026-09-17, the 0.8.408 apply on joinerydemo (release carried a table
rename, migration 192).** The whole apply went through `apply_update` and
no shell was opened; what follows is what the operator could not see.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Confirm the node's schema after a migration: the old table gone, the new column NOT NULL, a row count | Trusted the transcript's own lines | `schema_probe {table}` (observe): exists, row count, columns with type and nullability, indexes — the node's `information_schema` answer for one named table, no SQL taken from the plane |
| Which plugins are active on the node, and their versions | Inferred from the absence of a plugin-migration line | `host_report` (or `check_status`) carries the plugin set: name, version, active — the platform's own registry, read not guessed |
| The site's error log for the minutes after the swap | Nothing | `site_log {file, previous, lines}` (observe): the last N lines of one of the site's own log files from a compiled list, capped, redacted on the node — **BUILT** (agent 1.35.0), `agent_log_access.md` |
| A deploy result to read rather than a transcript to grep | Grepped 70 KB of routing debug for six lines | `apply_update` posts a structured result beside the transcript: version before and after, each migration run with its row counts, schema changes, deploy-tier verdict, rollback yes/no. The transcript stays for forensics |
| The site's own log tables after the swap: the last logins, request log rows, event log rows and webhook rows | Nothing | `log_table_tail {table, rows}` (observe): the newest N rows of one log table from a compiled list (`log_logins`, `rql_request_logs`, `evl_event_logs`, `wbh_webhook_logs`, `lfe_log_form_errors`), compiled column list per table, rows capped; the node's own query, no SQL taken from the plane. With `site_log`, gated by one owner-set switch on the node, on by default, redacted on the node — **BUILT** (agent 1.35.0), `agent_log_access.md` |
| The affected pages rendered on the node as a signed-in user | Only `/` and `/login` from outside; the pages were checked on dev with a throwaway superadmin | `page_probe {view}` (observe): the node renders a view from a closed list as a throwaway session of the platform's own making and reports status, size and any SQL or PHP error in the body — the `deploy_site_responds` test, given a list |
| Roll a release across the fleet in risk order, one node at a time, stopping at the first problem | Queued `apply_update` by hand per node from a script, waited on each job, grepped each transcript, queued the next | Not a node word — the node has `apply_update`. A **tier 2 recipe on the plane**, `staged_rollout {release, order}`: an ordered node list, one `apply_update` at a time, a gate between them read from the structured result above (completed, deploy tier green, version reported, no rollback), halt on the first miss and say which node and why. "Apply update to all on host" is its unordered ancestor |

**2026-09-22, the node that filled its disk for fifteen minutes.**
jeremytunnell.com built its weekly full backup on a disk that could no longer
hold one. The backup died, PostgreSQL went away and the nightly man-page index
failed; the space came back on the way out, so by the time anyone looked the
only survivor was `man-db.service` in the failed-unit notice. The whole
diagnosis was reconstructed from job rows on the management node. Incident:
`/var/www/html/joinerytest/incidents/2026-09-22-jeremytunnell-full-backup-enospc.md`.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Why `man-db.service` failed — its result, its exit status, its last journal lines | Nothing. The plane could render the name and not ask about it | `unit_journal {unit, lines}` (observe): a closed list of twelve units, capped lines, redacted on the node, behind the same owner switch as `site_log` — **BUILT** (agent 1.39.0) |
| What the ten gigabytes were that arrived in four days | Inferred from two stored series agreeing — the disk total and the incremental archive sizes — which is evidence, not an answer | `disk_usage` (observe, no parameters): the site tree's biggest directories to depth two and a compiled list of machine directories, sizes only, never a file name — **BUILT** (agent 1.39.0) |
| Whether the kernel had said "no space left on device" | Nothing; the journal was 33 hours old by the time anyone asked | `host_report` carries `kernel_events_24h`: three counts, OOM / ENOSPC / I/O error — **BUILT** (platform, no agent release: `host_report.sh` is a script word) |
| How much room a writer actually has | `total - used`, which quietly includes the root reserve — 2.4 GiB on that node | `host_report` carries `disk.avail_bytes` and `disk.inodes_used_pct` — **BUILT** |
| To clear the failed unit once it was understood | Nothing; it will keep being named until someone logs in or the box reboots | `reset_failed_unit {unit}` (operate, same closed list, starts and stops nothing) — **BUILT** (agent 1.41.0), with a *Clear* button beside the failed unit |
| To be told the disk was filling before it filled | Nothing. Four days of warning sat unread in stored host reports | The plane-side notice over stored samples (floor **and** slope, § 2 of the spec) was **dropped by the owner 2026-09-22**: disk space is the operator's responsibility and a daily notice is noise. What was built is the node's own floor: recipe `disk_headroom` (check-only, `Recipe.NoRepair`: 10% or 5 GiB available, a case on the first failing check) — **BUILT** (agent 1.41.0) |

## What complies with what

- `agent_management_first_principles.md`: programme, status, acceptance.
  Gains this spec as an item and rewrites its autonomy bullet.
- `sentinel_managed_recovery.md`: the driver, the ladder, the AI's rules,
  consent, redaction, the product. Its rungs 1 and 2 become recipes where
  local and deterministic; its rung library is the tier 2 word list; its
  collectors are observe words. Noted at its head.
- `node_unexplained_root.md`: the agent, its recipes and the host timer
  are expected root actors; a recipe run is explained because it is
  ledgered. Noted at its head.
- `docker_host_agent.md`: both actors on the host. Noted at its head.
- `agent_machine_posture_and_relay_converge.md`: deferred; its rule
  "collect what exists, refuse what needs what is missing, never guess a
  path" is a rule of this spec. Noted at its head.
- `agent_tier1_recipes.md`: the build of tier 1. Complies with every rule
  here and adds none.
- `post_release_fleet_defects.md`: B2 supplies the first installer; its
  vocabulary section points here.
- `implemented/agent_on_node_architecture.md`: not edited. A10 is reversed
  here and in first principles.

## Work packages

| WP | Scope | Spec |
|----|-------|------|
| WP1 | `host_housekeeping.sh` in `CORE_INSTALLERS`; installer stops writing the broken jail file; Apache jails behind `mod_remoteip` in proxy mode | `post_release_fleet_defects.md` B2 |
| WP2–WP5 | recipes package, check loop, case, first words and recipes, Sentinel rungs 1–2 as recipes | `agent_tier1_recipes.md` |

WP1 lands with the other defect packages. Everything else waits, by the
owner's order, until those have shipped.

## Open questions

Settled by the owner 2026-09-13; recorded in `agent_tier1_recipes.md`.
