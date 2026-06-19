# Inbound Email — Filters (Gmail-parity rules)

## Summary

Add **filters**: operator-defined rules that match incoming mail and apply actions to
it automatically — the inbound-email equivalent of Gmail's *Settings → Filters and
Blocked Addresses → Create a filter*. A filter has two parts, exactly as Gmail splits
them:

1. **Criteria** — From, To, Subject, *Has the words*, *Doesn't have*, Size
   (greater/less than, with a unit), and *Has attachment*.
2. **Actions** — apply a label, star, mark read, skip the inbox (archive), mark as
   spam, never send to spam, forward to an address, delete.

Filters run **at ingest**, once per delivered message, before the message lands in the
reader. They reuse the disposition primitives the reader already exposes (label
membership, star, read flag, spam verdict, soft-delete) and the relay path that
alias-level forwarding already uses — this spec adds the **rule logic and its
management UI**, not new ways to mutate a message.

This promotes the *"server-side filters/rules (a Sieve-equivalent for store/forward
mailboxes)"* item deferred in
`specs/inbound_email_shared_inbox_parity.md` §6 into its own feature.

## Scope: locally-received mail only

Filters apply to mail this platform **receives as the mail server** — the Postfix
(milter) path and the provider-webhook path (Mailgun/SendGrid/SES). They do **not**
apply to IMAP-polled feeds (`PollImapAccounts`), and that is deliberate, decided up
front:

- An IMAP feed mirrors an upstream account (e.g. a Gmail inbox) that already runs its
  **own** server-side filters before we ever see the message. Re-filtering it locally
  would duplicate or fight that.
- The reader's two-way IMAP sync (`specs/implemented/two_way_imap_sync.md`) treats the
  remote as the source of truth for read/label/folder state. A local filter mutating
  those flags would generate spurious upstream writes.

This matches the parity roadmap's own framing ("a Sieve-equivalent for **store/forward
mailboxes**"). Local labels still exist for IMAP mailboxes via the reader, but the
*filter engine* only fires on locally-received deliveries.

## Goals

- Reproduce Gmail's filter **criteria** and **actions** faithfully, minus product
  concepts we don't have (importance, categories/tabs, chats — see the inventory).
- Run filters at ingest across **every locally-received path** (Postfix milter +
  provider webhooks) from **one** hook, so behavior can't drift between paths.
- **Reuse existing disposition primitives.** A filter that "applies a label" calls the
  same membership write the reader's Labels control does; "mark as spam" writes the
  same `iem_spam_verdict`; "forward" uses the same relay. No parallel mutation paths.
- Manage filters from a **Filters** admin tab whose create/edit flow mirrors Gmail's
  two-step (criteria → actions) dialog, built with FormWriter (no hand-rolled forms).
- Support Gmail's *"Also apply filter to matching existing conversations"* as a bounded
  background backfill.

## Non-Goals

- **No IMAP-feed filtering** (see Scope, above).
- **No Gmail search-operator language.** "Has the words" / "Doesn't have" match against
  the same field set as the existing full-text search (sender + subject + plain + HTML
  body) using `websearch_to_tsquery`, which already tolerates arbitrary operator-ish
  input. We do not implement Gmail's `from:`/`label:`/`OR`/parenthesis mini-DSL.
- **No importance, categories, or chats.** We have no "important" signal, no
  Primary/Social/Promotions tabs, and no chat stream, so the matching *Don't include
  chats* checkbox and the *Mark/Never mark as important* and *Categorize as* actions are
  omitted (recorded in the action inventory so the omission is a decision, not a gap).
- **No forwarding-address verification handshake.** Gmail makes you verify a forward
  target by email confirmation. Filters here are operator-only (permission ≥ 5) and
  reuse the platform relay; the verification handshake is out of scope.
- **No SMTP-time action.** Like the spam specs, filters act on the **stored** message
  (move/label/flag/relay/soft-delete), never reject or bounce at RCPT.
- **No per-end-user filters.** Filters are operator-managed against a mailbox, not a
  self-service per-recipient feature.

## Gmail action inventory (decided up front)

Every action Gmail offers in the filter "actions" step, and how each maps here. This
is the up-front inventory so the set is decided once, not grown incrementally.

| Gmail action | Decision | Backing primitive |
|---|---|---|
| Apply the label… | **Build** | `InboundMessageFolder::setPresence($id, $folderId, true, false)` — same write as the reader's Labels control; label auto-created if new |
| Star it | **Build** | `iem_is_starred = true` (same field as `setStarred`) |
| Mark as read | **Build** | `iem_is_read = true`, stamp `iem_read_time` (same as `markRead`) |
| Skip the Inbox (Archive it) | **Build** (one net-new flag) | new `iem_is_archived` boolean + a reader Inbox/All-Mail split (see Design → Archive) |
| Mark it as spam | **Build** | `iem_spam_verdict = 'spam'` (same as `setSpamVerdict`) |
| Never send it to Spam | **Build** | clears any computed verdict to `'ham'`, and suppresses the classifier for this message |
| Forward it to… | **Build** | the relay path `forwardEmail()`/`relay()` already used by alias forwarding |
| Delete it | **Build** | `iem_delete_time = now()` (same as `softDelete`) |
| Mark as important / Never mark important | **Omit** | no importance signal in the product |
| Categorize as (Primary/Social/…) | **Omit** | no category tabs in the product |
| (criteria) Don't include chats | **Omit** | no chat stream to exclude |

## Gmail criteria inventory

| Gmail field | Stored as | Match semantics (at ingest, in PHP) |
|---|---|---|
| From | `fil_match_from` | case-insensitive substring on `iem_sender`; comma-separated terms are OR'd (Gmail behavior) |
| To | `fil_match_to` | case-insensitive substring on `iem_recipient`; comma-separated OR |
| Subject | `fil_match_subject` | case-insensitive substring on `iem_subject` |
| Has the words | `fil_match_has_words` | tokenized contains over sender+subject+plain+HTML (the full-text field set); all tokens must be present |
| Doesn't have | `fil_match_excludes` | same field set; message must contain **none** of the tokens |
| Size `>` / `<` value + unit | `fil_match_size_op` (`gt`/`lt`), `fil_match_size_bytes` | compare `iem_size_bytes`; UI unit (Bytes/KB/MB) is normalized to bytes on save |
| Has attachment | `fil_match_has_attachment` (bool) | true iff the message has ≥ 1 `ima_inbound_message_attachment` row |

Empty criteria fields are ignored. A filter **matches** when **all** non-empty
criteria match (AND across fields — Gmail's model). At least one criterion is required
(reject an all-empty filter at save).

## Design

### Data model — `fil_inbound_email_filters`

New data class `plugins/inbound_email/data/inbound_email_filter_class.php`
(`InboundEmailFilter` + `MultiInboundEmailFilter`), prefix `fil`, following the
plugin's data-class conventions (`$prefix`/`$tablename`/`$pkey_column`/
`$field_specifications`, `authenticate_write` gating permission ≥ 5, a Multi loader).
Schema is auto-synced from `$field_specifications` (no migration).

```
fil_inbound_email_filter_id     int8 serial  PK
fil_iea_inbound_email_alias_id  int8         the mailbox this filter belongs to;
                                             NULL = applies to every alias in the domain
fil_ied_inbound_email_domain_id int4         domain scope (for the NULL-alias case);
                                             FK cascade with the domain
fil_name                        varchar(255) operator label (defaults to a summary of criteria)
fil_is_enabled                  bool default true
fil_order                       int4         evaluation order within a mailbox

-- criteria
fil_match_from                  varchar(500)
fil_match_to                    varchar(500)
fil_match_subject               varchar(1000)
fil_match_has_words             text
fil_match_excludes              text
fil_match_size_op               varchar(2)   'gt' | 'lt' | NULL
fil_match_size_bytes            int8
fil_match_has_attachment        bool default false

-- actions
fil_action_label_id             int8         FK -> iif_inbound_imap_folder_id (the label to apply)
fil_action_star                 bool default false
fil_action_mark_read            bool default false
fil_action_archive              bool default false
fil_action_mark_spam            bool default false
fil_action_never_spam           bool default false
fil_action_forward_to           varchar(500)
fil_action_delete               bool default false

-- backfill bookkeeping (see Apply-to-existing)
fil_apply_existing_pending      bool default false

fil_create_time                 timestamp(6) default now()
fil_update_time                 timestamp(6)
fil_delete_time                 timestamp(6) soft delete
```

**Scope model.** A filter belongs to a **mailbox** (`fil_iea_inbound_email_alias_id`),
mirroring Gmail's per-account filters. A NULL alias means *domain-wide* — it runs for
every alias under `fil_ied_inbound_email_domain_id` (useful for org-wide rules like
"label anything from `@vendor.com`"). The management UI picks a mailbox or "All
mailboxes in <domain>".

**Label FK.** `fil_action_label_id` references an existing `iif_inbound_imap_folder`
(label/folder). Labels are created through the existing `createFolder` mechanism — the
action UI offers a "Create new label…" affordance that creates the `iif_` row, then
stores its id. For a NULL-alias (domain-wide) filter the label must belong to a real
alias; the UI requires picking the mailbox whose label is applied (open decision below).

### Behavior on the model (no separate engine class)

The matching and action logic lives on **`InboundEmailFilter` itself** — Active Record:
a filter owns its criteria and actions, so it judges and acts on a message. The feature
adds **one** model class (plus the mandatory `MultiInboundEmailFilter` loader that every
data class carries by convention); there is no separate engine class.

- `matches(InboundEmailMessage $msg, array $parsed): bool` — instance method; the single
  source of truth for criteria evaluation, pure and unit-testable.
- `applyActions(InboundEmailMessage $msg, array $parsed): array` — instance method;
  applies *this* filter's actions, returning what it did.
- `InboundEmailFilter::runForMessage(InboundEmailMessage $msg, array $parsed, InboundEmailAlias $alias): array`
  — **static orchestrator**. Loads enabled in-scope filters (alias-specific **and**
  domain-wide for the alias's domain) via `MultiInboundEmailFilter`, ordered by
  `fil_order, fil_inbound_email_filter_id`; runs `matches()` on each; **accumulates** the
  actions of all matching filters; applies them once in the fixed order below. Returns a
  summary (`['matched' => [...filter ids], 'actions' => [...]]`) for the transaction log.

The fixed-order accumulation is a collection-level concern — which is exactly why it's
the static method over the loaded set, not per-filter.

This runs with **system authority** (ingest-time, not a logged-in viewer), so it writes
through the **model layer** (`InboundEmailMessage` flags, `InboundMessageFolder::setPresence`,
the relay) rather than the viewer-scoped `MailboxService`, whose grant checks are
meaningless and unavailable at ingest. The primitives are identical; only the
authorization wrapper differs.

#### Action application order (within a matched message)

`runForMessage` accumulates all matching filters' actions, then applies them in this
fixed order so interactions are well-defined regardless of filter order:

1. **never_spam** (clear verdict → `'ham'`, mark classifier-suppressed) — runs before
   any spam action so an explicit allow always wins.
2. **mark_spam** (only if no never_spam fired).
3. **label**, **star**, **mark_read**, **archive** — independent flag/membership writes.
4. **forward_to** — relay a copy via `relay()`; failures are logged, never fatal to
   ingest (mirrors alias-forward error handling).
5. **delete** (soft) — last, so a logged/forwarded copy still happened (Gmail's
   delete-after-forward behavior).

`never_spam` beating `mark_spam` is the one cross-filter precedence rule, and it
matches Gmail (an explicit "never spam" filter overrides classification).

### Ingest hook (one place, all local paths)

`InboundEmailRouter::storeMessage()` returns the persisted message. Its callers —
`processEmail()` (Postfix + webhook) and `handleStoreOnly()` — call
`InboundEmailFilter::runForMessage(...)` immediately after the row + raw/manifest are
persisted and the spam verdict is set, before the call returns. Because both store-paths
funnel through `storeMessage`, that call sits at the **single** post-persist point there,
so Postfix and webhook deliveries are filtered identically with no per-path branch.

Ordering vs. the existing pipeline:

- The spam **verdict** is computed inside `storeMessage` (auth + content). The engine
  runs **after**, so `never_spam` can clear it and `mark_spam` can set it — the filter
  is the last word on disposition, as in Gmail.
- **Alias-level forwarding** (the alias's `MODE_FORWARD*`) is unchanged and independent
  of a filter's `forward_to`; a message can be alias-forwarded *and* filter-forwarded.
- A filter **delete** soft-deletes the just-stored local copy; it does not unsend an
  alias-forward that already fired (same as Gmail).

### Archive ("Skip the Inbox")

The reader currently defaults to **All Mail**. "Skip the Inbox" needs an Inbox/Archive
distinction, so this spec adds the **one** net-new model field, `iem_is_archived`
(bool, default false), and a small reader change:

- The mailbox's default list becomes **Inbox** = not archived, not spam, not deleted.
  An **All Mail** rail entry (already present) shows everything regardless of archive.
- An archive action in the open-thread toolbar (and "move to Inbox" from All Mail) lets
  a human do manually what the filter does — symmetry with star/spam, which already
  have both filter-driven and manual paths.

This is the only disposition concept filters introduce that the reader doesn't already
have; everything else (label, star, read, spam, delete, forward) reuses existing
reader/relay capability. The sectioned list (Unread/Starred/Everything-else) is
unaffected — sections still bucket whatever the active list returns.

### Admin UI — Filters tab

New tab **Filters** in `inbound_email_admin_tabs()` (between **Accounts** and **Logs**)
→ `plugins/inbound_email/admin/admin_inbound_email_filters.php`, with logic in
`plugins/inbound_email/logic/admin_inbound_email_filters_logic.php`. Staff-only
(permission ≥ 5). No new top-level menu item (it lives under the existing Incoming
section's tab strip).

**List view.** A table of filters grouped by mailbox: name/criteria summary, the
actions as compact chips, enabled toggle, edit / delete. A "Create filter" button.

**Create / edit — two steps, mirroring Gmail:**

- **Step 1 — Criteria** (the screenshot): a FormWriter form with From, To, Subject,
  *Has the words*, *Doesn't have*, a Size row (operator select `greater than`/`less
  than` + value + unit select Bytes/KB/MB), and a *Has attachment* checkbox. A mailbox
  selector ("This mailbox" / "All mailboxes in <domain>") scopes the filter. The Size
  value + unit reveal only when an operator is chosen — via FormWriter `visibility_rules`,
  not hand-rolled JS. "Continue" advances to step 2.
- **Step 2 — Actions:** checkboxes for star / mark read / skip inbox / mark spam / never
  spam / delete; an **Apply label** select (with "Create new label…"); a **Forward to**
  address field; and an *Also apply to N matching existing messages* checkbox. "Create
  filter" saves.

Both steps are FormWriter forms (CSRF, validation styling handled by FormWriter). No
explainer prose on the page — the controls are self-describing; conceptual docs live in
`plugins/inbound_email/docs/overview.md`.

Optional secondary entry point (nice-to-have, not required for parity): a "Filter
messages like this" affordance in the reader's open-thread kebab that pre-fills step 1
From/Subject from the open message. Listed so it's a decision, deferred unless cheap.

### Apply-to-existing (Gmail's "Also apply to N matching conversations")

When a filter is saved with the checkbox set, mark `fil_apply_existing_pending = true`.
A new scheduled task `ApplyInboundEmailFilters` (descriptor + class under `tasks/`,
frequency `every_run`, following the task conventions) drains pending filters:

- For each pending filter, page through that mailbox's **locally-received, non-deleted**
  stored messages in bounded batches (e.g. 200/run), run the **same** `matches()` matcher
  (a broad SQL pre-filter on the indexed full-text/`ILIKE` columns narrows candidates;
  the in-PHP matcher is authoritative), and apply the actions through the same
  `applyActions()` path.
- Clear `fil_apply_existing_pending` when the mailbox is exhausted; resume across runs
  via a cursor (highest processed `iem_inbound_email_message_id`) so a large mailbox
  doesn't block a single run. Forward-to is **not** re-applied during backfill (Gmail
  likewise doesn't re-forward historical mail) — only label/star/read/archive/spam/
  delete apply to existing messages.

Batch sizes and the no-historical-forward rule are logged so a capped run never reads as
"applied to everything" when it processed a page.

## Telemetry / logging

Each ingest that matches ≥ 1 filter writes a line to the existing inbound transaction
log (the `iel_` log surfaced under the **Logs** tab): message id, matched filter ids,
and the actions taken. This makes "why did this message get labeled/archived/deleted"
answerable, the same way auth/spam disposition is already traceable.

## Testing

- **`matches()` unit tests** (`tests/`, like the spam tests): each criterion in isolation
  (From substring + comma-OR, To, Subject, has-words tokens, doesn't-have exclusion, size
  gt/lt with unit normalization, has-attachment), AND across fields, empty-filter rejection.
- **Action tests:** a synthetic stored message + a filter → `applyActions()` /
  `runForMessage()` assert label membership / star / read / archive / verdict / soft-delete
  writes happened; assert `never_spam` beats `mark_spam`; assert delete runs last.
- **Path-parity test:** the same crafted message via the Postfix path and the webhook
  path yields identical filter outcomes (the single-hook guarantee).
- **Backfill test:** seed N messages, save a filter with apply-to-existing, run the task,
  assert matching rows mutated and non-matching untouched, and that forward-to did *not*
  fire on history.

## Docs

No doc changes land with this spec — `docs/` and plugin docs describe current state
only, and none of this is built yet. When it ships, fold a **Filters** subsection into
`plugins/inbound_email/docs/overview.md` (alongside the Mailbox Reader / Spam filtering
sections; do not create a new doc file), per the parity spec's docs convention.

## Open decisions (resolve at implementation, not now)

- **Domain-wide filter + label target.** A NULL-alias (domain-wide) filter that applies
  a label needs a concrete `iif_` label, which belongs to one alias. Options: (a) forbid
  "apply label" on domain-wide filters; (b) require the domain-wide filter to name a
  representative mailbox for label application; (c) auto-create the label per matched
  alias. Lean (a) for v1 (domain-wide filters do flag/spam/forward/delete, not label).
- **Forward target trust.** Operator-only today, no verification handshake. Confirm
  whether to cap `forward_to` to the same allow-list (if any) the alias-forward path
  uses, to avoid turning filters into an open relay.
- **Shared writes vs. MailboxService.** The model's action methods write flags/membership
  directly. Decide whether to extract those one-line writes into a helper both
  `MailboxService` (viewer-scoped) and `InboundEmailFilter` (system-scoped) call so the two
  never diverge — versus accepting the small duplication.
- **Inbox as default view.** Archive is **in** (decided). The reader's default list flips
  from All Mail to **Inbox** (non-archived, non-spam, non-deleted); All Mail remains a
  rail entry showing everything. Confirm only the wording/placement of the new
  archive/move-to-inbox toolbar affordance at build time.
