# API action feature gate — `requires_setting` on the descriptor

**Status: BUILT 2026-08-13. Split out of `specs/core_api_simplification.md`
(work item 1, second bullet), which is deferred; this carried the part that
fixed a live defect and nothing else.**

**Filed with one gate open, deliberately.** Everything below is built, and
`tests/unit/api_action_feature_gate_test.php` (26 checks, negative-control
proven) plus a green db tier at 272/272 cover it — but every assertion is
either the guard driven directly with a stubbed `api_error()` or a source-level
check over the wiring. No real API request has been made with `drive_active`
off, because dev runs Drive on and switching it off is a settings write on a
live box. The live gates are queued in the shared verification queue; the
discovery filter and the with-Drive-on regression are the two that matter most.

## The defect

Turning a feature off does not turn its API actions off.

`serve.php` gates feature pages with `check_setting` — `/profile/devices/link`
and `/s/{token}` both carry `'check_setting' => 'drive_active'` (lines 177 and
106). The API action face never goes through `serve.php`. It resolves an action
straight from its logic file, so the only thing standing between a switched-off
feature and a working API call is whether that action's author remembered to
re-check the setting by hand.

Twenty-three of the thirty Drive actions remembered. Seven did not:

| Action | What it still does with Drive off |
|---|---|
| `drive_device_link_approve` | Links a new computer and mints its credential |
| `drive_device_link_deny` | Refuses a pending link |
| `drive_device_link_info` | Describes the machine requesting a link |
| `drive_device_rename` | Renames a linked device |
| `drive_device_revoke` | Unlinks a device and revokes its key |
| `drive_devices` | Lists the caller's linked computers |
| `drive_vault_status` | Reports vault existence, public key, key generation |

All seven carry a descriptor, so all seven are live on
`POST /api/v1/action/{name}`. All seven require a session and enforce their own
per-user ownership checks, so this is **not** a privilege bypass — no caller
reaches another user's devices. It is a feature the operator switched off that
still answers, including completing the device-link ceremony that grants a
machine standing access to files.

Separately, `GET /api/v1/actions` advertises all thirty Drive actions on an
instance with Drive off, because discovery reads descriptors and descriptors
have never carried the fact.

## The fix, at the layer that caused it

The hand-written body guard is not the bug; the absence of any way to *declare*
the requirement is. A descriptor already declares that an action needs a
session and what its inputs are, and both faces enforce that. It gains one more
declaration.

**1. New descriptor key.** `'requires_setting' => 'drive_active'` — the name of
a setting that must be truthy for the action to exist. One setting name, not a
list; nothing needs a list today.

**2. Enforced at the dispatch chokepoints.**
`ApiLogicEndpoint::resolveAction()` and `::resolveForm()` are the two places
every API request resolves a descriptor, before authentication and before the
logic function runs. The check goes there, so it cannot be reached around.

Refusal is **403** with errortype `ActionError` and a message naming the
setting. Not 404 — the action does exist, and telling a developer it is unknown
sends them hunting a typo that is not there. Not 422 — nothing is wrong with
their input.

**3. Discovery honors it.** `GET /api/v1/actions` omits an action whose
required setting is off, the same way it already omits actions belonging to
inactive plugins.

**4. Declared on all thirty Drive actions**, not only the seven, so discovery
is correct for the whole family.

## Deliberate non-changes

- **The page face is untouched.** `process_logic()` gains nothing here.
  serve.php's `check_setting` already gates the pages correctly; making the
  page face descriptor-aware is the deferred spec's work item 1, and pulling it
  in would mean tightening 208 page paths to fix an API hole.
- **The twenty-three existing body guards stay.** They are correct, they now
  sit behind an earlier check that makes them unreachable, and deleting
  twenty-three live guards to save twenty-three lines is a worse trade than
  leaving them. They come out opportunistically when those files are next
  touched.
- **`settingActivate` in `plugin.json` and `check_setting` in `serve.php` are
  not unified with this key.** That consolidation is the deferred spec's; this
  key coexists with them.

## Behavior change to note

The twenty-three already-guarded actions currently answer a Drive-off call with
**422** and `"Drive is not enabled."` (their body's `LogicResult::error()`).
Once they declare `requires_setting`, they answer **403** at dispatch instead,
and the body guard never runs. This is the more correct status of the two, and
the instance has no production users, but it is a wire-visible change and is
called out here rather than discovered later. Native sync clients treat any
non-2xx as a failed call and surface the message, so no client change is
needed.

## Verification

- Unit coverage for the new key: an action declaring a setting that is off is
  refused at dispatch; the same action with the setting on runs; discovery
  omits the former and lists the latter.
- With `drive_active` off, each of the seven named actions returns 403 rather
  than executing — asserted per action, since the point of the spec is that
  hand-maintained coverage is what failed.
- `GET /api/v1/actions` lists zero `drive_*` actions with Drive off, all thirty
  with it on.
- Full db tier green before commit.

## Follow-on, not in scope

Thirteen other settings gate features the same way (`products_active`,
`events_active`, `subscriptions_active`, `register_active`, and ten more, by
body-guard count). Whether their API-exposed actions have the same gap is not
mechanically derivable — those features have no filename prefix to audit by,
and deciding which feature owns a given action takes per-action judgment. The
audit is real work and belongs in its own pass; this spec proves the mechanism
and fixes the family where the gap is demonstrated.
