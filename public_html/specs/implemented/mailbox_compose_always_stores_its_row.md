# A compose send always stores its own row

**Status:** implemented 2026-08-29

## What was wrong

The IMAP preset catalog carried a per-provider claim, `smtp_rewrites_message_id`,
true for Gmail and false for everything else. It meant: this provider renames a
message on the way out, so the copy it files in Sent can never be matched to
anything we stored, and there is no point storing anything.

`MailboxSender::send()` acted on that claim. For a Gmail feed with compose / Sent
sync switched on, a send stored **no** outbound row, permanently deleted the
draft it came from, and returned `pending_sent_ingest` — leaving the message to
appear whenever the next poll ingested the Sent folder.

The claim is false. Gmail preserves a client-supplied Message-ID.

## The evidence

Every message in the dev Gmail feed's Sent folder kept the Message-ID it was
submitted with, including two that Joinery composed and one carrying a
`@localhost` id:

```
uid=1  2026-06-07  <XTxWyngceNweWEBVAOOqoTK5d4zTtnmonYWvRJyCPoI@localhost>
uid=2  2026-06-07  <CnEXL8WIlgWC1jQ0BLlj1LE9OX9fZcQxjQZFZdFffY@localhost>
uid=3  2026-06-07  <f2cde41b6d7694ebc6b6a192c2b6a144@gmail.com>   = local row 97
uid=4  2026-08-29  <0941dad3b09a1ee83d328ed50ff6a10d@gmail.com>   = local row 46462
```

Stronger still, row 46462 carries `iem_imap_uid = 28`,
`iem_imap_folder = [Gmail]/All Mail`: the ingest had already matched the local
outbound row to Gmail's filed copy by Message-ID and adopted the locator onto it
(`ImapIngestor::adoptLocatorIfMissing`). The reconciliation the catalog called
impossible had been working on that account for months. It went unnoticed because
compose sync is off on that feed, so the ordinary store-the-row path ran.

## What the false claim cost

With compose sync on, a Gmail send:

- did not appear in the reader until the next poll — up to `iia_poll_interval_seconds`
  (default 300) of a sent message being nowhere;
- never persisted its uploads locally, because `persistOutboundUploads()` runs on
  the stored row that was never created;
- left **no record at all** when the Sent copy was never ingested — a disabled
  feed, a grant that cannot read mail, a Sent folder outside the feed's scope, a
  stopped poller — with the draft already permanently deleted.

## The change

Deleted rather than corrected. Gmail was the only preset where the flag was true,
so correcting it would leave a catalog column that is false everywhere,
an accessor that cannot return true, and a branch that cannot run.

- `smtp_rewrites_message_id` removed from all six presets, with its accessor
  `smtpRewritesMessageId()` and the paragraph documenting it.
- The branch and the `pending_sent_ingest` return removed from
  `MailboxSender::send()`; the send now always reaches `storeOutboundRow()`.
- `pending_sent_ingest` removed from the `mailbox/send` response and from the
  reader's send handler.

`smtp_files_sent` is untouched and still decides whether Joinery `APPEND`s the
Sent copy itself.

## The residual risk, and the durable answer

If a provider ever did rewrite the Message-ID, its filed copy would not match the
stored row and the reader would show the message twice.

The answer to that is not a per-provider claim — this defect is what a
per-provider claim looks like when it is wrong, and nothing tells us when one
goes stale. It is to stamp an identifier of our own on composed mail (an
`X-Joinery-*` header no provider rewrites) and reconcile the Sent copy on that,
which makes the question unaskable rather than answered per host. Not built:
every provider in the catalog preserves the Message-ID today, and a duplicated
row is a visible, recoverable fault, unlike the silent loss removed here.

## Supersedes

`specs/implemented/two_way_imap_sync.md` §433-476 describes the removed
behavior. That file is history and is not edited.
