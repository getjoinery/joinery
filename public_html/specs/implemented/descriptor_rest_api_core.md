# REST API consumes descriptors — core

**Status:** Implemented 2026-07-09

**Parent spec:** [`logic_code_refactor.md`](logic_code_refactor.md) — this covers
Step 7's sub-pieces 7a (REST API descriptor switch) and 7b (boundary input
validator). Split out from the original Step 7 spec; the remaining sub-piece —
7d, authoring descriptors across the `_logic_api()` estate and retiring the
legacy companion — continues in
[`../logic_api_descriptor_migration.md`](../logic_api_descriptor_migration.md).

The AI side of descriptor consumption (`describe_actions` / `invoke_action`)
shipped earlier — see [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md).

## Problem

Descriptors (`_logic_descriptor()`) are the source of truth for an action's
shape — description, session requirement, typed input schema — but the REST
API read only the minimal legacy companion `_logic_api()` (description +
requires_session). A field added to a descriptor never reached the API's
discovery response, and nothing validated an action request's body before the
logic ran.

## What was built

One declaration drives the whole REST surface:

```php
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

### 7a — REST API descriptor switch

- **`includes/ApiLogicEndpoint.php` 1.2.0** — a shared `resolveMeta()` reads
  `{action}_logic_descriptor()` first and falls back to `{action}_logic_api()`;
  both the action face (`POST /api/v1/action/{name}`) and the form face
  (`GET /api/v1/form/{name}`) use it. When both companions exist, the
  descriptor wins.
- **`api/apiv1.php` 2.12** — action discovery (`GET /api/v1/actions`) checks
  file contents for either companion, prefers the descriptor, resolves
  `requires_session` with the same `auth`-block-aware precedence the dispatcher
  uses, and exposes each action's typed `input` schema in the response
  (`null` for legacy-only actions, so the shape change is purely additive).

### 7b — Boundary input validator

The validator was not written new: `DescriptorValidator` already existed in
the joinery_ai plugin (built for the AI write tools) with the full type
vocabulary (`string`, `int`, `float`, `bool`, `email`, `text`, `password`,
`date`, `datetime`, `array`) plus `enum`, `min`/`max`, `max_length`,
`items`/`max_items`, and `default` handling. Core code cannot depend on plugin
code, so it was **promoted to `includes/DescriptorValidator.php`** (git mv,
history preserved) and the plugin's four require sites plus two tests point at
the core copy.

Wired into `ApiLogicEndpoint::executeAction()`: when the resolved metadata
declares an `input` schema, the merged GET + body input is coerced and
validated **before** the Idempotency-Key claim and before session simulation —
an invalid request exits `422` with errortype `ValidationError` naming the
failing field, with no side effects and no idempotency key consumed. Coerced
values (typed, defaults applied) overlay the raw input; fields the schema does
not declare pass through untouched, so a partial schema can never strip input
the logic reads. The logic file's own validation remains the backstop.

## Files

| File | Change |
|---|---|
| `includes/DescriptorValidator.php` | Moved from `plugins/joinery_ai/includes/` (v1.1); header rewritten for its core role |
| `includes/ApiLogicEndpoint.php` | 1.2.0 — `resolveMeta()` descriptor-first resolution; boundary validation in `executeAction()` |
| `api/apiv1.php` | 2.12 — descriptor-first discovery; `input` schema in the response |
| `plugins/joinery_ai/{includes/ActionInvoker.php, includes/PipelineRunner.php, data/recipes_class.php, views/admin/edit.php}` | Require path updated to the core validator |
| `tests/unit/descriptor_validator_pipeline_test.php`, `tests/integration/email_security_scan_job_test.php` | Require path updated |
| `docs/api.md` | Opt-in section rewritten around descriptors; boundary-validation contract; discovery `input` field; legacy companion noted |
| `docs/plugin_developer_guide.md` | Plugin action opt-in example uses a descriptor |
| `docs/formwriter.md` | Form exposure rule names both companions |
| CLAUDE.md (via `/admin/admin_agent_files`) | API Endpoint Rules opt-in changed to `_logic_descriptor()` |

## Verification (2026-07-09, live against dev)

12/12 checks in a harness-based end-to-end run:

- `GET /api/v1/actions` lists a descriptor-bearing action (`event_withdraw`)
  with its typed schema, and a legacy-only action with `input: null`.
- Missing required fields → `422 ValidationError`.
- Wrong-typed field (`evr_event_registrant_id: "abc"`) → `422` naming the field.
- Well-typed input reaches the logic (logic-level error, not `ValidationError`).
- A legacy `_logic_api()`-only action still executes via the fallback.

`descriptor_validator_pipeline_test.php` passes 17/17 at the validator's new
path.

## Notes

- Backwards compatibility held by design: the fallback means zero behavior
  change for actions without descriptors, and discovery's `input` field is
  additive.
- Validation runs pre-idempotency deliberately: an invalid request must not
  burn the client's Idempotency-Key.
