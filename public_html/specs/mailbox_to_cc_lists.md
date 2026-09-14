# Mailbox To / Cc lists — who else a message went to

**Status:** two phases (owner, 2026-09-14). **Phase 1 is built and goes out
now**; **Phase 2 is under investigation** and is not built. **This spec stays
in `specs/` — never `specs/implemented/` — until the temporary backfill is
retired.** Retirement is the last work package (§ 6); when it lands, move the
spec.

| Phase | What | State |
|---|---|---|
| **1** | The permanent fix (§ 2) + the backfill for rows that still have a source on hand — IMAP-pulled and stored-raw rows (§ 4) | built, tested, ships now |
| **2** | The full backfill: rows with no source on hand — imports and pre-2026-08-25 lean records — recovered by Message-ID search of a connected IMAP account (§ 5a), archive re-import as the fallback (§ 5b) | investigating; needs the § 0 numbers from jeremytunnell first |

## 0. Before Phase 2 — measure the two mailboxes

Phase 2 is sized by what jeremytunnell actually holds for
`jeremy@jeremytunnell.com` and `jeremy.tunnell@gmail.com`. The node's admin
pages do not show it and the message model is not on the API, so it is one
query on the node (`sudo -u postgres psql -d <db name from
/var/www/html/jeremytunnell/config/Globalvars_site.php>`):

```sql
SELECT a.iea_alias || '@' || d.ied_domain AS mailbox,
  CASE WHEN m.iem_to IS NOT NULL OR m.iem_cc IS NOT NULL THEN '1 already has lists'
       WHEN COALESCE(length(m.iem_raw_headers),0) > 0     THEN '2 header block: fixed on view'
       WHEN m.iem_raw_storage_driver = 'remote'            THEN '3 pulled from IMAP: phase 1 backfill'
       WHEN COALESCE(length(m.iem_raw_message),0) > 0
         OR m.iem_raw_storage_key IS NOT NULL              THEN '4 stored raw: phase 1 backfill'
       WHEN m.iem_mir_mail_import_run_id IS NOT NULL       THEN '5 imported, lean: phase 2'
       ELSE '6 lean, no source: phase 2 or gone' END AS bucket,
  count(*) AS n, min(m.iem_received_time)::date AS oldest, max(m.iem_received_time)::date AS newest
FROM iem_inbound_email_messages m
JOIN iea_inbound_email_aliases a ON a.iea_inbound_email_alias_id = m.iem_iea_inbound_email_alias_id
JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id
WHERE m.iem_direction = 'inbound' AND m.iem_delete_time IS NULL
  AND a.iea_alias || '@' || d.ied_domain IN ('jeremy@jeremytunnell.com', 'jeremy.tunnell@gmail.com')
GROUP BY 1, 2 ORDER BY 1, 2;
```

Buckets 1–4 are Phase 1. Buckets 5–6 are Phase 2, and the question Phase 2
has to answer is: **are those messages still in the Gmail account?** If yes,
§ 5a fixes them; if only in an archive, § 5b; if neither, they are gone.

**Why it exists:** on 2026-09-14 an email to info@getjoinery.com CC'd two
people and the reader showed nobody; Reply All would have left them off. The
owner's concern is two mailboxes on jeremytunnell.com — `jeremy@jeremytunnell.com`
and the `jeremy.tunnell@gmail.com` IMAP feed — and "at least the live emails
that can be fixed".

---

## 1. The defect

A received message stored only the one address the mail server delivered it
to (`iem_recipient`, the envelope `RCPT TO`). The `To:` and `Cc:` header lists
were kept nowhere the reader could see — only inside the sealed raw header
block, when that was retained at all. So the open message said
`to info@getjoinery.com`, and Reply All, which filtered `iem_recipient`, had
nothing to add. The reader's address splitter also broke on a quoted name with
a comma (`"Ford, Tom" <tford@…>` split at the comma).

## 2. The fix (built, permanent)

Every message carries its To and Cc lists in two sealed columns on
`iem_inbound_email_messages`:

| Column | Holds | Sealed |
|---|---|---|
| `iem_to` | the `To:` list as the message carried it | every direction |
| `iem_cc` | the `Cc:` list | every direction |

Values are in one canonical form, produced by one parser, `MailAddressList`
(`plugins/mailbox/includes/MailAddressList.php`):

```
"Ford, Tom" <tford@example.com>, "Beltran, Luis" <lubeltra@example.com>
```

Names always quoted (quotes, angle brackets, CR/LF and tabs stripped first —
the treatment `iem_sender` gets), addresses always in angle brackets, entries
joined by `, `, a bare address stored bare, a name equal to its address
dropped, duplicates (any case) stored once. A reader that respects double
quotes can split on commas without splitting a name. `NULL` means "never
captured" (a row from before the columns); `''` means "captured, nothing there".

**Every ingest path fills them** from what it has:

| Path | Source of the lists |
|---|---|
| SMTP push (`InboundEmailRouter::storeMessage`) | parsed headers |
| Relay deferred parse (`parsePendingMessage`) | parsed headers, in-window |
| IMAP-extracted (`storeExtracted`) | `$msg['headers']` |
| Joinery Direct (`storeDirectMessage`) | the header part — `MailDirectHandler::buildParts` writes `To:`/`Cc:` (never Bcc), `parseHeaderPart` reads them |
| Composed Sent row (`MailboxSender::storeOutboundRow`) | the typed To and Cc, kept apart beside the merged `iem_recipient` |

**Read path:** `MailboxService::getThread()` returns `to` and `cc` per message.
A row with both columns `NULL` answers from its retained header block
(`iem_raw_headers`, parsed in memory by `MailboxService::addressListsFor()`,
never written back — a page view writes nothing). A row with neither answers
`''`.

**Reader:** the open message shows a `to` line and, when present, a `Cc:`
line, each entry in its own span. Reply All puts the sender in To and everyone
from `to` + `cc` in Cc, minus the mailbox's own address and the sender; a row
with neither list stored offers only its routing address. The splitter
(`splitEntries`) respects quoted names.

Test: `plugins/mailbox/tests/message_address_lists_test.php` (29 checks).
Docs: `plugins/mailbox/docs/overview.md` § "Who else it went to".

## 3. What existing mail can and cannot recover

Nothing in § 2 needs a backfill for mail that has a header block. The rest
depends on where a row's headers still exist:

| Existing rows | After § 2 | Recoverable? |
|---|---|---|
| Push/relay delivered **since 2026-08-25** (header retention, 64f47929) | correct on view | nothing to do |
| **IMAP-pulled** (`iem_raw_storage_driver = 'remote'`) | routing address only | **yes** — the original is on the source server |
| Stored-raw rows (raw fallback, inline raw) | routing address only | **yes** — from the raw |
| Push delivered **before 2026-08-25**, lean record | routing address only | **only if the message also exists somewhere reachable** (§ 5) |
| **Archive imports** (Takeout / mbox / eml), lean record | routing address only | **only via the source** (§ 5) |
| Own Sent rows | merged To+Cc shown as "to" | nothing lost; no split |

## 4. Phase 1 — the temporary backfill (built)

`AddressListBackfill` (`plugins/mailbox/includes/AddressListBackfill.php`) is a
`VaultDeferredWork` consumer, `mailbox_address_lists`, registered in
`plugins/mailbox/includes/bootstrap.php` ahead of the inline-image backfill.
It exists because a sealed row's lists must be sealed under the row's own DEK,
which unwraps only in the owner's unlock window (docs/scheduled_tasks.md: cron
can never read sealed content). So it runs while the owner has the reader
open with the vault unlocked, newest first, 25 rows per drain tick.

- **Candidates:** inbound, not deleted, not pending parse, `iem_to` and
  `iem_cc` both `NULL`, no retained header block, and a source to ask — a
  `remote` locator on an enabled IMAP account, or a stored raw. A sealed row
  qualifies for the owner it records; an unsealed row for any holder of a
  grant on its mailbox.
- **Source:** `remote` rows — `ImapIngestor::fetchHeaderText()` (new, 1.19),
  header-only, one open connection per account per drain; stored-raw rows —
  `getRawMessage()` in-window, `rawHeaderBlock()`.
- **Write:** plaintext on an unsealed row (`updateColumns`); on a sealed row
  `sealColumns()` with the row's DEK (`unwrapDekInWindow`), wrapping untouched.
  A source with neither header records `''` in both columns.
- **Retry:** each attempt stamps `iem_lists_attempt_time` (new column,
  `timestamp(6)`); a stamped row is retried after a day. If the window closes
  mid-drain the row is unstamped and the drain stops. One row is one
  `SealedEgressGuard::isolate()` unit.

Test: `plugins/mailbox/tests/address_list_backfill_test.php` (23 checks:
plaintext raw, captured-empty, remote fetch through a stub ingestor, gone
source stamped, sealed row locked/unlocked, key wrapping untouched).

**For the owner's mailboxes:** every `jeremy.tunnell@gmail.com` message that
was pulled over IMAP and is still on Gmail is fixed by opening the reader with
the vault unlocked and leaving it — 25 per heartbeat. `jeremy@jeremytunnell.com`
mail since 2026-08-25 is already right; earlier mail is § 5.

## 5. Phase 2 — the easiest way to fix old imported mail (not built)

Old imported rows (Takeout/mbox/eml) and pre-2026-08-25 push rows are lean
records: no headers, no raw, no locator. There are two places the headers can
still be, and one is much cheaper than the other.

**5a. The message is still in a connected IMAP mailbox — extend the backfill
(easiest, recommended).** `ImapIngestor::resolveUid()` already falls back to a
**Message-ID search** when it has no usable UID. So for a row on a mailbox
that has an enabled IMAP account, the backfill can call
`fetchHeaderText(0, null, $folder, $message_id)` and the source answers by
Message-ID. For Gmail, `[Gmail]/All Mail` holds everything the account has,
imported history included — a Takeout of the same account is the same
messages. Cost: one IMAP SEARCH plus a header fetch per row, inside the same
25-per-tick drain; no upload, no new UI, nothing the owner has to do beyond
having the reader open.

Build: widen `candidateWhere()` with a third source arm — the row's alias has
an enabled IMAP account and the row has a `iem_message_id_header` — and in
`headerBlockFor()` treat a row with no locator like a `remote` row with uid 0,
folder = the account's all-mail folder when the provider has one, else its
configured folder. Everything else (sealing, stamping, `''`) is unchanged. A
row Gmail no longer has stays stamped, retried daily, exactly as today. About
thirty lines plus two test checks (a locator-less row found by Message-ID; one
not found is stamped).

This is what fixes `jeremy.tunnell@gmail.com` history that arrived by import
rather than pull. It also fixes `jeremy@jeremytunnell.com` history **if** that
mail is also in the Gmail account (forwarded, or a Takeout of it imported) —
the search is by Message-ID, so it does not matter which mailbox the row is
filed under, only that an account the owner holds can find the message.

**5b. The message is only in an archive file — re-import with adopt-on-dedup.**
Re-uploading the same Takeout/mbox today does nothing for existing rows: the
importer matches each message by Message-ID and records a dedup. That branch
already adopts attachment bytes onto the matched row
(`MailArchiveImporter.php` ~497, `AttachmentByteCustody::adopt`); filling
missing To/Cc there is the same shape — read the archive copy's headers, write
the lists the way § 4 writes them (sealed under the row DEK, which the import
runs in-window when the mailbox seals). Cost: the owner re-uploads and
re-scans the whole archive, which is slow for a large Takeout, and the archive
has to still exist. Only worth building if 5a cannot reach the mail.

**Not recoverable:** a pre-2026-08-25 push row whose message is in no
reachable mailbox and no archive. Its Cc is gone; the reader shows the routing
address, as it always did.

**Phase 2 decision, still open:** run § 0, then answer for each of buckets 5
and 6: is the message in the Gmail account (→ 5a), only in a Takeout/mbox on
disk (→ 5b), or nowhere (→ nothing to build)? 5a is the expected answer for
the Gmail feed's own imported history and is the default plan; 5b is built
only if 5a cannot reach the mail. Phase 2 ships as its own release and reuses
the Phase 1 drain, stamping and sealing unchanged.

## 6. Retirement — what "done" means and what to remove (after Phase 2)

The backfill is temporary. It is done when, on jeremytunnell.com,
`AddressListBackfill::hasWork()` is false for the owner's user id — no row of
`jeremy@jeremytunnell.com` or the Gmail feed lacks its lists while still
having a source to ask. (Rows stamped because the source is gone will keep
being retried daily until removal; that is expected, not "not done".)

Check from the node's admin (no shell needed): a superadmin query count of
candidates, or simply that the reader shows Cc on the oldest pulled messages.

Then remove, in one commit:

- `plugins/mailbox/includes/AddressListBackfill.php`
- its `VaultDeferredWork::register('mailbox_address_lists', …)` block in
  `plugins/mailbox/includes/bootstrap.php`
- `ImapIngestor::fetchHeaderText()` (nothing else calls it)
- `iem_lists_attempt_time` from `$field_specifications` (the column may stay
  in the database; `update_database` does not drop columns)
- the § 4 paragraph in `plugins/mailbox/docs/overview.md` ("Rows with no
  retained header block get their lists back…")
- `plugins/mailbox/tests/address_list_backfill_test.php`
- the 5a extension, if built, goes with it

Everything in § 2 stays. Then move this spec to `specs/implemented/`.

## 7. Schema and releases

Three columns on `iem_inbound_email_messages`, all via `$field_specifications`
+ `update_database` (ran on dev 2026-09-14): `iem_to`, `iem_cc` (§ 2, already
on jeremytunnell with the first push) and `iem_lists_attempt_time` (§ 4, Phase
1 release). Nodes get them from the release's plugin sync (`upgrade.php`). The
info@getjoinery.com Akamai message shows its Cc the moment getjoinery has the
release — its header block was retained.

**Phase 1 release contents:** `AddressListBackfill.php`, the bootstrap
registration (1.14), `ImapIngestor::fetchHeaderText()` (1.19), the
`iem_lists_attempt_time` column (message class), the docs paragraph,
`address_list_backfill_test.php`, this spec.

**After the Phase 1 release, on jeremytunnell:** open the reader, unlock the
vault, leave the tab. Buckets 3–4 fill newest first, 25 per heartbeat
(~3,500 an hour). Then run § 0 to size Phase 2.
