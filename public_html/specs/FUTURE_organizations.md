# Specification: Organizations (FUTURE)

## Status

**Future work.** This spec captures the design and the audit findings from a scoping discussion. It is **not** part of the multi-domain (branding) launch. Land when there is concrete demand — the first paying customer who needs "one payer, multiple users."

## Overview

Joinery is user-centric: every record of consequence is owned by a `usr_users` row. This spec adds **organizations** as a *core platform feature*, not a plugin feature, while leaving the user-centric model intact. An organization is a thin layer over users: a billing umbrella plus a member roster, brand-scoped, with no replacement of user-keyed ownership anywhere.

**Guiding principle:** orgs are an additive, opt-in feature. A user who never joins an org sees nothing different. The vast majority of the codebase — every plugin, every existing admin page, every permission check, every device/block/rule — does not change.

## Why this is core, not plugin

If orgs lived in a plugin (e.g., bundled with ScrollDaddy or NetworkSentry), billing and email integration would have to backflow into that plugin, future brands couldn't reuse them, and every new plugin would have to redo the wiring. Orgs are an identity/ownership construct on the same conceptual level as users — they belong as a peer of `usr_users` and `brd_brands` in the core.

## Model

An org is a **billing umbrella + member roster**. That's it.

- **Billing umbrella:** one Stripe customer per org; an org's active subscription covers the tier benefits for all its members.
- **Member roster:** a list of users associated with the org, with a role per membership.
- **Brand-scoped:** every org belongs to exactly one brand. NetworkSentry orgs and ScrollDaddy orgs are separate populations.
- **Opt-in:** users are not auto-enrolled into orgs. A user with no org memberships behaves exactly as today.

Orgs deliberately do **not** own product data. Devices stay user-owned. Blocks stay user-owned. Permissions stay user-keyed. The ScrollDaddy plugin (and any future plugin) never learns the word "organization."

### What an org buys you

The "20 users in a business" use case becomes: 20 individual user accounts, each managing their own devices, all covered by one org subscription that the org's billing contact pays. Each member sees their own dashboard exactly as a solo user would. The boss does *not* get to see or manage Alice's devices through this layer alone — that capability ("delegated management") is out of scope here and would be a separate plugin-level feature added later if a customer demanded it.

## Data model

### New tables

**`org_organizations`** — the org record
- `org_org_id` — int4 serial PK
- `org_name` — varchar(128)
- `org_brd_brand_id` — int4 (the brand the org belongs to)
- `org_type` — varchar(16) — `personal` | `team` (reserved for future personal-org pattern; initially all rows are `team`)
- `org_stripe_customer_id` — varchar(32) (live)
- `org_stripe_customer_id_test` — varchar(32) (test)
- `org_status` — varchar(16) — `active` | `inactive` | `archived`
- `org_created_time`, `org_modified_time`, `org_delete_time`

**`omb_org_memberships`** — many-to-many between users and orgs
- `omb_omb_id` — int4 serial PK
- `omb_org_org_id` — int4 (FK to `org_organizations`)
- `omb_usr_user_id` — int4 (FK to `usr_users`)
- `omb_role` — varchar(32) — `owner` | `admin` | `billing_contact` | `member` (final role list TBD)
- `omb_invited_by_usr_user_id` — int4, nullable
- `omb_status` — varchar(16) — `pending` | `active` | `revoked`
- `omb_joined_time`, `omb_created_time`, `omb_modified_time`, `omb_delete_time`
- Composite unique on (`omb_org_org_id`, `omb_usr_user_id`)

### Modifications to existing tables

None *required*. The integration points below (Stripe lookup helpers, tier resolution, billing email routing) work without altering existing schema. If a future need arises to record "this order/subscription was paid by an org," nullable `*_org_org_id` columns can be added at that time — also additive.

## Integration points (the things that "absolutely have to" change)

These are the only places core platform code is forced to change. Everything else stays as-is.

### 1. Billing identity becomes user-or-org

Today `usr_users` carries `usr_stripe_customer_id` (and `_test`). The Stripe customer is implicitly the user.

With orgs, the Stripe customer is on the user *for individual plans* (today's behavior, unchanged) **or** on the org *for team plans*. The helpers that create and look up Stripe customers gain a polymorphic owner argument. Webhook handlers that resolve a Stripe customer back to an internal entity try user lookup first, then org lookup.

Concretely:
- `User::GetByStripeCustomerId($id)` (`data/users_class.php:565`) stays as-is.
- New `Org::GetByStripeCustomerId($id)` lives alongside it.
- A new resolver helper `BillingPayer::resolve_from_stripe_customer($id)` returns either a `User` or an `Org`. Webhook code calls the resolver instead of the bare user lookup.

### 2. Tier resolution gains an org check

Today `SubscriptionTier::GetUserTier($user_id)` (`data/subscription_tiers_class.php:135`) walks user → groups → tier. Tomorrow it also walks user → org memberships → org subscriptions → tier. Returns the best tier across both.

The cleanest implementation rides on the existing **group-based tier assignment**: when an org subscribes to a tier, the org's active members are added to the tier group via `MultiGroupMember`. When membership changes (someone joins or leaves), the handler syncs the group accordingly. This means `GetUserTier()` itself barely changes — its existing group walk already finds tier groups regardless of how the user got into them.

The audit confirms this works against today's `grm_group_members` schema with no schema change.

### 3. Billing email recipients route through the payer

Today billing emails (receipts, dunning, plan-change notifications) are sent to "the user who placed the order." With orgs, those emails go to the org's billing contact when the subscription is org-keyed.

Implementation: a single helper `BillingEmail::get_recipient_for($order_or_subscription)` that returns the right user(s) — the order user for individual plans, the org's billing contact(s) for team plans. Email-generation sites call this helper instead of looking at the order's user directly.

Lifecycle emails (welcome, password reset, account verification) stay user-keyed. They're not billing-related.

### 4. Membership UI

- New `/profile/organizations` view: lists orgs the user belongs to, shows role, links to org detail (members, billing).
- New `/adm/admin_organizations` page: cross-brand CRUD over `org_organizations`, with brand filter.
- Invitation flow: owner sends invite email → invitee clicks link → signs in or signs up (still email-unique globally) → joins org with `pending` → `active` transition.

### 5. Brand scoping

Orgs are brand-scoped. An org belongs to one brand; cross-brand orgs are not supported. The `BrandContext` filter on `omb_org_memberships` and `org_organizations` follows the same `$brand_scoped = true` flag mechanism used elsewhere (defined in the multi-domain spec).

## What does NOT change

To be explicit, the following are deliberately untouched by this spec:

- Device ownership. `sdd_devices.sdd_usr_user_id` stays as the sole owner column.
- Block, rule, and any other ScrollDaddy data ownership. All user-keyed.
- The ScrollDaddy plugin code and any future plugin code. Plugins do not learn about orgs.
- Existing `/profile/*` and `/adm/*` pages. They keep showing user-owned data as today.
- The `usr_permission_level` system. Per-org roles layer alongside it; they do not replace it.
- `User::is_owner()` checks on individual records. They keep checking the record's user column.
- The `usr_users` table itself. No new columns required.
- Order and order-item schemas. They stay user-keyed.

This is the central design constraint: **orgs add a layer; they do not refactor the existing layer.**

## Architecture audit findings

A scoping audit confirmed there are **no current architecture blockers** to adding orgs later:

| Area | Today | Org-later cost |
|---|---|---|
| Stripe customer storage | On `usr_users` | Add equivalent columns on `org_organizations`. Webhook lookup adds an org branch. Additive. |
| Tier resolution | `GetUserTier($user_id)` walks user → groups | Add org-membership walk; or (preferred) keep `GetUserTier` as-is and have org-subscription handlers add members to tier groups. Additive. |
| Tier assignment via groups | `MultiGroupMember` keyed on user | Org subscription handler adds each org member to the tier group. No schema change. |
| Orders / order_items | Keyed to `*_usr_user_id` | Optionally add nullable `*_org_org_id` later if "this purchase was org-paid" needs to be recorded. Additive. |
| Plugin data (devices, blocks) | All `*_usr_user_id` | Untouched by this spec. |
| Permission system | Global level + per-record `is_owner` user check | Add org-role gating in a layer above. Existing checks untouched. |
| Email recipients | Implicit "owning user gets it" | Route billing-email lookup through `BillingEmail::get_recipient_for()`. Contained change. |

Two minor cleanups to existing helpers would make org-later marginally cheaper but are not required:

1. Route Stripe customer lookup through a `BillingPayer` resolver helper instead of bare `User::GetByStripeCustomerId()` calls.
2. Route billing-email recipient lookup through `BillingEmail::get_recipient_for()` helper instead of inline `$user->get('usr_email')` at email sites.

Both are local refactors; they can be done at any time, including as the first step of this spec when it's picked up.

## Open product question

In this user-centric, billing-umbrella-only model, "org admin manages 20 employees' devices" is not supported. Two distinct use cases need to be distinguished before implementing:

- **"One IT admin, many devices"** — the IT person logs in to one account that owns all 20 office devices directly. Already supported today, no org needed.
- **"Many users, one payer"** — each employee has their own login and their own dashboard; the org subscription covers them all; the boss never sees Alice's specific blocks. Requires this spec.

If the actual sales need is "office IT person sees and manages everyone's stuff from one dashboard," that is a *third* case — **delegated management** — which is a separate future feature on top of this spec, not part of it.

## Phases

### Phase 0 — Data model
- [ ] Create `org_organizations` data class
- [ ] Create `omb_org_memberships` data class
- [ ] `update_database` to materialize tables
- [ ] Brand-scope flag on both classes (per the multi-domain spec mechanism)

### Phase 1 — Billing payer abstraction
- [ ] `BillingPayer::resolve_from_stripe_customer()` helper
- [ ] Route existing webhook user-lookup through the resolver
- [ ] Add `Org::GetByStripeCustomerId()` for the org branch
- [ ] Stripe customer creation accepts an org owner

### Phase 2 — Org subscription → tier group sync
- [ ] When an org subscribes to a tier, add active members to the tier group
- [ ] When an org member is added/removed, sync tier group membership
- [ ] When org subscription expires/cancels, remove all members from the tier group
- [ ] Verify `GetUserTier()` behavior is unchanged for solo users

### Phase 3 — Billing email routing
- [ ] `BillingEmail::get_recipient_for()` helper returning user(s) for individual plans, org billing contact(s) for org plans
- [ ] Migrate billing email sites to use the helper
- [ ] Verify lifecycle emails (welcome, password reset) still go directly to the user

### Phase 4 — UI
- [ ] `/profile/organizations` view
- [ ] Org detail page (members, billing)
- [ ] `/adm/admin_organizations` CRUD
- [ ] Invitation flow (email link, accept/decline, role assignment)

### Phase 5 — Hardening
- [ ] Cross-brand org isolation tests
- [ ] Permission tests for org-role gating on org-management actions
- [ ] Stripe webhook tests for both user-paid and org-paid subscriptions

## Out of scope

- **Personal-org-by-default pattern.** Considered and rejected. The user-centric model means orgs are opt-in; no personal org is auto-created. The `org_type = 'personal'` value is reserved for a future migration if this decision is ever revisited, but no code reads it.
- **Delegated management.** "Org admin can see and edit a member's devices" is not part of this spec. Devices remain user-owned and user-managed. Add as a separate feature when a customer needs it.
- **Org-level data ownership.** No plugin data is migrated to be org-owned. Future plugins may opt into org-owned data; the ScrollDaddy plugin and any other current plugin do not.
- **Cross-brand orgs.** An org belongs to exactly one brand. A user can be in orgs across multiple brands; a single org cannot.
- **Hierarchical orgs / sub-orgs.** Single-level membership only.
- **Audit log of who-did-what within an org.** Useful eventually; not in this spec.
- **SSO / SCIM provisioning.** Enterprise feature; separate spec when there's a real enterprise sales pipeline.
- **Per-org email templates and per-org sender domains.** Email branding is at the *brand* level (multi-domain spec), not the org level. Two orgs on the same brand share the brand's email identity.

## Documentation updates

- New `docs/organizations.md` covering: opt-in model, billing-umbrella semantics, integration with brand context, group-based tier sync, the firm "no plugin data is org-owned" line.
- Update `docs/subscription_tiers.md` with org subscriptions and the tier-group sync pattern.
- Update `docs/email_system.md` with billing recipient routing.
