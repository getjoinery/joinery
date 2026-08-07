# Deletion rule defaults: stop inferring `cascade`

## The problem in plain terms

When you permanently delete a record, the platform decides what happens to
everything pointing at it. Today, if nobody has said what should happen, it
guesses — and it guesses "delete that too."

That guess is right for records a parent *owns* (delete an order, its line items
go with it) and catastrophic for records a parent is merely *referenced by*.

The live example: a user row carries `usr_phn_phone_number_id`, pointing at their
phone number. Nobody declared what should happen to a user when their phone
number is deleted, so the inferred rule is `cascade`. **Permanently deleting a
phone number deletes the user.** And because `cascade` is a flat SQL `DELETE`,
none of that user's own 70 deletion rules run — no vault teardown, no passkey
removal, no owned-file cleanup. The row vanishes and everything that hung off it
is orphaned in place. 300 users currently carry that foreign key.

Nothing distinguishes these two cases in the schema. Both are just an integer
column with an `_id` suffix. The engine cannot tell ownership from reference, so
it should not be guessing.

## Current behaviour

`DeletionRule::registerModelRules()` walks each model's `$field_specifications`,
resolves every foreign-key-shaped column to a source table by naming convention,
and writes a rule. If the model's `$foreign_key_actions` declares nothing for
that column, the action defaults to `cascade`
(`data/deletion_rule_class.php`, "Determine action: explicit override or default
cascade").

The result on dev: 126 `cascade` rules, of which only ~32 were written by a
developer. **39 of them point at a table that has children of its own**, so the
flat delete strands those children.

## Scope of the audit

Every `cascade` rule whose target table appears as a source table in
`del_deletion_rules` needs a decision. The four possible answers:

| Answer | When |
|---|---|
| `permanent_delete` | The parent genuinely owns the target, and the target has children or a `permanent_delete()` override |
| `cascade` | The parent owns the target and the target is a true leaf |
| `null` | The target merely references the parent (the phone-number case) |
| `prevent` | The reference is load-bearing and deleting the parent should be refused |

Worked examples to calibrate against, both already corrected:

- `ord_orders` → `odi_order_items` — ownership, and order items have children.
  `permanent_delete`.
- `phn_phone_numbers` → `usr_users` — reference, not ownership. `null`.

## Proposed change

**1. Stop inferring a destructive default.** An FK column with no declaration in
`$foreign_key_actions` should register as `prevent`, not `cascade`. An
undeclared relationship then fails loudly at delete time with a message naming
the model and column, instead of quietly deleting rows nobody intended.

This is a one-line change with a long tail: every delete path that currently
relies on an inferred `cascade` starts throwing until its model declares intent.
That tail is the actual work, and it is why this is a spec rather than a patch.

**2. Make the declaration mandatory, then remove the default entirely.**
`ModelTester` already warns when `$permanent_delete_actions` is empty while FKs
exist. Extend that to `$foreign_key_actions`: every FK-shaped column must have a
declared action, checked in the `db` tier so it gates a deploy. Once every model
declares, the default has nothing left to do.

**3. Sequence it so nothing breaks mid-flight.** Land the audit first (declare
intent on all 39, plus any other undeclared FK), then flip the default. Flipping
first turns every undeclared path into a production error.

## Related defect: `$permanent_delete_actions` is dead

`SystemBase::$permanent_delete_actions` is declared on the base class, documented
in `docs/example_class.php` and `docs/deletion_system.md` with a `delete_files` /
`cascade_delete` vocabulary, validated by `ModelTester`, and emitted into every
scaffolded model by `ScaffoldGenerator`. **Nothing reads it.** The deletion
engine never consults it; `includes/SystemBase.php:45` is the only reference
outside tests, docs, and the scaffold templates.

62 models declare the property. Only one — `MailboxFleetSlot` — declares a
non-empty value, and that declaration has never done anything.

Decide one of:

- **Implement it** — give `permanent_delete()` a pass that honours
  `delete_files` and `cascade_delete`. The one live declaration starts working.
- **Delete it** — drop the property, the docs, the `ModelTester` validation, and
  the scaffold template line; fold `MailboxFleetSlot`'s intent into
  `$foreign_key_actions`, where it already has an equivalent rule.

Deleting it is the smaller change and removes a documented mechanism that misleads
anyone reading the model template. Implementing it is only worth it if the
file-cleanup half is wanted platform-wide — and models that need that today
already do it by overriding `permanent_delete()`, which is the pattern
`InboundEmailMessage` uses.

## Already fixed (2026-07-26, separate change)

These landed alongside the investigation and are not part of this spec's work:

- `permanent_delete()`'s action switch had no `default:` case, so an
  unrecognised action silently no-opped and then deleted the parent anyway. It
  now throws, and `permanent_delete_dry_run()` reports it as blocking.
- `DeletionRule::registerModelRules()` now refuses to register an unknown action
  name, returning a warning naming the model and column.
- Four live rules used invalid action names: `'delete'` on
  `abt_app_bridge_tokens` (×2) and `mfd_mailbox_fleet_domain_claims`, and
  `'restrict'` on `mft_mailbox_fleet_slots` — the last of which was meant to
  block deleting a shard that still had slots and instead permitted it.
- `usr_users.usr_phn_phone_number_id` now declares `null`, closing the
  delete-a-phone-number-deletes-the-user path.
- Four mailbox rules moved from `cascade` to `permanent_delete` where the target
  owns children (`iea` ← domain, `iem` ← domain, `iia` ← alias, `iif` ← account).
- `tests/integration/deletion_cascade_test.php` covers the unknown-action
  rejection at both registration and delete time.

## Documentation

`docs/deletion_system.md` needs updating when this lands:

- The action table gains the rule for choosing between `cascade`,
  `permanent_delete`, `null`, and `prevent` — currently it explains what each
  does but not how to pick, which is how the mailbox rules ended up wrong.
- "Default action: `cascade`" becomes the new default.
- The `$permanent_delete_actions` section is either corrected to describe a real
  mechanism or removed.
- The worked example at line 435 shows the mailbox domain→alias rule as
  `cascade`, which is the exact mistake this spec exists to prevent. Replace it.

## Implementation record (2026-08-06)

Built in full, plus the audit surfaced and fixed defects beyond the spec's scope:

- **All 218 convention-resolvable FK columns now declare an action** (100 were
  undeclared). Final registered distribution on dev: 71 cascade, 51 null,
  32 set_value, 31 permanent_delete, 28 prevent; re-registration reports zero
  warnings. Zero cascade rules point at a child-bearing target (was 39).
- **Default flipped**: an undeclared relationship registers as `prevent` with a
  message naming the model and column (`data/deletion_rule_class.php`).
- **Prefix collisions found and fixed** — six prefixes are claimed by two models
  (bkt, cnv, rcp, fil, abt, del), and three mis-resolved live rules:
  bookings' type column resolved to backup targets, AI recipe references
  resolved to relay cloud provisions, and ConversationParticipant's DECLARED
  conversation rule silently registered against content versions. The resolver
  now disambiguates by matching the entity embedded in the column name
  (bkn_bkt_booking_type_id names bkt_booking_types) and refuses to guess when
  no candidate matches; the affected declarations also carry explicit
  source_class.
- **Gates**: ModelTester fails any model with an undeclared detected FK
  (db tier); validate_php_file.php's model contract reports the same as an
  error at edit time, with its convention mirror updated to match.
- **`$permanent_delete_actions` deleted** (the spec's recommended option):
  property removed from SystemBase and all 63 declaring models, ModelTester
  validation replaced, scaffold template line dropped, docs corrected.
  MailboxFleetSlot's one non-empty declaration was already equivalent to
  MailboxFleetDomainClaim's declared cascade.
- **Declared-rule corrections**: mail import runs iea rule cascade ->
  permanent_delete (runs own entry rows); Recipe owner rule and AgentFile
  candidate self-reference cascade -> permanent_delete (both targets have
  children); event registrants/sessions rules pinned to permanent_delete in
  plugins/event_manager/tests/event_deletion_test.php.
- **A latent test-fixture leak exposed**: provisioning_setup_test registered
  its provision row for teardown under a wrong pkey name, and the old
  inferred cascade was silently deleting it via the buyer user. The new
  prevent rule refused that deletion, exposing the bug; fixed (cvp_id).
- **Verified**: safe tier 93/93; db tier 239/239 (models_crud runs the new
  gate against every model); deletion_rule_registration_test extended to pin
  the prevent default and ambiguous-prefix resolution (26 checks); rules
  re-registered on dev with zero warnings and zero orphans pruned.
