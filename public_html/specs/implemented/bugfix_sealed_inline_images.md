# Bugfix: Inline images in sealed mail never render in the reader

**Status:** Active
**Found:** 2026-08-25 on jeremytunnell.com (production, sealed mailbox). Reported as
"inline image shows a broken icon in the conversation, but displays when I print."
**Evidence:** message 101316 (sent copy, inline part `inl16qqd` → File 19095) — the
signed inline URL returns HTTP 423 in the reader; message 101317 (the same send,
ingested from `[Gmail]/All Mail`) — its `cid:inl16qqd` is never rewritten at all
(`ERR_UNKNOWN_URL_SCHEME` in the console).

This spec covers two defects that present together and share one fix surface.

## Defect 1 — a sealed inline image cannot render in the conversation view

The reader renders each HTML body inside `<iframe sandbox="allow-popups
allow-popups-to-escape-sandbox" srcdoc=...>` (`plugins/mailbox/assets/mailbox_reader.js`).
A sandboxed srcdoc frame has an **opaque origin**, and the session cookie is
`SameSite=Lax`, so the browser sends **no cookies** with the frame's image requests.
That is by design — it is why inline images use signed URLs at all
(`MailboxService::resolveInlineImages()`, whose comment says "always signed, never
session-gated").

The signed URL authorizes the *fetch*. But when the file is sealed, serving also
requires *decryption*: `serve.php` `/uploads/*` → `File::serve_from_path()` → the
mailbox decrypt hooks (`plugins/mailbox/includes/bootstrap.php`) →
`VaultUnlock::secretKey()`, and the unlock window is keyed on **(session id, user
id)** in APCu (`includes/VaultUnlock.php`). A cookie-less request has no session,
so the window can never be found: the serve path returns **423 "This file is
locked"** and the reader shows a broken-image icon. Structurally, every sealed
inline image fails in the reader, always.

The print sheet (`plugins/mailbox/includes/message_export.php`) works because it
is a top-level same-origin page: its `<img>` requests carry the session cookie,
the window is found, decryption succeeds.

The same gap applies to the signed **attachment** URLs `withSignedTransport()`
mints for sessionless native clients: a sealed attachment fetched by a native app
over a bare signed URL hits the identical 423.

### Fix: the signed URL carries a serve grant

Minting is already the authorization statement — it happens only in code that has
scope-checked the viewer *and* (for sealed content) holds an open window, since the
same request just decrypted the bodies. The fix extends the statement to
decryption: at mint time, stash the file's content key server-side under a random
token, and let the serve path redeem it cookie-less.

New core helper `FileServeGrant` (`includes/FileServeGrant.php`):

- `mint(File $file, string $size_key, int $ttl_seconds): ?string` — resolves the
  file's content key **now, in-window**, stores it in APCu under
  `fsg:{128-bit random token}` with value `{file_id, size_key, shape, key
  material}` and the same TTL as the signed URL, and returns the token. Returns
  null when the file is not sealed (no grant needed) or the key cannot be
  resolved (window closed) — never throws.
- `redeem(File $file, string $size_key, string $token): ?array` — constant-time
  fetch + match of file id and size key; null on any mismatch or expiry.

Key material per sealed shape (both are server-custody):

- **Self-sealed container** (`fil_content_sealed`, `specs/implemented/mailbox_attachment_byte_custody.md`):
  the per-file key `DriveSealed::fileKey()` returns. `DriveSealedStream` gains a
  way to be constructed with an explicit key so the streaming serve path can run
  without the vault.
- **Message-DEK attachment** (`ima_is_sealed`, File bytes AEAD-sealed under the
  owning message's DEK): the unwrapped DEK. The AAD
  (`InboundEmailMessage::attachmentAd()`) is reconstructed at serve time from the
  manifest row, as the decrypt hook already does.

How the key gets from the consumer to core without core learning mailbox
internals: alongside the existing `registerDecryptHook` /
`registerStreamingDecryptHook` pair, a consumer may register a **grant key
exporter** for its `fil_source` tag — `fn(File): ?array` — which returns the
shape + key material *iff* the caller's window is open. `FileServeGrant::mint()`
calls it; the mailbox bootstrap registers one for `SOURCE_EMAIL_ATTACHMENT`.
The existing decrypt hooks each accept the redeemed material as an optional
argument and skip their `VaultUnlock` lookup when it is provided.

Mint sites (both in `MailboxService`):

- `resolveInlineImages()` — appends `&grant={token}` to the signed URL when the
  file is sealed. When no grant can be minted (window closed — e.g. a locked
  thread), the URL is emitted as today and serves 423; the reader is already
  showing sealed placeholders in that state.
- `withSignedTransport()` — same for sealed attachment download URLs, which fixes
  the native-client case in the same motion.

Serve site: the `/uploads/*` handler in `serve.php` reads the `grant` query
parameter when `$signed_ok` and hands it through `serve_from_path()` to the
decrypt hooks. **A grant is only ever consulted after the signature check
passes** — it can never widen who may fetch, only whether sealed bytes decrypt.

### Security posture

- The token is a bearer capability for **one file + one size key's plaintext**,
  for the TTL of a URL that is already a bearer capability for the bytes. The key
  material lives in APCu only — the same custody class and store as the unlock
  windows themselves — never the database, never the URL.
- URLs (with tokens) land in access logs; they lapse at TTL like the `sig`
  parameter beside them. This is the accepted trade-off already documented on
  `INLINE_IMAGE_TTL`.
- Client-custody content is out of scope by construction: the exporter is
  registered only by server-custody consumers, and `mint()` returns null for any
  source without one. Drive private-tier E2E files never gain a grant path.
- Grants are minted per page/thread open and not refreshed while idle — an
  expired grant renders broken until the message is reopened, exactly matching
  the existing signed-URL posture.

## Defect 2 — IMAP-ingested inline parts never resolve at all

`resolveInlineImages()` rewrites only manifest rows with `ima_is_inline = true`,
a non-empty `ima_content_id`, **and a non-null `ima_fil_file_id`**. The IMAP
ingest lane (`ImapIngestor::writeManifest()`) stores manifest rows as MIME
locators only (`ima_mime_part`, no `File`), so an inline image in an
IMAP-ingested message keeps its literal `cid:` src forever — the broken icon on
message 101317.

The byte-custody sweep does not close this gap on a sealed mailbox: it
deliberately skips sealed messages (cron holds no window and cannot open sealed
raw), which is why production sealed mail has no adopted Files at all.

### Fix: adopt inline image parts at ingest, while the bytes are in hand

At ingest the raw message has just been fetched from the provider and is in
plaintext in memory — sealing to the owner's vault **public** key needs no
window. So the IMAP store lane adopts inline parts the same way the router
delivery lane already does (`AttachmentByteCustody::adoptOnePart()`: create the
File, seal per-file when the message is sealed, backfill `ima_fil_file_id`):

- Applies to parts that are inline (`Content-Disposition: inline` /
  `Content-ID` present) **and** carry an image type, up to a per-part size cap
  (reuse the custody sweep's cap). Non-inline parts keep today's on-demand
  locator behavior.
- Runs for freshly-stored rows in `storeMessage()` right after
  `writeManifest()`, inside the same transaction posture (a half-adopted
  message must not commit — the D1 rule).

Backfill for existing rows: fold into the mailbox's in-window deferred consumer
(`VaultDeferredWork`, `specs/implemented/in_window_deferred_work.md`) — `has_work`
finds manifest rows that are inline + image-typed + `ima_fil_file_id IS NULL` on
messages whose raw is stored; the drain opens the sealed raw in-window, extracts
the part, and adopts it exactly as ingest now does. Existing broken messages heal
on the owner's next unlocked visit.

## Acceptance

1. On a sealed mailbox, an inline image in a composed-and-sent message renders in
   the conversation view (fresh thread open, no vault interaction beyond the
   normal unlock).
2. Fetching the minted inline URL with **no cookies** returns 200 and the image
   bytes while the grant is live, and 423 after TTL expiry.
3. A grant token replayed against a different file id or size key serves nothing.
4. A sealed attachment's signed download URL works cookie-less (native-client
   path) while live.
5. An inline image in a message ingested from IMAP (including the All Mail
   coverage pass) renders in the conversation view; its manifest row carries a
   `File` id after ingest.
6. A locked thread still renders sealed placeholders with no grant minted — the
   423 path is unchanged when the window is closed.
7. Tests: harness tests in `plugins/mailbox/tests/` covering grant mint/redeem
   (including expiry and mismatch), the ingest-time inline adoption, and the
   deferred backfill predicate. Grant redemption is exercisable in the `db` tier
   without a browser (APCu + direct serve-path call).

## Non-goals

- No change to the sandbox/iframe rendering model or the sanitizer.
- No cookie-based or session-based serving for iframe content (impossible by
  design of the sandbox).
- No grants for client-custody (E2E) files.
- Remote-image proxying/blocking policy is untouched.
