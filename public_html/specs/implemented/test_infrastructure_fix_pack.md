# Test Infrastructure Fix Pack — Review Findings

## Status: active

A high-effort code review of the test_infrastructure_unification build (the
uncommitted diff on `security-levels`) confirmed 10 defects. Three break the
feature outright (the web dashboard cannot spawn tests at all, the three
mobile gate scripts fatal on a deleted function, and two declared live suites
are silently invisible to discovery); the rest are false-green reporting, a
permanently red safe tier, timeouts that kill long tests mid-run, and a pipe
deadlock. Every finding below was independently verified against the working
tree — none are speculative.

Work items are ordered by severity. Each names the file, the confirmed
failure, and the required fix. General rules: run `php -l` and
`validate_php_file.php` on every touched PHP file; bump `@version` where one
exists; docs are current-state-only.

---

## 1. Dashboard cannot spawn the runner — wrong PHP binary

**File:** `logic/tests_run_logic.php:41`

The web dashboard runs tests by spawning a subprocess, and it picks the
binary with `PHP_BINARY`. Under php-fpm (how this site is served)
`PHP_BINARY` is `/usr/sbin/php-fpm8.3`, which prints its usage text instead
of executing `tests/run.php`. Every Run button on `/tests/` returns "Runner
produced no parseable result". Only the CLI path works.

**Fix:** add a shared helper `harness_php_cli()` in `tests/lib/discovery.php`
that returns the CLI binary regardless of SAPI:

- `PHP_SAPI === 'cli'` → `PHP_BINARY`;
- otherwise `PHP_BINDIR . '/php'` (verified: `/usr/bin/php` exists on this
  stack) if `is_executable()`, else the bare string `'php'` (PATH lookup).

Use it in `tests_run_logic.php` in place of `PHP_BINARY`. No new setting —
derive, per the zero-config principle.

## 2. Deleted `harness_require_debug_mode()` fatals all three mobile gates

**Files:** `tests/functional/api/api_test_harness.php`; callers
`tests/functional/ios/setting_ctl.php:16`, `menu_probe.php:22`,
`phase3_fixtures.php:28`, `phase3_conversation_fixtures.php:28`

The harness rewrite replaced the old debug-mode gate with the header-driven
`harness_enforce_env()`, but the four iOS **tooling** scripts (deliberately
excluded from discovery — they are helpers, not tests) still call
`harness_require_debug_mode()` and die with an undefined-function fatal.
Reproduced live. Because `phase2_gate.sh`, `phase3_gate.sh`, and the Android
`member_gate.sh` shell out to `setting_ctl.php` as their first setup step,
all three mobile gates fail on every invocation.

**Fix:** re-add `harness_require_debug_mode()` to `api_test_harness.php` as a
small gate for tooling scripts that have no `@joinery-test` header: if the
`debug` setting is off, print a one-line refusal and `exit(1)`. Document in
its docblock that it exists for header-less tooling; tests themselves get the
gate from `harness_boot()`. Verify with
`php tests/functional/ios/setting_ctl.php get debug`.

## 3. Discovery basename exclusion of `run.php` erases two declared live suites

**File:** `tests/lib/discovery.php:30`

`harness_is_excluded_path()` excludes by basename, and the list contains
`run.php` — intended to keep the runner itself out of discovery, but it also
swallows `tests/functional/products/run.php` and
`tests/functional/subscription_tiers/run.php`, both declared live suites in
this same diff. Exclusion happens before header parsing, so they are neither
listed nor flagged undeclared — they vanish silently, and `--only=` on them
returns "Unknown test".

**Fix:** remove `'run.php'` from `$excl_files`. Exclude the runner itself by
exact path instead: in `harness_discover()`, skip the file whose path equals
`$root . '/tests/run.php'` (add the `$root` comparison there, where `$root`
is in scope, rather than widening `harness_is_excluded_path()`'s signature).
Verify `php tests/run.php --list` now shows both suites under `[live]`.

## 4. Missing result contract + exit 0 is reported PASS — false greens

**Files:** `tests/run.php:192`; offenders
`tests/integration/mailgun_test.php`, `tests/email/email_pattern_test.php`,
`tests/models/test_model_tester.php`

A declared PHP test that emits no result contract is classified by exit code
alone, and three declared-but-unconverted tests always exit 0 even when
their checks fail: `mailgun_test.php` hits a `$_REQUEST` password gate that
is always empty on CLI and does a bare `exit;` before testing anything;
`email_pattern_test.php` prints "Failed: N" but only exits non-zero on a
thrown exception; `test_model_tester.php` echoes a red HTML failure div and
falls through to implicit exit 0. All three show green on the dashboard and
in the aggregate gate no matter what happens.

**Fix (both layers):**

1. **Runner:** in `run_one()`, a declared `.php` test that emits no contract
   is always `status: 'fail'` with note `'no result contract emitted'` —
   regardless of exit code. Shell gates (`.sh`) keep exit-code semantics
   (that is their contract; the note already says so). The
   "conservatively... unless exit==0" comment block goes away.
2. **Convert the three offenders to the shared harness** (`harness_boot()`,
   `check()`, teardown if needed, `harness_finish()`), so they emit the
   contract and real exit codes:
   - `mailgun_test.php` — drop the `$_REQUEST` password gate entirely (the
     env/tier header plus the dashboard's live-tier confirm is the access
     control now); each send/verify step becomes a `check()`.
   - `test_model_tester.php` — replace the HTML div output with `check()`
     calls; it keeps exercising ModelTester's own counters.
   - `email_pattern_test.php` — see item 5, which owns this file's rework.

## 5. email_pattern_test.php sends real mail to the literal address "--json"

**File:** `tests/email/email_pattern_test.php:377` (the CLI entry block)

The deleted `tests/email/index.php` runner was the only path that fed the
`email_test_recipient` setting into this test. The surviving CLI entry
treats `argv[1]` as the recipient, so when the runner appends `--json`, the
test attempts 11 real pattern sends to the address `--json`. There is no
longer any path that uses the configured test recipient.

**Fix:** rework the CLI entry:

- Recipient resolution: first non-flag positional arg if present, else the
  `email_test_recipient` setting, else `test@example.com`. Any arg starting
  with `--` is never a recipient.
- Convert the entry to the shared harness (one `check()` per pattern result
  from `getSummary()`/`$results`, then `harness_finish()`), which also
  closes this file's half of item 4. The `EmailPatternTest` class internals
  do not change.

## 6. Fixed 180s timeout kills the long-running suites and skips their teardown

**Files:** `tests/run.php:44,154`, `tests/lib/harness.php` (parser + boot),
headers of the long suites

Every test is wrapped in `timeout -k 5s 180s`. The migrated long suites —
the three mobile gates (multi-minute Gradle/XCUITest builds on the Mac mini)
and the Stripe product/subscription testers (written for
`set_time_limit(600)`) — are killed mid-run, and SIGKILL bypasses
`register_shutdown_function` teardown, stranding Stripe test objects, DB
fixtures, and simulator/emulator processes.

**Fix:**

1. Add an optional `timeout: <seconds>` key to the `@joinery-test` header.
   `harness_parse_metadata()` parses it (default 180, clamp to 1–1800,
   non-numeric → default). `run_one()` uses the test's own timeout; the
   existing `--timeout=` CLI flag, when given, overrides all tests for that
   run.
2. Set `timeout:` on the suites that need it — 900 for the three gates
   (`member_gate.sh`, `phase2_gate.sh`, `phase3_gate.sh`), 600 for
   `tests/functional/products/run.php`,
   `tests/functional/subscription_tiers/run.php`, and adjust any other suite
   the executor observes exceeding 180s (e.g. the email live suites).
3. Teardown under SIGTERM: in `harness_boot()`, when on CLI and pcntl is
   available, enable `pcntl_async_signals(true)` and install a SIGTERM
   handler that calls `exit(1)` — the shutdown reporter then runs teardown
   and emits a failing contract inside the 5s grace window before SIGKILL.
   Guard with `function_exists('pcntl_signal')`.

## 7. Pipe deadlock: stdout drained to EOF before stderr

**Files:** `tests/run.php:159`, `logic/tests_run_logic.php:65`

Both spawn sites read the child's stdout to EOF, then stderr. A child that
writes more than the ~64KB pipe buffer to stderr before closing stdout
blocks forever (reproduced with a 200KB stderr write). In the runner the
180s timeout eventually SIGKILLs it — reported as a timeout regardless of
real results; in the web logic there is no timeout at all, so the request
hangs indefinitely.

**Fix:** in both places, stop using pipes for the child's output. Pass
`tmpfile()` handles in the `proc_open()` descriptor spec for fds 1 and 2,
`proc_close()` (blocks until exit, no drain ordering), then rewind and read
both files. The child can never block on a full pipe. Delete the
`stream_get_contents($pipes[...])` sequences.

## 8. Restore the unrelated spec deletion

**File:** `specs/FUTURE_personal_ai_recipes.md` (unstaged `D` in git status)

The diff deletes this vision spec outright. It is unrelated to the test
infrastructure work and still referenced by
`specs/implemented/joinery_ai.md:5` and `specs/platform_gap_analysis.md:26`.

**Fix:** `git checkout -- specs/FUTURE_personal_ai_recipes.md`. Nothing else.

## 9. Safe tier is red out of the box — manifest validator enforces an impossible rule

**Files:** `includes/PluginHelper.php:115,200-201`;
symptom in `tests/integration/components_manifest_test.php`

`php tests/run.php` (the pre-deploy gate) fails from day one:
`components_manifest` reports 3 failures because `PluginHelper::validate()`
requires every profileMenu slug to start with `{plugin directory name}-`.
That rule is wrong at the source, twice over:

- The slug charset rule two lines above forbids underscores, so for any
  underscore-named plugin (`dns_filtering`, `joinery_ai`) the two rules are
  mutually unsatisfiable — no slug can ever pass both.
- `docs/plugin_developer_guide.md` (§ slug rules, and the profileMenu field
  table) documents the plugin-name prefix as a **recommended convention,
  not validated** — "a bare slug like `shelf` will sync fine".

The three manifests (`dns_filtering`, `joinery_ai`, `mailbox`) follow the
documented convention with hyphenated names and must not change — their
slugs are seeded menu identity.

**Fix:** remove the prefix `elseif` branch (lines 200-201) from
`PluginHelper::validate()`, aligning the validator with the documented
contract. Keep every other check (charset, length, `core-` reservation,
duplicates). Then verify
`php tests/integration/components_manifest_test.php` passes 3 more checks
with 0 failed, and `php tests/run.php` exits 0.

## 10. Dashboard tier batch runs in one blocking request

**Files:** `tests/index.php` (runTier JS), `logic/tests_run_logic.php`

"Run all" posts one `{tier}` request that executes every matching test
serially inside a single FPM worker. Any non-trivial tier outlives the
Cloudflare/Apache request timeout (~100s): the browser shows "Tier run
failed" while subprocesses keep running server-side, and all results are
lost. A second problem hides in the same code path: posting `tier: 'db'`
runs safe+db cumulatively, but the JS only marks the clicked section's rows.

**Fix:** run batches client-side, one test per request:

- `runTier()` collects the section's runnable rows (skip locked) and awaits
  `callRun({ test: path })` for each in sequence, rendering each result as
  it lands. Each request is bounded by one test's timeout, results stream
  in, and cumulative-tier confusion disappears (the CLI keeps cumulative
  semantics; the dashboard section button runs exactly what it shows). The
  `tier` param stays in the API action for CLI-parity callers.
- Tests whose declared timeout exceeds 90s cannot finish inside the proxy
  window: render their button as ghost-style "CLI" (disabled, with title
  "exceeds the web request window — run via php tests/run.php"). The
  discovery data already reaches the page; include `timeout` in the per-test
  array `tests/index.php` builds.

## 11. Minor confirmed cleanups (same files, do while there)

- `tests/index.php:112` links `/docs/testing.md`, which 404s (no route
  serves markdown). Render it as plain text (`docs/testing.md`), no anchor.
- `tests/run.php:112` `rel()` duplicates `harness_rel()` from
  `tests/lib/discovery.php`, which run.php already requires. Delete `rel()`
  and call `harness_rel()`.

---

## Documentation (docs/testing.md)

Update `docs/testing.md` current-state-only:

- The `@joinery-test` header field list gains `timeout: <seconds>` (default
  180, max 1800) with one sentence on when to set it.
- The result-contract section states the rule from item 4: a declared PHP
  test that emits no contract fails; only `.sh` gates are exit-code-only.
- The dashboard section describes per-test batch execution and the CLI-only
  rendering for tests whose timeout exceeds the web request window.
- Recipient resolution for the email pattern test (`email_test_recipient`
  setting, positional-arg override) lands in whichever section covers the
  email suites.

## Acceptance

1. `php tests/run.php --list` shows `tests/functional/products/run.php` and
   `tests/functional/subscription_tiers/run.php` under `[live]`, and 0
   regressions elsewhere in the declared list.
2. `php tests/run.php` (safe tier) exits 0 — components_manifest green.
3. On `/tests/` in the browser: a single safe test runs and renders a
   result; "Run all" on the safe tier streams per-test results; a >90s test
   renders the CLI marker.
4. `php tests/functional/ios/setting_ctl.php get debug` prints the setting
   value (no fatal).
5. `php tests/email/email_pattern_test.php --json` with no recipient arg
   resolves the recipient from the `email_test_recipient` setting (verify
   the address in output; do not assert on live deliverability).
6. Deliberately break one check in a converted no-contract offender (e.g.
   `test_model_tester.php`) and confirm `tests/run.php` reports it FAIL;
   revert.
7. A scratch test that writes 200KB to stderr then exits 0 passes through
   `run_one()` without deadlock or timeout; delete the scratch file after.
8. A scratch test with `timeout: 5` that sleeps 60s is killed, reports FAIL
   with the timeout note, and its `harness_defer()` teardown ran (observable
   side effect cleaned up); delete the scratch file after.
9. `git status` no longer lists `specs/FUTURE_personal_ai_recipes.md` as
   deleted.
10. `php -l` and `validate_php_file.php` clean on every touched PHP file.
