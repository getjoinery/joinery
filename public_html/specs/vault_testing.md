# Sealed Vault Test Estate

Automated tests for the Sealed Vault: the crypto core, the unlock window, the
ceremonies, and the mailbox consumer's re-seal path. Companion to
`specs/vault_code_tour_fixes.md` (the code-tour findings these tests must
pin down) and `docs/sealed_vault.md` (the behavior under test).

## Philosophy

The code tour proved the point: every real bug found was in **state ordering
across a crash boundary**, not in the math. Happy-path tests would have
passed all of them. So this estate weights accordingly:

- **Theft-side tests are refusal tests** — tampered ciphertext, spliced rows,
  wrong keys, wrong AD must *throw*, never return bytes.
- **Loss-side tests are recoverability tests** — kill or fail a ceremony at
  every boundary, then assert every unlocker that should work still works and
  every sealed item that should open still opens.
- A test that cannot run (no APCu in CLI, plugin inactive) **skips loudly**
  (`harness_skip`), never silently passes.

All tests use the shared harness and `@joinery-test` headers per
`docs/testing.md`. Everything lands in `tests/vault/` except the mailbox
consumer test (`plugins/mailbox/tests/`).

## Phase 0 — extract ceremony cores for testability (binding decision)

WebAuthn cannot run in CLI tests, and the browser's virtual authenticator has
no PRF support (known gotcha — see `project_security_levels_executor`
memory), so the ceremonies must be drivable without a passkey. A random
32-byte string is cryptographically equivalent to a PRF output; what tests
need is an entry point that accepts one.

Extract the ceremony bodies into `includes/VaultCeremonies.php`; the
`logic/vault_*_logic.php` files keep everything about *who may run this*
(settings gate, session, acknowledgment, rate limits, 2FA step-up, WebAuthn
verification, credential ownership) and delegate everything about *what it
does* to the core. Cores throw domain exceptions; logic files translate to
`LogicResult`. No behavior change.

```php
class VaultCeremonies {
    // setup: keypair, vault row + all wrappings (one transaction), open window
    public function setup(User $user, int $passkey_credential_id, ?string $passkey_label,
        string $kek, string $passphrase, int $code_count): array; // vault, recovery_codes, key_file

    // rotation: orphan cleanup, authorizing selection (lowest live generation
    // for the credential), unwrap, persist-new-then-flip (one transaction),
    // reseal callbacks, retire drained generation, key_file
    public function rotate(User $user, UserEncryptionVault $vault,
        int $passkey_credential_id, string $kek, string $passphrase): array;

    // recovery unlock: per-wrapping-salt matching, mark used, kill-switch
    // (lockAll THEN open), regenerate_recommended
    public function unlockWithRecoveryCode(User $user, UserEncryptionVault $vault, string $code): array;

    public function unlockWithPassphrase(User $user, UserEncryptionVault $vault, string $passphrase): string; // secret key
}
```

Passkey wrappings in tests are created with `random_bytes(32)` standing in
for the PRF output, plus a fixture `pkc_passkey_credentials` row so the
floor's liveness check has something real to count.

## The test files

### `tests/vault/sealedbox_test.php` — tier `safe`, env `any`

Pure crypto, no DB.

- Keypair/seal/open round trip; opening with a different secret key throws.
- AEAD round trip; flipping any single byte of nonce or ciphertext throws;
  decrypting with the right key but wrong AD throws; malformed / truncated /
  non-strict-base64 blobs throw. Blob prefixes are `v1.seal.` / `v1.aead.`.
- `wrapKey`/`unwrapKey` round trip; wrong KEK throws.
- Recovery codes: format is 26 Crockford chars grouped in fives; the alphabet
  never emits I/L/O/U; normalization equivalence — grouped, ungrouped,
  lowercase, and O→0 / I→1 / L→1 typos all derive the same KEK; a genuinely
  different code does not.
- KDF hygiene: same input + different salt → different KEK (both KDFs);
  passphrase salt length is validated; two encrypts of the same plaintext
  yield different blobs (fresh nonce).

### `tests/vault/vault_crypto_test.php` — tier `safe`, env `any`

The envelope dance and the splice defenses.

- newItemDek → sealItemDek → openItemDek → sealField → openField round trip.
- Splice A: row A's field ciphertext with row B's AD throws.
- Splice B: swapping two rows' sealed DEKs makes both field opens throw (the
  wrong DEK fails the AEAD even with the right AD).

### `tests/vault/vault_health_test.php` — tier `safe`, env `any`

`VaultHealth` reports every check as met/unmet/unknown, never false-passes:
an unverifiable check must be `unknown`, and the CLI checker exits non-zero
when any check is `unmet`.

### `tests/vault/vault_wrappings_test.php` — tier `db`, env `dev-only`

The wrappings model and the floor.

- `createWrapped` two-phase AD binding: unwraps with `adFor($vault, $id)`,
  throws with any other wrapping's AD; `uew_salt` persists what was passed;
  generation defaults to the vault's CURRENT `uev_key_generation`, not 1.
- `liveGenerations()` reflects live rows only (soft-deleted excluded).
- Floor scenarios (`assertWrappingDeleteSafe`): last passkey + 3 unused codes
  → allowed; last passkey + 2 unused codes → refused; used codes don't count;
  soft-deleted codes don't count; a passkey wrapping whose credential row is
  soft-deleted doesn't count; `$exclude_credential_id` excludes correctly.
- `cleanupRevokedCredential()` soft-deletes every wrapping of the credential
  and no others.

### `tests/vault/vault_unlock_window_test.php` — tier `db`, env `dev-only`

Requires APCu in CLI (`apc.enable_cli=1`); `harness_skip` with the ini
instruction when unavailable.

- open/isOpen/secretKey/close round trip; `secretKey` returns exactly what
  was stored; close wipes both the key and its metadata.
- Idle TTL: with `vault_unlock_idle_minutes` floored at 60s (the class
  clamps), an expired entry reads as locked. (Use the shortest reachable TTL;
  do not sleep longer than a few seconds — manipulate stored meta rather than
  waiting where possible.)
- Policy caps, no cron: rewrite the meta record's `armed`/`content`/`hb`
  timestamps backward and assert the next read reports locked and wipes —
  absolute cap, idle cap, and stale heartbeat each fire; a window with no
  metadata is never force-ended.
- Heartbeat: returns false with no window; stamps `hb` when one exists; a
  heartbeat alone must NOT extend the key's TTL (the deliberate asymmetry).
- `lock`/`lockAll`: wipe callbacks fire with the right scope argument
  (specific scope vs null); `lockAll` clears every scope and the `/dev/shm`
  markers; `hasAnyOpenWindow` sees a marker with future mtime, reclaims an
  expired one.

### `tests/vault/vault_ceremonies_test.php` — tier `db`, env `dev-only`

Setup and unlock cores, via `VaultCeremonies` with synthetic KEKs.

- Setup happy path: vault at generation 1; one passkey wrapping (salt null),
  N recovery wrappings and optional passphrase wrapping (salt recorded);
  window open afterward; key_file contains every wrapping row with salt and
  generation — assert the key_file alone suffices to unwrap the secret with a
  known recovery code (the backup-reconstruction promise).
- Setup atomicity: force a wrapping failure mid-ceremony (e.g. an
  over-length unlocker type via a test seam, or a constraint violation) and
  assert NO vault row survives — never a vault with zero unlockers.
- Setup refusals: existing vault; passphrase shorter than
  `SealedBox::PASSPHRASE_MIN_CHARS`; code_count clamps to [5,20].
- Recovery unlock: correct code opens; marks used; a used code never opens
  again; typo'd code (O for 0) opens; kill-switch ordering — open a window
  for a second fake session id first, unlock with a code, assert the other
  session's window is gone and only the current session's is open;
  `regenerate_recommended` flips when unused codes < 3.
- Passphrase unlock: opens; wrong passphrase refused; per-wrapping salt — a
  passphrase wrapping whose `uew_salt` differs from the current `uev_salt`
  still opens (simulates the two-generation state).
- Mixed-generation guard: with live wrappings in two generations, add-passkey
  /enroll-passphrase/regenerate-codes cores all refuse.

### `tests/vault/vault_rotation_crash_test.php` — tier `db`, env `dev-only`

The crown jewel. A synthetic consumer registered via `VaultUnlock::onReseal`
records the arguments it was called with and can be armed to throw; sealed
"items" are DEKs sealed via `VaultCrypto` and tracked by the test alongside a
per-item generation, mirroring the consumer contract.

- **R1 happy rotation**: gen 1→2. Consumer called once with the old secret,
  old generation 1, new public key, new generation 2; every gen-1 wrapping
  soft-deleted afterward; new recovery codes unlock; the drained generation's
  codes do not; items re-sealed by the consumer open under the new secret;
  key_file present and reconstructible.
- **R2 persist-phase failure**: force the transaction to fail and assert the
  vault row, salt, generation, and every original wrapping are byte-for-byte
  untouched — the ceremony left no trace.
- **R3 re-seal failure (the two-generation state)**: consumer armed to throw.
  Assert: ceremony returns an error; `uev` is at generation 2 but BOTH
  generations' wrappings are live; the authorizing passkey still unlocks (and
  unwraps the gen-1 secret on a rotation retry — lowest-generation
  selection); a gen-1 recovery code STILL UNLOCKS via its per-wrapping salt;
  enrollment cores refuse (mixed generations); then disarm the consumer,
  re-run the rotation, and assert convergence — the retry drains generation
  1, retires it, and exactly one generation remains live.
- **R4 orphan cleanup**: fabricate a wrapping tagged generation 3 while `uev`
  says 2; run a rotation; assert the orphan was soft-deleted before anything
  else and the ceremony completed normally.
- **R5 items sealed mid-brokenness survive**: in the R3 state, seal a new
  item to the (gen-2) public key, then complete the retry rotation (which
  drains gen 1 → the item's gen-2 DEK is untouched); assert the item still
  opens. This is the regression test for tour finding #5 — under the OLD
  ordering bug this item's key would have been unrecoverable.

### `plugins/mailbox/tests/mailbox_reseal_test.php` — tier `db`, env `dev-only`

The real consumer against real rows. Skip loudly if the mailbox plugin is
inactive.

- Seed a user with an alias grant and sealed messages in two generations plus
  a protected domain with live and pending sealed DKIM keys; run the reseal
  callback with the gen-1 secret; assert only gen-1 messages were re-sealed
  (gen bumped, new sealed key opens under the new secret, gen-2 rows
  untouched), both DKIM columns re-sealed, and the persisted FTS blob purged.
- Corrupt one message's `iem_sealed_key` and assert the callback attempts
  every row, counts the failure, and THROWS (the retire-blocking contract).
- DKIM-without-grants: a user owning a protected domain but holding no
  mailbox grants still gets the DKIM keys re-sealed.
- Sealed-field hook: a locked vault yields the `[locked …]` placeholder from
  `decryptSealedFieldStatic` and `VaultLockedException` from the File hook,
  never ciphertext or a fatal.

## What stays manual (and why)

A real passkey's PRF cannot be automated: Playwright's virtual authenticator
does not implement the PRF extension. The synthetic-KEK tests above cover
everything downstream of the PRF bytes; what they cannot cover is the
browser↔authenticator handshake itself. Manual checklist, run on dev before
each release that touches the vault:

1. Full setup ceremony with a real passkey; download the key file; confirm
   recovery codes render once.
2. Lock, unlock via passkey tap; unlock via a recovery code and confirm the
   alert email arrives and other sessions' windows died.
3. Key rotation with the passkey; confirm dropped-unlocker list and new codes.
4. `php maintenance_scripts/dev_tools/check_vault_health.php` on the target
   host (and on production before launch).
5. A sealed attachment request while locked returns HTTP 423, never bytes.

## Acceptance

- `php tests/run.php db` green with every file above discovered and declared
  (zero "undeclared" entries).
- The `safe` tier alone (pre-deploy gate) runs the three pure-crypto files.
- R1-R5 all pass; R5 is the named regression test for tour finding #5.
- Documentation: add a Tests line to `docs/sealed_vault.md` pointing at
  `tests/vault/`, and note the `apc.enable_cli` requirement for the window
  suite in that line.
