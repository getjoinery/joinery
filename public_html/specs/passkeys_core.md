# Core Passkeys (WebAuthn) — Authentication and Client-Held Key Derivation

**Status:** Draft / awaiting implementation
**Version:** 1.2
**Consumed by:** `specs/inbound_email_encryption_at_rest.md` (mail unlock — the
first PRF consumer). Designed as a **core platform capability**, not an email
feature: any future subsystem can enroll against the same service.

## Goal

Give the platform one passkey system with two distinct primitives, cleanly
separated because they answer different questions:

1. **Authenticate** — *"prove this is the account owner."* Passwordless or
   step-up sign-in using the device's fingerprint/face/hardware key instead of
   (or in addition to) a password. This is what most systems mean by passkeys.
2. **Derive a secret** — *"produce key material the server has never held."*
   Via the WebAuthn PRF extension, a passkey can compute a stable 32-byte
   secret inside the authenticator hardware, released only on a physical
   touch/face check. The server never sees the ingredient at rest — which is
   what lets features offer real encryption (a hacked server finds no usable
   key) with a fingerprint-tap experience instead of a memorized passphrase.

The mail encryption specs need primitive 2. The platform gets primitive 1 for
free from the same plumbing, and future features (e.g. the drive encryption
spec is a natural candidate) enroll as additional consumers rather than
building their own.

## Architecture

All core, no plugin. Vanilla JS on the front end per theme rules.

- **`includes/PasskeyService.php`** — the single server-side owner of both
  WebAuthn ceremonies: create registration options / verify registration,
  create authentication options / verify assertions, including PRF extension
  inputs and outputs. Challenge lifecycle is session-scoped and single-use.
  Relying-party ID is the site's domain (from site config); origin and rpId
  are strictly validated; attestation is not required (`none`).
- **WebAuthn library** — use a maintained library rather than hand-rolling
  COSE/CBOR parsing (this is signature-verification code; owning it is a
  liability). **Decided: `web-auth/webauthn-lib`.** The deciding feature is
  PRF-extension support, and only this library has it: it ships a first-class
  `PseudoRandomFunctionInputExtensionBuilder` with per-credential salt handling
  and dedicated docs. The leaner alternative, `lbuchs/WebAuthn`, has no PRF
  support — its source carries only generic extension scaffolding marked
  "extensions not implemented" — so it cannot produce the derived secret this
  whole design stands on. The heavier dependency tree is the accepted cost;
  `web-auth/webauthn-lib` is the pure-PHP library (not the Symfony bundle), so
  no framework is pulled in. The service wraps it so consumers never touch the
  library directly.
- **`data/passkey_credential_class.php`** (`pkc_passkey_credentials`) — one
  row per enrolled credential: user id, credential id, COSE public key, sign
  count, transports, AAGUID, PRF-capable flag, user-facing label ("MacBook
  Touch ID", "YubiKey"), created / last-used times. Standard soft-delete.
- **API endpoints** — `_logic_api()` actions on `/api/v1` (session credential
  + CSRF header, per the API rules): registration options, registration
  verify, assertion options, assertion verify. No `/ajax/` endpoints.
- **`passkeys.js`** — a small vanilla helper wrapping
  `navigator.credentials.create()/get()`, base64url conversion, PRF extension
  wiring, and capability detection (`window.PublicKeyCredential` and
  conditional-UI support). Loaded only on pages that use it.

## The Two Primitives

### 1. Authentication

- **Passkey sign-in** on the login page: assertion → `PasskeyService` verify →
  `SessionControl` establishes the session exactly as a password login does.
  Password login remains; passkeys are additive.
- **Step-up confirmation**: a one-call primitive any feature can invoke —
  "re-confirm with your passkey before this sensitive action" — returning a
  short-lived verified flag in the session. Generalizes beyond email (admin
  actions, destructive operations) without those features touching WebAuthn.

### 2. Secret derivation (PRF)

- A consumer asks the service for a **derived secret** in a named context
  (e.g. `mail-kek`): the JS helper runs an assertion with the PRF extension,
  salt = a fixed per-context constant, and the authenticator returns 32 bytes.
- **Outputs are per-credential**: two enrolled passkeys derive two different
  secrets for the same context. Consumers must therefore hold one wrapping of
  their protected key **per enrolled PRF credential** (the mail spec's
  multiple-unlockers model). The service exposes the credential list so a
  consumer can detect an unwrapped-for credential and offer to enroll it.
- **Enrollment order**: PRF capability is flagged at registration, but the
  first evaluation requires an assertion — so "enroll passkey for mail" is
  register → immediately assert with PRF → hand the secret to the consumer to
  create its wrapping. Adding a wrapping requires the consumer's key to be
  currently unwrapped (an unlocked session), which the consumer enforces.
- **Trust boundary, stated plainly**: the derived secret is produced in the
  browser and transits TLS to the server, which uses it transiently (e.g. as
  a KEK) and holds it at most in session RAM. Equivalent to the passphrase
  flow it replaces — at rest the server holds nothing; the deferred
  client-side-crypto model in the encryption spec would keep it browser-only.

## Consumer Inventory (decided up front)

1. **Login / account security** — passkey sign-in; a profile-page security
   section listing credentials with add / rename / revoke.
2. **Step-up confirmation** — the generic re-confirm primitive.
3. **Mail unlock** (`inbound_email` encryption specs) — PRF context
   `mail-kek`; per-credential wrappings of the mail secret key; enrollment
   embedded in the security-level ceremony.
4. **Future candidates** — `specs/drive_encryption.md` (same client-held-key
   shape); any feature wanting user-held encryption enrolls a new PRF context
   rather than a new mechanism.

## Fallbacks & Platform Coverage

- **No WebAuthn / no PRF**: authentication falls back to password; secret
  derivation falls back to the consumer's other unlockers (recovery codes,
  optional passphrase — the mail spec requires recovery codes precisely so a
  passkey is never the only unlocker). The JS helper's capability probe is
  what the UI branches on; no feature may *require* PRF support.
- **PRF support matrix** (verify during implementation): current Chrome and
  Safari support PRF with platform authenticators and recent YubiKeys;
  synced passkeys (iCloud Keychain / Google Password Manager) sync the PRF
  seed with the credential, so a user's other devices derive the same secret.
- **Native mobile apps** — the Android/iOS modules use the platform
  credential-manager APIs for the same ceremonies against the same endpoints;
  the web-session bridge (docs/mobile_apps.md) carries the session that the
  assertion establishes or unlocks. Details are an open item.

## Schema Changes (via data-class `$field_specifications`)

- `pkc_passkey_credentials` as above (new table, new data class).
- No changes to `usr_users`; credentials reference the user id.

## Settings

- `passkeys_enabled` (feature flag, default on once shipped) — declared in
  core `settings.json`.
- RP ID / origin come from existing site configuration, not new settings.

## Security Notes

- Challenges: cryptographically random, session-bound, single-use, short TTL.
- Verify origin and rpId hash on every ceremony; reject cross-origin.
- Enforce sign-count regression detection where authenticators provide it
  (flag, don't hard-fail — synced passkeys legitimately report zero).
- Registration of a new credential on an existing account requires an
  authenticated session **and** step-up (or password re-entry): a session
  thief must not be able to enroll their own passkey quietly.
- Revoking a credential deletes consumers' wrappings for it (service emits a
  revocation hook consumers subscribe to; the mail consumer deletes that
  credential's KEK wrapping). The hook is a veto point: a consumer refuses the
  revocation when deleting its wrapping would strand its protected key (the
  mail consumer's unlocker floor — encryption spec § Recovery & Key Loss),
  and the refusal reason surfaces to the user.

## Documentation to Update

- New `docs/passkeys.md` — the service, both primitives, the consumer
  contract (contexts, per-credential wrappings, revocation hook), JS helper
  usage, and the capability-probe fallback rules.
- `docs/api.md` — the four endpoint actions.
- `plugins/inbound_email/docs/overview.md` — the mail unlock section
  references the core service rather than describing WebAuthn itself.

## Open Items to Confirm During Implementation

- Confirm PRF availability on the oldest browsers/devices the operator
  actually uses; decide the minimum supported set.
- Native-app ceremony details (Android Credential Manager / iOS
  ASAuthorization) and how PRF is surfaced there.
- Whether passkey *sign-in* ships enabled at launch or the first release is
  derivation-only (mail unlock) with sign-in following.
- Conditional UI ("passkey autofill") on the login page — nice-to-have, not
  load-bearing.
