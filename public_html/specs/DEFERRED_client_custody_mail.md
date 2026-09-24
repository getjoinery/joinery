# DEFERRED — Client-Custody Mail (Browser-Side Email Encryption)

**Status: DEFERRED — design record, not for implementation.** Captured so the analysis
doesn't have to be re-derived when/if it's revisited. Nothing here is scheduled.
**Relationship to existing specs:** this is the "client-side crypto fork" already recorded
as *deferred, not rejected* in `specs/mailbox_encryption_at_rest.md` (§ *Alternative:
client-side key handling*) and `specs/mailbox_security_model_public.md` (§ *Why server-side
decryption*). It builds on `specs/mailbox_hardened_ingest_relay.md` (edge-sealing) and
`specs/implemented/sealed_vault_core.md`'s **client-custody** mode (already designed for drive/passwords).
**Level:** this is mail's **Fortress** rung — end-to-end encrypted — per
`specs/protection_levels_platform.md` (R4, 2026-09-23). Mail shows no Fortress card until
this is built.

## What this is

Take Private mail one step further: instead of the server decrypting in a bounded unlock
window (server-custody), the **browser** decrypts, and each message is encrypted to a key only
the member's devices hold **the moment it arrives**. After that instant the server can never
read it again. The result is a **zero-knowledge mailbox** for everything stored — the same
guarantee Drive and passwords get, applied to mail.

**Arrival is the one moment this cannot cover.** Mail arrives over SMTP in plaintext, so
whatever receives it holds plaintext for the instant before encrypting it — the same model
Proton uses. Without the relay, that receiver is the main server. With the **Seal at the
relay** add-on, it is the separate relay box, and the main server never holds plaintext at
all. The relay is an add-on here exactly as on Private; Fortress does not require it
(`specs/protection_levels_platform.md` § Arrival).

It is the maximum posture, and it is a **large build** — essentially a Proton-class mail
client for the mailboxes that opt in. That size, plus the feature loss below, is why it's
deferred, not why it's impossible.

## The principle (why mail is server-custody by default)

Custody mode follows **whether the server needs to read the content**:
- **Drive, passwords → client-custody.** The server never processes them (Drive searches
  small filename metadata; passwords are a handful of entries; neither runs automation over
  content). Going browser-only costs them nothing.
- **Mail → server-custody (default).** The server reads bodies to run **full-text search over
  years-deep archives** and **automation** — AI triage, spam learning, auto-labeling —
  including while the user is away.

Client-custody mail means giving up the second bullet's server-side capabilities for that
mailbox. That is the whole tradeoff.

## What survives (harder, but doable — Proton does all of it)

- **Reading & rendering** move client-side, including **HTML email sanitization** in the
  browser (security-sensitive but a solved problem).
- **Attachments & previews** decrypt in the browser (the sealed-`File` bytes are already
  ciphertext; the client holds the key).
- **Search** becomes a **client-side encrypted search index**: the browser downloads an
  encrypted index, decrypts it, caches it in **IndexedDB**, and queries locally (the Proton
  model). Full-text search still works — it just runs on the device. This replaces mail's
  server-side FTS5-in-tmpfs design for that mailbox.
- **Compose / send.** The message body is composed and encrypted client-side; DKIM signing
  (the sending-lock add-on's in-app signer) either moves to the browser and the signed message is handed to
  the server purely to relay, or stays a server-side in-window step over a body the server
  sees only transiently at send. (Which, is an open question below.)
- **Threading, sorting, listing** are unaffected — they already run on cleartext operational
  metadata.

## What collapses (mail-specific — the reason to defer)

- **Server-side automation over content dies.** Automatic AI triage, spam learning,
  auto-labeling — anything that reads bodies while the user isn't present — cannot run: there
  is no server-readable plaintext and no browser session awake when the user is away. AI can
  run **from the browser on open** (triage-when-you-look), but "triage my inbox automatically
  overnight" is gone. Drive/passwords never had this, so they lost nothing; mail's
  AI-assistant value prop depends on it.
- **Content in push notifications.** For Private today, notifications are generated at ingest
  while the server legitimately holds plaintext pre-seal (sender/subject available).
  Client-custody removes that moment, so notifications become **generic by construction** —
  "New mail to `user@domain`" — exactly like relay-sealed Private mail already is. (Without
  the relay the server could read sender/subject at arrival; it must not use them, or the
  notification becomes the leak.)
- **New residual introduced:** a **decrypted search index cached in the browser's IndexedDB**
  on the user's device — governed by device security (OS sandbox, disk encryption, screen
  lock), like Drive's offline cache. Zero-knowledge against the *server*; plaintext-index at
  rest on the *device*. State it honestly.

## Packaging (if un-deferred)

Offer it as mail's **Fortress card** — the third rung of the platform ladder
(`specs/protection_levels_platform.md`), meaning end-to-end encrypted, the same promise a
Fortress Drive folder makes. Never a default: most users want the AI triage and instant
server-side search that require the server to read their mail. The card states the cost: *"No
server-side AI or server search on this domain. The server can never read stored mail."* With
the relay add-on off, the card also says: *"New mail is encrypted the moment it arrives; a
server hacked while mail is arriving could read what arrives then."* Both mail add-ons (relay
sealing, sending lock) are offered at Fortress and neither is required; how the sending lock
works over browser-composed mail is the compose/DKIM question below.

## Build inventory (what un-deferring would require)

1. **Browser mail crypto** — decrypt bodies/subjects/senders and attachment bytes in the
   browser using the mailbox's client-custody vault key (`sealed_vault_core.md`, a new mail
   client-custody scope, e.g. `mail`, PRF context `vault-mail-kek`, isolated from the others).
2. **Browser HTML sanitization** of decrypted email (a hardened, audited client sanitizer).
3. **Client-side encrypted search index** — build/sync/query in the browser (WASM SQLite or a
   JS index), IndexedDB caching, incremental fold on new mail. This is the largest single
   piece and the divergent build.
4. **Arrival sealing to the client-custody key, in two places.** Without the relay, the main
   server's ingest encrypts each message to the mailbox's client-custody public key on receipt
   and keeps no plaintext copy (no plaintext spool, log or notification text). With the relay
   add-on, the relay does the same at the edge, so the main server never holds a decryptable
   form. One sealing format, two call sites. Deferred ingest (parse/split/re-seal) would have to move client-side too
   (the browser does the parse), which is a real re-architecture of the ingest pipeline.
5. **Compose/DKIM handoff** — resolve where signing happens (open question below).
6. **Feature gating** — the AI/spam/label surfaces detect a client-custody mailbox and
   disable server-side processing (or offer browser-on-open equivalents).

## Trigger conditions (when to revisit)

- A user segment materially wants zero-knowledge mail *and* accepts losing server-side AI +
  search (e.g. an HN/privacy-forward launch cohort asks for it).
- The client-custody vault mode (drive/passwords) has shipped and its browser crypto module +
  search-in-IndexedDB patterns are proven, lowering this build's marginal cost.
- Native/extension clients exist (the served-JS mitigation), so the zero-knowledge claim isn't
  undercut by browser-delivered crypto.

## Open questions (for whenever this is picked up)

- **Search index size & sync** for a large mailbox — download/decrypt cost per unlock,
  incremental sync, IndexedDB eviction. This is what made the mail spec call it "a much larger
  build"; measure before committing.
- **Compose/DKIM:** sign in the browser and relay a signed opaque message, or a transient
  server-side in-window sign? The former is more zero-knowledge but a bigger client build.
- **Deferred ingest client-side:** the browser does MIME parse/attachment-split/re-seal on
  first view — feasible, but re-architects the pipeline the relay spec put server-side.
- **Multi-device** — the encrypted search index and any client state must reconcile across the
  user's devices without a server-readable copy.
- **Served-JS residual** — un-deferring is most honest once native/extension clients close the
  browser-delivered-crypto gap (shared with the vault's Phase-4 hardening).

## Review 2026-09-23 (findings to fold in when un-deferred)

Checked against the vault, relay, and reader code as they stand. Identifiers are stable; nothing below is built.

### Corrections to this spec

- **B1. Client custody has shipped.** `VaultClientCustody`, `VaultScopes`, `assets/js/vault-crypto.js`, Drive Fortress folders and the password vault exist. Trigger condition 2 is met except for the IndexedDB search pattern (nothing in the codebase uses IndexedDB) and native clients (`specs/native_vault_unlock.md`, deferred).
- **B1a. Native clients need a key handoff, not a passkey.** A native app cannot run the passkey ceremony (the WebAuthn wall in `native_vault_unlock.md`), and the default vault has no bypass phrase, so a phone cannot unlock Fortress mail on its own. Drive already solves this: `devices_link` shows a code on the device, the user approves it in a browser where the passkey unlocks the vault, and the browser seals the drive secret key to the device's public key (`dlk_sealed_vault_key`). The passkey is used once, in the browser, to hand the key over; the device then holds it behind its own biometric gate. Mail's native path is the same ceremony sealing the `mail` scope key alongside the drive one. This handoff is correct for Fortress only; the native unlock spec forbids it for Private.
- **B2. The sealing formats do not match.** The browser opens only its own ECIES format (X25519 + HKDF-SHA256 + AES-256-GCM, `vault-crypto.js` header). The relay sealer (`relay-sealer/seal.go`) and PHP `SealedBox` emit libsodium `crypto_box_seal`, which WebCrypto cannot open, and no PHP code seals in the browser format. Build item 4 therefore needs PHP and Go sealers matching the browser format (scalarmult + HKDF + AES-GCM, a few dozen lines each). Preferred over a browser libsodium WASM: keeps the client dependency-free, and Drive needs the same for server-initiated shares.
- **B3. Deferred ingest moves client-side only on the relay path.** Without the relay the main server holds plaintext at arrival anyway, so the existing pipeline (parse, split, filters, rspamd) runs unchanged and seals to a different public key. The no-relay build is "swap the key ingest seals to" plus the reader.
- **B4. The PRF context is derived.** `VaultScopes::prfContext()` returns `vault-{scope}-kek` and refuses declared contexts. The build step is: declare scope `mail` under `vaultScopes` in the mailbox `plugin.json` (plugin scopes are client custody by rule).
- **B5. Content notifications do not exist today.** No mail path sends sender/subject notifications; `MailboxAttentionNotice` is a relay-health notice. Nothing collapses; the rule is that none may ever be built.
- **B6. Level transitions are missing.** Raise (Private→Fortress): a server-driven in-window batch re-seals every message and attachment to the client key (shape of `backfill_seal_logic`). Lower (Fortress→Private/Standard): only the browser holds the key, so a browser-driven batch decrypts and re-seals to the server public key. `drive_level_change_logic` refuses Fortress for this reason; mail needs both paths.
- **B7. "AI from the browser on open" contradicts the AI rule.** The platform AI is server-side; handing it decrypted mail is the server reading Fortress content. Per `protection_levels_platform.md` AI rule 3, AI over Fortress mail is device-local or nothing.
- **B8. Spam scoring collapses with the relay on.** rspamd runs on the main server (`InboundEmailRouter`, `MailboxSpamPolicy`, deferred parse), not on the relay. At Fortress with relay sealing there is no verdict unless the relay scores at accept.
- **B9. IMAP feeds.** A Fortress mailbox fed over IMAP has the server pull plaintext every poll with a server-held credential, and the source provider keeps plaintext. Exclude feeds at Fortress, or state that the arrival exception repeats every poll.
- **B10. The sending lock still needs a server window.** The DKIM key is sealed to the server-custody `user` scope and signs in-window. The claim becomes "the window opens only the signing key, never mail". The compose/DKIM question resolves: outbound plaintext reaches the server regardless (it hands plaintext SMTP to the next hop unless the interop path encrypts), so browser signing adds no confidentiality. Keep server-side in-window signing; the browser seals its own sent copy before handing the body over, and `MailboxSender` must never store a plaintext copy.
- **B11. Multi-device parse reconciliation.** On the relay path, whichever browser parses the pending backlog uploads the sealed fields (subject, sender, body, attachments) so other devices do not re-parse.
- **B12. Client scopes cannot rotate.** `vault_client_setup` writes `uev_key_generation = 1` and nothing advances it; there is no `onReseal` path for a client scope and no browser ceremony to re-wrap. The sync client polls `key_generation` for a rotation that cannot happen. Either build client-scope rotation (browser re-wraps the secret key under fresh unlockers; every sealed DEK stays valid because the keypair is what rotates, not the DEKs) or state that a client scope's remedy for a suspected key exposure is "new vault, re-seal everything", which is the browser-driven batch B6 already needs.

### Reuse and simplification

- **S1. The sandboxed iframe exists.** The reader renders inbound HTML in an iframe with no scripts and no same-origin plus an injected `<base target>` (`mailbox_reader.js`, `mbx-message-body`). Build item 2 is feeding decrypted HTML into it. Remote-image/tracking-pixel blocking is a separate, custody-independent item.
- **S2. Attachments are Fortress drive files.** `SealedFileContainer` (PHP) and `drive-crypto.js` share one chunk format the browser already opens. Seal the per-file key to the mail scope and no new attachment decrypt code is needed; the sealed-`File` hook stays.
- **S3. Search is one more sealed field, matched in the browser.** Each message carries a sealed `search text` field (sender, subject, plain body, attachment names: the same recipe `MailboxIndex` folds today), capped and compressed before sealing, under the message DEK like the body. The server writes it at ingest on the no-relay path; the browser writes it after parsing on the relay path and for Sent/drafts, posting it back sealed. To search, the browser fetches the mailbox's sealed entries, decrypts into memory, and runs the literal per-token substring match the server runs today (`sanitizeFtsQuery` quotes every token, so nothing ranked is lost). Entries are per-message and idempotent, so there is no shared index blob, no sync, and no multi-device reconciliation for search. A browser-side cache is an optimization for later, not part of the design. If unwrapping one DEK per message proves slow on a very large mailbox, add one per-mailbox search key wrapped to the `mail` scope; do not change the shape.
- **S4. Overlap with `mailbox_encrypted_interop.md`.** Its E2E-inbound row semantics (excluded from FTS, list placeholder, decrypt on view, body rules off, forward carries ciphertext) are this spec's "what collapses" list. Reference them. A larger merge, defining Fortress arrival as OpenPGP encrypt-to-self with the alias key held in the browser, would fold the two builds; cost is openpgp.js in a zero-dependency client module. Recorded, not taken.
- **S5. Seal-target selection is one more branch** in `RelayMapExporter::sealTargetForAlias` and `InboundEmailRouter::resolveSealTarget`, not "two places".

### Decisions 2026-09-23 (owner walkthrough)

- **Native clients are not a blocker.** The device-link ceremony hands the mail key to a phone once (B1a); the passkey is used in the browser at link time only. Re-link only on unlink, lost device, or vault key rotation.
- **The relay sealer change is one function** in the existing Go binary (`seal.go`, browser-format ECIES from the crypto library it already uses), plus its PHP mirror. No new program.
- **Search is S3 as rewritten:** one sealed search-text field per message, matched in the browser. No shared index blob, no local database.
- **What remains genuinely large:** the browser MIME parser and HTML sanitizer for the relay path only. Everything else in the "new" list is small or medium.

### Developer surface 2026-09-24

The seven simplifications found here (one sealed-row shape for both custodies, a PHP browser-format sealer, one browser `open(row)` call, the ceremony and scope session in core, the device handoff by scope, scripts by declaration) are core work that stands without this spec, so they moved to their own: **`specs/client_custody_declared_consumer.md`** (R1–R8, WP0–WP9). Build that first. With it built, the mail-specific work left from the build inventory is: the `mail` scope declaration in `plugin.json`, `sealScopeForWrite()` on the message model returning `mail` for a Fortress mailbox's rows, the seal-target branch (S5), the search-text field (S3), the level batches (B6, on the generic re-seal batch that spec's WP8 provides), the relay sealer function, and the browser MIME parser plus sanitizer for the relay path. B12 (client scopes cannot rotate) is answered by that spec's R8.

### Security

- **V1. Relay key substitution.** The relay seals to whatever public key the main server pushes in the relay map (`RelayMapSync`). A compromised main server swaps in its own key and reads everything arriving, so "this server never sees your mail, not even as it arrives" overclaims. Build item: the browser verifies the fingerprint the relay is sealing to against its own public key, and a change needs a browser-side approval.
- **V2. Metadata stays cleartext.** Message-ID, In-Reply-To/References, envelope recipient, times, sizes. Sender and subject are sealed. State the reply-graph residual next to the IndexedDB one.
- **V3. Pin ingest-side plaintext writes.** Raw spool (`RawMessageStore`), spam-held rows, `MailRunRecord`, `MailboxMessageTimeline`, error logs. Enumerate and pin in a test the way `tests/vault/sealed_read_paths_test.php` pins decrypt sites.
- **V4. Lock must tear down rendered content.** Rendered bodies, attachment blob URLs and the IndexedDB cache outlive the key unless the idle lock clears them. Encrypt the IndexedDB cache under a key held in the non-extractable `CryptoKey`, so lock means dropping the key; the "plaintext index on device" residual shrinks to "while unlocked".
