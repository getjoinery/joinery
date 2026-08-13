# Recovery Readiness Page

**Status:** Implemented 2026-08-01 (recommended defaults adopted for all open decisions; member card and operator aggregate included; DR-card fold-in remains with specs/key_management_simplification.md)
**Date:** 2026-08-01

## Problem

The platform mints secrets whose loss means permanent data loss, hands them to the operator once, and then goes silent. There is no page that answers: *what must exist outside this platform for my data to survive, do I actually have those things, and what did I call them?*

The concrete failure that motivated this: the operator could not determine whether the backup recovery private key was saved in their password manager or under what label — and the only verification tool (the possession check in the escrow walkthrough) disappears once setup completes. Meanwhile, live state on dev shows a drive vault holding client-custody encrypted content with only 2 unused recovery codes and no passkey enrolled — one lost passphrase away from a warning nobody has seen.

## Goals

1. One page that lists, derived live from actual system state, every secret that must exist outside the platform to prevent permanent data loss — and nothing else (no nice-to-haves diluting the list).
2. Every listed item has an on-screen verify tool that proves the operator holds it, without the secret being exposed, transmitted, or consumed.
3. Every item prescribes a canonical password-manager label (including a fingerprint), so "what did I call it" has one answer.
4. Verification is remembered: each item records when it was last proven, and staleness or never-verified state is surfaced where the operator will see it.

## The Must-Save List (what the page shows)

Registry-driven: core defines the item contract; core and plugins contribute items based on live state. An item appears only when the thing it protects actually exists.

### 1. Backup recovery private key *(shown when an escrow public key is configured)*

- **What it protects:** every encrypted database backup from every node (today via escrowed node keys; after the envelope model, every backup artifact outright).
- **Shown:** public-key fingerprint, date proven, which nodes' keys are sealed to it, suggested label — `Joinery {site} — backup recovery key ({first 8 of fingerprint})`.
- **Verify:** the existing in-browser WebCrypto ceremony (seal a challenge to the configured public key; operator pastes the private key into the page; unseal happens client-side; key never leaves the browser). Same mechanism as setup Step 2, now available on demand.

### 2. Vault recovery codes — the signed-in user's, per scope *(shown per vault that exists)*

- **What they protect:** that scope's encrypted content — mail (`user`), Drive E2E (`drive`), password vault (`passwords`). Client-custody scopes have no server-side recovery of any kind.
- **Shown:** per scope — unused code count, passkey count, passphrase enrolled or not, suggested label — `Joinery {site} — {scope} vault recovery codes ({username})`.
- **Verify:** enter one code; the page dry-run derives the KEK and attempts the unwrap **without consuming the code or persisting anything** — pass/fail only. For client-custody scopes this runs entirely in the browser (the wrapped key + salt are the user's own rows). Requires a step-up-confirmed session; rate-limited so the dry-run is not a better oracle than the real recovery flow.
- **Warnings:** unused codes below threshold (default 3), no passkey enrolled on a scope with content, codes from a stale key generation.

### 3. Backup bucket console access *(shown per enabled backup target)*

- **What it protects:** the ability to reach the backups at all after total server loss. Bucket endpoint and credentials are sealed in the control-plane database — the provider console login is the only non-circular way back in.
- **Shown:** provider, bucket name, path prefix (all non-secret), suggested label for the console login entry.
- **Verify:** not programmatically verifiable without credentials — this item is an attestation: "I confirmed I can sign in to the {provider} console" checkbox, timestamped. The page states plainly that this one is on the honor system.

### 4. Informational (listed, but explicitly *not* must-save)

A short closing section preventing over-saving: `secret_box_key` (recoverable from any backup — via the recovery key once envelope encryption lands), admin passwords (resettable with server access), SSH keys (re-keyable; operational outage, not data loss), TOTP secrets (admin-resettable). Each with one line on *why* it doesn't need to be in the password manager.

### Member-facing vault card

Vault recovery codes are per-user, so the admin page can only ever show the
signed-in operator's own. Each user therefore gets the same vault cards —
counts, warnings, canonical label, dry-run check (server or in-browser by
custody), inline step-up — on their member security page (`/profile/security`),
rendered with member chrome. Verify attempts ledger identically.

### Operator aggregate

The admin page additionally shows a **visibility-only** section over other
users' vaults: which accounts have vaults whose unlocker margin is thin (unused
recovery codes below threshold, or no passkey enrolled), with scope and issue —
metadata a superadmin can already see, never codes and nothing verifiable from
here. Its purpose is that a member one lost passphrase away from permanent data
loss is visible to the operator instead of silently at risk.

## Verification Ledger

A small core table records, per item: item key, last verified time, method (`ceremony` | `dry_run` | `attested`), verifying user. The page shows it per item; the admin dashboard shows one line when any must-save item is **never verified** or stale (default 180 days). Verification stores pass/fail + timestamp only — never the secret, never a derivative of it.

## Placement

New core superadmin page: `/admin/admin_recovery_readiness`, menu entry under the existing admin security grouping in `admin_menus.json`. Items 1 and 3 are contributed by server_manager through the registry until the backups-to-core move lands (`specs/implemented/backups_core_and_incremental.md` Phase 2), at which point they become core contributions with no page change. Item 2 additionally surfaces on the member-facing `/profile/settings` security hub for non-admin users (same component, member chrome), since vault codes are per-user, not operator-only.

This page absorbs two items from `specs/key_management_simplification.md` Phase A (standing re-verify; DR-card content is Open Decision 2 here) and is the natural home for that spec's Phase D key registry as a later, lower-stakes tab — must-save items first, full key inventory second.

## Per-Instance Scope

The page describes the instance it runs on. Each deployment (dev/getjoinery, jeremytunnell.com, Fortress, …) shows its own must-save list — vault codes on Fortress appear on Fortress's page, not here. When several instances are configured with the same recovery public key (the supported and recommended arrangement — see the envelope model in `specs/implemented/backups_core_and_incremental.md`), each instance's page shows the same fingerprint and suggests the same password-manager label, so verifying the one saved key on any instance verifies it for all of them. A standalone site with no backup system configured shows item 1 in an explicit "not set up — your backups have no recovery story" state rather than hiding it. A fleet rollup ("every instance's readiness at a glance" on the control plane, fed by the management API read-only channel) is deliberately deferred (Open Decision 3).

## Testing

- Registry: each contributed item appears exactly when its live-state predicate holds (escrow configured / vault exists / target enabled).
- Recovery-key ceremony: pass and fail paths; the challenge is single-use; nothing secret in the POST body (browser path is client-side only).
- Code dry-run: correct code passes without flipping `uew_is_used`; wrong code fails; rate limit enforced; step-up required; nothing persisted but the ledger row.
- Ledger: staleness math; dashboard warning renders for never-verified items.
- Warning thresholds: low-code and no-passkey warnings against fixture vaults.

## Documentation (update at build time, current-state only)

- New section in `docs/account_security.md` (recovery codes + verification) and `docs/key_management.md` when the key spec lands (must-save doctrine lives there; the page stays self-documenting per admin-page rules).

## Open Decisions

1. **Verify-tool depth for vault codes:** dry-run unwrap as specced (recommended), or verify-and-rotate (consumes the code, issues a fresh set — stronger, but turns a check into a ceremony)?
2. **DR card:** fold the key spec's recovery-card generator into this page as a "print recovery sheet" action (recommended — same audience, same data), and whether the sheet includes `secret_box_key`.
3. **Fleet rollup:** defer (recommended) or include a minimal per-node readiness column on the server_manager dashboard in v1?
4. **Stale threshold** default 180 days — confirm.
