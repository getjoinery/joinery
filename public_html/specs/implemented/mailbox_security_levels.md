# Mailbox — Security Levels (Per-Domain Protection Posture)

**Status:** Implemented — 2026-07-08. All phases built, review-fixed, and
browser-verified; see `inbound_email_security_levels_executor.md` (build log)
and `security_levels_review_fixes.md` (post-build fixes) in this directory.
**Version:** 1.7 — Fortress 2FA enrollment requires a factor INDEPENDENT of
any single passkey (TOTP or a second passkey): the vault-holder reset excludes
the authorizing passkey and demands another factor, so enrollment must
guarantee one exists — otherwise a single-passkey Fortress holder falls to the
passkey-alone reset floor meant only for Private users who declined 2FA.
(1.6 — § The Unlock Window event 3: presence is site-wide ("on Joinery"), not
mail-page-only; grace threshold ~5 min to tolerate background-tab throttling.)
**Unifies:** `specs/mailbox_encryption_at_rest.md`,
`specs/mailbox_outbound_send_protection.md`,
`specs/mailbox_hardened_ingest_relay.md`. Those specs define the
mechanisms; this spec defines how they are packaged, chosen, and presented.
**Also authoritative for** (added in v1.2–1.5): the per-level authentication
and unlock ceremonies, the role split between passwords/2FA and passkeys, the
unlock-window lifecycle (arming, ending events, caps), the vault-gated
settings rule (rerouting follows the content), and password reset /
account recovery (the three populations). The vault's window
*mechanism* is `specs/implemented/sealed_vault_core.md` (`VaultUnlock`); this spec sets the
mail consumer's window *policy*.

## Goal

Not every address deserves the same protection, and the protections have real
costs. The address used to sign up for a local dance club's newsletter does not
need — and should not pay for — the guarantees the operator's primary personal
identity needs. Let the user choose a **security level per domain**, at setup,
with the tradeoffs stated in plain outcome language.

## Why Three Levels

The three underlying specs don't form an arbitrary feature list — they answer
three distinct questions, and the questions stack:

1. *Can a compromised server read my stored history?* → encryption at rest.
2. *Can a compromised server speak and listen **as me, live**?* → outbound send
   protection + edge-sealed ingest + hidden origin.
3. (The baseline: *do I care at all?*)

Question 2's answers only make sense together: protecting the sending identity
while fresh inbound mail is readable (or vice versa) leaves the user's live
identity half-exposed while paying full ambient-capability costs. Splitting
them into separate levels would create four-plus options differing in ways
only a security engineer could weigh. Merging questions 1 and 2 into a single
"secure" level would force the passphrase-only user to give up automated
sending and run a relay box. Three levels, one per question, is the natural
count:

| | **Standard** | **Private** | **Fortress** |
|---|---|---|---|
| One-line meaning | The server manages this mailbox for you | Only you can read stored mail | Even a fully hacked server can't read new mail or send as you |
| Stored bodies/subjects/attachments/search index encrypted | — | ✓ | ✓ |
| Unlock ceremony required (enroll a passkey + print recovery codes; passphrase optional) | — | ✓ | ✓ |
| Sign in with | password *or* passkey (no vault to double-unlock); 2FA optional | password (vault disables sole passkey sign-in); 2FA optional | password (same); 2FA **enrollment mandatory** |
| Open sealed content with | — | passkey + PIN/biometric (user verification) | passkey + PIN/biometric (user verification) |
| Unlock window ends | — | on lock/leave events (§ The Unlock Window) | on lock/leave events **+ idle and absolute caps** |
| Automated sends from this domain (confirmations, notifications, mailing lists) | ✓ | ✓ | ✗ — sending is session-gated |
| Sending identity survives server compromise (DMARC-enforced) | — | — | ✓ |
| Fresh inbound mail sealed before reaching Joinery (relay at edge) | — | — | ✓ |
| Joinery IP visible in mail DNS | hidden once any relay exists — the relay fronts *all* hosted domains (routing is deployment-wide; sealing is per-level) | same | never — Fortress requires the relay |
| Filters/rules act | at receive | at receive | at next login |
| Previews / search | anytime | in-session | in-session |
| Extra infrastructure | — | — | relay box (provisioned once, shared by all Fortress domains) |
| Key-loss risk | none | mail unrecoverable if every unlocker is lost (all passkeys + recovery codes) | same as Private |
| Best for | club signups, newsletters, low-stakes addresses | mail worth keeping private, where automation must keep working | the address that *is* you — banking, identity, primary correspondence |

**Standard** is today's behavior, unchanged. **Private** is the
encryption-at-rest spec alone. **Fortress** is all three specs together.

## The Unit of Choice Is the Domain

MX records, SPF, DMARC, and DKIM are all domain-level facts — a mailbox cannot
have a different MX than its domain. So the level attaches to the **domain
record**, and every mailbox/alias on it inherits. This also resolves the
automated-send tradeoff recorded in the outbound spec cleanly, with no extra
mechanism: a subdomain is simply another domain entry with its own level. The
operator who needs `user@example.com` at Fortress *and* around-the-clock
confirmation emails puts the automated senders on `mail.example.com` at
Standard. Under Fortress's strict DMARC alignment the Standard subdomain's
keys cannot sign as the bare domain, so the split is safe by construction.

**Group-collaboration mailboxes are Standard-only — a firm product decision,
not a deferral.** Every level above Standard rests on the one-operator,
one-key model (it is what makes the crypto affordable); shared mailboxes would
require multi-recipient sealing and member-revocation re-sealing, which is a
different product. The constraint is enforced structurally in both directions:
a domain hosting a group mailbox (`mailbox_group_collaboration.md`)
cannot be raised above Standard, and a group mailbox cannot be created on a
protected domain — the editors simply don't offer the invalid combination.

**IMAP-source domains** (mail pulled from a remote provider — e.g. a gmail.com
feed) offer **Standard and Private only**: Fortress's guarantees are
meaningless there — the remote provider holds the plaintext and the sending
identity, and there is no MX to move. The level picker hides Fortress for
IMAP-source domains rather than disabling it with an explanation (guided
controls, not explainer prose).

## Key Management Across Levels

One operator, one key hierarchy. The first time any domain is set above
Standard, the setup flow runs the unlock ceremony from the encryption-at-rest
spec, once: enroll a passkey (the everyday unlocker — fingerprint/face, nothing
to memorize; `specs/implemented/passkeys_core.md`), print the one-time recovery codes, and
optionally set a passphrase. Every Private and Fortress domain seals to the
same user keypair; raising a second domain's level never re-runs the ceremony.
Dropping the last protected domain back to Standard does not delete the key
material (re-raising should not re-run the ceremony either).

## Authentication & Ceremonies (Per Level)

### The role split (a firm product decision)

**A passkey never opens both the session and the vault on the same account.**
That single configuration — one ceremony class as front door *and* vault
key — is the collapse being eliminated; everything else is allowed:

- **Account with no vault:** passkey sign-in is available and encouraged —
  it is the unphishable front door, and there is nothing sealed for it to
  double-unlock.
- **Account with an active vault** (any Private/Fortress domain): sole
  passkey sign-in is disabled account-wide — sign-in starts from the
  password. Passkeys answer "is the owner physically present, right now?"
  and that answer opens the vault. The session gate (server-verified
  knowledge + optional second device) and the vault gate (key material that
  only exists in authenticator hardware) stay on different failure modes.
- **Passkey as a second factor: always allowed**, vault or no vault — a
  step-up assertion after the password, alongside TOTP. (When the same
  credential is both a vault unlocker and the login 2FA, the separation
  nudge below applies — warned, not blocked.)

**The flip is an event in the vault setup ceremony**, not a standing feature:
raising the first domain above Standard (1) verifies the account has a
working password — prompting to set one if the user has lived on passkey
sign-in — and then (2) disables sole passkey sign-in for the account
(`passkey_login_verify` rejects users who hold a vault; the login page hides
the button for them). Dropping the last protected domain does not silently
re-enable it — re-enabling is an explicit account-security action, stated in
outcome language.

The built sign-in surface (`passkey_login_options` / `passkey_login_verify`,
the login-page button) therefore remains, serving no-vault accounts; the same
ceremony additionally serves as the passkey-as-2FA step in the password flow
(pending-login state, mirroring `verify_totp_logic.php`). This amends
`specs/implemented/passkeys_core.md` consumer #1.

### What each level requires

| | Sign in (session) | Open sealed content (vault) |
|---|---|---|
| **Standard** | password; 2FA optional (TOTP or passkey step-up) | — (nothing is sealed) |
| **Private** | password; 2FA optional | passkey ceremony with **user verification required** |
| **Fortress** | password; **2FA enrollment mandatory** — adding a Fortress domain (or receiving a grant on one) blocks at next login until a second factor **independent of any single passkey** is enrolled (TOTP, or a second passkey) | passkey ceremony with **user verification required** |

**User verification (the "PIN" question, resolved):** every vault unlock
ceremony sets `userVerification: required` — the authenticator itself must
check a person, not just presence. On platform authenticators that is the
biometric (Touch ID / Face ID / Windows Hello, device-passcode fallback). On
security keys it is the key's own FIDO2 PIN — verified inside the key, never
sent to the server, hardware-locked after 8 wrong attempts. A key with no PIN
set is forced by the browser to create one at enrollment. So "passkey + PIN"
reads formally as "passkey + user verification", and every PRF-capable
authenticator satisfies it. (Login step-up ceremonies stay
`userVerification: preferred` — the session gate has the password already.)

**Honest note on the biometric fallback:** platform authenticators accept the
*device passcode* when the biometric fails (Touch ID → Mac login password,
etc.). So a vault whose only unlocker is the laptop's platform passkey has the
laptop's passcode as its true floor — fine against thieves and remote
attackers, not against someone who knows that passcode. A hardware key's PIN
lives in the key and is independent of every computer it plugs into. The
ceremony/setup UI states this in one line at enrollment (*your device passcode
can stand in for the fingerprint — add a hardware key if you want the vault
independent of this computer*), enabling the choice without forcing it.

### 2FA cadence (user setting)

One account-level setting, two values:

- **`every_login`** — the second factor is asked on each password sign-in.
- **`sensitive_only`** — sign-in is password-only; the second factor is asked
  at sensitive actions: password/email change, 2FA method changes, passkey
  enrollment or revocation, recovery-code view/regenerate, domain
  security-level changes, recovery-code vault unlocks, and — on accounts
  with a vault — the mail-administration actions § Vault-Gated Settings
  assigns here (API keys, mailbox grants, notification toggle, AI recipe
  config).

The cadence is the user's choice at every level (Fortress mandates
*enrollment*, not cadence). When Fortress enrollment triggers, the setting
defaults to `every_login`; the user may relax it, and the setting's helptext
carries the one-line consequence (*password-only sign-ins expose Standard
content and mailbox metadata to a phished password*).

`sensitive_only` is a sound choice — not a loophole — because every
escalation path from a bare session is independently gated: sealed content
and Fortress compose need the passkey; password/2FA/passkey/recovery changes
need the second factor; and the settings that could reroute future mail need
an open vault window (§ Vault-Gated Settings). A phished password on this
posture sees the mailbox's shape — counts, times, labels, placeholders — and
opens nothing.

### Separation guidance (nudge, not gate)

If the credential a user picks as their login second factor is also a vault
unlocker, one stolen bag holds both gates. The ceremony/setup UI nudges
toward separation — login 2FA on the phone (TOTP), vault passkey on the
laptop or a hardware key — and shows a one-line warning when the same
credential ends up in both roles. It never blocks: one YubiKey is still
vastly better than none.

### Recovery-code unlocks

A recovery code is the everything-bypass, so it gets the strictest, least
convenient path — it is for disasters, not Tuesdays: it requires an
authenticated session **plus the account's second factor regardless of
cadence setting** (when the account has one), and every use notifies all of
the user's sessions and devices immediately. Code use also ends every open
unlock window everywhere (see below) — if the code was stolen rather than
recovered, the legitimate owner's notification arrives while the attacker
holds a *re-locked* vault.

### Password reset & account recovery (the three populations)

The governing property: **a password reset re-issues the session, never the
vault.** A successful reset — legitimate or hostile — yields a login:
metadata, Standard content, account standing. Sealed content still demands
the passkey ceremony. Every reset is a credential event (§ The Unlock
Window): all windows end everywhere, all sessions and devices are notified.
This is what lets reset stay humane — it is not the total-takeover event it
is at a conventional email provider.

**Population 1 — external account email (most members of most sites).** The
login address is a gmail/outlook/etc. address. "Forgot password" is today's
email reset link, unchanged. No new requirements: nothing is circular, and
nothing sealed rides on it.

**Population 2 — the login email is a hosted mailbox (the circular case).**
Detectable precisely: the account email's domain is one of the user's own
hosted domains, so the reset link would land in the mailbox the user is
locked out of. The fix is a targeted precondition, not a blanket 2FA
mandate: **making a hosted mailbox the account's login email requires
holding at least one non-email reset path first** — a passkey, TOTP, or an
external recovery address. The account-email change flow enforces it (and
account creation, where a hosted address is chosen up front). You closed the
email escape hatch; you must carry a key. There is no other mandatory-2FA
rule below Fortress.

**Population 3 — reset authorizers, ranked.** "Forgot password" offers
whichever of these the account holds:

- **Passkey** (best): a sessionless ceremony — "Reset with your passkey" —
  then set a new password. **For vault holders the ceremony additionally
  requires the account's second factor**: without it, a stolen
  authenticator could reset the password, log in, and unlock — the passkey
  transitively opening both doors, which § The role split forbids. Fortress
  always has an *independent* second factor to demand — mandatory enrollment
  requires one separate from any single passkey, precisely so this ceremony's
  demand is always satisfiable; a Private user who declined 2FA accepts
  passkey-alone reset as their floor, consistent with every other consequence
  of that choice.
- **TOTP alone** — for accounts without a vault: proving possession of the
  enrolled phone is at least as strong as proving control of an inbox.
  Rate-limited; notified like every reset.
- **External recovery address** — always available as a user choice, with
  the one-line disclosure that account-session security now includes that
  inbox. For a vault user a reasonable trade: a hijacked recovery inbox
  yields a session staring at placeholders.
- **Vault recovery codes are vault-only.** They answer "I lost my devices —
  give me my *data*", never "log me in." Blurring them into account reset
  would soften the one credential whose meaning must stay sharp.

**The stated floor:** a Population-2 user who loses every path — password,
passkey, phone, and holds no recovery address — is locked out of the
account, by design; the setup flow states this trade at the moment the
hosted address is chosen, not during the crisis. Operator-assisted reset
(admin) remains the human backstop for the *account*; it cannot open vaults
— structurally, not as policy.

## The Unlock Window

One vault-unlock ceremony arms a **window**: a bounded period where the
server holds the unwrapped key in RAM (`VaultUnlock`, APCu — mechanism in
`specs/implemented/sealed_vault_core.md`) so reading, search, and AI catch-up work
without re-prompting. The window is **per browser session** (keyed to the
session id; a second browser or device arms its own), and the design goal is:
*you feel it once per sitting, and it is gone the moment your sitting ends.*

### What arms it

A successful passkey ceremony with user verification, inside an authenticated
session (or a recovery-code/passphrase unlock, per their rules above).

### What ends it — the event list

Any one of these ends the window immediately; ending is idempotent and wipes
the APCu entry:

1. **Explicit lock.** A one-click Lock control on every mail surface
   (web and native). The panic gesture is *change your password from your
   phone* — see 7.
2. **Session end.** Logout, session expiry, or session destruction for any
   reason. The window never outlives its session.
3. **Browser gone.** Presence means **on Joinery, not on the mail page**:
   every page carries a presence beacon while a window is open, so moving
   between site pages — mail to calendar to admin — never ends the window,
   and a Joinery tab left in the background still counts as present (the
   browser throttles its beat; the grace threshold sits above the worst
   throttle interval, ~5 minutes). Only a browser that is genuinely gone —
   tab closed, browser quit, machine off — goes silent past the grace
   threshold, and the window ends at the next read. Staleness is hygiene,
   not a security boundary: the hard stops are events 2, 5, 6, and 7.
4. **Machine asleep or locked.** Lid close and system sleep stop the
   beacon (see 3). Where the browser exposes screen-lock/idle signals
   (Idle Detection API), the page reports lock immediately rather than
   waiting out the grace threshold — a progressive enhancement, not the
   mechanism of record.
5. **Network identity change.** The platform's existing IP-change guard
   already zeroes elevated session permissions when a session's address
   jumps; the same trigger ends the window. A laptop that leaves the cafe
   re-locks when it reappears on another network.
6. **Idle and absolute caps (Fortress).** On Fortress domains the window
   additionally ends after **2 hours without a content decrypt** (idle cap)
   and unconditionally **24 hours after arming** (absolute cap) — one touch
   per working day at most, even for a whole-mailbox-Fortress user. Private
   windows carry only a 7-day absolute backstop. (Defaults; if they need
   tuning they become settings, not code changes.)
7. **Credential events (global).** Password change, 2FA method change,
   passkey revocation, or recovery-code use ends **every** window on
   **every** session, everywhere. This is the remote kill switch: a user
   whose laptop just walked away changes their password from their phone
   and every window dies with it.

### The honest residual

A thief who takes a machine **awake, unlocked, on the same network, inside an
armed window** reads sealed content until an event above fires — at worst the
Fortress idle/absolute cap, at best the seconds it takes the owner to trigger
event 7. That residual is inherent to any design where content is readable
in-session (it is ProtonMail's residual too); everything in this spec exists
to make the exposure window short, endable from another device, and
impossible to re-arm without the owner's hardware and verification.

### Native apps

The app arms a window via the platform passkey ceremony (same server-side
`VaultUnlock`). On Fortress, backgrounding the app beyond a 5-minute grace
ends the window and re-entry re-prompts (biometric, one tap); on Private the
window survives backgrounding up to the same caps as web. App session
revocation (the existing App Sessions surface) is a credential event — it
ends that device's window with it.

## Vault-Gated Settings — Rerouting Follows the Content

Sealing the mail while the rules that *route* it sit behind password+2FA
leaves the attacker a better door than the one being guarded: don't pick the
vault, just reprogram what feeds it. A filter on a Private domain acts **at
receive time, on plaintext, before sealing** — a phished-password attacker
who adds "forward everything" reads all future mail without ever touching a
passkey — and a repointed outbound relay does the same to everything sent.
So the rule, stated once:

**When the account has an active vault (any Private/Fortress domain), every
action that redirects protected mail's plaintext — inbound before sealing,
or outbound after composing — requires an open unlock window.** No window →
the same one-tap prompt-and-continue the locked-state contract already
defines for content actions. Since a user rewiring mail routing is usually
mid-session and already unlocked, the gate adds zero friction in legitimate
use; it exists only for the credential thief who cannot produce the touch.

The vault-gated actions (enumerated and closed — a new setting joins this
list only if it can redirect protected plaintext):

1. **Filters and forwarding rules** on protected domains — create, edit,
   import. The receive-time plaintext window on Private is exactly what a
   malicious forward exploits.
2. **Alias changes on protected domains** — destinations, mode
   (store/forward), enable/disable. An alias in forward mode is a filter by
   another name.
3. **Outbound relay settings** (`mailbox_forwarding_smtp_*`) — repointing the
   relay routes future outbound plaintext through an attacker's box.

That is the whole list, and the line it draws: **the vault gates plaintext
redirection; the second factor gates administration.** Sensitive mail
administration on a vault account is a 2FA step-up (it joins § 2FA cadence's
sensitive-actions list), not a window requirement — an automated system
never performs these, and a signed-in owner can perform them from any
device, enrolled or not:

- **API keys** — creation, scope changes, revealing a secret. A stolen key
  cannot open the vault and reads only what a bare session reads — metadata,
  Standard mail, ambient sends; the lock it would need to matter is the one
  it can't pick.
- **Mailbox grants** — minting a reader, but of non-sealed content only;
  sealed mail stays sealed against the grantee.
- **Notification content toggle** on protected mailboxes — leaks future
  sender/subject to the push channel; real, but headers, not bodies.
- **AI recipe configuration targeting protected mailboxes.** Honest
  residual: a recipe reads content inside the owner's *future* windows, so
  an attacker who phishes a 2FA prompt can mint one that waits. Accepted so
  that automation stays manageable from any signed-in device; the recipe
  list is part of the unlock surface — visible wherever windows are.
- **Domain security-level changes** — already on the sensitive-actions
  list, and lowering is structurally in-window anyway (converting sealed
  mail back requires the key).

Unlocker and recovery management keep their § Authentication rules (2FA +
step-up + the structural floor) — those gates are about *identity* changes
and already compose with the vault veto hook.

Actions gated by nothing new, deliberately: app-session and password/2FA
management (2FA-gated identity actions; a new app session alone reads only
placeholders until its own passkey ceremony), Standard-domain filters and
aliases (nothing sealed to protect), and reading any settings page (gates are
on mutation, not navigation).

## Setup Presentation

The level is chosen at **domain creation** (and changeable from the domain
editor), as a required three-option choice — FormWriter radio options styled as
cards, each carrying only: the name, the one-line meaning, a "best for" line,
and its tradeoff lines from the matrix above. Outcome language only — no
mechanism names (no "DKIM", "sealed DEK", "FTS5") at the point of choice.
Default selection: **Standard** (the choice with no obligations attached; the
user opts *into* responsibility, never out of it).

Choosing a level drives the guided setup that follows — the existing
Setup-tab pattern of copy-ready records and verify checks, branched by level:

- **Standard** → today's checklist, unchanged.
- **Private** → Standard's checklist, plus the unlock ceremony if this is the
  first protected domain (enroll a passkey, print recovery codes, optional
  passphrase). The recovery-codes step must state plainly: *lose every passkey
  device and these codes and the mail is gone forever* — and require explicit
  acknowledgment before dismissal.
- **Fortress** → Private's steps, plus the level-specific DNS shape (MX at the
  relay, SPF without the Joinery box, `p=reject` strict-alignment DMARC, the
  forwarding-subdomain records), relay provisioning if this is the first
  Fortress domain, and one confirm-gate stating the operational consequence in
  one line: *this domain cannot send mail unless you are logged in.* If the
  user needs automated sends, the gate offers the subdomain pattern as a
  one-click "add a Standard subdomain for automated mail" action rather than
  prose advice.

`InboundEmailSetupCheck` / `InboundEmailHealth` branch per level: each check
verifies the DNS and infrastructure shape *correct for that domain's level*
(the outbound spec already notes some checks invert for protected domains —
this spec makes the domain's level the branching key).

## Changing Levels Later

- **Raising** is a guided, in-session act:
  - Standard → Private: run the ceremony if needed, then the one-time
    in-window backfill from the encryption-at-rest spec (idempotent,
    `looksEncrypted()`-marked) — which converges each message to the lean
    sealed form *including destroying its plaintext raw* (inline column and
    store file), not merely sealing the columns.
  - Private → Fortress: DNS cutover checklist + relay enrollment; existing
    sealed mail is already in the right form.
- **Lowering** is allowed but warned: Fortress → Private reverts the
  *identity* posture — the SPF/DMARC/DKIM shape and where mail is sealed —
  and re-enables ambient capability; Private → Standard decrypts the archive
  in-session back to plaintext columns. The confirm gate states what protection
  is being given up in outcome language. **A level change never moves the
  MX**: routing is deployment-wide by the relay spec's fronts-every-domain
  rule, so a downgraded domain keeps receiving through the relay (its mail
  simply pass-through-seals to the transport key like any Standard/Private
  domain). Removing the relay itself is a separate deployment-level
  decommission with its own checklist — repoint every domain's MX, re-provision
  the colocated stack, reopen port 25 — never a side effect of one domain's
  level change.
- A level change is a domain-editor action gated on an active session (raising
  needs the key for backfill; lowering needs it for decryption — the gate is
  structural, not policy).

## The Locked-State Surface Contract

Logged in but locked is the state a Private/Fortress user sees most often, so
its behavior is defined once, here, for every surface — not invented per
screen. The rule: **every surface shows cleartext metadata; every action that
needs content becomes a one-tap unlock prompt, and the original action
continues after unlock without re-navigation.**

- **Thread list**: threading, unread counts, labels, folders, times, and sizes
  render normally; sender, subject, and preview show a neutral sealed
  placeholder. The mailbox is *navigable but not readable* — the at-rest
  guarantee made visible.
- **Search** on a protected mailbox: the box renders; submitting prompts
  unlock, then runs the query.
- **Opening a thread, downloading an attachment, composing/replying on a
  Fortress domain**: the same prompt, then the action proceeds.
- **Pending-parse (Fortress) messages** show the same placeholder as sealed
  ones — the user never sees a third state.
- **Native apps inherit the contract over `/api/v1`**: endpoints return
  metadata plus a `locked` flag instead of erroring, so clients render the
  same placeholders rather than failure states, and trigger the native unlock
  ceremony on content actions.

## AI Processing of Protected Mail

Recipes that read message content (`joinery_ai_email_triage.md`,
`joinery_ai_email_security_scan.md`, and any future pipeline job over mail)
interact with the levels through three rules. The same key-gated pattern also
covers the non-AI content reader: spam-feedback learning (`LearnSpamFeedback`
trains rspamd on body tokens) queues cleartext references and learns in-window
on protected domains, while ingest-time spam *scoring* is pre-seal and
unchanged — see the encryption spec § No Sideways Copies.

- **Processing is key-gated, not re-plumbed.** The recipe's scheduled poll and
  per-recipe processing log stay exactly as the triage spec resolved them; the
  only change is a gate: a message on a Private or Fortress domain can be
  digested only while an unlocked session's key is available. Until then it
  simply remains pending in the log — durable, in PostgreSQL, ciphertext at
  rest; **no plaintext side-queue exists at any level.** On Standard the gate
  is always open (today's behavior, unchanged). On Fortress the message also
  waits for deferred ingest, so the login order is: deferred parse → index
  fold → recipe catch-up. Nothing is lost by waiting, by construction: triage
  results are only ever *seen* in-session, so processing that only *runs*
  in-session costs the user nothing.
- **Derived outputs split along the same sealed/cleartext line as everything
  else.** A label is operational metadata — cleartext, so the sorted inbox
  works like folders do. Content-derived text (the one-line `iem_ai_summary`
  gist) is content in miniature — sealed under the message's DEK on protected
  domains and decrypted in-session alongside the previews it renders with.
- **The LLM provider is a disclosure, not a restriction.** The levels promise
  concerns what a *compromised* box can do; sending message text to a
  configured cloud provider is a deliberate operator choice — the same class
  of choice as forwarding mail to Gmail — and is not gated by level (many
  operators have no local model, and pretending otherwise would make Fortress
  unusable). The AI settings for a protected domain carry one line of
  disclosure — *recipes send message text to your configured provider; choose
  a local model if it must never leave the box* — and nothing more.

## Notifications & Native Apps

**What a push notification can say is set by when content legally exists,
not by policy:**

- **Standard**: full notification content (sender, subject, snippet), as the
  mobile spec defines it.
- **Private**: the notification is generated *at the ingest moment*, while the
  plaintext is legitimately in hand pre-seal — so sender and subject are
  available. No server-side plaintext survives the moment; what leaves is a
  one-way copy into the push channel. State the honest limit once: push
  content transits Google/Apple push services and rests on the lock screen —
  operators who don't want that flip a per-mailbox "generic notifications"
  toggle (title only, no content). A disclosure and a switch, not a level gate
  — same doctrine as the LLM-provider rule above.
- **Fortress**: generic by construction, not by choice — the message arrives
  already sealed, so the server *cannot* put content in the notification.
  "New mail to `user@domain`" (recipient and count are cleartext metadata) is
  the ceiling.

**The native apps follow the web's unlock model exactly:**

- Unlock in-app is the passkey ceremony via the platform credential managers
  (`specs/implemented/passkeys_core.md`, native open item), opening the same server-side
  unlock window; reading and search are server-decrypted in-window over
  `/api/v1`, and sealed attachments serve through the gated `File` stream via
  signed URLs as today. No mail-key material is ever stored in the app.
- **Offline cache is a device decision, not a server residual.** A mail app
  that caches messages for offline reading holds plaintext on the user's own
  device, like every mail client ever — governed by the OS sandbox, device
  encryption, and screen lock, and stated as such. Per-level default: caching
  on for Standard/Private, off for Fortress (turn-on-able with the same
  one-line disclosure), so the strictest posture's data lives only where its
  guarantees hold.

## Integration Points That Change

- **Domain data class** — new level field (see Schema).
- **Domain create/edit editor** (Accounts tree `+ Domain` / Edit) — the level
  picker cards and the level-driven guided steps.
- **`InboundEmailSetupCheck` / `InboundEmailHealth`** — branch expected-state
  per domain level (subsumes the per-spec check changes already listed in the
  outbound and relay specs).
- **Ingest, sending, and search paths** — each consults the domain level to
  pick its path (plaintext vs sealed store; ambient vs session-gated signing;
  direct MX vs relay pull; SQL vs FTS5 search). The three mechanism specs
  define both branches of each fork; this spec makes the domain level the
  single switch that selects between them.
- **Login flow** — passkey sole sign-in becomes vault-conditional
  (`passkey_login_verify` rejects vault holders; `views/login.php` hides the
  button for them); the same ceremony additionally lands as the
  passkey-as-2FA step in the password flow, alongside TOTP (pending-login
  state per `verify_totp_logic.php`). The 2FA cadence setting and the
  Fortress mandatory-enrollment gate live in the account security surface;
  the vault setup ceremony gains the password-exists precondition and the
  sign-in flip.
- **Password reset flow** — gains the passkey and TOTP authorizers (§ Password
  reset & account recovery), the vault-holder passkey+2FA rule, the external
  recovery-address field, and reset-as-credential-event wiring; the
  account-email change flow (and signup with a hosted address) gains the
  Population-2 precondition check.
- **`VaultUnlock` end events** — a lightweight heartbeat endpoint for the
  unlocked mail surfaces; hooks from the IP-change guard, logout/session
  destruction, and credential-change paths (password/2FA/passkey/recovery
  events) that wipe windows; the per-level idle/absolute caps; the one-click
  Lock control on mail surfaces.
- **Vault-gated settings surfaces** — the reroute actions in § Vault-Gated
  Settings gain the window-required gate at their logic layer
  (filter/forwarding and alias editors on protected domains, relay SMTP
  settings), reusing the locked-state prompt-and-continue contract; API key
  actions, mailbox grants, the notification toggle, and recipe config join
  the 2FA sensitive-actions step-up instead.

## Schema Changes (via data-class `$field_specifications`)

- Domain record: `security_level` (smallint or enum-style varchar: standard /
  private / fortress).
- No per-mailbox override column — mailboxes inherit by design.

## Documentation to Update

- `plugins/mailbox/docs/overview.md` — a "Security levels" section: the
  three postures, the per-domain unit, the matrix, and the subdomain pattern
  for automated mail (current-state only).
- `docs/settings.md` cross-reference if any level defaults land in settings.

## Open Items to Confirm During Implementation

- Final level names are a product decision; "Standard / Private / Fortress" are
  working names. Criteria: outcome-evocative, one word, no security jargon.
- Whether Private→Standard downgrade (bulk decrypt to plaintext) is worth
  building at all pre-launch, or whether lowering below Private is
  delete-and-recreate until someone needs it.
- Where the level picker sits in the domain-create flow relative to the
  existing provider/hosting-mode choices (MX-hosted vs IMAP-source), since
  IMAP-source hides Fortress.
- Confirm the one-click "add a Standard subdomain for automated mail" action
  can pre-fill everything (domain entry, DKIM provisioning, DNS records) from
  the parent domain's setup state.
