# Owner-or-admin private files — align the serving gate with the ownership model

**Status:** Draft — design in progress
**Layer:** core `File` model (`data/files_class.php`)
**Depends on:** `specs/implemented/file_private_storage.md` — these files are private files
(private store + gated serving). That machinery exists; this spec adds the *who-can-view*
rule for them.
**Consumed by:** `specs/inbound_email_attachment_storage.md` — inbound attachments are
owner-or-admin private files. Likely future consumers: any per-user private file (private
uploads, generated personal documents) that should be viewable by its owner and admins.

## The problem

The platform already has an ownership rule for reaching a record: **owner OR admin**.
`SystemBase::authenticate_read()` allows a request when the session user owns the row, or
has admin permission (≥ 5); otherwise it denies. That governs API record access.

But the **file-serving** path doesn't use it. serve.php authorizes bytes with
`File::is_viewable()`, and `is_viewable()` only checks the four restriction columns
(min-permission, group, event, tier) — it has **no owner check and no admin bypass**. Its
own docblock says it is "a separate question from `authenticate_read`."

That gap means a private file can't be expressed as "owned by this user, viewable by them
and admins." `fil_min_permission` is the only lever, and it's a global *threshold*: it
can't say "this specific owner." For a permission-0 owner there's no value that works —
set it ≥ 1 and the owner is locked out of their own file; leave it unset and the file is
public to everyone. The serving gate needs the same owner-or-admin rule the record gate
already uses.

## In plain terms

Let a file be marked "private to its owner" so that the person who owns it — and admins —
can view it, and nobody else can. It's the *exact* rule the platform already applies to
opening a record; this just makes serving the file's bytes obey the same rule.

## The model

- **One marker: `fil_private` (boolean).** When set, the file is private (off the public
  bucket, onto the verified-private store) and its viewers are **owner OR admin** — the
  identical rule `authenticate_read` uses.
- **One ownership rule, shared.** Factor the owner-or-admin test into a single helper used
  by both `authenticate_read` and `is_viewable`, so the record gate and the serving gate
  can never drift apart.
- **Ownership is the gate.** `fil_usr_user_id` (the owner) is who the rule admits — a
  real, honest owner, the same field that already drives "my files" listings and
  delete-cascade.
- **Coarse by design for admins.** Any admin can view any owner-or-admin private file —
  this is the deliberate, documented limitation (see *Limitation*), not an oversight.

## What already exists (and is reused)

- **`authenticate_read()` / `authenticate_write()`** (SystemBase) — the owner-or-admin
  ownership rule. Reused verbatim as the shared helper's body.
- **`is_viewable($session)`** — the one serving gate, called by serve.php
  (`serve.php:408, 464`). Extended here; still the only entry.
- **`is_public()` is the single source of truth for placement.** `_offloaded_visibility()`
  and `_cloud_visibility()` derive from it (`data/files_class.php:326, :445`), so teaching
  `is_public()` about `fil_private` makes placement/offload/URL follow automatically.
- **The private offload profile is the complement of the public one** — excluding
  `fil_private` rows from the public partition pulls them into the private one with no
  second edit.

## What to build

### 1. The marker column

```php
'fil_private' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'false'),
```

`update_database` adds it; no migration. `fil_usr_user_id` (owner) already exists.

### 2. The shared owner-or-admin helper

Extract the rule both gates use (sketch; session- and `$data`-based callers both reach it):

```php
// allowed iff the session user owns this row, or is an admin (>= 5)
protected function is_owner_or_admin(int $user_id, int $permission): bool {
    $owner_col = static::$prefix . '_usr_user_id';
    $owner_matches = array_key_exists($owner_col, static::$field_specifications)
        && $this->get($owner_col) == $user_id;
    return $owner_matches || $permission >= 5;
}
```

`authenticate_read()` / `authenticate_write()` call it (throwing on false); `is_viewable()`
calls it (returning bool). One rule, one place.

### 3. `is_public()` — private means not public (one line)

```php
function is_public() {
    if ($this->get('fil_delete_time')) return false;
    if ($this->get('fil_private'))      return false;   // NEW
    if ($this->get('fil_min_permission')) return false;
    if ($this->get('fil_grp_group_id'))   return false;
    if ($this->get('fil_evt_event_id'))   return false;
    if ($this->get('fil_tier_min_level')) return false;
    return true;
}
```

### 4. `is_viewable()` — owner-or-admin for private files

After the delete check, short-circuit on the marker:

```php
if ($this->get('fil_private')) {
    return $this->is_owner_or_admin($session->get_user_id(), $session->get_permission());
}
// ... existing min-permission / group / event / tier checks, unchanged ...
return true;
```

Backward compatible: a file with `fil_private = false` behaves exactly as today. The
`fil_private` mechanism is an alternative to the four restriction columns, not combined
with them.

### 5. The public offload SQL excludes private rows

`FileStorageProfile::eligibilityWhere()` gains:

```php
AND (fil_private IS NULL OR fil_private = false)
```

The private profile needs no edit — it is the complement.

## What does NOT change

- **`_offloaded_visibility()`, `_cloud_visibility()`, `get_url()`,
  `move_to_correct_directory()`** — derive from `is_public()`; correct once §3 lands.
- **`FilePrivateStorageProfile`, `isEligibleRow()`** — complement / call `is_public()`.
- **serve.php** — already gates on `is_viewable()`; it now correctly serves owner-or-admin
  private files with no serve.php change.
- **The four restriction columns** — untouched.
- **Existing files** — `fil_private` defaults false → identical behavior.

## Up-front integration inventory

| Site | Change |
|---|---|
| `File::$field_specifications` | add `fil_private` |
| `is_owner_or_admin()` helper | new (shared by record + serving gates) |
| `authenticate_read` / `authenticate_write` | call the helper (behavior identical) |
| `File::is_public()` | one line — private ⇒ not public |
| `File::is_viewable()` | private ⇒ owner-or-admin via the helper |
| `FileStorageProfile::eligibilityWhere()` | exclude private rows from public partition |
| `_offloaded`/`_cloud_visibility`/`get_url`/serve.php | none (derive from `is_public()` / call `is_viewable()`) |
| `FilePrivateStorageProfile`, `isEligibleRow()` | none (complement / call `is_public()`) |

## Limitation (by design — document it)

An owner-or-admin private file is viewable by its **single owner** plus **any admin**. It
**cannot** express "this set of several specific non-admin users." A consumer that needs a
private file shared among multiple non-admin users is not served by this mechanism — that
requires per-set membership (a heavier gate this spec deliberately does not build). The
inbound-email consumer accepts exactly this: individual mailboxes work for any user
(owner match), and **shared/team mailboxes require their members to be admins** — a stated
product policy, documented as a limitation.

## Security

- **One rule, two gates.** Serving and record access authorize through the same
  owner-or-admin helper; they cannot drift.
- **No accidental public.** `is_public()` is false whenever `fil_private` is set, so a
  private file is never offloaded to the world-readable bucket.
- **Coarse admin access is explicit.** Admins viewing any private file is the documented
  trade, not a leak (see *Limitation*).

## Pre-launch / migration

No production users (`project_no_production_users`); additive boolean with a safe default.
`update_database` adds it; no data migration.

## Out of scope

- **Multi-user (non-admin) shared private files** — see *Limitation*; a later membership
  mechanism if a consumer ever needs it.
- **Reworking the four restriction modes** — untouched.
- **Per-file delegated viewer lists / ACL rows** — not built.

## Implementation outline (provisional)

1. Add `fil_private` to `File`; sync schema.
2. Extract `is_owner_or_admin()`; route `authenticate_read`/`authenticate_write` through it
   (assert behavior unchanged).
3. `is_public()` — one line. `is_viewable()` — private ⇒ owner-or-admin.
4. `FileStorageProfile::eligibilityWhere()` — exclude private rows; confirm the private
   partition picks them up (complement).
5. Bump `@version`; `php -l` + `validate_php_file.php`.
6. Tests: owner sees own private file (incl. **permission-0 owner**); non-owner non-admin
   denied; **admin sees** (the documented coarse case); private file classifies as private
   (offload partition) and yields no public URL; backward-compat — `fil_private = false`
   behaves identically to today.

## Docs

On implementation, update `docs/cloud_storage.md` (private files) and the `File`
authorization notes: `is_viewable()` is the serving gate and, for `fil_private` files,
applies the same owner-or-admin rule as `authenticate_read`; note the multi-user
limitation. Cross-reference `implemented/file_private_storage.md`.
