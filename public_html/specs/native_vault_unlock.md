# Native vault unlock — credential-keyed windows and on-device passkey ceremonies

**Status: DEFERRED 2026-08-06 — analysis complete, no build scheduled.**

The goal stands: a native client should be able to browse and open Private
content, with the strongest presence proof available. The investigation that
followed found that *the strongest available proof is not a passkey*, for
reasons outside anyone's control (see **The WebAuthn wall**), and that the
remaining options each carry a structural trade rather than a build cost. That is
a decision worth taking slowly, so it is parked here in full rather than made in
passing.

**Near-term direction instead: make the bridged webview path correct.** Private
content already works in the app's webview — it carries a real session cookie, so
the window resolves exactly as it does on desktop. The known gaps in that path are
listed under **The webview path** below and are small, self-contained work. This
spec resumes when native-first Drive is actually wanted.

Supersedes decision D7-a in `specs/implemented/drive_private_tier_defects.md`.

## Intent

A member with the app on their phone taps a Private file and it opens — after a
Face ID / fingerprint prompt, on that device, against a passkey that never leaves
it. Nothing about the bytes changes; what changes is that a native client can
*prove presence* the same way a browser tab can.

Today it cannot, and the reason is one line: the unlock window is stored under
`vault:{php_session_id}:{user}:{scope}`, and a native client has no PHP session.
Every consequence — the 423 on a signed URL, `requires_browser_session` on every
vault endpoint, `PasskeyService` refusing to run a ceremony — follows from that
one binding.

**This spec generalizes the binding rather than working around it.** The window
is keyed to a *credential*, of which a browser session is one kind and a device's
app session key is another. That is a platform change, not a Drive change: mail,
chat and the password vault inherit native unlock the day it lands.

## What exists, and what this rides on

Verified in the tree, because the design turns entirely on how session-shaped
these pieces already are:

| Piece | Where | State |
|---|---|---|
| Window store | `VaultUnlock::apcuKey()` — `vault:{sid}:{user}:{scope}` | `$sid` is an **opaque string**. Nothing reads it. |
| Window metadata | `VaultUnlock::metaKey()`, same shape | Same. |
| Wipe-all sweep | `VaultUnlock::lockAll()` regex `/^vault(?:meta)?:[^:]*:{user}:[^:]*$/` | **Already wildcards the session segment.** Works unchanged. |
| Cross-session presence | `hasAnyOpenWindow()` → `/dev/shm/vault_window_{user}_{scope}` | Keyed on user+scope only. Works unchanged. |
| Passkey challenge store | `pks_passkey_ceremonies.pks_session_id`, `varchar(128)` | **Already a database table**, not `$_SESSION`. Keyed by an opaque string. |
| Ceremony principal | `PasskeyService::_sessionId()` | The single place that demands `session_id()` and throws without one. |
| Window resolution | `VaultUnlock::secretKey()` → `currentSessionIdOrNull()` | The second place. |
| Endpoint gate | `'auth' => ['requires_browser_session' => true]` on 20 `logic/vault_*` + `logic/passkey_*` actions | The declared boundary. |
| Device identity | `sde_sync_devices` (`sde_apk_api_key_id`, `sde_device_pubkey`) | The desktop sync clients' model. The shape to reuse. |
| App session keys | `apk_api_keys`, `apk_type = 'session'`, minted by `auth/login` | Already device-scoped, already revocable per device at `/profile/security`. |

The important finding: **only two functions actually require a browser session.**
Everything downstream of them already treats the session id as an opaque
principal. This is a narrow change with a wide payoff.

## The change: windows are keyed to a credential, not a session

Introduce one concept — the **unlock principal** — and give it two spellings:

```
sess:{php_session_id}     a browser tab (and an app webview, which is one)
apk:{api_key_id}          a device holding an app session key
```

`VaultUnlock::currentPrincipal()` returns whichever applies to the request, and
every existing `$sid` parameter becomes that string. `pks_session_id` is renamed
`pks_principal` (same type, same index) so the column stops lying about what it
holds. No storage layout changes; no APCu key format change beyond the prefix
that is now part of the value.

**A machine key is never a principal.** `apk_type = 'machine'` returns null and
therefore can never hold a window — the load-bearing half of today's
`requires_browser_session` rule, kept exactly. An integration credential sitting
in a config file must not be able to decrypt a member's content, and this is the
line that stops it. `tests/functional/api/session_keys_test.php` already treats
that boundary as load-bearing; it gains cases for this.

The endpoint declaration splits accordingly:

- `requires_browser_session` — keeps its literal meaning, and stays on the
  actions that genuinely need a browser (passkey *registration*, which the app
  does through the bridge anyway).
- **`requires_unlock_principal`** (new) — admits a browser session **or** a
  session key, refuses machine keys. Every `vault_unlock_*`, `vault_status`,
  `vault_lock`, `vault_heartbeat` moves to this.

## The WebAuthn wall

Two facts, together, close the door on platform passkeys for a shipped app that
serves deployments on their own domains:

1. **A passkey binds to an RP ID, and an app may only assert for domains named in
   its Associated Domains entitlement — which is fixed when the app is signed.**
   One App Store binary cannot assert for `members.someones-site.org` unless that
   host was known at build time. Per-deployment app builds would solve it, and are
   not viable: they require every self-hoster to hold Apple and Google developer
   accounts and ship their own binary.
2. **The webview cannot stand in.** `docs/mobile_apps.md` already records it —
   "WKWebView cannot expose platform WebAuthn" — which is why passkey and vault
   management are web-managed in both shipped clients today. So the fallback of
   running the ceremony in the bridged webview is closed too.

This is a platform constraint, not a budget one. No amount of build effort makes
one signed binary assert a passkey for a domain it has never heard of.

**What survives is the property, not the mechanism.** What a passkey buys the
vault is: a secret that lives in hardware, cannot be exported, and is released
only after user verification. A Secure Enclave / StrongBox key with a biometric
access-control policy has those same three properties. What it lacks is
WebAuthn's origin binding — an anti-phishing measure for browsers, where the user
chooses the destination. A native client does not choose: it talks to the
`baseURL` compiled into it, so there is no origin to be fooled about.

### The three ways forward

**Option A — a Joinery-operated hostname per deployment.** Every install also
answers on `tenant.joinery.app`; the app talks to that name; the shipped binary
carries `webcredentials:*.joinery.app`. Real passkeys, one binary, no customer
developer accounts.

- Joinery must run the zone, issue and renew a certificate per deployment, and
  stay up for app unlock to work.
- The member's own domain and the platform hostname are different RP IDs, so a
  passkey enrolled on the website does not unlock in the app. The vault already
  supports many unlockers, so this is enrollment friction rather than a
  structural break — but it is friction on the most security-sensitive flow.
- It puts a Joinery-controlled name in the middle of every self-hosted install,
  which is in tension with sovereignty being a precondition rather than a
  feature.

**Option B — a device-bound hardware credential as a new unlocker type
(recommended).** The app generates a non-exportable P-256 keypair in the Secure
Enclave / StrongBox, with access gated on biometry-current-set, and enrolls its
public half as a vault unlocker. Unlock is a challenge-response: the server hands
the device its wrapping, the device performs a biometric-gated key agreement to
recover the KEK, the server unwraps the vault secret and opens the window. The
device transiently handles a KEK; it never holds the vault secret, and nothing is
exportable from the enclave.

- Works against any hostname. One binary, unlimited deployments, no developer
  accounts, no Joinery-operated name anywhere in the path.
- Slots into machinery that already exists: `uew_user_encryption_wrappings`
  already models `unlocker_type` (passkey / recovery / passphrase); this adds
  `device`. The enrollment ceremony has a working precedent in `devices_link`
  (code on the device, approval in an unlocked browser session).
- The honest cost: it is our protocol rather than an audited standard. It must
  stay boring — P-256 ECDH, HPKE-style sealing, a server challenge for freshness,
  no novel primitives — and it wants an external review before it ships.
- **A device key is not a key handoff.** `devices_link` today seals the *drive*
  (client-custody) vault key to a device. This must not: the device gets a
  credential that opens a server-side window, never the vault secret. Blurring
  that would turn Private into Fortress without Fortress's guarantees.

**Option C — the device holds the key, and reads locally.** The pattern the rest
of the industry actually ships (below). The device is handed sealed key material
at enrollment — the `devices_link` / `sde_device_pubkey` handoff this platform
already performs for Fortress sync devices — and decrypts Private files on-device
without any server-side window at all. Reading works offline; the server still
opens its own window for the web surface, previews and AI.

- No RP ID, no hostname coupling, no new protocol: the handoff ceremony exists.
- The trade is the tier's promise. A Private file's key would live on the phone,
  in the enclave behind biometrics, rather than only in server RAM during a
  window. "A stolen device yields nothing" weakens to "a stolen device yields
  nothing without the owner's biometrics" — which is what Proton and Bitwarden
  both ship and defend, but it is a change to what the Private card says, not
  just an implementation choice. It also drifts Private toward Fortress on that
  device, and the two tiers earn their keep by being distinguishable.

The three are not exclusive. B unblocks every deployment; A can be offered
wherever a Joinery-operated hostname already exists, as a second unlocker on the
same vault; C is the one that changes what the tier promises and therefore wants
the owner's call rather than an engineering recommendation.

### How the field solves it

Worth recording because it shaped the analysis, and because the conclusion is
not obvious: **nobody solves native unlock with WebAuthn against a tenant
domain**, because nobody can.

- **Proton** is single-tenant — every account lives on proton.me, and there is no
  self-hosting — so one associated domain covers every user and this problem
  never arises for them. More importantly, their model is client custody: the
  user's password derives a key that unwraps their private key *on the device*,
  and the server never holds it. There is no server-side window to open, so
  passkeys are a **login** factor, not what makes content readable. On mobile the
  key sits in the OS keychain behind a local biometric gate. That is structurally
  Joinery's Fortress, not Private.
- **Bitwarden** is the closer analogue: one shipped app, arbitrary self-hosted
  server URLs, a serious security bar — the same shape as this platform.
  Its native unlock is a biometric-gated local key, not WebAuthn against the
  customer's domain. Passkeys appear in its *web* clients, where the browser owns
  the origin binding for free.

The shared pattern is: passkeys for browser login, where the browser handles RP
binding; hardware-backed local credentials for native unlock. Options B and C sit
squarely inside it.

*Caveat: this is general knowledge of both products and their implementations may
have moved. Verify current behavior before citing either as precedent in a
decision.*

## The webview path

What works today, and the small gaps in it — this is the near-term direction
while the above is deferred.

Drive renders in the app through `WebScreen`, whose bridged session lives in the
shared persistent cookie store (`docs/mobile_apps.md` § JoineryKit). That is a
real browser session, so `VaultUnlock::secretKey()` resolves normally and a
Private file opens in the app exactly as it does on desktop, unlock ceremony
included. Nothing about Private is broken in the webview.

**Downloads out of the webview work.** Checked against the client source rather
than assumed: `WebScreen` returns `.download` from its navigation policy and
implements `WKDownloadDelegate`, so the **webview itself** performs the transfer
over its own network context — `configuration.websiteDataStore = .default()`,
which is where the bridged session cookie lives. The share sheet receives the
finished **local file URL**, never the remote one. A Private file downloads in the
app exactly as it does in a desktop browser.

**One gap, closed** (`DriveHelper::file_export()`, 2026-08-06): `drive_list`
carries no `requires_browser_session`, so a client calling it with an API key was
handed `download_url` and `thumb_url` that answer 423 — a listing of broken
tiles. Those URLs are now omitted for sealed files when the caller presents a key
rather than a session (`ApiAuth::isBrowserSessionPrincipal()`), and the export
carries `requires_window: true` so a client can say why. This was D7-b from
`specs/implemented/drive_private_tier_defects.md`, and it stands whether or not native unlock
is ever built.

## The native unlock ceremony

Shown for Option A (platform hostname, real passkeys), because it is the shape
Option B mirrors: same principal, same window, same server-side unwrap — only the
step that produces the KEK differs (a PRF assertion there, a biometric-gated
enclave key agreement here).

The app performs a real WebAuthn assertion with the PRF extension, on-device,
against the site's RP ID — the same `vault-kek` derivation the web ceremony uses
(`PasskeyService::verifyDerivation($credential, 'vault-kek')`). The server code
path is unchanged; only the transport and the principal differ.

```
app                                         server
 │  POST /api/v1/action/vault_unlock_options      (session key)
 │ ─────────────────────────────────────────────►
 │      challenge stashed under apk:{id}
 │ ◄─────────────────────────────────────────────
 │  ASAuthorization / Credential Manager
 │  → biometric, uv=required, PRF eval
 │  POST /api/v1/action/vault_unlock_passkey      (session key)
 │ ─────────────────────────────────────────────►
 │      verifyDerivation → unwrap → VaultUnlock::open()
 │      window stored at vault:apk:{id}:{user}:user
 │ ◄─────────────────────────────────────────────  { unlocked: true }
```

The secret key is unwrapped server-side into APCu exactly as on the web, lives
for the same bounded window, and is wiped by the same events. The app never holds
vault key material — a deliberate non-goal, and the reason this is a *server*
custody tier.

**Why a passkey and not the bypass phrase.** `vault_setup_verify` already accepts
an optional passphrase "for non-web clients", and that route would be far less
work. It is the wrong answer here: a phrase is a shared secret that can be
phished, keylogged, or read out of an app's keychain, and it proves knowledge
rather than presence. A passkey assertion with `userVerification: required`
proves a specific device and a specific human at it. The passphrase stays
available as the recovery unlocker it is; it is not the native path.

## The content path: authenticated bytes, no bearer URLs

Signed URLs stay what they are — a fetch grant for cookie-less contexts — and
are **not** extended to carry unlock authority. Folding a session into the
signature would make a leaked URL decrypt plaintext for its TTL, which is exactly
the property Private is sold on.

Instead, `/uploads/*` learns the API session-key credential, alongside the two it
already accepts (cookie session, signature):

- The app signs the request with its session key the same way it signs `/api/v1`.
- `ApiAuth` resolves the key → principal `apk:{id}` → `VaultUnlock::secretKey()`
  finds the window that device opened → `DriveSealedStream` serves it.
- Ranges, resume, `Accept-Ranges`, the 423/416/404 ordering and the
  `assertContainerIsWhole()` check all work unchanged: this is a credential
  addition in front of the existing gate, not a second serving path.

A URL alone remains useless. Opening Private bytes requires the device's key
secret *and* an open window on that device — two factors the URL carries neither
of.

`DriveHelper::file_export()` stops minting `download_url` / `thumb_url` for
sealed files when the caller is neither a browser session nor a session key, and
emits `requires_window: true` so any client can render "Unlock to view" instead
of a broken thumbnail. (This is D7-b from the defect spec, folded in here.)

## Defense in depth — what makes this stronger than the web path

The device is a better-identified principal than a browser tab, and the design
should spend that rather than settle for parity:

1. **Device binding (D-a, recommended).** The app generates an X25519 keypair in
   the Secure Enclave / StrongBox at enrollment and registers the public half —
   the `sde_device_pubkey` shape, extended to app session keys. Content requests
   for Private files carry a short signature over `{api_key_id, file_id,
   size_key, timestamp}`. A stolen key secret then does not open Private content
   without hardware-backed possession of the device.
2. **Tighter caps for device windows.** A phone in a pocket is not a browser tab
   someone is looking at. Device windows get their own absolute cap, and the
   heartbeat that keeps a web window monitored has no native equivalent — so a
   device window ends on its cap, not on presence.
3. **Background fetches never extend a window.** Reuse
   `VaultUnlock::setActivitySuppressed()`: an OS-scheduled or prefetch read is
   not a user being present, and must not hold the key in RAM on their behalf.
4. **Revocation parity, already free.** Killing the key at `/profile/security`
   ends the device's API access and its window in one gesture; a password change
   hits `lockAll()`, whose wildcard already covers `apk:` principals.
5. **Per-device audit.** The App Sessions view gains "vault unlocked" state and
   last-unlock time per device, so a member can see where their content is
   currently openable.

## Platform prerequisites and real risks

Named up front because they gate the client work, not the server work:

- **Associated domains are mandatory, and the deployment must serve them.**
  Passkeys bind to the RP ID, which here is the deployment's own hostname
  (`PasskeyService` derives it: `parse_url($this->origin, PHP_URL_HOST)`). An app
  can only assert against a host that vouches for it, and **nothing in this tree
  serves `/.well-known/apple-app-site-association` or `assetlinks.json` today** —
  that route is new work, and it belongs to the platform rather than to any one
  app (see D-b).
- **PRF on native needs verification, not assumption.** iOS exposes PRF through
  `ASAuthorizationPublicKeyCredentialPRFAssertionInput`, and Android through
  Credential Manager, but availability is OS-version and provider dependent. The
  existing `passkey_lab_report` action is the right probe: run it from each
  client before building on it. Note `tests/vault` cannot cover this — the
  WebAuthn virtual authenticator has no PRF, a limitation this estate already
  documents.
- **Origin validation must accept native origins.** `_checkAssertion()` verifies
  origin against the RP; native assertions present
  `android:apk-key-hash:…` or the associated-domain origin. The webauthn library's
  origin check needs an explicit allowlist per client, and getting this wrong
  fails closed (good) but silently (bad) — it wants a named test.
- **APCu is per-worker-pool.** Unchanged from today, but worth restating: the
  window lives in the web tier's shared memory, so a native request must land on
  the same pool as the ceremony that opened it. Single-host deployments are fine;
  a future multi-host deployment needs a shared window store, and that is a
  pre-existing constraint this spec inherits rather than creates.

## Decisions needed

- **D-a — device binding: build it now or leave the hook?** Recommended: build
  it. It is the single thing that makes the native path stronger than the web
  path rather than equal to it, and retrofitting a signature requirement onto
  shipped clients is far worse than shipping with it.
- **D-b — what proves presence on a device, given that WebAuthn cannot.**
  See *The WebAuthn wall* below: with one shipped app serving many deployments
  on their own domains, platform passkeys are unavailable both natively (the
  associated-domains entitlement is fixed at signing time) and in the webview
  (`docs/mobile_apps.md`: "WKWebView cannot expose platform WebAuthn"). The
  decision is therefore not *how* to use passkeys but *what replaces them*:
  a Joinery-operated hostname per deployment (real passkeys, at the cost of a
  Joinery-run name in every install), or a device-bound hardware credential as a
  new unlocker type (no hostname coupling, not WebAuthn). Recommended: the
  hardware credential, with passkeys available wherever a platform hostname
  already exists. Detailed below.
- **D-c — do webview and native share one window on the same device?** They are
  different principals (`sess:` vs `apk:`), so today they would not. Sharing them
  means a webview page could ride an unlock the user performed natively, which is
  convenient and slightly widens the blast radius. Recommended: keep them
  separate, and have the app bridge-unlock the webview silently when it holds a
  live native window.

## Build order

**Deferred — nothing below is scheduled.** Recorded so the shape survives the
pause. Steps 1–2 are the safe ones to start with if this resumes: they are pure
generalization, change no browser behavior, and the existing vault suite is the
regression gate.

1. **Principal generalization** — `VaultUnlock::currentPrincipal()`,
   `PasskeyService` principal, `pks_principal` rename, `requires_unlock_principal`.
   No behavior change for browsers; the whole existing vault test suite is the
   regression gate.
2. **Machine-key exclusion tests** before anything native can call the endpoints.
3. **Native ceremony over `/api/v1`** — endpoints admit session keys; origin
   allowlist; `passkey_lab_report` probe from a real device.
4. **`/uploads/*` session-key credential** + the `requires_window` export change.
5. **Device binding (D-a)** — keypair enrollment, request signatures.
6. **Caps, suppression, App Sessions unlock state.**
7. **Client work** (gated on D-b).

## Tests

- Every existing `tests/vault/*` suite passes unchanged — the browser principal
  must be untouched. That is the primary regression gate for step 1.
- A machine key is refused a window at every vault endpoint and at `/uploads`,
  with a dedicated test alongside the existing session-key boundary test.
- A session-key principal opens a window, reads a Private file's bytes with
  correct ranges, and is refused after its key is revoked — the full loop,
  without a cookie anywhere.
- Two principals for one user hold independent windows: locking one leaves the
  other open; `lockAll` ends both.
- Device-binding signature: a valid key secret without a valid device signature
  is refused for Private content and allowed for Standard.

## Docs

`docs/sealed_vault.md` (the principal model, replacing the session-id statement),
`docs/api.md` (`requires_unlock_principal`, the native ceremony, the session-key
credential on `/uploads`), `docs/mobile_apps.md` (native unlock and what the
bridge is still for), `docs/file_signed_urls.md` (signed URLs are a fetch grant,
never an unlock), `docs/drive_encryption.md` (Private is reachable natively).
Current-state only.
