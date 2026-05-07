# Joinery AI — Write Tools (design, deferred)

Part of the Joinery AI plugin. See [`joinery_ai.md`](implemented/joinery_ai.md) for the core system spec.

Write tools are explicitly deferred from v1 and v2. This document captures the design constraints so the decision is made deliberately when write tools are actually needed, not retrofitted.

## Why deferred

Two distinct problems must be solved before write tools are safe:

1. **Authorization** — "did the owner actually want this mutation to happen right now?"
2. **Validation gauntlet** — "does the write preserve the system's data integrity rules?"

v1 sidesteps both by being read-only. Neither problem is hard to solve in isolation; the challenge is that the right solution for each depends on the other, and both depend on having a real write use case to design against.

## The authorization problem

An LLM-triggered write can be wrong in ways a human reviewer would catch instantly — wrong target, wrong timing, wrong amount. Three gating mechanisms to choose from (not mutually exclusive):

- **Approval queue:** the tool emits a pending action; the owner approves via admin UI; the action runs. Safest. Breaks the "autonomous agent" model but enables async review.
- **Interactive-only flag:** the tool refuses unless the run was triggered manually by an authenticated owner sitting at the keyboard. Preserves autonomy for reads, gates writes on presence.
- **Per-call authorization in the prompt:** the recipe explicitly opts in at authoring time, e.g. "you may cancel bookings older than 90 days with no future recurrence." The LLM acts within the stated bounds; the tool validates against them. Fully autonomous but requires careful prompt engineering and executor enforcement.

These compose — a tool could require both interactive trigger AND an approval queue for destructive actions.

## The validation-gauntlet problem

This is the deeper concern. Joinery's data integrity rules live in three layers:

1. **Field-level** — `$field_specifications` (type, required, length, unique). Enforced by `prepare()` and `save()` on every model.
2. **Cross-record business rules** — logic files: is the event still open for registration, is the user already booked, has payment cleared, is the slot still available?
3. **Cross-system rules** — also logic files: charge the card, send the confirmation email, fire the purchase hooks.

A write tool that calls `Booking::save()` directly gets only layer 1. A write tool that calls `booking_logic.php` gets all three. **The difference is the entire reason logic files exist.** An agent that bypasses logic files can insert structurally valid but semantically garbage data — a booking with no payment, a subscription with no Stripe ID, an event registration after the event ended.

**Direct-to-model writes are ruled out for agent-facing tools.** Any write-tool design must guarantee that mutations route through the relevant `_logic()` function.

## Two design paths for write tools

### Path A — Convention-based (hand-written tools)

Write tools are hand-written `RecipeToolInterface` classes. By convention their `execute()` method routes through `_logic()` via a helper on `RecipeRunContext`:

```php
public function execute(array $input, RecipeRunContext $ctx): array {
    return $ctx->callLogic('event_register', $input);
    // callLogic: set_api_user → call _logic → translate LogicResult → clear_api_user
}
```

The author *could* skip the helper and write to a model directly, but the convention is documented and code review enforces it. Simpler — no new infrastructure beyond the `callLogic` helper on `RecipeRunContext`.

**Risk:** trusts every write-tool author to follow the rule. A careless or rushed author can bypass the gauntlet.

### Path B — Structurally enforced (auto-discovered write actions)

Write actions are defined only by a `{action}_logic_ai_write()` opt-in metadata block on the logic file itself. A `invoke_write_action` umbrella tool is the only path to mutation — there is no class to write, so there is no way to bypass `_logic()`. The executor calls `_logic()` by construction.

```php
function cancel_booking_logic_ai_write() {
    return [
        'description' => 'Cancel a booking by ID. Sends cancellation email and releases the slot.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'booking_id' => ['type' => 'integer'],
            ],
            'required' => ['booking_id'],
        ],
        'authorization' => 'approval_queue',   // or 'interactive_only', or 'prompt_scoped'
    ];
}
```

**Risk:** more infrastructure (umbrella tool, JSON Schema validator at the executor, qualification rule for write actions, the `authorization` field semantics). Eliminates the author-bypass failure mode but adds complexity.

## Recommendation (deferred decision)

Choose Path A or B when the first real write use case arrives. The choice will be clearer with a concrete example — the right answer for "cancel a booking" might differ from "send an email to a member."

Either path must also decide:

- **Idempotency.** The LLM can retry failed tool calls. A write tool needs a key or a uniqueness constraint to prevent double-execution.
- **Rollback.** If a multi-step write (charge card → create order → send email) fails mid-way, what's the recovery? Logic files already handle this with try/catch + compensating actions; the tool just surfaces the error.
- **Audit trail.** Write actions should be logged to `rcr_tool_calls` with full input/output, same as reads. No extra work — the runner already does this.

## What's needed to ship write tools

1. Pick a first use case.
2. Decide authorization gating (one of the three mechanisms above, or a combination).
3. Decide Path A or B for the validation guarantee.
4. Implement `callLogic()` helper on `RecipeRunContext` (Path A) or the write-action auto-discovery infrastructure (Path B).
5. Write the first tool. Everything else follows the established pattern.
