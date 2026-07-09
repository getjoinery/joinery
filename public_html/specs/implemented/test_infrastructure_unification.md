# Test Infrastructure Unification — One Harness, One Runner, One UI

## Status: active — design

Unify the platform's test estate behind a single shared harness library, a
tier-aware discovery runner, and one superadmin web dashboard. Based on a full
survey of the estate (July 2026), summarized below. The refactor is
deliberately incremental: the good harnesses keep their internals and adopt a
common result contract; only the duplicated boilerplate is deleted.

---

## Part 1 — Survey findings (July 2026)

### Scale

- **84 test files, ~22,000 lines**: 70 under `tests/`, 14 under
  `plugins/mailbox/tests/` (the only plugin suite), plus two strays in
  `utils/` (`test_ab_testing.php`, `test_components.php`) that belong in the
  tree.
- **No aggregate runner** exists anywhere. Every test is invoked individually
  by path; four suites have their own separate web index pages.
- **No PHPUnit** (no `phpunit.xml`, no `require-dev`, no vendor binary), **no
  CI config**, and **no `docs/testing.md`** — testing guidance is scattered
  across CLAUDE.md and six topic docs.

### Five incompatible harness styles coexist

| Style | Where | Reporting | CI-able |
|---|---|---|---|
| Hand-rolled micro-harness | ~30 files: `calendar/`, `unit/`, most of `integration/`, `scaffold/`, `schema/`, mailbox plugin | Each file re-declares an ~8-line `check()`/`ok()` counter, `exit(1)` on failure | Yes |
| `api_test_harness.php` (procedural) | `functional/api/` — 5 suites | `check()`, `section()`, fixture factories, LIFO teardown, `harness_finish()` exit code, debug-mode prod gate, Cloudflare-bypass cURL | Yes — the best harness in the tree |
| `ModelTester` / `MultiModelTester` | `models/` | Static pass/fail counters, echoes HTML `[PASS]` spans | No — HTML only |
| `EmailTestRunner` + suites | `email/` | Suites return nested `['passed'=>bool,...]` arrays; web dashboard renders | No |
| Monolithic web testers | `functional/products/` (1,827 lines), `functional/subscription_tiers/` (1,367 lines) | Own `$test_results`, HTML output; `exit(1)` on exception only, not assertion failure | No |

The single most duplicated block is the ~8-line pass/fail counter. Also
copy-pasted per file: the bootstrap preamble, `set_test_mode()` ctor/dtor
pairs, "which DB am I on" banners, and settings snapshot/restore via
reflection.

### Safety profile

Tests fall into four de facto tiers, currently undeclared and enforced only
by convention:

- **safe** — pure/mocked/rolled-back (all of `unit/`, most of `integration/`,
  `scaffold/`, `schema/`): ~half the estate.
- **db** — writes the dev DB and self-cleans (`functional/api/` suites,
  `functional/files/`, two calendar tests, email fixtures).
- **test-db** — runs against the copied test database via
  `DbConnector::set_test_mode()` (`models/`, ProductTester,
  SubscriptionTierTester), supported by `adm/admin_test_database.php`.
- **live** — real external effects: `cloud_storage_live_b2_test.php` (real B2
  bucket), `mailgun_test.php` / `email_pattern_test.php` (11 sends) /
  `auth_analysis.php` / email ServiceTests+DeliveryTests (real mail),
  Product/Tier testers (Stripe test keys), `ios/phase2_gate.sh` (mutates
  settings, drives the Mac mini).

Nothing stops a live-tier test from being run by accident except reading its
source.

### Dead code found

- `tests/email/legacy/email_send_test.php` (764 lines) — duplicate of
  `auth_analysis.php`.
- `tests/integration/phpmailer_test.php` (573 lines) — `extends TestCase`
  with no PHPUnit in the repo; cannot run.
- `tests/email/index.php` links `legacy/email_test_harness.php`, which does
  not exist.

### Coverage map

Strong: REST API (authz, session keys, idempotency, browser session, app
platform), email outbound + inbound, cloud storage/offload, OAuth2/SecretBox,
signed URLs, calendar/booking/recurrence math, routing, FormWriter JSON +
visibility contracts, error handling, scaffolding, schema index management.
Broad-but-shallow: all 98 data models get generic CRUD/validation via
ModelTester. Partial: products/cart/coupons and subscription tiers (two big
browser-run testers, not CI-able).

Zero coverage, ordered by risk:

1. **Authentication flows** — login (incl. rate limiting), registration,
   password reset 1/2, TOTP verify, forced password change. All 8 auth logic
   files untested.
2. **Stripe webhooks** (`ajax/` handlers) — payment truth arrives here;
   nothing exercises it.
3. **Event lifecycle** — registration, capacity/waiting list, withdraw,
   sessions/courses, recurring-instance materialization.
4. **Logic layer generally** — of 53 logic files, only `change_tier_logic`
   has dedicated coverage. `docs/logic_architecture.md` references a
   `tests/logic/` directory that does not exist.
5. Deletion cascades (`$foreign_key_actions`, soft-delete cascading),
   scheduled-task runner, messaging privacy/blocks, questions/surveys,
   product requirements, notifications, analytics, uploads/photos.
6. Plugins: bookings (0), server_manager (0), items (0), dns_filtering
   (SSRF-guard units only), joinery_ai (1 test).

---

## Part 2 — Design

### Decision: no PHPUnit

The estate is tied to the platform bootstrap (PathHelper, Globalvars,
DbConnector singletons, test-DB mode, the debug-mode prod gate), and
`api_test_harness.php` already proves the right shape for this codebase.
Generalize it rather than importing a framework. This also keeps the
web-dashboard and CLI paths identical — one output contract instead of a
framework's reporter plus a bespoke web layer.

### 2.1 Shared harness library — `tests/lib/harness.php`

Procedural, generalized from `tests/functional/api/api_test_harness.php`:

- `harness_boot(array $meta)` — bootstraps PathHelper/Globalvars/DbConnector,
  parses CLI args / query params, applies the test's declared tier rules
  (below), and registers the shutdown reporter.
- `check($cond, $label)`, `section($title)` — the assertion surface. One
  counter, one place.
- Fixture factories with **LIFO teardown**: `harness_defer(callable)` plus
  the existing `make_user()` / `make_machine_key()` style factories, moved
  here so any suite can use them.
- `harness_settings_snapshot()` / restore — the reflection-based Globalvars
  in-memory settings override that the cloud and inbound tests each
  re-implement, as one helper.
- `harness_test_mode()` — pairs `DbConnector::set_test_mode()` /
  `close_test_mode()` with automatic teardown, replacing the copy-pasted
  ctor/dtor pairs and the "which DB am I on" banner.
- `harness_enforce_env()` — runtime enforcement of the test's declared `env`
  (see §2.2): `dev-only` tests refuse to run unless the `debug` setting is on
  (generalizing the api harness's `harness_require_debug_mode()` gate), so a
  dev-only test invoked directly by path on production still refuses. `any`
  and `prod-verify` tests pass the gate.
- `harness_finish()` — prints the human summary on CLI, emits the JSON result
  contract when `--json` (CLI) or JSON output is requested (web), and always
  `exit($failed ? 1 : 0)`.

**Result contract** (the `error_handling_test.php ?ajax=1` envelope,
promoted): `{name, tier, stats: {total, passed, failed, skipped}, sections:
[{title, checks: [{label, passed, detail?}]}], duration_ms}`. Every runner and
the dashboard consume only this.

Migration of the ~30 hand-rolled files is mechanical: delete the local
counter block and bootstrap preamble, require the lib, add the metadata
header. Assertions and test bodies do not change.

### 2.2 Test metadata + discovery runner — `php tests/run.php`

Every runnable test declares a parseable header comment (readable without
executing the file, so the runner can enforce tiers before running anything):

```php
/** @joinery-test
 * name: cloud_offload_engine
 * tier: safe            # safe | db | test-db | live      (blast radius)
 * env: dev-only         # any | prod-verify | dev-only    (where it may run)
 * needs: []             # e.g. [stripe-test-keys, macmini, mailgun]
 */
```

`tier` and `env` are deliberately separate axes: tier describes **what a test
touches** (drives batching and side-effect warnings), env describes **where it
is allowed to execute**. Three env values:

- **`any`** — read-only or pure; safe on any environment including
  production (routing GETs, DNS record checks, pure unit tests).
- **`prod-verify`** — mutative, but deliberately prod-runnable: its purpose
  is verifying the production environment itself, with acceptable
  self-cleaning side effects (email deliverability tests that send real mail
  and check prod's SPF/DKIM, the B2 round-trip against the real bucket, the
  inbound loopback). Never included in batch runs; each invocation is
  explicit.
- **`dev-only`** — hard-blocked outside dev: anything that writes fixtures,
  trips rate limits, uses the test database, or exercises Stripe test keys.

A missing or unparseable `env` **fails closed to `dev-only`**. Enforcement is
two-layer: `run.php` reads the header and refuses before spawning the
subprocess, and `harness_enforce_env()` re-checks at runtime (§2.1) so direct
invocation by path offers no bypass.

`tests/run.php`:

- Discovers `*_test.php` under `tests/` and `plugins/*/tests/`, plus `.sh`
  gates that carry the header in a comment.
- `php tests/run.php` runs the **safe** tier. `php tests/run.php db` runs
  safe + db. `test-db` and `live` run **only when named explicitly** and
  never implicitly include each other. `--filter=<substr>` narrows by name
  or path; `--json` emits the aggregate contract.
- Runs each test in a subprocess (isolation between tests; a fatal in one
  file cannot take down the run), aggregates the per-test contracts, and
  exits non-zero if any test failed. This is the pre-deploy gate and makes
  the suite CI-able the day it exists.
- Files without a header are listed as **undeclared** in the summary rather
  than silently skipped, so the migration's tail is always visible.

Relocations into discovery scope: `utils/test_ab_testing.php` →
`tests/functional/ab_testing/ab_testing_test.php`; `utils/test_components.php`
→ `tests/integration/components_manifest_test.php`.
(`utils/email_send_test.php` and `utils/diagnostics.php` are operational
diagnostics, not tests — they stay.)

### 2.3 One web dashboard — `tests/index.php`

A single superadmin page served by the existing `/tests/*` route
(min_permission 10 in serve.php); the plugin route
`/plugins/{plugin}/tests/{page}` stays for direct access but the dashboard
lists plugin tests too.

- Lists every discovered test grouped by tier with its name, path, and
  `needs`, plus per-tier "run all" controls.
- Runs execute through an API action (`tests_run` via the `_logic_api()`
  opt-in, permission 10), which spawns the subprocess with `--json` and
  returns the result contract; page JS calls `/api/v1` with the
  browser-session credential and renders results. No new `/ajax/` endpoints.
- **live**-tier tests render with an explicit confirm step naming the side
  effect ("sends 11 real emails") before the run button activates.
- Env awareness: when the `debug` setting is off (production), `dev-only`
  tests render locked with the reason shown; `prod-verify` tests require the
  same named-side-effect confirm as live-tier tests. `any` tests run freely.
- Joinery System theme, vanilla JS, `.jy-ui` styling. The per-suite pages
  `tests/models/index.php` and `tests/email/index.php` are retired once
  their suites emit the contract (Phase 4); the Product/Tier `run.php`
  wrappers are replaced by dashboard entries.

### 2.4 Converge the big harnesses — adapt, don't rewrite

`ModelTester`/`MultiModelTester`, `EmailTestRunner`+suites, `ProductTester`,
and `SubscriptionTierTester` keep their internals and gain a contract-emitting
mode:

- **ModelTester**: separate result data from presentation — collect
  per-model/per-check results into the contract structure instead of echoing
  HTML spans; the dashboard renders them. CLI invocation
  (`php tests/models/run_all.php --json`) becomes possible, making the model
  suite CI-able for the first time. Read-only-on-live vs CRUD-on-test-db
  behavior is unchanged; the runner maps it to tier `test-db`.
- **EmailTestRunner suites** already return structured arrays — a thin
  translation to the contract in the runner, not in the four suite classes.
- **ProductTester / SubscriptionTierTester**: route their `$test_results`
  through the contract and make assertion failures (not just exceptions)
  produce a failing exit; the live-Stripe-keys abort stays.

### 2.5 Cleanup

- Delete `tests/email/legacy/` and `tests/integration/phpmailer_test.php`.
- Remove the dead "Test Harness (CLI)" link from the email dashboard (page
  itself retired in Phase 4).
- `tests/functional/ios/setting_ctl.php` is tooling, not a test — no header,
  excluded from discovery; `phase2_gate.sh` gets a `live` header with
  `needs: [macmini]`.

---

## Part 3 — Phases

Ordered because each phase builds on the previous one's contract. (Phases 1–2
deliver most of the value; 3–5 can land independently afterward.)

1. **Harness library** — extract `tests/lib/harness.php` from
   `api_test_harness.php`; `api_test_harness.php` becomes a thin wrapper
   adding only its API-specific pieces (`api_request()`, origin-IP pinning).
   Migrate the ~30 hand-rolled files. Add metadata headers everywhere.
2. **Runner** — `tests/run.php` with tier enforcement, subprocess isolation,
   aggregate exit code, `--json`. Relocate the two `utils/` tests.
3. **Dashboard** — `tests/index.php` + `tests_run` API action; live-tier
   confirms.
4. **Big-harness convergence** — ModelTester contract mode (the bulk of the
   work), email runner translation, Product/Tier exit-code fixes; retire the
   per-suite index pages.
5. **Cleanup + docs** — delete dead files; write `docs/testing.md` (see
   below); update the CLAUDE.md `/tests/` bullet and testing notes via
   `/admin/admin_agent_files` (CLAUDE.md is DB-managed, never edited on
   disk).

## Part 4 — Documentation

New `docs/testing.md`, written current-state-only per the documentation
rules: the tier model, the metadata header, how to run (`php tests/run.php
[tier]`, the dashboard at `/tests/`), how to write a new test with
`tests/lib/harness.php`, the result contract, the test-database workflow
(`adm/admin_test_database.php`), and plugin test conventions
(`plugins/{plugin}/tests/`). Existing scattered references (docs/api.md,
docs/mobile_apps.md, docs/file_signed_urls.md, docs/validation.md,
docs/email_system.md, docs/logic_architecture.md) link to it rather than
duplicating invocation instructions; the `tests/logic/` phantom reference in
docs/logic_architecture.md is corrected.

## Part 5 — Coverage backlog (follow-on work, not this spec)

The survey's gap list, ordered by risk, for future specs once the
infrastructure lands. Each becomes cheap to add because the harness, tiers,
runner, and dashboard already exist:

- **P1 — Authentication flows**: login incl. rate limiting, registration,
  password reset 1/2, TOTP, forced password change. The harness already does
  real web logins with cookie jars (`browser_session_test.php` pattern).
- **P1 — Stripe webhooks**: recorded test-mode payloads with valid signatures
  fed to the `ajax/` handlers; assert order/subscription state transitions.
- **P2 — Event lifecycle**: register, capacity, waiting-list promotion,
  withdraw, sessions/courses, recurring-instance materialization.
- **P2 — Logic-layer pattern**: call `*_logic()` with fake GET/POST, assert
  on the LogicResult; establishes `tests/logic/` for real, starting with
  checkout/cart_charge/billing.
- **P3 — Deletion cascades**: a generic walk of `$foreign_key_actions` and
  soft-delete cascade behavior, added to ModelTester.
- **P3 — Scheduled-task runner**; **P3 — messaging privacy/blocks**.
- **P4 — Plugin baselines**: bookings, server_manager job lifecycle,
  dns_filtering block model/tier gating, surveys/questions answer storage,
  upload validation.
