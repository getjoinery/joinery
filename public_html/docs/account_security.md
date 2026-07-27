# Account Security

The one place the platform's identity-and-access doctrine is written down: what
gets a user *in*, what each credential is allowed to open, and what every
sensitive action demands. The mechanics live in their own docs —
[Passkeys](passkeys.md) for the WebAuthn ceremonies, [Sealed
Vault](sealed_vault.md) for the encryption capability — this doc owns the
rules that tie them together. When a new feature needs to decide "what should
this action require?", the answer comes from here.

## The two doors

An account has up to two doors, and they are deliberately different:

- **The session** — proves *identity*. Opened by password sign-in (or passkey
  sign-in where allowed, below). A session reads and does everything that is
  not sealed.
- **The vault** — proves *presence*. Opened only by a live unlocker ceremony
  (a passkey assertion with user verification, a one-time recovery code, or
  an enrolled bypass phrase). Content sealed to the vault — and nothing else —
  is behind this door. See [Sealed Vault](sealed_vault.md).

Everything below follows from keeping those doors separate.

**Email activation gates every session-opening path.** When
`activation_required_login` is on, an unactivated account is refused a session
by web sign-in (`login_logic`) and by API login (`ApiAuth::attemptLogin`, the
door the native apps use) alike — same setting, same gate. Each refusal
re-sends the activation email, and the refusal is only issued after the
password verifies, so it discloses nothing about account existence. Pinned by
`tests/account_security/login_test.php` (web) and
`tests/functional/api/session_keys_test.php` (API).

## The role split: a passkey never opens both doors

**A passkey never opens both the session and the vault on the same account.**

- An account **without** a vault may use a passkey for passwordless sign-in —
  there is nothing behind the second door, so the single credential guards a
  single door.
- Activating a vault flips it: setup refuses to start until the account has a
  working password, and from then on `passkey_login_verify` rejects
  passwordless sign-in for that account. The passkey remains fully usable as
  a step-up confirmation and as the vault unlocker — only passwordless
  sign-in is withdrawn. (Mechanics: [Sealed Vault § The vault-activation
  flip](sealed_vault.md#the-vault-activation-flip).)

The point is theft math: whoever steals the one object that unlocks the vault
must still produce a different credential to get a session at all.

## Step-up confirmation

Any feature can demand "re-confirm your second factor before this". Passkey and
TOTP step-ups share **one** short-lived verified marker (session-bound, stored
server-side in `pks_passkey_ceremonies`, kind `stepup`): a passkey step-up writes
it via `PasskeyService::verifyStepUp()`, a TOTP step-up via
`SessionControl::stamp_second_factor()`. Step-up proves the account owner is
present at *this* keyboard *now*; a stolen session cookie cannot mint one.

`SessionControl::require_recent_second_factor($return_url)` is the shared gate a
sensitive **administration** action calls: it returns a redirect to the
`/verify-stepup` ceremony (which confirms with a passkey or a TOTP/backup code,
stamps the marker, and returns to `$return_url`) when no recent confirmation
exists, or proceeds otherwise. It is a no-op for an account with no second
factor enrolled — there is nothing to step up with. The line the gate draws:
**the vault gates plaintext redirection; the second factor gates
administration.** Domain security-level changes are gated this way today; the
same helper is how the remaining sensitive-administration actions adopt the gate.

## Fortress mandatory two-factor enrollment

A user who owns or holds a grant on a **Fortress**-level domain
(`InboundEmailDomain::maxSecurityLevelForUser()`) must have a second factor
enrolled that is **independent of any single passkey**: TOTP, or at least two
passkeys (`SessionControl::user_has_independent_second_factor()`). One passkey
alone does not satisfy it — the vault-holder password reset excludes the
passkey that authorized it and demands another factor, so enrollment must
guarantee that a lone stolen authenticator can never be both the reset
authorizer and its own confirmation. Until a qualifying factor exists,
`SessionControl::must_enroll_2fa_for_fortress()` blocks every page behind a
redirect to `/profile/security` (the same session-cached, enrollment-surface
pattern the admin-2FA requirement uses) — exempting only that page and
`/logout` to avoid a loop. Enrolling a qualifying factor clears the gate
immediately. The posture lookup is cached per session and dropped when the
acting user changes a domain's level.

## Second factor at sign-in

Whether a second factor is asked at password sign-in is the account's **2FA
cadence** (`usr_2fa_cadence`): `every_login` asks it on each sign-in;
`sensitive_only` signs in password-only and defers the factor to the step-up
gate above. When it is asked, the sign-in stashes a pending-login state and
diverts to `/verify-totp` (the **"Confirm it's you"** interstitial), which
offers **either** a TOTP/backup code **or a passkey step-up**
(`login_2fa_passkey_options`/`_verify`) — both finish through the same
`Login2fa::completePendingLogin()`. The divert fires whenever the
account holds *any* usable second factor (`user_has_second_factor()` — TOTP,
or a live passkey while `passkeys_enabled` is on), so a passkey-only account is
asked for its passkey at sign-in rather than silently skipping the second
factor. Passkeys stop counting while passkey sign-in is disabled site-wide, so
turning the setting off drops a passkey-only account to password-only sign-in
instead of stranding it at a factor prompt it cannot complete. This is separate
from the vault: a
passkey used here confirms *presence for the session*; it does not open the
vault (that is a distinct user-verification ceremony).

**The divert runs on every request, and exempts its own hand-off.** The pending
state is stashed by the sign-in itself and by the remember-me cookie path
(`get_user_from_cookie()`), so a browser remembered on an account that owes a
factor is diverted on each request until the factor is proved — including after
the trusted-device cookie is gone, which is what forgetting trusted devices
leaves behind. Four requests are exempt, because they *are* how the factor is
proved or abandoned: `/verify-totp`, the two passkey actions that page calls,
and `/logout`. Diverting any of them is an infinite redirect. The pending state
is stashed once per pending login rather than per request, because stashing
rotates the session id that carries it.

**Trusted devices.** The interstitial offers a default-checked **"Trust this
device for N days"** checkbox (N from `totp_remember_device_days`; 0 never
offers it), and `Login2fa::completePendingLogin()` issues the trusted-device
cookie (`sf_trusted`) only when it was checked — trust is an explicit choice,
never a side effect of proving the factor. The cookie's HMAC is signed with
`usr_second_factor_hmac_key`, a per-user key minted lazily by the first trust
grant and independent of any factor *method* — a passkey-only account trusts
devices exactly like a TOTP account. Rotating the key
(`User::rotate_second_factor_hmac_key()`) is the revocation, and every
factor-removal event rotates it: forgetting trusted devices, turning off the
authenticator app, and revoking a passkey. Removing a factor is the moment
device trust re-earns.

**Enrollment flips the ask.** When enrolling a passkey makes
`user_has_second_factor()` flip from false to true, the enrollment response
carries `became_second_factor` and the security page says so inline — the
account starts being factor-prompted at password sign-ins, and that change is
stated at the moment it happens, not discovered at the next sign-in.

**Dual-role passkeys.** Any live passkey may complete the second-factor step
at sign-in — there is no per-credential role scoping — so a vault-active
passkey necessarily also serves as a sign-in factor. The mitigations are
structural, not advisory: vault unlock always demands device user
verification (a stolen device without its PIN/biometric opens neither gate),
and the possession-factor invariant keeps a second factor enrolled at all
times.

## Enrollment is guarded

Adding a credential is itself a sensitive action — a session thief must not
be able to quietly enroll their own key:

- **First passkey** on an account: requires the account password re-entered.
- **Additional passkeys**: require a recent step-up with an existing passkey.
- **Vault unlockers** (another passkey wrapping, regenerated recovery codes,
  a bypass phrase): require an open unlock window, and code regeneration and
  bypass-phrase changes additionally require a recent step-up.

**Possession-factor invariant:** a vault holder must always retain a second
factor beyond memorized secrets — TOTP or at least one live passkey. Both
mutation points enforce it: disabling TOTP is refused when no live passkey
remains, and revoking the last passkey is refused while TOTP is off. Without
it, the vault's knowledge-factor unlocks (recovery code, bypass phrase) would
lose their step-up gate and a phished password + recovery code would open the
vault remotely.

## The unlock window

One unlocker ceremony opens the vault for a bounded window
(`VaultUnlock`, APCu, keyed to the browser session):

- **Opens** on any successful unlocker ceremony. Passkey unlocks demand
  device **user verification** (biometric or key PIN), not merely presence.
- **Extends** on activity; idles out after `vault_unlock_idle_minutes`
  (default 30).
- **Ends** at the idle timeout, on the lock chip's explicit **Lock now**
  ([Sealed Vault § The lock chip](sealed_vault.md)), or with the
  browser session — the window is keyed to the session and never survives
  it. `VaultUnlock::lock()` / `lockAll()` are the generic wipe surface policy
  events call.
- **Presence beacon.** Presence means "on Joinery": every page includes
  `assets/js/vault-presence.js` for signed-in users, and while a window is open
  it stamps `vault_heartbeat` every ~25s (hidden tabs keep beating at the
  browser's throttled cadence). `PublicPageBase` emits a
  `joinery-vault-window` meta flag when a window is open at render time;
  in-page unlock ceremonies start the beacon via `JoineryVaultPresence.start()`
  or a `joinery:vault-unlocked` event. A read that finds the beat stale beyond
  `VaultUnlock::HEARTBEAT_MAX_STALE_SECONDS` (300s, sized above worst-case
  background-tab throttling) ends the window — checked at read time, no cron.
  So navigating anywhere on the site keeps the window alive; only a browser
  that is genuinely gone (closed, asleep, machine off) goes stale.
- **Network identity change.** The existing IP-change guard that zeroes elevated
  permissions on a major address jump also ends that session's window.
- **Per-level caps.** Beyond the idle timeout, a window carries caps by the
  user's highest mail level (`VaultUnlock::capsForUser()`, recorded with the
  window and checked at read time): **Fortress** ends 2h after the last content
  decrypt (idle) and unconditionally 24h after arming (absolute); **Private**
  carries a 7-day absolute backstop.
- **Credential events end every window everywhere** (`lockAll()`) — the remote
  kill switch. A password change, a 2FA method change (disabling TOTP),
  app-session revocation, and a recovery-code unlock all wipe every session's
  window for the account and alert it. So a user whose laptop just walked away
  changes their password from their phone and every window dies with it.

## Two-factor cadence

One account setting, two values (`usr_2fa_cadence`, `User::two_factor_cadence()`):

- **`every_login`** (default) — the second factor is asked on each password
  sign-in.
- **`sensitive_only`** — sign-in is password-only; the factor is asked at
  sensitive actions instead (the step-up gate above). Sound, not a loophole,
  because every escalation from a bare session is independently gated: sealed
  content needs the vault; password/email/2FA changes and recovery-code use
  need a step-up; routing changes need an open window. A phished password on
  this posture sees the mailbox's shape — counts, times, labels, placeholders —
  and opens nothing. The setting's own change is a step-up action, and choosing
  it carries the one-line consequence.

## Unlockers, ranked

- **Passkey** — the everyday unlocker: one tap, user verification required.
- **Recovery codes** — one-time, for disasters, so the strictest path: when the
  account has a second factor, a recovery-code unlock requires a recent step-up
  **regardless of the 2FA cadence** (the API returns a `second_factor_required`
  flag that routes the user through `/verify-stepup` first). On use it **ends
  every open window everywhere** (`VaultUnlock::lockAll()`) and then opens one
  only for the recovering session, and emails a security alert to the account —
  so a *stolen* code lands the thief in a re-locked vault while the owner is
  notified. Consuming one drops the vault into a *regenerate recommended* state
  once fewer than 3 remain unused. **Recovery codes are vault-only**: they
  answer "give me my data," never "log me in."
- **Bypass phrase** — optional fallback (Argon2id-derived, internally `passphrase`), for accounts that
  want a memorized unlocker alongside hardware. Never offered during vault
  setup — added deliberately from unlocker management, behind a warning that
  it lowers the vault's strength to the strength of the phrase. Like a
  recovery code, unlocking with it requires a recent step-up **regardless of
  the 2FA cadence** when the account has a second factor.

**The unlocker floor:** any change that would leave a vault with fewer than 1
passkey wrapping *and* fewer than 3 unused recovery codes is refused at the
point of change — passkey revocation is vetoed
(`PasskeyRevocationVetoException`), wrapping deletions are blocked — and the
refusal names what to enroll first. Losing every unlocker loses the sealed
content permanently; the floor exists so no single careless click can get an
account there.

## Password reset

A password reset re-issues the **session, never the vault**: an authorizer
proves control of the account and yields a signed-in session, but sealed
content still demands an unlocker ceremony the resetter cannot fake. An
admin-assisted reset has the same shape — it can restore the account, and
structurally cannot open the vault. Every reset is a **credential event**: on
completion it ends every open window everywhere (`lockAll()`) and alerts the
account — so even a hostile reset lands the attacker in a re-locked vault.

**A code is spent once, everywhere.** An activation or reset code is a bearer
token: for as long as it resolves, it *is* the account. Consuming one
(`deleteTempCode`) therefore has to end it for every caller, not only the flow
that consumed it — `checkTempCode`, `getIdFromTempCode`, and `getTempCodeInfo`
all treat `act_deleted` as part of what makes a code valid. This matters because
`Activation::ActivateUser()` resolves a code without a separate validity call and
is reached straight from `login_logic`, where an account with no password set is
signed in and sent to `/password-set`. A resolver that honoured a spent code
would make an already-used activation email a live credential until its expiry.
Codes are never invalidated merely by being *read*, so re-clicking a link that
was never completed still works.

**Reset authorizers.** "Forgot password" offers whichever the account holds,
each routed through the one completion path
(`PasswordResetAuthorizers::issueResetUrl()` mints a single-use account code
consumed by `password_reset_2_logic`, never anything vault-scoped):

- **Email link** — the reset link mailed to the account email (and to a
  verified recovery address, if set). Today's flow, unchanged.
- **Passkey** — a sessionless ceremony
  (`password_reset_passkey_options`/`_verify`), reusing the passwordless
  sign-in dispatch. **For a vault holder the passkey is not enough on its own**:
  the ceremony additionally requires an *independent* second factor (TOTP, or a
  passkey other than the one that authorized the reset) at `/password-reset-2fa`
  — without it a stolen authenticator could reset, sign in, and unlock with one
  key, the collapse [the role split](#the-role-split-passwords-vs-passkeys)
  forbids. A vault holder with no independent factor accepts passkey-alone as
  their floor, consistent with declining 2FA everywhere else.
- **TOTP alone** — for accounts **without** a vault only
  (`password_reset_totp_logic`); a vault holder is refused and steered to the
  passkey path *before* any code is verified, so a denied reset never burns a
  backup code. Rate-limited.
- **External recovery address** — an out-of-band inbox the reset link is also
  sent to (`usr_recovery_email`, verified via `recovery_verify_logic`;
  set/removed under step-up in `security_logic`). **Vault recovery codes are
  never account-reset authorizers** — they answer "give me my data", never "log
  me in", and are only consumed by `vault_unlock_recovery_logic`.

**Population 2 — the login email is a hosted mailbox (the circular case).**
Making one of the user's own hosted addresses the account (login) email would
send every future reset link into the very inbox a locked-out user cannot
reach. So it requires holding a **non-email reset path** first (a passkey,
TOTP, or a verified recovery address) — enforced when the login email is
changed (`account_edit_logic.php`) and when a hosted address is chosen at
**account creation** (`register_logic.php`, via
`InboundEmailDomain::isHostedEmailAddress()`), stated at the moment the address
is chosen, not during the crisis.

## Vault-gated routing settings

Sealing protects *content at rest* — it does nothing about a setting that
redirects a sealed mailbox's *future* mail before it is ever sealed. A
filter's "Forward to" action, an alias's destination list, or its delivery
mode all act at receive time on the plaintext parse, upstream of sealing
entirely — a control-plane bypass that leaves the content encryption
technically intact while quietly routing around it. So once a mailbox is
sealed (its single owner holds a Sealed Vault), a mutation to any of these is
refused unless the owner has an **open unlock window** — proof of active,
in-the-moment consent to the routing change, not just a permission check.

This is enforced where the mutation happens (`plugins/mailbox/logic/
admin_mailbox_filters_logic.php`'s `_filter_require_unlock()`,
`admin_mailbox_alias_logic.php`'s `_mailbox_alias_require_unlock()`), not as
a new platform mechanism: both call the existing `VaultUnlock::isOpen($owner_id)`.
Because that check is scoped to the *calling* session, it can only ever be
satisfied by the owner's own logged-in session — an admin managing someone
else's sealed mailbox structurally cannot open that owner's vault, so the
mutation is refused with a clear message rather than a raw permission error.
A domain-wide filter scope (no single owner) and a brand-new alias (no
established owner/grant relationship yet) are never gated. **Outbound relay SMTP
settings** (`mailbox_forwarding_smtp_*`) — repointing the relay routes future
outbound plaintext through an attacker's box — are gated too: the core
settings-save (`adm/logic/admin_settings_logic.php`) refuses a change to any
setting a plugin declares in its `vaultGatedSettings` manifest list
(`VaultGatedSettings`) while the acting account holds a vault with no open
window, saving the rest and prompting the user to unlock and save again. The
list is plugin-declared, so the closed set of reroute settings never becomes a
core dependency on any one plugin.

## What each action requires

| Action | Requires |
|---|---|
| Sign in | Password — or passkey, only while the account has no vault |
| Second factor at sign-in | Asked when cadence is `every_login` and the account holds any factor: a TOTP/backup code or a passkey step-up — skipped on a device the user chose to trust (`sf_trusted`) |
| Read sealed content | Open unlock window |
| Open the window | Unlocker ceremony (passkey + user verification / recovery code / bypass phrase) |
| Enroll first passkey | Session + password re-entry |
| Enroll additional passkey | Session + recent step-up |
| Add a vault unlocker | Open window (+ step-up for codes/bypass phrase) |
| Deactivate a passkey for the vault | Session + recent step-up; refused if it breaks the unlocker floor |
| Revoke a passkey | Session; refused if it breaks the unlocker floor, or if it is a vault holder's last passkey while TOTP is off; trusted devices re-earn |
| Turn off the authenticator app | Session + a current TOTP/backup code; refused for a vault holder with no live passkey; ends all windows; trusted devices re-earn |
| Forget trusted devices | Session; rotates the device-trust HMAC key so every skip-second-factor cookie dies — no session ends, factor methods untouched |
| Rotate the vault key | Live PRF assertion from an enrolled passkey |
| Password reset | An authorizer — email link, passkey, TOTP (no-vault only), or verified recovery address; never opens a vault |
| Passkey reset on a vault account | Passkey **plus** an independent second factor (TOTP or a different passkey), when one is enrolled |
| Set / verify an external recovery address | Session + recent step-up; a hosted-domain or over-length address is refused |
| Change the account password | Session + recent step-up; ends all windows + alerts |
| Change the account (login) email | Session + recent step-up; a hosted address needs a non-email reset path first |
| Regenerate 2FA backup codes | Session + recent step-up |
| Change 2FA cadence | Session + recent step-up |
| Change a domain's security level | Session + recent second-factor step-up |
| Unlock the vault with a recovery code | Session + recent step-up (if a factor is enrolled); ends all other windows + alerts |
| Unlock the vault with a bypass phrase | Session + recent step-up (if a factor is enrolled) |
| Change a sealed mailbox's filters or alias routing | Session + open unlock window (the owner's own) |
| Send as a protected identity domain | Open unlock window, via the mailbox compose path only — ambient/transactional senders are refused outright |
| Protect a domain / stage or cut over a DKIM rotation | Admin session + open unlock window (the key seals to the owner's vault) |

Consumers of the vault (mail, chat) define their own *content* policies —
what they seal, what their locked state looks like — on top of these rules;
see each consumer's own doc.

## Native app: sessions and TOTP

The iOS member app's Security screen
(`ios/joinery-kit/Sources/JoineryMemberKit/SecurityScreen.swift`, reached
from Settings) renders app sessions and TOTP status natively, backed by two
`/api/v1` actions:

- **`security_overview`** (read) — TOTP enabled flag and enrollment time,
  backup-codes-remaining count, the app-session list (device label, created
  time, last-used time, an `is_current` flag on the key that made the
  request), passkey count, and vault-active flag. It is the only read
  surface for `ApiKey` — the model gets no CRUD exposure; this payload is it.
- **`security`** (mutation) — the same TOTP and app-session actions the web
  security page uses: `start_enable` / `confirm_enable` / `cancel_enable` /
  `regenerate_backup_codes` / `disable` for TOTP, `revoke_app_session` /
  `revoke_all_app_sessions` for sessions. The enable flow's response carries
  the `provisioning_uri` (an `otpauth://` string) the native screen renders
  as a QR with `CIFilter.qrCodeGenerator` — the same value the web page's SVG
  QR encodes.

Revoking the session key that made the request signs the native app out
immediately through the client's existing 401 handling
(`SessionController.sessionInvalidatedHandler`) rather than leaving a dead
session in the UI.

**Passkey and Sealed Vault management stay web-only.** WKWebView does not
expose platform WebAuthn to an embedded webview, so the only possible native
path is `ASAuthorizationPlatformPublicKeyCredential` (with PRF for the
vault) — a policy decision of its own (`passkey_register_options`,
`passkey_rename`, and `passkey_revoke` currently require the browser-session
credential; see [API](api.md) § Two authorization axes). The native Security
screen shows passkey count and vault status from `security_overview` and
links out to the web security page for management; native enrollment and
revocation belong to a dedicated future
`mobile_native_security_credentials` spec.
