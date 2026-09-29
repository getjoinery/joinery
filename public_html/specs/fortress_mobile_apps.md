# Fortress mail in the phone apps

**Status: INVESTIGATED 2026-09-29, awaiting owner decisions D1–D4. No build
scheduled.** Follows `specs/implemented/client_custody_mail.md` (Fortress:
the mail key lives in the owner's browser, the server stores ciphertext;
its A1 and B58 are this spec's starting point) and takes option C of
`specs/native_vault_unlock.md` (the device holds the key and reads locally)
for mail only. Apps: `ios/joinery-kit` (JoineryMailKit) and
`android/joinery-android-mail`. **Scope (owner, 2026-09-29): the full
build.** Beyond reading, search and compose, the apps get drafts (R12), AI
on the person's own model (R13), mail rules on relay-sealed mail (R14, which
also gives the browser the same) and new-mail notifications (R15, a
platform feature every mailbox gets).

## The problem in plain terms

A Fortress mailbox is one the server cannot read. Today the two phone apps
ask the server for mail and show what comes back, and for a Fortress mailbox
what comes back is ciphertext they ignore: every row reads "(no subject)"
from "(unknown)" with a blank body (B1, the spec's B58). The Fortress card
says "mobile apps are not available", which is true.

For a phone to read Fortress mail it needs two things: the mail key, and
the code to open messages with it. Neither exists in the apps. Both are
possible without passkeys, which is what the earlier investigation
concluded and this one confirms with one correction (§ Corrections).

## What was found

### F1. What a phone must be able to decrypt

Everything is standard: X25519, HKDF-SHA256, AES-256-GCM, gzip. Three
formats, all pinned by `tests/vault/fixtures/edge_vector.json`, which the
Go relay sealer and the browser already test against.

| Thing | Format | Opens with |
|---|---|---|
| The mail key as handed to a device | `base64(ephPub[32] ‖ IV[12] ‖ ct)`; AES key = `HKDF-SHA256(X25519(eph, devicePub), salt '', info 'sealed-vault:dek' ‖ ephPub ‖ devicePub)`; no AAD. Plaintext is the vault's X25519 secret as **PKCS#8** (48 bytes: `302e020100300506032b656e04220420` ‖ 32-byte scalar) | the device's own X25519 secret |
| A row's DEK (`iem_sealed_key`) | `v1.edgeseal.mail.` + the same seal shape, recipient = the mail vault's public key | the mail secret |
| A sealed field | `v1.edge.` + `base64(IV[12] ‖ ct ‖ tag[16])`, AES-256-GCM under the DEK, AAD `mail:{id}:{field}` | the DEK |
| A part's bytes | the field format as the File's content, AAD `mail:{id}:att:{mime_part}` | the DEK |
| `iem_search_text` | a field; `gz:` + base64 gzip when compressed | the DEK, then gunzip |

Every blob is standard base64 with padding. Sizes: a message body is one
AES-GCM box (25 MiB cap); a list page of 50 rows costs 50 key seals and 150
field opens, milliseconds on a phone. A relay row waiting to be parsed
carries the whole message as one field (`iem_relay_sealed_raw`, AAD
`mail:relay:{spool_id}`) under the same kind of DEK.

Sources: `assets/js/vault-crypto.js` (`sealToPublicKey` :338,
`openFromSecretKey` :349), `sync/jd-crypto/src/vault.rs` (the same in
Rust, with the PKCS#8 note), `includes/VaultCrypto.php`
(`sealItemDekToBrowserKey` :89, `openField` :285),
`plugins/mailbox/data/inbound_email_messages_class.php` (`sealedForBrowser`
:1113, AD rules :665–:690).

### F2. What the server already hands a key-holding client

The apps authenticate with an app session key (`apk_type = 'session'`,
minted by `auth/login`). With that credential, today:

- `mailbox/mailboxes` gives each mailbox `security_level` (`fortress`),
  `protection_addons`, `locked` (`MailboxService.php:599–603`).
- `mailbox/thread_list` returns a Fortress thread with `subject`, `sender`,
  `snippet` empty and `sealed: {key, sealed_scope, sealed_dek,
  sealed_ad_prefix, iem_sender, iem_subject, iem_snippet}` (`pending` for a
  relay row not yet parsed), and `fortress: true` on the page (:1380–1433).
- `mailbox/thread` returns each Fortress message with empty clear fields,
  `sealed` carrying the twelve sealed columns (`iem_sender, iem_subject,
  iem_body_plain, iem_body_html, iem_to, iem_cc, iem_recipient, iem_bcc,
  iem_attachment_manifest, iem_ai_summary, iem_ai_scan, iem_raw_headers`),
  flags `pending` / `unopenable` / `foreign`, `fortress: true`, and
  `attachments: [{id, mime_part, size_bytes, inline, url: null}]`
  (`fortressThreadMessage` :1793, `fortressAttachments` :1866). **`url` is
  null on purpose**: the browser fetches part bytes from the session-only
  page `/profile/mailbox/attachment`, which a session key cannot call.
- `mailbox/send` accepts `source_open` (what the client opened of a Fortress
  source, for the quote) and `upload_count`; it answers the sending lock
  with HTTP 200 `{locked: true}` (`send_logic.php:60–86`).
- `thread_list` accepts `device_hits` and `device_only` from any principal.

Sixteen mailbox actions and every vault action carry
`requires_browser_session`, which refuses **any** API key
(`ApiAuth::authorize` :396). The ones a phone would need:
`fortress_pending`, `fortress_parse_store`, `search_entries`,
`relay_seal_target`, `relay_pins`. The flag exists to keep machine keys
out; nothing about these actions needs a PHP session.
`specs/native_vault_unlock.md` already proposed the split
(`requires_unlock_principal`: browser session or session key, never a
machine key).

One device-facing vault action already exists: `vault_client_probe`
(`logic/vault_client_probe_logic.php`, session key allowed) answers
`{set_up, public_key, key_generation}` for a client-custody scope, written
so a native client can "notice a rotation and stop trusting the key it
holds". It does not report a pending key or whether this device is still
listed as holding the scope.

### F3. How a key reaches a device today

Device linking (`/profile/devices/link`) is built and walked for the mail
vault (WP6, 2026-09-28): the browser opens the vault, seals its secret to
the device's X25519 public key inside the keyring session
(`session.sealSecretKeyTo`, `vault-keyring.js:95`), and posts
`sealed_vault_keys: {mail: blob}`; the device collects it from
`GET /api/v1/auth/device_link/{poll_token}` (`ApiAuthEndpoint.php:251`).
The root vault is never handed over: a device gets the content keys it
uses, not the key that opens everything (`devices_link_logic.php` 1.2).

Four things make it a desktop-only path as built:

- **Platforms are `macos`, `windows`, `linux`** (`SyncDevice::platforms()`,
  `sync_devices_class.php:66`); `auth/device_link` refuses anything else
  (`ApiAuthEndpoint.php:196`).
- **It is gated on `drive_active`** (`devices_link_logic.php:34`,
  `drive_device_link_approve_logic.php` descriptor). A mail-only deployment
  cannot link a device.
- **It mints a new credential.** Approval calls `ApiKey::CreateSessionKey`
  and creates a `SyncDevice` row bound to it (:118–129). A phone already
  holds a session key from `auth/login`; the ceremony as built would give
  it a second identity.
- **A device is never told it was forgotten.** A mail-key rotation
  (`VaultClientRotation::forgetScopeOnDevices`, now in
  `includes/VaultClientCustody.php` :853) and a recovery-code
  use (`VaultClientCustody::forgetDevices` :382) clear `sde_vault_scopes`
  and, when nothing is left, `sde_device_pubkey`. The device keeps the
  retired secret and learns nothing; no action re-hands a key to an
  already-linked device (the Rust client has no code for it either, B6).

### F4. What the two apps have

Both surveyed in full on 2026-09-29. Neither has any cryptography beyond
storing the session key, any biometric prompt, hardware-key, passkey or
device-link code, or any word of Fortress handling.

| | iOS (`JoineryMailKit`, 1,888 lines) | Android (`joinery-android-mail`, ~2,080 lines) |
|---|---|---|
| Endpoints | `mailboxes`, `thread_list`, `thread`, `thread_action`, `send` | the same five |
| Parses `fortress` / `sealed` / `locked` | no | no |
| Bodies | HTML in `WKWebView`, JS off, default (shared) data store (`MessageCardView.swift:177–261`); plain as `Text` | HTML in `WebView`, JS off (`MessageCard.kt:148–191`); plain as `Text` |
| Inline images and parts | server-rewritten signed URLs; parts downloaded by `URLSession` to `tmp/`, no file protection, share sheet | signed URLs; `HttpURLConnection` to `cacheDir`, `FileProvider`, `ACTION_VIEW` |
| Compose | To/Cc/Subject/plain body, server quotes; attachments in memory; caps mirrored | the same |
| Drafts | none | none |
| Search | server `q` only | server `q` only |
| Key storage | Keychain, `AfterFirstUnlockThisDeviceOnly`, no access control (`KeychainStore.swift`) | `EncryptedSharedPreferences` on a Keystore master key, no user-auth requirement (`EncryptedCredentialStore.kt`) |
| Byte downloads through the API client | no: every response must parse as JSON | no: same |
| Third-party deps | none; iOS 16 | OkHttp, `security-crypto` (deprecated upstream); minSdk 24 |
| Web handoff from inside mail | none: `MailboxScreen` gets only the API client, not the web coordinator | same |
| Tests | 8 parsing tests on captured fixtures; 2 XCUITest suites; `phase3_gate.sh` | 1 parsing suite; 2 instrumented legs; `member_gate.sh` |

Fixtures are verbatim API envelopes shared between the two test suites
("parity by construction"); none is a Fortress row.

### F5. What each platform's crypto can do

- **iOS 16 (the deployment target):** CryptoKit has `Curve25519.KeyAgreement`
  (X25519), `HKDF<SHA256>`, `AES.GCM`, `HMAC<SHA256>`, `Curve25519.Signing`
  (Ed25519). No dependency needed. HPKE (iOS 17) is not needed. **The
  Secure Enclave holds P-256 keys only**, so the X25519 device key and the
  mail secret cannot live in it. They live in the Keychain as
  `kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly` items with a
  `SecAccessControl` of `.biometryCurrentSet`: the item's class key is
  itself held by the Secure Enclave and released after Face ID or Touch
  ID, and a new enrolled face or finger invalidates it.
- **Android, minSdk 24:** `java.security` has no X25519 before API 33, so
  one library is needed. **Tink** (`com.google.crypto.tink:tink-android`)
  covers X25519, HKDF, AES-GCM and Ed25519 in one small dependency and is
  Google-maintained; BouncyCastle would do the same at four times the
  size. The mail secret is wrapped under an Android Keystore AES-256-GCM
  key created with `setUserAuthenticationRequired(true)`,
  `setUserAuthenticationParameters(timeout, AUTH_BIOMETRIC_STRONG)`,
  `setInvalidatedByBiometricEnrollment(true)`, and `setIsStrongBoxBacked`
  where the device has one; `androidx.biometric.BiometricPrompt` unlocks it.

So the earlier answer's sentence needs one correction: the key is not
sealed **to** a key in the security chip. It is sealed to an X25519 key the
app generates, and that key and the mail secret are then stored **behind**
the chip's biometric gate. The property the user gets is the same: nothing
opens without the owner's face or finger on that phone.

### F6. Bugs found on the way (all ours)

- **B1 (open, = B58):** both apps drop `fortress`, `sealed` and `locked`, so
  a Fortress mailbox shows blank messages and offers Reply on them.
  `MailModels.swift:64–199`, `MailModels.kt:82–234`.
- **B2 (open):** `mailbox/send` answers the sending lock with HTTP 200
  `{locked: true}`; both apps ignore the body and dismiss the compose sheet
  as if sent. `MailAPI.swift:175,191`, `MailApi.kt:123–157`.
- **B3 (open, iOS):** the mail HTML `WKWebView` uses
  `WKWebViewConfiguration()`, whose data store is `.default()`, the store
  that holds the bridged web-session cookie (`WebScreen.swift:124`). An
  `<img>` in a message pointing at the deployment's own origin is fetched
  with the session cookie. It wants `.nonPersistent()` and, for Fortress
  bodies, no remote loads at all. `MessageCardView.swift:183–194`.
- **B4 (open, both):** downloaded parts land in `tmp/` (iOS, no
  `NSFileProtection` attribute) and `cacheDir` (Android) and are never
  deleted. Harmless for Standard mail; for opened Fortress bytes it is a
  plaintext copy at rest. `MessageCardView.swift:138–169`,
  `MessageCard.kt:289–326`.
- **B5 (open, both):** a reply to a Fortress message goes without
  `source_open`, so the server quotes nothing (it cannot read the source).
  Falls out of B1's fix.
- **B6 (open, platform):** a device forgotten by rotation or recovery-code
  use still holds the retired secret and is never told (F3). This spec
  gives phones a status call; the Rust sync client should take the same
  one (`sync/jd-daemon`, not edited here).

## Design

### R1. The phone holds the mail key and opens rows itself

No server window, no unlock ceremony against the server, no passkey. The
phone does what the browser does after `JoinerySealed.session('mail')`
resolves: opens each row's DEK with the mail secret, each field with the
DEK. In the browser that is one call, `JoinerySealed.openDek('mail',
sealedDek)` (the row key's raw bytes), then `VaultCrypto.decrypt` per field:
the pair the phones mirror. `docs/sealed_vault.md` § Client-custody scopes already describes this
as the device handoff's purpose; the desktop sync client works this way for
Drive.

**What the promise becomes on a phone**, stated on the card and in
`plugins/mailbox/docs/overview.md`: the mail key lives on the phone behind
its biometric lock. A stolen phone yields nothing without the owner's face
or finger; a phone the owner unlocks and hands over yields the mail. This
is what Proton and Bitwarden ship and what `native_vault_unlock.md` option C
named as the trade; for mail it is the owner's chosen posture already (the
browser holds the same key, reopenable after a reload).

### R2. Getting the key onto the phone: enrollment bound to the app's own credential

The phone is already signed in. It should not become a second device with
a second credential (the desktop ceremony's shape); it should attach a
device key to the identity it has. **D1** below is that choice.

- **`device_key_enroll`** (new core action, session key only): the app posts
  `{device_pubkey, platform, device_name}`; the server finds or creates the
  `SyncDevice` row for the calling `apk_api_key_id`
  (`SyncDevice::for_api_key`), stores the public key, opens a `DeviceLink`
  row already bound to that key and device (`dlk_apk_api_key_id`,
  `dlk_sde_sync_device_id` set at begin), and answers `{link_code,
  verify_url, poll_token, expires_time}` as `auth/device_link` does. Re-run
  after a rotation or a recovery-code use re-asserts the same public key.
- **The browser page** (`/profile/devices/link`) is unchanged in shape: it
  shows what is asking ("Jeremy's iPhone, iOS"), offers each content vault
  the user holds, opens each chosen vault and seals its secret to the
  device key. `drive_device_link_approve` sees a link already bound to a
  credential and **mints nothing**: it stores the sealed keys, records
  `sde_vault_scopes`, approves. The step-up rule stays.
- **`device_link` poll**, unchanged: the phone collects `sealed_vault_keys`
  (no `public_key` / `secret_key` when none was minted), opens the mail
  blob with its device secret, stores the mail secret (R3), and discards
  the sealed blob.
- `SyncDevice::platforms()` gains `ios` and `android`; the devices list on
  the Security page names them ("iPhone", "Android phone").
- The `drive_active` gate on `devices_link`, `drive_device_link_*` and
  `drive_devices` becomes "the user holds at least one client-custody
  vault" (`VaultScopes::contentScopes()` has one set up). Drive's own
  checkbox still needs `drive_active`.
- **`vault_client_probe` grows two fields**: `pending_public_key` (a
  rotation in progress; a key sealed to it is one this device will be able
  to open only after re-enrollment) and `held_by_this_device` (the
  calling key's `SyncDevice` row lists the scope). The app calls it at
  launch and on foreground: if `held_by_this_device` is false, or the
  vault's public key is not the one derived from the secret it holds, it
  wipes the secret and shows "Open a message on a computer once to hand
  this phone the new key" with an Enroll button. This is B6's fix for
  phones; the Rust client can take the same two fields.

### R3. Where the key lives on the phone and when it is open

- **Generated once**, at first enrollment: the device X25519 keypair.
  Stored biometric-gated (F5). Kept across sign-out so a re-enroll can
  reuse it; wiped on unlink.
- **The mail secret**, stored biometric-gated beside it after enrollment.
  Never written anywhere else, never logged, never in a backup (`iOS:
  ThisDeviceOnly`; Android: `allowBackup="false"` already set, and a
  Keystore-wrapped blob is unusable elsewhere).
- **Open while the app is in front.** Opening the mailbox screen with a
  Fortress mailbox in view prompts for biometrics once; the secret then
  lives in memory. It is dropped when the app has been in the background
  for `N` minutes (default 5, the browser idle lock's spirit), on sign-out,
  on a 401 (`SessionController.signOutLocally` / `sessionInvalidatedHandler`
  gain a "wipe held keys" hook), on unlink, and when `device_vault_status`
  says the key is retired. Dropped means zeroed.
- **Wiping the mail secret does not wipe the device key**, so the next
  enrollment approves the same public key.

### R4. Reading

- **List.** `ThreadSummary` parses `sealed`; when the app holds the key,
  it opens `iem_subject`, `iem_sender`, `iem_snippet` and fills the row.
  Without the key the row says "Encrypted on your other devices" and the
  screen shows one banner (R10). A `pending` row says "Waiting to be opened"
  (R7). `unopenable` and `foreign` say what the browser says.
- **Thread.** `MailMessage` parses `sealed` and `fortress`; the app opens
  the fields, decodes `iem_attachment_manifest` and names the parts from
  it (`id` ↔ `attachments[].id`, name, type, content id, size). A row a
  browser parsed from the relay has no `id` in its manifest; match on
  `mime_part`, as the reader does.
- **Parts.** The server **mints signed URLs for Fortress parts** as it does
  for Private ones (`withSignedTransport`); the bytes served are the stored
  ciphertext, so a leaked URL yields ciphertext, which the row already
  exposed. The app fetches the bytes with a plain HTTP client (both API
  clients refuse non-JSON; a byte path is added to each), opens them with
  the DEK and the part's AAD, and hands the user a file named from the
  manifest. The browser keeps its session page; nothing there changes.
- **Inline images.** `cid:` references stay in the HTML on a Fortress row
  (`resolveInlineImages` skips it). The browser swaps each `cid:` for a
  `data:` URL of the opened bytes (image types only, 5 MB cap,
  `mailbox_fortress.js` `inlineRewrite` :372–389). The apps do the same, or serve the bytes
  through a `WKURLSchemeHandler` / `shouldInterceptRequest`; either keeps
  the bytes off disk.
- **HTML view hardening (B3, both).** The Fortress body view uses a
  non-persistent data store (iOS) / a WebView with no cookies and
  `blockNetworkLoads` (Android) so an opened body can fetch nothing with a
  credential. Remote images in Fortress mail load through the same
  cookie-less path or not at all; the browser's sandboxed iframe already
  sends no cookies.
- **Opened files (B4).** Written under `NSFileProtectionComplete` / to the
  app's private cache with `MODE_PRIVATE`, and deleted when the share sheet
  or viewer returns and at sign-out.
- **Locked state.** With no key in memory (background lock), the list keeps
  metadata and the thread shows placeholders with one Unlock button, as the
  browser does after its idle lock.

### R5. Search on the phone

The server's search cannot see Fortress mail; the browser builds a sealed
word index once per browser (client_custody_mail.md § R5). A phone does the
same, from the same two endpoints: `search_entries` pages the rows' sealed
search text (`order: 'old'` for the first build, `order: 'new'` after a
cursor), and `thread_list` takes the hits as `device_hits` with
`device_only` when every mailbox in view is Fortress. **D2** chooses the
index. The recommendation is to port `mailbox_search_core.js` (529 lines
of pure functions: tokenizer, varint and bitmap codecs, shard build, merge,
query) to Swift and Kotlin and keep its on-disk shape (256 gzip+AES-GCM
shards, `ids`, `meta`, `tail`), because the node gate
`plugins/mailbox/tests/search_index_gate.sh` is then the oracle for all
three implementations and the size numbers (≈52 MB per 100,000 messages)
carry over. The record key is one random 32-byte key per phone, stored
beside the mail secret; a phone never needs `MailboxSearchKey`. First build
runs in the background with the browser's progress line ("Indexing mail on
this phone: 42,000 of 105,000"); "Rebuild" and "Remove from this phone" sit
in the mailbox menu.

### R6. Compose

Send stays plaintext to the server, as B10 of the mail spec settled. Three
things change in the apps:

- A reply or forward of an opened Fortress message posts `source_open`
  `{sender, subject, recipient, body_html, body_plain}`; a forward posts the
  source's parts, opened on the phone, as uploads with the inline ones named
  and keyed by Content-ID, plus `upload_count` (mirror of
  `mailbox_fortress.js` `sourceOpen` :608 and `sourceFiles` :620).
- Reply and Forward are hidden on a placeholder (no key).
- **B2:** a `{locked: true}` answer keeps the sheet open and shows the
  message; the sending lock is a browser ceremony, so the sheet says to send
  from a computer.

No drafts: the apps have none, so the browser's sealed two-step draft save
is not needed. If drafts ever come to the apps they follow WP4's shape.

### R7. The relay path

With **Seal at the relay**, a message arrives as a pending row: the whole
RFC 822 sealed under a DEK, parsed by the first device that opens it, which
posts the parsed sealed fields and parts to `fortress_parse_store` (one
`bundle` upload with each part at an offset, B37). A phone can do this only
with a MIME parser: the browser's is `mailbox_mime.js` (698 lines, gated by
`mime_parser_gate.sh` over fixtures in `plugins/mailbox/tests/fixtures/mime/`).
**D3** decides whether the port is in the first build. Until it is, the
phone shows a pending row as "Waiting to be opened on a computer" and
refreshes; a computer's next visit parses it and the phone reads the result.

**The relay pin** (§ R10 of the mail spec) is a check every key-holding
device makes: verify the relay's signed statement (Ed25519) and the pin's
MAC (`HKDF(secret, 'sealed-vault:pin')`). The phone can verify and alarm;
**Trust** needs a passkey step-up, so the phone's alarm says to approve the
new relay from a computer. Ships with D3's package.

### R8. Server changes

- **One auth flag**, `requires_person_credential`: admits a browser session
  or an `apk_type = 'session'` key, refuses a machine key. This is the
  `requires_unlock_principal` of `native_vault_unlock.md` under a name that
  says what it admits rather than what it is for. Moved to it:
  `mailbox/fortress_pending`, `fortress_parse_store`, `search_entries`,
  `relay_seal_target`, `relay_pins`; for R13 `ai_device_recipes`,
  `device_ai_entries`, `device_ai_verdict`, `ai_device_record`,
  `device_ai_test_prompt`; for R14 `mailbox_filters` (read). Everything
  else keeps `requires_browser_session`, in particular `vault_client_status`
  (the unlock material), `device_ai_host` (a step-up) and every unlock,
  setup, rotation, custody-change, resume and pin-set action. A test
  asserts the moved list is exactly these eleven and that a machine key is
  refused at each.
- `device_key_enroll` and the two `vault_client_probe` fields (R2);
  `drive_device_link_approve` and the poll handling a pre-bound link;
  platforms; the vault-based gate.
- Signed URLs for Fortress parts (R4); `thread` descriptor documents it.
- `mailbox_reader_fortress_visible` is unaffected; the web reader keeps
  every path it has.

### R9. Revocation and rotation

- Revoking the phone's app session at `/profile/security` (or unlinking the
  device) ends its API access; the app's 401 path wipes the mail secret.
  One row, one gesture, as `native_vault_unlock.md` promised.
- A mail-key rotation forgets the scope on every device; the phone learns
  it from `device_vault_status`, wipes, and asks to be enrolled again. A
  recovery-code use does the same. Both leave the phone's device key in
  place so the re-enrollment approves the same public key.
- Signing out of the app wipes the mail secret and the search index's key
  (the sealed index stays and reopens after the next enrollment, as the
  browser's does after a lock).

### R10. Cards and copy

- Fortress card, third line: "Team features are not available." (the phone
  clause goes when WP5 ships on both apps). The card's app note: "Your
  phone reads this mail once you hand it the key from a computer; the key
  stays behind your phone's face or fingerprint lock."
- In the app, a Fortress mailbox without the key shows one banner: "This
  mailbox is end-to-end encrypted. Hand this phone the key from a computer
  where your mail is open." with **Enroll** (shows the code and the page to
  open) and nothing else different.
- `docs/mobile_apps.md` gains a "Fortress mail" section per module;
  `plugins/mailbox/docs/overview.md` § Fortress replaces its phone-apps
  sentence. Current state only.

### R11. What the phone never does

Set up or unlock the vault against the server, rotate a key, approve
another device, change a level, run the lowering walk, trust a new relay,
or register where its AI model lives (R13: that is a step-up on a
computer). Passkeys stay web-managed, as `docs/mobile_apps.md` says. Every
protection-level change on the platform (Drive folders, mail domains and
mailboxes, chats) runs through `ProtectionLevelChange` and answers an API
caller with `requires_stepup` until the person confirms with a second
factor (the browser's `JoineryPasskeys.stepUp`); an app that ever reaches
one shows that refusal and points to a computer.

### R12. Drafts, at every level

The apps have no drafts today; a half-written reply lives only while the
compose sheet is open. Both apps get drafts the way the browser has them,
and the Fortress shape falls out of the same code.

- **A Drafts view** in the mailbox menu: `thread_list` with `drafts: true`
  (each draft is its own singleton row); `mailboxes` already returns a
  `drafts` count per mailbox for the badge. Opening one restores the
  compose sheet from `draft_get`; swiping one away is `draft_delete`.
- **Autosave** three seconds after the last edit and on leaving the sheet,
  as `mailbox_reader.js` does (`autosaveDraft` :4389; the save on leaving
  the page is `joineryApi.postForm` with `keepalive`, so it outlives the page). A send deletes the draft after the
  Sent row is stored; a cancelled send keeps it.
- **Standard and Private mailboxes:** `draft_save` with the plain columns,
  attachments as uploads; the server seals a Private draft as it does today.
- **Fortress mailboxes:** the browser's two-step save
  (client_custody_mail.md § WP4 As built). The first save posts the clear
  columns and gets `{draft_id, id, sealed_ad_prefix}`; every save after
  posts `sealed_dek` (one DEK for the draft's life, sealed to the vault's
  current or pending public key from `vault_client_probe`), `public_key`,
  `fields` (the eleven columns of `MailboxDrafts::FORTRESS_DRAFT_FIELDS`,
  each `v1.edge.` under the DEK with AD `mail:{id}:{field}`), `parts` and
  `keep`. A new attachment is sealed on the phone under the draft DEK with
  AD `mail:{id}:att:{part}`, named `draft:` + 24 hex, and posted as its own
  request after the text step (the browser's B7 shape: text first, one
  request per new part, failures shown). `keep` is authoritative. The
  server never opens the draft; the phone opens `draft_get`'s sealed shape
  and its parts with the DEK it holds, and re-posts the parts it holds with
  the send plus `draft_id`. A draft from a mailbox of the other custody is
  refused by the server and the phone starts a new one, as the browser does.
- **Lock.** The background lock (R3) saves an open Fortress compose first,
  then clears the sheet and drops the DEK with the mail secret (the
  browser's B14). Reopening asks for biometrics and restores from
  `draft_get`.
- The apps compose plain text only, so `draftinl:` inline parts do not
  arise; the parser accepts them in a draft made on a computer and shows
  them as attachments.

### R13. AI on the phone, against the person's own model

`specs/implemented/fortress_mail_device_ai.md` gives Fortress mail a summary
per message and the security scan by having the **browser** send opened text
to a model the person owns (their Ollama, or a hosted endpoint under their
key) and seal the verdict back under the row's DEK. The server pages the
work and stores ciphertext. A phone can do exactly this over the network;
what it cannot do is run the model.

- **Where the endpoint lives.** The base-URL origin is registered with the
  account under a step-up (`MailboxDeviceAiHost`, set on the Email settings
  page on a computer; the phone reads it from `ai_device_recipes` or a
  small addition to `mailboxes`). The key, the path and the model name are
  per device, as they are per browser (`localStorage jy_device_ai`): entered
  once on the phone, stored beside the mail secret behind the biometric
  gate. The platform's own model key never reaches a phone.
- **Reachability.** An Ollama on a home machine is reachable from a phone
  only on the same network or over a tailnet; a hosted endpoint always.
  The settings screen has a Test button (`device_ai_test_prompt`) that
  reports which step failed, as the browser's does. A "Wi-Fi only" switch
  (default on) keeps message bodies off cellular.
- **The drain.** While the mailbox screen is open and the key is held, and
  never in the background (an OS-scheduled run is not the person present,
  the rule R3 and `native_vault_unlock.md` already state): for each
  device-capable recipe from `ai_device_recipes {mailbox}`, page
  `device_ai_entries` (100 a page: `{id, sealed_dek, sealed_ad_prefix, auth
  results, received_time, sealed: {sender, subject, bodies, raw_headers,
  manifest}}`), open each, build the digest and the messages from
  `system_blocks` exactly as the browser's `assets/js/email-digest.js` and
  `verdict-check.js` do (both ported, with their fixtures as tests), call
  the endpoint (OpenAI-compatible chat completions, Bearer key, JSON
  response format, `max_tokens` from the recipe), validate the verdict,
  seal `iem_ai_summary` / `iem_ai_scan` under the row DEK with the row's
  AD, post `device_ai_verdict {id, recipe_id, fields, danger_score,
  scan_time}`. A reply that fails validation after one retry posts
  `ai_device_record {recipe_id, item_key}` (error); a transport failure
  posts nothing and the item is offered again. One drain at a time per app
  process (the browser's Web Lock becomes an in-process lock).
- **Showing it.** The list opens `iem_ai_summary` into the preview line;
  the thread opens `iem_ai_scan` into the danger banner naming the model,
  with `ai_danger_score` and `ai_scan_time` clear as on Private. A verdict
  a computer produced shows on the phone and the other way round: the
  `done` log row is per recipe and item, so two devices never judge one
  message twice.
- Only `EmailTriageJob` and `EmailSecurityScanJob` are device-capable; the
  phone runs whatever `ai_device_recipes` returns and nothing else.

### R14. Mail rules on relay-sealed mail, on whichever device opens it

Today a rule (`ief_inbound_email_filters`: match on from, to, subject,
has-words, excludes, size, has-attachment; act with label, star, read,
archive, spam, never-spam, forward, delete) runs on the server as mail
arrives. On the relay path at Fortress the server has no plaintext, so the
mail spec's card says rules do not run there (client_custody_mail.md § R9,
"rules are not evaluated in the browser in this build"). This spec builds
the evaluation into the parse step, on the browser and the phone alike, so
the sentence comes off the card.

- **Where it runs.** The device that parses a pending row
  (`fortress_pending` → parse → `fortress_parse_store`) evaluates the
  mailbox's rules on the parsed plaintext first. Rules come from
  `mailbox_filters` for the alias and its domain, enabled only, in
  `ief_order`; the match logic is a port of
  `InboundEmailFilter::matches()` (:290): sender, subject, bodies from the
  parse, recipient and size from the row's clear columns, has-attachment
  from the parsed parts. Both ports (JS and the two phones) run the same
  fixture cases as `plugins/mailbox/tests/` gives the PHP matcher.
- **Posting the outcome.** `fortress_parse_store` gains `rule_outcomes`:
  the accumulator `runForMessage()` produces today (`{never_spam,
  mark_spam, label_ids, star, mark_read, archive, delete, forward_to,
  matched_filter_ids}`). The server applies the flag and label outcomes to
  the row in the same transaction as the parse, checking authorization,
  not truth: every id in `matched_filter_ids` is an enabled rule of that
  mailbox or its domain, every label id is the caller's, `forward_to`
  addresses are those rules' acknowledged destinations
  (`ief_forward_ack_*`). The device is the only reader of the message, so
  its evaluation is the evaluation; the server's job is to refuse an
  outcome no rule of that mailbox could have produced.
- **Forwarding.** A forward rule needs the message to leave; the server
  cannot build it from ciphertext. The device posts the opened RFC 822
  bytes with the outcome (`forward_raw`, bounded by the message size), and
  the server hands it to `MailboxSender`'s forward path and keeps nothing,
  as any send. A device that cannot post the raw (a phone on the Wi-Fi-only
  switch) leaves the forward out and the row is marked
  `iem_rule_forward_pending`, which the next device to open the thread with
  a network settles. Mail rules never run twice on a row: the parse is
  idempotent (B11 of the mail spec) and so are its outcomes.
- **Apply to existing** (`ief_apply_existing_pending`) on Fortress rows:
  `ApplyInboundEmailFilters` skips browser-sealed rows today and keeps
  doing so. New action `mailbox/rule_backlog {filter_id, after_id}` pages
  the caller's Fortress rows the rule has not been applied to (the
  `device_ai_entries` shape: sealed sender, subject, bodies, manifest), the
  device evaluates and posts `mailbox/rule_outcomes {rows: [{id,
  outcomes}]}`; the cursor `ief_apply_existing_cursor` advances as it does
  for the server walk. Runs from the same drain as R13, after it.
- **Card and docs.** The relay add-on's cost line at Fortress becomes "New
  mail is opened on your device, and your mail rules run there when it is
  opened." Contact elevation on this path stays out (the server has no
  sender to look up; recorded, not built).

### R15. New-mail notifications, content-free

Neither app has push notifications for anything, and the platform has no
push infrastructure (`docs/notifications.md` covers the in-app bell and
email; `specs/implemented/ios_app_platform.md` and the Android one list
push as deferred). This is a platform feature: every mailbox gets it, and
Fortress mail rides along with nothing in the notification but the fact of
arrival.

**The constraint that shapes it.** A push to an iPhone is sent with the app
publisher's APNs key; a push to an Android phone with the app's Firebase
project. Both belong to whoever ships the binary, which is Joinery, not the
self-hosted deployment. A deployment cannot send pushes for the Joinery app
on its own, and shipping the APNs key to every deployment would let any one
of them push to every user of the app. This is the passkey domain problem
in another coat, and the industry answer is the same one Bitwarden ships
for self-hosted installs: **a push relay run by the app publisher**, which
deployments hand a token and an opaque payload. **D4** is whether to run
one.

- **What the server knows.** Mail arrives in `storeMessage`,
  `storeDirectMessage` and, on the relay path, `storeRelayPending`. Each
  emits one signal, `mailbox.message_arrived {alias_id, message_id,
  level}`, after commit (the signal bus, `docs/signals.md`). A push
  subscriber turns it into one nudge per registered device of the
  mailbox's holders that opted in to that mailbox.
- **Device tokens.** New core model `PushDeviceToken`
  (`pdt_push_device_tokens`: user, `apk_api_key_id`, platform, token,
  `client_app`, mailboxes opted in, create and last-seen times, failure
  count). `push_register` (session key only) upserts by key; sign-out and
  revocation delete the key's tokens (a revoked key's tokens go with it,
  the `SyncDevice` cascade pattern). A machine key can never register.
- **The nudge carries nothing readable.** Payload `{site: <deployment
  id>, ref: <opaque per-token alias handle>}`: no address, no user, no
  sender, no subject, at any level. The relay learns that a deployment
  nudged a token at a time and nothing else. The phone maps `ref` to a
  mailbox from its own cached `mailboxes` list.
- **What the phone shows.** iOS: a visible push with `mutable-content`; a
  Notification Service Extension rewrites it to "New message in
  {address}" and, for a **Standard** mailbox, fetches the newest row and
  shows sender and subject. Private and Fortress show the mailbox only:
  the phone's session key opens no server window, and the Fortress key is
  behind the biometric gate and not held in the background. Android: an
  FCM data message; the app's messaging service builds the same
  notification. Tapping opens that mailbox. Badge = unread from
  `mailboxes`.
- **Fallback when there is no relay** (setting `push_relay_url` blank, or
  the relay unreachable): the apps poll. iOS `BGAppRefreshTask`
  (opportunistic, minutes to hours apart, at the OS's discretion), Android
  `WorkManager` periodic work (15 minutes at best). The app compares
  `mailboxes` unread counts and posts the same local notification. Honest
  wording in settings: "Without the relay, new mail shows when your phone
  next checks, which it decides."
- **Preferences.** Per mailbox, on the phone (which mailboxes notify) and
  stored with the token so the server nudges only for those. Quiet hours
  are the phone's own Focus and Do Not Disturb.
- **The relay path.** A relay-fronted message becomes a pending row at the
  next pull, so the nudge fires at pull time; the pull interval bounds the
  delay, as it does for arrival itself.
- **Not built:** notifications for anything but mail arrival (calendar,
  messenger) ride the same signal-to-push subscriber later; the subscriber
  is generic, the signal is mail's.

## Decisions for the owner

**D1. How the phone becomes a key-holding device.**

- **Bind to the app's existing session key (recommended).** `device_key_enroll`
  attaches a device key to the credential the phone already has; approval
  mints nothing. One row per phone on the Security page; revoking it kills
  API access and the key at once; no re-login. Catch: two new actions and a
  branch in the approve and poll handlers.
- **Run the desktop ceremony as built.** The phone gets a second credential
  and a second Security-page row; the app must swap credentials and revoke
  the login one. Catch: two identities for one phone, and the swap is a
  place to lose a signed-in user.

**D2. The search index on the phone.**

- **Port the browser's shard core (recommended).** Same tokenizer, codecs
  and on-disk shape on web, iOS and Android; the existing node gate is the
  oracle; sealed at rest by construction; no dependency. Catch: two ports
  of ~530 lines and their unit tests against the gate's vectors.
- **SQLite FTS5 on the phone.** Native, less code. Catches: FTS5 is not
  guaranteed in every Android system SQLite at minSdk 24 (a bundled SQLite
  is a large dependency); the index is plaintext in the app container
  unless SQLCipher is added; a second tokenizer whose results can differ
  from the browser's.

**D3. The relay path in the first build.**

- **Ship reading and compose first; the MIME parser port and the pin check
  follow as WP7.** A Fortress mailbox with Seal at the relay shows arriving
  mail as "Waiting to be opened on a computer" until then. Catch: a
  phone-only user of a relay-fronted mailbox sees nothing new until they
  open a computer.
- **Include WP7 in the first build.** Nothing waits. Catch: the two MIME
  parser ports (~700 lines each) and their fixtures land before any user
  can read a message on a phone.

**D4. How a self-hosted deployment reaches a phone (R15).**

- **A Joinery-run push relay, with polling as the fallback (recommended).**
  Deployments post `{token, payload}` to `push.getjoinery.com`, signed
  with their Joinery Direct identity; the relay holds the APNs key and the
  Firebase credentials and forwards. Payloads are content-free by design,
  so the relay learns only that a deployment nudged a token. This is the
  shape Bitwarden ships for self-hosted installs. Catches: one more service
  Joinery runs and must keep up (the mail relay pattern applies:
  disposable, provisioned by script, never agented); a deployment that
  turns it off gets polling; a de-Googled Android phone has no FCM and
  gets polling.
- **Polling only.** No relay, nothing new to run. Catch: "new mail" arrives
  when the phone next wakes the app, minutes to hours later on iOS, at
  least 15 minutes on Android; for a mail app that is not a notification.
- **Per-deployment app builds** are not viable for the reason passkeys are
  not: every self-hoster would need Apple and Google developer accounts.

## Work packages

Server first, then shared test vectors, then the two apps in parallel.
Each is testable alone. The browser is untouched throughout.

### WP0. Server: credential admission and enrollment

- `requires_person_credential` in `ApiAuth::authorize`; the six actions
  moved (R8).
- `logic/device_key_enroll_logic.php`; `vault_client_probe` 1.1 with
  `pending_public_key` and `held_by_this_device`; `DeviceLink` rows bound
  at begin; `drive_device_link_approve` and the poll honouring a bound
  link; `SyncDevice` platforms; the vault-based gate.
- Signed URLs for Fortress parts.
- **Tests:** `tests/functional/api/person_credential_test.php`: a session
  key reaches each moved action, a machine key is refused, the list is
  exactly the five. `tests/functional/drive/sync_contract_test.php` (it
  already covers link info, deny, approve with `sealed_vault_keys`, scrub
  and the probe) gains: enroll binds to the caller's key, approval mints
  nothing and stores the sealed keys, the poll returns them once,
  re-enroll reuses the row, the probe reports the scope no longer held
  after `forgetScopeOnDevices`, a phone platform is accepted and a
  made-up one refused. `fortress_ingest` (which carries the reader-API
  checks) gains the signed-URL case.

### WP1. Shared vectors

- `tests/vault/fixtures/edge_vector.json` already covers the DEK seal and a
  field. Add `device_handoff_vector.json` (a device keypair, a sealed mail
  secret, the expected PKCS#8 plaintext) and a full Fortress row fixture
  (`thread.json` / `thread_list.json` Fortress variants captured from dev),
  used by both app test suites.

### WP2. iOS: key custody

- `JoineryKit`: `DeviceKeyStore` (biometric-gated Keychain items),
  `VaultCrypto` (X25519 + HKDF + AES-GCM over CryptoKit, PKCS#8 decode),
  `DeviceEnrollment` (enroll, show code, poll, open, store),
  `HeldKeys` (in-memory secret with the background timer, the wipe hooks on
  sign-out and 401).
- **Tests:** the vectors of WP1 open; a wrong recipient key fails; the
  timer drops the secret; sign-out wipes.

### WP3. iOS: read and compose

- `MailModels` parse `fortress`, `sealed`, `locked`, `mime_part`, `inline`;
  `FortressOpener` fills rows and messages; parts via signed URLs and a
  byte path in `APIClient`; `cid:` scheme handler; the hardened body view
  (B3); protected temp files (B4); `source_open` and forward parts (B5,
  R6); the locked answer (B2); the banner and Enroll flow (R10).
- **Tests:** parsing tests over the Fortress fixtures; an XCUITest leg in
  `phase3_gate.sh`: enroll the simulator from a Playwright-driven browser
  approval (`walk_fixture.php` makes the Fortress user), read a seeded
  message and its PDF, reply, lock and unlock.

### WP4. Android: key custody, read and compose

- `joinery-android`: `DeviceKeyStore` (Keystore AES key, `BiometricPrompt`),
  `VaultCrypto` over Tink, `DeviceEnrollment`, `HeldKeys`;
  `joinery-android-mail`: the WP3 items with `shouldInterceptRequest` for
  `cid:` and a cookie-less body WebView.
- **Tests:** JVM tests over the same fixtures; a `member_gate.sh` leg
  mirroring WP3's.

### WP5. Search on the phone (D2)

- Ports of `mailbox_search_core.js` to Swift and Kotlin; the fetch loop
  from `search_entries`; `device_hits` / `device_only` on `thread_list`;
  progress, Rebuild, Remove.
- **Tests:** each port replays the node gate's cases (tokenizer, codecs,
  merge equals build, query equals brute force) from a vectors file the
  gate writes; a gate leg indexes the seeded mailbox and finds a body word
  and an attachment name.

### WP6. Cards, docs, the Fortress card clause

- `ProtectionLevelPicker` copy (R10), `docs/mobile_apps.md`,
  `plugins/mailbox/docs/overview.md`, `docs/sealed_vault.md` § Devices
  (enrollment bound to a credential, the status call).

### WP7. The relay path on the phone (D3)

- MIME parser ports with the `mime_parser_gate.sh` fixtures as their
  tests; `fortress_pending` drain and `fortress_parse_store` bundle post;
  the relay pin check and alarm (verify only).

### WP8. Drafts (R12)

- Both apps: the Drafts view, autosave, restore, delete; the plain save
  for Standard and Private; the two-step sealed save, sealed parts and
  `keep` for Fortress; the lock-saves-first rule; `draft_id` on send.
- **Tests:** parsing tests over `draft_get` fixtures in both shapes; a gate
  leg on each platform: type a reply with an attachment, leave the sheet,
  see the draft in Drafts and in psql as ciphertext only (Fortress), reopen
  it with the attachment, send, see the draft gone.

### WP9. AI on the phone (R13)

- Server: the five AI actions moved to `requires_person_credential`
  (WP0's flag); the registered origin readable to a key principal.
- Both apps: the endpoint settings screen (key, path, model, Wi-Fi only,
  Test); ports of `email-digest.js` and `verdict-check.js` with their
  fixtures; the drain; summary and banner rendering.
- **Tests:** digest and verdict ports replay the browser fixtures; a gate
  leg against a stand-in endpoint on the Mac mini (the pattern of the
  browser's `selfCheck` stand-in): one message judged, `iem_ai_summary` and
  `iem_ai_scan` on the row as `v1.edge.`, the `done` log row present, a
  second run judges nothing.

### WP10. Mail rules on relay-sealed mail (R14)

- Server: `rule_outcomes` and `forward_raw` on `fortress_parse_store`, the
  authorization checks, `iem_rule_forward_pending`; `mailbox/rule_backlog`
  and `mailbox/rule_outcomes`; `mailbox_filters` readable to a key
  principal; the card line.
- Browser: `mailbox_fortress.js` evaluates rules in `drainPending()`
  before the store (a port of `matches()` to JS with the PHP fixtures as
  its gate), and runs the backlog walk from the AI drain's place.
- Both apps: the same port and the same two calls.
- **Tests:** `fortress_relay_pull_test.php` gains: outcomes applied in the
  parse transaction, an outcome naming another mailbox's rule refused, a
  label not the caller's refused, a forward to an unacknowledged address
  refused, a forward raw sent and nothing kept; the JS and phone matchers
  replay the PHP matcher's fixtures; a gate leg delivers a relay-sealed
  message matching a label rule and sees the label on the row after the
  phone parses it.

### WP11. Notifications (R15, D4)

- **Platform:** the `mailbox.message_arrived` signal from the three store
  paths; `PushDeviceToken` and `push_register`; the push subscriber and the
  relay client (Direct-identity-signed POST, `push_relay_url` setting,
  failure backoff, token retirement after repeated rejections); token
  deletion on sign-out and revocation.
- **Relay service** (`push.getjoinery.com`): a small Go program in the
  mail relay's mould (`provisioning/`, a `build.sh`, provisioned by
  script): verifies the deployment signature, forwards to APNs (token
  auth, HTTP/2) and FCM (HTTP v1), records nothing but counters. Its
  credentials are the App Store team's APNs key and the Firebase project
  the Android release spec creates.
- **iOS:** `aps-environment` entitlement, registration, the Notification
  Service Extension, `BGAppRefreshTask` fallback, per-mailbox settings.
- **Android:** Firebase Messaging, the messaging service, `WorkManager`
  fallback, per-mailbox settings.
- **Tests:** `tests/functional/api/push_register_test.php` (upsert by key,
  machine key refused, tokens gone after revocation); a subscriber test
  that one arrival nudges each opted-in token once with a content-free
  payload; the relay's Go tests (signature, a changed byte refused, APNs
  and FCM request shapes against recorded fixtures); a gate leg per
  platform on the Mac mini: the simulator or emulator registers, a seeded
  message produces the notification with the mailbox address and, for
  Standard, the sender and subject.

## Corrections to the earlier answer

The pasted assessment stands on every point that matters: passkeys are
blocked in the apps by the per-domain rule and Fortress does not need them;
device linking is the route; the apps hold nothing yet. Three details:

1. "Sealed to a key that lives in the phone's security chip." The handoff
   seals to an X25519 key, which neither the Secure Enclave nor StrongBox
   can hold. The key and the mail secret are stored behind the chip's
   biometric gate instead (F5). Same user-facing property, different
   mechanism, and the spec must say the true one.
2. "Built and tested: the linking step." True for a desktop client: the
   ceremony refuses the platforms `ios` and `android`, requires Drive to be
   on, and mints a second credential (F3). Small changes, but changes.
3. "A copy of existing work in two app languages." Mostly. Two pieces are
   ports of browser modules with their own test gates (the search index
   core, the MIME parser), and one piece is new on every surface (a device
   learning it was forgotten, B6).

## Size, for planning

What the phones mirror: `vault-crypto.js` 494, `mailbox_fortress.js` 1,473,
`mailbox_search_core.js` 529 (+ 846 of page and worker glue the phones do
not need), `mailbox_mime.js` 698, `email-digest.js` and `verdict-check.js`
(R13), and `InboundEmailFilter::matches()` (~70 lines of PHP, R14). The
mail kits today: iOS 1,888, Android ~2,080 lines. Server work for WP0–WP10
is small: three actions, one flag, one branch in approve and poll, signed
URLs for one attachment shape, `rule_outcomes` on the parse store. WP11 is
the one large server item: a signal, a token model, a subscriber, a relay
client, and a Go relay service with its own provisioning.

Order, if built as listed: WP0–WP6 give a phone that reads, searches and
replies; WP7 the relay path; WP8 drafts; WP9 AI; WP10 rules; WP11
notifications. WP8, WP9 and WP11 are independent of one another once WP0
and the key custody of WP2/WP4 exist, so they can run in parallel.
