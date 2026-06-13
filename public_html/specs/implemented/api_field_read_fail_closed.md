# API field-read: fail-closed export (declared columns + derived allowlist)

**Status:** Implemented (2026-06-13)
**Created:** 2026-06-13

**Scope:** The shared API read boundary `SystemBase::export_for_api()`. Closes the last
fail-open layer left by `specs/implemented/api_crud_resource_authorization.md`: that spec made
**resource exposure** opt-in and **row scope** deny-by-default, but left **field read** as a
*blocklist* — `export_for_api()` emits everything `export_as_array()` produces and then strips a
known-bad set. This spec inverts it to an **allowlist** (fail-closed), matching how the AI read
surface already works, so a credential-shaped value can no longer leave the API under a name the
blocklist did not anticipate. Pre-launch: no production data and the only runtime CRUD consumer is
`tests/functional/api/` (see [[project_no_production_users]]).

---

## 1. The problem: field read is still fail-open

`export_for_api()` is a blocklist. It takes the full `export_as_array()` output and removes only the
**declared columns** that hit the unreadable floor:

```php
function export_for_api() {
    $out_array = $this->export_as_array();
    foreach (array_keys(static::$field_specifications) as $field) {   // declared columns ONLY
        if (array_key_exists($field, $out_array) && static::is_unreadable_field($field)) {
            unset($out_array[$field]);
        }
    }
    return $out_array;
}
```

Any key an `export_as_array()` override **injects** — a computed/derived key that is not in
`$field_specifications` — never passes through the floor. It is emitted verbatim.

This is not hypothetical. `User::export_as_array()` (`data/users_class.php:531-533`) injects:

```php
$user_data['user_activation_key']    = LibraryFunctions::encode($this->key, 'activation_key');
$user_data['user_activation_key_qs'] = 'uak=' . $user_data['user_activation_key'];
```

`user_activation_key` is a reversible account-activation token. It ships in **every** CRUD and
embed read of a `User`. The author hand-strips `usr_password` two lines above (line 527) but the
activation token rides along because:

- `user_activation_key` ends in `_key` and **would** have matched the credential floor
  (`CREDENTIAL_FIELD_PATTERN`) — but only declared columns are scanned, and it is derived, so the
  floor never sees it.
- `user_activation_key_qs` ends in `_qs`, so **no name-based floor can ever catch it** — it carries
  the same token under an innocent name. This is the irreducible failure mode of a blocklist: you
  cannot enumerate every name a secret value can wear.

Since CRUD `User` reads are owner-or-staff, the concrete exposure is **any staff member reading any
user via the API receives that user's activation token** (and an owner receives their own).

### 1.1 The blocklist is the original sin, one layer down

The CRUD authorization spec's own thesis (§1) was: *auto-expose with deny-by-default turned off is
the one combination nobody recommends.* It fixed that for resources (opt-in) and rows
(deny-by-default) but left **field read** as auto-emit-then-blocklist. A derived key is exposed by
**accident** unless its name happens to match a regex — the same fail-open shape, at the field
layer. Patching the blocklist to also scan emitted key *names* would catch `user_activation_key` but
still miss `user_activation_key_qs`; it treats the symptom (a name) and not the cause (the
paradigm). See [[feedback_no_bandaid_design]].

### 1.2 The AI surface already does it right

`ModelQueryExecutor` (the AI read path) never serializes an object. It runs a direct `SELECT` over
an **allowlist** of `$field_specifications` names (`resolveOutputFields()`), so a field that is not
a declared column **cannot** be returned. It is fail-closed by construction — which is exactly why
the AI surface never leaked the activation token. The two surfaces were meant to share the floor;
today they only share the regex while running **opposite paradigms** (AI allowlist, REST blocklist).
Unifying them onto the allowlist makes "what may leave over an API" one fact, not two.

---

## 2. The model: fail-closed export

`export_for_api()` emits a key only if it is **either**:

1. a **declared column** (`$field_specifications`) that survives the unreadable floor, **or**
2. a key on an explicit per-model **derived allowlist**, `$api_derived_fields`.

Everything else an override injects is dropped. A derived key is exposed only by deliberate opt-in —
never by accident — the same shape as resource exposure (`$api_readable`) and the AI read surface.

```php
// SystemBase
/**
 * Derived (computed, non-column) keys an export_as_array() override may expose over
 * the API. export_for_api() emits ONLY declared columns (minus the unreadable floor)
 * plus keys named here, so a derived key reaches the API only by explicit opt-in.
 * Fail-closed, mirroring the AI surface's $field_specifications allowlist.
 */
public static $api_derived_fields = array();

function export_for_api() {
    $full = $this->export_as_array();
    $out  = array();
    // 1. declared columns that survive the unreadable floor
    foreach (array_keys(static::$field_specifications) as $f) {
        if (array_key_exists($f, $full) && !static::is_unreadable_field($f)) {
            $out[$f] = $full[$f];
        }
    }
    // 2. explicitly-allowlisted derived keys (still subject to the floor, so a
    //    credential-named derived key cannot be allowlisted back into the open)
    foreach (static::$api_derived_fields as $f) {
        if (array_key_exists($f, $full) && !static::is_unreadable_field($f)) {
            $out[$f] = $full[$f];
        }
    }
    return $out;
}
```

Properties:

- `user_activation_key` **and** `user_activation_key_qs` vanish by construction — neither is a
  declared column nor on any allowlist. Name-agnostic: the `_qs` value-leak class is structurally
  eliminated, not name-matched.
- Every future `export_as_array()` override is fail-closed: a new computed field is invisible to the
  API until someone declares it.
- Non-API callers (admin, internal, webhooks) are untouched — they keep calling `export_as_array()`,
  which still returns the full row including all derived keys.

---

## 3. Up-front inventory — decide the derived surface once

Per [[feedback_upfront_inventory]], the derived allowlist is decided here for the whole model set,
not added reactively. Only **six** core models override `export_as_array()` and can inject derived
keys; every other exposed model already emits declared columns only and is a no-op under the
inversion. Sampled live (a real row per model, emitted keys diffed against `$field_specifications`):

| Model | Derived keys | Decision |
|---|---|---|
| `EventRegistrant` | `key` | declare |
| `Event` | `key` | declare |
| `EventSession` | `key` | declare |
| `PhoneNumber` | `key`, `phone_string` | declare |
| `Address` | `display_string`, `city_state_string`, `privacy_checked_display_string` | declare |
| `User` | `key`, `display_name`, `usr_day_since_register`, `usr_days_since_last_email`, `contact_preferences`, `phone`, `address` | declare |
| `User` | `user_activation_key`, `user_activation_key_qs` | **do NOT declare — removed (§4)** |

Notes:

- **`key`** (the `$this->key` primary-key duplicate) is a shared idiom across five of the six
  overrides. It must be declared or clients lose it — the single biggest reason a blind fail-closed
  flip *without* this inventory would have had real blast radius.
- The `phone` / `address` embeds are already exported via `export_for_api()` on the child (the §5.5
  embed rule from the CRUD spec); the inversion changes only whether the **parent** emits the
  `phone` / `address` **keys** at all, so they are on the allowlist.
- Every other derived key is a display/convenience helper with no sensitivity; declaring them is a
  no-op to client-visible behavior.

Net client-visible change of this whole spec: **the two `User` activation fields disappear; nothing
else changes.**

---

## 4. The activation-key cleanup — remove dead, leaking derivation

`user_activation_key` / `user_activation_key_qs` are not merely hidden by the inversion; they are
**dead code**. A full-tree search (PHP, JS, themes, templates, JSON) finds **zero** consumers: no
`uak` query-param handling, no `LibraryFunctions::decode(..., 'activation_key')`, no read of either
key anywhere. Account activation runs through `ActivationCode` (a separate token/table). The fields
have been emitted-but-unread since the initial commit.

So the inversion hides them from the API, and separately they should be **deleted at the source** —
remove `data/users_class.php:531-533`. The inversion is the structural guarantee (any *future*
derived secret is fail-closed regardless of author discipline); the deletion removes the dead
derivation that exists today. Both, not either: the inversion without the deletion leaves a useless
token computed on every load; the deletion without the inversion leaves the next derived secret
fail-open.

---

## 5. Implementation steps

1. **`SystemBase`** — add `$api_derived_fields` (default empty) and rewrite `export_for_api()` to the
   fail-closed allowlist (§2). `php -l`, `validate_php_file.php`.
2. **Declare derived allowlists** (§3) on the six override models:
   `EventRegistrant`, `Event`, `EventSession` → `['key']`; `PhoneNumber` → `['key','phone_string']`;
   `Address` → `['display_string','city_state_string','privacy_checked_display_string']`;
   `User` → `['key','display_name','usr_day_since_register','usr_days_since_last_email',
   'contact_preferences','phone','address']`.
3. **Remove the dead activation derivation** — delete `data/users_class.php:531-533`. Confirm the
   "zero consumers" finding still holds at implementation time (re-run the tree search).
4. **Test** — extend `tests/functional/api/crud_authorization_test.php`'s nested-embed section to
   assert the fail-closed invariant directly: `export_for_api()` emits **no** key absent from
   (`$field_specifications` ∪ `$api_derived_fields`); and specifically that `user_activation_key` /
   `_qs` are gone. Keep the existing "no credential-suffixed key anywhere" assertion — it now passes.
5. **Run** both API suites (`session_keys_test.php` stays green; `crud_authorization_test.php` goes
   fully green). `php -l` + `validate_php_file.php` on every touched file.

---

## 6. Test plan

- **Fail-closed invariant:** for each of the six override models, `export_for_api()` output keys ⊆
  (`$field_specifications` ∪ `$api_derived_fields`). A synthetic override that injects an
  undeclared `foo_smuggle` key is dropped.
- **The leak is closed:** a `User` `export_for_api()` contains neither `user_activation_key` nor
  `user_activation_key_qs`; `display_name` / `phone` / `address` / `key` are still present.
- **Declared allowlist still floored:** a derived key named on `$api_derived_fields` that *also*
  matches the credential pattern is still dropped (cannot be allowlisted back into the open).
- **Non-API path intact:** `export_as_array()` still returns the full row (admin/internal callers
  unaffected) — only `export_for_api()` is fail-closed.
- **Regression:** `session_keys_test.php` stays green; `crud_authorization_test.php`'s
  nested-embed and AI-parity sections stay green.

---

## 7. Documentation updates

Update `docs/api.md` (current-state only, written at implementation time):

- Rewrite the **Field floors** subsection so the read side is described as **fail-closed**:
  `export_for_api()` emits declared columns that pass the unreadable floor, **plus** keys a model
  explicitly opts in via `$api_derived_fields`; anything else an `export_as_array()` override injects
  is dropped. State that this mirrors the AI read surface's `$field_specifications` allowlist.
- Document `$api_derived_fields` next to `$api_unreadable_fields` / `$api_unwritable_fields` as the
  third field-level declaration: "computed keys an export override may expose over the API."
- Replace the §5.5 "an override that embeds a child model must export it via `export_for_api()`"
  guidance's *reliance on author discipline* with the enforced rule: derived/embedded keys reach the
  API only when declared; the embed-via-`export_for_api()` rule remains for nested **floor**
  correctness on the child's own columns.

---

## 8. Non-goals

- Changing `export_as_array()` semantics for non-API callers (admin, webhooks, internal) — it still
  returns the full row.
- Recursive floor-walking of nested arrays — unchanged. A child model is exported via its own
  `export_for_api()` (the CRUD spec's §5.5 rule); this spec governs which **top-level** keys the
  parent emits.
- The write floor (`$api_unwritable_fields`) and row scope — unchanged; this spec is the read-side
  field allowlist only.
- Reworking account activation — `ActivationCode` is the live mechanism; §4 only deletes a dead,
  unread derivation.

---

## 9. Rollback

Additive plus one default-body change and one deletion. The `$api_derived_fields` property and the
six per-model declarations are inert if `export_for_api()` is reverted to the declared-column
blocklist. Reverting: restore the old `export_for_api()` loop, drop the `$api_derived_fields`
declarations, and (if desired) restore `data/users_class.php:531-533`. No schema, no data.
