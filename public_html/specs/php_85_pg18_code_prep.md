# Code Preparation for PHP 8.5 and PostgreSQL 18

**Status:** Phase 1 complete. 1.2's no-op deletions, 1.7 and 1.9 landed
2026-08-03 (`c8c085b3`); 1.1 and the rest of 1.2 landed 2026-08-04 (`d72e6812`);
1.3, 1.4 and 1.5 landed 2026-08-04 (`a126b16a`); 1.6 landed 2026-08-05
(`a03aaa0f`); 1.8 landed 2026-08-05 (`16584d95`). **All of Phase 1 is built,
committed, and deployed to the whole fleet** — all eight Joinery nodes run
0.8.237 or later, so goal 1 (Phase 1 on every node before any is migrated) is
met.

Phase 1 was then verified on a live scratch 24.04 box (45.33.72.32) on
2026-08-06 against an installer byte-identical to the released one: 1.1 (FPM
reload), 1.3 (no php-imap), 1.4 (version derivation and the six php.ini
tunings, checked in the served SAPI) and 1.5 (pg_hba round trip) all pass. What
is still unverified needs hardware that does not exist yet — a container host
for the Docker paths, and a 26.04 box for everything in Phase 2.

Phase 2 remains gated on the OS campaign.

Bumping Brevo exposed a deploy gap that belongs to no item here and is now
closed: `ComposerValidator` checked only that a required package was *present*
in the vendor tree, never that its version matched. A node carrying Brevo v2
would have passed validation, skipped `composer install`, and fatalled on the
first Brevo send. An installed version differing from `composer.lock` is now an
install-fixable error, so such a node converges on the lock instead. A fleet
survey taken before the change found zero drift across all eight sites, so
nothing fails that was previously passing.
**Date:** 2026-08-01
**Companion:** `specs/fleet_ubuntu_2604_postgres_upgrade.md` (the fleet migration itself)

## Problem

The fleet campaign moves every box from Ubuntu 24.04 (PHP 8.3, PostgreSQL 16) to
26.04 (PHP 8.5, PostgreSQL 18). Most of the code work that campaign implies does
not actually need the new stack to be correct — it is version-detection where a
literal is hardcoded, or removal of calls that have been no-ops since PHP 8.0.
Doing that work inside the migration window means debugging it on a half-migrated
fleet, on a box that is also being cut over.

This spec separates the preparation into what can land on the current stack today
and what cannot land until the new stack exists.

## Goals

1. Every change that is behaviour-identical on 8.3/PG 16 lands, is tested, and is
   deployed to the fleet **before** the first node is migrated.
2. The migration window contains only changes that genuinely require the new stack.
3. No node upgrade can silently half-succeed because a version literal did not match.

## Compatibility Policy

**The current stack is guaranteed. Older stacks are supported but not guaranteed.**

Guaranteed means tested and provisioned: the test estate runs on it, and
`install.sh` produces a working box on it. After the campaign that is Ubuntu
26.04 with PHP 8.5 and PostgreSQL 18.

Supported-but-not-guaranteed means the code is written so it should run, without
a test run behind the claim. The floor is **PHP 8.3 and PostgreSQL 16**. An
install on that stack is expected to work and a bug report against it is worth
fixing, but nothing in the estate proves it on any given release.

This is deliberately not a commitment to a multi-version test matrix. The value
is in reach for third-party installs and in decoupling node upgrades from
releases, not in a guarantee nobody asked for.

Four consequences that constrain how code gets written:

1. **No version-specific constructs above the floor** without a guard. That rules
   out property hooks, asymmetric visibility, `array_find`/`array_any`/`array_all`,
   `array_first`/`array_last`, the pipe operator, `#[\NoDiscard]`, and `clone with`.
   This costs nothing: the audit found zero PHP 8.3-only syntax already in the
   tree, so this describes existing practice rather than adding a constraint. The
   application's true floor today is 8.2, set by vendor (`brick/math`, Symfony 7.4,
   `web-auth/webauthn-lib` all require ≥8.2); 8.3 is declared instead because
   nothing is gained by carrying a fourth version.
2. **A feature that genuinely needs a newer version detects and degrades**, rather
   than assuming the fleet is uniform. The live example is database incremental
   backups, which require PostgreSQL ≥17 — `specs/backups_core_and_incremental.md`
   should gate on `server_version` and fall back to full dumps below it.
3. **The installer guarantees only the current target.** Best-effort application
   support does not oblige `install.sh` to keep provisioning superseded releases;
   `--allow-unsupported-os` remains the escape hatch for anyone who wants to
   finish by hand.
4. **Deprecation fixes stay version-agnostic.** Every Phase 1 item below is valid
   on 8.3 and on 8.5, which is what makes the phase safe to land now — but it is
   also what keeps the floor honest afterwards.

One hazard is unaffected by any of this: a PostgreSQL 16 `pg_dump` cannot dump a
PostgreSQL 18 server, so `copy_database` between nodes on mismatched majors fails
regardless of what the application supports. That is a client/server protocol
constraint, not an application one, and it stays in
`specs/fleet_ubuntu_2604_postgres_upgrade.md` as a mixed-window rule.

## Audit Result

A full static sweep of the tree (2026-08-01) against the PHP 8.4 and 8.5 UPGRADING
notes and the PostgreSQL 17 and 18 migration lists found the application code
substantially clean. Recorded here so it is not re-derived:

**Scope correction (2026-08-03).** The original sweep's `curl_close()` count was
core-only and missed every plugin — 12 recorded against 53 actual. Counts for
`imagedestroy()` (8), `finfo_close()` (2), and implicitly nullable parameters (2)
were re-verified across the whole tree at build time and were correct as written.
Any future audit item must be counted across `plugins/` as well as core.

**Clean — no occurrences anywhere in app code:** `(boolean)`/`(integer)`/`(double)`/
`(binary)` casts, `MHASH_*`, mysqli, `xml_set_*`, `xml_parser_free`,
`SplObjectStorage`, `setAccessible()`, `socket_set_timeout`, `lcg_value`,
`DatePeriod`, 2-argument `stream_context_set_option`, `session_set_save_handler`,
the deprecated `session.*` INI settings, the `SID` constant, `disable_classes`,
`$_SESSION` keys containing a pipe character. No user-defined function collides
with a global added in 8.4 or 8.5 (`array_find`, `array_any`, `array_all`,
`array_first`, `array_last`, `fpow`, `mb_trim`, `request_parse_body`, …). All ten
`ReflectionMethod` constructions use the two-argument form; only the one-argument
form is deprecated. The tree defines two traits, so 8.5's trait/parent binding
order change carries negligible risk.

**Implicitly nullable parameters — two, total.** Both in `includes/VaultDeferredWork.php`.
This was expected to be the bulk deprecation item and is not.

**PDO is clear of the common traps.** `DbConnector.php` supplies credentials only
inside the DSN and passes no constructor arguments, so PHP 8.4's "DSN credentials
take priority over constructor arguments" change is a no-op here; every other
connection site does the reverse and is equally unaffected. No deprecated
`PDO::PGSQL_*` constants or driver-specific methods are used, and no fetch-mode
integer is persisted or hardcoded (their values changed in 8.5).

**The PostgreSQL SQL surface needs no changes**, largely because of what the
codebase does not do. There are no materialized views, no `CREATE FUNCTION`, and
no expression indexes, so PG 17's safe-`search_path`-during-maintenance change
does not apply. There are no unlogged tables, no AFTER triggers, no
`SET SESSION AUTHORIZATION`, and no rule privileges. `DatabaseUpdater`'s catalog
queries read only `pg_attribute`, `pg_constraint`, `pg_index`, `pg_class`,
`pg_namespace`, and `pg_am`, and touch none of the columns removed or renamed in
17 or 18. `create_install_sql.php` emits pg_dump text-format `COPY` blocks rather
than CSV, so PG 18's `\.` end-of-file change does not apply. Backup and restore
are plain `pg_dump | gzip` and `psql`, forward-compatible 16 → 18.

## Phase 1 — Compatible Now

Every item lands on the current stack and behaves identically there. Order matters
only within 1.1: those two are the silent-failure fixes and go first.

### 1.1 Version literals that fail silently on the new stack — BUILT 2026-08-04

Four sites, not the two originally recorded. Each resolves on the current stack
to exactly the literal it replaced (`php8.3-fpm`, `/etc/postgresql/16/main`),
verified on the dev box, so the change is observable only on a host that is not
8.3 / PG 16.

**`utils/upgrade.php`** — `exec('service php8.3-fpm reload 2>/dev/null')`. This
runs on every deployed node at the end of an upgrade, after installing a
plugin-declared PHP extension. On a 26.04 node there is no `php8.3-fpm`, stderr
is discarded, and the exit code is not checked, so the reload silently does not
happen and opcache keeps serving pre-upgrade code while the upgrade reports
success. A new `upgrade_find_fpm_service()` prefers
`php{PHP_MAJOR_VERSION}.{PHP_MINOR_VERSION}-fpm` — `upgrade.php` runs under the
same PHP the web tier loads — and falls back to globbing `/etc/init.d/php*-fpm`
and the systemd unit directories, taking the highest version by
`version_compare`. It returns `''` rather than a guess when nothing is
installed. The caller now reads the exit code and warns on a failed reload;
`''` is reported separately as "no FPM here", so a mod_php host stays quiet
while a broken reload does not. The `apache2ctl graceful` on the preceding line
is version-independent and stays.

**`maintenance_scripts/install_tools/Dockerfile.template`** — two sites, both in
the container start command's `&&` chain, so neither degrades: the container
aborts before Apache with nothing saying which path was wrong.

- `PG_CONF="/etc/postgresql/16/main/pg_hba.conf"` — on PG 18 the `sed` against a
  missing file fails. Now `ls -1d /etc/postgresql/*/main/pg_hba.conf | sort -V |
  tail -1`, with an explicit FATAL naming the missing file.
- `service php8.3-fpm start` — the same failure one line from the end, after the
  whole site had already initialised. Now globs `/etc/init.d/php*-fpm` the same
  way, with its own FATAL.

Template version 4.4 → 4.5. `install.sh`'s own PostgreSQL detection
(`psql --version` parsed to a major number) resolves PG 18 correctly and needs
no change; its 37 PHP literals are 1.4 and deliberately untouched here.

**`maintenance_scripts/sysadmin_tools/migrate_site_to_code_volumes.sh`** —
`dpkg -l 'php8.3-*'` captures the installed extension packages before a
container swap and compares after. `prepare` and `swap` share the command, so on
any other PHP both sides return empty and the swap reports "No php packages
missing" while every declared extension is gone — the exact failure the record
exists to catch. Glob widened to `php[0-9]*-*`; output verified identical on the
dev box.

`tests/unit/installer_contract_test.php` gained a section asserting no
`php\d+\.\d+-fpm` and no `/etc/postgresql/\d+/` literal survives in any of the
three, that the reload reads its exit code and warns, and that both Dockerfile
paths fail with a message naming what they could not find. Comment lines are
excluded from the Dockerfile scan so the version log can keep naming the old
literal. 158 checks, green.

### 1.2 PHP deprecations

All of the following are valid on 8.3 and behave identically there.

**Deprecated in 8.4:**

- ~~`trigger_error(..., E_USER_ERROR)`~~ **BUILT 2026-08-04** — three sites in
  `includes/FormWriterV2Base.php`, all inside `validateVisibilityRules()`. Each
  is a developer-error assertion intended to halt, so each became a thrown
  `InvalidArgumentException` carrying the same message. It still stops the
  render, and it is now catchable and carries a trace to the offending call.
  FormWriter 2.18.0 → 2.19.0. The `E_USER_ERROR` constant remains in
  `includes/ErrorHandler.php`, where it is compared against
  `error_get_last()['type']` — the constant is not deprecated, only raising it
  through `trigger_error()` is.
  `tests/unit/formwriter_visibility_script_test.php` (12 → 17 checks) previously
  wrapped the one covered case in a `set_error_handler` shim to catch the fatal;
  that scaffolding is gone, the exception class is asserted, and the two rules
  that had no coverage at all — a field both shown and hidden for one value, and
  a non-string field reference — now have it.
- ~~Implicitly nullable parameters~~ **BUILT 2026-08-03** —
  `includes/VaultDeferredWork.php:118`: `string $scope = null` and
  `float $budget_seconds = null` became `?string` and `?float`. A whole-tree
  rescan confirmed these were the only two; the other `= null` typed params in
  the tree (`?\Throwable $previous` in `includes/ErrorClasses.php` and
  `MailingListProviderException.php`) were already explicit.
- ~~`E_STRICT`~~ **BUILT 2026-08-03** — `utils/diagnostics.php:19` is now
  `error_reporting(E_ALL)`.

**Deprecated in 8.5:**

- ~~`curl_close()`~~ **BUILT 2026-08-03** — **53 call sites across 30 files**, not
  the twelve originally recorded; the original count omitted `plugins/` entirely
  (`plugins/store/includes/PaypalHelper.php` alone held 14, plus sites in
  `server_manager`, `dns_filtering`, `mailbox`, and `store`). The function has
  been a no-op since PHP 8.0 (a `CurlHandle` is released by garbage collection),
  so all 53 were deletions. Every site was verified to be a standalone statement
  and none was the sole body of an unbraced `if`/`else` before deleting — that
  was the only way a bulk line-delete could have silently changed control flow.
- ~~`imagedestroy()`~~ **BUILT 2026-08-03** — eight call sites:
  `data/file_blobs_class.php:1091` and `:1092`, `includes/UploadHandler.php:618`,
  `:697` and `:708`, `includes/Photo.php:308` and `:309`,
  `tests/functional/files/blob_layer_test.php:49`. Also a no-op since 8.0.
  `UploadHandler.php:618` was the exception to "just delete the line": it read
  `return $image && imagedestroy($image);`, so the call was part of the return
  expression and became `return (bool)$image;`, preserving the result.
- ~~`finfo_close()`~~ **BUILT 2026-08-03** — `data/files_class.php:717` and `:747`.
- ~~`$http_response_header`~~ **BUILT 2026-08-04** —
  `plugins/mailbox/tasks/LearnSpamFeedback.php`, reading the rspamd controller's
  status line in the stream fallback taken when curl is unavailable. The
  replacement, `http_get_last_response_headers()`, is 8.4-and-later only, so both
  paths are kept behind `function_exists()` until the fleet is past 8.3.
  `validate_php_file.php` reports `Missing: 1` on this file for exactly that
  call — it does not exist on the 8.3 that runs the validator, which is why the
  guard is there. That flag is expected until the fleet moves and should not be
  re-investigated.

  **Adjacent defect fixed in passing:** the status-line regex was `/\s(\d{3})\s/`,
  which requires trailing whitespace and so read 0 from a bare `HTTP/1.1 200`.
  Code 0 fails the learn, which leaves the row diverged and re-selecting on every
  15-minute pass forever. Now `/\s(\d{3})(?:\s|$)/`.

### 1.3 Remove the IMAP extension dependency — BUILT 2026-08-04

PHP 8.4 unbundled `ext/imap` to PECL, so 26.04 will not carry a distro
`php8.5-imap`. The only consumer in the tree is `tests/email/auth_analysis.php`,
which opens Gmail over IMAP for authentication analysis; production inbound mail
uses `bytestream/horde-imap-client`, which is pure PHP and unaffected. Confirmed
by sweep: no `ext-imap` in `composer.json`, no `imap` in any plugin manifest's
`requires.extensions`, and every other `imap` match in the tree is either Horde
or the `iia_inbound_imap_accounts` naming.

- `php8.3-imap` dropped from the package list in `install.sh` (2.33 → 2.34).
  The failure it removes is not a missing feature: `apt install` refuses the
  batch, so every extension listed beside it — pgsql, mbstring, sodium — also
  goes uninstalled and the install stops there.
- `tests/email/auth_analysis.php` now reports the extension as absent instead of
  advising the operator to install a package that no longer exists, and reports
  it **before Step 1**. The old guard sat at Step 3, after the tool had sent a
  real email and slept out the delivery wait for a run that could not finish.
  The form page carries the same notice, so the Gmail app password is never
  typed in for nothing.
- The copies in `maintenance_scripts/archive/` are archived scripts; left alone.
- Pinned by `installer_contract_test` (171 → 176 checks): the apt list names no
  `php*-imap` (matched at line start, so the version history can keep naming the
  package it dropped), nothing in the tree declares the extension, and the tool's
  guard precedes its send.

### 1.4 Installer PHP parameterization — BUILT 2026-08-04

`install.sh` carried 21 version literals across the package list, the Apache
module and conf (`a2dismod php8.3`, `a2enconf php8.3-fpm`), the FPM service at
two sites, and nine `/etc/php/8.3/fpm/php.ini` paths. All are derived now
(`install.sh` 2.34 → 2.35). Verified on 24.04: the derivation resolves to `8.3`
and the generated package list is byte-identical to the one it replaced.

**Detection** — `detect_php_version()`, resolved once after `apt update` and used
for everything below it. A box that already has PHP keeps that version; only a
box without one asks apt, via `apt-cache depends php-cli`, which is the same
question apt answers for `apt install php-cli`. So the version installed and the
version configured cannot diverge. Consequence worth knowing: on a box carrying
the Ondřej PPA the fallback picks the PPA's newest rather than the distro's —
correct, because that is what apt would install, but it is not always the distro
default. Verified on the dev box, which has the PPA: the primary path returns
`8.3` (the installed PHP) and the fallback alone would return `8.4`.

**Two new stops**, both for failures that were silent:
- An undetectable version. Every path built from it becomes `/etc/php//fpm/`,
  which `sed` neither matches nor complains about — the tuning is skipped and
  the setup reports success.
- An fpm `php.ini` that is not where the tuning writes. Same outcome, so the
  file is checked before the first `sed` rather than after all nine.

**Package list** — the sixteen extensions are now suffixes with the version
applied in a loop. This is not cosmetic: apt takes the list as one transaction,
so one name that does not resolve on a release fails the batch and every other
extension goes uninstalled with it. Suffixes have no version to be wrong about.

**`plugins/mailbox/provisioning/install_email.sh`** (2.14 → 2.15) reads the
version off `PHP_BIN`, the interpreter it already resolved for the Postfix pipe
transport. Noted in the file: a box whose web PHP differs from its CLI PHP needs
that version's `sqlite3` package too, which this script does not provision.

**OS gate** widened to `Ubuntu (24|26)\.04`, which is why the parameterization
had to land first. Its comment and the `--allow-unsupported-os` help text no
longer claim hardcoded 8.3 paths — the gate is now about which package and
service layouts have been verified, not what the script can express. Matching
checked against `24.04.4`, `26.04`, `24.10`, `22.04.5`, and Debian 12.

**Docs** — `installation.md`, `quickstart.md`, and `deploy_and_upgrade.md`
carried the hardcoded-8.3 rationale and a 24.04-only requirement. `quickstart.md`
also offered 22.04 as a fallback, which the gate has never accepted; corrected.

Pinned by `installer_contract_test` (176 → 185 checks): no `php\d+\.\d+` and no
`/etc/php/\d+\.\d+/` anywhere in either script outside comments, both stops exit
1, `detect_php_version()` exists, the gate admits both releases, and the mail
provisioner reads its version from `PHP_BIN`. Proven non-vacuous — the same
regexes match 21 and 9 times against the previous revision.

### 1.5 PostgreSQL authentication hardening — BUILT 2026-08-04

`install.sh` wrote `md5` as the authentication method throughout the generated
`pg_hba.conf`, and both `install.sh` and `Dockerfile.template` contained a `sed`
dance flipping the `postgres` local line between `trust` and `md5` while setting
the role password.

PostgreSQL 18 deprecates MD5 passwords and warns on `CREATE ROLE`/`ALTER ROLE`
that set one. In practice nothing here creates an MD5 verifier —
`password_encryption` has defaulted to `scram-sha-256` since PG 14, and an `md5`
line in `pg_hba.conf` accepts a SCRAM verifier — so no warning fires and nothing
breaks on 18. The change is hardening ahead of the eventual removal, not a fix.

Re-verified on the dev box 2026-08-04, PostgreSQL 16.14: `password_encryption` is
`scram-sha-256`, both roles (`postgres`, `iemap_joinerytest`) hold SCRAM
verifiers, and the connection that read this authenticated over a live
`host all all 127.0.0.1/32 md5` rule. The keyword was accepting SCRAM already.

**What landed** (`install.sh` 2.35 → 2.36, `Dockerfile.template` 4.5 → 4.6):

- Generated rules name `scram-sha-256`, from a single `PG_AUTH_METHOD` variable
  that also feeds the post-password restore. The spec's caution was that the
  match strings live in two files and must move together; a variable removes the
  question inside `install.sh` entirely.
- Across the two files the coupling is broken differently: the container reads
  the method out of `pg_hba.conf` before flipping and puts *that* back. It no
  longer carries its own answer, so it cannot disagree with whatever `install.sh`
  wrote — including on a box installed by an older release.
- Both `sed` calls match the method as a field
  (`^local[[:space:]]+all[[:space:]]+postgres[[:space:]]+`) rather than as a
  whitespace-exact copy of a generated line. Demonstrated: against a
  single-spaced rule the old literal `sed` changes nothing and reports success;
  the field form rewrites it.
- **The restore is now verified in both files.** This is the part worth having.
  `sed` exits 0 when it matches nothing, so a drifted pattern left the local
  `postgres` role on `trust` — superuser for anyone with a shell — while every
  later step still succeeded. A restore that did not take is a hard stop naming
  the file and the line to fix.

Pinned by `installer_contract_test` (185 → 193 checks). All five assertions fail
against the previous revision: it had no field-matched `sed`, no restore check,
no method variable, five generated `md5` rules, and a container naming its own.

**Existing nodes** are handled by the campaign's per-node checklist rather than a
separate sweep (resolved 2026-08-01). Procedure A nodes get it free from the
updated installer template. Procedure B nodes — the dev box, the jeremytunnell
mail box, relay1, and the ScrollDaddy DNS servers, which are never rebuilt — get
an explicit step:

1. `SELECT rolname, rolpassword LIKE 'SCRAM-SHA-256%' AS is_scram FROM pg_authid
   WHERE rolpassword IS NOT NULL;`
2. Any role that is not SCRAM gets `ALTER ROLE ... PASSWORD ...`, which
   re-encrypts it under the current `password_encryption`.
3. Only then flip `md5` to `scram-sha-256` in `pg_hba.conf` and reload.
4. Verify one real connection before moving on.
5. While in the file, check the `local all postgres` line is not on `trust`.
   `SELECT type, database, user_name, auth_method FROM pg_hba_file_rules;` reads
   the live rules without opening the file. The dev box was found on `trust`
   2026-08-04 — see the note below.

Step 1 is not optional. Flipping a node to `scram-sha-256` while a role still
holds an md5 verifier locks that role out immediately. The reason not to simply
skip the whole thing is that `md5` is on PostgreSQL's removal track and procedure
B nodes are exactly the ones that never get rebuilt out of it.

**Found on the dev box while verifying the above (2026-08-04), not fixed:**
`local all postgres` is on `trust`, so anyone with a shell there is a PostgreSQL
superuser without a password. That is exactly the state the new restore check
stops an install from leaving behind, observed in the wild. Separately, the box
has `listen_addresses = '*'`, a `host all all 0.0.0.0/0 md5` rule, and postgres
bound to `0.0.0.0:5432`; whether that is reachable off-box depends on a firewall
this account cannot read. Both are live-configuration changes on a running
server and belong to the operator, not to this spec.

### 1.6 CSV escape parameter — RFC 4180 — BUILT 2026-08-04

PHP 8.4 deprecates relying on the default `$escape` for `fputcsv()`, `fgetcsv()`,
and `str_getcsv()`. The default is a backslash, which puts the parser into an
escape state that suppresses the following quote — a rule no other spreadsheet
tool implements. Pass `''` explicitly at every site, making the platform RFC 4180
compliant, which is what Excel, Google Sheets, and Google Contacts all emit and
expect. Valid on 8.3.

Nothing round-trips through the platform — the table export is download-only and
the contact import reads files other tools produced — so there is no pair of
sites that must change together.

- `data/admin_tableexport_data.php` (2 sites) — writes arbitrary table contents
  for admin download. **Measured, and narrower than first recorded:** a plain
  backslash survives the round trip either way, so file paths were never at
  risk. What corrupts is a backslash immediately before a quote — any JSON or
  serialized column, where `\"` is ordinary. `{"k":"v\"q"}` written with the
  default returns from a spreadsheet as `{"k":"v\q""}"`; with `''` it survives.
- `plugins/mailbox/includes/MailboxContacts.php` (2 sites) — parses Google
  Contacts exports, which are RFC 4180. **The worst of the set, and worse than
  the writer.** Demonstrated on a Notes field ending in a backslash:
  `Jane Doe,"see C:\Users\jane\",jane@example.com,Team A` parses as **2
  columns** under the default instead of 4, with the email address swallowed
  into the notes field — an import that silently drops the contact it was
  reading.
- `includes/ApiAuth.php:135` — splits an API key's IP allowlist. IP addresses
  contain neither backslashes nor quotes, so this is a no-op in practice; change
  it for consistency.
- Test tooling: 10 sites across four files in `tests/tools/`
  (`fetch_phishing_pot.php`, `fetch_spamassassin_ham.php`,
  `load_email_corpus.php`, `score_email_corpus.php`). The spec originally also
  named `plugins/mailbox/tests/`; no plugin test directory contains a CSV call.

**15 sites, 7 files, all converted.** A tree-wide sweep confirms no
`fputcsv`/`fgetcsv`/`str_getcsv` call is left relying on the default. `fgetcsv`
needs its length argument passed to reach the escape, so those read
`fgetcsv($fh, 0, ',', '"', '')`.

**Related defect, not fixed here:** `MailboxContacts::parseCsv()` splits its input
on newlines with `preg_split()` before parsing any field, so a quoted field
containing a newline — common in the Google Contacts "Notes" column — breaks the
row alignment regardless of the escape setting. Worth its own fix; out of scope
for an upgrade-prep pass. Recorded in the docblock at the call site so the next
reader does not conclude the escape fix made the parser correct.

### 1.7 Remove dead bulk-user page — BUILT 2026-08-03

`adm/admin_user_add_bulk.php` called `fopen("test.csv", "r")` against a hardcoded
relative filename that does not exist, with no upload and no form, and echoed
raw fields to the page. It was absent from `admin_menus.json` and from
`amu_admin_menus`, and nothing in the tree linked to it; reaching
`/admin/admin_user_add_bulk` with permission 5 rendered a blank page. Deleted,
after confirming zero code references and zero `amu_admin_menus` rows. This also
removed one site from 1.6.

### 1.8 Composer floor, platform pin, and dependency constraints — BUILT 2026-08-05

**Floor.** `public_html/composer.json` declared `"php": ">=7.4"`; it declares
`>=8.3`, the floor from the compatibility policy above. No installed version
changes — it makes the floor machine-readable, which is the only enforcement the
policy gets.

**Platform pin.** `config.platform.php` is set to `8.3.0`. Without it, Composer
resolves against whatever PHP the build box happens to run, so a `composer update`
on a 26.04 box could select a package that no longer installs on an 8.3 node. With
it, resolution is deterministic and a package that cannot satisfy the floor fails
at resolution time rather than at install time on someone else's server.

**Constraint audit.** Every one of the 86 installed packages declares a PHP
constraint, and all of them admit 8.5: `composer why-not php 8.5` reports no
blocker. Re-run that command after any dependency change — it is the whole audit,
and it is authoritative in a way that reading `require.php` strings is not.

*Correction to the original draft.* This spec claimed `wildbit/postmark-php` v7.0.0
was a live 8.5 hazard because it requires `~8.1 || ~8.2 || ~8.3 || ~8.4`. That
reading is wrong. Composer's two-part tilde means `>=8.1 <9.0`, so the constraint
admits 8.5; resolving `^7.0` against a pinned platform of 8.5.0 succeeds. There is
no Postmark hazard and no cap was added. The constraint stays `^4.0`, which pins
to 4.x for unrelated reasons. Read tilde and caret ranges with Composer's own
semantics, or better, let `composer why-not` answer the question.

**Brevo.** `getbrevo/brevo-php` moved from `^2.0` to `^5.0` (installed 5.0.1,
requires `^8.1`). The deprecation payoff is real and larger than drafted: counted
with the tokenizer rather than a regex — so that `?Type $x = null` and
`Type|null $x = null` are not miscounted as implicit — the vendor tree held **462**
implicit-nullable parameters, of which **417 were Brevo v2**. After the bump the
tree holds **45**, and Brevo holds **0**. One library was 90% of the 8.4
deprecation surface.

*This was a rewrite, not a symbol check.* The draft expected the five v2 symbols to
survive. None did: v5 is a Fern-generated client that replaces the whole
`Brevo\Client\*` namespace. `Configuration` + `Api\TransactionalEmailsApi` +
`Api\AccountApi` + `Model\SendSmtpEmail` + `ApiException` become
`Brevo\Brevo` (constructed with the API key directly, no Guzzle client passed),
`->transactionalEmails->sendTransacEmail()`, `->account->getAccount()`, and
`Brevo\Exceptions\BrevoApiException`. Setters become constructor arrays of typed
value objects (`SendTransacEmailRequestToItem` and siblings), so
`buildBaseEmail()` returns the constructor array and the caller fills in `to` and
`messageVersions`. Account fields are public properties, not getters, which
removes the `method_exists()` probing the v2 code needed.

Verified by capturing the outbound PSR-18 request rather than trusting the types:
both the single-send and `messageVersions` batch paths still `POST
https://api.brevo.com/v3/smtp/email` with the documented v3 field names
(`sender`, `to`, `cc`, `bcc`, `subject`, `htmlContent`, `textContent`, `replyTo`,
`headers`, `attachment`, `messageVersions`). `BrevoApiException::getCode()` still
returns the HTTP status, so the 401/403 branches of `validateApiConnection()` still
map. `tests/integration/email_inline_attachments_test.php` passes, but note it
only asserts a log marker and would not have caught a wrong payload shape.

### 1.9 Latent fatals surfaced by the validator sweep — BUILT 2026-08-03

Not upgrade work. Both were found by running `validate_php_file.php` over files
already being touched for 1.2, and both were pre-existing calls to methods that
do not exist anywhere in the tree — guaranteed fatals, latent only because
neither caller currently has callers of its own.

- `includes/StaticPageCache.php:1037` — `getRecentUrls()` called
  `self::getIndex()`. The class defines `loadIndex()` and `saveIndex()`; there is
  no `getIndex()`. Repointed to `loadIndex()`, which returns the index array the
  surrounding code goes on to use.
- `data/address_class.php:543` — `get_distance_between()` called
  `Address::GetDistanceBetweenLocations()`, which exists in neither the tree nor
  vendor. Deleted the method rather than implementing it: there is no haversine,
  PostGIS, or other distance helper anywhere to point it at, it had zero callers,
  it is not exposed through a descriptor or the AI surface, and the one adjacent
  hook (`get_address_dropdown_options`'s `$distance_from_addr`) is never passed by
  its only caller. Address-to-address distance is a feature request, not a fix —
  it belongs to `specs/geolocation_postgis_spec.md`.

A third flag, `$message->partIterator()` in
`plugins/mailbox/includes/InboundEmailRouter.php`, is a validator false positive:
the method is genuine `Horde_Mime_Part` API (`vendor/bytestream/horde-mime/lib/Horde/Mime/Part.php:1682`)
and the validator mis-attributes `$message` to the app's own `EmailMessage`.

**Tree-wide sweep (2026-08-03).** The validator was then run deliberately across
the tree. Because it `include()`s its target, a tokenizer pre-screen excluded any
file with top-level executable code: 857 declaration-shaped candidates under
`data/`, `includes/`, `logic/`, and the plugin equivalents, of which 806 cleared
and were validated. That yielded 14 findings, 11 of them false positives (traits
where `parent::`/`self::` cannot resolve standalone, and vendor classes such as
Guzzle's `Promise\Utils` and Brevo's aliased `Configuration`). The three real
ones, none of them PHP 8.5 issues:

- **`includes/PluginHelper.php:43` — `registerRoutes()` removed.** Orphaned
  residue: commit `7afd6337` (2026-04-06, "Remove unused public API surface from
  theme/plugin system") deleted both the `$this->registerRoutes();` call and the
  sibling `registerAdminMenu()` method, but left `registerRoutes()` itself.
  It was `private` with zero callers, and the `RouteRegistry` class it guarded on
  has never existed in any commit in the repository's history — plugin routing is
  auto-discovery. `getAdminMenuItems()` is unrelated and still live (3 callers).
- **`data/location_info_data.php` — left in place, deliberately.**
  `LocationInfo::find_location()` calls `LibraryFunctions::GetLocationInfoFromCache()`,
  which does not exist and has no `__callStatic` fallback. Nothing requires the
  file and nothing references the class, so it cannot execute. Not deleted: it is
  126 lines of zip/city-state parsing, and `includes/SessionControl.php:1034`
  carries a commented-out companion call to `StoreLocationInfoInCache()`, which
  reads as a paused feature rather than abandoned code. Needs an owner decision.
- **Blocking is documented but does not exist** — see below; a docs defect, not a
  code one.

`docs/social_features.md` claimed `get_or_create_conversation()` and
`add_message()` enforce user blocks and that "plugins don't need to check blocks
separately". No `UserBlock` class and no user-block table exist, so the
`class_exists('UserBlock')` branch at `data/conversations_class.php:52` and `:147`
is permanently false and no block check ever runs. The doc was corrected to state
that the platform has no blocking system and that callers must enforce their own
restrictions; the guards were left as the integration point if blocking is built.
The same file's "reporting" claim was also dropped — no report class or table
exists either. The `agf_agent_files` "Internal CLAUDE.md" record carries the same
correction, applied through the `AgentFile` model so the drift hash stays
consistent.

## Phase 2 — Requires the New Stack

None of these can land before 26.04 nodes exist.

1. **`Dockerfile.base:17`** — `FROM ubuntu:24.04` becomes `ubuntu:26.04`, with a
   `BASE_IMAGE_VERSION` bump at `install.sh:119` (currently `1.1`). Every host
   then needs `install.sh build-base` before site containers are recreated.
2. **Linode stackscript** — point `install_tools/linode_stackscript_wrapper.sh`
   and the Linode-side deploy image at 26.04, then re-run the quick-deploy app's
   live gates from `specs/linode_quick_deploy_app.md`.
3. **Drop 24.04 from the installer gate**, once no node remains on it. The
   corresponding assertion in `tests/unit/installer_contract_test.php:233` moves
   with it. This is an installer-scope decision, not an application-scope one:
   per the compatibility policy, an existing 24.04 site keeps running and keeps
   receiving upgrades — what stops is `install.sh` provisioning a *new* one, and
   `--allow-unsupported-os` still covers that case by hand.
4. **Documentation restatement.** Per the docs rule, these read as current state
   with no migration narrative: `docs/installation.md:95` and `:233`,
   `docs/deploy_and_upgrade.md:19`, `maintenance_scripts/install_tools/INSTALL_README.md:440`
   and `:527` (the latter names `postgresql-16-main.log`), and the Server Manager
   overview's node OS expectations.
5. **`specs/geolocation_postgis_spec.md:47` and `:578`** pin
   `postgresql-16-postgis-3`. That spec is unbuilt; update the pin when it is
   built rather than now.

### Verification that belongs on the new stack, not in Phase 1

These are behaviour changes with no code fix — they need a run, not an edit.

- **`round()` was reimplemented in 8.4.** Store money math is the exposure. The
  `db` tier is the gate; add a targeted check if one does not already cover
  rounding at the cent boundary.
- **`password_hash` bcrypt cost rose from 10 to 12 in 8.4.** User accounts are
  unaffected — `data/users_class.php` hashes and rehashes against
  `PASSWORD_ARGON2ID`. The exposure is `data/file_share_links_class.php:60`,
  which uses `PASSWORD_DEFAULT` (still bcrypt), so share-link password hashing
  gets roughly four times slower. Existing hashes still verify. Confirm the
  latency is acceptable on a 1 GB node rather than assuming it.
- **Deprecation sweep at `E_ALL`.** The dev box runs `error_reporting = 22527`
  (`E_ALL & ~E_DEPRECATED & ~E_STRICT`), so a sweep on the scratch box will
  report nothing useful unless it is raised first. Check the deployed `php.ini`
  for the 8.4-deprecated `session.*` settings at the same time; none are set
  from code.
- **PG 18 `pg_upgrade` and data checksums.** 18 enables data checksums by default
  at `initdb`. `pg_upgradecluster`, which the campaign spec names for procedure B,
  dump/restores by default and is unaffected. But `pg_upgrade --link` against a
  non-checksummed 16 cluster will refuse, so if anyone reaches for it they need
  `initdb --no-data-checksums`. This belongs as a line in the campaign spec's
  procedure B.
- **Generated `install.sql` loads on 18** — `create_install_sql.php` output is
  text-format `COPY`, which is not affected by 18's CSV `\.` change, but the
  install path should be exercised once on the scratch box regardless.

## Verification

- `php tests/run.php safe` after each Phase 1 item. **Green as of 2026-08-04 for
  the built subset (1.1, all of 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.9): 84/84 tests, 2237
  checks, 0 failed, 147 skipped.**
- `php tests/run.php db` before committing Phase 1, and again before publishing.
  **Green as of 2026-08-04 through 1.2: 226/226 tests, 6912 checks, 0 failed,
  159 skipped.** Re-run for 1.6, which changes a live import path
  (`MailboxContacts`) and the admin table export: **green 2026-08-04 with all of
  Phase 1 built bar 1.8 — 227/227 tests, 7073 checks, 0 failed, 159 skipped.**
- `validate_php_file.php` reports `Missing: 0` on every file touched so far, with
  one expected exception: `LearnSpamFeedback.php` flags
  `http_get_last_response_headers()`, which genuinely does not exist on the PHP
  8.3 running the validator and is guarded by `function_exists()` for that
  reason. Note the validator *executes* its target, so it is run only on
  class/definition files — `utils/` scripts and other run-on-include bodies get
  `php -l` and review by eye.
- `tests/deploy/syntax_sweep_test.php` covers the parse level for every file the
  deployed site can load; it is the existing deploy gate and needs no change.
- Phase 1.1 — **verified 2026-08-06.** On a live 24.04 box, `php8.3-apcu` (the
  `ext-apcu` the root `composer.json` declares) was removed and
  `utils/upgrade.php --verbose` run. It reinstalled the package and reloaded
  `php8.3-fpm`. The assertion is the reload's *effect*, not its exit code: a
  view served over HTTP reported `apcu=LOADED` where it had reported
  `apcu=absent`, and the fpm workers respawned. The fpm master PID also changed,
  which is apt's postinst restarting the service, not the graceful reload.
- Phase 1.4 — **verified 2026-08-06** on a clean 24.04 box, using an installer
  byte-identical to the one in the release archive. PHP 8.3 the only version
  installed, `php8.3-fpm` enabled and active, `mpm_event` + `proxy_fcgi` with no
  mod_php, `fastcgi_finish_request()` available, and all six tunings live *in
  the served SAPI* rather than merely present in the file. The box carries no
  Ondřej PPA, so `detect_php_version()` took the `apt-cache depends php-cli`
  fallback and resolved `8.3` — the branch the dev box cannot exercise. The PPA
  case is unchanged and remains an owner decision rather than a test.
- Phase 1.3 and 1.5 — **verified in the same run.** The install asked apt for
  exactly sixteen `php8.3-*` packages and no imap; afterwards `pg_hba_file_rules`
  shows `local all postgres` on `scram-sha-256` with zero `md5` rules, the role
  holds a SCRAM verifier, `listen_addresses` is `localhost`, and a real password
  connection works. Consequence worth knowing: `sudo -u postgres psql` now
  prompts for a password on a freshly installed box, because the local rule is
  no longer `peer`.
- Still unverified, for want of the hardware: the Dockerfile CMD globs, the
  container pg_hba round trip, `migrate_site_to_code_volumes.sh`, and
  `install_email.sh` all need a container host; the `config.platform.php` pin
  needs a 26.04 / PHP 8.5 build box.

## Out of Scope

- The fleet migration itself, per-node procedure, and ordering — see
  `specs/fleet_ubuntu_2604_postgres_upgrade.md`.
- Multi-distro support beyond Ubuntu — see `specs/multi_distro_install_refactor.md`,
  which covers the same `install.sh` parameterization for a wider target set.
  With 1.4 built, that spec inherits a script with no PHP version literal in it
  and its own examples now read as the state it is proposing to move away from.
- Application adoption of PostgreSQL 18 features. Incremental base backups land
  via `specs/backups_core_and_incremental.md`.

## Dependency Refresh

The vendor tree carries 462 implicitly-nullable-parameter deprecations, 363 of
them in `getbrevo/brevo-php`, with the remainder in `kriswallsmith/buzz` (9),
`mailgun/mailgun-php` (8), `jhut89/mailchimp3php` (6), and `sendgrid/sendgrid` (1).
These are deprecations only and do not block the upgrade; with
`error_reporting` as currently configured they are not even logged. Symfony 7.4,
`web-auth/webauthn-lib` 5.3.5, and the AWS SDK are current and already declare
8.2 or later.

Checked against Packagist 2026-08-01: none of the stale-looking pins is
abandoned, and every one of them is compatible with PHP 8.5.
`wildbit/postmark-php` is still the official client (v7.0.0, 2025-07-11) and
`jhut89/mailchimp3php` carries no abandonment marker. Upgrading is therefore
optional, and in Postmark's case actively harmful right now — see 1.8.

The one pin that stays out of scope on its own merits is `stripe/stripe-php`,
held at `^10.16` with v10.21.0 installed, many majors behind current. Its risk is
not PHP: a major SDK bump also moves the pinned Stripe API version, changing
subscription response shapes in `StripeSubscriptionReconciler` and the tier
billing paths. That needs its own spec and its own test-mode pass, and must not
ride along with an OS migration — when checkout misbehaves afterwards, nobody
should have to guess whether Ubuntu, PHP, or Stripe caused it. Most of the 30
files referencing Stripe touch `StripeHelper::` or `Stripe\Exception\*` rather
than raw SDK calls, and `StripeHelper` already uses the modern
`\Stripe\StripeClient` service pattern, so the eventual bump is smaller than the
file count suggests.

## Documentation

Phase 1 changes nothing user-visible, so no doc updates are due until Phase 2.
At Phase 2, the files listed in Phase 2 item 4 are updated to describe the 26.04 /
PHP 8.5 / PostgreSQL 18 stack as the current and only state.

## Open Decisions

1. ~~**CSV `$escape` parameter.**~~ RESOLVED 2026-08-01: go RFC 4180 — pass `''`
   at every site. Nothing round-trips through the platform, so no pair of sites
   must change together. The dead `adm/admin_user_add_bulk.php` is deleted rather
   than fixed. See Phase 1.6 and 1.7.
2. ~~**Dependency refresh timing.**~~ RESOLVED 2026-08-01: Brevo `^2.0` → `^5.0`
   only; constrain Postmark below v7 (v7 caps at 8.4 and would block install on
   8.5); leave Mailchimp alone; add the `config.platform.php` pin. Stripe stays
   out entirely, on its own spec. See Phase 1.8.
3. ~~**SCRAM on existing nodes.**~~ RESOLVED 2026-08-01: folded into the
   campaign's per-node checklist, gated on the `pg_authid` verifier check — no
   separate fleet sweep. See Phase 1.5.

**No open decisions remain.** Phase 1 is fully specified and ready to build in
order; Phase 2 is gated on 26.04.1 shipping.
