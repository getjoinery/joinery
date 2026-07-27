# Second-Factor UX Coherence

**Status:** Implemented

## Problem

The security page equates "two-factor authentication" with TOTP, while every
enforcement point (`SessionControl::user_has_second_factor()`) counts TOTP
**or any live passkey**. A passkey-only account therefore reads "Two-Factor
Authentication: Not enabled" directly above a cadence panel that only
appears because a second factor exists — and gets factor-prompted at every
sign-in. Three adjacent defects compound it: trusted-device cookies are
HMAC'd with a key that only TOTP enablement mints, so passkey-only accounts
re-prove the factor at every session expiry with no way to trust a device;
enrolling a first passkey silently turns on second-factor sign-in without
saying so; and "Disable 2FA…" turns off only TOTP, so an account with
passkeys keeps prompting after "disabling 2FA".

## Principles

- One vocabulary: the account has a **second factor** (or not); TOTP and
  passkeys are *methods* of it. No surface may use "two-factor" to mean
  TOTP alone.
- No gate weakens: cadence semantics, the `every_login` default, step-up
  rules, and the passkey-passwordless asymmetry (a passkey alone signs in;
  a password demands a factor) all stay exactly as documented in
  docs/account_security.md.

## Change 1 — "Second-factor sign-in" panel

Replace the "Two-Factor Authentication" panel and the separate "Ask for my
second factor" panel with one **Second-factor sign-in** panel:

- **Summary line**, computed from the same predicate the gates use:
  "Active — 2 passkeys · authenticator app off" / "Active — authenticator
  app" / "Off". `security_logic` supplies a `factor_summary` structure
  (per-method counts/state); the view renders only from it.
- **Cadence radios** (unchanged values/labels) inline in this panel, shown
  only while the summary is Active.
- **Forget trusted devices** moves here from the TOTP actions menu (it now
  applies to any factor method — see Change 2).
- **Authenticator app** becomes a method subsection inside the panel: the
  existing enable/QR/confirm, backup-code, and disable flows move under it
  unchanged. The Passkeys panel stays separate (passkeys are also sign-in
  credentials and vault unlockers), but the summary line names them.

## Change 2 — Trusted devices for every factor method

- New user field `usr_second_factor_hmac_key` (varchar(128), nullable),
  minted lazily the first time a trusted-device cookie is issued.
  `usr_totp_hmac_key` is removed outright — signing trust cookies was its
  only concern, so nothing remains for it to do.
- Cookie: name `sf_trusted`, format `{user_id};{expiry};{hmac_sha256(user_id
  + expiry, usr_second_factor_hmac_key)}`. Old `totp_trusted` cookies simply
  stop validating (pre-launch; no compat shim).
- **Rotation = revocation:** the key rotates (invalidating all trusted
  devices) on Forget trusted devices, on TOTP disable, and on passkey
  revocation — removing a factor is the moment trust re-earns.
- The `/verify-totp` interstitial gains an explicit **"Trust this device for
  N days"** checkbox (default checked), N from the existing
  `totp_remember_device_days` setting (relabel its settings.json entry
  "Trusted device duration"; a value of 0 keeps hiding the checkbox and
  issuing nothing). `Login2fa::completePendingLogin()` issues the cookie only
  when the box was checked — no longer unconditionally, and no longer
  coupled to remember-me.

## Change 3 — Say it at enrollment

When a passkey enrollment makes the account's factor predicate flip from
false to true, the add-passkey response carries `became_second_factor: true`
and the security page shows one inline notice: "Your passkey now also
protects password sign-ins — you'll confirm it when signing in with your
password." One line, no modal, no new page prose.

## Change 4 — Names that tell the truth

- "Disable 2FA…" → **"Turn off authenticator app…"**; when live passkeys
  remain, its confirm copy states sign-ins will still ask for a passkey.
- The `/verify-totp` interstitial title becomes **"Confirm it's you"**; body
  copy already adapts per available method. The route, view filename, and
  `totp_pending_*` session keys stay (rename churn, no user-visible gain).

## Out of scope

- Changing the cadence default (`every_login` stays).
- The passkey-passwordless single-ceremony sign-in asymmetry (doctrine,
  documented).
- Renaming the `/verify-totp` route or pending-state keys.
- Any admin-facing 2FA reporting.

## Touched

- `views/profile/security.php`, `logic/security_logic.php` — panel merge,
  factor summary, enrollment notice, button rename.
- `includes/SessionControl.php` — trust cookie mint/verify on the new key.
- `includes/Login2fa.php`, `views/verify-totp.php`,
  `logic/verify_totp_logic.php`, `logic/login_2fa_passkey_verify_logic.php`
  — trust checkbox plumbing, interstitial title.
- `data/users_class.php` — `usr_second_factor_hmac_key` field spec; rotation
  hooks in TOTP disable and passkey revocation paths.
- `settings.json` — relabel `totp_remember_device_days`.
- **Docs:** update `docs/account_security.md` (trusted devices section, the
  second-factor vocabulary, interstitial naming) in the same change, written
  as current state.
