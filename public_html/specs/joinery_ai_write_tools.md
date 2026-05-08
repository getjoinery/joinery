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

## Actor model and recordkeeping

Writes happen *as the recipe owner* — `rcp_recipes.rcp_usr_user_id`. There is no dedicated AI user, and no "temporary elevation." Creating a recipe already requires permission 10, so the owner is using existing admin perms through a different surface, not being promoted for the duration of the run.

A dedicated AI user was considered and rejected:

- It detaches the action from the responsible human. The audit answer to "who authorized this write?" should be the admin who configured the recipe, not a synthetic principal that nobody owns.
- It breaks ownership checks on per-user tables — a `Booking` owned by user 42 would have to grant write to the AI user, which forces the AI user to be permission 10, which is the original arrangement with extra steps.

### Dual attribution, not actor substitution

- **Tables with actor columns** (`xxx_update_user_id`, `xxx_create_user_id`, etc.) get the recipe owner's user id. That's correct attribution to the responsible human.
- **`rcr_tool_calls`** (already in the plan as the per-call audit log) captures the AI trail. The forensic question "did the admin do this directly or via AI?" is answered by joining: a write at time T with no matching `rcr_tool_calls` row is a direct admin action; one with a match was AI-driven and points back to the specific recipe + run + iteration.

If a future audit report needs to filter human vs. AI activity, the fix is to teach that report about `rcr_tool_calls`, not to swap actor identity.

### Owner-permission re-check at run start

A recipe owner's permission level can change between recipe creation and the next scheduled run (demotion, role change, account deactivation). A demoted admin should not have their old recipes continuing to act with admin reach.

`RecipeRunner` checks the current `usr_permission` on the recipe owner at run start. If it's below 10, the run fails immediately with a clear error (no tool calls executed) and the run row is marked `failed` with a message naming the demotion. Reactivating the recipe requires either restoring the owner's permission or transferring ownership to another permission-10 admin.

This check is lightweight (one user lookup, run-start only — not per-tool-call) and catches the configuration drift that would otherwise let writes happen as a stale admin.

## The validation-gauntlet problem (why two paths)

Joinery's data integrity rules live in three layers:

1. **Field-level** — `$field_specifications` (type, required, length, unique). Enforced by `prepare()` and `save()` on every model.
2. **Cross-record business rules** — logic files: is the event still open for registration, is the user already booked, has payment cleared, is the slot still available?
3. **Cross-system rules** — also logic files: charge the card, send the confirmation email, fire the purchase hooks.

A write tool that calls `Booking::save()` directly gets only layer 1. A write tool that calls `booking_logic.php` gets all three. **The difference is the entire reason logic files exist.** An agent that bypasses logic files can insert structurally valid but semantically garbage data — a booking with no payment, a subscription with no Stripe ID, an event registration after the event ended.

For models with logic-file invariants, direct-to-model writes are ruled out. For models without them — notes, bookmarks, preferences — layer 1 IS the entire gauntlet, and direct-to-model writes are safe by construction. That's the split. The two paths below cover both cases.

## Prompt injection (separate concern)

The validation gauntlet defends against *data integrity* failures (writes that bypass business rules). It does **not** defend against *intent* failures — the LLM being tricked, via untrusted text in tool results, into emitting structurally valid write calls that the admin never asked for. That threat is distinct and not solvable at the LLM layer.

See [`INFO_prompt_injection.md`](INFO_prompt_injection.md) for the full analysis, including a concrete exploit walkthrough, why each existing defense (descriptor validation, gauntlet, `rcp_allowed_tools`) catches or misses it, and which mitigations are real vs. theater.

### Save-time taint gate — the primary write-side defense

The `$ai_untrusted_fields` infrastructure already shipped on the read side wraps user-generated text in delimiters and frames it as data in the system prompt. That makes injection harder but not impossible — a sufficiently crafted note can still steer the LLM. The structural defense layered on top is **a save-time validation gate** on the recipe's allowed-tools / allowed-models intersection:

A recipe is *tainted-capable* if both:

1. `rcp_allowed_tools` contains any write tool (`create_model`, `update_model`, `delete_model`, `invoke_action`), and
2. `rcp_allowed_models` contains any model whose class declares a non-empty `$ai_untrusted_fields`.

A tainted-capable recipe must have `rcp_allow_tainted_writes = true` to save. The check fires in `admin_edit_logic.php`; the form rejects with a plain-language error explaining why and naming both the offending tool(s) and model(s).

This is a static gate — no runtime taint flag, no per-call check, no taint columns on `rcr_recipe_runs` or `rcr_tool_calls`. The trade-off vs runtime taint tracking is precision: a recipe allowed to query `Booking` but that never actually selects `bkn_notes` still has to opt in. That's accepted — a recipe whose allowed-models list lets it read untrusted text *could* read it on any iteration anyway, and pushing the decision to recipe-edit time puts it in front of the author at the right moment instead of as a mid-run failure.

The opt-in itself: `rcp_allow_tainted_writes` is a deliberate acknowledgment that this recipe's prompt is robust to injection in the queried fields. Setting it is bounded by admin trust (creating any recipe requires permission 10).

This is the structural mitigation. The other two recommendations from `INFO_prompt_injection.md` remain in scope but secondary:

- **`confirmation_required` flag on high-impact descriptors** — selective per-action approval gating (cancel_order, send_email_to_list, delete_user); the runtime surfaces those calls to the admin for explicit OK before executing. Decision deferred until the first real write use case.
- **Audit alerting on write rate** — every write logged to `rcr_tool_calls` (already planned) plus a configurable rate-limit alert per recipe run. Decision deferred.

### Implementation surface for the taint gate

- New column on `rcp_recipes`: `rcp_allow_tainted_writes bool default false`.
- `admin_edit_logic.php` computes the tainted-capable predicate from the posted `rcp_allowed_tools` and `rcp_allowed_models` (not the persisted values — the user might be changing them in the same save). On a tainted-capable save without the flag, returns a `LogicResult` error naming the specific tools and models that triggered the gate.
- `/admin/joinery_ai/edit` gets a checkbox for `rcp_allow_tainted_writes` with a help blurb that names the trade-off in plain terms ("This recipe can both read user-generated text and perform writes — confirm the prompt is robust to injection in those fields").
- The list of write tool names lives on a constant in the write-tool registry so the gate doesn't drift if a fifth write tool is added later.

### Considered and rejected: per-run write volume cap

A `rcp_max_writes_per_run` cap (defaulting low, e.g. 10) was considered as an extra blast-radius limit on top of the taint gate. It was rejected.

The cap encodes "high write count is suspicious" as a structural assumption, but that assumption doesn't hold for the platform's real use cases. A leak-list password reset, a bulk tag rename, a stale-record cleanup, or a mass status-flip after a tier change all legitimately want to write thousands of rows. A low default forces every legitimate batch recipe to bump the cap manually; once authors are routinely setting it high "just in case," the cap stops doing useful work.

The deeper issue is that volume is a poor proxy for the failure mode. What we care about is "LLM did writes the admin didn't intend" — that can be one wrong write or five thousand wrong writes. A volume cap catches a narrow slice of the first signature and none of the second, while breaking legitimate high-volume operations. The actual safeguards — static taint gate (capability), `rcp_allowed_tools` (tool scope), `rcr_tool_calls` (audit) — are the right shape and don't assume volume signals badness.

`rcp_max_iterations` remains as the implicit ceiling on cost and reasoning depth (and indirectly on writes), which is the appropriate framing: it bounds runs, not blast radius.

---

## Path 1: Direct-model writes (self-contained tables)

For tables where field-spec validation is the entire gauntlet. Three generic tools cover create/update/delete; new self-contained models become AI-writable by adding one line to the model class.

### Tools

- **`create_model(model, fields)`** — creates a new row in a model that opts in via a non-empty `$ai_writable_fields`. The LLM passes every value via `fields`, including any user-id / ownership column that needs to be set; the executor does not auto-inject anything. The recipe's actor identity (recipe owner's `user_id`, permission level) is named explicitly in the system prompt so the LLM has a concrete value to use for self-owned rows.
- **`update_model(model, key, fields)`** — updates allowlisted fields on an existing row. `authenticate_write()` runs with the recipe owner's identity (admin reach per the actor model section).
- **`delete_model(model, key)`** — soft-deletes a row. Same `authenticate_write()` check as update.

User-id columns are treated as ordinary fields. A model that wants the LLM to be able to create or reassign ownership puts the owner column in `$ai_writable_fields`; a model that wants ownership locked omits it and the LLM literally cannot set it. There is no `$ai_owner_field` declaration — auto-injection was considered and rejected because it doesn't generalize beyond simple owner-scoped models (Booking, for example, has two user columns), and the recipe owner's admin reach means many legitimate recipes operate on rows owned by other users.

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

- **`describe_actions(filter?)`** — read-only. Returns the descriptors for actions in the recipe's `rcp_allowed_actions` allow-list, optionally filtered by `mutates`. The LLM never sees actions outside the allow-list, even for discovery — same scoping principle as `describe_models` on the read side.
- **`invoke_action(name, input)`** — write-capable. Takes an action name and an input map, validates input against the descriptor's schema, calls the logic, returns the `LogicResult`. Refuses any name not in `rcp_allowed_actions`, regardless of whether the underlying logic file has `mutates: true`.

### Per-recipe action scoping

Recipes opt into specific actions via `rcp_allowed_actions` (jsonb array of action names), mirroring how `rcp_allowed_models` works on the read side. Enabling `invoke_action` in `rcp_allowed_tools` makes the *capability* available; populating `rcp_allowed_actions` makes specific actions *reachable*. Both are required.

The asymmetry is deliberate and matches the model side:

- New mutating actions added to the platform later are inert by default for existing recipes — they don't silently become callable.
- The recipe author, who knows the goal of *this specific recipe*, makes the AI-eligibility decision per-recipe, in context. There is no platform-wide "is this safe for AI?" flag because that question doesn't have a platform-wide answer.

The edit UI gets a checkbox list of registered actions parallel to the model checkbox list. Checked actions are added to `rcp_allowed_actions` on save.

### Role of `mutates`

`mutates: true` is a structural signal — "this action has side effects." It already exists on `_logic_descriptor()` for the REST API (HTTP method routing) and FormWriter (CSRF / confirmation behavior). The AI uses it for two read-only purposes:

1. The `describe_actions(filter)` tool exposes it so the LLM can introspect ("show me only the writes" or "show me only the reads") within its allow-list.
2. It's surfaced to the recipe author in the action checkbox list so they can see at a glance which actions mutate state.

It is **not** the AI authorization gate. That gate is `rcp_allowed_actions` per recipe. A separate `ai_writable: true` descriptor flag was considered and rejected — see "Considered and rejected" below.

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

The recipe's `rcp_allowed_tools` gates whether `describe_actions` and `invoke_action` are reachable at all — a recipe can opt into reads only by including `describe_models`/`query_model` and excluding the action tools. When `invoke_action` is enabled, `rcp_allowed_actions` further scopes which specific actions are callable.

### Considered and rejected: `ai_writable: true` descriptor flag

An earlier draft proposed adding `ai_writable: true` to `_logic_descriptor()` as a separate flag from `mutates: true`, on the reasoning that not every mutating action should be AI-callable (e.g. destructive maintenance ops). That flag was rejected in favor of `rcp_allowed_actions`.

The flag would have asked the descriptor author to predict, at the time the logic file is written, whether *any* future recipe might legitimately need AI access to it. That's the wrong place for the decision: AI eligibility depends on *which* recipe is calling, not on the action itself. `cancel_subscription` is reasonable AI access for a churn-management recipe and unreasonable for a welcome-email recipe.

Pushing the decision onto the recipe (`rcp_allowed_actions` allow-list) keeps `mutates` as the clean structural signal it already is, lets each recipe author make the eligibility call with full context, and avoids overloading descriptor metadata with two distinct concepts. Same minimal-allow-list principle as `rcp_allowed_models` on the read side and the static taint gate.

---

## Open decisions before shipping (both paths)

These need concrete answers, ideally informed by the first real write use case:

- **Idempotency.** The LLM can retry failed tool calls. Each write tool needs an idempotency key, a uniqueness constraint, or some other guard against double-execution.
- **Rollback.** If a multi-step write fails mid-way, what's the recovery? Logic files already handle this with try/catch + compensating actions; the tool just surfaces the error to the LLM. Direct-model writes are single-row, so rollback is moot.
- **Audit trail.** Write actions should be logged to `rcr_tool_calls` with full input/output, same as reads. No extra work — the runner already does this.

## What's needed to ship

1. Pick a first use case (per path, or one for each).
2. Lock the open decisions above.
3. **Path 1:** implement `ModelWriteExecutor` and the three model-write tools.
4. **Path 2:** land Step 7b (validator) and Step 7d (descriptor coverage on the 5 missing files) from [`FUTURE_descriptor_consumers.md`](FUTURE_descriptor_consumers.md); implement `describe_actions` and `invoke_action`; add `mutates` to existing descriptors that should be AI-callable.
5. Write the first end-to-end test for whichever path ships first.
