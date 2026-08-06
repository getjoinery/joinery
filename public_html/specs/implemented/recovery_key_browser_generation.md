# Recovery key: generate it in the browser

**Status:** BUILT. `includes/RecoveryKeySetupPanel.php` renders the whole state
machine; `assets/js/recovery-readiness.js` 1.1.0 adds `generateKeypair()` and
`attachGenerator()` with the no-auto-proving guard intact; `docs/backups.md`
§ Recovery key setup is browser-first with the CLI as fallback; the encoding
contract is pinned by `tests/backups/recovery_key_encoding_test.php`. Setting up
backup recovery needs no shell.
**Date:** 2026-08-03

**Deviation from § Scope, deliberate.** The spec named three surfaces and had all
three draw the shared panel. Built instead as **one setup surface plus links**:
`adm/admin_backups.php` draws the panel, `plugins/server_manager/views/admin/targets.php`
shows a standing state line and links to it, and the Recovery Readiness item stays
verify-only — when no key is configured it says to finish the setup rather than
offering a second place to generate one. This reaches the same goal by a shorter
route: the panel cannot drift across surfaces because only one surface renders it,
and there is one answer to "where do I set this up". `targets.php` 2.5 dropped the
three POST handlers left behind by its removed walkthrough form.

Both live gates are outstanding — see § Gate.

## The problem

Setting up backups on a site currently reads:

> Generate a keypair, keep the private half in your password manager, and paste
> the public half here.
> ```
> php /var/www/html/{site}/maintenance_scripts/sysadmin_tools/escrow_keypair.php generate --private-out ~/recovery.key
> ```

The operator has to leave the browser, get a shell on the server, run a CLI,
copy a base64 line back into the page, then find the private key file and move
it into a password manager by hand. That is the only step in the platform that
assumes shell access — and it sits on the one page whose entire job is making
sure the operator can recover. Someone running a hosted site may have no shell
at all, at which point backups simply cannot be turned on.

The file-on-disk detour is also the weakest part of the current flow: it writes
the private key to the server's filesystem (`~/recovery.key`), where the whole
design says it should never be. The instructions say to delete it afterwards.
Nothing checks.

## The shape of the fix

Generate the keypair in the page. The browser already does X25519 with WebCrypto
— `assets/js/recovery-readiness.js` opens the sealed possession challenge with
exactly the primitives needed to make a keypair (`subtle.generateKey` for
X25519, and the same `PKCS8_PREFIX` handling in reverse). Nothing new has to be
imported, and no framework is involved.

The private key is produced in the page, offered as a download and a copy
button, and never sent anywhere. Only the public half reaches the server, which
is already what the setting holds.

### The flow

1. **Generate.** One button. The page mints an X25519 keypair.
2. **Save it.** The private key is shown once, with a copy button and a
   "Download recovery.key" link (a `Blob`, so nothing is served from PHP). The
   text says plainly that this is the only copy and the platform will never have
   it.
3. **Save the public half.** The public key fills the declared
   `backup_recovery_public_key` field (via `SettingsFieldRenderer`, which now
   draws it) and the form is submitted. The site is in `unproven` state.
4. **Prove it, from where you saved it.** The existing ceremony runs unchanged:
   the operator pastes the private key back and the browser opens the challenge.

### The one decision that matters: no auto-proving

The private key is in memory at step 2, so the page *could* silently complete
the possession ceremony and hand the operator a fully-configured site in one
click. **It must not.**

The ceremony does not exist to prove the key is mathematically valid — the
server can see that. It exists to prove *the copy the operator saved actually
works*, because the failure this whole subsystem is built around is a shelf full
of archives nobody can open. Proving with the in-memory copy proves nothing
about the copy in the password manager, and would convert a real check into
theatre.

So the operator pastes it back. The friction is the feature, and it is the only
friction left in the flow.

## Browser support and the fallback

WebCrypto X25519 is Chrome 133+, Safari 17+, Firefox 132+. Older browsers get
the same treatment they already get in the ceremony code: a clear message and
the CLI command. `escrow_keypair.php` stays exactly as it is — it is also the
disaster-recovery unseal tool, and must keep working on a machine where the
platform is gone. What changes is its billing: it moves from the headline
instruction into a `<details>` disclosure for people who want it.

## Encoding contract

The browser must produce byte-identical material to the CLI, because the same
private key has to work in three places: the in-page ceremony, `escrow_keypair.php
unseal`, and any future recovery tooling.

- **Private key:** raw 32-byte X25519 scalar, base64, one line — the same thing
  `escrow_keypair.php generate` writes. Obtained by exporting `pkcs8` and
  stripping the 16-byte `PKCS8_PREFIX` already defined in the JS.
- **Public key:** raw 32 bytes, base64 — `exportKey('raw', publicKey)`.

libsodium clamps the scalar inside `crypto_scalarmult_base`, and WebCrypto
clamps during X25519, so a scalar generated on either side derives the same
public key on the other. This is asserted, not assumed — see the gate below.

## Scope

**Shared panel.** The recovery key setup appears on three surfaces today:
`adm/admin_backups.php`, `plugins/server_manager/views/admin/targets.php`, and
the Recovery Readiness item in
`plugins/server_manager/includes/RecoveryReadinessItems.php`. Two of them draw
their own version of the same box. Adding generation to each independently is
how they drift, so the panel is extracted once:

- **New** `includes/RecoveryKeySetupPanel.php` — renders the whole state machine
  (unconfigured / invalid / unproven / ready) for any page holding a FormWriter.
  Both admin pages call it. It draws the declared setting through
  `SettingsFieldRenderer` with `only`, as the fixed Backups page now does.
- `assets/js/recovery-readiness.js` — add `generateKeypair()`, the download and
  copy affordances, and the "you have not saved it yet" guard. Version bump.
- `adm/admin_backups.php`, `plugins/server_manager/views/admin/targets.php` —
  replaced by the panel call.
- `includes/BackupRecoveryKey.php` — no logic change. The message in
  `set_public_key()` already says "or generate one here"; it becomes true.
- `docs/backups.md` § Recovery key setup — rewritten browser-first, CLI as the
  fallback. Current state only: no "previously", no migration narrative.
- `docs/account_security.md` § Ceremony — confirm the description still matches.

## Gate

- **Round-trip vector (safe tier).** A keypair generated by a real browser is
  captured once and committed as a fixture. A PHP test asserts the platform can
  seal a challenge to that public key, that `escrow_keypair.php unseal` opens it
  with that private key, and that
  `sodium_crypto_box_publickey_from_secretkey(private) === public`. This is what
  locks the encoding contract; without it a subtle export change ships a keypair
  that looks fine and cannot open a backup.
- **Live, on dev, in a browser.** Generate → download → save → paste back →
  proven. Then run an actual encrypted backup and confirm the envelope seals to
  the new key's fingerprint.
- **Fallback.** Confirm the CLI path still completes end to end with the panel
  in its new shape.

## Open

- **Should the download be the only way to get the key**, or is copy-to-clipboard
  offered too? Clipboard is what a password manager wants, and is how most people
  will actually do it; it is also readable by anything else on the machine.
  Recommendation: offer both, lead with copy, and say what the download is for
  (an air-gapped save).
- **Does the page hard-block moving on until the operator confirms they saved it?**
  Recommendation: yes — a checkbox that enables the save button. It is the last
  moment the key exists.
