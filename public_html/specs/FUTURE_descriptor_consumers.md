# REST API consumes descriptors natively

**Parent spec:** [`implemented/logic_code_refactor.md`](implemented/logic_code_refactor.md) — this spec covers Step 7.

**Status:** Not started. Steps 1–5 of the parent spec are done; this can begin once descriptor coverage is high enough on action-shaped logic files (Step 3 covered 18 files; 5 more need descriptors before this can fully retire `_logic_api()`). Independent of the FormWriter `fromDescriptor()` work (Step 6, now folded into [`scaffolding_code_generator.md`](scaffolding_code_generator.md)) — either can ship first.

The AI side of descriptor consumption (`describe_actions` / `invoke_action`) is split out to [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md). It depends on the validator (7b) and descriptor coverage (7d) defined here.

## Problem

The REST API (`apiv1.php`) currently reads action metadata from `_logic_api()`. The descriptor function `_logic_descriptor()` has richer metadata (typed input schema, validation hints) but no consumers wire to it yet.

The duplication is the bug: descriptors are the source of truth for action shape, but the API still uses `_logic_api()` (description + requires_session only). A field added to a descriptor doesn't appear in the API parameter docs unless someone also touches the consumer-side code.

After this step: descriptors drive the REST API. Add `_logic_descriptor()` to a logic file and it appears in `GET /api/v1/actions` and validates input on `POST /api/v1/action/{name}`. `_logic_api()` retires.

## Goal

```php
// In any logic file:
function send_invitation_logic(array $input): LogicResult { /* ... */ }

function send_invitation_logic_descriptor(): array {
    return [
        'description'      => 'Send an invitation email to a user.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'email'   => ['type' => 'email', 'required' => true],
            'message' => ['type' => 'text',  'required' => false],
        ],
    ];
}
```

That single declaration drives:
- `GET /api/v1/actions` lists `send_invitation` with full input schema
- `POST /api/v1/action/send_invitation` validates the body before calling the logic

No per-consumer wiring code on the REST side. The AI side consumes the same descriptor — see [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md).

## Scope

Three sub-pieces, sequenced:

### 7a — Switch the REST API to descriptors (~80 lines, low risk)

`apiv1.php` currently has two relevant blocks:

- **Action discovery** (`GET /api/v1/actions`, around line 452): scans `logic/*_logic.php`, looks for `function {name}_logic_api()`, calls it, returns `description` and `requires_session`. Switch to look for `_logic_descriptor()` first; fall back to `_logic_api()` during migration; expose the descriptor's `input` schema in the response.

- **Action invocation** (`POST /api/v1/action/{name}`, around line 484): includes the logic file, looks up `_logic_api()` for the requires_session flag, then calls `_logic()` with the merged input. Switch the metadata lookup to `_logic_descriptor()` first; add an input-validation step before the logic call.

The validation is the meaningful new work — see 7b.

### 7b — Boundary input validator (~100–150 lines, new helper)

A new helper (`includes/DescriptorValidator.php` or similar) takes a descriptor's `input` schema and a raw input array and returns a coerced/validated array, or throws a structured validation error. Type coverage matches the descriptor type vocabulary established in Step 3:

| Descriptor type | Validation |
|----------------|-----------|
| `int` | Cast to int; reject non-numeric strings |
| `string` | Trim; reject non-scalar |
| `email` | Validate via filter_var; reject malformed |
| `bool` | Coerce truthy/falsy values; reject ambiguous |
| `select` | Must be in `options` array |
| `text` | Trim; allow multi-line |
| `date` | Parse Y-m-d; reject invalid |
| `password` | Pass through; never log |

Plus required-field checking. This is a fast first-pass at the API boundary; the logic file's own validation still runs as a backstop.

The validator is reusable: same code paths apply to the AI invocation surface in [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md).

### 7d — Migration and cleanup (~75 lines, mechanical)

Five files have `_logic_api()` but no `_logic_descriptor()` — descriptors need to be added before `_logic_api()` can retire:

```
booking_logic.php             event_sessions_logic.php
cart_logic.php                event_sessions_course_logic.php
survey_logic.php
```

These are the "mixed" files Step 3 explicitly deferred. Adding descriptors is a per-file judgment call about which POST action is the action surface. Once descriptors exist for all 23 `_logic_api()` files, the `_logic_api()` stubs can be deleted in a single sweep.

A diff pass before deletion confirms no metadata loss: some `_logic_api()` returns may have hints (rate limits, ajax variants) not yet expressed in descriptors. Either fold them into the descriptor schema (extend the type vocabulary) or accept the loss explicitly.

## Implementation order

1. **7a — REST API descriptor switch.** Smallest, lowest risk. Action discovery and invocation read descriptors with `_logic_api()` fallback. Existing API consumers continue to work; new descriptor-backed metadata flows through.
2. **7b — Validator.** Standalone helper. Wire into `apiv1.php` first; the AI tools (in [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md)) wire to the same helper later.
3. **7d — Migration.** After 7a is live, add descriptors to the 5 files, then delete `_logic_api()` across all 23 files in one sweep. Final commit retires the function.

## Cost

- ~250–350 lines of net new/changed code across `apiv1.php`, the new validator, and the 5 migration files.
- Validator type-coercion edge cases (date formats, bool truthiness, select enums) — easy to get subtly wrong; needs unit tests.

## Risk areas

- **Backwards compatibility during 7a**: existing API consumers depend on the current response shape of `GET /api/v1/actions`. Adding `input` is additive (safe); changing `requires_session` semantics or removing fields is not.
- **Rate-limit metadata loss**: spot-check `_logic_api()` returns for any per-action rate limits or ajax-variant hints before deletion. If found, decide whether to extend the descriptor schema or accept the loss.

## What this step does NOT include

- New descriptor types beyond what Step 3 established (unless 7d migration surfaces a need).
- The AI's `describe_actions` / `invoke_action` tools — see [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md).
- The AI's read-side model auto-discovery — see [`implemented/joinery_ai_autodiscovery.md`](implemented/joinery_ai_autodiscovery.md).
- Touching FormWriter — see the `fromDescriptor()` work in [`scaffolding_code_generator.md`](scaffolding_code_generator.md). Independent.

## Dependencies

- Step 3 (descriptors on action-shaped files) — done.
- Step 4 (uniform `(array $input)` signature) — done. Required for 7b's clean validator hand-off.
- Step 5 (single calling convention) — done. Same rationale.

## Effort estimate

Small-medium overall — 1.5–2.5 days of focused work. Breakdown:

- 7a (API switch): 0.5–1 day
- 7b (validator): 0.5–1 day
- 7d (migration): 0.5 day
