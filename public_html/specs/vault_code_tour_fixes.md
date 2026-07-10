# Sealed Vault — Code-Tour Fixes and Improvements

Findings from the guided code tour of the Sealed Vault (2026-07-10). Items
are graded: **fix** (behavior should change), **consider** (a dial worth
discussing), **note** (design tension to be aware of, may need no change).

**Status: all items (1-11) are IMPLEMENTED (2026-07-10).** Item 3 was
resolved as intended-behavior (heartbeat never extends the idle TTL —
deliberate; comment aligned in `VaultUnlock.php`). The `uew_salt` column
(item 9) is applied to the dev database via `update_database`.

## 1. Recovery-code normalization should map Crockford confusables — fix

`SealedBox::normalizeRecoveryCode()` (`includes/SealedBox.php:202`) strips
non-alphanumerics and uppercases, but does not apply Crockford base32's
canonical decode substitutions: `O → 0`, `I → 1`, `L → 1` (`U` is excluded
from the alphabet and has no substitution). A user re-typing a printed code
who reads `0` as `O` is told the code is wrong, on the path where they may be
using their last remaining unlocker.

Change: add the three substitutions to `normalizeRecoveryCode()` before
uppercasing/stripping. Backward-safe — generated codes never contain
I/L/O/U, so the substitution only converts previously-failing inputs into
successes; no stored hash changes.

Add a `tests/` case: a known code entered with `O`-for-`0` and `l`-for-`1`
typos must derive the same KEK as the clean form.

## 2. Passphrase KDF cost profile — consider

`SealedBox::kekFromPassphrase()` uses Argon2id with
`OPSLIMIT_INTERACTIVE` / `MEMLIMIT_INTERACTIVE` (~64 MB). This is the
lightest legitimate profile. The passphrase unlocker's threat model is an
attacker holding a copy of the database (the wrapping row + salt) grinding
guesses offline; `MODERATE` (~256 MB, noticeably slower) buys real margin at
the cost of ~1s extra unlock latency on the passphrase path only (passkey and
recovery-code unlocks are unaffected).

If changed, existing passphrase wrappings must be re-wrapped on next unlock
(the KDF params are not stored in the blob — a params field in the wrapping
row, or a v2 blob kind, would be needed to migrate lazily). Decide before
first production users; there are none yet, so a clean switch is currently
free.

## 3. Presence heartbeat does not extend the APCu key TTL — note

`VaultUnlock::heartbeat()` stamps window metadata but does not re-store the
`vault:` APCu entry, so the idle TTL (`vault_unlock_idle_minutes`, default
30) is extended only by content decrypts (`secretKey()` reads). A user with a
visible, beating tab who reads one message for 35 minutes loses the window
despite continuous presence. May be intended (idle = "no decrypts", heartbeat
exists only to end windows *early* on absence) — but the beacon comment in
`VaultUnlock.php` says presence should keep the window alive, so intent and
behavior disagree somewhere. Decide which is right and align code or comment.

## 4. capsForUser() fails open on error — consider

`VaultUnlock::capsForUser()` (`includes/VaultUnlock.php:144`) wraps the
security-level lookup in `catch (\Throwable) { /* fall through to no caps */ }`.
A transient DB error during unlock silently opens the window with NO
Fortress/Private caps — the user who configured the strictest policy gets the
laxest window, invisibly. The caps are described as hard stops in the spec, so
this should fail closed: on error, either refuse to open the window or apply
the strictest (Fortress) caps rather than none.

## 5. Rotation flips the vault row BEFORE persisting new-generation wrappings — fix (critical)

`logic/vault_rotate_verify_logic.php:118-127` saves the `uev` row (new public
key, salt, generation) and only THEN creates the new generation's wrappings.
`docs/sealed_vault.md` § Key rotation specifies the opposite order —
wrappings first, then flip — and the doc's order is the correct one.

Failure window: a crash/exception after `$vault->save()` but before the first
successful `createWrapped()` leaves the vault advertising a public key whose
secret exists nowhere (it died with the request). Every item sealed from that
moment until a successful re-rotation (incoming mail seals unattended) is
stamped with the orphaned generation. The retry ceremony authorizes from the
lowest live generation, drains only `iem_key_generation = old_generation`
(confirmed in `plugins/mailbox/includes/bootstrap.php:91`), and never touches
the orphaned generation's items — whose key is unrecoverable. **Permanent,
silent data loss**, and the broken state persists invisibly: unlock still
works (old wrappings), but no new mail can ever be read.

Change: reorder — create all new-generation wrappings first, then flip the
`uev` row; ideally both inside one DB transaction. Handle the mirror-image
crash (orphan future-generation wrappings with an unflipped `uev` row) by
soft-deleting any wrapping with `uew_key_generation > uev_key_generation` at
ceremony start. Add a test that kills the ceremony between the two steps and
asserts mail sealed afterward is still recoverable.

## 6. Setup path accepts any non-empty passphrase — fix

`vault_passphrase_enroll_logic.php:38` enforces a 12-character minimum;
`vault_setup_verify_logic.php:89` wraps the secret key under ANY non-empty
passphrase (1 character suffices). A weak passphrase is an offline-guessable
unlocker for a database thief — the strongest lock on the vault is worth
exactly its weakest wrapping. Apply the same minimum (shared constant or
helper) in setup.

## 7. Setup ceremony is not atomic — consider

`vault_setup_verify_logic.php` saves the vault row, then creates wrappings
one by one. A crash after `$vault->save()` but before the first wrapping
leaves a vault with zero unlockers: setup refuses to run again ("already set
up"), every unlock fails. No content is lost (nothing sealed yet), but the
account is stuck until manual DB surgery. Wrap setup in a DB transaction, or
let setup treat a vault with zero live wrappings and no sealed content as
re-runnable.

## 8. Unlock uses an arbitrary wrapping when two generations are live — note

After a partial rotation (re-seal callback failure), the authorizing passkey
holds live wrappings in two generations. `vault_unlock_passkey_logic.php:49`
takes `$wrappings->get(0)` (DB order) — whichever generation that happens to
be, the window holds one secret and items of the other generation read as
locked/broken until the rotation is re-run. Deterministically preferring the
current `uev_key_generation` wrapping would make the interim state
predictable. Low priority; the state converges on rotation retry.

## 9. Salt rotation strands old recovery/passphrase wrappings in two-generation states — OPEN

Found while implementing #5. Rotation replaces `uev_salt`, but recovery-code
and passphrase KEKs derive from the salt as it was at wrapping time. In the
two-generation state a failed re-seal legitimately leaves behind (both
generations' wrappings live), the old generation's recovery codes and
passphrase can no longer derive their KEKs — the unlock endpoints read the
CURRENT `uev_salt`. The doc's resumability claim ("old wrappings still unwrap
the old secret") holds only for passkey wrappings (PRF KEKs are
salt-independent). Practical exposure: a user whose rotation half-fails and
who then loses their passkey before re-running it cannot use their old
printed codes (new codes exist as wrappings but were never shown — the
ceremony errored). Mitigations that exist: the setup/rotation `key_file`
download contains the salt as of that ceremony.

Fix (implemented): each wrapping is self-contained — a nullable `uew_salt`
column records the KDF salt the wrapping was created under (null for passkey
wrappings); unlock paths derive per-wrapping KEKs from it, falling back to
`uev_salt` for null/legacy rows. Alternative considered and rejected as a
band-aid: never rotating the salt (leaves Argon2id salt reuse across
generations and doesn't make wrappings self-describing).

## 10. Post-rotation enrollments mislabeled their generation — fix

Found while implementing #9. `createWrapped()` defaulted
`$key_generation = 1`, and every enrollment path (add passkey, enroll
passphrase, regenerate codes) used the default — so on a vault at generation
2+, a new wrapping was tagged generation 1 while actually wrapping the
current secret. A later rotation would then never retire it (retirement
selects the drained generation), leaving a live wrapping that unwraps a
retired secret, miscounts in the unlocker floor, and can open a window whose
key opens nothing.

Fix (implemented): the default resolves to the vault's CURRENT generation;
every enrollment call site passes it explicitly; and because the in-window
secret's generation is ambiguous in a two-generation state, all three
enrollment ceremonies now refuse while `UserEncryptionWrapping::
liveGenerations()` reports more than one — "finish your rotation first."

## 11. Rotation response was missing the key_file backup payload — fix

`docs/sealed_vault.md` § Backups promises both setup and rotation return a
`key_file` payload for the client to offer as a download; the rotation
response didn't include one. Implemented: rotation now returns the same
structure as setup, and both now include each wrapping's `salt` and
`key_generation` (per #9/#10 the wrapping rows are self-describing, so the
backup must carry those fields to actually reconstruct them).
