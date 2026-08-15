# Bypass-phrase vault fallback

**Status:** built and verified 2026-08-15. Ships with the mandatory
`encryption_key` setup step ([setup_wizard.md](setup_wizard.md) § Step 2).
The browser-side gate is open — see **§ Open**.

## The problem

Every account should hold a personal encryption key. A key that seals nothing
costs its holder nothing, and an estate where everyone already holds one needs
no per-user capability check before offering a private folder, a sealed
mailbox, or a protected conversation. So the setup wizard's encryption-key step
is mandatory, and offers no decline to an account that can comply — the only
ways past it are the two the platform cannot solve for the user: hardware that
cannot derive a key, and an account with no password yet.

That runs into hardware. A Sealed Vault secret key is wrapped under a passkey's
**WebAuthn PRF** output, and PRF support is considerably narrower than passkey
support:

| Cohort | Why no PRF |
|---|---|
| iPhone/iPad on iOS 17 or older | Safari gained PRF for iCloud Keychain passkeys in iOS 18 / macOS 15. Devices that cannot run iOS 18 are permanently excluded |
| Windows 10, older Windows Hello | Platform-credential support came in later Windows 11 builds |
| Older Firefox | PRF landed well after Chrome/Edge; no authenticator helps |
| Older Android | Support arrived through Google Play Services updates |
| U2F-only security keys | No `hmac-secret` at all |

These are ordinary users on ordinary devices, not an edge case. Telling them to
buy a new phone is not a product, and leaving them with no encryption
contradicts the reason the step is mandatory. The exact version boundaries
above move over time and should be re-checked before being quoted; the shape
does not.

## The answer

An account that cannot derive a key from any passkey bootstraps its vault under
a **bypass phrase** instead: a `TYPE_PASSPHRASE` wrapping (Argon2id over the
memorized phrase, `SealedBox::kekFromPassphrase`) created at setup time, with
**no `TYPE_PASSKEY` wrapping at all**. Recovery codes are minted exactly as in
the passkey ceremony. Like the passkey route, it requires the account to
already have a password — a vault holder always keeps password sign-in as a
second factor.

Everything downstream is a normal vault. Private mail, private Drive folders,
saved passwords and Private/Guarded conversations all work. When the holder
later has a capable device, `vault_add_passkey_*` wraps the same key under a
real passkey (the secret comes from the open unlock window, which a phrase
unlock can open — so a phrase-only vault is never stranded) and the phrase can
then be removed. A bridge, not a permanent second class.

## The one rule that matters

**A compatibility fallback, never a preference.** A phrase can be guessed and
phished; a tapped passkey cannot. An account that *can* use a passkey must. The
design therefore decides eligibility from the credentials on the server, and
never presents it as a choice.

Scope of the rule: it governs **bootstrapping a vault with no passkey
wrapping**. A phrase as an *additional* unlocker alongside a passkey wrapping
is a separate, pre-existing feature (`vault_passphrase_enroll`, step-up gated)
and is available to any vault holder.

Four enforcement points, in order of importance:

1. **The gate is one predicate.**
   `Passkey::userNeedsPassphraseFallback($user_id)` is true only when the
   account holds **at least one** live credential **and every one of them** is
   provably `incapable`. The count requirement closes the empty-handed route:
   nobody reaches the weaker unlocker by owning nothing. It deliberately does
   not stop an owner deleting their capable passkeys while keeping an
   incapable one — at that point the account genuinely cannot use a passkey,
   so the rule still holds.

2. **The failure stamp comes only from a verified ceremony.**
   `PasskeyService::verifyDerivation()` stamps `pkc_prf_failed_time` on the
   credential **after** `_checkAssertion()` passes, never before. Order is
   security-critical — recording a missing PRF result straight off the posted
   body would let a forged request mark someone else's passkey incapable and
   push their account onto the weaker unlocker. A later successful derivation
   clears the stamp, because a firmware or OS update can make a credential
   capable. (The stamp is one of two evidence sets: registration-time signals
   alone can also prove `incapable` — see § Data model — and must, because a
   U2F-only key cannot pass a UV-required assertion and so could never earn a
   stamp.)

3. **The refusal lives in the ceremony, not the page.**
   `VaultCeremonies::setup()` re-asks the eligibility question itself whenever
   it is handed no passkey credential, and requires a phrase in that case.
   Hiding a button is not a gate; any other caller — a logic action, a CLI
   tool, a future client — hits the same refusal.

4. **One action reaches the path.**
   `logic/vault_setup_passphrase_logic.php`, browser-session only. It is
   deliberately **not** exposed to the AI agent (no `ai_agent` key): an agent
   must never mint a vault under a memorized phrase.

## Data model

One new column on `pkc_passkey_credentials` (via `$field_specifications`, no
migration):

```php
'pkc_prf_failed_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
```

It is distinct from `pkc_prf_capable = false`, which only ever meant "never
demonstrated". How `Passkey::vault_capability()` ranks the evidence:

1. `pkc_prf_capable` **true wins first** and answers `capable`. The flag is
   raised by registration-time PRF reporting or by any past successful
   derivation, and a credential carrying it stays `capable` **even with a
   failure stamp**. Deliberate: a synced passkey created on a capable device
   but asserted from an incapable browser fails a derivation without being
   incapable — the account still has a passkey route on the device that made
   it, so it must not become eligible for the phrase.
2. Otherwise the failure stamp answers `incapable` — a verified ceremony that
   produced no PRF output, ranking above the remaining registration-time
   signals.
3. Otherwise registration-time signals can prove `incapable` on their own
   (explicitly non-discoverable + cross-platform, or the `uvInitialized`
   latch false on a non-platform key). This is the only path a U2F-only key
   can take, and it needs no ceremony.
4. Anything else is `unknown`, and deliberately permissive — the ceremony is
   the real test.

The accepted corner of ranking rule 1: an account whose only credential
reported PRF at registration but whose only browsers cannot evaluate it will
fail derivations forever and never become eligible. Its exits are a passkey on
a capable device, or declining the step.

## What the user sees

The wizard step has three branches, decided server-side from
`vault_capability()` across the account's live credentials:

- **No passkeys at all** — sent to the sign-in-security step to enrol one
  first. Owning nothing is not a route to the phrase.
- **A capable or unknown credential exists** — the normal step. PRF is only
  truly provable by attempting a derivation, so an authenticator that cannot
  derive announces itself *here*, by failing. That failure is a supported
  outcome, not an error: the step restates it as a hardware limit and offers
  to re-show the step, so the server re-decides the branch with the new
  evidence — see § The route out of a failed derivation.
- **Every credential is incapable** — the limit stated plainly, with
  **Add a passkey elsewhere** as the primary action (a newer phone, laptop or
  password manager is genuinely better), **Use a bypass phrase instead** as the
  compatibility route, and **Continue without one** last.

The phrase panel states the trade in the user's terms — weaker than a passkey,
present because the device leaves no better option — and requires the phrase
twice, a 12-character minimum (`SealedBox::PASSPHRASE_MIN_CHARS`), and the same
permanent-loss acknowledgement the passkey route requires. The double entry is
the panel's own guard; the API treats the confirm field as optional, and the
length minimum is enforced server-side in both the logic action and the
ceremony.

The shown-once panel (recovery codes, key file, the "I've saved these" gate) is
shared by both routes: `includes/setup_steps/vault_shown_once.php`, which
defines `window.setupVaultShowResult()`. What must be saved, and the fact that
it is shown exactly once, does not differ by route.

## The route out of a failed derivation

PRF support is only truly provable by attempting a derivation, so an
authenticator that cannot derive announces itself on the normal branch, by
failing. That failure is a supported outcome, not an error. The step restates
it as a hardware limit and offers **Show my options**, a link back to
`/setup?step=encryption_key`. The failure has by then stamped the credential,
so re-entering makes the server re-decide the branch: an account whose every
credential is now incapable lands on the phrase route, one with other
credentials lands back on the normal step to try them.

The navigation is the whole mechanism, deliberately. The client never decides
eligibility inline — another credential on the account may still be capable,
and only the server can answer that.

## Accepted trade

Such a vault is openable with **memorized secrets alone** — the phrase, or a
recovery code — which is precisely what the possession-factor invariant avoids
elsewhere in the vault design. It is accepted because the alternative for these
users is no encryption at all, and because it is self-correcting: the moment
they hold a capable device, a passkey wrapping is one ceremony away.

Not built, and deliberately: wrapping the key under the **account password**.
It looks tidier and needs no new UI, but the server sees that password at every
login and could therefore unwrap the vault silently — destroying the "one
stolen credential must not open both doors" property the whole design rests on.

## Tests

`tests/account_security/vault_passphrase_fallback_test.php` (db tier, 14
checks). The load-bearing ones:

- an account with a capable passkey is **not** eligible, and the ceremony
  refuses it a passkeyless vault — the gate is proven at the ceremony, not the
  UI
- an account with **no** passkeys is not eligible either
- a proven-incapable account succeeds, and the resulting vault has no
  `TYPE_PASSKEY` wrapping, a `TYPE_PASSPHRASE` one, and its recovery codes
- a passkeyless vault with no phrase is refused, so it can never be left with
  recovery codes as the only unlocker

## Open

- The PRF-failure branch is untested against real hardware. The WebAuthn
  virtual authenticator has no PRF, which makes it a stand-in for exactly this
  cohort — Playwright can drive the failure path end-to-end, including the
  Show my options route back through the step.
- The device-cohort list under *The problem* should be re-checked against
  current browser support before it is quoted to users.
