# The agent's vocabulary and its two composers

**Status: UNIFIED DESIGN — owner-set 2026-09-13. Every spec in the agent
family complies with this one; where an older spec disagrees, this one wins
and the older spec carries a dated note saying so.** It reverses one owner
decision (A10, 2026-08-26: "the agent never initiates work of its own") and
records the reversal below.

**BUILT 2026-09-23 (agent 1.43.0), reviewed, all findings fixed, released.
The running list's words, the plugin set and the `page_probe` gate in
`staged_rollout` BUILT 2026-10-14 (agent 1.76.0, host_report 1.23); their
live checks are in the verification queue.** Every word, recipe and work
package below is built. The tier 1 contract it builds on is
`implemented/agent_tier1_recipes.md`; the first installer shipped with
`post_release_fleet_defects.md` B2. The spec stays here, not in
`implemented/`, because it governs the agent family and its running list of
words keeps growing.

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
   over output whose shape is known. Configuration files have shapes of
   their own (`key = value` with spaces, `Directive value`, INI sections),
   which the log redactor's `key=value` pattern does not match. A word that
   returns configuration lines returns a line whose key is on the redactor's
   secret-key list as the key alone, whatever the separator, and the
   redactor carries that configuration shape with a test for each file the
   word may read.
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
8. **Private means user data and secrets, not configuration.** Owner-set
   2026-09-23. A word may return host configuration. It never returns user
   data (members, their mail, their files, their rows) or a secret (a key,
   a password, a token, a certificate's private half), because a secret is
   a way to reach user data. A file in `/etc` can still hold a secret:
   `postfix/joinery-domains.cf` carries a database role's password. The
   owner's consent sentence for these reads (the setting `site_log` and
   `unit_journal` sit behind) names everything the switch grants — the site
   logs, the service journal, the host configuration files — and says that
   member data and keys are never sent.
9. **Destructive means data deletion or a machine rebuilt.** Owner-set
   2026-09-23. Deleting a backup, restoring over a database, decommissioning
   a site, reinstalling the OS: these take the node-issued approval. A
   configuration change that keeps a dated backup of what it replaced is an
   operate word, with two exceptions that keep a file out of reach even so:
   a file on the mail path, where a wrong change bounces mail that is then
   gone, and a security setting an owner may have tightened (`sshd_config`,
   `sysctl`), which is reported, never reset.
10. **A recipe supplies only parameters the node derived itself.** A recipe
   may call a word that takes a parameter when the value comes from the
   node's own compiled or discovered set (its own vhost list, its own
   expected-units list, its own container list), never from the wire or a
   file. This amends `implemented/agent_tier1_recipes.md` WP4, where a
   recipe composes parameterless words only; that file is not edited.
11. **A word's contract never changes.** Its name, its parameters and what
   it does are fixed once it ships. Its output may gain fields and never
   loses or redefines one; the plane reads a missing field as "not
   reported", never as zero or false. A change to parameters or meaning is
   a new word with a new name, and the old one stays until the version floor
   (*Different agent versions across the fleet*, below) passes it.

## First words and first recipes

Named here so the vocabulary has a starting shape; built under
`agent_tier1_recipes.md` (the two words and two recipes it needs) and
`sentinel_managed_recovery.md` §15 (the rest).

**Words:** `host_report` (observe, no parameters) — **BUILT** (agent 1.25.0;
widened in host_report.sh 1.5); `unit_journal {unit, lines}`
— **BUILT** (agent 1.39.0), `disk_headroom_and_unit_diagnosis.md` § 8 — and its
counterpart `reset_failed_unit {unit}` (operate, the same list) — **BUILT** (agent
1.41.0), § 9; `file_head {file, lines}` (observe, the readable list in
*Host files* below, capped, redacted on the node, behind the same owner switch
as `site_log`) — **BUILT** (agent 1.43.0), with an optional `site` slug for a
Docker host's per-site vhosts, and the redactor's configuration shape
(`redact.Config`: a secret-named key comes back alone, a secret named later in
the line loses its value, and addresses in configuration are kept, since they
are infrastructure); `host_converge` (operate, no parameters, runs
`host_housekeeping.sh`, with a `--machine` posture on a siteless host) —
**BUILT**; `run_installer {name}` (operate, a name from
`CORE_INSTALLERS` or `plugin:NAME` for a declared `host_installer`) — **BUILT**
(agent 1.43.0; `plugin:NAME` through the runner's `--only-plugin=`); `restart_unit {unit}`
(operate, the expected-units list less `joinery-agent`: the agent is
restarted only through `restart_agent` and its proof, rule 3) — **BUILT**
(agent 1.43.0);
`restart_container {name}` (operate, the node's own container list: a
container is not a systemd unit, and `service_health` needs a repair for
one) — **BUILT** (agent 1.43.0): the node's own list is every container whose
name is its `SITENAME`, the shape install.sh creates; `reclaim_managed_file {file}` (operate,
the resettable list below: move the file to a dated backup, run the installer
that owns it, return the new state). `reclaim_managed_file` is the general
form of the `fail2ban_reset_config` named in `implemented/agent_tier1_recipes.md`;
that name is not built. `host_housekeeping.sh` already takes back a
`jail.local` our old installer broke, so the word is for a hand-edited file,
where the driver reads it with `file_head` first and asks the owner when the
edit looks deliberate. **BUILT** (agent 1.43.0; WP7): the files `install.sh`
wrote once are written by `host_housekeeping.sh` when absent, from
`_host_files.sh`, the one definition `install.sh` also uses; the site's
logrotate and cron files by a new core installer, `site_housekeeping.sh`,
which `_site_init.sh` calls; `render_vhost.sh` renders a vhost moved aside
from the dated copy, only through a one-shot marker the reclaim run leaves;
the runner gains `--only-plugin=`. Dated copies live under
`/var/lib/joinery/reclaimed` (root-only, read by no service; pruned after 180
days); the move waits for the runner lock; a removed `jail.local` restarts
fail2ban; a journal cap already set by any drop-in counts as present. A moved
file whose owner does not write it on that machine is put back (review
B6–B12).

**Recipes:** `fail2ban`, then `agent_supervision`, then `disk_headroom` — the
first check-only recipe (`Recipe.NoRepair`, a case on the first failing check,
`disk_headroom_and_unit_diagnosis.md` § 10) — all **BUILT** — then Sentinel's
rungs 1 and 2 where the check is local and the repair deterministic:
`service_health`, `container_health` and `certificate_expiry`, **BUILT**
(agent 1.43.0; *Settled 2026-09-23* below).

## Host files: what may be read and what may be reset

Owner-set 2026-09-23, under rules 8 and 9. Each list is compiled into the
agent. A file parameter is a name from the list, never a path; where a file
belongs to a site, the parameter is the site slug, validated by the compiled
pattern, and the agent builds the path. A file absent on the node is
reported absent, never guessed at.

**Readable by `file_head`:** every entry is a name, never a glob: a pattern
matches whatever an operator drops beside our files.

| File | What it diagnoses |
|---|---|
| `/etc/fail2ban/jail.local`, `/etc/fail2ban/jail.d/joinery-sshd.local`, `/etc/fail2ban/jail.d/joinery-apache.local` | fail2ban dead or not banning |
| `/etc/apache2/sites-available/{site}.conf`, `/etc/apache2/sites-available/{site}-le-ssl.conf` | a site down, the wrong certificate, proxy errors |
| `/etc/apache2/apache2.conf`, `/etc/apache2/mods-available/mpm_event.conf`, `/etc/apache2/conf-available/joinery-remoteip.conf` | Apache will not start; client addresses lost behind a proxy |
| `/etc/php/{installed version}/fpm/php.ini` | PHP-FPM errors, limits |
| `/etc/systemd/journald.conf.d/size-limit.conf`, `/etc/logrotate.d/joinery-{site}` | logs filling the disk |
| `/etc/cron.d/joinery-{site}`, `/etc/cron.d/joinery-agent`, `/etc/cron.d/certbot` | scheduled work not running |
| `/etc/apt/apt.conf.d/20auto-upgrades`, `/etc/apt/apt.conf.d/50unattended-upgrades` | patching state |
| `/etc/docker/daemon.json` | container hosts |
| `/etc/sysctl.d/99-security.conf` | hardening state |
| `/etc/postfix/main.cf`, `/etc/postfix/master.cf` | mail not flowing; settings only |
| `/etc/rspamd/local.d/actions.conf`, `classifier-bayes.conf`, `dkim_signing.conf`, `milter_headers.conf`, `rbl.conf`, `worker-proxy.inc`, `/etc/rspamd/override.d/options.inc` | mail checking, spam filtering and signing; the files `install_email.sh` writes |

**sshd is not read as a file.** Ubuntu's cloud images set
`PasswordAuthentication` in `/etc/ssh/sshd_config.d/`, so `sshd_config`
alone misreads a lockout. `host_report`'s sshd posture widens instead: the
effective settings from `sshd -T`, filtered to a compiled key list (port,
root login, password, public-key and keyboard-interactive authentication,
allowed users and groups, maximum auth tries). No file is read, so no key
material can travel. **BUILT** (host_report.sh 1.5).

**Never readable, by any word:**

- Secrets: every map `main.cf` points at, starting with
  `/etc/postfix/joinery-domains.cf` (a pgsql map holding a database role's
  password); `/etc/rspamd/dkim/*` (the signing keys and their map),
  `/etc/letsencrypt/live/*`,
  `/etc/joinery-agent/joinery-agent.env`, `config/Globalvars_site.php`.
- Access and identity: `/etc/sudoers.d/*`, `/etc/crypttab`, `/etc/passwd`,
  `/etc/shadow`, `/etc/ssh/*` beyond the `sshd -T` settings above.

The relay's recipient, transport, relay-domain and SRS maps live on the
relay, which is never agented, so no word on a node could reach them.

**Resettable by `reclaim_managed_file`:** exactly the files a re-runnable
installer in `CORE_INSTALLERS` or a declared `host_installer` writes, since
the word's repair is running that installer. Requirement 5 says install day
and repair day run the same code, so the files `install.sh` and
`_site_init.sh` write once move into re-runnable installers as part of this
word's build; until a file has moved, it is readable and not resettable.

| File | Written today by | Owner after the build |
|---|---|---|
| fail2ban `jail.d/joinery-sshd.local`, `joinery-apache.local`; `joinery-remoteip.conf` | `host_housekeeping.sh` | unchanged |
| fail2ban `jail.local` | nothing: `host_housekeeping.sh` removes the broken copy an older install left and puts our jails in `jail.d`. Reclaiming a hand-edited one moves it to a dated backup and runs `host_housekeeping.sh` | unchanged |
| `{site}.conf` | `render_vhost.sh` | unchanged |
| `/etc/cron.d/joinery-agent` | `install_agent.sh` | unchanged |
| `mpm_event.conf`, `php.ini`, journald `size-limit.conf` | `install.sh` | `host_housekeeping.sh`, which writes each when it is absent (so an owner's edit survives every converge, and moving the file aside is the reset); `php.ini` is rebuilt from the distribution's `php.ini-production` with the platform's keys |
| `logrotate.d/joinery-{site}`, `cron.d/joinery-{site}` | `_site_init.sh` | `site_housekeeping.sh`, a new core installer, written when absent (never the cron entry inside a container, whose start command owns it) |

Never reset: `apache2.conf` (owner, 2026-09-23, option A: the installers edit
Ubuntu's copy in place and nothing on the machine can rebuild it once it is
moved aside, so it is readable and a bad edit is a person's to fix),
`{site}-le-ssl.conf` (certbot writes it; `provision_certificate`
is its repair), the mail files (rule 9, mail path), `99-security.conf` and
the apt files (rule 9, security settings; the apt files are also `install.sh`
only), and `docker/daemon.json` (a change restarts every container).

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
| **2026-10-14/15, getjoinery-orgs after the 0.8.495 deploy failed its tier.** Get a node out whose deployment files no longer match the signed manifest at its site root, without a shell. The rollback had put 0.8.494's code and `VERSION` back under 0.8.495's manifest; `apply_update` was refused, and *Restore signed copy* fetched the file for the `VERSION`'s release and refused it against the manifest | A root SSH session into the container: three files copied from the preserved failed tree over the live ones | `restore_release_file` and the new `repair_release_files` ask the plane for the file **by the hash the manifest lists**, the `VERSION` a hint only; recipe `release_files` runs the repair on the node's own clock, so the state lasts twenty minutes, not a day. **BUILT** (agent 1.79.0, `release_file_repair_by_hash.md`) |
| Confirm the node's schema after a migration: the old table gone, the new column NOT NULL, a row count | Trusted the transcript's own lines | `schema_probe {table}` (observe): exists, row count, columns with type and nullability, indexes — the node's `information_schema` answer for one named table, no SQL taken from the plane. `table` matches a compiled identifier pattern and must exist in the node's own `information_schema` or the word refuses; the row count is accepted as an aggregate, as `page_probe`'s size is — **BUILT** (agent 1.43.0) |
| Which plugins are active on the node, and their versions | Inferred from the absence of a plugin-migration line | `check_status` carries the plugin set as `plugins`: name, version (from the recorded `plugin.json`), active, and `plg_status` — the platform's own registry, read not guessed; the management API's stats endpoint carries the same list — **BUILT** (agent 1.76.0) |
| The site's error log for the minutes after the swap | Nothing | `site_log {file, previous, lines}` (observe): the last N lines of one of the site's own log files from a compiled list, capped, redacted on the node — **BUILT** (agent 1.35.0), `agent_log_access.md` |
| A deploy result to read rather than a transcript to grep | Grepped 70 KB of routing debug for six lines | `apply_update` posts a structured result beside the transcript: version before and after, each migration run with its row counts, schema changes, deploy-tier verdict, rollback yes/no. The transcript stays for forensics. Field list in *Settled 2026-09-23* below; `staged_rollout` depends on it — **BUILT** |
| The site's own log tables after the swap: the last logins, request log rows, event log rows and webhook rows | Nothing | `log_table_tail {table, rows}` (observe): the newest N rows of one log table from a compiled list (`log_logins`, `rql_request_logs`, `evl_event_logs`, `wbh_webhook_logs`, `lfe_log_form_errors`), compiled column list per table, rows capped; the node's own query, no SQL taken from the plane. With `site_log`, gated by one owner-set switch on the node, on by default, redacted on the node — **BUILT** (agent 1.35.0), `agent_log_access.md` |
| The affected pages rendered on the node as a signed-in user | Only `/` and `/login` from outside; the pages were checked on dev with a throwaway superadmin | `page_probe {page, viewer}` (observe) — **BUILT** (agent 1.43.0), see *Settled 2026-09-23* below |
| Roll a release across the fleet in risk order, one node at a time, stopping at the first problem | Queued `apply_update` by hand per node from a script, waited on each job, grepped each transcript, queued the next | Not a node word — the node has `apply_update`. A **tier 2 recipe on the plane**, `staged_rollout {release, order}`: an ordered node list, one `apply_update` at a time, a gate between them read from the structured result above (completed, deploy tier green, version reported, no rollback), halt on the first miss and say which node and why. "Apply update to all on host" is its unordered ancestor — **BUILT**, see *Settled 2026-09-23* below |

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

**2026-10-06, removing getjoinery's old container from docker-prod.** The removal
reached for the volumes of two sibling sites (getjoinery_developers_*, getjoinery_orgs_*)
by a name-prefix match; remove_account.sh 2.10 fixed it and now removes only a container
install.sh made. Deciding that rule needed each container's image and mounts, and whether
the host keeps run specs, so the operator opened a read-only SSH session to docker-prod.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Each container's image, its mounts (type, volume name), and whether its run spec exists | Read-only SSH: `docker inspect` per container, `ls /etc/joinery/sites` | `container_inventory` (observe, machine, no parameters): every container on the machine with `install_sh` (its name is its `SITENAME`), `image`, `state`, `mounts` (type and volume name, never a bind source path) and `run_spec` (present or not), so the plane can show a container that install.sh did not make before anyone removes it. Its own word, not keys in `host_report`: a host's hundred sites already fill most of the agent's 64 KiB there — **BUILT** (agent 1.76.0) |
| To be told the disk was filling before it filled | Nothing. Four days of warning sat unread in stored host reports | The plane-side notice over stored samples (floor **and** slope, § 2 of the spec) was **dropped by the owner 2026-09-22**: disk space is the operator's responsibility and a daily notice is noise. What was built is the node's own floor: recipe `disk_headroom` (check-only, `Recipe.NoRepair`: 10% or 5 GiB available, a case on the first failing check) — **BUILT** (agent 1.41.0) |
| Whether a user's Mark as spam reached the spam filter on jeremytunnell (10-03) — the correction rows and the size of the learned corpus | Read-only SSH and a PHP query against `iem_inbound_email_messages` and `ibt_inbound_bayes_tokens`; the cron log only said Taught 0, which cannot tell a correction already taught apart from one never recorded | `spam_learning_status` (observe, no parameters): counts only, never a subject or address — corrections recorded, taught, pending clear, pending sealed, skipped as IMAP-polled in the last 7 days, corpus token count and newest token time, and the two policy switches — **BUILT** (agent 1.76.0; `SpamLearning::status()`) |

**2026-10-10, the data root moves (one_data_root.md WP4), joinerydemo first.** The move
ran through `data_root_migrate`, and its last step is a reboot: the old copies stay
until the host has booted with the data root working, and the reboot is what proves
the mounts come up on their own. Nothing on the plane can reboot a machine, and every
production host already showed "Reboot required" for 1 to 7 days.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| To reboot a host after a data root move, and to act on "Reboot required" at all | The owner rebooted from Linode's Cloud Manager; on a server outside our accounts (jeremytunnell-vps, the owner's own Linode) only its owner can | `reboot_machine` (operate, machine, no parameters): schedules the reboot a few seconds out so the job reports first, refuses inside a site container; a node page button behind a confirm beside "Reboot required"; the plane notes the boot id before and sees it change — **BUILT** (agent 1.76.0): twenty seconds out on a transient timer; also refuses where the boot would wait for a recovery key (an encrypted data root with no escrow holder, or mid-run), while dpkg or apt runs, and while the host's installers hold their lock; `host_report` carries `boot_id` |

**2026-10-12, mail apps on a relay-fronted test site (mailapps-walk.joinerytest.com).**
Allow mail apps failed with "the gateway did not start listening on 993 and 465" while
the gateway's own journal said it was listening on `[::]:993`. The cause (no IPv4
listener) was found by reading the gateway source and reproducing it on dev; the node
could not say which addresses its services were bound to.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Which addresses and ports the machine is listening on, and which program holds each | Read the source and reproduced it on dev | `listening_sockets` (observe, machine, no parameters): the `ss -ltnp` answer as rows of address, port, family and unit or program name, capped; no remote addresses, no connections — **BUILT** (agent 1.76.0; bound UDP sockets too) |

**2026-10-14, the 0.8.494 check on an encrypted data root (node-n, TEST plane).** 0.8.494
changed `joinery_data_root.sh` to 1.22 (`podman-restart.service` joined its consumers). The
check proved the upgrade and an escrow-opened reboot through the plane, but two of its facts
were read over SSH, read-only.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Which version of the data root script the machine runs (`/usr/local/sbin/joinery-data-root`, the copy a run's unit starts, which the tick brings up to the release's) | Read its `# Version:` line over SSH | `host_report`'s `data_root_script` (not `data_root_encryption`: a plain data root has the script too): the installed copy's version beside the release's own, so a node page can say when they differ — **BUILT** (host_report 1.23) |
| Which consumer drop-ins the data root wrote, and whether each names a unit installed here | Listed `/etc/systemd/system/*.service.d` over SSH | `host_report`'s `data_root_script.consumers`: for each unit in the script's `CONSUMERS` (a closed list), drop-in present and unit installed — **BUILT** (host_report 1.23) |

**2026-10-14, the 0.8.494 sandboxed plugin check (plugin_tiers.md) on two TEST boxes, sbxbm
(bare metal) and sbxct (a site container).** A sandboxed plugin was uploaded, approved and
removed on each. The host's own state could not be read: the container's keepalive held the
converger's lock (fixed in install_sandbox_host.sh 1.2), found only from the converger's log,
and the restore check needed a shell on the box, which a TEST box no longer gives.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Whether the sandboxed plugin host's unit is active, stopped or gone, and its last journal lines | Inferred from the plugin's own answers and from its files disappearing | `unit_journal` gains the per-site unit family `joinery-sandbox-host-*` (closed: the units install_sandbox_host.sh names) |
| The host's keepalive log in a site container (`/var/log/joinery-sandbox-host-{site}.log`) and whether its cron file exists | Nothing | `site_log` gains the host log, and `host_report` carries the sandbox host's mode (systemd, cron, none), its version and whether it answers `/health` |
| The converger's own journal on a bare-metal host (which run holds the lock, since when) | Read the container's converger log only; on bare metal nothing | `unit_journal` gains the converger's unit |
| Restore one site's dump onto another site with a different `secret_box_key`, to see `RESTORE_SECRET_KEY_MISMATCH` | Proved on dev instead (tests/backups/restore_secret_key_gate.sh: the script's own check, read-only, plus a canary that does not open) | None needed for the platform; the end-to-end proof waits for a box the operator can reach |

**2026-10-14, the data root's own mount point locked underneath it (`joinery_data_root.sh`
1.24, site_container_engine WP3 review R2), landed on dev.** The first tick of a boot locks
`/srv/joinery` underneath the mount, or names the files it holds. Whether it did was
answered only because dev is the machine this session runs on: the tick's message went to
the journal, which a non-root session cannot read, and the plane has no word for either.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Whether the data root's mount point is locked underneath it, or holds files written while it was not mounted | Read `/run/joinery/data-root.mountpoint-seen` on dev (the tick records `locked`, `mode-only`, `holds-files`, `failed` or `unreachable` there) | `host_report`'s `data_root_script` carries `mountpoint`: that file's word, or none yet this boot, so the node page can say when a host's data root is not locked underneath |

**2026-10-22, the 0.8.517 rollout halted at scrolldaddy (job 6747, lost).** The plane said
only that the agent came back idle. The answer was that docker-prod, scrolldaddy's host, got
a power-button press from Linode at 15:58:32 UTC, two minutes into the apply; the upgrade had
staged 0.8.517 and stopped before the swap, so the site stayed on 0.8.516, clean. Both facts
were read over read-only SSH.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| The agent's own log in a site container around a lost job (`/var/log/joinery-agent.log`: the last line before it died, the restart) | Read-only SSH, `docker exec` and grep | `site_log` gains the agent's log file where the agent runs under the container keepalive; `unit_journal`'s `joinery-agent` already covers a systemd host |
| Whether and why the machine restarted under a job: when it booted, and how the previous boot ended (power button from the provider, `systemctl reboot`, or no clean end at all, which is a crash) | Read-only SSH: `last -x` and the previous boot's journal on docker-prod | `host_report` carries `last_boot`: boot time and the previous boot's end as one word from a closed list (`power_key`, `reboot`, `poweroff`, `none`). The plane then names it in a lost job's message when the node, or a container's host, booted after the job started: "docker-prod restarted at 15:59 (power button)" instead of "the agent or its machine restarted" |

**2026-10-22, the site copy final test's C5 (a move by DNS onto a Hetzner server this
management node made; root login off there).** The test asks that, while the old server
forwards, a visitor sent to the old address reaches the copy and the copy records the
visitor's own address. Reaching it was proven: a sign-in pinned to the old IPv4 landed in
the copy's `log_logins`. The address it recorded could not be read, by design:
`log_table_tail` leaves out `log_ip` and `site_log` masks every IP (`agent_log_access.md`).

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Whether a request sent through the old server reached the copy carrying the visitor's own address, not the old server's | Proven on brought servers over SSH (C4, C4b); on a plane-made copy, only that the request arrived | `copy_probes` takes a one-time path token and the address the plane sent it from, and answers whether the copy's access log holds a request for that token from that address: yes or no, no address leaving the machine |

**2026-10-22, the 0.8.520 live check of fleet_security_patching WP10.** The new
`outbound_mail` check failed on galactictribune and joinerydemo ("Mailgun is not set up").
Whether a fallback service would still send their mail could not be read from here.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Which mail service a site names, and its fallback (`email_service`, `email_fallback_service`): the names only, never a key | Read-only SSH to docker-prod, `docker exec` and the site's own PHP (galactictribune: Mailgun with no key, fallback SMTP with no host). joinerydemo has no SSH, so it stays unknown | Covered by the fix instead: the check itself names the service and the fallback (PluginProvisioning 1.5). A general `site_settings` observe word would take a closed list of non-secret setting names |

**2026-10-22, migration 220 after 0.8.521 (getjoinery, getjoinery-orgs, getjoinery-developers).**
220 gave the shared grm_group_members ids their own; the table's missing primary key could
then be added, but only by update_database's upgrade pass, which runs in an apply. getjoinery
(the management node) ran it from its own Utilities page. The other two had no way short of
a whole release.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| To run the site's schema pass (`update_database --upgrade`) on a node without redeploying its release | Re-ran `apply_update` at the version the node already had (jobs 7493, 7496): a full redeploy of 0.8.521, its swap window included, for one `ALTER TABLE ... ADD PRIMARY KEY` | `update_database` (operate, site, no parameters): runs the site's own `utils/update_database.php --upgrade` as an apply does and posts its `UPDATE_DATABASE_RESULT` as the structured result |

**2026-10-22, the site copy final test's C6 (an IP swap onto a Hetzner server this
management node made; root login off there).** After the swap's power cycle, and again on
the second copy, the copy's host report named failed units (`cloud-final`,
`cloud-init-main`, `cloud-init-network`) and opened a failed-units incident. The node page
offered no Why? or Clear for them: `unit_journal` and `reset_failed_unit` read only their
compiled list, which has no cloud-init unit. With root login off, nothing could say why.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Why a cloud-init unit failed on a plane-made server, and to clear it once understood | Nothing: the incident stays open unexplained (copy-final's B46) | `unit_journal` and `reset_failed_unit` gain `cloud-init-local`, `cloud-init-network`, `cloud-init-main`, `cloud-init` and `cloud-final` (the three copies of the list: agent, `unit_journal.sh`, the plane) |

**2026-10-23, jeremytunnell.com hanging behind Cloudflare.** All five rows below BUILT 2026-10-23 (agent 1.93.0, `slow_request_diagnosis_words.md`). The owner's unstar, calendar
decline and email open hung for up to 90 seconds; each write landed, the reply never came
back. From dev, 40 requests at once down each path: through Cloudflare 5 stalled (31 to
90 s, 520 and 408), getjoinery through Cloudflare 0, the same site pinned to its own
address 0. Apache logged `AH01075 ... (reading input brigade)` timeouts: the request
reached Apache and its body never followed. `host_report` showed a healthy machine.
Whether the kernel was dropping connections (a full accept queue, a full connection
tracking table) could not be read: an SSH attempt with the wrong key got dev's address
banned by the sshd jail.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| Whether the kernel is dropping connections: listen-queue overflows, SYN drops, backlog drops, retransmits and timeouts, connection-tracking count against its maximum | Nothing; the question is still open | `net_health` (observe, machine, no parameters): the TCP counters from `/proc/net/netstat` and `/proc/net/snmp` (`ListenOverflows`, `ListenDrops`, `TCPBacklogDrop`, `TCPTimeouts`, `RetransSegs`, `TCPAbortOnTimeout`) read twice ten seconds apart so the answer is a rate, plus `nf_conntrack_count` and `_max`, plus connection counts by state on the web ports. Counts only, no remote address. `kernel_events_24h` in `host_report` gains `conntrack_full` and `syn_flood` (the kernel's "table full, dropping packet" and "Possible SYN flooding") |
| Whether the web tier was saturated: Apache workers busy or idle, PHP-FPM active, idle, listen queue, max children reached | Inferred from a fast direct request at the same moment | `web_workers` (observe, machine, no parameters): Apache's `server-status?auto` scoreboard over loopback and each PHP-FPM pool's status page (active, idle, listen queue, max listen queue, max children reached, slow requests). Counts only; no request line, no client |
| Which requests were slow on the server, as opposed to slow in transit | Nothing: `rql_response_ms` was null on every one of the 171 API rows read, because only some callers of `RequestLogger` pass it | Not a word. The API front door records `rql_response_ms` on every request, so `log_table_tail requests` answers it. `RequestTooLarge` stops being the label for a body that never arrived (Content-Length 2 read as "exceeds post_max_size 32M"): a short read is its own error type |
| The same request timed through the site's public name and pinned to its own address, side by side, at the same moment | A shell loop of `curl` from dev, 40 pairs at a time, read by eye | A plane-side probe, not a node word: `edge_vs_origin {node}` sends N matched pairs (public name, and `--resolve` to the node's recorded addresses, IPv4 and IPv6) and reports each path's p50, p95, and stall count over 5 s. A Check Status button can then say "slow through the edge, fast direct" in one line |
| The node's IPv6 address, to test the second origin path an edge may use | Nothing; it is not on the node row | `host_report` carries the machine's global addresses (IPv4 and IPv6), so the plane can probe both |

## Settled 2026-09-23

Owner-set. Each is built under its own spec; this fixes its shape.

### Sentinel rungs 1–2 as tier 1 recipes

Two recipes, both under the tier 1 contract (check, repair, verify, retry,
hold, case) — **BUILT** (agent 1.43.0), as three: a recipe is one check word
and one repair word, so the container half of `service_health` is its own
recipe, **`container_health`** (repair `restart_container`). The check reads
three `host_report` fields added for it (rule 11: added, never changed):
`answers` (Apache and PHP-FPM by one request for the site's own name, sent to
the address its vhost is bound to, that must come back through PHP; PostgreSQL
by `pg_isready`, the credential-free form of `SELECT 1`, since the site's
config is a secret), `containers`, and `served_certificates`.

- **`service_health`**: php-fpm, PostgreSQL, Apache, and on a container host
  each Joinery container. The check is "answers", not "running": a PHP
  request through FPM, a `SELECT 1`, an HTTP request to the loopback, the
  container's health state. systemd already restarts a crashed unit, so the
  recipe exists for a unit that runs and does not answer, or that systemd
  has given up on. Repair is `restart_unit {unit}` for a unit and
  `restart_container {name}` for a container, the value drawn from the
  node's own expected-units or container list (rule 10); one that fails its
  check after a restart opens a case. The agent itself stays
  `agent_supervision`.
- **`certificate_expiry`**: each site's served certificate, read over the
  loopback. Fewer than 14 days left means certbot's own timer has failed;
  repair is `provision_certificate` for that site, the domain taken from the
  node's own vhost list (rule 10), verify by reading the
  served certificate again, a case when renewal fails.

Two rung 2 repairs are **not** recipes. Reclaiming disk: a full disk is the
operator's responsibility (owner, 2026-09-22), and `disk_headroom` opens a
case and stops. Finishing a half-applied upgrade: `upgrade.php` re-runs
itself after a self-update and a deploy that fails its tier rolls back, so
the wedge the rung was written for does not occur.

### `page_probe {page, viewer}` (observe)

**BUILT** (agent 1.43.0; platform `utils/page_probe.php`, `includes/PageProbe.php`,
`PageProbeGrant`). The `admin` viewer is a throwaway user at permission 10, so
every admin page renders. View-only is enforced at the database layer: once a
probe request is claimed, every statement that writes throws unless it is the
server's own (`server_initiated_write`), and the refusal is in the report as
`refused_write` at its `file:line` (review B3; the platform's GET tripwire
only logs). A failing asset path is named only when the release ships that
file, else counted (B4); the body is held to 4 MiB (B21); the request-log
row is marked `page_probe` (B5). The HTML is read with patterns, never a C
parser, because it carries what members wrote and the probe runs as root
(`specs/parser_jail.md`), so the structure hash is over the tag sequence.

The node renders one page as a throwaway viewer and reports facts about the
render, never the render.

- **`page`**: a page the node itself serves: its admin menu entries and its
  public views, with no query string. A name not on the node's own list is
  refused. Every request writes its own request-log row, and may write an
  error row or a login row; those are the writes of the probe's own session
  and are accepted. What is refused is a page whose view or logic is itself
  a handler that acts on arrival (the OAuth callback, a payment gateway
  return, setup wizard steps), named in a compiled list on the platform. The
  write-on-GET enumeration in `tests/unit/core_api_mechanical_test.php` is
  by file and includes the request logger, so it cannot serve as that list;
  the new list is pinned by a test of its own the same way.
- **`viewer`**: `anonymous`, `member` or `admin`, from that closed set. The
  member and admin viewers are throwaway users the platform mints for the
  probe and own no data. They are a session type of their own, which does
  not exist yet and is built with this word: short-lived, view-only, and
  logged as a probe, never a borrowed account.
- **Lifecycle, inside the one word:** create the user, open the session,
  render, close the session, **permanently** delete the user (user deletion
  is soft by default; a probe leaves no soft-deleted row), and the rows that
  hang off it. A render as a member may itself write rows (analytics
  events, cart state, a last-seen time); the build lists each such write
  and either suppresses it for a probe session or deletes it with the user.
  A probe that cannot finish its cleanup says so in its result.
- **Returns:** HTTP status; bytes; render time; database query count; peak
  memory; the PHP warnings and errors raised during that render as type and
  `file:line` only; theme and plugin static assets the page references that
  fail to load; a landmark check (header, main, footer present; count of
  forms); and a structure hash (the tag tree with all text and attribute
  values removed), so the same page before and after a deploy can be
  compared.
- **Never returns:** page text, HTML, form values, the text of a warning (it
  can carry a row value), or the address of an uploaded file.
- Size, query count and memory on an admin page loosely track how many rows
  the site holds. That aggregate is accepted; no row crosses.

### `staged_rollout {release, order}` (tier 2, on the plane)

**BUILT** (`/admin/server_manager/rollouts`, `StagedRollout`,
`StagedRolloutRunner`, task *Advance Staged Rollouts*). The release is the
newest *published* version, the one a node's apply pulls; one mover at a time
under an advisory lock; a node whose apply has not finished in 90 minutes
halts the rollout by name, and the nodes after a halt or stop read skipped
(review B16, B17, B20). The optional `page_probe` gate is **BUILT**
(2026-10-14): up to five pages, rendered on each node before its apply and
again after it passes, halting where a page got worse (an error status it did
not answer, more PHP errors, an asset it no longer loads).

Not a node word: each step is the node's existing `apply_update`. The plane
takes a release and an ordered node list, applies to one node at a time, and
moves on only when that node's structured apply result (the running-list row
above) says: job completed, deploy tier green, the new version reported, no
rollback. `page_probe` over a chosen set of pages may be added to the gate.
The first miss halts the rollout and names the node and the reason. It runs
for minutes per node, so it is a tracked record: it survives a reload, shows
where it is, and can be stopped between nodes. It depends on the structured
apply result; built before that, it would be grepping transcripts.

### The structured apply result

**BUILT**: `upgrade.php` ends every CLI run with one `APPLY_RESULT:` line;
`update_database.php --upgrade` feeds it an `UPDATE_DATABASE_RESULT:` line and
`sync_extensions.php` each plugin's version before and after. The line is
held under 24 KiB (lists cut from the end, full counts kept, `truncated`
said) so it survives the agent's output tail (review B19).

`apply_update` posts, beside its transcript, one bounded JSON object:

- `version_before`, `version_after`, and whether `upgrade.php` re-ran itself
  after a self-update;
- `migrations`: each one run, in order, with its outcome and rows affected;
- `schema_changes`: each table and column `update_database` created or
  altered, core and plugin;
- `plugins`: each plugin synced, with its version before and after;
- `deploy_tier`: passed or failed, with the name of each failed test;
- `rolled_back`: yes or no, and when yes, the step that triggered it and
  whether the schema was left ahead of the code;
- `duration_seconds`.

It is what a person or `staged_rollout` reads; the transcript stays for
forensics. It carries no row data: counts and names only.

## Different agent versions across the fleet

Owner-set 2026-09-23. On a node with a site, the agent arrives inside the
platform release, so a node that is not upgraded keeps its agent. Spread
comes from customers who defer, self-hosted nodes on their own schedule,
and a node that refused a version that failed to start. It runs both ways:
a customer node can be newer than the self-hosted management node it
reports to.

What already holds, and stays: the plane routes by the words and recipes a
node reports at poll, never by its version number (rule 7,
`mgn_agent_primitives`, `mgn_agent_recipes`); a node refuses a word it does
not know; a signed update that fails to start is rolled back and refused
until a newer release. Added:

1. **Contracts never change** (rule 11). This is what makes routing by name
   sufficient: a name means one thing on every version that reports it.
2. **A plane feature declares the words it needs.** A feature built from
   several words (`staged_rollout`, the Host card, a `page_probe` gate)
   names them in one place, and on a node that does not report all of them
   it shows one standard state — "needs a newer agent; update this node" —
   never an error, a blank, or a half-rendered card.
3. **A version floor.** The plane holds one minimum supported agent version.
   A node below it is offered `apply_update` and nothing else, and is
   flagged on the node list. The floor rises deliberately, in a release;
   each rise deletes the compatibility code for the versions it passes,
   starting with `JobCommandBuilder::PRIMITIVE_MIN_AGENT_VERSION` and the
   fallback for agents that report no vocabulary.
   **The first floor is the agent release that completes this spec's
   build** (owner, 2026-09-23): **1.43.0**.
4. **Both directions are tested.** A test runs the plane's intake and
   routing against a recorded older agent's poll, and a newer agent's poll
   (unknown words, unknown fields) against the plane, so a release that
   breaks either direction fails before it ships.
5. **The spread is visible.** The node list shows each node's agent version,
   how far behind the newest it is, and whether it is below the floor.
   `staged_rollout` is how the spread is closed.
6. **A newer agent keeps its report.** The claim intake refused any field it
   did not know, and the agent's answer was to drop every capability field,
   its vocabulary included, so a node newer than its management node could be
   routed nothing (found while building this, 2026-09-23). The intake now sets
   an unknown claim field aside unread (`AgentChannelEndpoint::known_claim_fields`),
   and against an older management node the agent drops only the field the
   refusal names.

**BUILT** (WP6): the floor is `AgentVocabulary::FLOOR` = **1.43.0**, a
constant rather than a setting, because the code for the versions below it is
deleted and an operator lowering it would route jobs to agents the plane no
longer speaks to. Deleted with it: `PRIMITIVE_MIN_AGENT_VERSION`, the
no-vocabulary fallback, and three per-value floors it passes
(`SITE_LOG_POSTGRES_`, `BACKUP_RUN_OBJECTS_`, `VERIFY_BACKUP_OBJECTS_MIN_AGENT_VERSION`).
The two-direction test is `plugins/server_manager/tests/agent_version_spread_test.php`
with the agent's `vocabulary_test.go`.

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
| WP1 | **BUILT.** `host_housekeeping.sh` in `CORE_INSTALLERS`; installer stops writing the broken jail file; Apache jails behind `mod_remoteip` in proxy mode | `post_release_fleet_defects.md` B2 |
| WP2–WP5 | **BUILT.** Recipes package, check loop, case, first words and recipes (`implemented/agent_tier1_recipes.md`); this spec's words and recipes, and Sentinel rungs 1–2 as recipes, 2026-09-23 | `agent_tier1_recipes.md`, this spec |
| WP7 | **BUILT** 2026-09-23. `reclaim_managed_file`: the files `install.sh` and `_site_init.sh` write once move into re-runnable installers (see *Host files*), the runner gains `--only-plugin=` for `run_installer plugin:NAME`, then the word. Needs the host timer stopped on dev while installers are edited. `apache2.conf` stays readable only (owner, option A) | this spec |
| WP6 | **BUILT** 2026-09-23. Version spread: the plane's declared-words helper and its standard "needs a newer agent" state; the version floor (`AgentVocabulary::FLOOR` = 1.43.0, a constant; node-list flag; `apply_update`-only below it), with `PRIMITIVE_MIN_AGENT_VERSION` and the no-vocabulary fallback deleted; the two-direction channel test; version spread on the node list | this spec, last |

All work packages are built and released. A `page_probe` is proven live
(jeremytunnell, job 96242); a recipe repair, a reset and a staged rollout
(with and without pages) wait in the verification queue.

## Open questions

Settled by the owner 2026-09-13; recorded in `agent_tier1_recipes.md`.

**2026-10-22, the 0.8.521 live check of fleet_security_patching (B24, dev).** Dev's host
converger read `installer-failed` on every run, and which installer failed could not be seen:
its transcript file stopped being written on 10-13, its journal is root's, and `unit_journal`
does not carry `joinery-host-converger`.

| Wanted | Done instead | Word or recipe |
|---|---|---|
| The host converger's last run: its outcome, and the warn lines of the installer that failed (`joinery-host-converger`'s journal, or the converger keeping its own last transcript under `cache/`) | Read the installers' code: housekeeping's security check refuses a tree not root's alone and fails the run; proven when the fix made the next converge read `converged` | Add `joinery-host-converger` to `unit_journal`'s compiled list (and `UNIT_JOURNAL_UNITS` on the plane), or have the converger keep its last run's transcript where `host_report` can carry its last failure |
