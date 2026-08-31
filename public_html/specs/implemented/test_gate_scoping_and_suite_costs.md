# Test Gate Scoping and Suite Costs

Status: BUILT 2026-08-31 (both parts, same day as written; contract-carries-files
check lives in changed_selection_test rather than harness_contract_test).
Written 2026-08-31 from a profile of the gate run on 2026-08-30 (349 suites). Two parts, independent of each other: run only the
suites a change can reach (`--changed`), and stop four suites from doing real
work that proves nothing.

## Problem

`php tests/run.php db` is the pre-checkin and pre-publish gate. It takes 8–10
minutes on the dev box and is run after most changes, so it is where the day
goes. Measured on 2026-08-30:

| | suites | serial time |
|---|---|---|
| declared `safe` (runs inside every `db` run) | 150 | 83s |
| declared `db` | 190 | 424s |
| `test-db` lane (hidden alongside the batch) | 9 | 32s |
| **wall clock** | 349 | **~8.5 min** |

Where the seconds are: 217 suites finish under a second (57s together); 103
suites take 1–5s (239s); 18 suites take 5s or more (210s). On 2026-08-03 the
same gate was 221 suites in 256s — it doubled in four weeks.

The box makes it worse: 2 CPUs, 3.9 GB RAM, ~500 MB free, load average 2.8
before a gate starts (five Claude sessions hold ~2.1 GB). Under that
contention the same suite ran 60% slower within one afternoon (`booking_flow`
18.1s vs 11.2s).

`docs/testing.md` and CLAUDE.md still quote "79 safe tests / 20s, 221 tests /
four minutes". `safe` alone is 83s now.

Two things are true at once and the fix has to address both:

1. **Most of the gate is irrelevant to any given change.** Of the last 60
   commits, 23 touched only one plugin. A mailbox edit does not need
   `restore_roundtrip`, `booking_flow`, or the drive suites — but nothing
   today can say so, so every run pays for everything.
2. **Four suites spend their time on real waits or real work that the check
   does not need** (details in Part 2). Together with Argon2 at production
   cost inside test processes, that is roughly 90 seconds of the 505.

A parallel runner was proposed and rejected on 2026-08-03 (audit-rot against
gate trust) and is not proposed here; on a 2-core box at load 2.8 it would not
deliver anyway.

## Part 1 — `--changed`: run what the change can reach

### What it does for the user

```
php tests/run.php --changed          # the working loop: safe suites that reach an edited file
php tests/run.php db --changed       # pre-checkin: db-tier suites that reach an edited file
php tests/run.php db                 # pre-publish: everything, unchanged
```

The runner looks at what is edited (`git status`: staged, unstaged, and
untracked), works out which suites load any of those files, and runs only
those. A mailbox-only edit runs the mailbox suites plus any core suite that
happens to load the edited file; a change to `includes/VaultCrypto.php` runs
every suite that loads `VaultCrypto.php` and nothing else. A docs-only edit
runs nothing and says so.

The full gate stays the pre-publish gate. `--changed` narrows a run; it never
changes what "the gate" means.

### How a suite knows what it reaches

Every PHP suite already loads, through the class autoloader and `require`,
exactly the code it exercises. `harness_emit_json()` (`tests/lib/harness.php`)
adds one field to the result contract:

```
files: [ "public_html/includes/VaultCrypto.php", ... ]
```

— `get_included_files()` at finish, made repo-relative, limited to paths
inside the repository, `vendor/` excluded. This costs nothing to produce.

A suite that reaches things it does not `include` — a JSON manifest it reads,
a shell script it runs, a JS asset it round-trips — declares them in its
header with a new key, globs relative to the repo root:

```
 * covers: [public_html/plugins/*/plugin.json, maintenance_scripts/sysadmin_tools/backup_*.sh]
```

Shell gates have no include list, so for them `covers:` is the whole
declaration. The three Rust gates (`sync_sim`, `sync_engine`,
`sync_cross_build`) declare `covers: [sync/**]`; `sync_crypto_parity` declares
`sync/**` plus the two JS crypto files it checks parity against.
`harness_parse_metadata()` learns the key; it is optional and defaults to
empty.

### The coverage map

After every run, the runner merges each finished suite's `files` and `covers`
into `{site root}/cache/test_coverage_map.json` (the `cache/` directory is
gitignored and already holds `class_map.php`, written the same way — temp
file then rename). One entry per suite path:

```
"public_html/tests/vault/vault_ceremonies_test.php": {
  "name": "vault_ceremonies", "recorded_at": "2026-08-31T14:00:00Z",
  "files": [...], "covers": [...]
}
```

Every run of a suite — scoped or full — refreshes that suite's entry, so the
map is never older than the last time each suite ran. A full `db` run
refreshes all of it. The map is per box; a fresh checkout has none, which is
handled by the first rule below.

### Selection rules

Selection happens after the existing tier, env, and `needs` gates, inside
whichever batch was requested. A suite in the batch runs when any of these
holds, and the summary names which rule selected it:

1. **No map entry for it** — a suite that has never run here, or was added
   since the last run. Unknown means run, never skip.
2. **Its own file changed**, or a file under any `fixtures/` directory in its
   area changed (`tests/X/fixtures/` → the suites under `tests/X/`;
   `tests/fixtures/` → every suite).
3. **Its recorded `files` or its `covers` globs intersect the changed set.**
4. **It drives the web server** (`needs` includes `dev-web`) **and a core
   directory or its own plugin changed.** Those suites make their requests in
   Apache, so the server-side code they reach is not in their process's
   include list. Core directories: `public_html/{includes,data,logic,views,
   adm,api,ajax,theme}` and `serve.php`.

And two rules that override the selection:

5. **The harness or the runner changed** (`public_html/tests/lib/**`,
   `tests/run.php`) → the whole batch runs, and the summary says why.
6. **`--changed` is refused** for the `live` and `deploy` tiers, when `git` is
   absent, or when the tree is not a repository. A deployed node has no
   repository and never uses this; `utils/upgrade.php` is untouched.

The test-db lane is selected by the same rules. `models_crud` records every
data class, so any `data/` change runs it, which is right.

`--changed=<ref>` adds `git diff --name-only <ref>` to the changed set, for
"everything since I branched" (`--changed=origin/main`). Renames contribute
both sides.

### What the summary shows

```
Changed: 4 files → 11 of 190 db suites selected (map: 338 entries, oldest 2026-08-30)
  mailbox_level_scope        files: plugins/mailbox/includes/MailboxScope.php
  models_crud                files: data/mailboxes_class.php  [test-db]
  ...
Uncovered — no suite reaches these:
  public_html/plugins/mailbox/assets/js/compose.js
```

The uncovered list is the useful by-product: a changed code file that no suite
loads is a coverage gap, named at the moment someone is looking. Markdown,
`specs/`, and `docs/` are not listed. A `--changed` run that selects zero
suites exits 0 with the uncovered list — a docs-only change is legitimately
green — and does not trip the existing zero-match exit 2, which stays for
`--filter`/`--only` typos.

The aggregate JSON gains `selection: {mode, changed, selected, uncovered,
reason}` so the dashboard can show the same thing later; the dashboard itself
is unchanged by this spec.

### Limits, stated

- A suite that **starts** loading a file after its entry was recorded misses
  a change to that file until the suite next runs. The pre-publish full gate
  refreshes every entry, so the window is one publish cycle at most.
- Code reached only through Apache is covered by rule 4, which over-selects
  (all ten `dev-web` suites, ~60s) on any core change rather than
  under-selecting.
- Files read as data are covered only where a suite declares them. The
  uncovered list is how a missing declaration surfaces.

None of these can make the full gate wrong; they can only make a scoped run
run less than it could. That is the trade: scoped runs are the working loop,
the full gate is the proof.

## Part 2 — suites that do real work the check does not need

Each was traced (`strace -f` on sleeps, connects, and execs) rather than
guessed at.

### 2a. `dns_records` — 10 seconds of real `sleep()` in a safe-tier suite

`DnsDriverBase::request()` retries a 429 on a read with
`sleep(max(1, $wait ?: RATE_LIMIT_BACKOFF_SECONDS * $attempt))`
(`includes/dns/DnsDriverBase.php:405`). The rate-limit section of
`tests/dns/dns_records_test.php` drives it through the schedule and really
sleeps 1, 1, 2, 4, 1, 1 seconds. 11.1s wall, 0.6s CPU.

Fix: the wait goes through one overridable method on the driver base
(`protected function pause(int $seconds)`); the test's fake driver records the
schedule instead of sleeping, and the section asserts the schedule it was
given — a stronger check than today's, which proves only that time passed.
Target: under 1s.

### 2b. `relay_cloud_provision` — a real rsync to a dead address

The suite scripts every command through `RelayCloudProvisioner::$runner`, but
`RelaySsh::run()` (`plugins/mailbox/includes/RelaySsh.php:99`) calls `exec()`
directly and the provisioner reaches it at line 652. Two commands escape the
script on every run: `rsync -az --timeout=30 -e ssh … 10.99.0.1:` — a real
connection attempt to the WireGuard tunnel address, which is not there, and
takes 15s to time out — and `ssh-keygen -R 10.99.0.1` against the developer's
real `~/.ssh/known_hosts` (`forgetHostKey`). 15.4s wall, 0.06s CPU.

Fix: `RelaySsh` owns the single injectable runner (`RelaySsh::$runner`, a
callable returning `[code, output]`); `run()` and `forgetHostKey()` go through
it; `RelayCloudProvisioner::$runner` is that one, not a second. The test's
recorder already answers unknown commands with `ok`. `RelaySpoolConsumer` and
`RelayMapSync` already call `RelaySsh::run`, so they become testable the same
way for free. The suite adds one assertion: every rsync and ssh-keygen line it
expects appears in the recorder's command list, which is the proof they did
not escape. Target: under 1s.

### 2c. `backup_runner` — a real full-site backup on every gate run

The section "The run refuses rather than producing something worthless"
calls `BackupRunner::run(array())` expecting an unconfigured site to report
why. On dev the site is configured: the call starts the real project files
engine — `tar` of the whole site, `gzip`, `openssl enc`, into
`{site root}/backups/chain-<stamp>/` — which fails on an unreadable file
("No passwordless sudo"), shreds the archive, and returns `error`. The check
accepts `error`, so a crash reads as the intended refusal. 34s in the gate
(61s traced; 28s user + 19s sys CPU).

The debris is real: **159 of the 167 `chain-*` directories on the dev shelf
are empty** (2026-08-05 to today, one per gate run; the other 8 are the 06:00
scheduled backups), and **`bkh_backup_history` holds 198 `failed` rows** since
2026-08-02 with that message, beside 27 real successes.

Fix, three parts:

1. **The test makes the site unconfigured for that run** and asserts
   `skipped` specifically — `error` is a crash and a crash is not "reporting
   why". The builder reads `BackupRunner::plan()` to find the config that
   makes it throw before any engine starts. The section also asserts that the
   shelf directory listing and the history row count are identical before
   and after — the guard against this recurring.
2. **`BackupRunner::run()` removes a chain directory that a failed run left
   empty.** This is a product fix, not a test fix: a customer node whose
   backups fail accumulates the same empty directories.
3. **Debris cleanup on dev** — the 159 empty directories and the 198 failed
   history rows. Listed under Actions; deletion needs the owner's word.

Target: under 3s.

### 2d. Argon2id at production cost inside test processes

Passwords are Argon2id at PHP's defaults (64 MB, 4 passes): **560 ms and
64 MB per hash on this box**, measured. Every fixture user with a password,
every sign-in a suite attempts, and every 2FA backup-code set (ten hashes —
5.6s, which is all of `admin_second_factor_reset`) pays it. `account_login`'s
throttle sections alone make 22 failed sign-ins: 12 of its 14 seconds.
`account_registration`, `password_reset`, `api_session_keys`, `routing_authz`,
`booking_flow` pay too.

Fix: `User` gets one static, `User::password_hash_options()`, returning `[]`
in production and the test parameters when the harness has installed them.
`GeneratePassword()`, the backup-code generator (`users_class.php:838`), and
the `password_needs_rehash()` call in `check_password()` all use it — the
rehash call must see the same options, or a test-hashed password would be
silently re-hashed at production cost on its first login and the saving lost.
`harness_boot()` installs `memory_cost 8192, time_cost 1, threads 1`
(**7 ms**, measured; `password_verify` accepts any parameters because the hash
carries them). The installer refuses outside the CLI.

Two rules that keep this honest:

- **In a test process, rehash-on-login is off entirely.** Otherwise a CLI
  suite that signs in a real (production-hashed) user would write a weak hash
  onto a real row. Fixture users are deleted at teardown, and
  `referential_integrity` already reddens on a leftover.
- **Suites that sign in through Apache (`dev-web`) are unaffected** — the
  server process never sees the test options. Their sign-ins stay at 560 ms;
  that is where the remaining cost lives and it is stated, not hidden.

`file_share_links_class.php` uses bcrypt (`PASSWORD_DEFAULT`, 111 ms) for
share-link passwords and is left alone.

Expected: at least 25s off the gate; the builder measures before and after.

### What stays heavy, and why

| suite | time | reason it is left alone |
|---|---|---|
| `sync_sim` | 25.6s | a real `cargo test` of the sync simulator; with `--changed` it runs only when `sync/` changes |
| `restore_roundtrip` | 15s | five real PostgreSQL dump/restore round trips — the thing it verifies |
| `plugin_sync` | 13s | the real deploy-time schema mutator, run three times over every plugin |
| `booking_flow` | 11s | the whole booking subsystem end to end; re-measure after 2d |
| `drive_encryption` | 10s | real chunked encrypted upload pipeline |

## Documentation

`docs/testing.md`: the tier-cost table gets the real numbers and a
`--changed` section (rules, the map, the uncovered list, `covers:`). CLAUDE.md
(via `/admin/admin_agent_files`, "Internal CLAUDE.md") "Which tier, when"
becomes: `php tests/run.php --changed` is the working loop; `db --changed` is
pre-checkin; `db` is pre-publish. Both written as the current state.

## Tests for this spec

- `tests/unit/changed_selection_test.php` (safe): the selection function is
  pure — given a map, a changed list, and a batch, it returns the selection
  with reasons. Cases: unknown suite runs; own file; fixtures dir; `files`
  hit; `covers` glob hit; `dev-web` + core dir; harness change runs all;
  docs-only selects nothing; `live`/`deploy` refused.
- `tests/estate/harness_contract_test.php`: the contract carries `files`,
  repo-relative, no `vendor/`.
- `tests/unit/password_hash_cost_test.php` (safe): the harness installed test
  options; a hash takes under 50 ms; `check_password()` on a test-hashed
  password does not rewrite the row; the installer refuses outside the CLI.
- `dns_records`: asserts the backoff schedule through the recorder.
- `relay_cloud_provision`: every expected rsync/ssh-keygen line went through
  the recorder.
- `backup_runner`: shelf listing and history count unchanged across the
  section; result is `skipped`.

## Acceptance

- `php tests/run.php --changed` after editing one file under
  `plugins/mailbox/`: selects only suites whose map or `covers` includes it,
  finishes in under 60s.
- `php tests/run.php db --changed` for the same edit: under 90s.
- `--changed` after a docs-only edit: zero suites, exit 0, nothing listed as
  uncovered.
- `dns_records` < 1s, `relay_cloud_provision` < 1s, `backup_runner` < 3s,
  `account_login` ≤ 2s, `admin_second_factor_reset` ≤ 1s.
- Full `db` gate at least 90s faster than 505s, same pass/fail set as before
  the change (the four standing failures are a separate item).
- `ls {site root}/backups` and `bkh_backup_history` unchanged across a full
  gate run.
- No new setting, constant, or environment variable.

## Actions resolved before the build (2026-08-31, owner-approved)

- **A1 DONE** — the empty `chain-*` directories on the dev shelf were deleted
  (158; the 8 non-empty 06:00 backups remain). Part 2c keeps them from coming
  back.
- **A2 DONE** — the 164 `bkh_backup_history` rows matching the test's exact
  failure message were deleted. 34 other `failed` rows (unreadable site key /
  missing `/backups`, 2026-08-02..05) are real early-config failures and stay.
- **B1 DONE** — the four standing gate failures were root-caused and fixed:
  `models_crud`/`multi_models_crud` failed on test-copy schema drift — the
  runner now rebuilds a stale copy before the test-db lane runs;
  `installer_contract` failed because the agent keepalive's launcher dies
  under dash whenever an inherited descriptor is numbered 10 or above
  (`exec 10>&-` parses as "run the program named 10") — the launcher is bash
  now (`install_agent.sh` 2.9), the runner starts every test child with only
  stdio open so a suite behaves identically in the gate and alone, and the
  test holds fds 9–13 open as the regression case; `agent_bundle_drift` was
  real drift (source 1.13.0 vs bundle 1.10.0), cleared by the next publish.

## Not in this spec

- A per-suite time budget that fails the gate (would stop regrowth; separate
  decision).
- Running the gate on another machine.
- Splitting `db` by cost into a `heavy` tier.
- A parallel runner (rejected 2026-08-03; do not re-propose without new
  evidence).

## Work packages

- **WP1** `files` in the contract, `covers:` in the header, the coverage map
  writer. Ships alone: a full run seeds the map, nothing else changes.
- **WP2** the selection function and `--changed`, with its unit test and the
  summary/JSON output.
- **WP3** 2a, 2b, 2d — the three small fixes, each measured.
- **WP4** 2c — the backup suite and `run()`'s empty-directory cleanup, then
  A1/A2 once approved.
- **WP5** docs and CLAUDE.md.

WP3 and WP4 do not depend on WP1/WP2 and can be built first.
