# Joinery AI — Write Tools

Part of the Joinery AI plugin. See [`implemented/joinery_ai.md`](implemented/joinery_ai.md) for the core system spec, and [`implemented/joinery_ai_autodiscovery.md`](implemented/joinery_ai_autodiscovery.md) for the (shipped) read side of model auto-discovery.

This spec covers **both AI write surfaces**:

| Use case | Path | Opt-in mechanism |
|----------|------|------------------|
| Self-contained CRUD on a single table | Direct model write | `$ai_writable_fields` allowlist on the model |
| Anything with cross-record / cross-system effects | Logic-file write | `mutates: true` on the logic file's `_logic_descriptor()` |

Both paths default off. The split exists because the validation gauntlet only applies to logic-bound models — for self-contained tables, field-spec validation IS the entire gauntlet, and a logic-file wrapper would be ceremony.

**Status:** designed. Both paths ship together. They cover different layers of the write surface and are intended to be complementary, not alternatives — see [Path coexistence](#path-coexistence-and-write-path-overlap) below.

Authorization is not a separate concern under admin-only deployment: admins can already perform any action through the admin UI, so an LLM-triggered write is bounded by admin trust, not by per-action gating. There is no approval-queue / interactive-only / prompt-scoped infrastructure to design — those mechanisms only exist for the multi-user case (recipe-owner ≠ admin), which Joinery AI is not built for. If admin-only ever changes, gating becomes new work at that point, not a flip of pre-staged metadata. Same stance as the read side took on owner-scoping.

## Actor model and recordkeeping

Writes happen *as the recipe owner* — `rcp_recipes.rcp_usr_user_id`. There is no dedicated AI user, and no "temporary elevation." Creating a recipe already requires permission 10, so the owner is using existing admin perms through a different surface, not being promoted for the duration of the run.

A dedicated AI user was considered and rejected:

- It detaches the action from the responsible human. The audit answer to "who authorized this write?" should be the admin who configured the recipe, not a synthetic principal that nobody owns.
- It breaks ownership checks on per-user tables — a `Booking` owned by user 42 would have to grant write to the AI user, which forces the AI user to be permission 10, which is the original arrangement with extra steps.

### Dual attribution, not actor substitution

- **Tables with actor columns** (`xxx_update_user_id`, `xxx_create_user_id`, etc.) get the recipe owner's user id. That's correct attribution to the responsible human.
- **`rcr_tool_calls`** (already in the plan as the per-call audit log) captures the AI trail. The forensic question "did the admin do this directly or via AI?" is answered by joining: a write at time T with no matching `rcr_tool_calls` row is a direct admin action; one with a match was AI-driven and points back to the specific recipe + run + iteration.

If a future audit report needs to filter human vs. AI activity, the fix is to teach that report about `rcr_tool_calls`, not to swap actor identity.

### Owner lifecycle: pre-run check covers all "no longer admin" states

A recipe owner's status can change between recipe creation and the next scheduled run — demotion, soft delete, permanent delete. Any of these should stop the recipe from continuing to act with admin reach.

`RecipeRunner::checkOwnerActive()` runs at the start of every run and fails it with a specific error if any of:

- Owner record doesn't exist (permanent delete).
- Owner has `usr_delete_time` set (soft delete).
- Owner's `usr_permission < 10` (demotion).

Each condition produces a distinct error message naming which one fired, so the admin investigating the failed run can see exactly what to fix. The check is lightweight (one user lookup, run-start only — not per-tool-call) and catches the configuration drift that would otherwise let writes happen as a stale admin.

### Ownership transfer and orphan surfacing

Recipes are not auto-deleted when their owner is deleted or demoted. The recipe row persists in a broken state; recovery is by ownership transfer rather than recreation.

- **Transfer mechanism.** The recipe edit page (`/admin/joinery_ai/edit`) gets an owner dropdown listing all active permission-10 admins. Any current permission-10 admin can change ownership to any other permission-10 admin. There is no special transfer flow — "edit the recipe and change the owner field" IS the transfer.
- **Orphan visibility.** The recipe list page (`/admin/joinery_ai`) gets an "Owner" column showing the owner's name and a status badge (active / inactive). Inactive means the owner fails the same `checkOwnerActive()` predicate the runner uses. An inactive owner is the visible signal that the recipe is broken and needs ownership transferred.
- **No cascade on user deletion.** When a permission-10 admin is deleted, their recipes are *not* deleted. Silently removing recipes during an admin departure is worse than silently breaking them: the broken state is recoverable (transfer ownership), the deletion isn't. The orphan badge on the list page is the recovery surface.

What v1 does not include: alerting when a recipe transitions to orphan state. The list-page badge is the surface; if real ops show that's insufficient (recipes silently broken for weeks because nobody checks the list), add a dashboard alert later.

### Session context for the actor

The recipe owner is the actor identity, but the layers below need that identity presented through specific channels. Two consumers care:

- **Path 1 — `authenticate_write()`** expects a `$data` array with `current_user_id` and `current_user_permission`.
- **Path 2 — logic files** use `SessionControl::get_instance()` to read user identity / permission, the same way they do under HTTP request handling.

`RecipeRunner` initializes the SessionControl singleton with the recipe owner's identity at run start, after the lifecycle checks pass and before the first tool call. Logic files keep using `SessionControl::get_instance()` exactly as they always have — they don't know they're being called from a recipe runner. `ModelWriteExecutor` builds `authenticate_write()`'s data array from the same singleton.

Each recipe run is a fresh PHP worker process spawned per pending row by `RecipeWorkerSpawner`, so the singleton starts blank, the runner is the only thing that touches it, and the mutation evaporates on process exit. No leakage between runs or other requests.

Sequence at run start: owner-lifecycle check → taint-drift check → allow-list staleness check → setup actor session → first inference call.

#### Implications for AI-callable logic files

Logic files declared with `mutates: true` must tolerate `SessionControl::get_instance()` returning the *recipe owner's* identity rather than the user the data belongs to. For most actions this is a no-op — the recipe owner is admin, and admin-trusted actions don't differentiate by caller identity. For actions that branch on "is this the user's own data," the author re-evaluates AI-callability before declaring `mutates: true`. That re-evaluation is part of opting an action in; the descriptor declaration is the explicit acknowledgment.

#### Acknowledged as pragmatic, not optimal

The singleton-mutation pattern is recognized as a v1 compromise, not the right long-term design. It works because each run is a single process with a well-bounded lifecycle, but it carries the failure modes of all global-state designs:

- Logic-file behavior depends on whatever last mutated the singleton, not on what was passed in. The coupling is invisible from the function signature.
- Assumptions about per-process scoping break if the runner ever moves to an async or threaded model that batches multiple recipes in one process.
- The pattern is the kind of "spooky action at a distance" that bites later when something changes in the surrounding environment.

A cleaner design would either thread identity through as an explicit parameter (`_logic($input, $context)`) or add a thread-local override API on SessionControl that AI-callable code uses explicitly. Both require a broader refactor of the codebase's logic-file surface and weren't worth the cost for v1 when the alternative is not shipping. Revisit if the runner architecture changes or the implicit-context coupling causes real bugs.

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

A recipe is *tainted-capable* if `rcp_allowed_tools` contains any write tool (`create_model`, `update_model`, `delete_model`, `invoke_action`) AND at least one of the following:

1. `rcp_allowed_models` contains any model whose class declares a non-empty `$ai_untrusted_fields`, or
2. `rcp_workspace` is non-empty — see [Workspace as a cross-run taint vector](#workspace-as-a-cross-run-taint-vector) below.

A tainted-capable recipe must have `rcp_allow_tainted_writes = true` to save. The check fires in `admin_edit_logic.php`; the form rejects with a plain-language error explaining why and naming the specific trigger(s) — offending tool(s) and either the offending model(s) or "non-empty workspace from prior runs."

This is a static gate — no runtime taint flag, no per-call check, no taint columns on `rcr_recipe_runs` or `rcr_tool_calls`. The trade-off vs runtime taint tracking is precision: a recipe allowed to query `Booking` but that never actually selects `bkn_notes` still has to opt in. That's accepted — a recipe whose allowed-models list lets it read untrusted text *could* read it on any iteration anyway, and pushing the decision to recipe-edit time puts it in front of the author at the right moment instead of as a mid-run failure.

The opt-in itself: `rcp_allow_tainted_writes` is a deliberate acknowledgment that this recipe's prompt is robust to injection in the queried fields. Setting it is bounded by admin trust (creating any recipe requires permission 10).

This is the structural mitigation. The other two recommendations from `INFO_prompt_injection.md` remain in scope but secondary:

- **`confirmation_required` flag on high-impact descriptors** — selective per-action approval gating (cancel_order, send_email_to_list, delete_user); the runtime surfaces those calls to the admin for explicit OK before executing. Decision deferred until the first real write use case.
- **Audit alerting on write rate** — every write logged to `rcr_tool_calls` (already planned) plus a configurable rate-limit alert per recipe run. Decision deferred.

### Workspace as a cross-run taint vector

`rcp_workspace` is LLM-curated text that persists across runs. The save-time taint gate is correctly scoped for *one run*, but the workspace is a state channel that bypasses it: a tainted note read on run N can be copied into the workspace, and run N+1 will start with that text in its system prompt — even if the recipe's allow-list has since been narrowed to "clean" models.

Two structural defenses cover this:

1. **Workspace is always wrapped in untrusted delimiters in the system prompt** — the same `<untrusted>` framing the read side uses for `$ai_untrusted_fields`. The LLM is instructed to treat its own past notes as adversarial input, not authoritative context. This applies unconditionally, regardless of whether the recipe is tainted-capable, because past LLM output is structurally untrusted: the system prompt is the only authoritative voice, and the workspace doesn't carry that authority.
2. **A non-empty workspace makes a recipe tainted-capable when combined with any write tool** (predicate condition 2 above). This forces the recipe author to opt into `rcp_allow_tainted_writes` once the workspace has been populated. The opt-in is a deliberate acknowledgment that prior LLM-curated state may have been influenced by tainted reads and the prompt is robust to that.

Together these mean: workspace content is treated as adversarial by the LLM (defense 1), and the admin has acknowledged the cross-run risk (defense 2). The combination closes the carry-over hole without requiring runtime per-call taint tracking.

#### Considered and rejected: workspace clearing on allow-list narrowing

A targeted alternative — when an admin removes a model with `$ai_untrusted_fields` from `rcp_allowed_models`, force-clear `rcp_workspace` on save — was considered. Rejected because predicate condition 2 already covers the same scenario: removing the untrusted model doesn't drop the recipe's tainted-capable status while the workspace remains non-empty, so `rcp_allow_tainted_writes` is still required and the admin still gets the explicit prompt. Auto-clearing user data (the workspace is genuinely useful state for some recipes) to defend against a case the gate already catches is the wrong trade.

### Implementation surface for the taint gate

- New column on `rcp_recipes`: `rcp_allow_tainted_writes bool default false`.
- `admin_edit_logic.php` computes the tainted-capable predicate from the posted `rcp_allowed_tools`, `rcp_allowed_models`, and the persisted `rcp_workspace` value (the user can clear workspace separately if they want to drop the gate trigger; otherwise it's read live). On a tainted-capable save without the flag, returns a `LogicResult` error naming the specific tools and triggers.
- `/admin/joinery_ai/edit` gets a checkbox for `rcp_allow_tainted_writes` with a help blurb that names the trade-off in plain terms ("This recipe can perform writes and either reads user-generated text or carries LLM-curated state across runs — confirm the prompt is robust to injection from those sources").
- `RecipeRunner` wraps `rcp_workspace` content in `<untrusted>` delimiters when assembling the system prompt, mirroring the read-side `$ai_untrusted_fields` framing. The system-prompt preamble explicitly tells the LLM to treat workspace content as untrusted carryover.
- The list of write tool names lives on a constant in the write-tool registry so the gate doesn't drift if a fifth write tool is added later.

### Run-start re-evaluation: catching model-class drift

The save-time gate locks in the predicate's truth at the moment of save, but the predicate depends on model class state — specifically, whether any allowed model declares `$ai_untrusted_fields`. That state evolves. A developer adding `$ai_untrusted_fields = ['some_field']` to a model class three months after a recipe was saved silently makes the recipe tainted-capable without re-triggering the save-time gate. The recipe runs with its old (now-stale) safety claim.

To close this drift, `RecipeRunner` re-evaluates the same predicate at the start of every run, alongside the owner-lifecycle check. If the predicate is now true AND `rcp_allow_tainted_writes` is still false, the run fails with a specific error naming which model and field newly triggered the gate (e.g. "`SdScheduledBlock.ssb_user_notes` was added as an untrusted field; re-acknowledge `rcp_allow_tainted_writes` on the recipe to allow continued operation").

This is one-way tightening: a predicate that becomes false again (e.g. the field was removed) does not auto-clear the recipe's opt-in. The opt-in is the admin's acknowledgment, not derived state.

The check is free in any meaningful sense — it reads class properties already in memory and intersects arrays already loaded with the recipe row. No additional DB queries, no LLM API calls. Both run-start lifecycle checks (owner + taint-gate drift) fail fast before the first inference call, so a recipe that fails either check spends zero tokens.

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

### Read opt-in is required for write opt-in

A model declaring a non-empty `$ai_writable_fields` MUST also declare `$ai_readable = true`. The two opt-ins are coupled because the LLM only learns about a model's existence and field schema through `describe_models` (the read-side surface). Without read opt-in, the model is invisible at the schema level, and `create_model` / `update_model` calls have no way to be constructed correctly. The Tool Response Envelope's reliance on `query_model` for post-write verification also assumes the model is readable.

#### Enforcement

`ModelRegistry` validates the constraint when scanning model classes. The check is read-only — it inspects the existing static properties on each class via reflection and never modifies them. On violation:

- **Write surface not registered.** The model is not added to the registry's writable map. It does not appear as AI-writable anywhere. (If `$ai_readable` is also false, the model is fully invisible to the AI surface — same outcome as a model that opted into nothing.)
- **Error-level log entry.** `[joinery_ai] UserNote: $ai_writable_fields is set but $ai_readable is not true. Write surface not registered. Set $ai_readable = true to enable.` Logged at error level so it surfaces in any error-monitoring tooling, not just buried in routing-debug noise.
- **In-band UI alert on the recipe edit page.** `/admin/joinery_ai/edit` renders a "Models with configuration issues" alert above the model checkbox list when the registry has any skipped models. Each entry names the class and the reason. The alert is absent when there's nothing to show — zero clutter on the normal path.

The combination closes the visibility gap from both directions: the error log catches it for anyone watching production monitoring; the edit-page alert catches it for the developer actively trying to use the missing model and wondering why it isn't there. The data is already available — the registry knows what it skipped — so the surface is a small `ModelRegistry::warnings()` method plus an alert block on the edit view.

#### Considered and rejected

- **Auto-elevating `$ai_readable` to true when `$ai_writable_fields` is set.** Implicit mutation of class-level intent; lets developers ship misconfigured opt-in silently. The opt-in to reads should remain an explicit declaration.
- **Adding a write-only schema discovery surface** (e.g. `describe_writable_models`). Overhead for a use case that doesn't exist yet. If a real recipe needs write-only access to data that shouldn't be browsable, the better answer is likely `$ai_excluded_fields` on the read side, not a parallel discovery surface.
- **Throwing at PHP load time** so the application fatal-errors on a misconfigured model. Too aggressive — breaks unrelated app functionality for an AI-surface configuration issue. Graceful degradation matches the existing pattern for non-AI models.

### Excluded fields are not writable

A field listed in `$ai_excluded_fields` cannot also be written via `create_model` or `update_model`. The exclusion means "hands off, both directions" — read-blocked AND write-blocked. Allowing writes to a field whose contents are deliberately hidden from the AI would violate the model's expressed intent.

Two enforcement layers, mirroring the auto-block regex (`/_(password|secret|key|token|hash)$/i`) that already strips universally-sensitive patterns:

#### Registry-scan-time warning

`ModelRegistry` detects `$ai_writable_fields ∩ $ai_excluded_fields ≠ ∅` when scanning model classes. On intersection:

- Error-level log entry names the model and the conflicting field(s): `[joinery_ai] User: usr_internal_notes appears in both $ai_writable_fields and $ai_excluded_fields. Field will be stripped from writes; remove from one list to silence this warning.`
- The recipe edit page's "Models with configuration issues" alert (from the writable-without-readable rule) surfaces the same conflict, named per-field.
- The model's read and write surfaces still register — only the conflicting fields are removed from the writable surface. Partial conflicts don't kill the whole surface.

#### Executor-level strip (defense in depth)

`ModelWriteExecutor` removes any field in `$ai_excluded_fields` from the input map before `set()` is called, the same way the auto-block regex already strips password/secret/key/token/hash. The Tool Response Envelope's `fields_set` output names exactly which fields were applied, so the LLM sees what got dropped and can adapt on the next call rather than retrying the same input.

The auto-block regex remains as a third layer for the universally-sensitive patterns it covers — it catches cases the developer never declared in `$ai_excluded_fields` because the field name made the intent obvious.

#### Considered and rejected

- **Refuse to register the entire write surface on intersection.** Too aggressive — most of the writable list is probably fine, and killing the whole surface is overkill for a partial conflict. Strip + warn is precise.
- **Treat the intersection as opt-in to write-only mode** (write yes, read no, for patterns like "mark as reviewed without revealing contents"). Tempting, but adds a third opt-in concept (`$ai_write_only_fields` or similar) without evidence of demand. Excluded-means-hands-off is the simpler invariant; revisit if a real recipe asks for the write-only pattern.

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

## Path coexistence and write-path overlap

Both paths ship together. They cover different layers of the write surface and are intended to complement each other:

- **Path 1** addresses self-contained data-layer writes — opt a model in via `$ai_writable_fields`, get CRUD coverage with no per-action ceremony.
- **Path 2** addresses workflow-layer writes — declare `mutates: true` on a logic-file descriptor, get the full validation gauntlet by construction.

A recipe can use either, both, or neither. The two allow-lists (`rcp_allowed_models`, `rcp_allowed_actions`) are orthogonal — granting one does not imply the other.

### The overlap case

A model can have *both* `$ai_writable_fields` declared *and* a `mutates: true` action that wraps it. In that situation, the LLM has two routes to the same effect:

- `update_model('Subscription', id, ['sub_status' => 'cancelled'])` — direct write, skips refunds, hooks, notifications.
- `invoke_action('cancel_subscription', {id})` — full workflow.

Both are correct in isolation; the tension only appears once both are reachable from the same recipe. An admin who ticks "Subscription" into `rcp_allowed_models` without realizing `cancel_subscription` exists will quietly grant a recipe the ability to bypass the workflow.

### Author-side guidance (the chosen mitigation)

The recipe edit page surfaces the overlap to the recipe author at the moment they're choosing scope:

- Each entry in the **Allowed Models** checkbox list shows a "Wrapped by:" footnote naming any `mutates: true` actions whose descriptor input schema targets that model (typically by including its primary key). The footnote suggests granting those actions in place of, or alongside, direct write.
- Each entry in the **Allowed Actions** checkbox list shows the model(s) it mutates, so the author can see the connection from the other direction.

This is convention-as-UX, not enforcement. The LLM still has both routes if both are checked. The decision of which to grant stays with the human authoring the recipe — they're the one who knows whether the recipe needs raw field access (e.g. a maintenance recipe fixing a corrupted column) or workflow-respecting writes (e.g. a churn-management recipe).

The cross-reference data is already available: `RecipeToolRegistry` knows the registered actions, and descriptors carry input schemas that name the target model(s). The edit page renders that connection inline; no new metadata is required.

### Considered and rejected: hard exclusion

A stricter alternative — a model field covered by a `mutates: true` action becomes unwritable via Path 1 unless explicitly overridden — was considered and deferred. It would require descriptors to declare which fields they cover (new metadata) and would need an override mechanism for legitimate raw-access cases. Worth revisiting if real recipes routinely trip on the overlap; not worth pre-building.

### Allow-lists gate direct access, not transitive access

`rcp_allowed_models` and `rcp_allowed_actions` scope two different surfaces, and the distinction matters:

- **Direct access** — what the LLM can reach via `query_model`, `create_model`, `update_model`, `delete_model` (Path 1). This is gated by `rcp_allowed_models`. A model not on the list is structurally unreachable from these tools.
- **Transitive access** — what an invoked action reaches internally as part of doing its job. This is **not** gated by `rcp_allowed_models`. An action can read or write any model its implementation touches; the recipe author granted access to the action, and the action's contract is whatever its `_logic()` does.

Concrete example: a recipe with `rcp_allowed_models = [Subscription]` and `rcp_allowed_actions = [send_user_email]` cannot directly query the User model, but it can call `send_user_email(usr_user_id, subject, body)`, and that action reads User internally to fetch the email address. The User model is reachable transitively via the action despite not being on the model allow-list.

This is by design: actions encapsulate their own authorization model and side-effect surface, and granting an action means accepting whatever that action does. But authors must understand that **`rcp_allowed_models` is not a complete data-access boundary** once `rcp_allowed_actions` is non-empty. The complete boundary is the union of (a) directly-allowed models and (b) everything the granted actions reach internally.

The practical consequence: when reviewing a recipe's scope, the author has to read the action's `description` (and ideally its source) to know what it actually touches. The descriptor's `description` field is contractually the place that documents this — "Sends an email to a user; reads User to fetch the address" — and authors writing descriptors should treat it as the place to disclose internal reach.

Surfacing all touched models in the edit UI (beyond just mutated ones, which the path-overlap UI already shows) was considered. It would require a `reads` field on every descriptor, which is overhead the system can't yet justify; relying on honest `description` text is the v1 stance, revisitable if real recipes get surprised.

---

## Idempotency contract

The retry vector is narrow: the framework does not auto-resume. The only path to re-execution is the LLM seeing a tool-result error and emitting the same tool call again. The contract below covers that case.

Per-tool semantics:

- **`update_model`** — idempotent. Same input → same end state. Safe to retry after an error response.
- **`delete_model`** — idempotent. Soft-deleting an already-deleted row is observably a no-op. Safe to retry.
- **`create_model`** — **not idempotent.** Each call produces a new row. Returns the new row's primary key and creation timestamp on success, so the LLM can recognize prior success from its conversation history.
- **`invoke_action`** — depends on the action. Authors declare semantics in the descriptor `description` text (e.g. "Sends a confirmation email — not idempotent" or "Sets status to active — idempotent on repeated calls"). The framework does not enforce or coerce.

Mechanism: every write-tool response carries enough identifying info for the LLM to recognize duplicate-success without re-executing. The system-prompt preamble instructs the LLM explicitly:

> When a write tool call has already returned a success result in this conversation, do not re-emit the same call. The result in your history IS the result; re-emitting will produce a duplicate, not a retry. Retries are appropriate after error responses, never after success responses.

### Considered and rejected: replay infrastructure

A persisted idempotency-key table — runner injects a deterministic key per call, caches results, returns cached responses on duplicate keys — was considered. Rejected for v1 because the retry pathology that the cache would catch (LLM re-emitting after success) is not the observed failure mode: LLMs don't redo work they just got a result from; they move on. Retry happens after errors, where re-execution is correct and we want it to succeed. Building replay infrastructure ahead of evidence that the contract approach fails is overhead before need. Revisit if real recipes show post-success retry behavior.

### Considered and rejected: in-run hash-based dedup

A per-run in-memory `(tool_name, input_hash)` table that returns cached results on exact duplicates was considered. Rejected because it has surprise factor — the LLM cannot tell why a tool call returned a "this already ran" result — and because it blocks legitimate deliberate re-assertions of the same state. The contract approach trusts the LLM to read its own conversation history, which composes correctly with its natural behavior.

## Tool response envelope

Every write tool returns a structured envelope, not raw model rows or LogicResult internals. The envelope is the LLM-facing contract: it's what the idempotency mechanism relies on for duplicate-success detection and what bounds the tool's output surface.

### Path 1 — direct model writes

```
create_model success → { status: 'success', model, key, created_time }
update_model success → { status: 'success', model, key, fields_set: [...] }
delete_model success → { status: 'success', model, key }
any error            → { status: 'error', model, code, message }
```

No row contents flow back through the envelope. If the LLM needs to verify post-write state, it calls `query_model`, which already filters by `$ai_excluded_fields`. This keeps reads and writes aligned on the read-side filter and prevents `create_model` from acting as a backdoor query around `$ai_excluded_fields`.

### Path 2 — action invocations

```
success → { status: 'success', action, summary, data }
error   → { status: 'error', action, code, message }
```

Where:

- `data` = `LogicResult.data` directly. This is the same payload convention the REST API uses; an action's AI-facing data and its REST-API-facing data are the same surface.
- `summary` = first entry of `LogicResult.messages`, or a generic "completed" if empty.
- `page_vars`, `redirect`, and internal LogicResult flags are stripped at the tool boundary. They never reach the LLM.

Action authors do not declare a separate AI output shape. The convention is: anything in `data` is publicly visible (REST API + AI); anything in `page_vars` is view-internal. That split already exists for non-AI reasons; the AI reuses it.

#### Considered and rejected: per-descriptor `output_shape` field

An earlier draft added a per-descriptor `output_shape` field letting actions declare a different AI surface than their REST API surface. Rejected because the divergence was speculative — there's no concrete reason an action's REST and AI outputs should differ — and it adds metadata before evidence of need. If a real recipe exposes an action putting AI-unsafe data in the `data` payload, the fix is to clean up `data` (which also fixes the REST API surface), not to add per-surface filtering.

## Concurrency: last-write-wins, by design

Recipe runs take seconds to minutes; the world can shift between an LLM read and an LLM write. Two flavors of conflict are possible:

- **Stale reads driving writes** — a query at T0 returns a row state that no longer holds at T1 when the LLM writes. The LLM's plan is based on T0; T1 reality is silently lost.
- **Concurrent writes** — another writer (admin form, REST API, public-page action) modifies the same row between the LLM's read and write. Both writes succeed; the later one wins. No conflict detection — `SystemBase::save()` does a plain UPDATE by primary key with no version guard.

This is consistent with the rest of the application. The model layer has never had optimistic locking; admin forms, REST endpoints, and public actions all operate last-write-wins. Carving out AI writes as the one surface with concurrency guards would be asymmetric and introduce a coordination concept the rest of the codebase doesn't have.

### v1 stance

Recipes operating on actively-changing data are in last-write-wins territory. Authors who need concurrency safety should:

- **Use Path 2 (logic-file actions) for concurrency-sensitive writes.** A logic file is a single PHP scope where re-fetch + validate + mutate happens together; that's the right level to coordinate against concurrent writers, the same way it already coordinates transactions, validation cascades, and downstream hooks.
- **Avoid recipes that touch high-contention rows** (active-user profiles, in-flight orders) unless the action layer guarantees consistency.
- **Re-read before write in the recipe prompt** when stale reads would meaningfully drive bad writes — instruct the LLM to query the row immediately before updating it.

### Considered and deferred

- **Version columns + framework-level optimistic locking on AI-writable models.** Cross-cutting change to the data layer, imposes the column on all consumers (not just AI), inconsistent with the rest of the application. Not worth the cost without evidence of conflict-driven harm in real recipes.
- **`expected_values` parameter on `update_model`.** The LLM passes a map of fields it expects to be unchanged; the executor verifies before writing and fails with a structured error if they've shifted. Opt-in per call, no schema changes. This is the lighter escalation path if v1 demand materializes; not built in v1 because the LLM doesn't reliably remember to use opt-in safeguards, and Path 2 covers the same ground at the right abstraction layer.

## Per-call timeouts and run-budget boundaries

The dispatcher already has structural bounds on a run: `RecipeRunner::WALL_CLOCK_SECONDS = 90s` and a 600s reaper for stuck workers. There is no per-tool-call timeout. A single `invoke_action` that hangs (slow external API, runaway query, deadlock) consumes the full wall-clock budget; the LLM never sees the call return, and the run dies as `timeout`.

### v1 stance

The wall-clock budget is the only timeout. A run that exceeds 90s is killed; the dispatcher reaper catches stuck workers within 600s. This is consistent with rejecting the per-run write volume cap — the system intentionally avoids artificial bounds on individual operations because legitimate bulk work (bulk updates, batch sends, cross-system reconciliation) routinely takes 30-90s. Capping individual calls just breaks differently than capping volume, while the structural failure mode (one call eats the budget) is recoverable: if a recipe hits the wall-clock repeatedly, the recipe needs to be redesigned to do less per run, not gain a per-call cap.

### Diagnostic addition: tool-call start time

`rcr_tool_calls` records each call's start time, not just its completion time. The audit row is inserted at dispatch (with `rct_started_time` set, `rct_completed_time` and result null) and updated when the call returns. When a run times out, the audit shows the last tool call that *started* but never completed — that's the call that hung. Without this, the audit only contains completed calls and can't identify the offender on a timeout.

One column addition (`rct_started_time`) and a write-at-dispatch ordering change. No new infrastructure.

### Considered for future work, not v1

- **Per-call hard timeout.** Kill the call after N seconds, surface `{status: 'error', code: 'tool_timeout'}` to the LLM so it can react. Useful when an action is known to hang, but caps legitimate slow operations at the cap. Defer until a real recipe shows the wall-clock-only stance is insufficient.
- **Soft per-call timeout in the envelope.** Add `elapsed_ms` to every tool response so the LLM can see which calls are slow without being killed. Adds visibility without bounding runtime; the LLM can choose to avoid slow calls on subsequent iterations. Useful for self-tuning recipes; not v1 because it depends on the LLM actually using the field, which is unproven.
- **Signal-based per-call termination.** PHP `pcntl_alarm` or equivalent to forcibly interrupt a hanging call. The implementation surface for the per-call hard timeout above; required if that one ever lands.
- **Retry-around-slow-calls / alternative-tool-on-timeout.** Failover behavior for known-slow actions. Requires action-level metadata about expected latency and is out of scope for v1.

## Iteration budget exhaustion

`rcp_max_iterations` caps the tool-loop depth (default 50, max 50 in the edit form). When the LLM hits this cap without naturally concluding — i.e. it's still emitting tool calls when iteration N+1 would have happened — the runner stops the loop. This is neither a "success" (the LLM didn't actually finish) nor a "failed" (no error occurred — the system worked as configured). It's its own state.

A new run status, `incomplete`, distinguishes this case from `success` and `failed`. On exhaustion, the run row gets `rcr_status = 'incomplete'`, `rcr_error = 'iteration budget exhausted at N iterations'`, and `rcr_completed_time` set. The audit log records every tool call up to the cap, including any partial writes the LLM made along the way (which stay committed, per the partial-write disposition elsewhere in this spec).

The admin's response: either increase `rcp_max_iterations` (if the cap is too low for the recipe's natural pattern), restructure the prompt to converge faster (if the LLM is wandering), or accept that the recipe genuinely needs more than the budget allows and split it into smaller recipes.

Existing statuses (`pending`, `running`, `success`, `failed`, `timeout`, `cancelled`) are preserved; `incomplete` is additive. The recipe list page surfaces `incomplete` runs distinctly so the pattern is visible — distinguishing "hit the cap repeatedly" from "fails repeatedly" matters for diagnosis.

## Mid-run cancellation

The wall-clock budget bounds total run duration, but a recipe doing the wrong thing within 90 seconds can still commit thousands of writes. Without a cancellation mechanism, an admin watching a misbehaving run in the audit log can't intervene — `rcp_enabled = false` stops future scheduled runs but doesn't reach in-flight ones, and the wall-clock is the only structural cut-off.

### Run-row kill flag

A new column `rcr_kill_requested bool default false` on `rcr_recipe_runs`. The runner checks the flag at the top of every tool-loop iteration — one extra read against the run row that's already loaded. If set, the run aborts: marks `rcr_status = 'cancelled'`, `rcr_completed_time = NOW()`, `rcr_error = 'cancelled by admin'`, and exits cleanly without making the next inference call.

Implementation surface:

- One column on `rcr_recipe_runs` (`rcr_kill_requested`).
- One additional check at the top of each tool-loop iteration in `RecipeRunner`.
- A "Stop" button on the recipe runs view. Admin clicks → `UPDATE rcr_recipe_runs SET rcr_kill_requested = true WHERE rcr_run_id = ?`. The next iteration boundary picks it up; cancellation completes within one round-trip of whatever the LLM is doing.
- When an admin sets `rcp_enabled = false` on a recipe with active runs, the save path also sets `rcr_kill_requested = true` on those runs. One button means "stop everything."

### What this doesn't cover

Mid-tool-call cancellation. A run waiting on a slow `invoke_action` (e.g., a 60-second external API call) still waits for wall-clock — the kill flag stops the iteration *loop*, not PHP execution mid-call. Admins watching such recipes have at most a 90s wait either way.

### Considered for future work

- **Signal-based mid-call interruption.** POSIX SIGTERM trapped by the runner; interrupts within a slow tool call. Defer; combines naturally with the per-call hard timeout future-work item, since both depend on the same signal infrastructure.
- **Kill-reason field.** A free-text `rcr_kill_reason` column for the admin to record why they stopped the run. Useful for audit trail when multiple admins might intervene independently. Not v1; the existing `rcr_error` field captures the basic "cancelled" signal.

## API resilience: handling Anthropic API failures mid-run

The runner calls Anthropic's API on every tool-loop iteration. Real-world failure modes during a run: transient network errors, 429 rate limits, 5xx server errors, and 4xx hard errors (invalid key, quota exceeded, malformed request). Without explicit handling, any of these kills a run and leaves whatever was already written committed, with no useful diagnostic for the admin.

### v1 stance

Three components, lean by design:

1. **Trust the SDK's built-in retry behavior.** The official Anthropic SDK retries 429 and 5xx with exponential backoff (typically 2-3 retries). The runner does not add a second retry layer on top — that compounds delays into the wall-clock budget without measurably better recovery. If the SDK exhausts its retries, the runner treats it as a hard failure.
2. **Specific error codes in `rcr_error` on hard failure.** The admin needs to know whether the failure is fixable config or transient infrastructure:
   - `api_auth_failed` — 401/403; check API key in `Globalvars_site.php`.
   - `api_quota_exceeded` — 402, or 429 after retries; check account quota.
   - `api_server_error` — 5xx after retries; usually transient.
   - `api_request_invalid` — 400/422; runner bug, should never occur in production.
   - `api_network_error` — timeouts / connection failures after retries.
   Each code is human-readable on the run detail page and points at the appropriate fix.
3. **Partial writes stay committed.** Consistent with the rollback decision elsewhere in the spec — direct-model writes are single-row, logic-file actions handle their own atomicity, no framework-level rollback. A run that failed mid-way leaves whatever was written, written. The audit log records exactly what was done up to the failure point, so the admin can decide whether to manually clean up, re-trigger, or accept the partial state.

### Considered for future work

- **Resume-from-checkpoint.** Restart a failed run from where it left off. Out of scope for v1: requires state tracking (which rows were operated on, what the LLM had decided), and the LLM's plan at failure time is stale by the time the restart happens. The simpler fix — the next scheduled run starts fresh and re-evaluates — is sufficient.
- **Per-error-code retry policy in the runner.** A second retry layer over the SDK's retries, with different policies per error class. Adds complexity and clock-time without evidence of need; the SDK already handles the transient cases reasonably.
- **Circuit-breaker on repeated 429s.** Auto-pause a recipe whose calls keep getting rate-limited. Operationally useful but not v1 — the admin sees failures in the run history and can disable manually.

## Stale references in allow-lists

`rcp_allowed_models` and `rcp_allowed_actions` are jsonb arrays of class/action names persisted to the database. Code evolves: model classes get renamed or removed, logic files with `_logic_descriptor()` get deleted. The recipe's allow-list outlives those changes and ends up referencing names that no longer resolve.

This is the same shape as the taint-gate model-class drift handled at run start — the recipe was valid at save time, but the codebase moved underneath it. The same lifecycle pattern (run-start re-check + edit-page surface + save-time normalization) covers it.

### Run-start staleness check

`RecipeRunner` resolves every entry in `rcp_allowed_models` against `ModelRegistry` and every entry in `rcp_allowed_actions` against the action registry, alongside the owner-lifecycle and taint-gate drift checks. If any entry doesn't resolve, the run fails with a specific error naming the missing items:

> `Recipe references models that no longer exist: [UserNote]. Edit the recipe to remove these entries.`

The check is free in token terms — registry lookups run pre-LLM, before the first inference call. All three run-start checks (owner, taint drift, allow-list staleness) compose into one fail-fast pass.

### Edit-page surface

When the recipe edit page renders the model and action checkbox lists, it also detects entries in the persisted allow-list that no longer resolve in the live registry. Each list gets a "Stale references" notice below its checkboxes naming the missing entries. The admin removes them with a single click — or just re-saves the recipe to clear them automatically.

### Save-time normalization

The save path filters out allow-list entries that no longer resolve. Re-saving the recipe (the simplest admin action) cleans the list. This happens only on explicit save — never as a silent background mutation.

### Considered and rejected

- **Auto-remove stale entries during registry scan.** Silent mutation of admin intent. A stale entry may be a signal the admin should investigate — was this model removed intentionally, should the recipe be retired? Cleanup should be explicit.
- **Auto-disable the recipe when stale entries are detected.** Too aggressive — staleness is recoverable via re-save, and silently disabling a scheduled recipe goes unnoticed for weeks. The run-start error makes the failure visible at the next scheduled fire.

## Other operational notes

Smaller concerns with obvious dispositions, grouped here rather than scattered.

### Audit logging is best-effort

`rcr_tool_calls` records each tool call with its inputs, output envelope, start time, and completion time. The insert is best-effort — if it fails (DB connection blip, write conflict), the runner logs the failure to `error_log` and proceeds. The action's effect on the touched models is the source of truth; the audit row is supplementary. Aborting an action because its audit row failed to write would create a worse failure mode (dropping legitimate work to preserve logging).

A run with audit-log gaps still completes with whatever final status it would have had. Gaps are observable in error logs and as time-discontinuities in the run's tool-call timeline.

### System prompt cardinality and cost

Recipe authors with large `rcp_allowed_models` allow-lists pay a token cost: every selected model's schema is injected into the system prompt at run start (read-side design, inherited from autodiscovery). A recipe with 30 allowed models can carry 15K+ tokens of schema before the prompt itself. Across many iterations, even with prompt caching, the cumulative cost adds up.

Path 2 doesn't have this issue — actions are discovered via `describe_actions` tool calls, not upfront injection. Only action names and descriptions sit in the prompt; full schemas come on-demand.

For v1, the lever is recipe author discipline: grant minimal models. The "Allowed Models" UI on the edit page shows model count next to the checkbox list as a soft signal of cost.

Future work: extend the lazy-schema pattern from actions to models — a `describe_models(name)` tool that fetches a specific model's schema on demand, with only names and descriptions injected up front. That's a read-side design change beyond this spec's scope; revisit if recipe authors routinely hit cost ceilings from large model allow-lists.

### Descriptor / `_logic()` signature drift

`_logic_descriptor()` declares the action's input shape; `_logic()` consumes it. If a developer changes one without the other (e.g., adds a required input to `_logic()` but doesn't update the descriptor), the LLM constructs calls satisfying the descriptor but failing in `_logic()` with a runtime error.

This is a quality-practice problem, not a design decision: descriptor and `_logic()` are paired by author convention. Recommended discipline is a smoke test per descriptor that calls `_logic()` with the descriptor's input schema (filled with type-appropriate samples) and asserts the call doesn't error on input validation. A CI step running these smoke tests catches drift before deploy.

Adding framework-level enforcement (e.g., introspecting `_logic()`'s parameter list and comparing to the descriptor) is out of v1 scope — PHP function introspection is shallow on associative-array inputs (the actual contract surface), so a static analyzer would have low signal. Smoke tests are the right tool.

### External API cost and quota for action calls

Actions that make external API calls (Mailgun for email, Stripe for charges, etc.) incur real $ cost and external rate limits. A recipe granted `send_user_email` could trigger thousands of sends in one 90-second window. The framework does not impose per-recipe limits on external API consumption — that's outside the framework's knowledge boundary. Defenses live where the action lives:

- Action authors include rate limiting in the action implementation (e.g., a daily-cap check against a counter row).
- External services typically have their own rate limits or quota safety nets at the account level.
- Recipe authors evaluate action descriptions for cost implications before granting (transitive access — same caveat as the allow-list scoping section).

Trust-the-action-grant is the consistent stance; the framework provides the audit trail (`rcr_tool_calls` records every call) so admins can investigate after the fact.

## Beyond v1: known future capabilities

Items recognized as valuable but explicitly out of v1 scope. Each has a concrete reason for deferral:

- **Dry-run mode.** Run the inference loop, record the tool calls the LLM would have made, but don't execute writes — surface the planned actions for admin review. Useful for prompt development and verifying recipe behavior before letting it mutate state. Implementation requires the executor to support a "simulate" mode and the response envelope to return synthetic results. Defer because no recipe has yet been written to demand it; the first complex recipe will likely make the case.
- **Recipe export / import.** Serialize a recipe (prompt, allow-lists, schedule, settings) to a portable format for transfer between environments (dev → prod) or sharing patterns. Defer because v1's installation model is single-environment; cross-environment workflows aren't the immediate need.
- **Cleaner actor identity threading.** Replace the SessionControl singleton-mutation pattern with explicit identity context (parameter threading on `_logic()` or a thread-local override on SessionControl). See the "Acknowledged as pragmatic, not optimal" subsection of "Session context for the actor" for details.

Other future-work items are noted inline in their respective sections (per-call hard timeout, signal-based interruption, replay infrastructure, hard path-overlap exclusion, lazy model schema, etc.). This list captures only those that are wholly new capabilities rather than escalations of existing ones.

## Open decisions before shipping (both paths)

All previously-open design decisions have been resolved through the sections above. The remaining items are implementation work, captured in "What's needed to ship."

For historical reference, two items spent time on this list:

- **Rollback.** Resolved via "Partial writes stay committed" (see API resilience). Direct-model writes are single-row, so rollback is moot; logic-file actions handle their own atomicity via existing try/catch + compensating-action patterns.
- **Audit trail.** Resolved via "Audit logging is best-effort" (see Other operational notes). Every tool call writes to `rcr_tool_calls` with start time, completion time, inputs, and output envelope; failures degrade gracefully.

## What's needed to ship

### Path 1 (direct model writes)

1. `ModelWriteExecutor` — the security boundary that runs opt-in check → field allowlist + auto-block regex + `$ai_excluded_fields` strip → `set()` → `prepare()` → `authenticate_write()` → `save()`.
2. `CreateModelTool`, `UpdateModelTool`, `DeleteModelTool` — thin wrappers over the executor that produce the response envelope.
3. `ModelRegistry` updates: scan-time validation (writable ⇒ readable; `$ai_writable_fields ∩ $ai_excluded_fields` strip + warn), `ModelRegistry::warnings()` API for the edit page UI surface.

### Path 2 (action invocations)

1. Land Step 7b (validator) and Step 7d (descriptor coverage on the 5 logic files still using `_logic_api()`) from [`FUTURE_descriptor_consumers.md`](FUTURE_descriptor_consumers.md).
2. `DescribeActionsTool`, `InvokeActionTool` — both gated by `rcp_allowed_actions`. The invoke tool coerces `LogicResult` to the response envelope (`status`, `summary`, `data`).
3. Add `mutates` to existing descriptors for actions that should be AI-callable.

### Recipe schema additions (`rcp_recipes`)

- `rcp_allow_tainted_writes bool default false` — taint-gate opt-in.
- `rcp_allowed_actions jsonb` — per-recipe action allow-list.

### Run schema additions

- `rcr_recipe_runs.rcr_kill_requested bool default false` — mid-run cancellation flag.
- `rcr_tool_calls.rct_started_time timestamp(6)` — for diagnosing timeout-causing calls.
- New `rcr_status` value: `incomplete` (iteration-budget exhaustion).

### Runtime: `RecipeRunner`

1. Pre-LLM checks at run start, in order: owner active → taint drift → allow-list staleness → session setup. All four fail-fast with specific error codes; together they cost zero tokens.
2. Wrap `rcp_workspace` in `<untrusted>` delimiters when assembling the system prompt.
3. Between-iteration check of `rcr_kill_requested`.
4. Map Anthropic API errors to the `api_*` error codes on hard failure.

### Edit-page UI

1. Owner dropdown (transfer mechanism).
2. Allowed Actions checkbox list with `mutates` and "wrapped-by" / "mutates" cross-references between models and actions.
3. "Models with configuration issues" alert (writable-without-readable + excluded ∩ writable conflicts).
4. "Stale references" notice below model and action checkbox lists.
5. `rcp_allow_tainted_writes` checkbox with the help blurb naming the trade-off.

### List-page UI

1. Owner column with `active` / `inactive` badge.
2. Distinct visual treatment for `incomplete` and `cancelled` run statuses on the latest-run column.
3. "Stop" button on in-flight runs.

### End-to-end tests

Exercise both paths against representative recipes, including: tainted-writes opt-in, owner-lifecycle drift, taint-gate run-start drift, allow-list staleness, idempotent retries after error, mid-run cancellation, iteration-budget exhaustion, and a Path 2 action returning a coerced envelope.
