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
