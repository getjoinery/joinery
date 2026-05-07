# Joinery AI — Write Tools

Part of the Joinery AI plugin. See [`implemented/joinery_ai.md`](implemented/joinery_ai.md) for the core system spec, and [`implemented/joinery_ai_autodiscovery.md`](implemented/joinery_ai_autodiscovery.md) for the (shipped) read side of model auto-discovery.

This spec covers **both AI write surfaces**:

| Use case | Path | Opt-in mechanism |
|----------|------|------------------|
| Self-contained CRUD on a single table | Direct model write | `$ai_writable_fields` allowlist on the model |
| Anything with cross-record / cross-system effects | Logic-file write | `mutates: true` on the logic file's `_logic_descriptor()` |

Both paths default off. Both are deferred. The split exists because the validation gauntlet only applies to logic-bound models — for self-contained tables, field-spec validation IS the entire gauntlet, and a logic-file wrapper would be ceremony.

**Status:** deferred. Read side ships; this is the next major write surface to design when a real use case arrives.

## Why deferred

The unsolved problem is the **validation gauntlet** — "does the write preserve the system's data integrity rules?"

Authorization is not a separate concern under admin-only deployment: admins can already perform any action through the admin UI, so an LLM-triggered write is bounded by admin trust, not by per-action gating. There is no approval-queue / interactive-only / prompt-scoped infrastructure to design — those mechanisms only exist for the multi-user case (recipe-owner ≠ admin), which Joinery AI is not built for. If admin-only ever changes, gating becomes new work at that point, not a flip of pre-staged metadata. Same stance as the read side took on owner-scoping.

v1 sidesteps the gauntlet problem by being read-only. The right solution depends on having a real write use case to design against.

## The validation-gauntlet problem (why two paths)

Joinery's data integrity rules live in three layers:

1. **Field-level** — `$field_specifications` (type, required, length, unique). Enforced by `prepare()` and `save()` on every model.
2. **Cross-record business rules** — logic files: is the event still open for registration, is the user already booked, has payment cleared, is the slot still available?
3. **Cross-system rules** — also logic files: charge the card, send the confirmation email, fire the purchase hooks.

A write tool that calls `Booking::save()` directly gets only layer 1. A write tool that calls `booking_logic.php` gets all three. **The difference is the entire reason logic files exist.** An agent that bypasses logic files can insert structurally valid but semantically garbage data — a booking with no payment, a subscription with no Stripe ID, an event registration after the event ended.

For models with logic-file invariants, direct-to-model writes are ruled out. For models without them — notes, bookmarks, preferences — layer 1 IS the entire gauntlet, and direct-to-model writes are safe by construction. That's the split. The two paths below cover both cases.

---

## Path 1: Direct-model writes (self-contained tables)

For tables where field-spec validation is the entire gauntlet. Three generic tools cover create/update/delete; new self-contained models become AI-writable by adding one line to the model class.

### Tools

- **`create_model(model, fields)`** — creates a new row in a model that opts in via a non-empty `$ai_writable_fields`. Owner field injected from session.
- **`update_model(model, key, fields)`** — updates allowlisted fields on a row the owner controls. `authenticate_write()` enforces row-level ownership.
- **`delete_model(model, key)`** — soft-deletes a row. Same ownership check as update.

### Opt-in shape

```php
class UserNote extends SystemBase {
    // Read side (already shipped — see autodiscovery spec)
    public static $ai_readable        = true;
    public static $ai_description     = 'User-created notes.';
    public static $ai_excluded_fields = [];

    // Write side
    public static $ai_writable_fields = ['unt_title', 'unt_body', 'unt_color'];  // non-empty = opted in
}
```

Declaring a non-empty `$ai_writable_fields` allowlist *is* the write opt-in act — there's no separate flag. A read-only model omits it entirely (or sets it to `[]`).

### When to opt a model in

The test the author applies before declaring `$ai_writable_fields`:

> *Are `prepare()` and `save()`'s field-level rules the entire validation gauntlet for this model? Are there any cross-record invariants (slot allocation, capacity, status transitions), payment effects, hook firings, or external system calls that would be skipped by a direct write?*

If the answer is **"field-spec is the entire gauntlet"** — opt in. The model is self-contained: notes, bookmarks, preferences, tags, user-owned simple records.

If there are any cross-record or cross-system effects — leave `$ai_writable_fields` undeclared and use Path 2 (logic-file writes) instead. The full gauntlet runs by construction there.

### Field-level defense

`$ai_writable_fields` is an **allowlist**. Anything not on the list is dropped before `set()` is called. The auto-block regex (`/_(password|secret|key|token|hash)$/i`) strips sensitive columns even if mistakenly allowlisted — defensive overlap.

The asymmetry with the read blocklist (described in the autodiscovery spec) is intentional:

- **Reads default to exposed** — adding a column to a readable model surfaces it. Cost of an oversharing read is a low-stakes information leak.
- **Writes default to non-writable** — adding a column to a writable model does not make it writable. Cost of an unintended write is state corruption.

### Threat-model additions for writes

| Threat | Defense |
|--------|---------|
| LLM bypassing logic-file invariants on a model that has them | Writes default off — `$ai_writable_fields` must be explicitly declared with a non-empty allowlist. The author asserts opt-in only after applying the gauntlet test above. Misclassification is the failure mode; code review of new `$ai_writable_fields` declarations is the defense. |
| Field corruption on writable models (LLM sets a field the author didn't intend writable) | `$ai_writable_fields` is an allowlist. Adding a new column does not silently make it writable. |

### Implementation sketch

```
plugins/joinery_ai/includes/
  ModelWriteExecutor.php     # write security boundary
plugins/joinery_ai/recipe_tools/
  CreateModelTool.php        # wraps ModelWriteExecutor (create)
  UpdateModelTool.php        # wraps ModelWriteExecutor (update)
  DeleteModelTool.php        # wraps ModelWriteExecutor (delete)
```

`ModelWriteExecutor` enforcement order: model opt-in (`$ai_writable_fields` non-empty) → field allowlist (`$ai_writable_fields` minus auto-block) → field-by-field `set()` → `prepare()` → `authenticate_write()` → `save()`. Soft-delete uses the same opt-in gate plus `authenticate_write()` and skips the field allowlist (delete sets `delete_time`, not user fields).

### Caveats

- **Write granularity:** `$ai_writable_fields` is currently all-or-nothing across create/update/delete. If a future use case needs finer control (create-only intake forms, update-only state machines), splitting into `$ai_creatable_fields` / `$ai_updatable_fields` / a separate delete flag is a non-breaking extension.
- **Misclassified opt-in:** the gauntlet test depends on the author honestly answering "is field-spec the entire validation surface." A model that quietly grew cross-record effects after being opted in becomes a hidden hazard. Code review of new `$ai_writable_fields` declarations — and a re-audit when a model gains hooks or external calls — is the practical defense.

---

## Path 2: Logic-file writes (cross-record / cross-system effects)

For everything with side effects beyond a single table — cancel a booking, place an order, send an invitation. The umbrella tool routes all writes through `_logic()`, so the full gauntlet runs by construction.

### Two implementation paths considered

- **Path A — convention-based:** hand-written `RecipeToolInterface` classes, one per action, with a convention that they route through `_logic()` via a helper on `RecipeRunContext`. Simple, but trusts every author to follow the rule; a careless author bypasses the gauntlet.
- **Path B — structurally enforced:** action metadata is declared on the logic file itself; a generic umbrella tool is the only path to mutation. There is no class to write, so there is no way to bypass `_logic()`. The executor calls it by construction.

**Path B is the chosen path.** The metadata vehicle is `_logic_descriptor()`, which already exists for the REST API and FormWriter consumers (Step 3 of the logic refactor — 18 logic files have descriptors today). Adding `mutates: true` to a descriptor is the AI write opt-in.

Path A remains available as an escape hatch for custom tools that don't fit the descriptor model — multi-step orchestrations, tools wrapping non-action-shaped logic, hand-tuned prompts. But action-shaped logic files take Path B by default.

### Tools

- **`describe_actions(filter?)`** — read-only. Returns the list of logic files that have `_logic_descriptor()` set, optionally filtered by `mutates`. Lets the AI discover what actions are available without per-tool registration.
- **`invoke_action(name, input)`** — write-capable. Takes an action name and an input map, validates input against the descriptor's schema, calls the logic, returns the `LogicResult`. Gated by descriptor's `mutates` flag and any session/permission requirements.

Example logic-file descriptor:

```php
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

That single declaration drives the AI's `describe_actions` tool to expose the action as write-capable and the `invoke_action` tool to call it with structured input. No per-action PHP class, no hand-written tool wrapper.

### Upstream dependencies

Two pieces of infrastructure are specced in [`FUTURE_descriptor_consumers.md`](FUTURE_descriptor_consumers.md) and shared with the REST API:

- **DescriptorValidator** (Step 7b) — coerces and validates input against the descriptor's `input` schema. Same validator the REST API uses, so the AI and API surfaces stay consistent.
- **Descriptor coverage** (Step 7d) — five logic files (`booking`, `cart`, `survey`, `event_sessions`, `event_sessions_course`) still have `_logic_api()` instead of `_logic_descriptor()`. Adding descriptors to those is a prerequisite for full AI write coverage.

The recipe's `rcp_allowed_tools` gates whether `describe_actions` and `invoke_action` are reachable at all — a recipe can opt into reads only by including `describe_models`/`query_model` and excluding the action tools.

---

## Open decisions before shipping (both paths)

These need concrete answers, ideally informed by the first real write use case:

- **Idempotency.** The LLM can retry failed tool calls. Each write tool needs an idempotency key, a uniqueness constraint, or some other guard against double-execution.
- **Rollback.** If a multi-step write fails mid-way, what's the recovery? Logic files already handle this with try/catch + compensating actions; the tool just surfaces the error to the LLM. Direct-model writes are single-row, so rollback is moot.
- **Audit trail.** Write actions should be logged to `rcr_tool_calls` with full input/output, same as reads. No extra work — the runner already does this.
- **`mutates` semantics (Path 2 only).** Is `mutates: true` enough to mark a logic file as AI-callable, or does it also need a separate `ai_writable: true` flag? Argument for separate: not every mutating action should be AI-callable (e.g. destructive maintenance ops the admin would never run via AI). Argument against: a recipe's `rcp_allowed_tools` already provides per-recipe scoping; adding another descriptor flag is curation theater.

## What's needed to ship

1. Pick a first use case (per path, or one for each).
2. Lock the open decisions above.
3. **Path 1:** implement `ModelWriteExecutor` and the three model-write tools.
4. **Path 2:** land Step 7b (validator) and Step 7d (descriptor coverage on the 5 missing files) from [`FUTURE_descriptor_consumers.md`](FUTURE_descriptor_consumers.md); implement `describe_actions` and `invoke_action`; add `mutates` to existing descriptors that should be AI-callable.
5. Write the first end-to-end test for whichever path ships first.
