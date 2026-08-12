# Managed Backup Recovery — Wrapped-Key Custody for the Recovery Key

**Status:** Unbuilt, spec (2026-08-11). Companion to
`specs/managed_backups.md`; requires managed storage (the wrapped key lives
in the managed bucket, the server share lives at getjoinery).

**What it is:** managed storage closes every loss mode except one — the
customer losing their own recovery key. This spec closes that one by
storing the recovery **private** key alongside the backups, wrapped
several times under independent credentials the customer already has: a
passphrase and their passkeys. Any one surviving credential recovers the
key; the key recovers everything. This is the sealed vault's wrapping
model (one secret, multiple independent wrappings, per-wrapping failure
tolerance) applied to `backup_recovery_public_key`'s private half.

**Custody statement:** getjoinery still cannot open backups unilaterally.
The passphrase wrapping is **server-gated**: the wrap key is derived from
the customer's passphrase *and* a getjoinery-held per-tenant server
secret. A bucket breach cannot dictionary-attack the blob (no server
secret); getjoinery cannot open it (no passphrase). Recovery requires the
customer proving the passphrase to getjoinery — which is just signing in.

This is the "server-gated" fork of `specs/vault_legacy_access.md`'s open
question, settled here the same way; that spec should adopt the same
construction rather than designing a second one.

## What already exists to build on

- **The wrapping pattern:** the sealed vault wraps one identity key under
  a passphrase and any number of passkey-PRF wrappings, tolerating
  individual wrapping failures. Same shape, same crypto conventions.
- **The ceremony surfaces:** `includes/RecoveryKeySetupPanel.php` already
  has the private key in the browser at generation and at proof; the
  standing **Recovery Readiness** re-verification has it again on demand.
  These are the only legitimate moments the private key exists in a
  browser, and they are exactly the moments the wrapping set is minted or
  refreshed.
- **Passkey PRF derivation** is built (vault capability); wrappings under
  it are additive.
- **The write-only tenant credential** can upload the blob beside the
  backups; it cannot delete, so updates write new versions and the newest
  wins.

## Design decisions

- **The passphrase wrapping is the load-bearing one.** Passkeys are bound
  to the site's domain, so a passkey wrapping only opens while the site is
  alive — it covers "lost my recovery key, site is fine" (unwrap, rotate,
  done), never the disaster where the site is gone. The passphrase
  wrapping is portable: it opens on a fresh install or a getjoinery-hosted
  recovery page. Passkey wrappings are minted whenever available, as
  bonus paths.
- **Wrappings are minted only when the private key is legitimately
  present** — the initial ceremony and Recovery Readiness re-verification.
  Both refresh the full wrapping set (current passphrase, all current
  PRF-capable passkeys) and re-upload the blob. There is no server-side
  copy of the unwrapped key, ever.
- **Unwrap happens client-side.** In every flow — passkey or passphrase —
  the key material is assembled in the browser (WebCrypto), and the
  recovered private key is delivered to the restore target (the fresh
  site), never persisted by getjoinery. The server share is released only
  after account authentication with step-up.
- **The blob rides the backup path.** One well-known object per tenant
  prefix (`{tenant-slug}/recovery/wrapped_key.json`), uploaded with the
  same write-only credential the backups use, re-uploaded on every
  wrapping-set change. It carries the wrapping list, KDF parameters, and
  the recovery public key fingerprint it belongs to — a blob for a rotated
  key is legible as stale, never silently wrong.
- **Recovery flow:** fresh install (or a provisioned box) → sign into the
  getjoinery account → step-up → getjoinery serves the blob and the server
  share → customer types the passphrase → key unwraps in the browser →
  restore. The remaining loss mode is forgetting the passphrase *and*
  losing every passkey *and* the original key copy — Recovery Readiness
  keeps nagging exactly so that stays rare.
- **Enrollment for managed recovery updates the custody sentence** on the
  Backups page from "getjoinery cannot help if you lose your key" to
  "your key is recoverable with your passphrase or a passkey." The base
  managed-storage tier keeps the original sentence; this tier is the
  opt-in that changes it.

## Build items

### 1 — Wrapped-key blob format and crypto (core)

The blob schema (wrappings list, per-wrapping type and KDF/PRF
parameters, public-key fingerprint, version) and the two wrapping
constructions: passphrase (Argon2id over passphrase + server share) and
passkey PRF. Follows the sealed vault's conventions; per-wrapping
try/catch on open — one corrupt wrapping never blocks the others.

### 2 — Ceremony integration (core)

`RecoveryKeySetupPanel` and Recovery Readiness re-verification mint and
refresh the wrapping set while the private key is in the browser, then
post the blob for upload via the site's managed target. A passphrase is
collected at first mint (see open item D1 for which passphrase). The
Backups page shows the wrapping set's health — which wrappings exist,
when last refreshed — beside the existing readiness state.

### 3 — Server share service (operator side, server_manager plugin)

Per-tenant random server secret, minted at managed-recovery enrollment,
stored SecretBox-sealed at getjoinery. One endpoint on the managed
backups service: after account auth + step-up, serve the share (and the
current blob fetched with the master credential). Every release is
audited and notifies the account's contacts — release is a normal
authenticated event, not a break-glass ceremony, but it is never silent.

### 4 — Recovery page (operator side)

A getjoinery-hosted page for the site-is-gone case: authenticate,
step-up, fetch blob + share, unwrap in the browser, hand the key to the
restore flow — pasteable into a fresh install's restore, or delivered
directly to a provisioned box's restore job without getjoinery
persistence (the delivery mechanism is a build-time design detail; the
constraint is that the unwrapped key never lands in a getjoinery job row
or log).

### 5 — Tier feature and product

`managed_backup_recovery` (boolean) as a core tier feature; sold as an
add-on or folded into higher plans (owner decision at build time, same
as plan sizes).

## Tests

- Blob round-trip: each wrapping type opens independently; a corrupted
  wrapping is skipped, not fatal.
- Server-gating: the blob does not open with passphrase alone or share
  alone; only the combination derives the wrap key.
- Staleness: a blob whose fingerprint mismatches the current recovery
  public key reads as stale, and the ceremony refuses to leave it that
  way silently.
- Passkey wrapping opens on the site's own origin and is absent from the
  portable recovery path.
- Share release requires step-up; every release writes an audit row and
  a notification.

## Documentation (written at build time — docs describe current state only)

- `docs/backups.md`: the wrapped-key model, what each wrapping can and
  cannot open, the custody statement per tier, the recovery flow.
- `docs/sealed_vault.md` cross-reference if the wrapping conventions are
  shared as a helper rather than duplicated.
- `plugins/server_manager/docs/overview.md`: the server share service and
  its audit/notification behavior.

## Open items

- **D1 — which passphrase.** A dedicated recovery passphrase (stable
  across login-password resets — the sealed vault precedent, and a login
  password reset would silently strand a login-password wrapping) versus
  reusing the login password (nothing new to remember, but needs
  staleness detection after resets). Recommendation: dedicated
  passphrase, with Recovery Readiness surfacing wrapping staleness either
  way.
- **D2 — `vault_legacy_access.md` alignment.** Adopt the server-gated
  construction there and share the implementation, or leave that spec to
  its own decision. Recommendation: share it — one construction, one
  audit surface.
