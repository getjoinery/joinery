# Passkey Vault Capability Detection

**Status:** Drafted, unbuilt. All open decisions resolved 2026-08-05.
**Date:** 2026-08-05
**Related:** `docs/passkeys.md`, `docs/sealed_vault.md`

## Problem

A U2F-only security key enrolls as a passkey successfully, then fails at vault
activation with a browser dialog that says "this security key can't be used" and
nothing else. The user has no way to tell whether they did something wrong, need
to change a setting on the key, or own a key that can never do the job.

The two steps ask the authenticator for different things:

- **Enrollment** asks for user verification *preferred* and requests PRF opportunistically
  (`PasskeyService::_authenticatorSelection()`). A U2F-only key satisfies that with a
  touch, so it enrolls and works for sign-in.
- **Vault activation** is a PRF derivation ceremony: user verification **required** plus
  the PRF extension (`PasskeyService::getDerivationOptions()`). A key that can neither
  verify a user nor evaluate hmac-secret is ruled ineligible by the browser before the
  request reaches the site.

So the outcome is correct — the key genuinely cannot unlock a vault — but it is
delivered at the worst moment, by the wrong party, in words that describe nothing.

We cannot improve the failure itself. The browser returns a deliberately generic
`NotAllowedError` for every assertion failure, indistinguishable from the user
cancelling the prompt; that opacity is an anti-fingerprinting measure and is not
going to change. What we can do is know the answer at enrollment and never let the
user reach that dialog.

## What is actually detectable

A U2F-only key and a FIDO2 key with no PIN are distinguishable at registration,
and the distinction is exactly the one that matters to the user:

| Signal in the registration response | U2F-only key | FIDO2, no PIN | FIDO2 + PIN |
|---|---|---|---|
| `clientExtensionResults.prf.enabled` | false | **true** | true |
| `clientExtensionResults.credProps.rk` | false | true | true |
| `authenticatorAttachment` | cross-platform | cross-platform | cross-platform |

A FIDO2 authenticator reports PRF support at creation whether or not a PIN is set —
hmac-secret is a capability of the key, not a permission granted by the PIN. So
`prf.enabled: false` **and** a non-discoverable credential **and** cross-platform
attachment together mean the browser fell back to CTAP1: a U2F-only key, which can
never unlock a vault regardless of what the user does to it.

`prf.enabled: true` followed by a failed activation means the opposite: the key
supports the mechanism and could not verify the user, which in practice means no PIN
is set.

Two of those three signals are thrown away today. `verifyRegistration()` records
`prf.enabled` into `pkc_prf_capable` and nothing else; the `credProps` extension is
never requested; `assets/js/passkeys.js` does not forward `authenticatorAttachment`.
Adding them is small — the JS already passes `clientExtensionResults` through
generically, so `credProps.rk` arrives for free once the extension is requested.

## Goals

1. A key that can never unlock the vault is identified **at enrollment** and labelled
   as sign-in-only, with no vault action offered on it.
2. A key that could unlock the vault but currently cannot gets a message naming the
   likely cause (no PIN) rather than a generic failure.
3. Registration-time reporting never hard-blocks an attempt that might succeed.
4. Activating a specific passkey activates *that* passkey, or fails saying so.
5. No existing credential's behaviour changes.

## Non-goals

- Distinguishing "no PIN set" from "PIN set but user cancelled" at failure time.
  The browser does not tell us, and no amount of client code recovers it.
- Blocking enrollment of a U2F-only key. Sign-in-only is a legitimate use and one
  the platform should keep supporting.
- Attestation-based key identification. Attestation conveyance stays `none`.

## The capability rule

Three states, held as a derived answer rather than a stored verdict:

- **`capable`** — the credential has evaluated PRF at least once. `pkc_prf_capable`
  is already upgraded to true on the first successful derivation
  (`PasskeyService::verifyDerivation()`), so this is evidence, not a guess.
- **`incapable`** — PRF not reported at creation, `credProps.rk` explicitly false,
  and attachment explicitly `cross-platform`. All three must be *known* and negative.
- **`unknown`** — anything else.

A credential enrolled before this spec has no `credProps` or attachment recorded, so
it reaches `incapable` by a second evidence set drawn from what *was* stored:
`pkc_prf_capable` false, the registration-time `uvInitialized` flag explicitly false,
and `pkc_transports` non-empty and lacking `internal`. Same conclusion — the browser
fell back to CTAP1 — from the signals available. Either evidence set is sufficient;
neither is required. See Backfill below for why `uvInitialized` is trustworthy here
and `credProps` is still the better signal going forward.

`unknown` is permissive: the ceremony is still offered and still allowed to run. This
preserves the reason behind the existing comment at `PasskeyService.php:343` — Windows
Hello omits `prf.enabled` at creation and evaluates PRF fine at assertion, so
registration-time reporting alone must never be the thing that stops an attempt.

`incapable` is provably safe to act on in one specific way: a credential that never
supported PRF cannot ever have completed a derivation, so it cannot hold a vault
wrapping. Excluding it from a vault ceremony can therefore never remove a working
unlocker.

## Phase 1 — Capture the signals

**`includes/PasskeyService.php`** (bump `@version`)

1. `getRegistrationOptions()` — request **both** the `credProps` extension and PRF,
   unconditionally. The `$prf_capable_requested` parameter is removed along with the
   `prf_capable_requested` field the browser posts to `passkey_register_options`.

   This is a correctness fix, not tidying. PRF can only be enabled at creation time
   and is inert on an authenticator that lacks it — the JS already comments as much
   and always passes the flag — but because the request is a caller-supplied
   parameter defaulting to false, `pkc_prf_capable = false` means "did not report PRF"
   only if PRF happened to be asked for, and no row records whether it was. A future
   API consumer that omitted the flag would mint credentials that look incapable and
   are not. Asking unconditionally makes `false` mean one thing forever.
2. `verifyRegistration()` — read `clientExtensionResults.credProps.rk` and the
   top-level `authenticatorAttachment` from the client response and persist them.
   Both are absent-tolerant: a client that reports neither yields nulls, which land
   the credential in `unknown`.

**`assets/js/passkeys.js`** — `encodeAttestationResponse()` forwards
`credential.authenticatorAttachment` when the browser provides it.

**`data/passkeys_class.php`** — two nullable columns, null meaning "not reported":

```php
'pkc_discoverable' => array('type'=>'bool', 'is_nullable'=>true),
'pkc_attachment'   => array('type'=>'varchar(16)', 'is_nullable'=>true),
```

Schema lands through `update_database` from the field specifications. No migration.
Both are API-readable — the owner is the only reader of their own passkeys already
(`Passkey::authenticate_read`).

**`Passkey::vault_capability(): string`** — returns `capable` / `incapable` /
`unknown` per the rule above. One method, one place, so UI and ceremony agree.

### Backfill

Existing credentials are classified from data already stored — no re-enrollment, no
ceremony, no user action (D3). `pkc_source_json` is the library's serialized
`CredentialRecord`, which carries `uvInitialized`: whether user verification was
performed at registration. A CTAP1 fallback cannot verify a user, so `false` there is
the same evidence `credProps.rk: false` provides, from a field we have held all along.

A migration in `/migrations/` — a data change, not a schema change — walks live
credentials and stamps `pkc_discoverable = false` / `pkc_attachment = 'cross-platform'`
on rows meeting the backfill evidence set, so the stored signals and
`vault_capability()` agree without a second code path reading `pkc_source_json` at
runtime.

Three conditions guard it, and all three must hold:

1. `pkc_prf_capable` is false. A FIDO2 authenticator reports PRF whether or not a PIN
   is set, so this alone excludes every PIN-less FIDO2 key — the misclassification
   that would actually hurt, since hiding activation on a key that a PIN would fix is
   worse than leaving it `unknown`.
2. `uvInitialized` is **explicitly** `false`. Null means the flag was never recorded,
   which is not evidence of anything and leaves the row `unknown`.
3. `pkc_transports` is non-empty and does not contain `internal`. Platform
   authenticators are excluded outright: Windows Hello reports no PRF at creation and
   evaluates it fine at assertion, and it is the known false negative this whole
   three-state design exists to accommodate.

Against current dev data the rule marks zero rows — the two `prf_capable = false` rows
there are both `internal` with `uvInitialized = true`, correctly untouched. That is
the expected shape of a good run: the backfill should mark U2F-only keys and nothing
else, so a run that marks a large fraction of the estate is a signal the rule is
wrong, not that the estate is bad.

The migration logs the credential ids it marks. A row marked in error is recoverable
by re-enrolling the key, and cannot cost anyone access to a vault — an `incapable`
credential holds no wrapping by construction, and Phase 3.2 filters unlock prompts by
wrapping rather than by capability precisely so that a classification mistake can
never reach the unlock path.

## Phase 2 — Say it at the right moment

**`views/profile/security.php`**

1. **At enrollment.** When a vault exists and the freshly enrolled credential is
   `incapable`, replace the current chain-into-activation with a modal the user must
   answer (D1): the passkey was added and will sign you in, this is a U2F-only
   security key, and it cannot unlock your vault — **Keep for sign-in** or **Remove
   it**. No derivation ceremony is attempted, so the browser dialog never appears.
   `capable`/`unknown` keep today's behaviour — chain straight into activation.

   The modal necessarily comes *after* the credential exists: capability is only
   knowable from the registration response, so there is nothing to warn about until
   the key is enrolled. That is why the second button is **Remove it** rather than
   **Cancel** — the choice offered has to be a real one. It calls the existing
   `passkey_revoke` action. Two consequences of taking it, both acceptable:
   revocation demands a recent second factor, which the step-up at the top of the
   enrollment flow already satisfies, so there is no second prompt; and revocation
   rotates the trusted-device HMAC key, so any skip-second-factor cookie dies. A user
   who backs out of adding a key can reasonably be asked to re-trust their device.
2. **In the table.** An `incapable` credential shows a neutral `Sign-in only` badge
   instead of the `Not activated` warning badge, with a title explaining why. The
   existing two-way `pkc_prf_capable` tooltip split collapses into the three-state
   answer.
3. **In the Actions menu.** `Activate for vault` is not offered on an `incapable`
   credential. It is the only action that would fail by construction.
4. **On failure.** When activation does fail on a `capable` or `unknown` credential,
   the alert names both live possibilities — no PIN set on the security key, or the
   key does not support PRF — and points at `/admin/admin_passkey_lab` for superadmins.
   It must not assert which one, because we do not know.

**`adm/admin_passkey_lab.php` / `adm/logic/admin_passkey_lab_logic.php`** — the
credential table gains a capability column and shows the raw signals
(`pkc_prf_capable`, `pkc_discoverable`, `pkc_attachment`) behind it. The lab is where
someone goes when they do not believe the badge; it should show its work.

## Phase 3 — Scope the vault ceremonies

`getDerivationOptions()` puts **every** enrolled credential in `allowCredentials`, for
all six of its callers (setup, unlock, rotate, add-passkey, client-custody). Two
consequences, both fixed here.

### 3.1 Activation is not scoped to the credential you clicked

`Activate for vault` on a specific row accepts any enrolled passkey. Choose the
YubiKey row, tap Touch ID at the prompt, and Touch ID is activated instead — the row
you clicked still reads `Not activated` and nothing says why. The mis-activation is
silent in the one direction that matters: if the tapped credential is *already* an
unlocker the request is rejected with "This passkey already unlocks your vault"
(`logic/vault_add_passkey_verify_logic.php`), but if it is not, the wrapping is
created against the wrong credential and the call reports success.

This is a correctness bug independent of capability detection — it misroutes a vault
unlocker on a healthy pair of keys — and it is what makes the U2F case confusing to
diagnose, because the failure and the surprise land on different rows.

1. `getDerivationOptions()` takes an optional credential-id filter, restricting
   `allowCredentials` to a caller-named subset. Absent, behaviour is unchanged, so the
   other five callers are untouched.
2. `logic/vault_add_passkey_options_logic.php` accepts a credential id and passes it
   through; `views/profile/security.php` sends the id of the row whose action was
   clicked. `runVaultActivation()` already takes no argument and is called from both
   the post-enrollment chain and the per-row action — it gains one, and the enrollment
   path passes the id of the credential just created.
3. Tapping the wrong authenticator then fails at the browser rather than succeeding
   against the wrong row. That is the correct outcome, but it arrives as the same
   opaque `NotAllowedError` as everything else, so the activation failure message says
   which passkey was expected — by label.
4. `vault_add_passkey_verify` returns the activated credential's id and label
   alongside `wrapping_id`, and the UI confirms the activation by name. Belt and
   braces: scoping prevents the wrong credential being activated, and the echo means a
   future path that forgets to scope cannot activate one silently.

### 3.2 Every vault prompt offers every enrolled passkey

Including the ones that cannot unlock *this* vault, so the browser lists keys it will
then refuse. One rule covers every caller (D2):

> **Offer the credentials that hold a wrapping for this scope's vault. If none do,
> this is an enrollment — offer everything except the known-`incapable`.**

The two halves answer different questions, and the first is the stronger one. "Which
credentials hold a wrapping for this vault" is a stored fact about this vault, not an
inference from registration-time reporting, so unlock and rotate — the paths where a
wrong answer means someone cannot reach their sealed content — never depend on the
new classification at all. Capability filtering only applies where there is nothing to
intersect with, which is first-time setup.

The rule reads the same way for every caller without special-casing them:

- **Unlock** (`vault_unlock_options_logic.php`) and **rotate**
  (`vault_rotate_options_logic.php`) — wrappings exist, so the prompt offers exactly
  the credentials that can unlock. A partially-rotated vault holds wrappings across
  generations; the offer is their union.
- **Setup** (`vault_setup_options_logic.php`) — no wrappings yet, so `incapable` is
  dropped and `unknown` is kept.
- **Client-custody** (`vault_client_prf_options_logic.php`) — one endpoint serves both
  enrollment and unlock for a scope, and the rule distinguishes them without being
  told which is which. The server cannot read a client-custody KEK, but it does store
  each scope's wrapping rows tagged with the credential id
  (`VaultClientCustody::insertOpaqueWrapping()`), so it knows *which* credentials
  unlock the scope without knowing *what* they unlock.
- **Add-passkey** — scoped to the clicked credential by 3.1; the rule does not apply.

**The empty-set guard is mandatory.** If the computed set is ever empty, fall back to
offering every enrolled credential. Minting an empty `allowCredentials` on the unlock
path is a vault lockout, and that is the one failure mode in this spec worth
engineering against rather than merely arguing about. The fallback is unreachable by
the rule as written — an empty wrapping set falls through to the capability branch —
but it is cheap, and it stays correct if a future caller reorders the branches.

## Docs

- **`docs/passkeys.md`** — a `Capability detection` subsection under *Persistence*
  describing the three states, the signals behind them, and the rule that `unknown`
  stays permissive. Update the `Persistence` field list with the two new columns, and
  the *Diagnostics* section for the lab's new column. Written as current state: no
  reference to what was recorded before. *The four ceremonies* gains one sentence: a
  derivation ceremony may be scoped to a named credential, and the caller that adds an
  unlocker always scopes it. *API surface* drops `prf_capable_requested` from
  `passkey_register_options` and states that registration always requests PRF.
- **`docs/sealed_vault.md`** — *Enrollment* notes that a credential the platform knows
  to be PRF-incapable is never offered as a vault unlocker, and *The unlocker floor +
  revocation veto* notes that such a credential cannot count toward the floor because
  it can never hold a wrapping.

## Tests

- **`tests/account_security/passkey_capability_test.php`** (new, `safe` tier) — the
  classification rule over stored flags: all three negative signals present →
  `incapable`; any one missing or null → `unknown`; `pkc_prf_capable` true →
  `capable` regardless of the others. Plus the invariant that an `incapable`
  credential is absent from a derivation ceremony's `allowCredentials` while an
  `unknown` one is present.
- **Offer rule (Phase 3.2)** — with two credentials enrolled and a wrapping on one of
  them, `vault_unlock_options` returns only that one; with no wrappings,
  `vault_setup_options` returns the `unknown` credential and drops the `incapable`
  one. Plus the guard: a computed empty set falls back to offering everything rather
  than minting an empty `allowCredentials`.
- **Scoping (Phase 3.1)** — with two credentials enrolled, `vault_add_passkey_options`
  for a named credential id returns exactly one entry in `allowCredentials`, and the
  same call with no id returns both. This is the regression guard for the misrouting
  bug, and it is assertable without a physical authenticator because it only inspects
  the options the server mints.
- **Backfill** — over fixtures covering each guard: a U2F-shaped row (no PRF,
  `uvInitialized` false, `["usb"]`) is marked; a PIN-less-FIDO2-shaped row (PRF true)
  is not; a platform row (`["internal"]`) is not; a row with `uvInitialized` null is
  not. The negative cases are the ones that matter — the migration's job is to mark
  U2F-only keys and leave everything else alone.
- **`tests/account_security/passkey_lab_test.php`** — extend for the capability column.
- The registration path itself needs no new coverage: the new fields are pass-through
  from the client response, and `passkey_lab_test.php` already covers ceremony shape.

## Live verification

Automated tests can assert the classification rule and the shape of the options the
server mints, and nothing more: the WebAuthn virtual authenticator does not implement
PRF, so no ceremony in this spec can be driven end-to-end in CI. Every claim about
what a real authenticator does needs hardware, on a real deployment.

1. **U2F-only key, vault present** — enroll it. Expect the Keep-for-sign-in / Remove-it
   modal and no browser refusal dialog at any point. This is the reported bug; it is
   the gate that matters most.
2. **Backfill** — run the migration on a node already holding the U2F key. Expect that
   credential marked, badged `Sign-in only`, with no `Activate for vault` action, and
   expect the log to name it and nothing else.
3. **FIDO2 key with no PIN** — enroll. It should report PRF, so activation stays on
   offer and fails, with a message naming both causes. Set a PIN, activate again,
   expect success. This pair is what separates the two failure modes the whole spec
   rests on; if a PIN-less FIDO2 key reports no PRF, the classification rule is wrong
   and Phase 1 needs rethinking before the rest ships.
4. **Scoping** — with two credentials enrolled, click `Activate for vault` on one and
   tap the other. Expect a failure naming the expected passkey, not a silent
   activation of the tapped one.
5. **Unlock offer** — with one of two credentials holding a wrapping, confirm the
   unlock prompt offers only that one.
6. **Fleet mis-wrapping check (D4)** — on each node, compare the passkeys each vault
   lists as active against the ones intended, and re-activate deliberately where they
   disagree.

## Open decisions

1. ~~**Enrollment of an `incapable` key while a vault exists**~~ — **RESOLVED
   2026-08-05: allow it, but make the user answer for it.** Not a passive notice: the
   enrollment stops on a modal that states the limitation and offers Keep for sign-in
   or Remove it. Refusing the enrollment outright was rejected — a sign-in-only backup
   key is a legitimate thing to own, and refusing would be a regression against
   behaviour that works today. See Phase 2.1 for why the modal lands after the
   credential is created and what Remove it costs.
2. ~~**Filtering `incapable` credentials from unlock prompts**~~ — **RESOLVED
   2026-08-05: filter by wrapping first, capability only where there is no wrapping.**
   A blanket capability filter was rejected as the wrong instrument for the unlock
   path: the vault already stores which credentials hold a wrapping, which is a
   stronger and more specific answer than "could this key ever do PRF", and it keeps
   the new classification code off the path where a mistake locks someone out of their
   own content. See Phase 3.2 for the rule and the mandatory empty-set fallback.
3. ~~**Backfill**~~ — **RESOLVED 2026-08-05: backfill from stored data.** Existing rows
   are classified from `pkc_source_json`'s `uvInitialized` flag plus transports, which
   requires nothing of the user and fixes the reported key without re-enrolling it.
   Doing nothing was rejected: it would have left every credential on the estate
   `unknown` and offering an activation that cannot succeed, and made the first thing
   the release notes had to explain a limitation rather than a fix. See Backfill in
   Phase 1 for the three guard conditions.
4. ~~**Existing mis-wrapped credentials**~~ — **RESOLVED 2026-08-05: check the fleet
   directly, no release note.** If the misrouting in 3.1 has already fired, a vault
   holds a wrapping against a credential the user did not intend to activate. It is a
   working unlocker, so nothing is broken and nothing needs repair, and the mistake is
   undetectable after the fact — a wrapping against Touch ID looks identical whether
   it was intended or not, because the intent was never recorded. The platform is
   pre-launch, so the affected population is the owner's own accounts; a fleet check
   during live verification reaches all of them, and a release note would be a pointer
   to the `Vault active` badges that already exist on `/profile/security`. Revisit if
   there are users to tell by the time this ships.

**All four open decisions are resolved.** The spec is ready to build.
