# Mailbox lowering unseal: a downgrade converges history back to plaintext

**Status:** Active — agreed 2026-07-21
**Builds on:** specs/mailbox_raise_receipt.md (receipt card, in-place batch loop),
specs/implemented/mailbox_protection_ceremony.md (sealing convergence),
specs/implemented/inbound_email_encryption_at_rest.md (the sealed row shape)

## Problem

Lowering a sealing level (Private/Fortress → Standard) is a plain save today:
messages sealed while the domain was protected stay sealed forever. On a
Standard domain that means an unlock ceremony to read history, search that
still routes through the sealed index (see below), and a state the owner never
chose — "my domain is Standard but my old mail is locked." A raise converges
history into the sealed form; a lowering must converge it back out.

## The asymmetry that shapes everything

Sealing needs only the holder's vault PUBLIC key — any admin session drives
it. Unsealing needs the per-message DEK, which unwraps only with the holder's
SECRET key, which exists only inside the holder's own browser-session unlock
window (`VaultUnlock` is APCu keyed by session id; CLI/cron can never hold a
window). Therefore:

- Unsealing always runs **caller-scoped**: a session unseals only rows whose
  sealed owner is the session user, only while their window is open.
- A domain whose mailboxes are held by several people converges **per
  holder**: the lowering admin unseals their own rows immediately; every
  other holder's rows unseal from that holder's own session later.
- No background job is possible, ever. This is caller-driven batch work, the
  exact mirror of `mailbox/backfill_seal`.

## Design

### 1. The unseal primitive

`InboundEmailMessage::unsealAndPersistContent(InboundEmailMessage $msg): bool`
— the inverse of `sealAndPersistContent()`, one row, in-window:

- Unwrap the DEK (`unwrapDekInWindow` with `iem_sealed_owner_user_id`);
  return false when the window is closed or no owner resolves.
- Open and write back the sealed content columns: `iem_sender`,
  `iem_subject`, `iem_body_plain`, `iem_body_html` always; `iem_recipient`,
  `iem_bcc`, `iem_draft_state` only on composed directions
  (`isComposedDirection`) and only when non-empty — the same field set, AD
  strings (`sealAd`), and direction guard the sealer uses.
- Sealed attachments (`ima_is_sealed` rows with `ima_fil_file_id`): open the
  File bytes (`attachmentAd`), `File::replace_bytes()` the plaintext back,
  flip `ima_is_sealed` false. Per-file, like sealing recorded it.
- Sealed raw (`iem_raw_sealed`, the extraction-failure fallback): open under
  `rawAd`, re-store plaintext via `RawMessageStore::write()`, flip the flag.
- Clear the row's sealed state in the same UPDATE as the plaintext columns:
  `iem_content_sealed` false, `iem_sealed_key` NULL,
  `iem_sealed_owner_user_id` NULL, `iem_key_generation` 0.
- Any open/write failure logs and returns false (the row stays sealed and
  counted — never half-unsealed; the column UPDATE happens only after every
  decrypt succeeded).

### 2. The batch driver

`mailbox_protection_unseal_batch(?InboundEmailDomain $domain, int $caller_user_id,
int $limit = 25)` in `protection_ceremony.php` — mirror of
`mailbox_protection_seal_batch`, but caller-scoped and refusing any domain
that still seals (`seals_content()` domains are skipped entirely: unsealing
protected mail is never a thing). `$domain` null means every non-sealing
domain (the reader-driven convergence path). Batch size 25, not 200 —
unsealing rewrites attachment bytes. Returns
`{unsealed, own_remaining, others_remaining}`: rows the caller can still
unseal, and rows sealed to other holders (which this session can never
touch).

### 3. Lowering lands on the receipt

The lowering save keeps its existing gate (acting user's vault open — now
doing double duty: it also guarantees the unseal loop that follows can run).
Leaving a sealing level redirects to the editor with `unsealed_now=1` — no
flash; the receipt card is the whole voice, mirroring the raise:

- **Title:** "This domain is now Standard."
- **Unseal row, live:** "Unsealing earlier messages — N remaining…" →
  resolves to "N earlier messages unsealed".
- **Fact row:** "New mail is stored ready to read — no unlock needed."
- **Button:** "Open mailbox" (visible on completion).
- **Other holders' rows**, when `others_remaining > 0` at completion: an
  info-dot row — "N messages stay sealed until their readers next unlock" —
  gray, not red; it resolves by itself.
- **Stuck guard** (mirror of the raise): a batch that unseals nothing while
  own rows remain turns the row red (corrupt/undecryptable rows, logged).
- **Locked resume:** an editor visit with own sealed rows but a closed
  window shows an amber row — "Unlock your vault, then reload to continue
  unsealing." (The primary flow never hits this: the lowering gate proved
  the window open seconds earlier.)

Card presence condition: domain does not seal AND (arrived with the
`unsealed_now` marker OR sealed rows remain) — the same resume-by-existence
rule the raise card uses. A no-JS `<noscript>` form drives the same batches
one page load at a time (`ceremony_unseal_batch` editor action).

### 4. The API action

`mailbox/unseal_batch` (`plugins/mailbox/logic/unseal_batch_logic.php`,
browser-session credential): body `{domain_id}` optional. Caller-scoped by
construction (mirror of `mailbox/backfill_seal` — any signed-in holder may
converge their own mail; no staff gate, because the rows are theirs and the
domain posture already says plaintext is the correct state). Returns the
batch driver's `{unsealed, own_remaining, others_remaining}` plus
`{locked: true}` instead when the caller's window is closed.

### 5. Search follows the domain's posture

`MailboxService` currently picks the sealed FTS5 index for a single-mailbox
scope whenever the owner *holds a vault* — correct when vault ⇒ sealed
mailbox, wrong after a lowering: search would demand an unlock forever, and
the plaintext tsvector path would sit unused. New condition: the sealed index
is used when the owner holds a vault AND the mailbox still has sealed content
(its domain seals, or sealed rows remain on the alias). Once a lowered
mailbox converges, search is plain Postgres FTS with no unlock — and until it
converges, leftovers stay searchable through the index instead of silently
unfindable.

### 6. Reader-driven convergence (other holders)

The same action serves every other holder without new machinery: the profile
reader page, when the signed-in user has sealed rows on non-sealing domains,
runs `mailbox/unseal_batch` (no domain_id) quietly in the background. Window
closed → the action answers `locked` and the loop stops silently; the
holder's next unlock-and-visit converges them. No banner, no decision — the
domain owner already made the decision when they lowered the level.

### 7. Setup tab visibility

`InboundEmailSetupCheck` gains an info row on a non-sealing domain that still
has sealed rows: "N messages remain sealed from an earlier protection level —
they unseal when their readers next unlock." Keeps stragglers visible to the
admin without alarming anyone (INFO, not FAIL: the state is safe, just not
yet converged).

## Out of scope

- Unsealing anything on a domain that still seals (refused at every layer).
- Key deletion / vault teardown after convergence — the vault may serve other
  scopes; nothing here touches it.
- A lowering step-up beyond what exists (level changes already step up).

## Tests

`plugins/mailbox/tests/lowering_unseal_test.php` (db tier), reusing the
ceremony test's fixture pattern (domain, alias, grant, sodium keypair vault):

- Round trip: seal rows via `mailbox_protection_seal_batch`, lower the
  domain, unseal via the batch driver with a stubbed-open window — plaintext
  restored byte-for-byte (subject/body/sender), flags and key columns
  cleared, attachment bytes plaintext with `ima_is_sealed` false.
- Sealing-domain refusal: the batch driver skips domains that seal.
- Caller scoping: rows sealed to another owner count in `others_remaining`
  and are untouched.
- Closed window: primitive returns false, row untouched (still sealed).
- Composed-direction fields: an outbound row's recipient unseals; an inbound
  row's recipient (routing metadata, never sealed) is left alone.
- Search-path condition: sealed-index only while sealed content remains
  (unit-level on the new condition helper).
- Receipt render: lowering title/facts, others-remaining info row, locked
  amber state, noscript form.

Window stubbing: `VaultUnlock` is APCu/session-backed — the test uses the
same in-window simulation the existing sealed-read tests use (see
`mailbox_reseal_test.php` / compose tests for the established pattern).

## Docs updates (same change)

- `plugins/mailbox/docs/overview.md` — the security-levels section gains the
  lowering paragraph: lowering runs the unseal convergence on the receipt
  card, per-holder, caller-driven; search path follows the mailbox's sealed
  content; Setup tab info row.
- `docs/sealed_vault.md` — only if it enumerates mail's consumer behaviors;
  otherwise no change (the unseal is mailbox-internal).
