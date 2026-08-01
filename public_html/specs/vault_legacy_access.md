# Vault legacy access: posthumous access to a user's vault and account

## The problem in plain terms

A user's sealed content — mail, chat, the password manager, encrypted Drive —
is protected by keys only they can unwrap. That is the point. But it also
means that when the user dies, their family inherits a locked box: the
passkeys are on devices nobody can unlock, the recovery codes are wherever
the user kept them, and the platform operator cannot help because the whole
design guarantees they hold nothing useful.

The user needs a way to say, while alive: *this person gets in after I die —
and not one day sooner.*

**"After death" is not a cryptographic event.** No key arrangement can
distinguish "the owner died" from "the family decided to look." Every honest
design imports a death oracle from outside the cryptography, and there are
only three kinds:

1. **A time delay the owner can veto while alive** (a dead man's switch)
2. **A trusted third party** who attests the death
3. **A quorum** of parties who would have to collude to lie about it

This spec uses oracle 1 as the backbone (a claim starts a clock; any sign of
life from the owner cancels it) and offers oracle 3 as an optional hardening
layer. The choice between the two release-gating options below is the one
open decision.

## Definitions

- **Owner** — the user whose vaults and account are being granted.
- **Beneficiary** — the user designated to receive access. Phase 1 requires
  the beneficiary to be a user on the same instance (family members on a
  self-hosted family instance is the primary case). Inviting an outside
  email address to create an account first is ordinary user invitation, not
  part of this spec.
- **Grant** — the standing arrangement: who, the challenge window length,
  and the sealed key material.
- **Claim** — the beneficiary's assertion that the owner has died, which
  starts the challenge window.

## What the grant carries

The payload is a bundle of the owner's vault secret keys, one per scope the
owner has enrolled — the server-custody `user` scope (mail/chat) and any
client-custody scopes (`passwords`, `drive`). Whoever holds the bundle can
unwrap everything those scopes seal, so the bundle itself is always sealed
to the **beneficiary's legacy keypair** before anything else touches it:

- The beneficiary enrolls a dedicated client-custody scope, `legacy`
  (`uev_custody = 'client'`, PRF context `vault-legacy-kek`), when accepting
  the designation. This reuses the existing client-custody layer
  (`vault-crypto.js` / `vault-keyring.js` / `VaultClientCustody`) unchanged —
  own keypair, own unlockers, own recovery codes. Unlocking it never opens
  the beneficiary's other scopes, and nothing about the owner's data is
  readable without it.
- The bundle is sealed to that scope's public key via `SealedBox::sealDek()`.
  The server stores and gates ciphertext only; at no point in any flow does
  the server hold the plaintext bundle.

The grant does **not** carry the owner's account password or second factor.
Account access after release is handled by estate mode (below), not by
escrowing sign-in credentials.

## Designation ceremony (owner side)

From the owner's security settings (`/profile/settings` security area,
FormWriter, API actions via `_logic_descriptor()`, `requires_browser_session`
like every vault action):

1. Owner picks the beneficiary and a challenge window length (setting-backed
   default 30 days, floor 7). Requires a recent step-up.
2. Beneficiary is notified and must **accept**: they enroll (or already
   have) their `legacy` scope and its public key lands on the grant. A
   designation the beneficiary never accepts stays `invited` and carries no
   key material.
3. Owner runs the **sealing ceremony** in the browser: unlock each enrolled
   scope (one passkey tap per scope — the client-custody scopes only exist
   in the browser, so this ceremony is inherently client-side), assemble the
   bundle, seal it to the beneficiary's legacy public key, upload. The grant
   goes `active`.
4. Under Option B (below), the ceremony additionally splits a content key
   and produces the printable estate shares before upload.

Either side can end it at any time while the owner is alive: owner revoke
(step-up required) or beneficiary decline. Both destroy the sealed material.

## Claim and veto (the dead man's switch)

State machine on the grant row:

```
invited → active → claim_pending → released
   ↓         ↓          ↓
declined  revoked    active (vetoed — any owner sign-in, or explicit veto)
```

- **Claim**: the beneficiary, with a recent step-up on their own account,
  files a claim. The grant enters `claim_pending`, `lag_claim_time` is
  stamped, and the owner is notified on every channel on file, repeatedly
  over the window.
- **Veto**: any authenticated activity by the owner auto-cancels the claim —
  the session layer stamps owner activity, and the claim check compares
  against it. The notification emails also carry an explicit one-click veto
  (signed link → sign-in → veto), for the case where the owner has stopped
  using the platform but is very much alive. A vetoed claim returns the
  grant to `active` and is recorded; the owner is told who claimed and when,
  and can revoke the grant entirely.
- **Release**: if the window expires with no owner activity, the grant goes
  `released` and the server-gated material becomes fetchable by the
  beneficiary (with step-up) — what that material is depends on the option
  below.
- A scheduled task (the existing scheduled-task system) drives window
  expiry, reminder cadence, and the staleness nag below. There is no
  document review and no operator judgment anywhere in the flow — the
  platform is self-hosted and the design must work with no ops team.

## Release gating — the open decision

Both options share everything above. They differ only in what must come
together for the sealed bundle to open, i.e. who the death oracle is.

### Option A — server-gated release (challenge window only)

The grant row holds the sealed bundle; the server refuses to serve it until
`released`. That's it.

- **Trust statement**: the platform (this instance's server and whoever
  administers it) is the sole death oracle. If the server releases early —
  operator compromise, coercion, or collusion with the beneficiary — the
  beneficiary can open the bundle immediately. No party *other than* the
  beneficiary can ever read it, early or late.
- **If the instance dies before the owner does**, the grant dies with it.
  There is no offline recovery path — deliberately, because an offline copy
  of the sealed bundle would be openable by the beneficiary at any time,
  which breaks the while-alive guarantee.
- **Cost**: near zero beyond the shared machinery. This is the Apple/Google
  legacy-contact shape, with the improvement that the platform still never
  sees plaintext.

### Option B — 2-of-3 estate shares (window + estate papers)

The sealing ceremony wraps one more layer: seal the bundle to the
beneficiary's key as in A, then AEAD-encrypt that ciphertext under a random
content key **K** (`SecretBox`), and split K 2-of-3 with Shamir secret
sharing:

- **Share 1 — server-gated**: stored on the grant row, released to the
  beneficiary only after the challenge window, exactly like Option A's gate.
- **Shares 2 and 3 — estate papers**: rendered by the ceremony as two
  printable sheets (Crockford-base32 blocks in the recovery-code idiom, plus
  a QR), intended for the owner's will/lawyer/safe — **two separate
  locations**. The beneficiary holds neither while the owner is alive; that
  is what makes the collusion resistance real. (If the beneficiary held a
  live share, server + beneficiary would already be two of three and Option
  B would collapse into Option A with extra steps.)
- **Legacy packet**: the ceremony also offers the encrypted payload itself
  as a download — the owner files it with the estate papers. Useless without
  2 shares *and* the beneficiary's legacy key.

Recovery paths:

- **Normal death**: beneficiary gets one paper from the estate + the server
  share after the window → K → ciphertext (from the server) → their legacy
  key opens it.
- **Instance is gone**: both papers + the packet file → K → their legacy key
  opens it. The grant survives the platform. For an inheritance feature this
  is a real scenario — the service's lifetime and the owner's are
  independent variables.
- **Early release / collusion**: the server share alone is worthless; an
  early opening additionally requires a paper out of the estate. The
  will/safe becomes the second oracle.

Costs: a Shamir implementation (GF(256), implemented as a small `SecretBox`
sibling in PHP and mirrored in `vault-crypto.js` — libsodium does not ship
one), a print ceremony, and an owner obligation: after every rotation
refresh (below) the packet changes and must be re-filed with the estate —
the shares of K can stay stable across refreshes (re-encrypt the new payload
under the same K), so only the packet file goes stale, and only the
platform-death path depends on it being current.

## Rotation is the trap — and it cannot ride the reseal hook

The existing rotation re-seal callback contract hands consumers the **old
secret key and the new public key** — enough to re-seal item DEKs, and
structurally incapable of refreshing a legacy grant, whose payload *is* the
new secret key. A grant left to the ordinary hook dies silently at the
owner's first rotation and nobody finds out until the claim, when it is
unfixable.

So grant refresh is a **dedicated step inside the rotation ceremonies
themselves**, which are the only places the new secret exists:

- **Server-custody rotation** (`VaultCeremonies`): after the drain confirms
  (step 3 of the rotation order) and before old wrappings retire, re-seal
  the grant's bundle entry for that scope to the beneficiary's legacy public
  key and stamp the grant scope row's generation.
- **Client-custody rotation** (`vault-keyring.js`): the browser holds the
  new secret; it seals the refreshed entry and uploads it in the same
  ceremony.

Belt and suspenders, because an old client or a partial failure can still
miss the refresh: each grant scope row records the `uev_key_generation` it
was sealed against. A mismatch with the vault's current generation marks the
grant **stale** — surfaced on the owner's security page, in `vault_status`,
and nagged by the scheduled task — and the fix is re-running the sealing
ceremony (one unlock of the stale scope). A stale grant is degraded loudly,
never silently dead. The same generation check runs when the *beneficiary*
rotates their `legacy` scope: their client-custody rotation re-seals every
grant bundle naming them (it holds old secret + new public, and here the
payload is a sealed-to-them blob, so the ordinary re-seal shape does work on
their side).

## Release mechanics and estate mode

After `released`, the beneficiary (step-up required) fetches the gated
material and opens the bundle in their browser with their `legacy` scope
unlock. From the recovered scope secrets:

- **Client-custody scopes**: the browser enrolls the beneficiary's own
  unlockers as new wrappings on the owner's `passwords`/`drive` vaults —
  from that point they unlock them like their own.
- **Server-custody scope**: the browser submits the recovered secret through
  a release-only enrollment action that verifies it against the vault's
  public key and creates a wrapping under the beneficiary's chosen unlocker.
- **Estate sign-in**: the owner's account is flagged deceased and the
  beneficiary gets an estate sign-in path to it (the `admin_user_login_as`
  shape, gated on the released grant + beneficiary step-up, every entry
  logged). This is what makes the *unencrypted* estate — domains, the store,
  servers, plain files, billing — inheritable too; a vault-only release
  would hand the family a locked admin account. Estate mode disables the
  owner's own credentials and outbound identity (no sending mail as the
  deceased) — the exact restriction set is a build-time decision inside this
  spec's scope, not a new spec.

## Data model

- **`lag_legacy_access_grants`** — one row per (owner, beneficiary):
  `lag_owner_usr_user_id`, `lag_beneficiary_usr_user_id`, `lag_status`
  (`invited`/`active`/`claim_pending`/`released`/`revoked`/`declined`),
  `lag_window_days`, `lag_beneficiary_public_key` (the accepted legacy-scope
  public key, pinned at acceptance), `lag_gated_blob` (Option A: the sealed
  bundle; Option B: the server share), `lag_claim_time`,
  `lag_release_time`, `lag_last_reminder_time`, timestamps, soft delete.
- **`lgs_legacy_grant_scopes`** — one row per (grant, scope):
  `lgs_lag_legacy_access_grant_id`, `lgs_scope`, `lgs_sealed_entry` (that
  scope's sealed bundle entry), `lgs_key_generation` (staleness check),
  timestamps.
- Deletion strategy: owner or beneficiary account deletion `permanent_delete`s
  their grants (a grant without either party is meaningless and its material
  should not linger); declared in `$foreign_key_actions`, never inferred.
- Neither table is an API resource; all access is through the grant actions.

## Integration inventory

Decided up front so nothing is discovered mid-build:

| Point | Touch |
| --- | --- |
| Client-custody layer | New `legacy` scope on the beneficiary — reused as-is, plus grant re-seal in its rotation path |
| Rotation ceremonies | Dedicated grant-refresh step in `VaultCeremonies` and `vault-keyring.js` (NOT the `onReseal` hook) |
| `vault_status` / security page | Grant list, staleness flag, claim state, veto affordance |
| Scheduled tasks | One task: window expiry, reminders, staleness nag |
| Session layer | Owner-activity stamp consulted by the claim check (auto-veto) |
| Email templates | Invite, accept, claim-started (with veto link), reminders, vetoed, released, revoked |
| Account security doctrine | Estate sign-in path + deceased flag (`docs/account_security.md`) |
| Deletion system | `$foreign_key_actions` on both new tables |
| Crypto helpers (Option B only) | Shamir GF(256) split/combine in PHP + `vault-crypto.js`, print/QR rendering |

## Settings

- `legacy_access_enabled` (default on wherever the vault is enabled)
- `legacy_access_default_window_days` (default `30`)
- `legacy_access_min_window_days` (default `7`)

Declared in `settings.json`; no migrations.

## Tests

`tests/vault/legacy_access_*`: state-machine transitions (including
auto-veto on owner activity and claim-during-rotation), seal/open round-trip
against synthetic keys, generation-staleness detection, rotation-refresh
crash injection (a rotation that fails after refresh must leave the grant
openable by exactly one generation), and — Option B — Shamir vectors
(any-2-of-3 reconstructs, any-1 reveals nothing, PHP and JS interoperate).

## Documentation

At build time, developer docs land in the existing files per standing
practice: a **Legacy access** section in `docs/sealed_vault.md` (grant
lifecycle, the rotation-refresh contract, the `legacy` scope) and the estate
sign-in path + deceased flag in `docs/account_security.md`. No new
standalone doc.

## Decisions

**Resolved by this spec:**

- Dead man's switch (challenge window + owner-activity auto-veto) is the
  backbone; no document review, no operator judgment.
- Beneficiary must be a same-instance user with a dedicated `legacy`
  client-custody scope; the server never holds the plaintext bundle.
- Grant refresh lives inside the rotation ceremonies, with
  generation-staleness as the loud fallback; the `onReseal` hook is
  structurally unsuitable and must not be used for this.
- Release includes estate mode (account succession), not just key material.
- No escrow of the owner's password or second factor, ever.

**Open — pick before build:**

1. **Option A or Option B** (the death-oracle question): is the platform
   alone allowed to trigger release, or must the estate hold a required
   share? A is simpler and matches the industry shape; B survives platform
   death and resists server+beneficiary collusion at the cost of a paper
   ceremony and a re-file-the-packet obligation after rotations. (B could
   also ship as a per-grant choice on top of A's machinery — the state
   machine and grant rows are identical — but that is still a decision to
   make deliberately, not a default.)
2. **Multiple beneficiaries** — the data model supports N grants per owner
   as specified; decide whether Phase 1 exposes one or several.
3. **Estate-mode restriction set** — exactly which capabilities the
   beneficiary gets in the deceased account (proposed: everything except
   sending as the deceased and destroying the audit trail).
