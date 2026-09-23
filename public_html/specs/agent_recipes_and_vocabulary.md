# The agent's vocabulary and its two composers

**Status: UNIFIED DESIGN — owner-set 2026-09-13. Every spec in the agent
family complies with this one; where an older spec disagrees, this one wins
and the older spec carries a dated note saying so.** It reverses one owner
decision (A10, 2026-08-26: "the agent never initiates work of its own") and
records the reversal below.

**BUILT 2026-09-23 (agent 1.43.0), reviewed, all findings fixed; not yet
released or proven live on a node.** Every word, recipe and work package
below is built except the items marked **Open** or **Not built**: the plugin
set in `host_report` (running list, Open) and the optional `page_probe` gate
in `staged_rollout`. The tier 1 contract it builds on is
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
| `/etc/postfix/main.cf`, `/etc/postfix/master.cf`, `/etc/opendkim.conf`, `/etc/opendmarc.conf` | mail not flowing; settings only |
| `/etc/rspamd/local.d/actions.conf`, `classifier-bayes.conf`, `milter_headers.conf`, `redis.conf`, `worker-proxy.inc` | spam filtering; the five files `install_email.sh` writes |

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
  password); `/etc/opendkim/keys/*`, `/etc/opendkim/key.table`,
  `/etc/opendkim/signing.table`, `/etc/letsencrypt/live/*`,
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
| Confirm the node's schema after a migration: the old table gone, the new column NOT NULL, a row count | Trusted the transcript's own lines | `schema_probe {table}` (observe): exists, row count, columns with type and nullability, indexes — the node's `information_schema` answer for one named table, no SQL taken from the plane. `table` matches a compiled identifier pattern and must exist in the node's own `information_schema` or the word refuses; the row count is accepted as an aggregate, as `page_probe`'s size is — **BUILT** (agent 1.43.0) |
| Which plugins are active on the node, and their versions | Inferred from the absence of a plugin-migration line | `host_report` (or `check_status`) carries the plugin set: name, version, active — the platform's own registry, read not guessed. **Open:** `check_status` carries the hourly plugin fleet report (each plugin's own checks), not this set |
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
| To be told the disk was filling before it filled | Nothing. Four days of warning sat unread in stored host reports | The plane-side notice over stored samples (floor **and** slope, § 2 of the spec) was **dropped by the owner 2026-09-22**: disk space is the operator's responsibility and a daily notice is noise. What was built is the node's own floor: recipe `disk_headroom` (check-only, `Recipe.NoRepair`: 10% or 5 GiB available, a case on the first failing check) — **BUILT** (agent 1.41.0) |

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
(review B16, B17, B20). The optional `page_probe` gate is not built.

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

All work packages are built. What remains is the release (agent 1.43.0 with
the platform) and a live proof on one node: a `page_probe`, a recipe repair,
a reset, and a staged rollout.

## Open questions

Settled by the owner 2026-09-13; recorded in `agent_tier1_recipes.md`.
