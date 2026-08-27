# Sent is ordered by time, not by unread

## The failure

The owner's Sent folder for `jeremy.tunnell@gmail.com` showed **August 6** as its
newest message, three weeks stale, and a message sent that afternoon never
appeared — not even after a sync.

The message was there. Row 101455 was ingested from `[Gmail]/All Mail` at
19:00:07 and correctly promoted to `iem_direction = 'outbound'`. It just was not
on the page.

The Sent view for that mailbox, ordered exactly as the reader orders it:

| thread | latest_time | unread_count | section_rank |
|---|---|---|---|
| `<CALoeTA5J3z…>` | 2026-08-06 23:37 | 1 | 0 |
| `<CALoeTA7F22…>` | 2026-08-02 18:52 | 1 | 0 |
| `<CALoeTA7i=u…>` | 2026-07-21 21:18 | 1 | 0 |
| … | … | 1 | 0 |
| (row 101455) | 2026-08-27 18:59 | 0 | **2** |

Every row on page one is `section_rank 0` — the unread section. August 6 is
simply the newest thing in the unread pile.

## Why

`listThreads` sorts `ORDER BY section_rank ASC, latest_time DESC`, bucketing
threads unread → starred → everything else. That is a **Gmail-style Inbox
affordance**: it answers *what still needs me?* and keeps the buckets contiguous
across pages so the client can render one header per section.

On a view of mail the member **sent**, the question is meaningless. Worse, the
flag it reads is not the member's:

- IMAP ingest stores every row with `iem_is_read = false` (the column default).
  That mailbox holds **25,097** outbound rows going back to 2005, nearly all of
  them never opened here.
- A freshly synced message picks up the source's `\Seen`, so today's send arrives
  **read** — and ranks *below* all 25,097.

The newest sent message was therefore guaranteed to sort last. This got worse,
not better, the longer the mailbox had been connected.

## The rule

**A view of mail the member sent or wrote is ordered by time alone.**

Sectioning applies where "unread" is something the member decides. It is not, on
an outbound or draft row: there it is the source's `\Seen`, or the ingest
default. Sent and Drafts read strictly newest-first, which is also what every
mail client does with sent mail.

## Implementation

`MailboxService` 1.33:

- `$sectioned = !$sent && !$drafts`. When false, `section_rank` is emitted as the
  constant `2` rather than the `CASE`. Emitting a constant rather than dropping
  the column means the existing `ORDER BY section_rank ASC, latest_time DESC`
  collapses to pure reverse-chronological with no change, and the client renders
  one plain list (`'other'`) with no section header — no reader-side change.
- The per-mailbox unread badge gains `AND iem_direction IS DISTINCT FROM
  'outbound'`. Its comment already claimed it matched `listThreads`' Inbox
  filter exactly; that filter excludes outbound rows and the badge did not, so
  the badge counted thousands of sent messages the Inbox it lands on has never
  shown.

Tests: `mailbox_reader_test` 1.5 — a read message sent today outranks an unread
one sent a month ago and is the first row, Sent reports one `other` section, and
the Inbox still groups unread first.

## Not in scope

Those 25,097 outbound rows are still marked unread, which is a **flag-pull**
question, not an ordering one: the historical import never reconciled `\Seen`
against the source. Ordering no longer depends on it, and the badge no longer
counts it. Left alone deliberately — rewriting the flag locally would push
`\Seen` back to the source on a two-way feed.
