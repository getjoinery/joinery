# Agent tier 1: compiled recipes, the check loop, and the case

**Status: DESIGN SET 2026-09-13, unbuilt, sequenced LAST.** Owner's order:
every other package in `post_release_fleet_defects.md` ships first, then this
one is built on its own, because it is the one piece of that work that is
major: the first time the agent acts without being asked. It builds the tier 1
composer of `agent_recipes_and_vocabulary.md`, which is the design authority;
this spec is the build. Nothing here may loosen a rule stated there.

## What this builds, in one sentence

The agent watches a short list of things on its own node, repairs each with
a compiled sentence when it breaks, and raises its hand when the sentence
does not work.

## Scope

In: a `recipes` package in the agent registered like `primitives`; the
check loop; the hold marker; the recipe ledger; the **case** and its two
delivery paths (paired: the poll; unpaired: local notice and mail); the
plane's intake of a case, the Host card, and the notices; the words the
first recipes need (`host_report`, `host_converge`); the first two recipes
(`fail2ban`, `agent_supervision`); a burn-in on our own fleet before any
customer node runs a recipe.

Out: the driver and the AI (tier 2, `sentinel_managed_recovery.md`);
Sentinel's rungs 1–2 as recipes beyond the first two (each is its own
review, sequenced by Sentinel §15); `host_housekeeping.sh` itself (ships
earlier under `post_release_fleet_defects.md` B2 and is the floor until
this lands).

## The recipe contract

A recipe is a fixed sentence compiled into the agent: **check, repair,
verify, retry policy, hold, escalate.** It runs with no plane. A recipe is a
Go value registered like a primitive, in a `recipes` package beside
`primitives`, with the same posture and the same gate test that pins the
registry. The agent runs a check loop on a compiled cadence and reports its
recipe list at poll beside its vocabulary, so the plane never guesses which
recipes a node has.

The recipe contract:

- **check**: an observe word or a local probe, cheap, side-effect free.
- **repair**: one operate word, usually an installer by name.
- **verify**: the check again, plus whatever the repair returns.
- **retry**: attempts and backoff, compiled per recipe (fail2ban: three in
  an hour).
- **hold**: a root-owned marker on the node
  (`/etc/joinery-agent/hold/<recipe>`) that an operator sets while working
  on that aspect; the recipe records that it is held and does nothing. Data
  that narrows the agent is allowed; data that widens it is not.
- **escalate**: when retries are exhausted, the recipe opens a **case**.
  While a recipe's case is open it keeps checking but never repairs; the
  host timer's daily run is the floor and a human is the next actor. The
  case closes itself when the check passes.

**Rules of the loop (owner-reviewed 2026-09-13):**

- **A recipe takes the job lock.** The mutex a plane job holds while it runs,
  and the self-updater refuses to swap the binary without, is the one a
  recipe attempt holds too. A tick that cannot take it skips, writes "a job
  is running" to the ledger, and tries next tick; that is not an attempt.
- **A recipe attempt sets the job marker** (`/etc/joinery-agent/job-running`)
  for its duration, so an installer that would restart the agent
  (`install_agent.sh` under `agent_supervision`) defers the restart to the
  self-updater exactly as it does for a job.
- **The ledger is written before the repair runs and completed after.** An
  attempt is appended (recipe, word, time) when it starts and its outcome
  filled in when it ends. An entry with no outcome is a failed attempt on
  the next tick, so a repair that kills the agent still counts against the
  budget and cannot loop through restarts.
- **Two ledgers.** The ledger the agent reads back (attempt counts, backoff,
  the open case) lives root-only under `/etc/joinery-agent/ledger/`. The
  copy the site renders for the unpaired notice is written outward to the
  site's cache directory and never read back, because the web user can write
  there and a forged "no attempts yet" would widen the agent from a file on
  disk. Same shape as the job marker.
- **The hold directory is root-owned and writable by root alone,** or the
  marker is ignored and the ledger says so. A marker only narrows, but it is
  a stated refusal, not an accident.
- **Consecutive** means two failed ticks with no pass between them, and an
  agent restart resets the count, so a failure recorded before a reboot is
  not the first tick of a new one.
- **Unknown never repairs.** A check that cannot answer (journal unreadable,
  systemd not responding, the runner busy) is "unknown", not "failed".

A recipe never has a parameter. There is nothing to configure about "keep
fail2ban running", and a parameter would be a way for something outside the
source to change what the recipe does.

## The case

The interface between the tiers is the case:

- The agent **originates** a case when a recipe gives up, or when the
  unexplained-root classifier fires (`node_unexplained_root.md`). This is
  the one thing the agent now initiates. A case is a small record: recipe
  or source, the recipe's ledger (every attempt, every word run, every
  result), a fresh `host_report`, and the agent's vocabulary.
- On a **paired node** the case rides the next poll, and the plane's driver
  picks it up. The driver then works the case with words: observe words to
  gather, operate words to act, destructive words only through the node's
  approval. It closes the case with a note the owner can read: what it saw,
  what it ran, where any backup is.
- On an **unpaired node** the case lands in the agent's local ledger, the
  site's admin shows it as a notice (the same path
  `HostConvergerNotice` uses for timer transcripts today), and superadmins
  get one email. Tier 2 for an unpaired node is a human reading that
  notice; the sovereignty answer stays "run your own management node".

The AI service that consumes a case, its model, consent and redaction,
remain `sentinel_managed_recovery.md` §5, §11 and §14.E. This spec builds
the case and its delivery; the plane-side intake here is the minimum that
shows a case on the node page and raises the notice, so a case is visible
to a human before any driver exists.

## The words and recipes this spec builds

**Words (observe):**

- `host_report`: failed units; expected units and their state (fail2ban,
  apache2 or php-fpm, cron, postgresql); fail2ban jails with ban counts;
  SSH auth failures in the last 24 hours from the journal; sshd posture as
  read from `sshd -T` (password auth, root login), reported never changed;
  disk; memory and swap; reboot-required; unattended-upgrades last run.
  No parameters. Cheap enough to run every check-loop tick.
- `unit_journal {unit, lines}`: `unit` from the compiled expected-units
  list, `lines` capped at 200.
- `file_head {file, lines}`: `file` from a compiled list of host
  configuration files worth reading in a diagnosis (`jail.local` and the
  drop-ins, the rendered vhosts, `sshd_config`), `lines` capped.

**Words (operate):**

- `host_converge`: run `host_housekeeping.sh` from the tree through the
  timer's runner and return the transcript plus a fresh `host_report`. No
  parameters. Refuses when the tree is untrusted or the installer is not in
  `CORE_INSTALLERS`. **Built as (owner-chosen 2026-09-13, option 1 of
  three):** the runner gains a single-installer mode
  (`--only=<installer>`) that takes the runner lock, runs that one core
  installer, refuses any name outside `CORE_INSTALLERS`, touches neither
  the converge stamp nor the timer's last-run file, and skips everything
  else. `host_converge` is the runner path plus that one **compiled
  constant** argument, verified against the manifest exactly as
  `run_plugin_installers` is; its header review says why a compiled
  constant is not a wire-supplied argument. The runner's exit code is
  fail-safe zero, so the recipe verifies by checking the unit, never the
  exit code. Rejected: running the installer directly from the agent (a
  second root path outside the runner, no shared lock) and queueing a root
  request (the agent handing itself a job through a file, which A10's
  reversal keeps forbidden).

  **The runner lock (platform side of WP2).** Today the runner's flock is
  taken only at the root-request phase, after every core installer has run,
  so a recipe's run and the timer's daily run can overlap on the same
  installer. Four rules:
  1. The lock moves to the top of the runner and covers every mode,
     including cron, before anything changes the host. A run that finds it
     held **waits for it, bounded** (`flock -w`, minutes: long enough for a
     full converge or an upgrade's installer run, far shorter than the
     service timeout), and only then says who holds it and exits 0. An
     immediate skip was the first cut (review R1, 2026-09-13): the timer's
     tick holds the lock for about a second every minute, so an upgrade's
     installer run or a plane-dispatched `run_plugin_installers` that
     landed on a tick did nothing and exited 0 looking done, and a full
     converge lasting minutes made that certain for anything started
     beside it.
  2. The lock file records its holder: pid and start time, written after the
     lock is taken, so a busy tick can say who holds it and since when.
  3. The lock cannot go stale (a kernel flock dies with its holders) but it
     can be held by something alive and hung. The timer's oneshot service
     gains a start timeout (an hour); systemd's default kill mode then kills
     the whole control group, installer and children together, and the lock
     releases. The agent, when a script times out, kills the process group
     and not only the bash it started, so a hung child cannot inherit the
     descriptor and keep the lock; this is a dispatch fix every script word
     inherits, `run_plugin_installers` included.
  4. Busy has its own budget: the runner found busy on consecutive ticks for
     longer than the service timeout is a case ("the runner has been held by
     pid X since T"). The agent never kills the holder; a root installer
     mid-run is a human's call.
- `run_installer {name}`: the generalisation, `name` from the core list or
  a plugin's declared `host_installer`. `run_plugin_installers` (built)
  stays as "all of them".
- `restart_unit {unit}`: `unit` from the compiled expected-units list.
  Sentinel's rung 1.
- `fail2ban_reset_config`: move a hand-edited `jail.local` to a dated
  backup, write the drop-ins, restart, return the new state. The tier 2 word
  for the case the recipe refuses.

**Recipes, in build order:**

1. `fail2ban`: check the unit is active; repair `host_converge`; verify
   active and a jail count; three attempts in an hour; escalate.
2. `agent_supervision`: check the supervisor facts `restart_agent` already
   proves; repair `run_installer install_agent.sh`; this is the recipe that
   makes requirement 2 hold on a minutes clock instead of the timer's daily
   one.
3. Sentinel's rungs 1 and 2 as recipes where the check is local and the
   repair is deterministic: `php_fpm_down`, `postgres_down`,
   `disk_reclaim`, `certificate_renew`, `upgrade_half_applied`. Each is
   its own review.

## Walkthroughs

**A self-hosted box, no plane, fail2ban dies at 09:00.** 09:02 the check
loop runs recipe `fail2ban`: inactive. No hold marker. It runs
`host_converge`; the installer rewrites the jail drop-ins and starts the
service. 09:03 verify: active, sshd jail present. Ledger written; the site's
admin notice reads "fail2ban repaired 09:03". Nobody was called.

**Same box, the operator hand-edited the jail file.** The installer refuses
to overwrite a file it did not write, three times in an hour. The recipe
opens a case. Unpaired: the admin page shows the case with the three
transcripts and the superadmins get one mail. A human reads the transcript,
sees their own edit, fixes it or sets the hold.

**A paired customer node, same failure.** The case rides the 10:05 poll.
The driver asks `host_report`, then `unit_journal {fail2ban, 50}`, then
`file_head {jail.local, 40}`. It reads the duplicate-section error and the
appended block, recognises our old installer's fingerprint rather than a
customisation, and runs `fail2ban_reset_config`. `host_report` confirms.
Case closed with the note and the backup path. Had it judged the edit to be
the customer's own, the only honest word is "ask the owner", and the case
waits.

**The agent itself is broken.** Recipe two cannot run because its runner is
dead. The host timer's daily converge runs `install_agent.sh` and the agent
is back within a day; the next release brings it back the same day. If a
faster clock is wanted, that is a timer setting, not a new mechanism.

**The Docker host.** It is a node in machine posture
(`docker_host_agent.md`), and machine posture means *both* actors are on
the host: the agent with host-scoped words and recipes, and the host timer
with the host-scoped installer set. fail2ban and certbot live on the host,
so without both the host has no self-repair. `docker_host_agent.md` carries
this as an added acceptance.

## Where the bugs will live

This is new behaviour in a root process on every node, and the review
before build and the burn-in after it look for exactly these:

- **A recipe fighting an operator.** Someone stops fail2ban to work on it
  and the agent starts it again a minute later. The hold marker is the
  answer, and the recipe must log that it is held rather than silently
  skip, so the operator learns the marker exists the first time they need
  it.
- **A loop.** Repair succeeds, the fault recurs in a minute, repair
  succeeds again, forever. Every recipe carries a compiled attempt budget
  per window, and exhausting it is a case, not a fourth attempt.
- **A case storm.** One broken thing opening a case every check tick. A
  recipe holds at most one open case; a new failure while a case is open
  appends to it.
- **The check itself being expensive or wrong.** A check runs every few
  minutes on every node forever; it must be cheap, and a false positive is
  worse than a missed one because it triggers a repair. Checks read state,
  they never probe by changing anything.
- **The agent acting on a node it cannot see clearly.** A check that cannot
  answer (journal unreadable, systemd not responding) is "unknown", not
  "failed", and unknown never triggers a repair.

## Burn-in

The first agent release carrying recipes runs on our own fleet with the
recipes in **report-only** mode for one release cycle: checks run, cases
open, nothing is repaired. The ledger from that cycle is reviewed before the
repair step is armed. Report-only is a compiled constant, so arming is a
release, never a setting.

*Customer nodes one release after our fleet* is **deferred (owner,
2026-09-13)**: the agent self-updates from the plane on one channel, so
every paired node moves together the moment a release is published. The
report-only release therefore reaches every node, which is safe by
construction because nothing repairs. Whether a second channel is worth
building is decided after the burn-in ledger is read.

**Accepted risk (owner, 2026-09-13):** `host_report` tells the plane the
node's sshd posture, jails and failed units, which is reconnaissance to a
hostile plane. The accepted-limits table already grants the plane sight, and
the Host card needs it. What `host_report` never carries is the SSH
auth-failure detail: counts only, no usernames, no source addresses.

**A case is untrusted input to the plane.** It is the one thing a node pushes
at the plane on its own initiative, and a compromised node writes what it
likes into every field. Intake caps every field, the card escapes every
field, nothing in a case ever reaches a shell, a template or a link, and the
superadmin mail is plain text with no link that acts. On an unpaired box the
web user can forge the rendered copy (a lie to the admin and one mail,
nothing root acts on); the notice says "as reported by the agent's ledger".
The unpaired mail is capped at one per recipe per day; the notice stays live
throughout.

## Work packages

| WP | Scope | Ships as |
|----|-------|----------|
| WP2 | Agent: `recipes` package and registry, check loop, job lock and marker, hold marker, the two ledgers, recipe list at poll, process-group kill on script timeout; `host_report`, `host_converge`; recipe `fail2ban`, report-only. Platform: the runner's `--only` mode, the lock moved to the top with its holder recorded, the oneshot service timeout | agent + platform release |
| WP3 | Agent: the case; plane: the incident record data class (Sentinel §14.B, built here as the case store), case intake on the poll, Host card on the node page, failed-unit and open-case notices; unpaired path (admin notice + superadmin mail) | agent + platform release |
| WP4 | Words `unit_journal`, `file_head`, `restart_unit`, `run_installer`, `fail2ban_reset_config`; recipe `agent_supervision` | agent release |
| WP5 | Sentinel rungs 1 and 2 as recipes, each its own review; the driver consumes cases | agent + platform release, sequenced by `sentinel_managed_recovery.md` §15 |

`host_housekeeping.sh` (B2 of `post_release_fleet_defects.md`) ships before
WP2 so the first recipe has something to run. WP2 ships report-only first. Every WP ends with the agent's test suite and
`php tests/run.php db --changed` green, and every new word ships with its
hostile-caller review written into the Go file's header comment, the way
`restart_agent` does.

## Build order (owner-set 2026-09-13)

Slices that each ship and prove something alone, the recipe loop last. The
risk is one thing, a root process acting unasked, so everything it stands
on runs in the field before it exists. One executor, one slice at a time,
reviewed per slice, never as a whole at the end. Every word's hostile-caller
review is in the file header before the code.

1. **Platform: harden the runner.** Lock to the top of every mode, holder
   recorded, `--only=<installer>` mode, oneshot service timeout. Fixes a
   live defect (cron hosts can overlap themselves today). Gate: two runners
   started together, the second waits and runs after the first; a holder
   that outlives the wait is named. Platform release. **Built 2026-09-13,
   runner 2.16, reviewed; awaiting commit.**
2. **Agent: process-group kill on script timeout.** Every script word
   inherits it. Ships with slice 3. **Built 2026-09-14, reviewed.**
3. **Agent: `host_report` as a plain observe word.** No loop. The plane
   dispatches it as a job; the Host card renders the result. Proves the
   word, its bounds and the card with nothing acting, and reads the fleet's
   real host state before any recipe does. **Built 2026-09-14, agent
   1.25.0, reviewed; live proof after the publish.** Shape as built: the
   agent's gate allows only `script.go` to start a process, and the report
   needs systemctl, fail2ban-client, `sshd -T` and journalctl, so
   `host_report` is a script word like `run_plugin_installers`: the whole
   of what runs is `maintenance_scripts/sysadmin_tools/host_report.sh`,
   shipped in the tree and in the Docker host's support bundle, verified
   against the manifest, no argv, no stdin. The script prints one JSON
   object with a compiled set of keys; the plane rebuilds it on intake
   (`sanitise_host_report`) and stores it in `mgn_last_host_report`, its
   own column, never the status fold. This is the shape every later observe
   word that needs a process takes.
4. **Agent: `host_converge` as a plane-dispatched operate word.** No recipe.
   Run from the node page against dev, then jeremytunnell; read the
   transcript. Proves the `--only` path, the manifest check, the lock and
   the compiled constant under the job model, where every run is already
   ledgered.
5. **Agent: the recipe loop, report-only.** The loop is a state machine
   (budget, backoff, consecutive, hold, unknown, open case, busy) built as
   a package with a fake clock and fake check and repair, table-driven
   tests for every shape in "Where the bugs will live" before a real check
   is wired in. Then `fail2ban` composes slices 3 and 4. On dev the
   executor sets the `fail2ban` hold marker while editing, because the
   working tree is what a recipe on dev runs as root. Release; burn-in
   starts.
6. **WP3: the case and the incident record**, in the same release as 5 or
   the next, so the burn-in's cases have somewhere to land.
7. **Arming**, its own release, after the burn-in ledger from dev,
   jeremytunnell and docker-prod is read and written up.

Slices 3 to 5 are all Go in one repository and tempting to ship together.
They are not shipped together: 3 and 4 are the proofs 5 stands on, and only
if they ran in the field first.

## Settled questions (owner, 2026-09-13)

- **Q1, the check-loop cadence: every 10 minutes, and a recipe repairs only
  after its check fails on two consecutive ticks.** Nothing on the tier 1
  list needs minutes: a crashed unit is restarted by systemd in seconds, a
  recipe only matters once systemd has given up or the configuration is
  wrong, and those are hour-scale faults. A faster loop buys transients (an
  apt upgrade restarting php-fpm, a reboot, an operator reloading fail2ban)
  and a transient read as a fault is a repair fighting an operator. Worst
  case detection is 20 minutes. The retry budget stays three attempts in an
  hour, spaced by backoff (the recipe's second and third attempts wait 10
  and 30 minutes), never three in a row. Each recipe still carries its own
  compiled minimum interval, so an expensive check can be rarer than the
  tick; none may be more frequent.
- **Q2, `host_report` is its own word, not part of `check_status`.** They
  differ in subject, not speed: `check_status` is the site (web-root disk,
  load, Postgres, version, database list, certificate) with keys pinned to
  the management API's stats endpoint; `host_report` is the machine (units,
  jails, sshd posture, journal auth failures, reboot-required,
  unattended-upgrades). `host_report` deliberately repeats disk, memory and
  swap so a recipe reads one word per check; that overlap is by design and
  is not to be deduplicated. On a paired node the plane asks `host_report`
  as its own scheduled job on the `check_status` cadence, so the Host card
  is fresh without a case.
- **Q3, the case IS Sentinel's incident record (§14.B), built here.** WP3
  creates the incident record data class as the case store, node-scoped;
  the owner column for tenancy arrives with the Sentinel plugin's tenancy
  work as a data-class column, not a migration. A case from a recipe and an
  incident the uptime monitor opens land in one list, one node-page card and
  one notice path, and the tier 2 driver reads them with no translation.
  The shape:
  - **What the agent sends:** source (recipe name, or the unexplained-root
    classifier), a node-minted case id, the attempt ledger (time, word,
    bounded result), a fresh `host_report`, the vocabulary and recipe list.
    Every field is capped, because it rides the channel and the channel
    caps its bodies.
  - **How it rides:** the poll claim gains an optional list of open cases.
    The plane stores a new one, appends to a known id, and holds at most
    one open case per recipe per node.
  - **Who closes it:** the node. A case closes when the recipe's check
    passes again, and the next poll reports the close. A human on the plane
    writes the note and marks it read, but cannot tell the node the fault
    is gone; the node's check is the truth, which keeps the plane at "see
    and ask".
  - **Unpaired:** the agent writes a rendered copy of the record outward to
    the site's cache directory, readable by the web user the way the host
    timer's last-run file is, and never reads it back (see "Two ledgers"
    under the recipe contract); the admin notice and the one superadmin
    mail render from it.
