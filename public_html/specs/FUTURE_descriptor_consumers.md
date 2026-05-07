# REST API and AI consume descriptors natively

**Parent spec:** [`implemented/logic_code_refactor.md`](implemented/logic_code_refactor.md) — this spec covers Step 7.

**Status:** Not started. Steps 1–5 of the parent spec are done; this can begin once descriptor coverage is high enough on action-shaped logic files (Step 3 covered 18 files; 5 more need descriptors before this can fully retire `_logic_api()`). Independent of [`FUTURE_formwriter_descriptors.md`](FUTURE_formwriter_descriptors.md) (Step 6) — either can ship first.

## Problem

Two consumer surfaces — the REST API (`apiv1.php`) and the AI tool layer (joinery_ai plugin) — currently read action metadata from `_logic_api()`. The descriptor function `_logic_descriptor()` has richer metadata (typed input schema, validation hints) but no consumers wire to it yet.

The duplication is the bug: descriptors are the source of truth for action shape, but the API still uses `_logic_api()` (description + requires_session only) and the AI uses per-action `RecipeToolInterface` classes (hand-written wrappers around each action). A field added to a descriptor doesn't appear in the API parameter docs or the AI tool schema unless someone also touches the consumer-side code.

After Step 7: descriptors drive both surfaces. Add `_logic_descriptor()` to a logic file and it appears in `GET /api/v1/actions`, validates input on `POST /api/v1/action/{name}`, and (if `mutates` is set appropriately) shows up as an AI tool. `_logic_api()` retires.

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
- The AI's `describe_actions` tool exposes it as a write-capable tool (because `mutates: true`)
- The AI's `invoke_action` tool can call it with structured input

No per-consumer wiring code.

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

Plus required-field checking. This is a fast first-pass at the API/AI boundary; the logic file's own validation still runs as a backstop.

The validator is reusable: same code paths apply to the AI invocation surface in 7c.

### 7c — AI describe_actions / invoke_action surface (~200–400 lines, biggest piece)

The joinery_ai plugin has 8 hand-written tool classes in `plugins/joinery_ai/recipe_tools/` (`SaveNoteTool`, `GetMyNotesTool`, etc.). Each is a per-action wrapper exposing a single capability.

Replace per-action wrappers (for descriptor-backed actions) with two generic tools:

- **`describe_actions`** — read-only tool. Returns the list of actions with descriptors, optionally filtered by `mutates`. Lets the AI discover what actions are available without per-tool registration.
- **`invoke_action`** — write-capable tool. Takes an action name and an input map, validates against the descriptor, calls the logic, returns the result. Gated by descriptor's `mutates` flag and any session/permission requirements.

Existing hand-written tools that map cleanly to descriptor-backed actions can be retired. Tools that have logic outside the descriptor model (web search, fetch URL, stock data) stay as-is — they're not action wrappers.

**Cross-references:** [`joinery_ai_autodiscovery.md`](implemented/joinery_ai_autodiscovery.md) covers the read-side AI surface (model auto-discovery); [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md) covers the write-side design. Both should be reviewed before starting 7c.

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
2. **7b — Validator.** Standalone helper. Wire into apiv1.php first; AI tools wire to the same helper in 7c.
3. **7c — AI tools.** Largest piece, highest design complexity. Review the joinery_ai cross-reference specs first. Probably ships independently of 7a/7b.
4. **7d — Migration.** After 7a is live, add descriptors to the 5 files, then delete `_logic_api()` across all 23 files in one sweep. Final commit retires the function.

## Cost

- ~500–800 lines of net new/changed code across `apiv1.php`, the new validator, joinery_ai recipe tools, and the 5 migration files.
- Validator type-coercion edge cases (date formats, bool truthiness, select enums) — easy to get subtly wrong; needs unit tests.
- AI write-tool security model decision: descriptor `mutates` flag is coarser than the existing per-tool-class authorization. Worth a design discussion before 7c.

## Risk areas

- **Backwards compatibility during 7a**: existing API consumers depend on the current response shape of `GET /api/v1/actions`. Adding `input` is additive (safe); changing `requires_session` semantics or removing fields is not.
- **Rate-limit metadata loss**: spot-check `_logic_api()` returns for any per-action rate limits or ajax-variant hints before deletion. If found, decide whether to extend the descriptor schema or accept the loss.
- **AI tool over-exposure**: `mutates: true` actions become AI-callable. If `mutates: true` is set on a logic file the AI shouldn't call (e.g. an admin-only destructive action), the AI gets it. Mitigation: descriptor's `requires_session` + the AI's session context should already block sensitive ops, but worth a per-action audit before 7c ships.

## What this step does NOT include

- New descriptor types beyond what Step 3 established (unless 7d migration surfaces a need).
- Changing the AI's read-side model auto-discovery — that's [`joinery_ai_autodiscovery.md`](implemented/joinery_ai_autodiscovery.md).
- Touching FormWriter — that's [`FUTURE_formwriter_descriptors.md`](FUTURE_formwriter_descriptors.md). Independent.

## Dependencies

- Step 3 (descriptors on action-shaped files) — done.
- Step 4 (uniform `(array $input)` signature) — done. Required for 7b's clean validator hand-off.
- Step 5 (single calling convention) — done. Same rationale.
- 7c specifically benefits from the joinery_ai cross-reference specs being reviewed/updated first.

## Effort estimate

Medium-large overall — 3–5 days of focused work. Breakdown:

- 7a (API switch): 0.5–1 day
- 7b (validator): 0.5–1 day
- 7c (AI tools): 1.5–3 days (most variance; depends on existing recipe-runner integration)
- 7d (migration): 0.5 day
