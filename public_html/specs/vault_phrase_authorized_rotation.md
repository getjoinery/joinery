# Phrase-authorized vault key rotation

**Status:** unbuilt.

## The problem

A vault holder whose bypass phrase or recovery-code sheet is exposed needs to
retire the compromised key: rotation mints a new secret, reseals everything to
it, and kills every old unlocker. For a passkey-holding vault that works today.
For a **phrase-only vault** — the compatibility fallback for hardware that
cannot derive a key ([vault_passphrase_fallback.md](implemented/vault_passphrase_fallback.md))
— it cannot: `VaultCeremonies::rotate()` is authorized by a passkey wrapping,
and a phrase-only vault has none. `vault_rotate_options` refuses such a vault
with `no_passkey_route` and points at adding a passkey — which is exactly what
this cohort's hardware cannot do.

So the one group most likely to leak an unlocker (a memorized phrase can be
guessed and phished; a tapped passkey cannot) is the one group with no way to
recover from the leak. Changing the phrase (`vault_passphrase_enroll`'s
replace) re-wraps the **same** secret under a new phrase: anyone who already
used the leaked phrase holds the secret itself, and keeps reading everything
sealed after the "change". Only rotation actually revokes.

## The answer

Rotation authorized by the bypass phrase, for the vaults that have nothing
stronger. The holder presents their **current** phrase (which proves they can
unwrap the old secret — the same proof the passkey route derives from PRF) and
a **new** phrase; the ceremony mints a new generation sealed under the new
phrase plus fresh recovery codes, drains content off the old secret, and
retires every old-generation wrapping. The old phrase, the old recovery codes,
and — critically — the old secret key are all dead afterward.

## Rules

1. **Same scope rule as the bootstrap: a fallback, never a preference.**
   Phrase-authorized rotation is offered only to a vault with **no
   `TYPE_PASSKEY` wrapping**, and only while
   `Passkey::userNeedsPassphraseFallback()` still answers true. A vault with
   any passkey wrapping rotates by passkey, full stop. An account holding a
   capable passkey that simply is not vault-active yet is refused with the
   route that keeps the strong path strong: unlock with the phrase, add the
   passkey (`vault_add_passkey_*`), rotate with the passkey. The check lives
   in the ceremony, not the page.

2. **The new phrase is mandatory and must differ from the presented one.**
   The point of rotating is that the presented phrase may be in someone
   else's hands; carrying it forward seals the new secret to the same leak.
   Same `SealedBox::PASSPHRASE_MIN_CHARS` minimum as everywhere else.

3. **The authorizing wrapping is the presented phrase's lowest-generation
   live `TYPE_PASSPHRASE` wrapping** — the same rule passkey rotation applies
   to credentials, for the same reason: after an interrupted rotation both
   generations are live, and the ceremony must unwrap the *oldest* secret,
   the one still holding un-resealed content.

4. **Pending-completion mode works by phrase too.** When the presented
   phrase's generation is below the vault's current one, the ceremony
   completes the pending rotation (drain to the existing current key, retire
   the old generation) instead of minting another — mirroring
   `completePendingRotation()`. Without this, a crash mid-rotation strands a
   phrase-only vault permanently: every enrollment ceremony refuses while two
   generations are live, and the only exit is re-running rotation.

5. **The action carries the full weaker-unlocker gate set.** Browser-session
   only (`requires_browser_session`); **no `ai_agent` exposure** — an agent
   must never rotate a vault onto a phrase it chose; the standard
   `second_factor_required` step-up render; the same rate limit class as
   `vault_unlock_passphrase` (this action *verifies* a phrase, so it is the
   same guessing surface); an explicit permanent-loss acknowledgement (the
   old recovery codes die with the rotation); `RequestLogger` success/failure
   logging.

## Mechanism

`VaultCeremonies` gains `rotateWithPassphrase(User $user, UserEncryptionVault
$vault, string $current_passphrase, string $new_passphrase, bool $open_window
= true): array`. Internally, `rotate()` splits into *authorize* and *execute*:
the existing method keeps its signature and does passkey authorization; the
phrase variant authorizes by deriving `kekFromPassphrase` against each
`TYPE_PASSPHRASE` wrapping (per-salt, the `unlockWithPassphrase()` pattern,
including the skip-damaged-wrapping rule) and then both run the same
mint/drain/retire core — one rotation, two authorizers. The phrase variant
enforces Rule 1 before touching anything, and returns the same shape
(`rotated`, `completed_pending`, `key_generation`, `recovery_codes`,
`key_file`; `dropped_passkeys` is always empty and `passphrase_reenrolled`
always true for this path).

**API:** one new action, `logic/vault_rotate_passphrase_logic.php` — no
options step, since there is no WebAuthn ceremony to mint. Input:
`current_passphrase`, `new_passphrase`, optional `new_passphrase_confirm`
(server treats confirm as optional, the panel enforces double entry),
`acknowledged`. Success returns the new recovery codes and key file for the
shared shown-once panel.

**Refusal copy that routes:** with this built, the `no_passkey_route` refusal
in `vault_rotate_options` names the phrase route for phrase-only vaults
instead of dead-ending at "add a passkey".

**UI:** on the security page, the existing **Rotate Vault Key** action checks
`vault_status`: `passkey_wrapping_count === 0 && has_passphrase` selects the
phrase flow — current phrase, new phrase twice, the acknowledgement naming
what dies (old phrase, old recovery codes), then the shared recovery-codes
panel. Everything else keeps the passkey flow. The setup wizard is untouched:
rotation is a maintenance act and lives at the vault's permanent home.

## Deliberately not built

- **Rotation authorized by the open unlock window.** Rotation stays
  authorized by presenting a credential, never by an already-open window — a
  session rider must not be able to rotate a vault onto secrets they chose.
- **Rotation authorized by a recovery code.** Leaked codes alone are handled
  by `vault_regenerate_codes`. The corner where the phrase is *both* leaked
  and forgotten leaves the holder able to unlock (recovery code) and re-wrap
  (`vault_passphrase_enroll`) but not rotate; accepted for the same reason
  the fallback itself is — the alternative is widening the set of memorized
  strings that can mint a new key generation. Revisit only with evidence it
  happens.

## Tests

`tests/account_security/vault_phrase_rotation_test.php` (db tier). Load-bearing:

- a phrase-only vault rotates: generation increments, content sealed under
  the old key is readable after (drain), the old phrase and old recovery
  codes no longer unlock, the new phrase and new codes do
- the ceremony refuses: a vault with a passkey wrapping; an account that no
  longer satisfies `userNeedsPassphraseFallback()`; a wrong current phrase; a
  new phrase equal to the current one; a short new phrase
- an interrupted rotation (two live generations, staged as
  `vault_rotation_crash_test` does) completes via the phrase instead of
  minting a third generation

## Documentation (at build time)

Per standing practice, the developer documentation lands in
`docs/sealed_vault.md`, not here: extend the rotation section with the
phrase-authorized variant and its scope rule, add the action to the
enrollment/ceremony table, and update the fallback section's "temporary by
design" paragraph to name rotation as available to the cohort.
