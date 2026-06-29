# Joinery AI — chat member access (owner-scoped reads)

**Status:** Active — deferred until the product decision to open chat to non-admins
**Plugin:** `joinery_ai`
**Depends on:** the shipped chat assistant
([`joinery_ai_chat_assistant.md`](implemented/joinery_ai_chat_assistant.md))

## Goal

Let **non-admin members** use the chat assistant safely. The shipped chat is
admin-only: an admin already has full read/write access to everyone's data, so
reads run unscoped and there is nothing to contain. A member is different — a
member must not read another member's rows, and the write-confirmation boundary
can't help a read (a read has no sign-off step). So the one piece member access
needs is **owner-scoped reads**: when the caller is a non-admin member,
`query_model` returns only rows that belong to them.

This is **purely additive and member-gated** — it does not change the admin path
that shipped. Writes already contain a sub-staff member for free
(`SystemBase::authenticate_write` grants a non-staff user only their own rows), so
no write changes are needed.

In plain terms: an admin can already see everything, so the assistant lets them.
A member can only see their own things, so when a member uses the assistant it
must read through that same fence.

## Why it bolts on cheaply

Reads and writes already resolve identity through the run **context** (the
`ToolContext` interface, with `actingUserId()`). The scope decision is therefore a
property of the context, not a global "assume admin." Member read-scoping is
"implement the owner filter for a member caller," not "rethread the executors" —
the seam the chat assistant deliberately left open.

## Design

### 1. Owner-column resolver

For a **member** caller, resolve a model's owner column from a new optional model
property `$ai_owner_field`:

- **unset** ⇒ infer a single `*_usr_user_id` / `*_owner_user_id` column.
  Fail-closed: zero or 2+ candidate columns ⇒ the model is **hidden** from members
  (ambiguous ownership is never guessed).
- **a column name** (string) ⇒ use it.
- **a list** of column names ⇒ OR-match across them (e.g. `messages` =
  sender-or-recipient).
- **`false`** ⇒ ownerless catalog/config; members may read all rows.

### 2. Read owner-scope filter

`ModelQueryExecutor` appends `WHERE {owner} = actingUserId` **only when the acting
context is a non-admin member**. Admins read unscoped, exactly as today. The
acting context already knows the caller's identity and permission, so the branch
is a property check, not a new plumbing path.

### 3. Model-classification inventory

Set `$ai_owner_field` only on the models the convention **can't** resolve:
ownerless catalog (`= false`) and ambiguous multi-owner (`= 'col'` or a list). The
single-owner-column majority is inferred with no declaration. See
[Appendix A](#appendix-a--model-classification-inventory) for the full classification.

### 4. Resolved-scope report

A per-model `inferred` / `ownerless` / `hidden` readout in `validate_php_file.php`
(or a dedicated dev report) so a developer can see, at a glance, what each model
resolves to for a member caller and catch an accidentally-hidden or
accidentally-exposed model before it ships.

## What does NOT change

- The admin path: admins still read unscoped; the confirmation boundary, the risk
  heuristic, the capability toggles, and lazy discovery are all unchanged.
- Writes: `authenticate_write` already contains a member to their own rows.
- `query_model` / `ModelQueryExecutor` callers — the filter is internal to the
  executor, gated on caller permission.

## Documentation & scaffolding updates

Ship with the work, folded into existing docs as current-state (no migration
narration):

- `plugins/joinery_ai/docs/overview.md` — document owner-scoped reads for
  non-admin callers, the owner-column convention, and the resolved-scope report.
  Update the security-posture note: member reads close the
  exfiltration-of-others'-data path that the admin-only launch accepted as a
  residual.
- `docs/example_class.php` — add `$ai_owner_field` to the AI surface block with its
  states (unset = infer; column/list = name the owner; `false` = ownerless,
  members read all).
- `includes/scaffold/templates/data_class.tpl.php` — emit `$ai_owner_field` from
  the manifest (column, list, or `false`); document the key in
  `docs/scaffolding.md`.

## Testing

- **Read scoping:** as a member, `query_model` on an owner-scoped model returns
  only that user's rows; an ambiguous/unclassified model is hidden; an
  `$ai_owner_field = false` model returns catalog; an admin reads unscoped.
- **Owner-column inference:** a single-`*_usr_user_id` model resolves with no
  declaration; a two-owner model resolves to `hidden` until `$ai_owner_field`
  names the column(s); the resolved-scope report lists each model's outcome.

## Out of scope

- Opening the chat UI/permission gate to members (the access decision and any
  member-facing surface) — this spec is the read-safety prerequisite, not the
  rollout.
- Per-member write capping beyond what `authenticate_write` already does.

---

## Appendix A — Model-classification inventory

All `$ai_readable` models, classified for owner-scoping. The convention infers the
single-owner majority; only buckets B, C, D need a declaration.

- **A — Owner-scoped (single owner column):** inferred from the lone
  `*_usr_user_id` / `*_owner_user_id` column — no declaration. Reads filter
  `WHERE <col> = actingUserId`.
- **B — Ownerless catalog/config:** `$ai_owner_field = false`. Members read all
  rows; not user-owned data.
- **C — Complex ownership (dual-user / polymorphic / join):** doesn't fit a flat
  column. Dual-user cases take `$ai_owner_field = [...]` (OR-match);
  polymorphic / join cases stay hidden until a richer scope form lands.
- **D — Admin-only / excluded:** sensitive or pure admin config; never
  owner-scoped to a member.

### A — Owner-scoped (21) → inferred owner column

`address` (`usa_usr_user_id`), `comments` (`cmt_usr_user_id`),
`conversation_participants` (`cnp_usr_user_id`), `event_registrants`
(`evr_usr_user_id`), `files` (`fil_usr_user_id`), `mailing_list_registrants`
(`mlr_usr_user_id`), `notifications` (`ntf_usr_user_id`), `order_items`
(`odi_usr_user_id`), `orders` (`ord_usr_user_id`), `phone_number` (`phn_usr_user_id`),
`posts` (`pst_usr_user_id`), `product_details` (`prd_usr_user_id`), `reactions`
(`rct_usr_user_id`), `survey_answers` (`sva_usr_user_id`), `videos` (`vid_usr_user_id`),
`items` (`itm_usr_user_id`), `item_relations` (`itr_usr_user_id`), `devices`
(`sdd_usr_user_id`), `recipe_notes` (`rcn_owner_user_id`), `recipes`
(`rcp_owner_user_id`), `users` (`usr_user_id` — the pk itself).

### B — Ownerless catalog/config (19) → `$ai_owner_field = false`

`pages`, `page_contents`, `products`, `product_groups`, `product_requirements`,
`product_requirement_instances`, `events`, `event_types`, `event_sessions`,
`event_session_files`, `locations`, `mailing_lists`, `subscription_tiers`, `questions`,
`question_options`, `surveys`, `survey_questions`, `seo_page_metadata`,
`item_relation_types`. (No per-user ownership — catalog, configuration, or public
content.)

### C — Complex ownership (8) — deferred within this spec

| Model | Why it doesn't fit a flat column | Extended scope needed |
|---|---|---|
| `messages` | dual: `msg_usr_user_id_sender` **OR** `msg_usr_user_id_recipient` | OR-of-columns |
| `bookings` | dual: `bkn_usr_user_id_booked` **OR** `bkn_usr_user_id_client` | OR-of-columns |
| `calendar_entry` | polymorphic subject (`cal_subject_type`/`cal_subject_id`) | subject = ('user', me) |
| `schedule` | polymorphic subject (`sch_subject_type`/`sch_subject_id`) | subject = ('user', me) |
| `entity_photos` | polymorphic entity (`eph_entity_type`/`eph_entity_id`) | entity = ('user', me) |
| `conversations` | owned via `conversation_participants` join | join scope |
| `groups` | membership via `group_members`; only a creator col on the row | join scope |
| `group_members` | polymorphic member (`grm_foreign_key_id` + `grm_grp_group_id`) | member = me |

The trivial **OR-of-columns** form unlocks `messages` and `bookings` (the two
highest-value conversational targets) alongside bucket A; the polymorphic / join
cases follow with a richer scope declaration.

### D — Admin-only / never owner-scoped (2)

`agent_files` (system-internal agent instructions — sensitive), `coupon_codes`
(affiliate/marketing config — admin surface).
