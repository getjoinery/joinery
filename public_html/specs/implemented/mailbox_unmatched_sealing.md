# Sealing mail that belongs to no mailbox

**Status:** Implemented and shipped in 0.8.205 / 0.8.206, with the sealing
warning relaxed in 0.8.206 so a fresh backlog no longer raises the
needs-attention banner. Verified by the db tier (208 tests) including a suite
against a real Fortress domain, and confirmed live on jeremytunnell.com, where
the blocked message became converging.

It exposed a gap outside its own scope: nothing runs the sealing pass on a
timer, so a backlog waits until an operator opens the domain editor.

## The problem

On a domain that encrypts mail at rest, some mail arrives that nobody owns.

Mail addressed to `postmaster@`, `abuse@`, a typo'd address, an old address you
retired, or an address a spammer guessed is accepted by the domain's catch-all
and stored with **no mailbox attached**. Sealing encrypts each message to one
person's key, and that person is normally the mailbox's single owner. A message
with no mailbox has no owner, so there is no key, so it is stored **in
plaintext** — on a domain whose entire purpose is that the server cannot read
your mail without you.

The mailbox admin currently reports this correctly and then gives advice that
cannot be followed: create a mailbox for that address. You cannot create a
mailbox for every address a stranger might invent, and you should not have to.

This is not a rare edge. `jeremytunnell.com` accumulated 209 such messages, and
they arrive continuously.

## The fix

**Mail that belongs to no mailbox seals to the domain's owner.**

Every domain already has an owner — `ied_owner_usr_user_id`, currently described
as whose vault seals the domain's DKIM key. Catch-all mail is domain-level mail:
it arrived for your domain, at an address you never created. The domain owner is
exactly whose it is.

One key covers unlimited addresses. Nothing has to be created per address.

## Why this is a small change

The read path already works. When a message is sealed, the owner is stamped on
the row (`iem_sealed_owner_user_id`), and decryption reads **that column** — not
the alias, not the grant list. `sealedOwnerUserId()` already resolves this way,
deliberately, so that later grant or alias changes can never strand sealed mail.
A message sealed to the domain owner therefore decrypts through existing code
untouched.

Only the seal-time owner lookup gives up, in exactly two places:

**At delivery.** `InboundEmailRouter::attachmentOwnerId()` returns
`User::USER_SYSTEM` when there is no alias or no single grantee. The caller reads
that as "no vault", `$sealing` comes out false, and the message is written as
plaintext.

**In the backlog pass.** `protection_ceremony.php` does
`$alias_id ? InboundEmailMessage::singleOwnerUserId($alias_id) : null`, so
alias-less rows are skipped and stay in the backlog forever.

Both become: *no mailbox → use the domain owner*.

The resolution belongs in one named helper rather than duplicated at both sites,
since a future third caller getting it wrong reintroduces exactly this bug. The
delivery-time function is also used for **attachment** ownership, so the sealing
owner is a separate concern from it and should not simply change meaning
underneath that name.

## A sealing domain must have an owner

The fallback is only as good as the field behind it. Today a domain can sit at a
sealing level with no owner at all — `scrolldaddy.app` is Private with
`ied_owner_usr_user_id` empty right now.

So having an owner, and that owner holding a vault, becomes a **required
prerequisite of the sealing levels**, evaluated by the protection ceremony
alongside the ones already there ("one reader per mailbox", "every reader holds
a vault"). Two new required rows:

| Row | Fails when | Fix offered |
|---|---|---|
| `domain_owner` | the domain has no owner | choose an owner |
| `domain_owner_vault` | the owner has no vault | that person sets one up |

A raise is refused until both pass, in the same place every other prerequisite
is refused — while it is still a choice, rather than as a surprise afterwards.

Fortress domains mostly satisfy this already: the outbound-protection ceremony
sets the owner as a side effect of sealing the DKIM key. Private domains do not,
which is why one of yours has no owner today. Existing sealing domains that fail
the new rows are flagged by the setup check rather than silently downgraded —
nothing is un-sealed or re-levelled behind the operator's back.

## What the setup check says afterwards

An alias-less message stops being *blocked* (nothing can ever seal this) and
becomes *converging* (the next pass will seal it), which is the honest state once
a domain owner exists.

The blocked row remains for the cases that really are stuck — a mailbox with no
owner or several, an owner without a vault, or a sealing domain whose own owner
is missing. Its wording already names which cause applies per group; the
catch-all cause becomes "this domain has no owner to seal it to" instead of
"create a mailbox for that address".

## The other half: unmatched mail you cannot reach

Deleting everything in the Unmatched box makes the box disappear, and its trash
with it.

The reader renders Unmatched only when it has live messages
(`data.unmatched.total > 0`, counting `iem_delete_time IS NULL`). Trash is scoped
to the selected mailbox — `trashScopeSql()` has a dedicated Unmatched branch, so
the view exists, but it is reachable only by selecting the box first. Empty the
box and there is no way back in: the mail is soft-deleted and intact, and the
interface offers no route to it until the retention sweep purges it for good.
Deletion that is reversible in the database and irreversible in the interface.

Observed live: 208 messages sat unreachable this way, and only became reachable
again because unrelated new mail happened to arrive and bring the box back.

**Fix:** count trashed unmatched messages too, and show the box when **either**
count is non-zero. An empty-but-has-trash box is precisely the state a user needs
to see. One extra `COUNT(*) FILTER (WHERE iem_delete_time IS NOT NULL)` in
`MailboxService`, one changed condition in `mailbox_reader.js`, and the box's
badge should reflect that it holds only discarded mail rather than showing an
unread count of zero.

## Consequences to be clear about

**Only the domain owner can read unmatched mail.** Another permission-10 admin
still *sees* those rows — visibility rules do not change — but the content will
not decrypt for them. That is correct for a sealing domain and it is a change
from today, where any all-access admin reads them in plaintext.

**Existing plaintext unmatched mail converges, it does not vanish.** Once a
domain owner is resolvable, the sealing pass picks up the backlog the same way
it picks up any other unsealed history.

**Attachment ownership is unchanged.** Files on unmatched mail keep whatever
ownership they have today; only the sealing key changes. Revisiting that is a
separate question and is deliberately not bundled here.

## Rejected alternatives

**Create a mailbox per unmatched address.** Unbounded, and driven by whoever
happens to send you mail. This is what the current advice asks for and it is
the reason this spec exists.

**Refuse unmatched mail on a sealing domain** (reject rather than store).
Bounces the mail you most want — DMARC reports, `postmaster@`, misaddressed
mail from real people — to solve a key-management problem.

**Seal to every grantee on the domain.** Sealing is one message, one key. Making
it many-key is a different and much larger security model, and the platform
deliberately chose one reader per mailbox.

**Leave unmatched mail in plaintext and stop warning about it.** The warning is
correct: on a Fortress domain, plaintext mail on disk is the single thing the
level exists to prevent. Silencing it would make the level a lie.

## Data model changes

None. `ied_owner_usr_user_id` already exists and is already populated on Fortress
domains; this spec gives it a second job and makes it required at the sealing
levels.

## Docs to update

- **`plugins/mailbox/docs/overview.md`** — under encryption at rest: mail with no
  mailbox seals to the domain owner; the sealing levels require an owner with a
  vault; only that owner can read unmatched mail.
- **`docs/sealed_vault.md`** — the consumer contract mentions resolving one owner
  per item; note that mail resolves the domain owner when an item has no mailbox.

## Tests

In `plugins/mailbox/tests/`:

- an alias-less message on a sealing domain seals to the domain owner at
  delivery, and its `iem_sealed_owner_user_id` is that owner;
- the same message decrypts in the owner's unlock window and reports locked
  outside it;
- the backlog pass converges pre-existing alias-less plaintext;
- a sealing domain with no owner, and one whose owner has no vault, each produce
  their required ceremony row and refuse the raise;
- a Standard domain is unaffected — unmatched mail stays plaintext and no row
  appears;
- the setup check reports alias-less mail as converging once an owner exists, and
  blocked with the domain-owner cause when one does not;
- the Unmatched box is offered when it holds only trashed mail, and its trash
  lists those messages.

## Decisions

- Unmatched mail seals to the domain owner rather than to a per-address mailbox.
- An owner with a vault is a **hard** prerequisite of the sealing levels; the
  ceremony refuses a raise without one, and existing sealing domains that lack
  one are flagged rather than downgraded.
- The Unmatched box appears whenever it holds live *or* trashed mail.
