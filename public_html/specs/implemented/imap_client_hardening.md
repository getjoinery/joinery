# IMAP Client Hardening

**Status:** Implemented 2026-09-23. All 18 findings were reproduced (see *Verification results*), then fixed and pinned by `plugins/mailbox/tests/imap_hardening_test.php` (70 checks). Q1 and Q2 are decided. Where the build differs from the plan below, see *Implementation notes* at the end.

**Scope:** The mailbox plugin's IMAP feed: `ImapIngestor.php`, `ImapSyncer.php`, `ImapClient.php`, the Horde library settings we pass (`bytestream/horde-imap-client` v2.34.1), `PollImapAccounts.php`, and the shared store path in `InboundEmailRouter::storeExtracted` where IMAP mail passes through it.

**How findings were graded:** each one was first traced in the code (file:line given), then run for real (results below).

---

## Summary

| # | Problem (plain terms) | Severity |
|---|---|---|
| F1 | Server certificates are never checked, so anyone on the network path can pose as the mail server and collect the password or token | Critical |
| F2 | When a server renumbers a folder, two-way sync keeps using the old numbers and changes, trashes or deletes the **wrong messages** on the server | Critical |
| F3 | One message that fails to store blocks the folder: nothing more than one batch past it is ever fetched again | Critical |
| F4 | If the server returns nothing for a body, the message is saved with an empty body, permanently | High |
| F5 | Bad characters in a subject, sender or filename, or a cut through a multi-byte character, make the database refuse the message, which then triggers F3 | High |
| F6 | Mail with no Message-ID is saved again from every folder and every rescan | High |
| F7 | Mail with a missing or bad Date header is dated "now", so old imported mail lands at the top of the inbox | Medium |
| F8 | A folder status the server omits is read as 0: the feed either rescans every poll or silently never fetches again | Medium |
| F9 | A server whose folder numbering keeps changing sends the feed into an endless reseed/rescan loop | Medium |
| F10 | A short or empty answer from the server makes sync drop labels on messages that still have them | Medium |
| F11 | A push that fails forever (folder deleted on the server) blocks every later flag and trash push | Medium |
| F12 | A moved message's new number is saved with the *old* folder's numbering generation | Medium |
| F13 | Text bodies are downloaded whole before being cut to 2 MB — one huge message can exhaust memory | Medium |
| F14 | A failing account is retried every interval forever; Gmail and Microsoft lengthen their blocks when clients keep trying | Medium |
| F15 | A message moved or deleted on the server outside a label folder leaves a stale pointer; its attachments then report "no longer available" | Medium |
| F16 | Sent/Trash/Junk are not recognised under names like `INBOX.Sent` on servers without folder-role flags | Low |
| F17 | The IMAP host can be an internal address; the poller will connect to services inside our own network | Low |
| F18 | The "does this folder already exist?" check treats `*` and `%` in a label name as wildcards | Low |

Owner decisions: **Q1** — remove plaintext connections (F1). **Q2** — keep the local copy when the source deletes, and mark it source-gone (F15).

---

## Verification results (2026-09-23)

**How it was run.** A fake IMAP server sits behind the `ImapClient` seam and returns real Horde fetch objects, built from raw RFC 822 messages. The real `ImapIngestor::poll` and `ImapFetch::run` cycle (pull → ingest → push) ran against it, writing to the test database (`test_joinerytest`, via `harness_test_mode()`). F1 used a real TLS listener. The scripts are scratch only and live outside the repo; each fix's test replaces its script. The fixtures were torn down afterwards: no leftover rows in dev or test.

| # | Result | What happened |
|---|---|---|
| F1 | **Confirmed** | A TLS listener on 127.0.0.1 presented a self-signed certificate for `attacker.invalid`. `testConnection()` connected anyway and sent `AUTHENTICATE PLAIN` with the account's credentials. |
| F2a | **Confirmed** | After INBOX was renumbered, the read-flag push for message A went to INBOX UID 1, which now belongs to a stranger (C). |
| F2b | **Confirmed** | A local delete of B copied INBOX UID 2 to Trash. The server's Trash then held stranger **D**; B was untouched. |
| F2c | **Confirmed** | After the label folder was renumbered, A's label membership was deleted, though A is still in that folder on the server (at UID 9). |
| F3 | **Confirmed** | Message 5 of 300 fails every time. After 6 polls of 50: 53 of 299 good messages stored, cursor stuck at UID 4. |
| F3b | **Confirmed** | One unparsable message in a batch: 0 of 19 good messages stored in 3 polls, and every poll failed outright. On a feed with sync off this throws out of `poll()` for the whole account, not just the folder. |
| F4 | **Confirmed** | The server returned no body once. The row was stored with body `''` and was never refilled after the server recovered. |
| F5 | **Confirmed** (byte cuts) | An emoji across the 2 MB body cut, and a 600×"é" subject cut at 1000 bytes: both rejected (`invalid byte sequence for encoding "UTF8": 0xc3`). **Not reproduced:** a raw Latin-1 subject; Horde's envelope handling converts it. **Also found:** the run record shows the follow-on error `current transaction is aborted`, not the real cause (see F5). |
| F6 | **Confirmed** | One message without a Message-ID, in 2 tracked folders → 2 rows. |
| F7 | **Confirmed** | No Date header, INTERNALDATE 2019-03-01 → stored `received_time` = the time of the poll. |
| F8 | **Confirmed** (UIDNEXT) | The server omitted UIDNEXT: 3 messages on the server, 0 stored, status "INBOX: no new". **Not reproduced:** "missing UIDVALIDITY reseeds every poll". A missing value reads as a steady 0, so it never mismatches; the real risk is that a renumbering can then never be detected (see F8). |
| F9 | **Confirmed** | Changing UIDVALIDITY each poll: future-only stored 0 of 3 new messages. Full history issued 123 fetches in 3 polls with no new mail (the whole folder re-walked each time). |
| F10 | **Confirmed** | The server answered the UID list short while STATUS still said 3: the label folder's memberships went 3 → 0 in one cycle. |
| F11 | **Confirmed** | 12 dirty rows in a folder deleted on the server, `maxPerRun` 10: a newer star on an INBOX message was never pushed in 3 cycles. |
| F12 | **Confirmed** | After a MOVE into Work (UIDVALIDITY 900), the locator was Work/1 with UIDVALIDITY **100**. |
| F13 | **Confirmed** | A 32 MB text part: the body fetch asks for no length limit, and the poll's peak memory rose by 93 MB to store 2 MB. |
| F14 | **Confirmed** | An account with 6 consecutive failures, last polled 301 s ago (interval 300 s), is claimed for polling. |
| F15 | **Confirmed** | A message moved out of INBOX on the server into an untracked folder: the locator stays INBOX/1, with no mark. |
| F16 | **Confirmed** | With no role flags from the server, `INBOX.Sent`, `INBOX/Trash`, `INBOX.Junk`, `INBOX.Drafts` and `[Google Mail]/Sent Mail` all get no role. |
| F17 | **Confirmed** | An account saves with host `169.254.169.254`, and the F1 run connected to `127.0.0.1`. No host check exists in the model or either admin logic path. |
| F18 | **Confirmed** | CREATE `Q*A` failed, but LIST `Q*A` matched `QxA`, so the pending flag was cleared anyway. |

---

## F1 — Certificates are never verified (Critical)

**What goes wrong.** Every IMAP connection is encrypted but never checks *who* it is talking to. Horde's socket layer defaults to `verify_peer => false, verify_peer_name => false` (`vendor/bytestream/horde-socket-client/lib/Horde/Socket/Client.php:94-101`), and the only place we build a connection (`ImapIngestor.php:304-331`) passes no `context` to override it. Anyone who can intercept traffic (hostile Wi-Fi at a hosting provider, DNS poisoning, a compromised router) can present any certificate and receive the account's password, or its OAuth bearer token, in the LOGIN/AUTHENTICATE that follows. This is the same class as CVE-2021-26911 (Canary Mail) and CVE-2020-13163 (em-imap).

**Fix.**
- Pass `'context' => ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => <host>, 'allow_self_signed' => false]]` in the params at `ImapIngestor.php:304`. The system CA bundle is PHP's default.
- A certificate failure raises a clear, credential-free message ("The server's certificate could not be verified for imap.example.com"), recorded in `iia_last_status`, with the feed-health announcement as today.
- This is the only `new Horde_Imap_Client_Socket` in the codebase, so one change covers ingest, sync, attachment download and materialize.

**Q1 — Plaintext connections. Decided: remove.** `iia_imap_encryption` allows `none` (`inbound_imap_accounts_class.php:74,161`), which sends the password unencrypted. No account on dev uses it (all are `ssl`).
- Drop `ENC_NONE` from the constants and `allowed_values`, and from the encryption choices on the connect and edit forms.
- A data migration moves any existing `none` row to `ssl`, guarded for the plugin table per the migration rules.
- A server with no TLS at all can't be connected. That is the intended result.

**Test.** A test that connects to a listener presenting a self-signed certificate for the wrong hostname and expects the login to be refused before any credential is written. Plus a check that the params built by `client()` carry `verify_peer => true`.

---

## F2 — Stale message numbers after a folder renumbering (Critical)

**What goes wrong.** An IMAP server can declare that a folder's message numbers (UIDs) are all reset, by changing the folder's UIDVALIDITY. That happens after a restore, a migration, or a folder deleted and recreated with the same name. Every UID we saved for that folder then points at nothing, or at a *different* message.

Today, when the ingest sees the change (`ImapIngestor.php:713`) it resets only the folder's fetch cursor. Nothing invalidates the UIDs saved on messages (`iem_imap_uid`) or on label memberships (`ilm_imap_uid`). The sync engine then uses them without checking which numbering generation they belong to:
- `ImapSyncer::pushFlags` (`:371-403`) sets read/star on `folder + uid`. **This marks a different message on the server.**
- `pushTrash` (`:550-590`) moves `folder + uid` to Trash. **This trashes a different message on the server.**
- `pushMembershipNonExclusive` (`:524-529`) expunges `folder + f_uid`. **This permanently deletes a different message on the server.**
- `reconcileFlags` → `locatorRow` (`:634-646`) matches on `folder + uid` only, so another message's read/star state is copied onto ours.
- `vanishedViaUidDiff` (`:276-307`) compares old-generation UIDs against the new set and deletes label memberships for messages that are still there.

Only the attachment download path (`resolveUid`, `ImapIngestor.php:1978`) checks the generation before trusting a UID.

**Fix.**
1. **Invalidate on detection.** When ingest sees the change, one transaction nulls `iem_imap_uid` / `iem_imap_uidvalidity` for rows whose locator is that folder with the old value, and `ilm_imap_uid` / `ilm_imap_uidvalidity` for that folder's memberships. It leaves `ilm_present_base` alone, so the rows are neither dirty nor treated as removed.
2. **Guard every use.** Every query that turns a stored UID into an IMAP command or a local change also requires `uidvalidity = <folder's current iif_uidvalidity>`: `pushFlags`, `pushTrash`, `pushMembership*`, `locatorRow`, `vanishedViaUidDiff`, `repointLocator`. A row that fails the guard is skipped, never acted on.
3. **Re-find before any destructive write.** Before MOVE or EXPUNGE, a row with no valid UID is re-found by Message-ID search in its folder (the same `searchByMessageId` the download path uses). If exactly one message matches, its locator is rewritten and the write proceeds. Zero or several matches: skip, and note it in the run record.
4. **Rebaseline memberships.** After a renumbering, the next UID pass of a label folder re-finds memberships by Message-ID, so labels survive instead of being dropped.

**Test.** Using the fake client (`ImapClient` seam, `imap_syncer_test.php`): seed rows under UIDVALIDITY 100, switch the fake folder to UIDVALIDITY 200 with UIDs reused by different messages, and run a full cycle. Assert that no STORE/COPY/MOVE/EXPUNGE reaches a reused UID, no local flag changes, and no membership is deleted.

---

## F3 — One bad message blocks the folder (Critical)

**What goes wrong.** When a message fails to store, the folder cursor is held just below it (`ImapIngestor.php:790` for "server returned nothing", `:818` for any exception), so the next poll retries. There is no retry limit. Each poll then fetches a window of `maxPerRun` UIDs starting at the stuck message. Messages inside the window are stored or deduped, but **anything past the stuck message plus one window is never reached**. The folder stops receiving new mail indefinitely, with only a repeating "1 failed" in the status. (imap-mcp #54 describes the same failure.)

A second route to the same outcome: if the *batch* fetch in `fetchWindow` (`:1258`) throws — for example because Horde can't parse one message's malformed structure (iCloud, Exchange and NetEase all send some) — the whole folder errors every poll (`:638-645`) with no way past.

**Fix.**
1. **Count per-UID failures.** Add a small table (`imf_imap_ingest_failures`: account, folder id, uidvalidity, uid, attempts, last reason, first/last attempt time).
2. **Give up after 3 attempts on different polls.** The UID is recorded as *skipped*, the cursor advances past it, and the run record plus the account status say "N messages could not be imported" with the reason.
3. **Offer a retry.** The mailbox admin lists skipped messages with a "Retry" button, which re-ingests just that UID.
4. **Isolate a failing batch.** If `fetchWindow` throws, split the window in half and retry each half, down to single UIDs. A UID that fails alone gets a failure count like any other. The rest of the window is ingested normally.

Deliberate stops are not failures and keep holding the cursor: a sealing mailbox with no key (`InboundEmailRouter` "declines the message") and `InboundStoreCollisionException`. Both resolve on their own and must never be skipped.

**Test.** A fake folder with UIDs 1–300, where UID 5 throws on every ingest and `maxPerRun` = 50. After 4 polls, UIDs 6–300 are all stored, UID 5 is listed as skipped, and the cursor is at 300. A second case: the fake's batch fetch throws whenever the range includes UID 5, and every other UID is still ingested.

---

## F4 — A missing body is saved as an empty body (High)

**What goes wrong.** `fetchTextPart` (`ImapIngestor.php:2010-2015`) returns `''` when the server returns no data for the body part. That happens, for example, when another client deletes the message between the header fetch and the body fetch, or on Yahoo's "claims the message exists, returns nothing" bug. The message is stored with no body. Because it is then deduped on every later pass, it never gets one.

**Fix.** `fetchTextPart` throws when the server returns nothing for a part the structure says exists. The message counts as failed and is retried (bounded by F3). If it really was deleted, the next window no longer contains it.

**Test.** The fake returns structure + envelope, but nothing for `BODY[1]`. Assert no row is stored and the failure is counted.

---

## F5 — Invalid characters make the database refuse a message (High)

**What goes wrong.** The database is UTF-8 and rejects invalid byte sequences. Bodies are cleaned (`DocumentText::toUtf8`), but:
- the body ceiling cuts at a raw byte offset **after** cleaning (`ImapIngestor.php:1324-1327`), which can split a multi-byte character;
- the subject, sender and attachment filenames are never cleaned, and are byte-cut at 1000/500 (`InboundEmailRouter.php:1915-1916`, `:1847`; `ImapIngestor.php:1853`). Raw 8-bit headers and malformed encoded words (Latin-1 subjects in spam, old mailers) reach the insert as-is.

The insert fails, the message fails, and F3 then blocks the folder.

Verification showed the byte cuts are the live trigger. A raw Latin-1 subject is converted by Horde's envelope handling and stored fine. Cleaning header strings is still worth doing, because not every value comes through the envelope (filenames come from the structure).

**The real reason is hidden.** When the insert fails on encoding, `storeExtracted`'s catch (`InboundEmailRouter.php:1951-1984`) asks `duplicateMessageExists()` inside the now-aborted transaction. That throws `current transaction is aborted`, which is what the run record keeps. The catch must detect an aborted transaction (SQLSTATE `25P02` on the follow-up, or any non-`23xxx` first error) and rethrow the **original** exception.

**Fix.**
- One helper, `DocumentText::clip(string $s, int $bytes): string` = `scrub()` + `mb_strcut()`. It replaces every byte-limit `substr` on text headed for the database in both files: subject, sender, recipient, filename, content id, Message-ID, the body ceiling and the 4096 plain-text cut.
- Header strings pass through `toUtf8($s, '')` (detection path) before clipping.
- `storeExtracted` is shared with Postfix/Mailgun delivery, so the fix protects those paths too.

**Test.** Ingest a message whose raw subject is ISO-8859-1 bytes and whose HTML body puts a 4-byte emoji across the 2 MB boundary. Assert it stores, and that the stored text is valid UTF-8.

---

## F6 — Mail without a Message-ID is duplicated (High)

**What goes wrong.** Duplicates are detected by `(Message-ID, recipient)`. When a message has no Message-ID (some automated senders, old archives), `storeExtracted` sets it to NULL (`InboundEmailRouter.php:1895`), which matches nothing. The same message is then stored once per folder it appears in: on Gmail that is INBOX, All Mail and every label. It is stored again on every rescan (a full-history seed, or a folder renumbering under F2).

The reverse also happens, rarely: two *different* messages that share a Message-ID and recipient collapse into one, and the second is lost.

**Fix.**
1. **Gmail:** when the server advertises `X-GM-EXT-1`, fetch `X-GM-MSGID` in `fetchWindow` and store it in a new column `iem_source_message_key` (`gm:<id>`). Dedup on it first. It is exact across all labels and survives renumbering.
2. **Other servers, no Message-ID:** the key is `hdr:<sha256 of the raw header block>`. The same message on the same server has identical header bytes in every folder.
3. Add a unique index on `(iem_iia_inbound_imap_account_id, iem_source_message_key)` where it is not null. The existing Message-ID dedup stays as the second check.

**Test.** One fake message without a Message-ID in three folders is stored once. A Gmail fake with two messages that share a Message-ID but have different `X-GM-MSGID` values stores both.

---

## F7 — Missing Date header becomes "now" (Medium)

**What goes wrong.** `envelopeDate` (`ImapIngestor.php:2054-2063`) falls back to the current time. An old message with no or unparsable Date header, imported by a backfill, sorts as today's newest mail. INTERNALDATE (the server's arrival time) is already fetched in the same window (`:1263`) but unused here.

**Fix.** Fall back in this order: Date header → INTERNALDATE (`internalDateUtc`) → now. Also treat a Date more than a day in the future as invalid (spam commonly sets one to stay at the top).

**Test.** A message with no Date header and INTERNALDATE 2019-03-01 stores `received_time` 2019-03-01.

---

## F8 — A missing status value is read as zero (Medium)

**What goes wrong.** `intval($status['uidvalidity'] ?? 0)` and `intval($status['uidnext'] ?? 0)` (`ImapIngestor.php:701-703`, `ImapSyncer.php:142-144`) turn an omitted value into 0:
- a missing UIDNEXT makes the "highest UID" 0, so `lastSeenUid >= highUid` and the folder reports **"no new" forever**, silently (reproduced);
- a missing UIDVALIDITY is stored as 0 and then matches itself every poll. It does not reseed (reproduction showed this), but a real renumbering on that server can never be detected, which leaves F2's guards with nothing to compare against.

Some servers also report a stale UIDNEXT (Courier doesn't advance it after an APPEND).

**Fix.**
- A missing or zero UIDVALIDITY raises a folder error ("server did not report UIDVALIDITY") and never reseeds.
- A missing or zero UIDNEXT falls back to the real highest UID: `UID SEARCH ALL`, taking the maximum.
- When UIDNEXT is present, the highest UID is `max(UIDNEXT-1, highest UID actually returned by the last window)`, so a stale value only ever delays, never skips.

**Test.** The fake omits UIDNEXT: new mail is still ingested. The fake omits UIDVALIDITY: the folder reports the error every poll, and the cursor and seed are unchanged.

---

## F9 — A server that keeps renumbering causes endless rescans (Medium)

**What goes wrong.** Some servers report a different UIDVALIDITY per session (Nylas documents this for several hosts). Each poll then reseeds:
- with "future only" scope it jumps to the top every time and **never ingests anything**;
- with "full history" it **re-walks the whole mailbox every poll**, which on Gmail burns through the 2.5 GB/day download allowance and gets the account blocked.

**Fix.** Count UIDVALIDITY changes per folder (`iif_uidvalidity_changes`, `iif_uidvalidity_changed_time`). A third change within 24 hours stops that folder and reports it through the existing feed-health announcement ("this server keeps resetting message numbers for Folder X; syncing is paused for it"). The operator resumes it with a button once the server is fixed.

**Test.** The fake alternates UIDVALIDITY every poll: after 3 polls the folder is paused, and later polls issue no fetches for it.

---

## F10 — A short answer from the server drops labels (Medium)

**What goes wrong.** On servers without fast resync (Gmail), `vanishedViaUidDiff` (`ImapSyncer.php:276-307`) treats every stored membership UID missing from the server's current list as removed, and deletes the label locally. If the list comes back short or empty without an error (Yahoo's "claims N messages, returns nothing", a truncated response), every label in that folder is dropped. The only guard today is `highUid < 1`.

**Fix.** Ask for `STATUS MESSAGES` in the same call. If the number of UIDs returned is less than the message count, skip removal for that folder this cycle and log it. Also cap removals per cycle: if more than 50% of a folder's memberships would vanish at once, skip and log. A real mass removal is confirmed on the next cycle, when the counts agree.

**Test.** The fake reports MESSAGES 100 but returns 0 UIDs from the diff fetch. No membership is deleted.

---

## F11 — One stuck push blocks all later pushes (Medium)

**What goes wrong.** `pushFlags` and `pushTrash` select the oldest `maxPerRun` dirty rows (`ImapSyncer.php:380-381`, `:568-569`). A row whose push fails every time (its folder was deleted or renamed on the server) stays dirty and oldest. Once `maxPerRun` such rows exist, **no newer flag change or delete ever pushes**. `pushMembership` doesn't have this problem, because it doesn't use a LIMIT that counts failures.

**Fix.** Add a per-row retry backoff: columns `iem_push_attempts` and `iem_push_retry_after`. A failed push sets `retry_after = now + 2^attempts minutes` (capped at 1 day). Both queries exclude rows whose `retry_after` is in the future. After 10 attempts the row's local state is kept and marked "not synced to source". Success clears both columns.

**Test.** The fake throws on STORE for 60 rows whose folder is gone, then one row changes normally. With `maxPerRun` 50, the normal row pushes within 2 cycles.

---

## F12 — Destination UID saved with the source folder's generation (Medium)

**What goes wrong.** After a MOVE or COPY, the new UID in the destination folder is saved together with the **source** folder's UIDVALIDITY (`ImapSyncer.php:488, 490, 523, 581-582`). Folders have independent generations, so the saved pair is wrong. Today this only costs a slower Message-ID lookup on download. Once F2's strict matching lands, these locators would all read as stale.

**Fix.** Take the destination folder's UIDVALIDITY from `iif_uidvalidity` on the destination's folder row (kept current by ingest). Horde's COPYUID result gives the UID mapping but not the destination generation.

**Test.** Moving a message from a folder with UIDVALIDITY 100 into a folder with UIDVALIDITY 900 saves 900 on the locator.

---

## F13 — Whole text bodies are downloaded before the 2 MB cut (Medium)

**What goes wrong.** `fetchTextPart` fetches the entire text part, and the result is only then cut to `TEXT_BODY_CEILING` (`ImapIngestor.php:1320`). A message with a very large text part (log dumps, a mailer that inlines a huge HTML file) is loaded fully into PHP memory, several times over while it is decoded and converted. This is how the earlier check-mail memory exhaustion happened.

**Fix.** Ask the server for only what we keep: `bodyPart($id, ['decode' => true, 'peek' => true, 'start' => 0, 'length' => CEILING + 64KB])`. If the server doesn't decode for us, request the encoded bytes with the same limit, adjusted for the encoding (base64 ×4/3, rounded down to a whole 4-character group; quoted-printable cut at a line end), then decode. The truncation marker is added when the part's declared size is larger than what was fetched.

**Test.** The fake serves a 50 MB text part. Peak memory during the ingest stays under 32 MB, and the stored body is 2 MB plus the marker.

---

## F14 — Failing accounts are retried every interval forever (Medium)

**What goes wrong.** The account already counts consecutive failures (`iia_consecutive_failures`), but the poller's due-query (`PollImapAccounts.php:129`) ignores it. A wrong password, a Gmail "account exceeded bandwidth limits" block, or a Microsoft "User is authenticated but not connected" throttle is retried every poll interval. Gmail and Microsoft extend their blocks when clients keep trying, and Yahoo's random authentication rejections can build into a real lockout.

**Fix.** The due-query adds a backoff: `interval × 2^min(failures, 6)`, capped at 6 hours. A success resets it. The manual "Fetch now" still runs immediately. Specific server responses (Gmail `OVERQUOTA` / "bandwidth limits", Microsoft "not connected", the `[UNAVAILABLE]` and `[LIMIT]` response codes) jump straight to a 1-hour wait and are named in the account status.

**Test.** Poller test with an account at 3 consecutive failures: it isn't due at 1× the interval, and is due at 8×.

---

## F15 — Remote moves and deletions leave stale pointers (Medium)

**What goes wrong.** Removal detection only runs for label folders (`ImapSyncer.php:171`, `isMembership()`). Take a message whose pointer is INBOX, All Mail, Sent or Trash. If it is moved elsewhere on the server (for example to an untracked Archive folder), or permanently deleted, the pointer keeps the old folder + UID. Downloading its attachments then fails with "no longer available". On a server that files by folders rather than labels, this is the normal result of archiving in another mail client.

**Fix (pointer only).** Run the same removal detection on the pointer folders. For each message that vanished, look it up by Message-ID in the other tracked folders and move the pointer there. If it is found nowhere, mark it source-gone (`iem_source_gone_time`), so the reader says "no longer on the source server" at once instead of failing on click.

**Q2 — Remote permanent deletion. Decided: keep the local copy.** A message permanently deleted on the source keeps its Joinery row. It is marked source-gone (`iem_source_gone_time`), and the reader shows "no longer on the source server" in place of attachment download links that would fail. Joinery is the archive: a slip or a bulk delete in another mail client can't destroy mail here. A message moved to the source's Trash is still soft-deleted here as today (`markDeletedInTrash`); that is an explicit delete, not a disappearance.

**Test.** The fake moves a message out of INBOX into an untracked folder. After a cycle the pointer moves to All Mail (Gmail case), or `iem_source_gone_time` is set when no tracked folder holds it.

---

## F16 — Folder roles missed under namespaced names (Low)

**What goes wrong.** When a server doesn't mark folder roles (older Dovecot, Courier, many shared hosts), `InboundImapFolder::roleFor` (`inbound_imap_folders_class.php:180-204`) matches whole names only. `INBOX.Sent`, `INBOX/Trash`, `INBOX.Junk`, and Gmail's German-locale `[Google Mail]/…` get no role. Sent mail then reads as incoming, Trash isn't a delete signal, and Junk isn't spam.

**Fix.** Match on the last path segment, split on the folder's reported delimiter, and treat `[Google Mail]/` like `[Gmail]/`. Role flags still win when present.

**Test.** Unit test of `roleFor` with `INBOX.Sent` (delimiter `.`), `INBOX/Deleted Items` and `[Google Mail]/Gesendet` (the last is handled only when the server sends its role flag).

---

## F17 — The IMAP host can point inside our network (Low)

**What goes wrong.** An operator-entered host isn't restricted. `127.0.0.1:5432`, `10.x` or link-local addresses are accepted, and "Test connection" reports whether something answered. That is a way to probe services on the host or its private network. Horde also accepts a response of any size from the server with no limit, so a hostile server can stream gigabytes into a temp file.

**Fix.**
- Resolve the host and refuse loopback, private, link-local and metadata addresses (reuse the address checks `SafeHttpClient` already applies), both at save and at connect time, to cover DNS rebinding.
- Add a wall-clock cap to a scheduled poll (10 minutes per account) alongside the existing interactive budget.

**Test.** Saving an account with host `127.0.0.1` or `169.254.169.254` is refused with a clear message.

---

## F18 — Wildcards in the "folder exists" check (Low)

**What goes wrong.** `mailboxExists` (`ImapSyncer.php:362-369`) runs LIST with the folder name as the *pattern*. A label containing `*` or `%` matches other folders, so a failed CREATE is taken as "already exists": the pending flag is cleared and pushes into a folder that doesn't exist fail from then on (feeding F11).

**Fix.** Keep the LIST, but require an exact name match in the result (compared the same way Horde normalises names).

**Test.** A label `Q*A` where CREATE fails and folder `QxA` exists: the folder stays pending.

---

## Checked and already sound

| Known bug class | Where it's handled |
|---|---|
| Message positions shifting mid-sync | Everything uses UIDs (`Horde_Imap_Client_Ids` defaults to UID) |
| "N:*" returning a message below N | Numeric ranges plus a filter on returned UIDs (`nextOccupiedWindow`, `probeEdgeInBand`) |
| Quotes/backslashes in passwords | Horde sends LOGIN arguments as quoted or size-prefixed strings |
| Command line too long | Our UID sets are ranges or single UIDs |
| XOAUTH2 error hang | Horde answers the error challenge with the empty response (`Socket.php:831-839`) |
| STARTTLS injection / PREAUTH downgrade | Horde refuses PREAUTH when TLS is required and resets capabilities after STARTTLS; presets all use port 993 |
| MOVE fallback deleting on failed COPY | Horde throws on COPY failure before expunging (`Socket.php:3691-3713`) |
| EXPUNGE without UIDPLUS removing other clients' deletions | Horde temporarily unflags them (`Socket.php:2146-2165`) |
| `\Noselect` / `\NonExistent` folders | Skipped in `discoverFolders` |
| HIGHESTMODSEQ 0 / missing | Treated as "not established" (`ImapSyncer.php:153-168`) |
| VANISHED applied after the modseq advance (Mailspring bug) | The cursor advances to the pre-pull value only after reconciling |
| Wrong RFC822.SIZE from Microsoft | Used for display only, never to decide how much to read |
| Gmail drafts in All Mail | `isSourceDraft` skip |
| Hung server | Horde's 30 s read timeout |
| IDLE timeouts | Not applicable — the feed polls |

Still worth a test, since it is library behaviour we haven't exercised: non-ASCII and `&` in folder names (modified UTF-7) round-trip through discovery, STATUS and CREATE.

---

## Implementation order

1. **F1** — one params change; the largest security exposure.
2. **F2 + F12** — stop destructive writes to wrong messages (F12 must land with F2's guards).
3. **F3 + F4 + F5** — the blocked-folder chain: F5 and F4 are common triggers, F3 is the way past.
4. **F6, F7, F8, F9, F10, F11, F13, F14**.
5. **F15** (after Q2), **F16, F17, F18**.

Schema additions (via `$field_specifications`, `update_database`): `imf_imap_ingest_failures` table (F3); `iem_source_message_key` + index (F6); `iif_uidvalidity_changes`, `iif_uidvalidity_changed_time` (F9); `iem_push_attempts`, `iem_push_retry_after` (F11); `iem_source_gone_time` (F15).

Every fix gets a test on the existing fake-client seam in `plugins/mailbox/tests/` (header + harness). Run with `php tests/run.php db --changed` before check-in.

## Out of scope, noted

Outbound SMTP (`MailboxSender`) wasn't audited here. It should get the same certificate check as F1.

---

## Implementation notes (2026-09-23)

The build follows the plan above, except for these deliberate differences:

- **F1:** Horde reports every failed connect as "Error connecting to mail server". So after a failed implicit-TLS connect, a second handshake without verification decides the wording: refused with verification and accepted without it means the certificate failed. Only a handshake is sent, never credentials. A plainly refused password skips this check. Checked live: `imap.gmail.com`, `outlook.office365.com` and `imap.mail.me.com` are accepted; `wrong.host.badssl.com` and `self-signed.badssl.com` get the certificate message.
- **F3:** Connection-level failures are never counted against a message: disconnect, read/write errors, throttle, `INUSE`, missing folder, and any login error. Neither are store collisions or a sealing mailbox with no key. Batch isolation splits only on read/parse failures.
- **F6:** Gmail's `X-GM-MSGID` is **not** used, because Horde has no support for Gmail's fetch items. The header-hash key covers messages without a Message-ID on every server. Dedup is a lookup under the per-account fetch lock, backed by a partial index; there is no database unique constraint. The rare case of two different messages sharing a Message-ID is not addressed.
- **F8:** A missing UIDNEXT falls back to the last message's UID by sequence number. A stale UIDNEXT (Courier) is left alone, because it only delays a message until the next one arrives.
- **F9:** The pause is shown on the Accounts page with a **Resume** button, not as a feed-health announcement.
- **F10:** Only the count check. There is no 50% removal cap, because a real mass removal would have been refused forever without extra state to confirm it.
- **F11:** No terminal state after 10 attempts. The back-off caps at one day, so the row is retried daily.
- **F14:** Exponential back-off only. There is no provider-specific one-hour jump.
- **F15:** Found proactively only on QRESYNC servers, via VANISHED. On other servers a moved message is found the first time its attachments or original are fetched (`ImapIngestor::locate()`).
- **F17:** At save, only internal addresses are refused. A host that doesn't resolve right now is left for the connection test, so a DNS hiccup can't block saving the rest of a feed.
- **Existing tests:** `imap_fetch_budget`, `imap_poller`, `imap_sent_direction` and `inbound_imap_account` delete folders with raw SQL in their teardown. They now remove the folders' ingest-failure rows first.

**Gate:** `php tests/run.php db --changed`: 302 of 303 suites pass. The one failure is `core_api_mechanical` ("no file writes during a page view without being listed — includes/GuardedPdo.php"). It comes from commit eb06a599 in another session, not from this work.
