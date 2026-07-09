# Encrypted Mailbox UX Test Plan

Manual end-to-end UX review of the security-levels feature set: bring a real
address into Joinery at Standard, live with it, upgrade to Private, live with
it, upgrade to Fortress — exercising passkeys, TOTP 2FA, the sealed vault, and
every gate along the way.

**Goal is UX review, not just correctness.** At the end of every phase, record
friction notes: confusing copy, dead ends, missing affordances, places you had
to guess. That output is the deliverable.

---

## Phase 0 — Fixtures and preconditions

**P0.1 — Choose the test domain.** You need a real domain you control DNS for.
Do **not** use the domain behind your primary personal address: the Fortress
upgrade moves MX to the relay, strips the box from SPF, and publishes
`p=reject` DMARC — that affects all live mail on the domain. Use a real but
sacrificial domain (or a dedicated subdomain) whose MX you can freely cut over.
The "existing address" for the scenario is an address on that domain.

**P0.2 — Choose the external correspondent.** A Gmail account you control.
Every deliverability check ("did it land in inbox, did DKIM pass") uses
Gmail's *Show original* view.

**P0.3 — Test user.** One permission-10 user (domain-level actions live in
the admin area) with a working account **password** — vault setup requires
one. This user will hold the mailbox grant, the passkeys, the TOTP, and the
vault.

**P0.4 — Settings check.** `passkeys_enabled` must be `1` (default is off —
no passkey or vault UI appears without it). Note `vault_unlock_idle_minutes`
default (30) — you'll observe it in Phase 3.

**P0.5 — Authenticators.** A real PRF-capable authenticator is mandatory:
platform biometric (Touch ID / Windows Hello / iCloud Keychain passkey) or a
hardware key. The CDP virtual authenticator cannot do PRF, so vault unlock,
decrypt-on-view, and Fortress compose cannot be tested with it. Have a
**second** authenticator available (second device or hardware key) for the
multi-passkey and lost-passkey scenarios.

**P0.6 — Start clean.** Test user has: no passkeys, no 2FA, no vault, no
recovery address. Log everything from a normal browser profile (not
incognito) so passkey UX is realistic.

---

## Phase 1 — Account security foundation (passkeys + 2FA)

All on `/profile/security` unless noted.

**1.1 First passkey.** Passkeys panel → add first passkey. Expect: prompted to
**re-enter account password** (not step-up — there's nothing to step up with
yet). Complete ceremony. Rename it to something meaningful. UX check: is it
clear the password prompt is a security gate, not a login bug?

**1.2 Passkey sign-in (pre-vault).** Log out. Sign in at `/login` using only
the passkey. Expect: works — sole passkey sign-in is allowed while the account
has no vault. (This stops working in Phase 3; that flip is a key test.)

**1.3 TOTP enrollment.** Two-Factor panel → Enable. Expect: QR code, add to
authenticator app, confirm with 6-digit code, **backup codes shown exactly
once**. Save them. UX check: is the shown-once nature communicated clearly?

**1.4 2FA at login — all three satisfiers.** Cadence is `every_login` by
default. Three logout/login cycles:
- (a) password → `/verify-totp` → 6-digit TOTP code
- (b) password → `/verify-totp` → 8-char **backup code** (verify it's consumed
  — the panel's remaining-codes count drops)
- (c) password → `/verify-totp` → **use a passkey instead** (passkey-as-2FA
  path on the same page)

**1.5 Cadence change.** Switch to `sensitive_only`. Expect: the change itself
demands a recent step-up (`/verify-stepup`). Log out/in — no factor prompt at
login. Switch back to `every_login`.

**1.6 Second passkey.** Add passkey #2 (the second authenticator). Expect:
gate is a **recent step-up with the existing passkey**, not password re-entry.

**1.7 Step-up page both ways.** Trigger `/verify-stepup` (e.g. regenerate
backup codes) and satisfy it once with a passkey, once with a TOTP code.
UX check: does the step-up page explain *why* you're being challenged?

**1.8 Recovery address.** Set an external recovery address (the Gmail).
Expect: step-up gate; verification mail; complete via `/recovery-verify`.
Negative: try setting an address on the hosted test domain — must be refused.

**1.9 Password reset, pre-vault.** Test the authorizers that change after
vault activation, while they're still in their no-vault form:
- (a) email-link reset (standard flow)
- (b) **TOTP-alone** reset (`/password-reset-totp`) — this path exists only
  for no-vault accounts; confirm it works now (and vanishes in Phase 6)
- (c) passkey reset (`/password-reset-1` → passkey)

**Phase 1 UX notes:** ______

---

## Phase 2 — Standard mailbox with a real address

Admin area: Emails → Mailbox.

**2.1 Add the domain.** Accounts tab → + Add Domain. Security level:
**Standard** (default). Note: even saving a level choice is step-up-gated —
confirm the `/verify-stepup` redirect fires and returns you to the editor.

**2.2 Negative test — Private before vault.** While still vault-less, edit
the domain and try to set **Private**. Expect refusal with the "set up your
vault before choosing Private or Fortress" message. UX check: does the
refusal tell you *where* to go set up the vault? (Do this now — the
opportunity disappears after Phase 3.)

**2.3 DNS setup — detect/instruct/verify.** Setup tab, select the mailbox.
Publish what the Receiving checklist instructs (MX, SPF, DMARC). Re-verify
until green, including the end-to-end send-and-watch proof. UX check: are the
copy-ready records actually copy-paste correct for your DNS host?

**2.4 Alias in forward mode.** Create the alias matching your existing
address, mode **forward**, destination = the Gmail. From Gmail (or another
account), send to the address. Expect: arrives in Gmail forwarded, SPF/SRS
sane, original DKIM intact (Show original).

**2.5 Switch to store mode.** Edit alias → **store** (or `forward_and_store`).
Send again from Gmail. Read it at **`/profile/mailbox/mailbox`** as the
member. Check threading, attachment open (`/profile/mailbox/attachment`),
message renders.

**2.6 Compose and reply.** From the webmail reader, compose to Gmail. Expect:
lands in inbox, DKIM=pass for your domain in Show original. Reply from Gmail;
confirm the reply threads correctly in the reader.

**2.7 Standard-level behavior baseline.** Search works with no unlock
concept; notification (if configured) carries full sender/subject; nothing
prompts for a vault anywhere. This is the baseline to compare against later.

**Phase 2 UX notes:** ______

---

## Phase 3 — Sealed vault

All on `/profile/security`, Encrypted Vault panel.

**3.1 Vault setup ceremony.** Set Up Your Vault. Expect in one flow: PRF
passkey ceremony (user-verification required), **recovery codes shown once**,
optional passphrase offer, and an explicit acknowledgment that losing every
unlocker loses the data forever. Enroll the passphrase too (you'll test it).
Save the recovery codes separately from the Phase 1 backup codes — UX check:
is it clear these are *different* codes with different jobs (vault vs login)?

**3.2 The sign-in flip.** Log out. Attempt passkey-only sign-in at `/login`.
Expect: **refused**, pointed at password sign-in — vault holders lose sole
passkey sign-in. Sign in with password + 2FA. UX check: does the refusal
explain itself, or does it feel like a broken passkey?

**3.3 Unlock/lock cycle, all three unlockers.**
- (a) Unlock with Passkey (biometric/UV prompt must appear) → panel shows
  unlocked → Lock.
- (b) Unlock with Passphrase → Lock.
- (c) Unlock with Recovery Code. Expect: consumes the code, requires a recent
  step-up first (you have factors enrolled), ends windows elsewhere, and an
  **alert email** arrives. Confirm remaining-code count dropped.

**3.4 Window lifetime.** Unlock, go idle ~35 minutes (default
`vault_unlock_idle_minutes` 30 + beacon staleness). Return: window should be
closed and content actions re-prompt. Also confirm closing the tab / walking
away doesn't leave a window open on another device.

**3.5 Password change ends everything.** With a window open, change the
account password (step-up-gated). Expect: every unlock window ends
everywhere + alert email. Re-unlock afterward to confirm the vault itself is
fine (password change does **not** re-key the vault).

**3.6 Second passkey into the vault + the floor.** Unlocked panel → Add
Another Passkey (passkey #2). Then try to **revoke** both passkeys from the
Passkeys panel one after another: the revoke that would leave the vault below
its floor (<1 passkey wrapping and <3 unused codes) must be **vetoed** with a
clear message. Leave both enrolled.

**3.7 Vault key rotation.** Rotate Vault Key with passkey #1. Expect: fresh
PRF ceremony; afterward passkey #2 **no longer unlocks** (only the rotating
credential and a re-entered passphrase carry forward) and the UI tells you to
re-add it. Re-add passkey #2.

**Phase 3 UX notes:** ______

---

## Phase 4 — Upgrade to Private and live with it

**4.1 The upgrade.** Domain editor → level **Private**. Expect: step-up gate,
then (first protected domain) the ceremony/backfill flow: existing stored
mail is re-encrypted in-window, plaintext destroyed. UX check: does the
backfill communicate progress and completion, or does it just happen?

**4.2 Locked-state contract.** Log out, log in, do **not** unlock. Open the
reader:
- Thread list: folders, labels, unread counts, times all normal; **sender,
  subject, preview show the sealed placeholder**.
- Open a thread → one-tap unlock prompt → after unlock the thread you asked
  for opens **without re-navigation**.
- Search: box renders; submitting prompts unlock, then the search runs.
UX check: is the placeholder styling obviously "locked" rather than
"broken"?

**4.3 Sealed at rest.** Send a fresh mail from Gmail while logged out of
Joinery. Verify in the DB (read-only) that the new row's body/subject columns
are ciphertext, not plaintext. Then log in, unlock, read it normally.

**4.4 Notifications at Private.** Confirm the new-mail notification carries
sender + subject (generated pre-seal), and exercise the per-mailbox generic
toggle if configured.

**4.5 Attachments.** Send yourself an attachment from Gmail. Locked: attempt
download → expect a locked response surfaced as an unlock prompt (API layer
is `423 Locked`). Unlock → download succeeds and file is intact.

**4.6 Compose at Private.** Reply to Gmail from the reader. Record observed
gating (per spec, Private keeps ambient identity signing — compose should
work; reading the thread you're replying to is what needs the window).
Confirm DKIM still passes at Gmail.

**4.7 Vault-gated settings.** With the vault **locked**, try to edit the
alias (destinations/mode) and a mailbox filter. Expect: refused/prompted for
unlock — routing follows content. Unlock and make an edit to confirm the
happy path.

**4.8 Skip/observe.** The 7-day absolute backstop isn't practically testable
in a sitting — note it as designed-only coverage.

**Phase 4 UX notes:** ______

---

## Phase 5 — Upgrade to Fortress and live with it

**5.1 Prerequisite gate (optional negative).** Fortress requires a second
factor independent of any single passkey; your TOTP from Phase 1 satisfies
it. Optional: with a scratch user holding a vault + one passkey + no TOTP,
observe `must_enroll_2fa_for_fortress` blocking every page behind a redirect
to `/profile/security`.

**5.2 The upgrade.** Domain editor → **Fortress**. Expect: step-up, then the
cutover checklist — MX at the relay, SPF without the Joinery box, `p=reject`
DMARC, forwarding-subdomain records, relay provisioning (first Fortress
domain), and the confirm gate: *"this domain cannot send mail unless you are
logged in."* Work the checklist to green. UX check: is the DNS instruction
set complete enough to execute without guessing?

**5.3 Sealed DKIM.** Run the protect-domain / DKIM step. Expect: requires an
open unlock window; the DKIM private key is generated sealed to your vault
(never handed to opendkim). Verify the selector's public key is published and
a signed send passes at Gmail.

**5.4 Ingest-time sealing.** Log out entirely. Send from Gmail. Expect:
notification is generic ("New mail to user@domain") — no sender/subject by
construction. Log in, don't unlock: the message shows the same sealed
placeholder as any other (no visible "pending-parse" third state). Unlock:
message parses and reads normally, attachments included.

**5.5 Compose locked vs unlocked.** With the window closed, attempt compose/
send from the Fortress mailbox. Expect: refused and surfaced as an unlock
prompt (not a raw error). Unlock → send → DKIM=pass at Gmail.

**5.6 Filters act at next login.** Create a filter (window open). Log out,
send a matching mail from Gmail, log back in + unlock. Expect: the filter's
action applied at login, not at receive.

**5.7 Automated sends refused.** Trigger any transactional/platform send that
would go out as the Fortress domain identity. Expect: refused — only the
in-session compose path may send as that identity.

**5.8 Window caps.** Fortress adds a 2h idle cap after last content decrypt
and a 24h absolute cap — observational only; note as designed-only coverage.

**Phase 5 UX notes:** ______

---

## Phase 6 — Recovery drills under vault

**6.1 Password reset as a vault holder.** Log out, "forgot password."
Expect: the TOTP-alone path from 1.9(b) is **gone**; the passkey authorizer
now additionally demands an independent second factor at
`/password-reset-2fa`. Complete it. Confirm the reset issued a session but
did **not** open or damage the vault (unlock afterward works; a reset never
unlocks the vault).

**6.2 Lost-passkey drill.** Simulate losing passkey #1 (just don't use it):
sign in with password + TOTP, unlock the vault with a **recovery code**,
revoke passkey #1, enroll a replacement, add it to the vault, regenerate
recovery codes (step-up-gated). This is the full "my laptop died" story —
UX-note every rough edge; this is the flow a stressed real user hits.

**Phase 6 UX notes:** ______

---

## Phase 7 — Downgrades

**7.1 Fortress → Private.** Expect: warning; identity posture reverts
(SPF/DKIM/DMARC instructions to undo the cutover), ambient sending capability
returns, MX untouched. Confirm mail still reads (already sealed in the right
form).

**7.2 Private → Standard.** Expect: warning about returning mail to
plaintext; in-session decrypt of the archive back to plaintext columns.
Afterward: reader works with no unlock anywhere, search works anytime,
key material is **retained** (vault still exists), and sole passkey sign-in
stays disabled — that's an account property now, not a domain one.

**Phase 7 UX notes:** ______

---

## Route quick-reference

| URL | Purpose |
|---|---|
| `/profile/security` | 2FA, cadence, recovery address, passkeys, vault |
| `/profile/mailbox/mailbox` | Member webmail reader |
| `/verify-stepup` | Step-up confirmation (passkey or TOTP/backup) |
| `/verify-totp` | Second factor at login (TOTP/backup or passkey) |
| `/password-reset-1` / `-2` / `-2fa` / `-totp` | Reset authorizer flows |
| `/recovery-verify` | External recovery address verification |
| Emails → Mailbox → Accounts / Setup / Mailboxes | Admin domain, DNS checks, admin reader |

## Coverage that stays designed-only (not exercised here)

- Private 7-day absolute backstop; Fortress 2h idle / 24h absolute caps (4.8, 5.8)
- Group-collaboration domains locked to Standard; IMAP-source domains capped at Private (constraint tests, not part of this scenario's fixture)
- Native app locked-flag behavior on `/api/v1` mailbox endpoints (separate mobile pass)
