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
  `CORE_INSTALLERS`.
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
repair step is armed. Customer nodes get recipes one release after our fleet
does.

## Work packages

| WP | Scope | Ships as |
|----|-------|----------|
| WP2 | Agent: `recipes` package and registry, check loop, hold marker, ledger, recipe list at poll; `host_report`, `host_converge`; recipe `fail2ban` | agent release |
| WP3 | Agent: the case; plane: case intake on the poll, Host card on the node page, failed-unit and open-case notices; unpaired path (admin notice + superadmin mail) | agent + platform release |
| WP4 | Words `unit_journal`, `file_head`, `restart_unit`, `run_installer`, `fail2ban_reset_config`; recipe `agent_supervision` | agent release |
| WP5 | Sentinel rungs 1 and 2 as recipes, each its own review; the driver consumes cases | agent + platform release, sequenced by `sentinel_managed_recovery.md` §15 |

`host_housekeeping.sh` (B2 of `post_release_fleet_defects.md`) ships before
WP2 so the first recipe has something to run. WP2 ships report-only first. Every WP ends with the agent's test suite and
`php tests/run.php db --changed` green, and every new word ships with its
hostile-caller review written into the Go file's header comment, the way
`restart_agent` does.

## Open questions

- Q1: the check-loop cadence. Recommendation: every 2 minutes, with each
  recipe carrying its own minimum interval so a cheap check can be frequent
  and an expensive one rare.
- Q2: does `host_report` also ride inside `check_status`? Recommendation:
  no; it must answer when `check_status` cannot, because the site being
  down is when it matters.
- Q3: the case format. Recommendation: reuse the incident record data class
  Sentinel §14.B defines, so a case and an incident are one thing seen from
  two sides.
