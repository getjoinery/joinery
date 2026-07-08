# Security Levels — Post-Build Review Fixes

**Status:** Active
**Version:** 1.0
**Parent:** `specs/mailbox_security_levels.md` (design authority, v1.6 → v1.7 with this spec)
**Origin:** Final code review of the Phase 7-rest / 5.4 / 12 build found three
defects and three hardening items. This spec fixes all six.

## Fix 1 — Fortress enrollment must yield an INDEPENDENT second factor

**Defect.** The vault-holder passkey reset demands an independent second factor
only when one exists; the design accepts passkey-alone reset as the floor for a
*Private* user who declined 2FA, and justifies Fortress safety with "Fortress
always has the second factor (mandatory enrollment)." But the enrollment gate
(`SessionControl::must_enroll_2fa_for_fortress()`) is satisfied by
`user_has_second_factor()`, which counts **one passkey**. A Fortress vault
holder can therefore hold exactly one passkey and no TOTP — and for that user a
single stolen authenticator resets the password (passkey-alone branch), signs
in (same passkey as the login second factor), and unlocks the vault (same
passkey's PRF). One credential opens both doors — the exact collapse § The role
split forbids.

**Fix — at the promise's own layer, the enrollment gate.** Fortress mandates a
second factor **independent of any single passkey**: TOTP, or at least two live
passkeys. New helper `SessionControl::user_has_independent_second_factor($user)`
(TOTP enabled, or ≥2 live passkeys while passkey sign-in is enabled);
`must_enroll_2fa_for_fortress()` keys on it. The gate's redirect message states
the independence requirement. With the gate fixed,
`PasswordResetAuthorizers::secondFactorRequirement()` always finds an
independent factor for a Fortress holder, so the reset ceremony's demand is
always satisfiable — the spec's claim becomes structurally true. The
passkey-alone floor remains, deliberately, for Private-level vault holders who
declined 2FA.

**Design-spec change (v1.7).** § levels table and § 5.3: Fortress 2FA
enrollment requires a factor *independent of any single passkey* (TOTP or a
second passkey). § Password reset Population 3: the "Fortress always has the
second factor" sentence gains the independence qualifier.

## Fix 2 — Disabling `passkeys_enabled` must not strand passkey-only accounts

**Defect.** The sign-in second-factor divert (`login_logic.php` and the cookie
auto-login path in `SessionControl`) fires on `user_has_second_factor()`, which
counts passkeys regardless of the `passkeys_enabled` setting — while
`/verify-totp` hides the passkey button when the setting is off. A passkey-only
user is diverted to a page with no completion path: locked out of sign-in by an
admin toggle.

**Fix — one point, the helper itself.** `user_has_second_factor()` counts
passkeys only while `passkeys_enabled` is on. Every consumer then agrees with
what the ceremony pages actually offer:

- Sign-in divert (form + cookie paths): a passkey-only account signs in
  password-only while passkeys are disabled — no divert to a dead end.
- Step-up gates (`require_recent_second_factor`, passkey register/revoke,
  vault recovery, `/verify-stepup`): a passkey-only account is treated as
  having no usable factor — the same treatment a no-2FA account gets — instead
  of being asked for a ceremony it cannot run.
- Fortress gate (via Fix 1's helper, same setting check): a passkey-only
  Fortress account is asked to enroll TOTP while passkeys are disabled.
- Population-2 guard and security-page display: reflect usable factors only.

`PasswordResetAuthorizers::secondFactorRequirement()` is unchanged: it runs
only inside the passkey reset ceremony, which is already refused outright when
`passkeys_enabled` is off.

## Fix 3 — Account-edit Population-2 guard accepts a verified recovery address

**Defect.** The design (and `docs/account_security.md`) list the non-email
reset path for making a hosted mailbox the login email as "a passkey, TOTP, or
an external recovery address." `account_edit_logic.php` checks only
`user_has_second_factor()` — a user whose only path is a verified recovery
address is wrongly refused.

**Fix.** The guard passes when `user_has_second_factor($user)` OR
`$user->has_verified_recovery_email()`. The refusal message names all three
options. (Register-time refusal is unchanged: a brand-new account cannot hold
any path yet.)

## Hardening 4 — Same-credential exclusion enforced server-side

The reset second factor must be a *different* passkey than the one that
authorized the reset. The pre-ceremony check compares the client-sent
`credential['id']` — honest in the vendored webauthn-lib (the denormalizer
rejects any `id`/`rawId` mismatch and the base64url decode is canonical), but
the guarantee is inherited from a library internal, not asserted locally.
`PasskeyService::verifyStepUp()` now returns the verified credential's
`pkc_credential_id`; `password_reset_2fa_passkey_verify_logic` compares that
server-derived value against the ticket's reset credential after the ceremony
and refuses on match. The pre-ceremony check stays as the fast path.

## Hardening 5 — Throttle recovery-address verification sends

`set_recovery_email` sends a confirmation email to an arbitrary external
address with no per-source throttle (step-up-gated, but an authenticated user
could spam a third-party inbox). Rate-limit sends: 5 per hour per IP
(`RequestLogger` feature `recovery_verify_send`, counting sends).

## Hardening 6 — `recovery-verify.php` undefined-variable guard

`views/recovery-verify.php` passes `$is_valid_page` without the `?? false`
default its sibling views use. Add it.

## Documentation

- `docs/account_security.md`: Fortress enrollment section states the
  independence requirement; the sign-in divert paragraph states that passkeys
  count only while passkey sign-in is enabled. (The Population-2 passage
  already describes the Fix-3 end state.)
- `specs/mailbox_security_levels.md` → v1.7 per Fix 1.

## Acceptance

1. Fortress user with one passkey and no TOTP hits the enrollment gate; adding
   TOTP **or** a second passkey clears it. Fortress user with TOTP is
   unaffected.
2. With `passkeys_enabled` off, a passkey-only (no-TOTP) account signs in
   password-only; no divert to `/verify-totp`.
3. A user holding only a verified recovery address may make a hosted mailbox
   their login email; a user with no path is still refused, with the
   three-option message.
4. Vault-holder reset second factor still refuses the reset credential itself
   (now provably server-side) and accepts a different passkey or TOTP.
5. Sixth `set_recovery_email` send within an hour from one IP is refused.
6. `php -l` + `validate_php_file.php` clean on all touched files.
