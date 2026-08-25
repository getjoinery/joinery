# Bugfix: Sent-folder promotion breaks the sealed-recipient contract (and sent mail duplicates)

**Status:** Active
**Found:** 2026-08-25 on jeremytunnell.com (production, sealed mailbox). Reported as
"my vault is unlocked, but clicking the thread shows the This-mail-is-sealed banner."
**Evidence:** error log — `MailboxService: could not read iem_recipient on message
101317: InboundEmailMessage.iem_recipient holds plaintext on a sealed row —
something wrote it without sealColumns()` (also messages 2475/2478/2479 from
2026-08-24, so every promoted send does this). And the same send stored twice:
row 101316 (composer's Sent copy, recipient sealed) beside row 101317 (the
`[Gmail]/All Mail` copy, recipient plaintext, later promoted to outbound) — same
Message-ID, same alias.

This spec covers two defects in the IMAP Sent-mail lane plus one banner-semantics
defect they exposed.

## Defect 1 — direction promotion leaves plaintext in a sealed column

`iem_recipient` is a compose-only sealed field: sealed on `outbound`/`draft`
rows, deliberate cleartext metadata on `inbound` rows (the direction guard in
`decryptSealedField*()`, `plugins/mailbox/data/inbound_email_message_class.php`).

The IMAP ingest lane stores a Sent-folder or All Mail copy as an **inbound** row
(`ImapIngestor::storeMessage()` → `storeExtracted()`), recipient plaintext —
correct at that moment. Later, `markDirectionOutbound()` flips
`iem_direction = 'outbound'` with a raw UPDATE and re-seals nothing. The row now
violates the contract: an outbound sealed row holding plaintext recipient. The
read tripwire (`SystemBase`, `v1.aead.` envelope sniff) throws, the reader
substitutes a placeholder, and — defect 3 — raises the whole-thread banner.

The promotion cannot seal in place: it runs from cron, sealing the recipient
under the row's **existing** DEK requires unwrapping that DEK, and a CLI process
never holds a window (`VaultUnlock::secretKey()` returns null by construction).
Sealing is therefore deferred to the owner's next unlocked visit.

### Fix

1. **Promotion marks the debt.** New column on `iem_inbound_email_messages`:
   `iem_reseal_pending` (bool, not null, default false), declared in
   `$field_specifications` (plugin schema sync — no migration).
   `markDirectionOutbound()` sets it on the rows it promotes when the row is
   sealed.

2. **An in-window deferred consumer pays it** (`VaultDeferredWork`,
   `specs/implemented/in_window_deferred_work.md`), registered from the mailbox
   bootstrap:
   - `has_work`: indexed count of the owner's rows where
     `iem_direction = 'outbound' AND iem_content_sealed AND
     (iem_reseal_pending OR iem_recipient NOT LIKE 'v1.aead.%')`.
     The second disjunct makes the consumer **self-discovering**: every
     already-broken production row (2475, 2478, 2479, 101317, …) is found and
     healed with no repair migration. (A migration could not mark rows anyway —
     migrations run before plugin schema sync, so the new column would not exist
     on first deploy; see the plugin column/migration ordering rule.)
   - `drain`: per row, `unwrapDekInWindow()` on the row's `iem_sealed_key`, seal
     `iem_recipient` under that same DEK (`sealColumns()` reuse path), clear
     `iem_reseal_pending`. A row whose DEK will not unwrap is logged and skipped,
     never retried in a tight loop (the predicate keeps it visible).

3. **The read path tolerates the debt without lying.** In
   `MailboxService::decryptThreadRow()` (and the thread-list equivalent,
   `fetchAndDecryptContent()`), when `iem_recipient` on a sealed outbound row
   fails the envelope sniff **and** the row matches the reseal predicate, render
   the plaintext value — it *is* the true recipient — with no placeholder, no
   banner, no log line. The tripwire keeps throwing for every other
   column/state: it exists to catch write-path bugs, and this one known lane is
   now enumerated.

## Defect 2 — the same send is stored twice

Two independent dedup misses:

- The general `(Message-ID, recipient)` unique key cannot match a composer's
  Sent copy: its `iem_recipient` is sealed ciphertext, the All Mail copy's is
  plaintext. Sealing quietly disabled that key for outbound mail.
- The §9 Message-ID-only dedup (`aliasMessageIdByMessageId()`) is gated on the
  Sent-**role** folder, so the All Mail coverage pass never consults it and
  stores a fresh row. (Sequence on jeremytunnell: composer stored 101316 at
  11:55; All Mail pass stored 101317 at 12:00; the later Sent pass then §9-hit
  one of the two — `LIMIT 1`, no ORDER BY, in practice the wrong one — and
  promoted it.)

### Fix

1. **Coverage folders dedup against the composer's copy too.** Before
   `storeExtracted()`, every IMAP store path (not just Sent-role) looks up the
   alias's rows by Message-ID **restricted to `outbound`/`draft` direction** —
   the composed-mail directions, where the recipient half of the unique key is
   sealed and meaningless. On a hit: adopt the locator, record folder
   membership, store no new row. Inbound-vs-inbound dedup keeps today's
   `(Message-ID, recipient)` semantics — two aliases legitimately hold copies of
   one inbound message.
2. **`aliasMessageIdByMessageId()` prefers the composer's copy**: order the
   lookup outbound-first, then draft, then inbound (and oldest first as the
   tiebreak), so the Sent pass binds locator and promotion to the right sibling
   deterministically.
3. **Existing duplicates are merged by the same deferred consumer** (a second
   `has_work` clause): for a pair of live outbound rows on one alias sharing a
   Message-ID where one carries an IMAP locator and the other is the composer's
   copy, move the locator (`adoptLocatorIfMissing()`) and folder-membership rows
   onto the composer's copy and soft-delete the ingested duplicate. Merging runs
   in-window because deciding and logging touches sealed rows; the action is a
   plain row merge. Only exact (alias, Message-ID, both-outbound) pairs qualify —
   anything less similar is left alone.

## Defect 3 — one bad column raises the whole-thread "sealed" banner

`MailboxService::$content_locked` is set by **any** placeholder substitution and
surfaces as the reader's "This mail is sealed." unlock banner (`data.locked`,
`mailbox_reader.js`). Three very different states currently share it: a genuinely
closed window, a pending-parse row, and a damaged/mis-written column. For the
third, the banner is a lie — unlocking fixes nothing — which is exactly how this
bug presented ("my vault is unlocked, but…").

### Fix

`content_locked` is raised only by `VaultLockedException` and pending-parse
substitutions — the states an unlock (or waiting for parse) actually resolves. A
column that fails decryption for any other reason still renders its placeholder
and still logs, but does not raise the banner. (With defect 1's tolerance in
place, the promoted-recipient case no longer reaches this path at all; this
change is what keeps the *next* write-path bug from masquerading as a lock.)

## Acceptance

1. Sending mail from a sealed mailbox with IMAP Sent-folder sync active yields
   **one** outbound row per send (composer copy, IMAP locator adopted onto it),
   with `iem_recipient` sealed.
2. On a mailbox with pre-existing broken rows, one unlocked visit heals them:
   the reseal predicate goes to zero, recipients decrypt, no banner, no
   tripwire log lines.
3. Pre-existing duplicate pairs merge to the composer's copy; Sent view shows
   the message once; its attachments remain fetchable (locator adopted).
4. While debt is outstanding (before the drain runs), the thread renders the
   plaintext recipient with **no** sealed banner and no error log.
5. The banner appears for a genuinely locked window and for pending-parse rows,
   and does not appear for a damaged unrelated column (which still logs and
   renders a placeholder).
6. The tripwire still throws for plaintext in any other sealed column, and for
   `iem_recipient` on rows outside the reseal predicate.
7. Tests: harness tests in `plugins/mailbox/tests/` covering promotion marking,
   the drain (seal + clear, DEK-reuse), the read-path tolerance window, the
   outbound-first dedup lookup, coverage-pass dedup, duplicate merge, and the
   banner semantics. The deferred pieces are exercisable in the `db` tier by
   driving the consumer directly with a synthetic window.

## Non-goals

- No change to what is sealed on inbound rows (`iem_recipient` stays cleartext
  routing metadata there — scope queries and dedup depend on it).
- No change to the sealed-envelope format or `sealColumns()` itself.
- No attempt to seal from cron — the window model is the design, not the bug.
- Self-addressed sends keep their existing inbound treatment (§9's
  `envelopeAddressedToSelf()` rule is untouched).
