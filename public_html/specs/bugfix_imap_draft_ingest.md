# A draft on the source is not mail

## The failure

Composing one email in Gmail put **four incoming emails from himself** in the
owner's Joinery mailbox, and no sent copy where one was expected.

The four rows on node 176, alias 12 (`jeremy.tunnell@gmail.com`), 2026-08-27:

| row | folder | UID | bytes | source time |
|---|---|---|---|---|
| 101450 | `[Gmail]/All Mail` | 685650 | 1137 | 18:09:45 |
| 101452 | `[Gmail]/All Mail` | 685692 | 3695 | 18:20:03 |
| 101453 | `[Gmail]/All Mail` | 685731 | 5505 | 18:29:00 |
| 101454 | `[Gmail]/All Mail` | 685755 | 7352 | 18:37:27 |
| 101455 (outbound) | `[Gmail]/All Mail` | 685767 | 7001 | 18:59:56 |

Four distinct UIDs, four distinct Message-IDs, sizes growing as the message was
typed, one per ten-minute poll — and then the real send at 18:59:56, ingested
and promoted to outbound exactly as designed.

## Why nothing caught it

Gmail's `[Gmail]/All Mail` carries **drafts** alongside real mail, and Gmail
replaces a draft on every autosave: the old UID is expunged and a new, higher
one appears. To the coverage pass that is indistinguishable from new mail
arriving above the cursor, so each revision was fetched and stored.

None of the existing defences could apply:

- **Message-ID dedup** — every autosave carries its own Message-ID, so the rows
  are not duplicates of each other in any sense the unique key
  (`iem_message_id_header`, `iem_recipient`, `iem_direction`) can see. They are
  genuinely different messages.
- **Sent-folder promotion** — a draft is not in `[Gmail]/Sent Mail`, so nothing
  ever promotes it, and it stays an incoming message from yourself forever.
- **Not tracking `[Gmail]/Drafts`** — the drafts role folder is untracked by
  default, which is correct but insufficient: a draft is not confined to it.

The rows also outlive their source. Gmail expunges each superseded draft UID, so
the local rows point at UIDs that no longer exist.

## The rule

**A message the source still holds as a draft is never ingested, in any folder.**

The `\Draft` flag is the source's own word for "not mail yet". Joinery keeps its
own drafts as `iem_direction='draft'` rows and has no use for the source's.

Folder-agnostic on purpose: the flag travels with the message, so asking it is
the only test that covers the Drafts folder, the All Mail coverage view, and a
user label that happens to hold a draft.

## Implementation

`ImapIngestor` 1.17:

- `fetchWindow()` adds `$query->flags()`, so the decision costs no extra round
  trip.
- `isSourceDraft($data)` reads `getFlags()` and matches `draft` case-insensitively
  with any leading backslash stripped (Horde lowercases flags). **Fails toward
  ingesting**: a server that returns no flags, or throws, leaves the message
  ordinary mail — losing real mail is worse than storing a draft.
- `ingestFolder()` skips a draft immediately after the day-window scope guard and
  counts it in `source_draft`. The cursor advances past it, which is right: the
  next autosave arrives as a new UID, and if the draft is ever sent, the sent
  copy arrives as its own message.

`MailRunRecord` 1.2: `DIMENSIONS_POLL` gains `source_draft`, so a skipped draft
is provable-on-purpose and the run record's `unaccounted` reconciliation still
balances. Never a silent skip.

Tests: `plugins/mailbox/tests/imap_draft_ingest_test.php` (safe, 14 checks) —
flag recognition including the near-miss `\Drafted`, the fail-toward-ingesting
direction, that `fetchWindow` asks for FLAGS without dropping the envelope, and
the run-record reconciliation.

## What this does not do

It does not remove rows already stored from source drafts. They are ordinary
inbound rows now and can be deleted from the reader; there is no signature that
distinguishes them from real mail after the fact without asking the source, and
their source UIDs are gone.
