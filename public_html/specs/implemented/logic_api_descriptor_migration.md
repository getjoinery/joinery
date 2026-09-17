# Descriptor migration — retire `_logic_api()`

**Status:** DONE 2026-09-17. Steps 1 and 3 landed earlier: no logic file
defines `_logic_api()`, `ApiLogicEndpoint::resolveMeta()` reads descriptors
only, the docs mention no legacy companion, and
`tests/unit/core_api_mechanical_test.php` fails on any file that reintroduces
one. Step 2 is moot with the stubs already gone. Acceptance item 2 closed in
two batches on 2026-09-17: 26 descriptors outside the sign-in paths, then the
57 passkey, vault, password-reset and mailbox descriptors. Every descriptor
whose logic reads request input now declares an `input` schema (208 of 232
descriptors; the 24 without one read no input — status reads, option issuers,
catalog lists — and are complete as they are). Fields are optional unless the
logic refuses the request without them; values the logic compares strictly as
strings stay typed as strings; enums appear only where the logic already
rejects anything else. `DescriptorValidator` 1.3 adds the `object` type for
opaque structured values (a WebAuthn credential response, an unlocker, a
browser-produced wrapping blob), checked to be an object and passed through
untouched. Gates run after each batch: `db --changed`, `db --filter=api`,
`--filter=mailbox`, `--filter=vault`, `--filter=passkey`,
`--filter=account_security`, `--filter=password`, all passing.

**Prerequisite (done):** the REST API consumes descriptors natively with
`_logic_api()` as a fallback — see
[`implemented/descriptor_rest_api_core.md`](implemented/descriptor_rest_api_core.md).
This spec is the remaining half: author descriptors across the legacy estate,
then delete `_logic_api()` in one sweep. Formerly sub-piece 7d of
[`implemented/logic_code_refactor.md`](implemented/logic_code_refactor.md) Step 7.

## Problem

Two metadata companions coexist. As of 2026-07-16, 104 logic files define
`_logic_api()` and 83 define `_logic_descriptor()` — 85 files have
`_logic_api()` with no descriptor. (Note: the gap has *grown* since the
2026-07-09 snapshot of ~45, because the logic estate keeps growing; the
inventory grep below is the source of truth, not any snapshot.)
Legacy-only actions get no boundary validation and expose no input schema in
discovery, and every file carrying both companions is duplicated metadata
waiting to drift. The forward rule (CLAUDE.md, docs/api.md) already points new
code at descriptors, so the estate stops growing — this spec drains it.

## The work

### 1 — Author descriptors (~85 files, the bulk)

For each logic file with `_logic_api()` and no `_logic_descriptor()`: read the
logic, write an honest descriptor — description, `requires_session` (and any
`auth` block), `mutates`, and the `input` schema (field names, types,
required flags) matching what the logic actually reads.

Current inventory command (the list, not a snapshot, is authoritative):

```bash
grep -L "function.*_logic_descriptor" $(grep -l "function.*_logic_api" logic/*.php plugins/*/logic/*.php)
```

This is judgment work, not find-and-replace:

- **Schema accuracy is the risk.** A descriptor that omits a field the logic
  reads is harmless (undeclared fields pass through), but a field declared
  `required` that is actually optional — or typed more strictly than the
  logic accepts — makes the API reject requests the logic would have served.
  When in doubt, declare the field optional and let the logic's own
  validation decide.
- **Mixed files need a call about which POST action is the action surface**
  (the reason the original Step 3 deferred `booking_logic`, `cart_logic`,
  `survey_logic`, `event_sessions_logic`, `event_sessions_course_logic`).
- **Batching:** migrate in reviewable batches (e.g. 10 files), running the
  relevant functional tests after each batch. The `tests/functional/api/`
  suites exercise several of these actions end-to-end.

### 1b — Input schemas for descriptors that still lack one

A descriptor without `input` gets no boundary validation and discovery shows
`input: null`. Find the ones whose logic reads request input:

```bash
for f in logic/*.php plugins/*/logic/*.php; do
  grep -q '_logic_descriptor' "$f" || continue
  grep -q "'input'" "$f" && continue
  grep -qE '\$(post|get|input|data)\[' "$f" && echo "$f"
done
```

Write each schema from what the logic actually reads: optional when unsure,
never a type the callers do not already send. Batch by area and run
`php tests/run.php db --changed` after each batch.

### 2 — Metadata-loss diff pass

Before deletion, diff every `_logic_api()` return against its new descriptor:
some may carry hints (rate limits, ajax variants) not yet expressed in the
descriptor schema. Fold them into the descriptor vocabulary or accept the
loss explicitly — no silent drops.

### 3 — Retirement sweep

Once every `_logic_api()` file has a descriptor: delete all `_logic_api()`
stubs in one commit, then remove the fallback reads (`resolveMeta()` in
`includes/ApiLogicEndpoint.php`, the discovery closure in `api/apiv1.php`)
and the "legacy companion" passages in docs/api.md and docs/formwriter.md so
docs read as though descriptors always were the only companion.

## Acceptance

1. `grep -rn "_logic_api" logic/ plugins/*/logic/ includes/ api/` returns
   nothing.
2. `GET /api/v1/actions` lists every previously-exposed action, each with a
   non-null `input` schema.
3. The `tests/functional/api/` suites pass unchanged — no action's request
   contract tightened beyond what its logic actually accepts.
4. Descriptor-declared bad input on migrated actions returns
   `422 ValidationError` (spot-check per batch).

## Risk areas

- **Over-strict schemas** rejecting formerly-valid requests — mitigated by
  optional-when-unsure and per-batch functional test runs.
- **Sessionless actions** (`register`, password resets): their descriptors
  must keep `requires_session => false` exactly, or first-launch clients lose
  access. Verify against `browser_session_test.php` / `session_keys_test.php`.
