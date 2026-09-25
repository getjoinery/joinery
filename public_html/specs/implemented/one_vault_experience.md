# One vault experience — two kinds of key, one thing to unlock

**Status: IMPLEMENTED 2026-09-25 (see § As built). Drafted 2026-09-24, revised after review by public-html-5d (B1–B7,
Q1–Q4 folded in below). Owner decisions 2026-09-24: one set of recovery codes
(R6); a passphrase opens everything, and exists only where no passkey can
hold a key (R7, R8).** Owner decision (Q7 → a): a short core spec,
built before `specs/client_custody_mail.md` WP3. Arises from the Fortress mail
proof walk, where one mailbox needed two passkey unlocks, two setups, two sets
of recovery codes and a padlock that said "unlocked" over sealed mail.

## The problem, in the owner's words

"Why can't it be one vault? This double vault thing is confusing."

Today a person can hold up to four vaults: the server-custody **account
vault** (`user`, labelled "Mail & messages vault") and one browser-held vault
per client-custody scope (`drive`, `passwords`, `mail`). Each has its own
setup, its own passkey prompt, its own recovery codes, its own row on the
Security page and its own line in the padlock.

## Why there are two kinds of key, and must stay two

- The **account key** exists so the server can work on your data while you
  are signed in: the search index, AI, DKIM signing, reading Private mail. The
  server holds it, unwrapped, for the unlock window.
- A **browser-held key** exists so the server can never read that data, even
  while you are signed in (Fortress mail, end-to-end Drive folders, the
  password vault).

One key for both would hand the server, at every ordinary unlock, the key to
content it promises never to read. So the keys stay separate. **What becomes
one is everything the person sees and does.**

## Intent

One vault, as far as a person can tell:

1. **One unlock.** One passkey touch opens the account key and every
   browser-held key the person has.
2. **One padlock state**, one "Lock now", one idle lock.
3. **One name.** "Vault" everywhere a person reads it; scope names stay
   internal (and in the Security page's detail for those who look).
4. **One setup.** Setting up the vault once is enough; a browser-held key a
   feature needs later is made silently the first time the vault is open.
5. **One set of recovery codes** (R6; owner chose Q1 (b), 2026-09-24).

## Facts, verified 2026-09-24

- Server unlock: `vault_unlock_options` mints an assertion with PRF salt
  `_prfSalt('vault-kek')`; `vault_unlock_passkey` posts the credential and
  `PasskeyService::verifyDerivation()` (`includes/PasskeyService.php` ~550)
  reads **only** `clientExtensionResults.prf.results.first`.
- Client unlock: `vault_client_prf_options` mints an assertion with salt
  `_prfSalt(VaultScopes::prfContext($scope))` (`vault-{scope}-kek`); the
  browser keeps the output and posts nothing back.
- `assets/js/passkeys.js` already serializes `prf.eval.second` into the
  request and `prf.results.second` out of the response.
- WebAuthn PRF gives **two** outputs per assertion (`first`, `second`).
- `VaultScopes::prfContext()` derives one context per scope on purpose, "so
  two scopes can never share one"; see R2 for how that property is kept.
- Resume after reload (`specs/client_custody_mail.md` § R4a) keeps each
  open scope across reloads; the padlock learns the page's scopes through
  `JoinerySealed.want()` (`assets/js/vault-lock.js` 1.6).
- There are no production users (project memory), so existing client vault
  wrappings can be re-made rather than carried forever.

## Design

### R1. One touch, two outputs

The unified unlock asks for one assertion with
`prf.eval.first = salt('vault-kek')` (the account key's KEK, as today) and
`prf.eval.second = salt('vault-client-kek')` (a new **client root**). The
browser deletes `results.second` from the credential before posting it to
`vault_unlock_passkey`, so the server's view is byte-for-byte today's; a test
pins that the posted JSON carries no `second`. The client root never leaves
the browser. This is enough: `verifyDerivation` reads only `results.first`,
and the assertion signature never covers `clientExtensionResults`.

- `vault-client-kek` joins `VaultScopes::prfContexts()` so the server may
  mint it, and `PasskeyService::getDerivationOptions` learns to mint two
  evals (today it mints one).
- **Verify on real authenticators first** (iCloud Keychain, Google Password
  Manager, Windows Hello on dev): hmac-secret carries two salts, but not every
  platform authenticator surfaces `second`. An authenticator that returns only
  `first` falls back to today's per-scope prompt (R3).

### R2. Per-scope keys from the client root

Each client scope's passkey KEK becomes `HKDF-SHA256(client root, info
'vault-client-kek:' + scope)`, so scopes still get distinct keys (the
property `prfContext()` exists for) from one PRF output. The derivation lives
only inside `VaultKeyring`; consumers never see the root. A scope's existing
passkey wrapping (made under `vault-{scope}-kek`) is replaced by one under the
derived KEK the next time that scope is open — pre-production, so no
long-lived dual path.

- **What changes in the threat model, said plainly.** Today a page that
  opens one scope holds that scope's KEK only. After R2 an unlock yields the
  material for every scope. That is the point; it also means a script on any
  page with the vault open reaches every end-to-end area, not just the one
  in view (already true of anything served while unlocked).
- **Pinned:** the root is imported as a non-extractable HKDF `CryptoKey`,
  used only to derive the per-scope KEKs, and dropped at once; R4a's resume
  persists per-scope secrets only, never the root.
- **Which wrappings change where** (review Q2): WP2 replaces each client
  scope's **passkey** wrapping; its **passphrase** wrapping is left alone
  (R3); its **recovery** wrappings are replaced by WP5b's single set.

### R3. One ceremony, one padlock

- `JoineryVaultLock.unlock()` becomes the one ceremony: it runs R1, opens the
  server window, then opens every client scope the person holds (not only the
  ones the page named) with keys from R2, and hands each to `JoinerySealed`
  (which persists each for reload per R4a).
- The padlock reads open when the account key and every held client key are
  open; "Lock now" and the idle lock close all of them. The per-scope lines in
  its menu go away; the menu says "Vault — locked / unlocked".
- A scope whose passkey wrapping predates R2 falls back to that scope's own
  prompt once; R2's re-wrap makes it the last time.
- **A recovery code** opens both halves at once (R6).
- **A passphrase opens everything too** (R7).
- **Client rotation gets cheaper:** one touch yields the root, so
  `VaultClientRotation::begin` can take every scope's new passkey wrapping
  from one assertion instead of one per scope.

### R4. One setup

The Security page's vault setup creates the account vault as today. A client
scope's vault is created the first time a feature needs it **while the vault
is open**: keypair in the browser, passkey wrapping under the R2 key, and the
recovery wrappings from the one set (R6) — no separate ceremony, no second
"Set up" button.

- Silent creation needs the client root in hand (review Q3). A passkey
  unlock and a passphrase unlock (R7) both give it; a recovery-code unlock
  does not (the code opens existing wrappings only), nor does a passkey that
  returned no `second`. In those cases the feature asks for one passkey touch
  or the passphrase, then creates the scope.
- Silent creation needs the codes' client wrapping, so it comes after WP5b
  (review B3).
The mailbox banner, the Setup page's "Mail vault" row and the Fortress level
change then say "Unlock your vault", never "Set up mail vault".

### R5. One name

"Vault" in every person-facing string: the padlock, the banners, the Setup
rows, the unlock ceremony. The registry labels (`vault_scopes.json`, plugin
`vaultScopes`) remain for the Security page's detail list and for logs.
Resolves client_custody_mail Q6.

### R7. The passphrase opens everything, and the server never sees it

Decided (owner, 2026-09-24, option B). The passphrase is not a rare backup:
it is how accounts unlock whose passkeys cannot produce a key (iPhone before
iOS 18, Windows 10, older Firefox and Android, most hardware security keys),
which the owner expects to be many (project note, setup wizard, 2026-08). So
one passphrase opens the account key and every end-to-end key, in one step.
It exists only on such accounts (R8).

- **Computed in the browser.** The browser runs Argon2id once over the
  passphrase with the account vault's salt and KDF params
  (`VaultCrypto.kekFromPassphrase`, which today imports the result; a raw
  variant returns the 32 bytes), then splits the result with HKDF:
  `kek_account = HKDF(h, 'vault-passphrase:account')` and
  `client root = HKDF(h, 'vault-passphrase:client')`. The per-scope KEKs come
  from that root as in R2 (`'vault-client-kek:' + scope`). The browser posts
  `kek_account` only; the passphrase itself never leaves it.
- **The server stops deriving.** `vault_unlock_passphrase` and
  `vault_passphrase_enroll` take `kek_account` (32 bytes) instead of the
  phrase; `SealedBox::kekFromPassphrase` on the server retires with them. The
  minimum length (`SealedBox::PASSPHRASE_MIN_CHARS`) is enforced in the
  browser before derivation and stated on the form.
- **One passphrase.** A client scope's own passphrase wrapping (today a
  separate phrase the scope's setup offered) is replaced by one under the
  R7 per-scope KEK the next time the scope is open; pre-production, no dual
  path.
- **What it costs, said plainly.** A server that has been broken into holds
  `kek_account` from the next passphrase unlock and can guess passphrases
  offline, each guess costing one Argon2id run, until one reproduces it; the
  same guess then yields the end-to-end keys. So for an account that unlocks
  by passphrase, end-to-end content is as strong as the passphrase against a
  compromised server, not "only your devices". These accounts already accept
  that for their account vault ("opens with memorized secrets alone"); R7
  extends it. The Fortress card says so to a passphrase account
  (`client_custody_mail.md` § R12 gains a line for it), and the passphrase
  form keeps its warning.
- **Surfaces that change:** `vault_unlock_passphrase_logic`,
  `vault_passphrase_enroll_logic`, the passphrase fallback in
  `VaultCeremonies::setup` (the setup wizard's PRF-failure path), the phrase
  prompts in `assets/js/vault-lock.js`, `mailbox_reader.js` and
  `chat_view_body.php`, and `VaultKeyring`'s client passphrase setup and
  unlock. Tests: no action accepts a raw passphrase (descriptors and a static
  check); one phrase opens the account key and a client scope in a fixture;
  a wrong phrase opens neither.

### R8. No passphrase where a passkey can hold the key

Decided (owner, 2026-09-24): a passphrase is weak, so it is allowed **only**
on an account whose passkeys provably cannot hold a key —
`Passkey::userNeedsPassphraseFallback()`: at least one passkey, and every one
of them failed a real key derivation or registered the old U2F way. Nobody
reaches it by owning no passkey (they are sent to enrol one first). An
account with a working passkey has no passphrase: a lost device is what the
recovery codes are for (R6), and most passkeys sync across a person's devices
anyway.

- **The account vault** already refuses a passphrase-only setup unless the
  account qualifies (`VaultCeremonies::setup`, ~74). Unchanged.
- **The optional "bypass phrase"** on an account with a working passkey goes
  away. `vault_passphrase_enroll` refuses unless the account qualifies, and
  the Security page stops offering it there.
- **Browser-held vault setup** gets the same check (review, B8): today
  `vault_client_setup` accepts a passphrase-only vault from anyone, and the
  setup box offers "Set up with a passphrase only" to everyone. The server
  refuses a passphrase wrapping unless the account qualifies, and
  `vault_client_status` answers `passphrase_allowed` so the box offers the
  passphrase only then, with the weaker-protection warning. Under R7 a
  qualifying account's browser-held vaults take the one passphrase and are
  never set up separately.
- **Existing phrases.** Pre-production: a passphrase wrapping on an account
  that does not qualify (the account vault's bypass phrase, or a browser-held
  vault's own passphrase) is removed at that vault's next open, and the
  person is told why. The unlocker floor still holds: those accounts have a
  working passkey and their recovery codes.
- **An account that gains a working passkey** (a phrase-only account whose
  new device can hold a key): once the new passkey holds a wrapping, the
  passphrase is removed the same way, with notice. The account is back to
  passkey plus recovery codes.
- **The warnings.** Where a passphrase is offered it says what it costs:
  end-to-end content is as safe from a hacked server as the passphrase is
  hard to guess (R7).
- Tests: `vault_passphrase_enroll` and `vault_client_setup` refuse a
  passphrase for an account with a vault-capable passkey and accept it for
  one whose passkeys are all incapable; `vault_client_status` reports
  `passphrase_allowed` accordingly; an existing phrase on a qualifying-no-more
  account is removed at next open.

### R6. One set of recovery codes, and the server never sees a code

Decided (owner, 2026-09-24, Q1 → b): one set of codes opens both kinds of
key. That holds only if **no recovery code ever reaches the server**, for
either half: a server that saw a code could derive the end-to-end key from it.
Today the account vault's codes are generated on the server
(`VaultCeremonies::setup`) and `vault_unlock_recovery` posts the code itself,
so both move to the browser:

- **Generated in the browser.** The browser makes the codes
  (`VaultKeyring.generateRecoveryCode`, ~130 bits each), shows them once, and
  sends the server only what it stores: per code, the account secret wrapped
  under `kek_account = KDF(code, account salt, info 'vault-recovery:account')`.
  Each held client scope's recovery wrapping is made under `kek_client =
  KDF(code, that scope's salt, info 'vault-recovery:client:' + scope)`.
- **Used in the browser.** Recovery unlock derives both KEKs from the typed
  code. It posts `kek_account` (never the code) to a recovery-unlock action
  that finds the wrapping it opens, opens the server window and marks that
  code used; it opens each client scope locally with `kek_client`, and marks
  the matching client wrappings used. A code is spent for both halves at once.
- **Why posting kek_account is safe.** It is a one-way function of a code
  with ~130 bits of entropy, so the server cannot recover the code from it,
  and without the code it cannot compute `kek_client`.
- **Regenerating** a set (Security page) is the same browser-side flow, with
  the vault open; the old set is retired for both halves together.
- **The KDF** (review): today the server derives a code's KEK with BLAKE2b
  (`sodium_crypto_generichash`, `SealedBox.php` ~547) and the browser with
  SHA-256 (`vault-crypto.js` ~109). Both move to one browser-computed
  HKDF-SHA256, and the server stops deriving: it takes the posted
  `kek_account` straight into `unlocker()` (`probeWrappings` already can).
  Pre-production: re-make the wrappings, no dual path.
- **Atomic spend** (review B5): each set carries an id, recorded on every
  wrapping row made from it with the code's index. The one recovery action
  takes `kek_account`, finds its wrapping, and in one transaction marks it and
  every client wrapping with the same set id and index used, returning those
  client wrappings for the browser to open. No per-scope probing, and no
  failure can spend one half only.
- **What a recovery use evicts** (review B6): today it runs
  `VaultUnlock::lockAll` for the account vault. It also drops every R4a
  resume half the person holds (all their PHP sessions, via a per-user
  revocation stamp the resume `get` checks) and flags every device holding a
  client scope (`sde_vault_scopes`) to re-confirm. Other tabs' in-memory
  sessions end at their next resume check or idle lock; the notice email says
  so.
- **Rotation** (review B2): `VaultCeremonies::rotate` and
  `completePendingRotation` mint codes on the server today; they take
  browser-made wrappings exactly as setup does, or a rotation would leave
  codes that open the account half only.
- **Rate limit** (review Q4): against a posted 256-bit KEK a rate limit does
  nothing for brute force; it stays only to stop hammering, and the
  "a recovery code was used" notice stays.
- **The readiness ledger** (review B7): `RecoveryReadiness::vaultItems` shows
  one item for the one set (one unused count, `codes_since` from the set's
  creation time), and its dry run becomes the browser one client scopes
  already use (`recordClientDryRun`).
- **Existing vaults.** Codes the server generated have been seen by the
  server, so they can never open end-to-end content. The first unlocked
  visit after this ships asks the person to replace their codes (one new set,
  browser-made); until then end-to-end recovery uses the scope's current
  codes. Pre-production, so this is a one-time step for dev accounts.
- **What stays on the server:** checking which wrapping a posted
  `kek_account` opens, the used flag, the "a recovery code was used" notice,
  rate limits on the recovery action.

## Work packages

- **WP0. Authenticator check.** On dev, with each platform authenticator in
  use, confirm a two-salt PRF assertion returns both outputs (review Q1).
  Record the result here; an authenticator that drops `second` keeps the
  per-scope prompt.
- **WP1. One touch.** `vault_unlock_options` also returns the client-root
  salt; `vault-lock.js` asks for both outputs and strips `second` before
  posting; `VaultKeyring` derives per-scope KEKs (R2) and opens held scopes;
  `JoinerySealed` accepts sessions opened this way. Tests: a PHP test that
  `vault_unlock_passkey` never receives `results.second` (the JS builds the
  post; pin with a static check plus a JS self-check); `verifyDerivation`
  unchanged.
- **WP2. Re-wrap.** On a scope's first open after WP1, add the R2 passkey
  wrapping and remove the old one (`vault_client_add_wrapping` /
  `remove_wrapping`). Test on a fixture vault.
- **WP3. One padlock, one lock.** R3 in `vault-lock.js`; `JoinerySealed.want`
  becomes "also open these" rather than a menu line.
- **WP4. One set of recovery codes (R6)** — before one setup (review B3).
  Browser-made codes; set id and index on every wrapping; the one recovery
  action and its atomic spend; eviction (B6); rotation taking browser-made
  wrappings (B2); the replace-your-codes step. Every surface that makes or
  takes a raw account-vault code today changes (review B1):
  - `vault_unlock_recovery_logic` (the raw code);
  - `openWithUnlocker` with a code as the unlocker, in
    `passkey_register_verify_logic` (~96), `vault_passphrase_enroll_logic`
    (~58), `vault_add_passkey_verify_logic` (~64),
    `vault_regenerate_codes_logic` (~76);
  - `VaultCeremonies::setup`, `rotate`, `completePendingRotation` (codes
    generated on the server);
  - `RecoveryReadiness::dryRunVaultCode`, `verifyMemberVaultCode` (server
    dry run → the browser dry run), and `vaultItems` (B7);
  - the code entry in `mailbox_reader.js` `unlockVault`,
    `plugins/joinery_ai/includes/chat_view_body.php`, `assets/js/vault-lock.js`;
  - tests: `tests/vault/vault_ceremonies_test`, `vault_recovery_concurrency_test`,
    `vault_rotation_crash_test`, `tests/account_security/vault_unlock_no_second_factor_test`.
  Unaffected, checked: admin second-factor reset and account recovery
  (`PasswordResetAuthorizers.php` keeps vault codes out), device handoff (seals
  a scope secret to the device, no KEK), native apps (web-session bridge).
  Tests: no action accepts a raw recovery code (descriptors plus a static
  check); a code opens both halves in a fixture; a used code opens neither; a
  failure mid-spend spends nothing; a recovery use drops resume halves in
  another session.
- **WP4b. One passphrase, only where needed (R7, R8).** The browser-side
  derivation and split, the two actions taking `kek_account`, the client
  passphrase wrappings re-made under the per-scope KEKs, the Fortress card
  line for passphrase accounts; the eligibility check on
  `vault_passphrase_enroll` and `vault_client_setup` (B8),
  `passphrase_allowed` in the status, the Security page no longer offering a
  bypass phrase to a passkey account, and the removal of phrases that no
  longer qualify. Ahead of one setup, like WP4.
- **WP5. One setup.** R4; the mailbox banner, the Setup page row and the
  domain editor's Fortress step move to "Unlock your vault".
- **WP5b. One name.** R5 strings.
- **WP6. Docs.** `docs/sealed_vault.md`, `docs/account_security.md` (its
  "Bypass phrase — optional fallback" entry becomes the R8 rule),
  `docs/passkeys.md`.

## As built (2026-09-24): deviations from R1–R8

Recorded here so a reviewer reads the design the code has, and why.

- **D1. A root vault, not a PRF-derived client root (replaces R1's
  `vault-client-kek` and R2's per-scope derivation from the PRF output).** A
  `root` client-custody scope holds a random secret. Every unlocker opens it:
  each passkey's second output (`prf.eval.second` = context `vault-root-kek`),
  each code's root half, and the phrase's root half. A content scope holds one
  `root` wrapping under `HKDF(root secret, 'joinery-vault:scope:v1:' + scope)`.
  Why: a key derived directly from the PRF output differs per passkey. With
  it, a second passkey could not open content scopes made under the first,
  and silent creation (R4) would need the passkey present. With a random
  root, any unlocker opens the root and the root opens everything.
- **D2. Every browser-side derivation is salted by the root vault's salt**:
  codes (account half `HKDF(code, root salt, 'joinery-vault:recovery:account:v1')`,
  root half `SHA-256(root salt ‖ code)`) and the phrase (Argon2id with the root
  salt and params, then HKDF per half). The info strings differ from R6/R7's
  sketch (`joinery-vault:*:v1`). The root is always created in the same
  transaction that gives the account vault its codes (setup, code-set
  adoption), so the salt exists wherever a code does. The key file names the
  salt and derivation (`code_kdf`).
- **D3. Legacy content scopes keep their own unlockers.** A content scope made
  before the root keeps its passkey, recovery and passphrase wrappings (removing
  them needs a step-up). It opens once by its own ceremony and is then given a
  `root` wrapping. WP2's replace-the-passkey-wrapping step is not built.
- **D4. Adoption is the migration (R6 "existing vaults").** An account vault
  whose codes carry no set id gets a browser-made set, and the root vault, in
  its next `vault_unlock_passkey` (`adopt_code_set` + `root`). The padlock
  shows the new codes once. No per-scope "replace your codes" step.
  Decided (owner, 2026-09-25, review B3 → a): there is no other way across. An
  account from before the root converts only at a passkey unlock; one that
  cannot (phrase only, or its passkey lost first) is set up again by hand. Dev's
  three account vaults (users 1, 4497, 4500) all have a passkey; the owner's
  accounts on jeremytunnell each need one passkey unlock after the release.
- **D5. A passkey the root does not know yet.** When a passkey opens the
  account vault but has no root wrapping, the ceremony asks once for a passkey
  that does. The root then learns the first passkey from the second output
  already in hand, with no step-up because that passkey already opens the
  account vault. A passkey added on the Security page is taught to the root at
  once: activation reuses its touch, and a new registration takes one extra
  tap while the root is open.
- **D6. The chip's menu is one line.** "Vault", Unlock or Lock now. The server
  window ending anywhere locks the browser-held vaults too.
- **D7. `SealedBox::kekFromPassphrase` is removed; `kekFromRecoveryCode`
  stays** only for the readiness dry run of an account vault with no root.
- **D8. The root's codes and phrase change only with the account's.**
  `vault_client_replace_recovery` refuses the root, and `vault_client_remove_wrapping`
  removes only a passkey from it; a root is never created without the account
  vault's paired code set (review B4, B5).
- **D9. A root passkey wrapping replaces that passkey's existing one** (no
  step-up when the account vault already has the passkey), so a bad blob heals
  at that passkey's next unlock (review B7).
- **Not built:** root-key rotation, and it is refused
  (`VaultClientRotation::assertCanBegin`; the Security page offers no rotate
  button on the root's card): content vaults' `root` wrappings are under a key
  derived from the root's secret, so a root rotation would orphan them (review
  B1). The account vault's rotation replaces the root's code (and phrase)
  twins with it; the root's own keypair does not rotate. Rotating a content
  vault that opens through the root is not built either (its card is not shown).

## Acceptance

With the account vault and the mail vault set up: reload the mailbox, touch
the passkey once, and the padlock, the banner and the contacts panel all
read open; reload, still open (R4a); Lock now, everything shuts.
