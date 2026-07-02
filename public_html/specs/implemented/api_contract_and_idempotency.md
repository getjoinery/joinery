# API Contract Freeze + Idempotent Writes

Pin the response shapes clients may rely on, fix drift while nothing
shipped depends on it, and make mutating actions safe to retry — all
before the first phone app freezes the contract in a store binary.

**Problem.** After the first app ships, changing a response shape means
forcing upgrades through 426. And phones on flaky networks retry: a
double-submitted `send` mails twice, a retried `cart` action double-adds.

## Change 1 — the contract

A "Contract" section in `docs/api.md` pinning what clients may rely on:
the response envelope, the error shape (`errortype` + message + field
errors), pagination parameters and response form, timestamp format (UTC,
ISO), and naming conventions. Then an audit of the existing action and
CRUD surfaces against it, fixing drift **now**, while no shipped client
depends on the inconsistencies. The audit output is part of the
deliverable (what was checked, what changed).

## Change 2 — idempotency

Mutating actions accept an `Idempotency-Key` header (hyphen form, like the
client headers):

- New data class `api_idempotency_keys` (`aik_` prefix): key hash, owning
  credential (API key id or user id), action name, request-body hash,
  response status + body, create time.
- First request with a key executes normally and stores the outcome; a
  retry with the same key and same request-body hash returns the stored
  response without re-executing; the same key with a *different* body is
  rejected as a client error.
- Keys expire (24h) via a scheduled task purge (`docs/scheduled_tasks.md`
  patterns). No header → today's behavior, unchanged.
- Both credential types (API keys and the browser-session credential —
  `specs/implemented/api_browser_session_credential.md`) get the same
  behavior.

## Change 1 audit output (completed 2026-07-02)

**Checked:** every JSON emission point on `/api/v1` — `api_error()` /
`api_success()` / `api_translate_logic_result()` and the CRUD verb +
collection blocks in `api/apiv1.php`; `ApiLogicEndpoint` (action + form
faces); `ApiAuthEndpoint` (login/session/logout payloads);
`ManagementApiRouter` + all 7 management handlers;
`SystemBase::export_for_api()`; the data payloads of all 24 core
`_logic_api()` actions and all 15 dns_filtering actions.

**Changed (drift fixed):**

- CRUD reads serialized timestamp columns as PHP `DateTime` internals
  (`{"date": …, "timezone_type": 3, "timezone": "UTC"}`) —
  `export_for_api()` now emits contract strings, recursively through
  derived embeds. New shared normalizer `LibraryFunctions::api_timestamp()`.
- `api_error()` emitted `data: ""` (string) while action errors emitted an
  object, and prefixed messages with `Error: ` while action errors did not.
  Error `data` is now always an object and `error` is the bare message.
- Collection pagination echoed `page`/`numperpage` as raw query strings and
  `num_results` untyped — all three now integers.
- dns_filtering `exportDevice()`: `allow_device_edits` was `(int)` among
  sibling `(bool)` flags (now bool); `create_time`/`activate_time` carried
  raw microsecond precision (now contract strings).

**Checked, documented as-is (no change):**

- CRUD 400 `TransactionError` for a missing/unauthorized row (not 404) —
  long-documented behavior, kept.
- Management handlers' key-naming variance (`version` vs `system_version`
  vs `joinery_version`; `db_list` vs `databases`) — internal control plane
  consumed only by server_manager; excluded from the client contract.
- Page-oriented action payloads returning framework-object husks
  (`{"key": N}`) and occasional HTML fragments (e.g. `checkout`
  `sections[].summary`, `event_sessions` `error_message`, `security`
  `qr_uri`) — declared non-contract in docs/api.md § Contract; each
  action's payload becomes contract when documented as an API surface
  (the mobile specs drive that per-action work). Verified no secret
  leakage: `json_encode` of framework objects emits only public `key`.

## Sequencing

The contract audit completes before the first app store submission
(`specs/ios_app_platform.md` Phase 4); idempotency lands with that spec's
Phase 1 API work. App clients (`specs/ios_app_platform.md`,
`specs/android_app_platform.md`) send `Idempotency-Key` on all mutating
calls; `specs/mobile_native_email.md` `send` is the marquee case.

## Tests

- Duplicate key + same body executes once and replays the stored response.
- Same key + different body rejected.
- Keys scoped per credential (two users may use the same key string).
- Expiry purges via the scheduled task.
- Envelope audit assertions folded into the existing API test suites.

## Acceptance checklist

1. Every documented action and CRUD response passes the envelope audit;
   the Contract section in `docs/api.md` matches observed behavior.
2. A mutating action retried with the same `Idempotency-Key` executes
   once; the retry receives the original response.
3. Requests without the header behave exactly as before.

## Out of scope

- API versioning schemes beyond the existing 426 handshake.
- Idempotency for `/ajax/` endpoints (legacy surface).

## Versioning

- Bump `@version` on each modified API core file; new
  `data/api_idempotency_keys_class.php` starts at 1.0.

## Documentation deliverables (on implementation)

- `docs/api.md` — the Contract section and the `Idempotency-Key` header.
