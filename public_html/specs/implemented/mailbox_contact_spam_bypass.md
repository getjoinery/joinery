# Contacts and the spam filter

## The problem

A member adds a correspondent to their contacts and their mail still lands in
Spam, every single time, no matter how often it is corrected. Two separate
reasons, and the second is the one that bites:

1. The address book was consulted on exactly one delivery path — Joinery Direct.
   Mail arriving over SMTP or a webhook provider never looked at it.
2. The authentication rule (DMARC fail, or no DMARC with SPF and DKIM both
   failing) files a message as spam on its own, and nothing downstream could
   lift it. Marking a message "not spam" trains the content scanner's corpus,
   which only moves the content signal — so a sender with a misconfigured DMARC
   record is re-filed forever and the correction never takes.

## What contacts buy

A contact entry elevates the sender past the **content score**. It does not
clear the **authentication rule**.

That split is the whole design. A contact is a deliberate act — the store is
only ever written by a manual add or an import, never by mail traffic — so it is
a real statement of trust and it should outrank a statistical content score.
But a DMARC failure means the `From` header is not attested: anyone can put that
address on a message. Letting contact membership clear an auth failure would
hand the inbox to whoever spoofs a contact's address next, which is precisely
what the Direct channel's own gate refuses to do (it requires the address AND a
signing domain bound to the verified instance key).

So a message from a contact whose domain authenticates correctly reaches the
inbox even with a high spam score. A message from a contact whose domain fails
authentication still files as spam — correctly — and the user is offered a way
to overrule it deliberately.

## The offer in the Spam view

A message sitting in Spam **because of the auth rule** carries a banner that
says so in plain words: this sender's domain failed authentication, their mail
cannot be proved to come from them, anyone can put that address on a message.
It offers one button — *Always allow `<address>`*.

The button writes an explicit `never_spam` filter scoped to that mailbox,
matching that address, flagged for the "also apply to existing" backfill so mail
already sitting in Spam from that sender is swept up too. The messages in hand
are marked ham inline so the click has a visible effect immediately.

The trust is therefore:

- **deliberate** — the user pressed a button that told them the risk first;
- **visible** — it is a rule on the Filters page beside every other rule;
- **reversible** — deleting the rule takes it back.

A message filed on the **content** score gets no banner. Two remedies already
exist for it and neither needs a new rule: "Not spam" teaches the scanner, and
adding the sender to contacts elevates them from then on.

## Where the rule lives

`InboundEmailMessage::authRuleSaysSpam()` is the single definition of the
authentication rule. The router asks it to decide a verdict at ingest; the
reader asks it of a stored row to decide whether to offer the banner. One
definition, so the button cannot appear on messages it would not help.

## Scope

- Elevation applies on every ingest path that stores a message: live SMTP,
  store-only and catch-all-store, the deferred/sealed parse, and archive
  imports. The Direct path already had it.
- Elevation is checked against the mailbox's shared, unencrypted address book.
  Ingest is keyless, so per-grantee contacts sealed under a closed vault are
  invisible to it and a Private/Fortress mailbox gets no automatic elevation.
  That is fail-closed, and it matches how the Direct gate reads the same store.
  The Spam-view button is unaffected: the user is present with an open window,
  so it reads the sender through the same decryption the reader uses.
- The content score is still recorded on every elevated message. A reader that
  can say "scored 9.5, delivered because the sender is a contact" is more honest
  than one that shows nothing.
