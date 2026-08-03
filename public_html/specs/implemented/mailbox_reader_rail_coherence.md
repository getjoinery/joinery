# Mailbox reader — rail coherence

**Status:** IMPLEMENTED 2026-08-03. All decisions resolved by the owner during design.

## The problem

Clicking **Contacts** in the reader's left rail did something incoherent: the mailbox you
were in stayed highlighted, its folder list collapsed, and the Contacts entry never lit up.
Behind that specific bug was a design one — the rail mixed three different kinds of thing:

- **mailboxes**, which hold mail and have folders
- **Drafts**, an account-wide bucket spanning every mailbox
- **Contacts**, an account-wide store that is not a mail location at all

Anything account-wide sitting beside a mailbox reads as a sibling of it, which is what made
selecting one leave the other looking half-selected.

## The shape

The rail lists **where mail lives, and nothing else.** Everything belonging to a mailbox sits
inside it or beside the message, not next to the mailbox.

```
jeremy@example.com          [3]
  Inbox
  Drafts                    [1]
  All Mail
  <tracked IMAP folders>
  Spam
  Trash
info@example.com
  …
Unmatched · example.com     [2]     ← all-access only, one per domain
Unmatched · other.com       [1]
```

Contacts move to the right-hand aside (`#mbx-context`), which already existed for the
correspondent card on an open message.

## Decisions

**D1 — Contacts are scoped per mailbox.** *Resolved: per mailbox.* Composing from a work
address must not suggest addresses harvested in a personal one. A contact belongs to the
mailbox it was seen on; the same person on two mailboxes is two rows, which is fine because
this store is a cache, not a person record (`FUTURE_verified_connections.md` owns identity).

**D2 — Autocomplete re-scopes when the From selector changes.** *Resolved: strict re-scope.*
Changing who you are writing **as** changes which addresses may be suggested. Addresses
already typed stay put; only the suggestion list changes. The softer alternative — suggest
across all mailboxes while the panel stays per-mailbox — was rejected because it leaks
exactly what D1 exists to prevent.

**D3 — Drafts become a per-mailbox folder.** *Resolved: per mailbox.* Every draft is already
bound to a From mailbox at save time (`MailboxDrafts::save()` rejects one with no alias), so
a draft always has exactly one place to live and none can be stranded. The cost is the loss
of a single "you have drafts somewhere" badge; the count moves onto each mailbox.

**D4 — Unmatched splits per domain.** *Resolved: per domain.* Catch-all mail seals to its
**domain's owner** (`mailbox_unmatched_sealing.md`), so one lumped box could hold mail sealed
to several different people and could state no honest protection level. Per domain, each box
means one thing and can carry a truthful chip.

**D5 — The contacts panel is collapsed by default on the list view.** *Resolved: collapsed,
remembered.* It is reference material rather than the task at hand. Collapsed still shows a
labelled spine, so the panel announces itself instead of vanishing; the choice persists.

## Implementation notes

**Mailbox scope rides in the address hash.** `imc_address_hash` digests the alias id and the
normalized address together, so the existing `(hash, user)` unique constraint already means
one row per (user, mailbox, address). This avoids re-keying a unique constraint over a column
that is itself ciphertext — and matters because `update_database` only ever **adds**
constraints, never drops the stale one, so a changed `unique_with` would have left the old
2-column constraint in place and silently defeated the change.

**Sealing follows the user, not the mailbox.** A contact row seals to the harvesting user's
vault. Two grantees sharing one mailbox each build their own contacts, readable only by them.
The alias is a scope tag, which keeps the change additive with no re-seal.

**Legacy rows are deleted (migration 164).** Rows written before scoping carry no mailbox and
hashed the address alone, so no mailbox-scoped read can ever return them. Nothing re-creates
them either — contacts are entered deliberately now rather than harvested from mail traffic —
so left in place they would be permanently invisible dead weight. A backfill was not an
option: the rows are sealed, and the blind index is keyed to the owner's in-window vault
secret, which a CLI migration can never hold; and a row records no hint of which mailbox it
came from, so on an account with several there is nothing to adopt it into. The migration is a
plain idempotent DELETE.

**The unmatched sentinel.** `MailboxService::parseAliasParam()` accepts `unmatched:{domain_id}`
and encodes it as `UNMATCHED_DOMAIN_BASE - domain_id`. The scope value travels as an `?int`
through many callers but is decoded in exactly two (`readScopeSql` / `trashScopeSql`), so
widening the signature would have touched every caller to serve two of them.

**Harvest attributes to the mail's own mailbox.** On send, the From mailbox. On thread open,
each message's own `alias_id` grouped — so a thread read from "All mail" still lands in the
right store, and a message belonging to no mailbox is skipped rather than stored unscoped.

## Files

- `plugins/mailbox/data/mailbox_contacts_class.php` — `imc_iea_inbound_email_alias_id` + FK
- `plugins/mailbox/includes/MailboxContacts.php` — alias threaded through; `listForMailbox()`
- `plugins/mailbox/includes/MailboxService.php` — per-domain unmatched, per-mailbox drafts,
  `unmatchedDomainId()`, alias-scoped `draftScopeSql()`
- `plugins/mailbox/includes/MailboxSender.php` — harvest into the From mailbox
- `plugins/mailbox/logic/` — `contacts`, `contacts_import`, `sender_context`, `thread`
- `plugins/mailbox/assets/mailbox_reader.js` / `.css` — rail, folder Drafts, contacts panel
- `plugins/mailbox/tests/` — `contacts_test.php`, `drafts_test.php`, `mailbox_reader_test.php`
- `plugins/mailbox/docs/overview.md`

## Verification

Full `db` tier: 221 suites, 6672 checks, 0 failed. `safe`: 79 suites, 1904 checks.
`db --filter=mailbox`: 51 suites, 1424 checks, 0 failed. Contacts test covers the scope
isolation directly (same address on two mailboxes is two rows with independent counts;
neither mailbox's list or lookup sees the other's). The reader test asserts the per-domain
sentinel actually filters, not just labels.

Two pre-existing assertions were passing **vacuously** once the aggregate `drafts` key went
away — `intval(null) === 0` made the co-grantee and superadmin author-scoping checks succeed
regardless. Both now read the per-mailbox count with `===`, so a missing mailbox fails instead
of reading as an empty Drafts folder.
