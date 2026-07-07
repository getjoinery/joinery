# Account Security

The one place the platform's identity-and-access doctrine is written down: what
gets a user *in*, what each credential is allowed to open, and what every
sensitive action demands. The mechanics live in their own docs —
[Passkeys](passkeys.md) for the WebAuthn ceremonies, [Sealed
Vault](sealed_vault.md) for the encryption capability — this doc owns the
rules that tie them together. When a new feature needs to decide "what should
this action require?", the answer comes from here.

## The two doors

An account has up to two doors, and they are deliberately different:

- **The session** — proves *identity*. Opened by password sign-in (or passkey
  sign-in where allowed, below). A session reads and does everything that is
  not sealed.
- **The vault** — proves *presence*. Opened only by a live unlocker ceremony
  (a passkey assertion with user verification, a one-time recovery code, or
  an enrolled passphrase). Content sealed to the vault — and nothing else —
  is behind this door. See [Sealed Vault](sealed_vault.md).

Everything below follows from keeping those doors separate.

## The role split: a passkey never opens both doors

**A passkey never opens both the session and the vault on the same account.**

- An account **without** a vault may use a passkey for passwordless sign-in —
  there is nothing behind the second door, so the single credential guards a
  single door.
- Activating a vault flips it: setup refuses to start until the account has a
  working password, and from then on `passkey_login_verify` rejects
  passwordless sign-in for that account. The passkey remains fully usable as
  a step-up confirmation and as the vault unlocker — only passwordless
  sign-in is withdrawn. (Mechanics: [Sealed Vault § The vault-activation
  flip](sealed_vault.md#the-vault-activation-flip).)

The point is theft math: whoever steals the one object that unlocks the vault
must still produce a different credential to get a session at all.

## Step-up confirmation

Any feature can demand "re-confirm with your passkey before this" via
`PasskeyService::getStepUpOptions()` / `verifyStepUp()` — a short-lived
verified marker (5 minutes, session-bound, stored server-side in
`pks_passkey_ceremonies`) that `hasRecentStepUp()` checks. Step-up proves the
account owner is present at *this* keyboard *now*; a stolen session cookie
cannot mint one.

## Enrollment is guarded

Adding a credential is itself a sensitive action — a session thief must not
be able to quietly enroll their own key:

- **First passkey** on an account: requires the account password re-entered.
- **Additional passkeys**: require a recent step-up with an existing passkey.
- **Vault unlockers** (another passkey wrapping, regenerated recovery codes,
  a passphrase): require an open unlock window, and code regeneration and
  passphrase changes additionally require a recent step-up.

## The unlock window

One unlocker ceremony opens the vault for a bounded window
(`VaultUnlock`, APCu, keyed to the browser session):

- **Opens** on any successful unlocker ceremony. Passkey unlocks demand
  device **user verification** (biometric or key PIN), not merely presence.
- **Extends** on activity; idles out after `vault_unlock_idle_minutes`
  (default 30).
- **Ends** at the idle timeout, on the explicit Lock control, or with the
  browser session — the window is keyed to the session and never survives
  it. `VaultUnlock::lock()` / `lockAll()` are the generic wipe surface any
  future policy event calls.

## Unlockers, ranked

- **Passkey** — the everyday unlocker: one tap, user verification required.
- **Recovery codes** — one-time, for disasters. Consuming one to unlock is
  always allowed, but drops the vault into a *regenerate recommended* state
  once fewer than 3 remain unused. **Recovery codes are vault-only**: they
  answer "give me my data," never "log me in."
- **Passphrase** — optional fallback (Argon2id-derived), for accounts that
  want a memorized unlocker alongside hardware.

**The unlocker floor:** any change that would leave a vault with fewer than 1
passkey wrapping *and* fewer than 3 unused recovery codes is refused at the
point of change — passkey revocation is vetoed
(`PasskeyRevocationVetoException`), wrapping deletions are blocked — and the
refusal names what to enroll first. Losing every unlocker loses the sealed
content permanently; the floor exists so no single careless click can get an
account there.

## Password reset

A password reset re-issues the **session, never the vault**: the reset link
proves control of the account email and yields a signed-in session, but
sealed content still demands an unlocker ceremony the resetter cannot fake.
An admin-assisted reset has the same shape — it can restore the account, and
structurally cannot open the vault.

## What each action requires

| Action | Requires |
|---|---|
| Sign in | Password — or passkey, only while the account has no vault |
| Read sealed content | Open unlock window |
| Open the window | Unlocker ceremony (passkey + user verification / recovery code / passphrase) |
| Enroll first passkey | Session + password re-entry |
| Enroll additional passkey | Session + recent step-up |
| Add a vault unlocker | Open window (+ step-up for codes/passphrase) |
| Revoke a passkey | Session; refused if it breaks the unlocker floor |
| Rotate the vault key | Live PRF assertion from an enrolled passkey |
| Password reset | Control of the account email; never opens a vault |

Consumers of the vault (mail, chat) define their own *content* policies —
what they seal, what their locked state looks like — on top of these rules;
see each consumer's own doc.
