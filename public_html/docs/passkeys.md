# Passkeys

Core, platform-wide WebAuthn: passkey sign-in, step-up confirmation, and PRF-based
secret derivation. `includes/PasskeyService.php` is the single owner of every
ceremony — consumers never touch the underlying library or handle raw WebAuthn
JSON themselves.

Gated by the `passkeys_enabled` setting — an emergency kill switch, on by
default (vault setup and protected mailboxes depend on passkeys). Every
endpoint and UI entry point checks it and fails closed (404/hidden) when off.

This doc covers the *mechanics*. The account-level rules — which door a passkey
may open, when passwordless sign-in is allowed, what each sensitive action
demands — are defined once in [Account Security](account_security.md).

## Library

Wraps [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-lib)
(pure-PHP, no Symfony framework pulled in), pinned to the **5.3** line —
`composer.json` declares `^5.0`; the exact installed version is `web-auth/webauthn-lib:5.3.5`.
This is the only maintained PHP WebAuthn library with first-class PRF support
(`Webauthn\AuthenticationExtensions\PseudoRandomFunctionInputExtensionBuilder`),
which is the deciding feature for secret derivation. Reference its docs at
webauthn-doc.spomky-labs.com for ceremony internals beyond what's summarized here.

The 5.3 API is built around `Webauthn\CredentialRecord` (not the older, deprecated
`PublicKeyCredentialSource`) and a `Webauthn\CeremonyStep\CeremonyStepManager` — a
pipeline of individual verification steps (challenge match, origin, rpId hash,
signature, counter, etc.) assembled once per request by `CeremonyStepManagerFactory`
and reused across every ceremony call in `PasskeyService`.

## The four ceremonies

| Ceremony | Service methods | Purpose |
|---|---|---|
| Registration | `getRegistrationOptions()` / `verifyRegistration()` | Enroll a new credential on a signed-in session |
| Authentication | `getAuthenticationOptions()` / `verifyAuthentication()` | Passwordless sign-in |
| Step-up | `getStepUpOptions()` / `verifyStepUp()` / `hasRecentStepUp()` | Re-confirm with an existing passkey before a sensitive action (user verification required) |
| Secret derivation | `getDerivationOptions()` / `verifyDerivation()` | WebAuthn PRF extension — a stable 32-byte secret per (credential, context) that the server never holds at rest |

A derivation ceremony may be **scoped to named credentials**:
`getDerivationOptions($user, $context, $credential_ids)` restricts
`allowCredentials` to that subset. A caller acting on one particular passkey must
scope it, because the browser decides which credential answers an unscoped
prompt — the caller that adds a vault unlocker always does. An empty array means
"no opinion" and offers every live credential, never nothing: an empty
`allowCredentials` on an unlock path is a lockout.

Each `get*Options()` call mints a random 32-byte challenge, stashes it in
`pks_passkey_ceremonies` keyed by the browser-session id and tagged with a
purpose string and a five-minute expiry, and returns a JSON-ready options array
for the browser. Each `verify*()` call reads the stashed challenge, **deletes it
immediately** (single-use — a replay finds nothing), and only then runs the
WebAuthn verification. A mismatched purpose or an expired stash raises
`PasskeyException`. Ceremony state lives in that table rather than `$_SESSION`
because browser-session API requests are read-only on the web session (`ApiAuth`
releases the session lock before dispatch); the step-up-verified marker is a row
of the same table. One ceremony is in flight per session — a new options call
replaces any pending challenge — and expired rows are swept on every stash.

RP ID and origin come from the site's own domain
(`LibraryFunctions::get_absolute_url()`) — there is no separate RP-ID/origin
setting. Attestation conveyance is `none`; the credential's public key algorithm
is restricted to ES256/RS256. Registration asks for a discoverable credential
(`residentKey: preferred` — usernameless sign-in with an empty `allowCredentials`
list only works with resident keys; `preferred`, not `required`, so old security
keys still enroll).

Sign-count regression is flagged, not fatal (`PasskeyFlaggingCounterChecker`):
synced passkeys (iCloud Keychain, Google Password Manager) legitimately report a
counter of 0 on every use, so only a genuine same-or-lower nonzero counter logs a
warning to the error log — no assertion is rejected on that basis alone.

**The vault-activation flip** (docs/sealed_vault.md): passwordless sign-in is
withdrawn from any account that has a [Sealed Vault](sealed_vault.md) —
`logic/passkey_login_verify_logic.php` checks for one immediately after the
assertion verifies and, if found, undoes the session `verifyAuthentication()`
just established and rejects, pointing the user at their password.
`logic/passkey_login_options_logic.php` makes the same check early for an
email-scoped request as a friendlier rejection, but the verify-side check is
the actual enforcement (a usernameless request has no account to check yet).
Step-up and vault-unlock ceremonies are unaffected — only passwordless
sign-in is withdrawn.

## Persistence

`data/passkeys_class.php` defines `Passkey` / `MultiPasskey` over
`pkc_passkey_credentials`. One row per enrolled credential:

- `pkc_credential_id` — base64url of the raw WebAuthn credential id; the lookup
  key on every assertion. A partial unique index enforces one *live* row per
  credential id (`WHERE pkc_delete_time IS NULL`) so a revoked credential can be
  re-enrolled.
- `pkc_source_json` — the library's serialized `CredentialRecord`. Authoritative:
  every ceremony round-trips through it. Never exported over the API
  (`$api_unreadable_fields`).
- `pkc_sign_count`, `pkc_transports`, `pkc_aaguid`, `pkc_prf_capable`,
  `pkc_discoverable`, `pkc_attachment`, `pkc_label`, `pkc_created_time`,
  `pkc_last_used_time` — denormalized-on-write conveniences for lookup, UI
  display, and capability detection.

The model is API-readable **owner-only** — `Passkey::authenticate_read` is
tightened past the owner-or-staff default because passkeys are authentication
credentials: no one but the owner has a reason to read them, staff included
(admin surfaces manage users, not credentials). Any caller's collection read
returns only their own rows. Not API-writable — rename and revoke are actions
with their own authorization and side effects, not raw CRUD.

### Capability detection

A U2F-only security key enrolls as a passkey and signs in fine, but it can never
unlock a [Sealed Vault](sealed_vault.md): it can neither verify a user nor
evaluate hmac-secret. `Passkey::vault_capability()` is the one place that answer
is decided, and it returns one of three states derived on read from stored
signals:

| State | Means | Built from |
|---|---|---|
| `capable` | Has evaluated PRF at least once | `pkc_prf_capable`, which `verifyDerivation()` sets on the first success |
| `incapable` | The browser fell back to CTAP1 — can never unlock a vault | Either evidence set below |
| `unknown` | Not proven either way | Everything else |

Two independent evidence sets reach `incapable`, and either is sufficient. The
first is what registration records: PRF not reported, the credential explicitly
non-discoverable (`pkc_discoverable`), and attachment explicitly `cross-platform`
(`pkc_attachment`). **All three must be known and negative** — a null is the
absence of a signal, not a negative one. The second reads the serialized
`CredentialRecord`'s `uvInitialized`, a latch set at registration and raised by
the first assertion that verifies the user, which therefore reads false only for
a credential that has never verified a user in its life; paired with transports
that exclude `internal`, that is the same conclusion for credentials enrolled
before the two columns existed.

The distinction that makes this work: **a FIDO2 authenticator reports
`prf.enabled` at creation whether or not a PIN is set.** hmac-secret is a
capability of the key, not a permission granted by the PIN. So a key that reports
no PRF, made no discoverable credential, and is not built into the device is one
the browser could only reach over CTAP1.

`unknown` is deliberately **permissive** — the ceremony is still offered and still
allowed to run. Windows Hello omits `prf.enabled` at creation and evaluates PRF
fine at assertion, so registration-time reporting must never be the thing that
stops an attempt. Only `incapable` is acted on, and only in one direction:
withholding a vault action. That is provably safe, because a credential that
never supported PRF cannot have completed a derivation and so cannot hold a
wrapping — excluding it can never remove a working unlocker.

`vault_capability` rides along on the API export as a derived field, so the
owner's security page and the [lab](#diagnostics) read the same answer rather
than each re-deriving it.

## Consumer contract

Every PRF consumer today is a [Sealed Vault](sealed_vault.md) scope, one context
each: `vault-kek` (server-custody mail + chat, whose KEK is sent to the server),
and the client-custody contexts `vault-passwords-kek` (the
[password manager](../plugins/vault/docs/overview.md)) and `vault-drive-kek`
(Drive), whose KEK is derived and used **only in the browser** and never
transmitted. The distinct per-scope context is what guarantees one scope's KEK can
never unwrap another's key.

**A context is DERIVED from its scope name, never declared.**
`VaultScopes::prfContext($scope)` returns `vault-{scope}-kek`, with the single
grandfathered exception `user` → `vault-kek`, and
`PasskeyService::allowedPrfContexts()` is computed over the registered scopes.
That is the isolation property, made structural rather than trusted: a declared
context is a footgun with no floor under it, because a developer who copies
another plugin's declaration and forgets to change the string silently merges
two scopes' unlocks — one tap would open both. Deriving from the name, which the
scope registry already keeps unique, makes that mistake unrepresentable.

Every derivation request sets `userVerification: required` (not merely
`preferred`, unlike sign-in), since a vault unlock demands device user
verification. A feature that wants a client-held encryption key beyond the vault
is a **PRF consumer** in the same shape:

1. Call `PasskeyService::getDerivationOptions($user, $context)` /
   `verifyDerivation($client_response_json, $context)`. `$context` must be one of
   `PasskeyService::allowedPrfContexts()` — declare a vault scope and its context
   follows. The context is hashed into a fixed, deterministic PRF salt, so the
   same context always evaluates the same secret for a given credential.
2. `verifyDerivation()` returns `[User $user, Passkey $credential, string $prf_output_32]`.
   The 32-byte output is per-**credential** — two enrolled passkeys derive two
   different secrets for the same context. A consumer holds one wrapping of its
   protected key per credential the user has activated for it
   (`PasskeyService::listCredentials($user)` to enumerate credentials).
   `pkc_prf_capable` is registration-time reporting and is never load-bearing:
   within whatever subset a caller scopes to, derivation options list **every**
   live credential (the ceremony itself is the capability test — some
   authenticators, notably Windows Hello, omit the creation-time report while
   evaluating PRF fine), and a successful `verifyDerivation()` upgrades a false
   flag to true from that evidence. The one credential a consumer may drop is
   one `Passkey::vault_capability()` reports as `incapable`, which by
   construction can hold no wrapping.
3. The derived secret is produced in the browser and transits TLS to the server,
   which uses it transiently (e.g. as a KEK) and holds it at most in session RAM —
   never at rest.

### Revocation veto hook

Before soft-deleting a credential, `PasskeyService::revoke()` consults a registry
of veto callbacks in registration order:

```php
PasskeyService::onPreRevoke(function (int $user_id, int $credential_id) {
    // throw PasskeyRevocationVetoException('reason') to block the revocation
});
```

A callback throws `PasskeyRevocationVetoException($reason)` to refuse the
revocation; `revoke()` re-throws it and does **not** delete the row. The message
surfaces to the user verbatim. This is a plain callback registry rather than the
platform signal bus (`SignalBus::dispatch()`) because the bus catches and logs
subscriber exceptions rather than propagating them — it cannot veto synchronously.
A consumer (e.g. the mail unlock floor) subscribes at bootstrap and vetoes when
deleting its wrapping for that credential would strand its protected key.

## API surface

Eight `_logic_api()` actions under `/api/v1/action/*` (see [API](api.md) for the
full table) plus the free CRUD read `GET /api/v1/Passkeys` for the credential
list — no bespoke listing endpoint. Enrollment always demands proof beyond the
session cookie: a second (or later) passkey requires a fresh step-up assertion
first, and the very first passkey (nothing to step up with yet) requires the
account password (`current_password` in the `passkey_register_options` body).
Accounts with no password (e.g. OAuth-only) have only the session as an anchor
for the bootstrap enrollment.

Registration always requests both the PRF and `credProps` extensions, server-side
and unconditionally — there is no caller flag. PRF can only be enabled at creation
time and is inert on an authenticator that lacks it, so asking always costs
nothing and is what makes `pkc_prf_capable = false` mean one thing forever: were
the request caller-controlled, "did not report PRF" and "was never asked" would be
the same stored value.

## JS helper

`assets/js/passkeys.js` defines `window.JoineryPasskeys`, a vanilla wrapper around
`navigator.credentials.create()/get()`:

```js
JoineryPasskeys.isSupported()       // -> bool: window.PublicKeyCredential + navigator.credentials present
JoineryPasskeys.isPrfLikely()       // -> Promise<bool>, best-effort - never load-bearing
JoineryPasskeys.register(options)   // -> Promise<JSON-ready attestation response>
JoineryPasskeys.authenticate(options) // -> Promise<JSON-ready assertion response>
JoineryPasskeys.derive(options)     // -> Promise<{response, prfOutput}> - response is POSTable, prfOutput is base64url
```

`options` is the JSON-ready object a `get*Options()` service call returns
verbatim (challenge, credential ids, and any PRF extension input are base64url
strings; the helper decodes them to `ArrayBuffer`s before calling the WebAuthn
API and re-encodes the browser's response back to base64url JSON). Page scripts
fetch options from the matching action, pass them through the helper, then POST
the returned object — wrapped as `{ credential: ... }` (register/step-up/login
verify) or `{ credential: ..., label: ... }` (register verify) — to the matching
verify action.

No feature may *require* PRF support: `isPrfLikely()` is a coarse, best-effort
probe (platform authenticator availability, or the client-capabilities API where
present) — a consumer branches its UI on it but must still offer a non-PRF
fallback (recovery codes, a bypass phrase) since some authenticators only reveal PRF
support at the first real evaluation.

## Diagnostics

`/admin/admin_passkey_lab` (superadmin only) runs assertion ceremonies whose
request shape is chosen per run — user verification required or preferred, PRF
extension on or off, credential subset — against the signed-in superadmin's own
passkeys, so a misbehaving browser/authenticator combination can be isolated
one variable at a time. Its credential table shows each passkey's
[vault capability](#capability-detection) next to the raw signals behind it —
PRF reported, discoverable, attachment, and whether user verification has ever
been performed — because the lab is where someone goes when they do not believe
the badge on `/profile/security`. Every outcome is recorded in the request log under
feature `passkey_lab`, including browser-side rejections that never reach the
server (the page posts the error name back). Completing a lab ceremony grants
nothing: `verifyDiagnostic()` sets no step-up marker and unlocks nothing.
Backed by `PasskeyService::getDiagnosticOptions()` / `verifyDiagnostic()`.

Independently of the lab, the JS helper reports **every** ceremony rejection
(register or authenticate, any page) to `passkey_client_report`, which logs it
under request-log feature `passkey_client` with the surface path, error name,
elapsed milliseconds, and the document's focus/visibility state. Browser-layer
refusals such as `NotAllowedError` never reach a verify action, so this
telemetry is the only server-side trace they leave. The domain editor's
step-up interceptor additionally reports a `stepup-fallthrough` row when it
declines to run because the helper is absent.

## Settings

- `passkeys_enabled` (default `"1"`) — emergency kill switch for passkey
  sign-in, enrollment, and PRF derivation. Declared in core `settings.json`.
  Turning it off disables all passkey operations deployment-wide, including
  vault setup.

No RP-ID or origin setting — both come from the site's own domain.
