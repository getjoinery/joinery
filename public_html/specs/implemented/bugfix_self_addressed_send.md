# A message you send to yourself is one message

## What was wrong

Sending mail from a hosted mailbox to that same mailbox put the message in the
conversation **twice** — once tagged Sent, and once, right below it, reading as
a reply from yourself with identical content.

Observed on `info@mapsofwisdom.org` (2026-09-01) and reproduced on dev: one
send, two rows carrying one Message-ID.

| row | direction | written by |
|---|---|---|
| 101599 | `outbound` | `MailboxSender::storeOutboundRow()` at send time |
| 101600 | `inbound` | `InboundEmailRouter` when the message completed the trip out through MX and back in through Postfix |

Both carried the same `iem_thread_key`, so the thread list showed one
conversation — correctly — and opening it showed the message twice.

The dedup that should have caught this structurally could not. Its key is
`(Message-ID, recipient, direction)`, and the two rows differ in direction by
construction. The IMAP ingest path had the same hole for a different reason: it
*deliberately* skipped its composed-copy dedup for a self-addressed envelope,
because the composer's row is `outbound` and the Inbox is defined as "not
outbound" — without the exemption a self-send would have vanished from the
Inbox entirely.

A second defect surfaced beside it. `info@mapsofwisdom.org` is a **Standard**
mailbox — unencrypted — yet its Sent copy was AEAD ciphertext while its
delivered copy was plaintext. `InboundEmailRouter::resolveSealTarget()` calls
itself "the one answer every ingress path uses so they can never disagree" and
reads the mailbox's posture; `MailboxSender` and `MailboxDrafts` never asked it,
sealing whenever the mailbox's single owner happened to hold a vault. The result
was sent mail that renders as "Sealed message" outside the owner's unlock window
and never appears in search, on a mailbox nobody asked to encrypt. Both files
even carried comments describing the Standard behaviour they did not implement.

## The end state

**One row.** The composer's row is the message. Its delivered copy reconciles
onto it rather than storing beside it — the same rule every other collision in
this package already follows (`ImapIngestor`'s composed-copy dedup,
`PromotedRowRepair`'s duplicate retirement: the composer's copy survives).

`iem_self_delivered` on that row is what makes the single row workable. The
Inbox admits an outbound row carrying it, so the message appears in the Inbox
**and** in Sent — one entry in each, which is what every mail client does with
mail you address to yourself.

### Ingest

Both ingress paths resolve a self-addressed delivery to the same row, by
Message-ID scoped to the mailbox. That lookup — not the unique key — because on
a sealing mailbox the composed row's `iem_recipient` is ciphertext, so the key
is blind to it.

- **`InboundEmailRouter::storeMessage()`** — a live inbound delivery whose
  mailbox already holds a live composed row for that Message-ID stores nothing.
  It stamps `iem_self_delivered` on the composer's row and adopts the delivery's
  DKIM/SPF/DMARC verdicts where that row still holds placeholders, then reports
  an ordinary dedup. Idempotent, so a Postfix redelivery cannot fork a row.
- **`InboundEmailRouter::storeDirectMessage()`** — the same reconcile on the
  same terms. Direct discovery can resolve a domain this deployment hosts, so a
  message can leave over the channel and arrive back at the mailbox that composed
  it; a store path that opted out would duplicate on one transport and not the
  other.
- **`ImapIngestor::ingestOneStored()`** — the self-addressed exemption is gone.
  A sighting outside the Sent folder reconciles onto the composer's copy like
  any other and marks it self-delivered.

Deliberately narrow. A delivery still stores its own row when there is no
composer's copy to reconcile onto (mail from outside; a self-send composed in
some other client), when that copy was **thrown away** (the delivery is then the
member's only copy), and when the matching row belongs to a **different**
mailbox.

A self-send composed elsewhere and pulled in over IMAP still stores as one
inbound row and shows in the Inbox only, exactly as before — the source mailbox
holds one copy, so there is nothing to reconcile.

### Sealing posture

`MailboxSender::sealTargetFor()` is the one place composing decides, and it
delegates to `InboundEmailRouter::resolveSealTarget()`. `MailboxDrafts` uses it
too, so a draft and the send it becomes can never disagree. A sealing mailbox
with no usable key throws rather than downgrading — the same refusal delivery
makes, surfaced as a send failure, because writing the composer's own words in
the clear on a Private or Fortress mailbox must never happen quietly.

## Historical rows

`iem_015_reconcile_self_addressed_sends` heals the pairs that predate the fix:
the composer's row gains the flag, the delivery's verdicts, and its read state;
the delivered row goes through `permanent_delete()`, which reaches the
attachment Files and manifest rows a one-level cascade cannot.

Sent rows already sealed on a Standard mailbox need no new machinery. They are
sealed content whose posture no longer seals, which is exactly the scope
`mailbox_protection_unseal_batch()` converges: the domain editor lists the
backlog on any visit and the owner clears it in-window.

## Tests

- `plugins/mailbox/tests/self_addressed_send_test.php` (db) — the reconcile, its
  limits, both reader views, and the sealing posture.
- `plugins/mailbox/tests/imap_sent_direction_test.php` (db) — updated: the
  coverage pass reconciles a self-send instead of duplicating it.
