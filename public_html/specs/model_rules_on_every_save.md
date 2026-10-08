# Model rules on every save

**Status:** Draft, 2026-10-08. Research done (section 2), no code. Split out of
`declared_permissions` because it will break existing writes and needs its own
testing pass.

## 1. What this does

A model's own rules (no duplicate email, a valid domain name, a slug in the
right shape) live in its `prepare()` method. Today they run only when the
calling code remembers to call `prepare()` before `save()`. Most callers do not.

After this change, every `save()` runs the model's rules. A row that breaks
them is never written, whichever page, API action, job or script saved it.
`prepare()` stays callable on its own, so a form can check its input early, for
example before charging a card.

## 2. What the platform does today

Research 2026-10-08.

**Most saves skip the rules.**

- About 2,400 `save()` calls, about 290 `prepare()` calls.
- 81 page and logic files save a model and never call `prepare()`.
- `safe_load_and_set()` runs `prepare()` only when asked (`$and_prepare`).
- Four models call `prepare()` from inside their own save path
  (agent_files, inbound_email_labels, inbound_imap_folders twice).

**`save()` already checks the declared rules.** Required fields, allowed values
and `validation` rules from `$field_specifications` run inside every `save()`.
Only two kinds of rule are left outside:

- the duplicate check for columns declared `unique` or `unique_with`, which
  lives in the base `prepare()`;
- each model's custom rules in its own `prepare()`.

**Most overrides drop the base check.** 49 of the 53 `prepare()` overrides
never call `parent::prepare()`. On those models the declared-unique check never
runs, even when the caller does call `prepare()`. The database constraint still
catches the duplicate, but the user sees a raw database error.

**What the 53 overrides do.**

| Kind | Count | Examples |
|---|---|---|
| Stamp `{prefix}_update_time` only | 20 | mailbox fleet tables, shelf_runs, management_jobs, service_tenants |
| Duplicate check | 12 | users (email), settings, email_templates, coupon_codes, products (link), managed_nodes (slug) |
| Format and value rules | 12 | inbound_email_domains, inbound_email_aliases, backup_targets, recipes, inbound_email_filters |
| Clean up values | 6 | users (capitalize names), users_addrs (capitalize address), phone_numbers (drop leading 1), managed_hosts (slug) |
| Empty, commented out, or "data is NULL" (which `save()` already checks) | 9 | questions, events, event_sessions, mailing_lists, coupon_code_uses |

Several models do more than one kind, so the counts overlap.

## 3. What will break

Each item below is a change in behaviour for a save that skips `prepare()`
today.

- **R1. Old rows that fail today's rules can no longer be saved.**
  - `User::prepare()` refuses an invalid email. A user imported with a bad
    address could not sign in if sign-in saves the user row.
  - The same applies to any model whose rules were added after its rows
    existed.
- **R2. Clean-up rewrites data on unrelated saves.**
  - `User::prepare()` re-capitalizes first and last names. A name the user
    typed as "McDonald" or "van der Berg" would be rewritten by any save, such
    as a preference change.
  - users_addrs re-capitalizes addresses the same way.
- **R3. `update_time` moves on every save.** Some code reads it as a meaning,
  not just a timestamp:
  - `cvp_update_time` is the "frozen" token that ManagedSiteRequirement
    compares against (ManagedSiteRequirement.php:117). A bookkeeping save
    would invalidate a buyer's draft.
  - SweepSiteDrafts, ProvisionCustomerCloud (:272) and ProvisionHostedMail
    (:299) measure age from it. A bookkeeping save would reset the clock.
- **R4. Rules that read other rows add queries.** Duplicate checks run a
  `SELECT count(*)` per save. Bulk paths (user import, mail import, sync) pay
  it per row.
- **R5. Value rules that coerce rather than refuse can change state.**
  `InboundImapAccount::prepare()` forces sync mode to Off when CONDSTORE is not
  known. Any save of the account, including a poller's, would apply that rule.
- **R6. Rules that throw a "displayable" exception will surface where nothing
  catches them,** such as a scheduled task or an API action, and fail that
  operation outright.

## 4. Design

### 4.1 `save()` runs the rules

`save()` runs these steps, in this order, before writing:

1. Defaults and zero-on-create (unchanged).
2. Declared field rules: required, allowed values, validation (unchanged).
3. **Declared-unique check.** `save()` calls `check_unique_constraints()`
   itself, so no override can drop it.
4. **The model's `prepare()`.** It holds only this model's extra rules and no
   longer calls `parent::prepare()`.

The base `prepare()` becomes empty. `prepare()` stays public: calling it early
is allowed and harmless.

### 4.2 Clean-up and rules must be safe to run twice

`prepare()` may run more than once on the same row (an early call, then the
save). Every override must give the same result on a second run. The phone,
slug and lowercase clean-ups already do.

### 4.3 Stamps become a declaration

The 20 models whose `prepare()` only stamps `update_time` get a field option
instead:

```php
'svr_update_time' => array('type'=>'timestamp(6)', 'stamp_on_save'=>true),
```

`save()` sets a `stamp_on_save` column on every write. A column whose time
carries a meaning (R3: `cvp_update_time`) does not get the option. The code
that relies on it gets a column of its own that it sets on purpose, such as
`cvp_frozen_time`.

### 4.4 Clean-up that should run only on user input moves out of the model

Re-capitalizing a name is about how a person typed it, not a rule about the
row. `User::UcName` and `Address::UcAddress` move to the form handlers that take
the typed value. The model keeps only the rules that must hold for every row
(R2).

### 4.5 Rollout: log first

Same pattern as the GET-must-not-write check (`assert_not_get_mutation`):

1. `save()` runs `prepare()` in **log-only mode** on every save that did not
   already call it this request. A rule that would throw is logged as
   `[MODEL_RULES]` with the model, the rule's message and the stack, and the
   save goes ahead.
2. Burn down the log: fix the caller, the rule or the data.
3. Turn on refusal. The `safe_load_and_set` `$and_prepare` argument goes away.

Log-only mode cannot catch R2, R3 or R5, because those rules change values
rather than throw. They are handled before step 1 by 4.3 and 4.4, and R5 by
reviewing the coercing rules.

## 5. Work packages

**WP1: shape.**
- Declared-unique check moves into `save()`.
- Remove empty and redundant overrides (the 9 in section 2).
- Drop `parent::prepare()` calls.
- Confirm every remaining override is safe to run twice (4.2).

**WP2: stamps (4.3).**
- Add `stamp_on_save`.
- Convert the 20 stamp-only models.
- Give `cvp` its own frozen-time column and move its readers to it.
- Check the other `update_time` readers found (ssr, ilm, mjb, rcl, ifd, bkt)
  for meaning.

**WP3: clean-up out of the model (4.4).** Users, addresses. Review coercing
rules (R5): inbound_imap_accounts, inbound_email_filters,
inbound_email_domains, inbound_email_aliases.

**WP4: log-only run (4.5 step 1).** Run the full `db` test gate and a period of
normal dev use. Burn down every `[MODEL_RULES]` line.

**WP5: refuse (4.5 step 3).** Run the full `db` gate again, plus a dev walk of
sign-in, checkout, event registration, mail import and a node job.

**Tests.**
- Each model with a custom `prepare()`: a save that breaks the rule is refused
  without an explicit `prepare()` call.
- Declared-unique: an override that does not call the parent still gets the
  readable duplicate message.
- Running `prepare()` twice gives the same row.
- `stamp_on_save` sets the column on insert and update.
- A user whose name is "McDonald" keeps it through an unrelated save.

## 6. Open questions

- **Q1. Bad existing rows (R1).** When the log shows rows that fail their
  model's rules (for example users with invalid emails), fix the data with a
  migration, or loosen the rule to apply only on insert and on change of that
  field?
