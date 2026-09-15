# Mailbox To / Cc lists — who else a message went to

**Status:** two phases (owner, 2026-09-14). **Phase 1 is built and goes out
now**; **Phase 2 is under investigation** and is not built. **This spec stays
in `specs/` — never `specs/implemented/` — until the temporary backfill is
retired.** Retirement is the last work package (§ 6); when it lands, move the
spec.

| Phase | What | State |
|---|---|---|
| **1** | The permanent fix (§ 2) + the backfill for rows that still have a source on hand — IMAP-pulled and stored-raw rows (§ 4) | built, tested, ships now |
| **2** | The full backfill: rows with no copy of their headers on the node — archive imports whose archive is gone, pre-2026-08-25 push rows. Fixed **in place** by reading the headers back from the connected IMAP account in bulk (§ 5, `AddressListSweep`); never a delete and re-import (owner, 2026-09-15). The archive arm (§ 5b) is the fallback for what the account does not hold | **built 2026-09-15**, uncommitted; needs a release, then the owner's reader open with the vault unlocked on jeremytunnell |

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

Buckets 1–4 are Phase 1. Buckets 5–6 are Phase 2. Bucket 5 is fixed from
the archive (§ 5); whether that archive is still on the node decides whether
the owner has to upload it again first:

```sql
SELECT r.mir_mail_import_run_id AS run, r.mir_state, r.mir_format, r.mir_create_time::date AS uploaded,
  r.mir_fil_file_id IS NOT NULL AS archive_kept, r.mir_stored, r.mir_dedup,
  (SELECT count(*) FROM iem_inbound_email_messages m
     WHERE m.iem_mir_mail_import_run_id = r.mir_mail_import_run_id
       AND m.iem_to IS NULL AND m.iem_cc IS NULL
       AND COALESCE(length(m.iem_raw_headers),0) = 0) AS rows_without_lists
FROM mir_mail_import_runs r ORDER BY 1;
```

Bucket 6 (pre-2026-08-25 push, never imported) is only reachable if the
message is also in the Gmail account (§ 5a); otherwise it is gone.

**Outcome 2026-09-15 (evening):** the sweep finished on jeremytunnell —
98,747 messages read, 71,304 rows recovered; the card reads 71,925 recovered,
1 retrying (source gone), **1,884 with no copy anywhere reachable**. Those
1,884 are, by count and date, run 1 — the `jeremy@jeremytunnell.com.zip`
messages that were never forwarded into Gmail. § 5b is now sized at exactly
that: re-upload that zip into `jeremy@jeremytunnell.com`, all dedup, then the
archive arm. Owner's call whether 1,884 rows are worth it.

**Findings 2026-09-15** (from the node's import page and `mail_import_status`,
superadmin; the bucket query itself still needs a shell):

| Run | Archive | Into | Imported | Ran | Archive kept? |
|---|---|---|---|---|---|
| 2 | `All mail Including Spam and Trash-002.mbox` (Gmail Takeout) | `jeremy.tunnell@gmail.com` | **96,754** (98,296 scanned, 65 already here, 1,095 excluded) | 2026-08-22 → 08-23 (22 h) | **no** — discarded |
| 1 | `jeremy@jeremytunnell.com.zip` | `jeremy@jeremytunnell.com` | **1,932** (2,222 scanned) | 2026-07-31 | **no** — discarded |

Both runs predate header retention (2026-08-25), so every one of those
98,686 rows is bucket 5: lean, no archive on the node. The Gmail IMAP feed
is live and healthy, polling INBOX, `[Gmail]/All Mail`, Sent Mail and Trash —
the account the Takeout came from is still connected, and All Mail still
holds what the Takeout held. That is what makes § 5 (read the headers back
from Gmail in bulk) the cheap path for run 2, and § 5b (re-upload) the
expensive one.

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
open with the vault unlocked, newest first, up to 200 rows per turn (the
turn's deadline is the real bound).

- **Candidates:** inbound, not deleted, not pending parse, `iem_to` and
  `iem_cc` both `NULL`, no retained header block, and a source to ask — a
  `remote` locator on an enabled IMAP account, or a stored raw. A sealed row
  qualifies for the owner it records; an unsealed row for any holder of a
  grant on its mailbox.
- **Source:** `remote` rows — `ImapIngestor::fetchHeaderTexts()` (1.20),
  header-only, batched: one open connection per account per drain, one STATUS
  + one FETCH per (account, folder) per 50 rows, so a row costs a share of two
  round trips rather than two of its own; stored-raw rows — `getRawMessage()`
  in-window, `rawHeaderBlock()`.
- **Write:** plaintext on an unsealed row (`updateColumns`); on a sealed row
  `sealColumns()` with the row's DEK (`unwrapDekInWindow`), wrapping untouched.
  A source with neither header records `''` in both columns.
- **Retry:** each attempt stamps `iem_lists_attempt_time` (new column,
  `timestamp(6)`); a stamped row is retried after a day. If the window closes
  mid-drain the row — and, in a remote batch, every row not yet written — is
  unstamped and the drain stops. One row is one `SealedEgressGuard::isolate()`
  unit; the batched header fetch decrypts nothing, so it sits outside them.

**Progress:** the drain runs inside the owner's browser session and leaves
no trace an operator can see, so `AddressListBackfill::progress()` counts
every inbound row still without a retained header block — *filled* by the
backfill (stamped, lists present), *waiting* (a source to ask, not yet tried;
by the owner whose open window it waits on, or "shared" for unsealed rows),
*retrying* (tried, the source did not answer; asked again daily),
*unrecoverable* (no source at all) — and
`AddressListBackfill::renderProgressCard()` shows them as a card at the top
of Inbound Email → Accounts, **In progress** or **Finished** — nothing
waiting on a source and every account's sweep (§ 5a) walked to the end. A
row whose source is gone is retried daily by design and does not hold the
badge open. Once the sweep is over, the *no copy* line says those rows are
not in the account either and only the archive they came from can fill them.
The card renders only while some old row lacks its lists.
Everything about it lives in the class; the admin page makes one call.

Test: `plugins/mailbox/tests/address_list_backfill_test.php` (24 checks:
plaintext raw, captured-empty, remote fetch through a stub ingestor, gone
source stamped, sealed row locked/unlocked, key wrapping untouched,
progress() moving rows from waiting to filled).

**For the owner's mailboxes:** every `jeremy.tunnell@gmail.com` message that
was pulled over IMAP and is still on Gmail is fixed by opening the reader with
the vault unlocked and leaving it — a few hundred per drain slice. `jeremy@jeremytunnell.com`
mail since 2026-08-25 is already right; earlier mail is § 5.

## 5. Phase 2 — reading the lists back from the connected account (built)

Old imported rows (Takeout/mbox/eml) and pre-2026-08-25 push rows are lean
records: no headers, no raw, no locator. The owner's rule (2026-09-15): **fix
the existing rows in place; never delete and re-import.** Deleting would lose
labels, read state, stars, threads and every attachment adoption, and the
re-import would still have to seal everything again.

**Why there is no standalone script.** On a sealed mailbox the lists are
written under the row's own DEK, and that key unwraps only inside the owner's
unlock window — cron and a CLI never hold it (docs/scheduled_tasks.md). So the
"script" runs where the Phase 1 drain runs: in the owner's browser session,
as a second `VaultDeferredWork` consumer. It is one file that depends on
nothing that is not already public, and is deleted whole (§ 6).

### 5a. The sweep — `AddressListSweep` (built)

`plugins/mailbox/includes/AddressListSweep.php` (1.0), consumer
`mailbox_address_lists_sweep`, registered in `bootstrap.php` (1.15) after the
Phase 1 consumer. On jeremytunnell the account the Takeout came from is
still connected and its All Mail still holds everything the Takeout held, so
rather than asking for each row one at a time (one IMAP SEARCH per row,
~98,000 of them), the sweep **walks each folder once** and matches what
comes back:

- **Which accounts:** every enabled, non-broken IMAP account on a mailbox
  the user holds a grant on, whose sweep is not finished.
- **Which folders:** the `\All` folder plus Trash and Junk where the account
  has an `\All` folder (Gmail — All Mail excludes those two); every tracked
  folder otherwise. From the account's discovered `iif_` rows — no LIST.
- **The walk:** UID windows from 1 to UIDNEXT, one header-only FETCH
  (`BODY.PEEK[HEADER]`) per window — 500 UIDs to start, doubling over a window
  that came back empty up to 4,000, back to 500 after a hit. A window is
  advanced over only once it has been fetched, so a sparse Gmail folder
  (UIDNEXT ≈ 270,000, live mail in the top few thousand UIDs) costs a few
  dozen round trips, not a per-UID probe. Each fetched block's `Message-ID`
  is matched, in one query per window, against **this user's rows** still
  without lists and without a header block (sealed to them, or unsealed on a
  mailbox they hold a grant on) — by mailbox-agnostic Message-ID, so a
  jeremy@ row whose message was ever forwarded into Gmail is fixed too.
- **The write:** `AddressListBackfill::writeLists()` (public as of 1.3) —
  plaintext on an unsealed row, sealed under the row's own DEK on a sealed
  one — then `stampRows()`, so `progress()` counts the row as *filled*. One
  row is one `SealedEgressGuard::isolate()` unit. A locked window
  (`VaultLockedException`) stops the turn: the rows already written in that
  window stay written and counted, the window is **not** advanced, and the
  next turn fetches it again; unsealed rows fill with no window at all.
- **The cursor:** `iia_lists_sweep_state` (text, JSON) on the account row —
  per folder `{uidvalidity, next, done}`, plus `done`, `seen` (messages
  fetched), `filled`. A UIDVALIDITY change restarts that folder from 1.
  `done` = every folder walked to its UIDNEXT; the account then leaves
  `hasWork()` for good. Rows still without lists after that are not in the
  account.
- **The card (§ 4 Progress):** one line per account — *"Reading headers back
  from jeremy.tunnell@gmail.com: 98,300 messages read, 96,912 rows recovered
  ([Gmail]/All Mail at UID 184,000; [Gmail]/Trash done)"* — and the
  *unrecoverable* line says those rows are being looked for in the account.

Test: `plugins/mailbox/tests/address_list_sweep_test.php` (21 checks, a
stub sweep answering from canned folders): sparse walk with doubling and
gap-free coverage below UIDNEXT, nothing asked past it; unsealed fill without
a window; sealed row stops the turn unadvanced and not stamped, then seals
under its own DEK in-window with the wrapping untouched; Trash walked; INBOX
and Sent not walked beside `\All`; done state, `hasWork()` false, a further
drain asks nothing; the card line.

**On jeremytunnell:** after the release, open the reader with the vault
unlocked and leave the tab. The two consumers share the drain slice; the
sweep finishes All Mail (UIDNEXT ≈ 270,000) in a few dozen fetches of a few
hundred KB each — well under an hour of open-window time — and run 2's
96,754 rows fill as their Message-IDs come past. Then read the card.

### 5b. The archive on the node — the archive arm (built)

For rows the account does not hold — on jeremytunnell the 1,884 from the
`jeremy@jeremytunnell.com.zip` import — the source is the archive they came
from. An import run keeps an index of its archive: one `mie_` entry per
message with its position (`mie_locator`) and, once stored or deduped, the
row it became or matched (`mie_iem_inbound_email_message_id`); the archive is
a Drive file (`mir_fil_file_id`) until discarded.

`AddressListBackfill` (1.5) has a fourth source arm, `archiveSourceSql()`:
a row linked from an entry (state `stored` or `dedup`) of a run that still
holds its file is a candidate, and `progress()` counts it as *waiting*
rather than *no copy*. The drain groups such rows by run, opens the run's
reader once (`MailArchiveImporter::readerAndPath()`, 1.8 — the same `open()`
the import uses, so an extracted member lands in the run's working area and
Discard archive clears it), reads each message at its locator,
`MailArchiveReader::headerBlock()`, and writes the lists the way every other
arm does. A locator the archive no longer answers stays stamped for the
daily retry; an archive that will not open stamps its whole group. A row
with a stored raw uses the raw, never the archive.

Both of jeremytunnell's runs discarded their archives, so the arm needs the
owner to **upload the zip again and import it into the same mailbox**. The
importer creates and deletes nothing: every message matches its existing row
by Message-ID (`MailArchiveImporter::existingMessageId`), is recorded as a
dedup, and the entry is linked to that row — exactly the index the arm reads.
Dedup is per mailbox, so an archive goes into the mailbox its rows live in
and no other (it would *store* everything that mailbox lacks). The archive
stays until the card reads Finished; Discard archive then reclaims it.

Test: the archive section of `address_list_backfill_test.php` (35 checks
total) — a real mbox scanned by the importer, entries linked as a re-import
links them, both rows filled through the reader; a run whose file is gone is
no source and its row reads as *no copy*.

**Not recoverable:** a row whose message is in no connected account and no
archive. Its Cc is gone; the reader shows the routing address, as it always
did.

**Finishing on jeremytunnell:** release with the archive arm → on Inbound
Email → Accounts, under jeremy@jeremytunnell.com, *Import archive*: upload
`jeremy@jeremytunnell.com.zip` (same *addresses that were yours*), Read the
archive, then import — every message reads *already here* → open the reader
with the vault unlocked and leave the tab; the card's *no copy* line moves to
*still to do* and drains to *recovered* → **Finished** → *Discard archive*
on the import page → § 6.

## 6. Retirement — what "done" means and what to remove (after Phase 2)

The backfill is temporary. It is done when, on jeremytunnell.com,
`AddressListBackfill::hasWork()` and `AddressListSweep::hasWork()` are both
false for the owner's user id — no row of `jeremy@jeremytunnell.com` or the
Gmail feed lacks its lists while still having a source to ask, and the Gmail
account's sweep has walked every folder. (Rows stamped because the source is gone will keep
being retried daily until removal; that is expected, not "not done".)

Check from the node's admin (no shell needed): the "Recovering To/Cc on
older messages" card at the top of Inbound Email → Accounts
(`AddressListBackfill::renderProgressCard()`, § 4 Progress) reads **Finished**
when nothing is waiting or retrying; it also says how many rows have no source
copy and never will fill. Or simply that the reader shows Cc on the oldest
pulled messages.

Then remove, in one commit:

- `plugins/mailbox/includes/AddressListBackfill.php`
- `plugins/mailbox/includes/AddressListSweep.php`
- the one `AddressListBackfill::renderProgressCard()` line (and its comment) in
  `plugins/mailbox/admin/admin_mailbox_accounts.php`
- both `VaultDeferredWork::register(…)` blocks — `mailbox_address_lists` and
  `mailbox_address_lists_sweep` — in `plugins/mailbox/includes/bootstrap.php`
- `iia_lists_sweep_state` from the IMAP account class's
  `$field_specifications` (the column may stay in the database)
- `ImapIngestor::fetchHeaderText()` (nothing else calls it)
- `iem_lists_attempt_time` from `$field_specifications` (the column may stay
  in the database; `update_database` does not drop columns)
- the § 4 paragraph in `plugins/mailbox/docs/overview.md` ("Rows with no
  retained header block get their lists back…")
- `plugins/mailbox/tests/address_list_backfill_test.php` and
  `plugins/mailbox/tests/address_list_sweep_test.php`
- `MailArchiveImporter::readerAndPath()` (1.8; nothing else calls it)

Everything in § 2 stays. Then move this spec to `specs/implemented/`.

## 7. Schema and releases

Three columns on `iem_inbound_email_messages`, all via `$field_specifications`
+ `update_database` (ran on dev 2026-09-14): `iem_to`, `iem_cc` (§ 2, already
on jeremytunnell with the first push) and `iem_lists_attempt_time` (§ 4, Phase
1 release); and one on `iia_inbound_imap_accounts`, `iia_lists_sweep_state`
(§ 5a, ran on dev 2026-09-15). Nodes get them from the release's plugin sync (`upgrade.php`). The
info@getjoinery.com Akamai message shows its Cc the moment getjoinery has the
release — its header block was retained.

**Phase 1 release contents:** `AddressListBackfill.php`, the bootstrap
registration (1.14), `ImapIngestor::fetchHeaderText()` (1.19), the
`iem_lists_attempt_time` column (message class), the docs paragraph,
`address_list_backfill_test.php`, this spec. **Phase 2 release contents:** the progress card (`progress()` /
`renderProgressCard()`, the one line in `admin_mailbox_accounts.php`),
`AddressListSweep.php`, the bootstrap registration (1.15), the
`iia_lists_sweep_state` column, `writeLists()`/`stampRows()` public on
`AddressListBackfill` (1.3), `address_list_sweep_test.php`.

**After the Phase 1 release, on jeremytunnell:** open the reader, unlock the
vault, leave the tab. Buckets 3–4 fill newest first, 25 per heartbeat
(~3,500 an hour). Then run § 0 to size Phase 2.
