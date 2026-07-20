# Protection Ceremony: a Guided Path to Private and Fortress

## Problem

The security-level mechanisms are built and correct, but there is no path
through them. Raising a mailbox's protection currently requires knowing, in
the right order, that: the level lives on the **domain** editor (not the
mailbox the user is looking at); the owner must set up a Sealed Vault at
`/profile/security` **before** the flip (`InboundEmailRouter::storeMessage()`
seals only when the owner holds a vault — a Private domain with no vault
silently keeps storing plaintext); passkey unlock needs `passkeys_enabled`
turned on in admin settings and a PRF-capable enrollment; and the mailbox must
have exactly one live grant, a rule that today surfaces only as a refusal
after the fact. The platform's owner could not find the path without help.
No user will.

The fix is a pattern the platform already owns — the protected-identity flow's
**publish → verify → activate** doctrine and the Setup tab's verdict-row
idiom: a prerequisite checklist where every row is a verdict with an in-place
fix, and the level flip is disabled until the required rows are green. The
ordering trap is not warned about; it is made impossible.

## Design

### 1. Entry points: the badge (complete surface inventory)

Every surface that shows a mailbox or domain shows its protection level as a
badge — `Standard` / `Private` / `Fortress` — linking to the domain editor's
protection section (the ceremony). Nobody needs to know levels live on
domains; the badge takes them there.

| Surface | Badge placement |
|---|---|
| Accounts tab (`admin_mailbox_accounts` tree) | on each domain row and each mailbox row |
| Domain editor (`admin_mailbox_domains`) | the three-card picker itself (see §2) |
| Mailboxes tab (staff reader) | in the mailbox header |
| Member reader (`/profile/mailbox/mailbox`) | in the mailbox header (badge only — the link target requires admin; members see the state, not the ceremony) |
| Setup tab | no prerequisite rows (those live in the ceremony; the Setup tab already carries the Fortress DNS-shape rows) — but it gains the per-domain **Mail sealed at rest** assertion row (§ 3) |

No other mailbox surfaces exist today; a future surface that lists mailboxes
adopts the badge as part of its definition of done.

### 2. The ceremony: the level picker becomes a gated checklist

The domain editor's three-card picker stays the single authority. Choosing a
card **above the current level** does not save — it reveals the ceremony
checklist for that target level, rendered in the Setup-row idiom (verdict +
summary + one-click fix), evaluated live server-side:

**Rows for Private (all `required` unless noted):**

1. **One reader per mailbox.** Every alias on the domain has exactly one live
   grant. A violating alias renders its holder list with per-holder **Remove
   access** buttons (the existing grant-removal action, inline). This turns
   today's opaque save-refusal into the thing you fix from the row.
2. **The reader holds a vault.** Evaluated for each alias's grant holder
   (the sealing target — `attachmentOwnerId`), not for the admin running the
   ceremony. When the holder IS the current session user, the fix button
   jumps straight to the vault panel on `/profile/security` with a `return`
   parameter — completing setup bounces automatically back to the ceremony
   (the multi-state vault ceremony, recovery-code display included, is
   security-critical UI that must not be duplicated inline; jump-and-return
   preserves "no go-find-it navigation" without a second implementation).
   When the holder is someone else, the row names them and states plainly
   that they must set up their vault from their Security page before the
   domain can be raised (no fix button — an admin cannot create someone's
   zero-knowledge vault).
3. **Passkey unlock ready** (`recommended`). The holder has a PRF-capable
   passkey (`pkc_prf_capable`) enrolled — the row explains this is how the
   mailbox unlocks with a touch instead of the passphrase. Inline enrollment
   when the holder is the session user; named-holder guidance otherwise.
   Recommended, not required: the passphrase and recovery code are complete
   unlock paths on their own. When the `passkeys_enabled` kill switch is off,
   the row renders a plain "passkeys are disabled on this deployment" note
   rather than silently vanishing.

**Rows for Fortress: the Private rows plus the existing protected-identity
machinery**, with the two-stage semantics pinned explicitly:

- Stage 1 — **enter the transition**: with the Private-tier rows (plus a
  relay-fronted row, green on any relay-fronted deployment) all passing,
  activation saves `ied_security_level = fortress`. This is the moment
  today's mechanisms already key off the level: the relay starts sealing to
  the holder's vault key, and the Setup/ceremony rows begin prescribing the
  inverted DNS shape.
- Stage 2 — **verify-gated protect flip**: the DNS-inversion rows
  (`protectedShapeResults()`) continue in the same checklist across sessions
  (DNS takes time), and `ied_is_protected_identity` flips only when they
  verify — the existing publish → verify → activate ceremony, unchanged,
  just rendered as the tail of one checklist instead of a separate flow.

**Activation.** The **Raise to Private / Raise to Fortress** button sits under
the checklist and is enabled only when every `required` row passes. The save
handler re-verifies server-side (the button state is a convenience, never the
enforcement — same doctrine as `listener_admin`'s server-side guardrail
re-check). Rows re-evaluate on page load and after each inline fix action.

**Backlog sealing (automatic).** Sealing is per-row, so a raise would leave
every already-received message plaintext forever — "my mail is now private"
must not be quietly untrue for history. The server already holds that
plaintext and sealing needs only the holder's vault PUBLIC key, so any admin
session can drive it: after a successful raise the domain editor auto-runs
bounded server-side batches (`mailbox_protection_seal_batch`, 200 rows per
pass, auto-continuing) with a progress line until the domain's backlog is
empty — and it resumes on any later editor visit that finds a backlog, so
protection that degraded (vault deleted, then recreated) reconverges. Rows
whose mailbox has no vault-holding single owner are skipped, stay counted,
and stay loud via the Setup row (§ 3). Lowering never unseals (sealed rows
stay sealed and readable in-window — unchanged doctrine).

**Lowering** a level stays a plain save (no ceremony), with a confirm that
states the per-row reality: already-sealed mail stays sealed and readable
in-window; new mail stops being sealed.

**Existing structural refusals stay** as the backstop (IMAP-source domains
hide the Fortress card; the group-mailbox save refusal remains for direct
POSTs) — the ceremony is the path, the refusals are the guarantee.

### 2b. Ongoing invariants enforce at the mutation points

The ceremony guards the raise; the raised state must then be impossible to
corrupt, not merely alarmed about. On a domain whose level seals content:

- **Adding a grant to a mailbox that already has one is refused** at the
  grant-add path, with the plain reason (one reader per protected mailbox;
  lower the domain to Standard to share it).
- **Creating an alias with no holder is refused** at the alias-create path —
  a protected domain's mail always has a sealing target from the first
  message.

The health assertion (§3) remains the backstop for states these refusals
cannot reach (e.g. a vault deleted after the raise), never the primary
enforcement.

### 3. Sealing gains a doctrine assertion (close the silent-plaintext hole)

Ceremony gating makes flip-before-vault impossible going forward, but the
ingest branch keeps its capability check (`$vault !== null`). The assertion
for the residual cases (a vault deleted after the flip) derives from the data
itself — no new fact recording: the Setup tab carries a per-domain **Mail
sealed at rest** row (`domain.sealed_backlog` in `InboundEmailSetupCheck`),
PASS when every stored message on a protected domain is sealed, REQUIRED FAIL
naming the unsealed count otherwise, with the fix pointing at the domain
editor (whose sealing pass auto-resumes on any visit that finds a backlog).
Protection that silently degrades is the one outcome this spec exists to end;
if it ever happens anyway, it must be loud.

### 4. Passkeys default on

`settings.json`: `passkeys_enabled` default `0` → `1`, helptext rewritten as
an emergency kill switch ("Master switch … turn off only to disable all
passkey operations deployment-wide"). Passkeys are part of the platform's
account-security doctrine; a feature the doctrine assumes cannot default off.
A data migration flips existing rows still at `0` (pre-launch platform, no
operator has deliberately disabled it; the dev/jeremytunnell rows at `0` are
just the old factory default).

## What this deletes

- The undocumented ordering dependency (vault before flip).
- The multi-grant save refusal as the *discovery* mechanism for the
  single-reader rule.
- "Go to your profile Security page, then come back" navigation for vault and
  passkey setup mid-ceremony.
- The admin-settings scavenger hunt for `passkeys_enabled`.

## Tests

- `plugins/mailbox/tests/protection_ceremony_test.php` (db): row evaluation
  matrix — multi-grant alias renders the remove-access row; holder-without-
  vault renders required fail (self vs named-other variants); PRF passkey row
  recommended verdicts (plus the kill-switch-off note); Fortress adds the
  DNS/relay rows; activation guard refuses a raise with a red required row
  (direct POST, not just button state); lowering needs no ceremony;
  mutation-point refusals (second grant on a protected mailbox, holderless
  alias on a protected domain); backlog sealing seals pre-raise rows and the
  progress state empties.
- Extend `inbound_email_health` coverage: unsealed-on-protected-domain fact
  recorded and reported.
- Existing suites must hold: `setup_topology`, security-level ingest tests
  (per-row sealing unchanged).

## Documentation

- `plugins/mailbox/docs/overview.md` § Security levels: the ceremony is how a
  level is raised; badge surfaces; the health assertion.
- `docs/account_security.md` / `docs/passkeys.md`: `passkeys_enabled` default
  and kill-switch semantics.

## Out of scope

- New crypto or changes to sealing mechanics (per-row sealing, key hierarchy
  untouched).
- Multi-reader sealing for group mailboxes (Standard-only rule stands —
  specs/mailbox_security_levels.md owns that decision).
- Native app surfaces (badge/ceremony are web; apps follow in their own
  packages).
