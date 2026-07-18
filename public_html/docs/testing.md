# Testing

The platform's tests share one harness library, one discovery runner, and one
web dashboard. Every test declares a small metadata header that says what it
touches and where it may run; the runner reads that header to decide what to
execute, and both the CLI and the dashboard consume the same result contract.

## The two axes: tier and env

Every test declares a **tier** (blast radius — what it touches) and an **env**
(where it is allowed to run). They are separate on purpose.

**tier** — drives which batch a test runs in:

| tier | meaning |
|---|---|
| `safe` | Pure, mocked, or rolled back. No persistent side effects. |
| `db` | Writes the dev database and self-cleans. |
| `test-db` | Runs against the copied **test** database (`DbConnector::set_test_mode()`). |
| `live` | Real external effects — sends mail, hits a storage bucket, uses Stripe test keys, drives a remote host. |

**env** — where a test may execute:

| env | meaning |
|---|---|
| `any` | Read-only or pure; safe even on production. |
| `prod-verify` | Mutative but deliberately prod-runnable and self-cleaning — its job is to verify the production environment itself (real deliverability sends, a bucket round-trip, the inbound loopback). Never part of a batch run. |
| `dev-only` | Writes fixtures, trips rate limits, uses the test database, or uses Stripe test keys. Hard-blocked when the `debug` setting is off. |

Enforcement is two-layer: the runner reads the header and refuses to spawn a
`dev-only` test when `debug` is off, and the harness re-checks at runtime, so
invoking a test directly by path offers no bypass. A missing or unparseable
`env` fails closed to `dev-only`.

## The metadata header

Every runnable test (`.php` or a `.sh` gate) carries a header comment. It is
read **without executing the file**, so the runner can enforce tiers before
running anything:

```php
/** @joinery-test
 * name: cloud_offload_engine
 * tier: safe            # safe | db | test-db | live
 * env: dev-only         # any | prod-verify | dev-only
 * needs: []             # e.g. [stripe-test-keys, macmini, mailgun, b2]
 * timeout: 180          # optional wall-clock cap in seconds (default 180, max 1800)
 */
```

Set `timeout:` only when a suite genuinely needs longer than the 180-second
default — a multi-minute build gate or a live third-party flow. The runner kills
a test that exceeds its cap and marks it failed. Tests that explicitly declare a
timeout beyond the web request window run from the CLI only (see the dashboard
section).

A file that looks like a test (`*_test.php`, `test_*.php`, or a `*_gate.sh`) but
has no header is listed as **undeclared** in the runner's summary — never
silently skipped.

## Running tests

From the command line:

```bash
php tests/run.php              # the safe tier
php tests/run.php db           # safe + db + test-db — the pre-deploy gate
php tests/run.php test-db      # only the test-database suites
php tests/run.php live         # only live tests (never implied)
php tests/run.php db --filter=api   # narrow by name or path substring
php tests/run.php --only=tests/unit/dns_resolver_test.php  # one exact test by repo-relative path
php tests/run.php db --timeout=30   # override every test's declared wall-clock cap (seconds)
php tests/run.php --list       # list discovered tests, run nothing
php tests/run.php --json       # emit the aggregate JSON contract (for CI)
```

`safe`, `db` and `test-db` are cumulative, so `php tests/run.php db` is the
pre-deploy gate and covers the model CRUD suite. `live` runs only when named and
never pulls in the others — it has real external effects, so it is always an
explicit choice. Each test runs in its own subprocess, so a fatal in one file
cannot take down the run. The runner exits non-zero if any test failed — it is
the CI entry point.

The `test-db` suites declare `needs: [test-db]`, and the runner probes the
connection itself rather than trusting the setting. An install without the
database copy skips them with a named reason instead of failing the gate; see
[The test database](#the-test-database).

A run that matches **zero** declared tests exits non-zero (code 2) with an
explicit message, rather than reporting a hollow green — a `--filter`/`--only`
typo or an empty tier is almost always a mistake, and the gate must not pass on
nothing.

An unrecognized or mistyped `tier:` in a header resolves to `live`, not `safe`:
`safe` runs on every `php tests/run.php`, so defaulting an unknown tier there
would be fail-open (a `tier: Live` typo could fire a real-effect suite in the
pre-deploy gate). `live` never runs unless named, so the typo fails safe — the
test simply does not run until its header is corrected. `env` fails closed the
same way, defaulting to `dev-only`.

A test's `needs` are enforced: before running, the runner probes each declared
dependency (`macmini`, `node`, `stripe-test-keys`, `mailgun`, `b2`, …). An unmet
need makes the test a reported **SKIP** with its reason — never a silent pass and
never a hard failure — so a box without the dependency stays green honestly. An
unrecognized need name is treated as met (it never blindly skips).

Any single test also runs on its own and prints a human summary, or emits the
contract with `--json`:

```bash
php tests/unit/dns_resolver_test.php
php tests/unit/dns_resolver_test.php --json
```

### The dashboard

`/tests/` (superadmin, permission 10) lists every discovered test grouped by
tier with its env and `needs`, plus per-tier "Run all" controls. Runs execute
through the `tests_run` API action, which spawns the runner with `--json`;
page JS calls `/api/v1` with the browser-session credential. On production
(`debug` off) `dev-only` tests render locked; `live` and `prod-verify` tests
require a confirm naming the side effect before they run.

"Run all" runs a tier's tests one request at a time, client-side, rendering each
result as it lands — so no single request spans a whole tier and results are
never lost to a proxy timeout. It runs exactly the tests the section shows (the
CLI keeps cumulative safe+db semantics; the dashboard does not). A test that
declares a `timeout:` beyond the web request window renders a disabled **CLI**
button — run it via `php tests/run.php`.

## Writing a test

Require the shared harness, declare the header, build sections of assertions,
and finish. The harness bootstraps PathHelper, Globalvars, SessionControl,
DbConnector, and LibraryFunctions for you.

```php
<?php
/** @joinery-test
 * name: my_feature
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');   // path is relative to your test
harness_boot();

section('Creation');
$user = make_user('MyFeature');                  // auto-cleaned at finish
check($user->key > 0, 'user was created');
ok('email is set', $user->get('usr_email') !== ''); // label-first alias

section('Behaviour');
harness_set_setting_mem('some_flag', '1');       // in-memory only, auto-restored
check(do_the_thing() === 'expected', 'thing does the thing');

harness_finish();                                 // MUST be last — prints/emits + exits
```

The assertion surface:

- `check($condition, $label, $detail = '')` — condition-first.
- `ok($label, $condition, $detail = '')` — label-first alias (same recorder).
- `section($title)` — group the checks that follow.
- `harness_skip($label, $detail = '')` — a skipped check (e.g. an unmet `needs`).

Fixtures and teardown (all LIFO, run automatically at finish or on crash):

- `make_user($suffix, $permission = 0)` — a test user, registered for cleanup.
  The generated email carries a per-process random token, so leftovers from a
  killed run can never collide with the next run's same-suffix fixture.
- `make_machine_key($user_id, $name, $permission = 4)` — an API key.
- `harness_register_row($table, $pkey_col, $id)` / `harness_register_user($user)`
  / `harness_register_key_id($id)`.
- `harness_defer(callable)` — any custom teardown.

Register every row a test creates — or register a parent whose declared
DB-level foreign keys (`foreign_key` in the field spec, see
[Deletion System](deletion_system.md)) cascade to the children. Never assume a
cascade that is not declared. The `referential_integrity` test (tier `safe`)
fails the gate whenever a run leaves orphan rows, stray `harnesstest_%` users,
or a serial sequence behind its table's `MAX(pkey)` — a leak surfaces in the
very next run with the table named, not later as flakiness in another suite.

Settings and databases:

- `get_setting_raw($name)` / `set_setting_raw($name, $value)` — persisted DB settings.
- `harness_set_setting_mem($key, $value)` — override a setting in the Globalvars
  in-memory cache only, restored at teardown. Never persisted.
- `harness_test_mode()` — switch to the copied test database and close it at
  teardown; use with tier `test-db`.

**`harness_finish()` is mandatory.** If the script ends without it — a fatal
error, an uncaught exception, an early `exit()` — the harness records a failing
check so a crash can never be misread as a pass. Do not scatter bare `exit()`
calls in a test body; return, or reach `harness_finish()`.

## Talking to the site over HTTP

`tests/lib/http.php` is the one HTTP client for tests — require it instead of the
harness (it pulls the harness in) whenever a test makes real requests. There is
no per-suite curl to copy, and nothing to configure: the base URL comes from the
`webDir` setting, and requests to that site are automatically pinned to the
machine's own origin IP so they bypass Cloudflare and keep a stable
`REMOTE_ADDR`.

```php
require_once(__DIR__ . '/../lib/http.php');
harness_boot();

$r = harness_request('POST', '/api/v1/action/foo', array(
    'headers' => harness_csrf_header($token),   // or key_headers(...) for API keys
    'body'    => array('a' => 1),               // arrays default to a JSON body
));
check($r['status'] === 200, 'foo ran', $r['raw']);
```

`harness_request($method, $url, $opts)` returns `status`, `body`/`raw`, `json`,
`headers` (array), `header_string`, `content_type`, `redirect_url`, and `error`.
`$url` is a path against the base URL, or a full absolute URL (signed links).
The options cover the whole surface: `encode` (`json`|`form`|`raw`|`multipart`),
`files` for multipart uploads, `jar` for a cookie session, `cookies` for extra
cookies, `follow` for redirects, `accept` (defaults to `application/json`, pass
`null` to omit), `timeout`, `insecure`, and `pin` to force origin pinning on/off.

For a browser (cookie) session rather than an API key:

- `harness_jar_new($prefix)` — a cookie jar file, deleted at teardown.
- `harness_web_login($jar, $email, $password)` — form-login and return the CSRF
  token from the resulting page, or `null` if login failed.
- `harness_jar_csrf($jar)` — the `joinery_api_csrf` cookie (present for anyone,
  anonymous included); `harness_meta_csrf($html)` reads the `joinery-api-csrf`
  meta tag instead (rendered only into a logged-in page). The two are not
  interchangeable — pick the one the endpoint expects.
- `harness_csrf_header($token)` — the `X-Joinery-Csrf` header a browser-session
  call must send.
- `harness_put_chunk($path, $key_headers, $content_range, $body)` — a raw-body
  chunk PUT for the upload transport.

A test may accept an optional `[base_url] [origin_ip]` on the command line; call
`harness_http_boot($argv)` before `harness_boot()` to honour it (runner flags
like `--json` are ignored). The REST API suites layer `api_test_boot()` /
`api_request()` / `key_headers()` on top of this in
`tests/functional/api/api_test_harness.php`.

## Calling a logic function directly

Admin and page logic is normally reached through `process_logic()`, which exits
on the post-save redirect — fatal to a test. `tests/lib/logic.php` calls the
logic function itself instead and hands back the `LogicResult`:

```php
require_once(__DIR__ . '/../lib/logic.php');

$result = harness_call_logic('logic/foo_logic.php', 'foo_logic',
    array('action' => 'add', 'name' => 'X'), 'POST');
check(!$result->error, 'save succeeded', (string)$result->error);
```

`harness_call_logic($path, $fn, $data, $method)` simulates the request method
(`REQUEST_METHOD`, `$_POST`/`$_GET`/`$_REQUEST`) and restores the superglobals
afterwards — `$method` matters, since form saves are gated on a POST while GET
actions (e.g. `new_version`) must not be sent as a form save. It does **not**
throw on failure, so a test can assert on the result; `harness_call_logic_ok()`
is the variant that throws, for fixture-setup steps where a failure should stop
the run.

## The result contract

A declared `.php` test **must** emit this contract. If it doesn't — it crashed,
or was never converted to the harness — the runner marks it failed regardless of
its exit code (a script that prints "Failed" but exits 0 must never read as
green). Only `.sh` gates are exit-code-only; that is their contract.

Every test emits the same JSON contract on a `--json` run (prefixed on the CLI
by the sentinel `@@JOINERY_TEST_RESULT@@` so the runner can locate it even after
error output). The runner and the dashboard consume only this:

```json
{
  "name": "cloud_offload_engine",
  "tier": "safe",
  "stats": { "total": 12, "passed": 12, "failed": 0, "skipped": 0 },
  "sections": [
    { "title": "Creation", "checks": [ { "label": "…", "passed": true, "detail": "" } ] }
  ],
  "duration_ms": 8
}
```

## The test database

`test-db` tests run against a copy of the dev database so CRUD is isolated from
live data. Manage the copy — create it, sync its schema with the live database —
from `/admin/admin_test_database`. The model CRUD suite
(`tests/models/models_test.php`, tier `test-db`) drives every data model's
generic CRUD/validation against that copy; if it reports schema errors, sync the
test database first.

## Plugin tests

A plugin's tests live in `plugins/{plugin}/tests/` and carry the same header.
They are discovered and listed alongside core tests (the dashboard includes
them), and are reachable directly at `/plugins/{plugin}/tests/{page}`
(superadmin). Follow the same harness conventions; the relative path to the
harness from a plugin test is `__DIR__ . '/../../../tests/lib/harness.php'`.
