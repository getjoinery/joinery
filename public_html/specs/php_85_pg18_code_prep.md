# Code Preparation for PHP 8.5 and PostgreSQL 18

**Status:** Phase 1 partially BUILT. 1.2's no-op deletions, 1.7 and 1.9 landed
2026-08-03 and are committed (`c8c085b3`); 1.1 and the rest of 1.2 landed
2026-08-04 and are uncommitted. **1.2 is now complete.** What remains of Phase 1
is 1.3–1.6 and 1.8. Phase 2 remains gated on the OS campaign.
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

### 1.3 Remove the IMAP extension dependency

PHP 8.4 unbundled `ext/imap` to PECL, so 26.04 will not carry a distro
`php8.5-imap`. The only consumer in the tree is `tests/email/auth_analysis.php`,
which opens Gmail over IMAP for authentication analysis; production inbound mail
uses `bytestream/horde-imap-client`, which is pure PHP and unaffected.

- Drop `php8.3-imap` from the package list at `install.sh:1812`.
- Guard `tests/email/auth_analysis.php` to skip when `imap_open()` is absent.
- The copies in `maintenance_scripts/archive/` are archived scripts; leave them.

### 1.4 Installer PHP parameterization

`install.sh` carries 37 occurrences of `8.3`: the package list from `:1803`, the
Apache module (`a2enmod php8.3` at `:1844`), the FPM service (`:2005`, `:2208`),
and the INI paths under `/etc/php/8.3/apache2/` (`:2013` onward). Detect the
distro's PHP once and derive package names, module name, service name, and INI
directory from it. On 24.04 this resolves to 8.3 and produces the same
configuration it produces today, so it is safe to land and exercise immediately.
`utils/list_dependencies.php` already derives its `php{MAJOR}.{MINOR}-{ext}`
prefix this way and is the pattern to follow.

Same treatment for `plugins/mailbox/provisioning/install_email.sh:179`, which
pins `php8.3-sqlite3` in its `PACKAGES` array.

Widening the OS gate at `install.sh:1722` to accept both 24.04 and 26.04 is
additive and belongs here, but only after the parameterization above — otherwise
the gate would admit a release the script cannot yet configure. The comment block
above the gate, and the `--allow-unsupported-os` help text, both state that PHP
8.3 paths are hardcoded; those statements stop being true and must be rewritten
in the same change.

### 1.5 PostgreSQL authentication hardening

`install.sh` writes `md5` as the authentication method throughout the generated
`pg_hba.conf` (`:1931`–`:1960`), and both `install.sh` (`:1982`, `:1991`) and
`Dockerfile.template` (`:139`, `:142`) contain a `sed` dance that flips the
`postgres` local line between `trust` and `md5` while setting the role password.

PostgreSQL 18 deprecates MD5 passwords and warns on `CREATE ROLE`/`ALTER ROLE`
that set one. In practice nothing here creates an MD5 verifier —
`password_encryption` has defaulted to `scram-sha-256` since PG 14, and an `md5`
line in `pg_hba.conf` accepts a SCRAM verifier — so no warning fires and nothing
breaks on 18. The change is hardening ahead of the eventual removal, not a fix.

Switch the generated rules to `scram-sha-256`. This works on PG 16 and is safe to
land now. One caution: the `sed` match strings appear in two files and must be
updated together or they silently match nothing.

The `md5` keyword is already vestigial. `password_encryption` has defaulted to
`scram-sha-256` since PG 14, and verified on the dev box 2026-08-01 both real
roles (`postgres`, the site role) store SCRAM verifiers — so an `md5` line in
`pg_hba.conf` is accepting SCRAM authentication today. Changing it is deleting a
word that stopped meaning anything, not migrating an auth method.

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

Step 1 is not optional. Flipping a node to `scram-sha-256` while a role still
holds an md5 verifier locks that role out immediately. The reason not to simply
skip the whole thing is that `md5` is on PostgreSQL's removal track and procedure
B nodes are exactly the ones that never get rebuilt out of it.

### 1.6 CSV escape parameter — RFC 4180

PHP 8.4 deprecates relying on the default `$escape` for `fputcsv()`, `fgetcsv()`,
and `str_getcsv()`. The default is a backslash, which puts the parser into an
escape state that suppresses the following quote — a rule no other spreadsheet
tool implements. Pass `''` explicitly at every site, making the platform RFC 4180
compliant, which is what Excel, Google Sheets, and Google Contacts all emit and
expect. Valid on 8.3.

Nothing round-trips through the platform — the table export is download-only and
the contact import reads files other tools produced — so there is no pair of
sites that must change together.

- `data/admin_tableexport_data.php:33` and `:42` — writes arbitrary table
  contents for admin download. The highest-impact site: JSON blobs, serialized
  columns, and file paths all contain backslashes, and the current output form
  is misread by Excel.
- `plugins/mailbox/includes/MailboxContacts.php:418` and `:447` — parses Google
  Contacts exports, which are RFC 4180. A backslash in a name or notes field can
  currently swallow the following quote and shift every subsequent column.
- `includes/ApiAuth.php:135` — splits an API key's IP allowlist. IP addresses
  contain neither backslashes nor quotes, so this is a no-op in practice; change
  it for consistency.
- Test tooling under `tests/tools/` and `plugins/mailbox/tests/` follows the same
  rule.

**Related defect, not fixed here:** `MailboxContacts::parseCsv()` splits its input
on newlines with `preg_split()` before parsing any field, so a quoted field
containing a newline — common in the Google Contacts "Notes" column — breaks the
row alignment regardless of the escape setting. Worth its own fix; out of scope
for an upgrade-prep pass.

### 1.7 Remove dead bulk-user page — BUILT 2026-08-03

`adm/admin_user_add_bulk.php` called `fopen("test.csv", "r")` against a hardcoded
relative filename that does not exist, with no upload and no form, and echoed
raw fields to the page. It was absent from `admin_menus.json` and from
`amu_admin_menus`, and nothing in the tree linked to it; reaching
`/admin/admin_user_add_bulk` with permission 5 rendered a blank page. Deleted,
after confirming zero code references and zero `amu_admin_menus` rows. This also
removed one site from 1.6.

### 1.8 Composer floor, platform pin, and dependency constraints

**Floor.** `public_html/composer.json` declares `"php": ">=7.4"`. Raise it to
`>=8.3`, the declared floor from the compatibility policy above. This does not
change any currently installed version — it makes the floor machine-readable,
which is the only enforcement the policy gets.

**Platform pin.** Add `config.platform.php` set to the floor. Without it,
Composer resolves against whatever PHP the build box happens to run, so a
`composer update` on a 26.04 box could select a package that no longer installs
on an 8.3 node. With it, resolution is deterministic and a package that cannot
satisfy the floor fails loudly at resolution time instead of at install time on
someone else's server.

**Constraint audit — the real 8.5 dependency risk.** All 86 installed packages
that declare a PHP constraint declare an *open* one, so `composer install` works
on 8.5 with the current lockfile untouched. The hazard is not today's tree; it is
a future update pulling in an upper-bounded constraint. The live example:
`wildbit/postmark-php` v7.0.0 requires `~8.1 || ~8.2 || ~8.3 || ~8.4`, which
excludes 8.5 — while the installed v4.0.5 declares `>=7.0.0` and is fine.
Constrain Postmark below v7 until it admits 8.5. Re-run the audit
(every `require.php` in `vendor/composer/installed.json`, flagged if it cannot
admit the target version) after any dependency change.

**Brevo.** `getbrevo/brevo-php` is the source of 363 of the tree's 462 vendor
deprecation notices. v5.0.1 requires `^8.1` — open, safe on 8.5 — so move the
constraint from `^2.0` to `^5.0`. Three majors, but the blast radius is one file,
`includes/email_providers/BrevoProvider.php`, which touches only
`Brevo\Client\Configuration`, `Api\TransactionalEmailsApi`, `Api\AccountApi`,
`Model\SendSmtpEmail`, and `ApiException`. Verify those symbols survived the
v2→v5 move; `tests/integration/email_inline_attachments_test.php` already
exercises `BrevoProvider::buildBaseEmail()` and is the regression check.

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
  the built subset (1.1, all of 1.2, 1.7, 1.9): 83/83 tests, 2116 checks,
  0 failed.**
- `php tests/run.php db` before committing Phase 1, and again before publishing.
  **Green as of 2026-08-04 for the built subset: 226/226 tests, 6912 checks,
  0 failed, 159 skipped.**
- `validate_php_file.php` reports `Missing: 0` on every file touched so far, with
  one expected exception: `LearnSpamFeedback.php` flags
  `http_get_last_response_headers()`, which genuinely does not exist on the PHP
  8.3 running the validator and is guarded by `function_exists()` for that
  reason. Note the validator *executes* its target, so it is run only on
  class/definition files — `utils/` scripts and other run-on-include bodies get
  `php -l` and review by eye.
- `tests/deploy/syntax_sweep_test.php` covers the parse level for every file the
  deployed site can load; it is the existing deploy gate and needs no change.
- Phase 1.1 specifically: confirm on a current 24.04 node that an upgrade
  installing a declared extension still reloads FPM. The bug being fixed is
  silence, so the test has to assert the reload happened, not that the command
  returned.

## Out of Scope

- The fleet migration itself, per-node procedure, and ordering — see
  `specs/fleet_ubuntu_2604_postgres_upgrade.md`.
- Multi-distro support beyond Ubuntu — see `specs/multi_distro_install_refactor.md`,
  which covers the same `install.sh` parameterization for a wider target set. If
  that spec is built first, Phase 1.4 here is subsumed by it.
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
