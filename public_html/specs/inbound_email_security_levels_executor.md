# Mailbox — Security Levels — Executor Package

**Status:** BUILD IN PROGRESS — checkpoint 2026-07-08. Built, review-fixed
(three review rounds), and browser-verified: Phases 1, 2, 3, 4, 6, 8, 9, 10,
11, and Phase 5 except 5.4. **Remaining:** Phase 5.4 (passkey-as-2FA at login
+ separation nudge — a login-flow restructure; closes the quirk where a
passkey-only Fortress user is never asked a second factor at sign-in), Phase
7-rest (sessionless passkey reset with the vault-holder +2FA rule, TOTP-alone
reset, external recovery-address field + verify flow, signup-time Population-2
— a takeover surface; build with fresh headroom and verify each authorizer
live), and Phase 12 (final end-to-end acceptance pass, including the unlocked
side of the site-wide presence beacon). Design authority is now **v1.6**:
§ The Unlock Window event 3 changed after build start — presence is site-wide
("on Joinery", `assets/js/vault-presence.js`, 300s grace), not mail-page-only;
that part is already implemented. Scope note recorded: the "add a Standard
subdomain" one-click prefills the domain name only, not DKIM/DNS from the
parent's setup state.
**Version:** 1.2 — status checkpoint added.
**Version 1.1 baseline:** re-derived against design v1.5 and reconciled against
the built system (2026-07-08). All file paths and line anchors below were
verified against the working tree on that date; re-confirm any anchor before
editing.
**Design authority:** `specs/mailbox_security_levels.md` (v1.5) — the *why*, the
three-level matrix, the auth doctrine, the window lifecycle, and the
locked-state contract. This is the *how*.

**All mechanism packages are BUILT** and live in `specs/implemented/`:
`passkeys_core_executor.md`, `sealed_vault_core_executor.md`,
`inbound_email_encryption_at_rest_executor.md`,
`inbound_email_outbound_send_protection_executor.md`,
`inbound_email_hardened_ingest_relay_executor.md`. This package builds **no new
crypto mechanism** — it adds the per-domain level field, makes that field the
single switch selecting each mechanism's already-built branch, completes the
locked-state and unlock-window surfaces, and lands the v1.2–1.5 auth doctrine
(2FA cadence, passkey-as-2FA, password reset, window end events).

The plugin rename is done; every path below is current. Class names
(`InboundEmailDomain`, `MailboxService`, `ModelQueryExecutor`) and table
prefixes (`ied_`/`iem_`) are as they exist on disk.

## Baseline — what is already built (do not rebuild)

The encryption/relay/vault builds implemented much of what the design describes.
The executor's job around these is to *retarget or complete* them, never to
recreate them:

| Built mechanism | Where (verified 2026-07-08) |
|---|---|
| Sealing at ingest, capability-based (`$sealing = ($vault !== null)`) | `plugins/mailbox/includes/InboundEmailRouter.php:380` inside `storeMessage()` (:342) |
| Relay seal-target choice (single-grantee owner with vault → vault key, else transport key) | `plugins/mailbox/includes/RelayMapExporter.php:226` `sealTargetForAlias()` |
| Fortress pending-parse + deferred ingest at unlock | `InboundEmailRouter::parsePendingMessage()` (:501), `plugins/mailbox/includes/DeferredIngest.php`, drain-on-view in `MailboxService` (:725+) |
| Locked-state reads: sealed rows return `[locked - unlock your vault to view]` placeholders | `plugins/mailbox/includes/MailboxService.php` — `listThreads()` :437 (row hook :646–:685), `getThread()` :766 (hook :833+) |
| Locked search signal + FTS5 in-window search | `MailboxService.php` :491–:499, `search_locked` in result :636–:639 |
| AI sealed-fields read hook (locked → placeholder text) | `plugins/joinery_ai/includes/ModelQueryExecutor.php:183` `decryptSealedFields()`, driven by `InboundEmailMessage::$sealed_fields` (`plugins/mailbox/data/inbound_email_message_class.php:115`) |
| Spam-learning gate (sealed raw skipped while locked) | `plugins/mailbox/tasks/LearnSpamFeedback.php:81–84` |
| Vault window mechanism: open/close/lock/lockAll, idle TTL, window marker, passkey-revocation hooks | `includes/VaultUnlock.php` |
| Explicit lock endpoint | `logic/vault_lock_logic.php` (`vault_lock` API action) |
| Vault ceremonies + UI (setup, 3 unlockers, add-passkey, regenerate, rotate) | `logic/vault_*_logic.php`, `views/profile/security.php`, `assets/js/passkeys.js` |
| Vault setup password precondition + sole-passkey-login flip for vault holders | `logic/vault_setup_options_logic.php:21–27`, `logic/vault_setup_verify_logic.php:22–25`, `passkey_login_options/verify` reject vault holders |
| Reroute window gates on **filters** and **aliases** | `plugins/mailbox/logic/admin_mailbox_filters_logic.php:271`, `plugins/mailbox/logic/admin_mailbox_alias_logic.php:175` |
| Fortress identity ceremony (DNS-verified flip of `ied_is_protected_identity`, staged DKIM rotation) | `plugins/mailbox/logic/mailbox_protect_domain_logic.php` + `plugins/mailbox/admin/admin_mailbox_protect.php` |
| Idempotent seal backfill | `plugins/mailbox/logic/backfill_seal_logic.php` |
| TOTP 2FA + step-up ceremonies | `logic/verify_totp_logic.php`, `logic/passkey_stepup_options_logic.php` / `passkey_stepup_verify_logic.php` |
| Password reset (email link) | `logic/password_reset_1_logic.php`, `logic/password_reset_2_logic.php` |
| IP-change guard (zeroes elevated session permissions) | `includes/SessionControl.php` — `_is_major_ip_change()` :1090, trigger sites :1049 and :1107 |

## The one new fact: `ied_security_level`

Add to `InboundEmailDomain::$field_specifications`
(`plugins/mailbox/data/inbound_email_domain_class.php`):

```php
'ied_security_level' => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'standard'), // 'standard' | 'private' | 'fortress'
```

Plus a `security_level()` accessor and a `MultiInboundEmailDomain` filter option
(`security_level`). This is the **single source of truth** for a domain's
posture. No per-mailbox override column — mailboxes/aliases inherit their
domain's level by design.

**The built system is capability-based** — today it behaves as "Private
wherever the owner holds a vault, Fortress wherever the relay finds a
single-grantee vault owner." The level field *restricts* that to chosen
domains. Three concrete injection points, and one non-injection:

1. **Ingest seal gate** — `InboundEmailRouter.php:380`: change
   `$sealing = ($vault !== null);` to also require the domain's level:
   `$sealing = ($vault !== null && in_array($domain->security_level(), array('private','fortress'), true));`
   (`$domain` is already a `storeMessage()` parameter).
2. **Relay seal target** — `RelayMapExporter::sealTargetForAlias()` (:226):
   return the vault-key target (`key_kind=user`, which produces Fortress
   pending-parse rows) **only when the alias's domain is at `fortress`** and the
   single owner holds a vault; every other case seals to the transport key. The
   exporter iterates aliases — confirm how it reaches the domain row (it may
   already hold one per alias; else fetch by `iea_ied_inbound_email_domain_id`
   with a per-domain cache like `vaultPublicKey()`'s).
3. **No change to the pending-parse path** — after (2), `key_kind=user` blobs
   exist only for Fortress domains, so `parsePendingMessage()` /
   `DeferredIngest` need no level check of their own.
4. **Do NOT derive `ied_is_protected_identity` on save.** The outbound build
   ships a verify-gated ceremony (`mailbox_protect_domain_logic.php`) that flips
   the flag only after the published DNS proves the protected shape, and stages
   key rotation. Choosing Fortress *records the level immediately* (sealing at
   ingest is safe from that moment) and drives the user into the protect
   ceremony + relay setup; the domain's Setup/Health tabs show the shape as
   incomplete until the ceremony verifies. Lowering from Fortress reverts
   through the same surface (un-protect), never by a bare flag write.

Rows sealed under the old capability-based behavior remain readable regardless
of the level their domain lands on — sealed-ness is per-row
(`iem_content_sealed`), and the read hooks key off the row, not the domain. No
data migration (pre-launch).

## Level → mechanism-branch switch (the whole job, in one table)

| Concern | Standard | Private | Fortress | Switch point (verified anchor) |
|---|---|---|---|---|
| Ingest store | plaintext columns | seal columns + attachments | seal via relay (deferred parse at unlock) | `InboundEmailRouter.php:380` + `RelayMapExporter.php:226` |
| Search | SQL tsvector | FTS5 in-window | FTS5 in-window | already branches on sealed rows — `MailboxService.php:491` |
| Outbound signing | ambient (opendkim) | ambient | in-app, session-gated | built; keyed off `ied_is_protected_identity`, which Fortress reaches via the protect ceremony |
| DNS shape check | today | today | inverted (SPF w/o box, strict DMARC, MX at relay) | Phase 10 — `InboundEmailSetupCheck` / `InboundEmailHealth` branch on level |
| Receive path | relay pass-through (transport key) | relay pass-through, sealed at ingest | relay sealed-to-owner, pending-parse | `RelayMapExporter.php:226` |
| Filters act | at receive | at receive | at next unlock | built (deferred ingest) |

## Phase 0 — Preflight

Branch `security-levels`. Confirm the baseline table's anchors still hold
(files drift; every line number above is a hint, not a contract). Read
`docs/account_security.md`, `docs/sealed_vault.md`, `docs/passkeys.md` first —
they are the doctrine and mechanism docs this package extends.

## Phase 1 — Level field + switch injection

The schema addition and injection points (1)–(3) above, plus a shared helper
this package needs twice later:

- `InboundEmailDomain::maxSecurityLevelForUser(int $user_id): string` (or an
  equivalent static on the Multi class) — the highest level across domains the
  user owns or holds a grant on. Used by the per-level window caps (Phase 6)
  and the Fortress 2FA-enrollment gate (Phase 5).

Run "Sync with Filesystem" from the admin Plugins page after the field lands.

## Phase 2 — The level picker (domain editor)

- **Form** (`plugins/mailbox/admin/admin_mailbox_domains.php`, `domain_form`
  built at :56): add a **required three-option level picker** after the
  `ied_is_enabled` checkbox (:94). Use FormWriter radio options styled as cards
  (confirm the radio/segmented helper; else `dropinput`), each carrying
  **outcome language only** — name, one-line meaning, "best for", tradeoff
  lines from the design matrix. **No mechanism names** (no "DKIM", "DEK",
  "FTS5") at the point of choice. Default **Standard**.
- **IMAP-source hides Fortress:** a `visibility_rules` entry keyed to the
  existing `domain_type` field (mirror `$type_visibility`, :66–:87) so the
  Fortress card is hidden when the domain is an IMAP source — the remote
  provider holds plaintext and there is no MX to move.
- **Save** (`plugins/mailbox/logic/admin_mailbox_domains_logic.php`, set()
  block :40–:51): set `ied_security_level`. Gates, all structural:
  - **Raising** with an existing vault requires an open window
    (`VaultUnlock::isOpen()`) — the backfill needs the key. First-ever raise
    has no vault yet; the guided ceremony (Phase 3) creates one and opens the
    window, then the save completes.
  - **Lowering** Private→Standard requires an open window (decrypt-back) —
    see the design's open item on whether bulk decrypt ships at all.
  - Choosing Fortress records the level and routes into the protect ceremony
    (Phase 3); it never writes `ied_is_protected_identity` directly.
- **Group-collaboration constraint (firm):** a domain hosting a group mailbox
  cannot be raised above Standard, and a group mailbox cannot be created on a
  protected domain — the editors simply don't offer the invalid combination.
  `mailbox_group_collaboration.md` is not yet built; today's structural proxy
  is an alias with more than one live grant (the relay exporter already treats
  those as transport-sealed via `InboundEmailMessage::singleOwnerUserId()`).
  Enforce against the proxy now; the group build inherits the constraint.

## Phase 3 — The guided setup ceremony (level-driven)

Choosing a level drives the existing Setup-tab detect-instruct-verify flow,
branched. **Reuse the built flows — link/embed, don't reimplement:**

- **Standard** → today's checklist unchanged.
- **Private** → Standard's checklist plus, **if this is the first protected
  domain**, the vault ceremony — the built setup surface
  (`views/profile/security.php` + `assets/js/passkeys.js`, endpoints
  `vault_setup_options`/`vault_setup_verify`, recovery codes, optional
  passphrase via `vault_passphrase_enroll`). Run **once** across all domains;
  raising a second domain never re-runs it; dropping the last protected domain
  does not delete key material. The recovery-codes step requires explicit
  acknowledgment that losing every unlocker = permanent loss. Then the
  one-time idempotent backfill (`backfill_seal_logic.php` — confirm its
  trigger surface) converges existing messages to lean sealed form *including
  destroying plaintext raw*.
- **Fortress** → Private's steps plus the protect ceremony
  (`admin_mailbox_protect.php` / `mailbox_protect_domain_logic.php`: forwarding
  subdomain, sealed DKIM key, DNS publish, verify, activate), the
  level-specific DNS shape (MX at relay, SPF without the box,
  `p=reject; aspf=s; adkim=s`), relay provisioning **if this is the first
  Fortress domain** (the relay package's provisioning surface —
  `admin_mailbox_relay.php`), and one confirm-gate: *this domain cannot send
  mail unless you are logged in.* If the operator needs automated sends, the
  gate offers a one-click **"add a Standard subdomain for automated mail"**
  action pre-filling a `mail.<domain>` domain entry + DKIM provisioning + DNS
  from the parent's setup state.

## Phase 4 — Completing the Locked-State Surface Contract

The rule: **every surface shows cleartext metadata; every content action
becomes a one-tap unlock prompt, and the original action resumes after unlock
without re-navigation.** The server side is largely built (baseline table);
what remains:

### 4.1 Web reader

- **Placeholder + flag.** The row read hook (`MailboxService.php:646–:685` and
  the `getThread()` twin at :833+) currently substitutes the literal
  `[locked - unlock your vault to view]`. Replace with a neutral product
  placeholder (single sealed-message string, no bracket syntax — product copy,
  e.g. "Sealed message") and add a top-level `locked: true` to the
  `listThreads()` / `getThread()` result whenever any substitution happened
  (pattern: the existing `search_locked` at :636–:639). Threading, unread,
  labels, folders, times, sizes continue to render normally.
- **One-tap unlock + resume.** In `plugins/mailbox/assets/mailbox_reader.js`:
  `threadRow()` (:288) renders the placeholder as-is; on a `locked` response,
  opening a thread (`renderThread()`, :376), submitting search, downloading an
  attachment, or composing on Fortress shows the unlock prompt, runs the built
  passkey ceremony (`assets/js/passkeys.js`, `vault_unlock_options` →
  `vault_unlock_passkey`), then **re-runs the original request** without
  navigation.
- **Pending-parse (Fortress) rows** must render the **same** placeholder as
  sealed ones — never a visible third state. They store empty content columns
  today; verify the list path substitutes the placeholder for them too (key off
  `iem_relay_sealed_raw` presence / pending state, not just sealed-row
  decrypt failure).

### 4.2 Native `/api/v1` (the five mail actions)

All `requires_session=true`, gated on `MailboxViewer::fromSession()`. Insert
the `locked` flag so clients render placeholders and trigger the native unlock
ceremony instead of erroring:

| Action | Logic file | Insertion point |
|---|---|---|
| `mailbox/thread_list` | `plugins/mailbox/logic/thread_list_logic.php` | returns `listThreads()` verbatim (:42) — the Phase 4.1 payload change covers it; verify only |
| `mailbox/thread` | `plugins/mailbox/logic/thread_logic.php` | returns `getThread()` — same |
| `mailbox/send` | `plugins/mailbox/logic/send_logic.php` | Fortress compose while locked → return `locked: true` instead of sending (`MailboxSender` construction :52) |
| `mailbox/mailboxes` | `plugins/mailbox/logic/mailboxes_logic.php` | payload (:31) gains each mailbox's `security_level` + current `locked` state for the switcher |
| `mailbox/thread_action` | `plugins/mailbox/logic/thread_action_logic.php` | mark/star/delete are cleartext-metadata — **unaffected**, keep working while locked |

The native unlock ceremony is the platform-passkey `vault-kek` derivation over
`/api/v1`, opening the same server-side window.

## Phase 5 — AI processing & auth doctrine (the v1.2–1.5 build items)

### 5.1 AI reads: pending, not placeholder

`ModelQueryExecutor::decryptSealedFields()` (:183) currently substitutes
placeholder text for locked rows — an AI recipe would "process" the
placeholder as content. Per design § AI Processing: **a locked row is excluded
from AI results and stays pending.**

- Change the locked branch to **drop the row** from the result set instead of
  substituting text, and surface the count in the tool payload (e.g.
  `locked_rows_excluded: N`) so the model knows results are partial.
- The recipe pipeline (`plugins/joinery_ai/recipe_tools/QueryModelTool.php` is
  the query entry) must not mark excluded messages processed — confirm how the
  per-recipe processing log marks rows and ensure excluded rows remain
  eligible for catch-up after unlock. On Fortress the login order is already
  built: deferred parse → index fold → recipe catch-up.
- **AI exposure opt-in:** declare `public static $ai_readable = true;` and
  `public static $ai_untrusted_fields = array('iem_sender','iem_subject','iem_body_plain');`
  on `InboundEmailMessage` (near `$sealed_fields`, :115) — the surface contract
  is documented in `docs/example_class.php` (:89–:119). `iem_ai_summary` is
  already a `$sealed_fields` member (content-in-miniature).
- **LLM provider is a disclosure, not a level gate.** The AI settings surface
  for a protected domain carries one line — *recipes send message text to your
  configured provider; choose a local model if it must never leave the box* —
  and nothing more.
- **Spam learning is built** (`LearnSpamFeedback.php:81–84` skips sealed raw
  while locked); verify only.

### 5.2 2FA cadence (account-level user setting)

One setting, two values: `every_login` | `sensitive_only` (design § 2FA
cadence). Store per-user (follow the existing per-user preference pattern —
confirm whether that is a `usr_` column or the user-settings mechanism).
Sign-in flow (`logic/login_logic.php` + `verify_totp_logic.php`) asks the
second factor per cadence; `sensitive_only` defers it to the sensitive-actions
step-up (5.5). When Fortress enrollment triggers (5.3) the setting defaults to
`every_login`, relaxable, with the one-line consequence in helptext.

### 5.3 Fortress mandatory 2FA enrollment

Adding a Fortress domain (or receiving a grant on one) blocks at next login
until a second factor is enrolled. Enforcement point: after password
verification in `login_logic.php`, check
`maxSecurityLevelForUser() === 'fortress'` && no TOTP && no step-up-capable
passkey → route to a blocking enrollment page (pattern:
`change_password_required_logic.php`).

### 5.4 Passkey as a second factor + separation nudge

The passkey step-up ceremony (`passkey_stepup_options/verify`, built) becomes
an alternative to TOTP in the password sign-in flow — a pending-login state
mirroring `verify_totp_logic.php`. Show the separation nudge (one-line
warning, never a block) when the credential chosen as login 2FA is also a
vault unlocker (`uew` wrapping exists for that credential).

### 5.5 Sensitive-actions step-up (shared helper)

A shared assertion (e.g. `SessionControl::requireRecentSecondFactor()` or
equivalent) that prompts TOTP/passkey step-up and stamps the session; used by:
password/email change, 2FA method changes, passkey enrollment/revocation,
recovery-code view/regenerate, **domain security-level changes**, and — on
vault accounts — API keys, mailbox grants, the notification toggle, and AI
recipe config targeting protected mailboxes. (The line: **the vault gates
plaintext redirection; the second factor gates administration.**)

### 5.6 Recovery-code unlock hardening

`logic/vault_unlock_recovery_logic.php` currently requires only a session. Add:
the account's second factor **regardless of cadence** (when enrolled), notify
all sessions/devices on use, and end **every** open window everywhere
(`VaultUnlock::lockAll()`).

## Phase 6 — Unlock-window end events

`VaultUnlock` (built) holds windows on an idle-extended APCu TTL and already
wires passkey-revocation hooks; its docblock names everything else a consumer
call. Build the consumers (design § The Unlock Window):

1. **Explicit lock.** One-click Lock control on every mail surface (web reader
   header; native follows) calling the built `vault_lock` action.
2. **Session end.** Logout/session destruction → `VaultUnlock::lock()` for
   that session (confirm the logout path; add the hook if absent).
3. **Heartbeat.** A lightweight `vault_heartbeat` API action stamped by the
   unlocked mail surfaces while visible-or-recently-active (JS: visibility-aware
   interval; Idle Detection API as progressive enhancement, not the mechanism
   of record). Window-read paths (`isOpen()`/`secretKey()`) treat a heartbeat
   stale beyond one 60 s grace interval as an end event — read-time check, no
   cron.
4. **IP change.** The existing guard (`SessionControl.php` :1049/:1107,
   `_is_major_ip_change()` :1090) additionally ends that session's windows.
5. **Per-level caps.** Fortress: 2 h without a content decrypt (idle) and 24 h
   absolute; Private: 7-day absolute backstop. Level =
   `maxSecurityLevelForUser()`. Mechanism: record armed-at with the window;
   check caps at read time. The idle-touch stamp exists
   (`touchWindowMarker()`, `VaultUnlock.php:170`). Defaults in code; they
   become settings only if tuning is ever needed.
6. **Credential events (global kill switch).** Password change
   (`password_edit_logic.php`), password reset (`password_reset_2_logic.php`),
   2FA method changes, recovery-code use (5.6), app-session revocation →
   `VaultUnlock::lockAll($user_id)` + notify all sessions/devices. Passkey
   revocation is already wired (`registerRevocationHooks()`).
7. **Native grace** (Fortress: backgrounding beyond 5 min ends the window) —
   rides the native app packages; record the contract in the docs (Phase 11),
   no server change beyond the caps above.

## Phase 7 — Password reset & account recovery

Design § Password reset (the three populations). The governing property: **a
reset re-issues the session, never the vault** — and every reset is a
credential event (Phase 6.6).

- **Authorizers** added to the reset flow (`password_reset_1/2_logic.php`):
  - **Passkey reset** — a sessionless ceremony ("Reset with your passkey" →
    set a new password), reusing the sessionless-dispatch pattern from
    `passkey_login_options/verify`. **Vault holders additionally require the
    account's second factor** (closes the transitive both-doors hole).
  - **TOTP alone** — accounts *without* a vault only. Rate-limited, notified.
  - **External recovery address** — new user field + verify flow, always
    offered as a choice with its one-line disclosure.
  - **Vault recovery codes are vault-only** — never accepted for account
    reset.
- **Population-2 precondition:** making a hosted mailbox the account's login
  email (account-email change flow, and signup when a hosted address is chosen)
  requires holding ≥1 of passkey / TOTP / external recovery address first.
  Detect: the email's domain is one of the user's own hosted domains. State
  the locked-out floor at that moment, not during the crisis.
- **Admin reset** stays the human backstop for the *account*; it cannot open
  vaults — structural already, verify nothing added violates it.

## Phase 8 — Remaining vault-gated setting

Filters and aliases are already window-gated (baseline). The third and last
reroute surface: **outbound relay settings** (`mailbox_forwarding_smtp_*`,
form in `plugins/mailbox/settings_form.php` — confirm its save path). When the
account has an active vault, saving them requires an open window, reusing the
locked-state prompt-and-continue contract. That completes the enumerated,
closed list — a new setting joins it only if it can redirect protected
plaintext.

## Phase 9 — Notifications & native apps

- **Push content is set by when plaintext legally exists, not policy.**
  Standard: full (sender/subject/snippet). Private: generated at the ingest
  moment (pre-seal, plaintext legitimately in hand) → sender + subject; a
  per-mailbox "generic notifications" toggle (title only) — a disclosure +
  switch, not a level gate (the toggle joins the 2FA step-up list, 5.5).
  Fortress: generic by construction — the message arrives sealed; ceiling is
  "New mail to `user@domain`". Confirm the mail push generation point (the
  mobile/push package's ingest hook) and branch it on level there.
- **Native offline cache** is a device decision: default on for
  Standard/Private, **off for Fortress** (turn-on-able with the same one-line
  disclosure). Rides the native app packages; record the contract in docs.

## Phase 10 — Setup/health branching

`InboundEmailSetupCheck` / `InboundEmailHealth` branch expected DNS/infra shape
on `ied_security_level` — SPF inversion, strict DMARC, MX-at-relay for
Fortress (the relay/tunnel checks are already Fortress-aware,
`InboundEmailHealth.php:331`); today's shape for Standard/Private. Level is
the single branching key; no new check logic beyond making it the switch.

## Phase 11 — Docs

`docs/account_security.md` is the platform's single doctrine doc — **extend
it, never create parallel security docs**: the 2FA cadence setting, the
per-level ceremony table, the window end-event list, the vault-gated reroute
rule + step-up split, and the password-reset three-populations rules, all in
current-state voice.

`plugins/mailbox/docs/overview.md` — a "Security levels" section: the three
postures, the per-domain unit, the matrix, the subdomain pattern for automated
mail, notification/offline-cache defaults. `docs/settings.md` cross-reference
if any level default lands in settings. `docs/api.md` — the `locked` flag on
the five mail actions + `vault_heartbeat`.

## Phase 12 — Verification (acceptance gate)

12.1 `php -l` + `validate_php_file.php` on every edited PHP file.

12.2 On `dev.getjoinery.com`:

- **Picker:** three outcome-language cards, default Standard; Fortress hidden
  for an IMAP-source domain; a multi-grantee (group-proxy) domain refuses
  above Standard.
- **Switch injection:** a vault holder's **Standard** domain stores plaintext
  (the capability-based behavior is gone); raising it to Private seals new
  ingest; only a Fortress domain's relay map entry seals `key_kind=user`.
- **Raise Standard→Private:** ceremony runs once; backfill seals + destroys
  raw; a second domain raised to Private does not re-run the ceremony.
- **Locked-state web:** placeholders + metadata while locked; search / open /
  attachment / Fortress compose prompt one-tap unlock, then **resume the
  original action**; pending-parse rows show the same placeholder.
- **Locked-state native:** `thread_list`/`thread` return `locked: true` +
  metadata; `thread_action` (mark read) still works locked; `mailboxes`
  carries level + locked per mailbox.
- **AI gate:** a recipe over a locked Private mailbox **excludes** the rows
  (no placeholder text reaches the model), reports the excluded count, and
  catches up after unlock; `$ai_readable` exposure works via `query_model`.
- **Auth doctrine:** cadence `sensitive_only` skips login 2FA but step-up
  fires on the 5.5 list; Fortress domain add blocks next login until 2FA
  enrolled; recovery-code unlock demands 2FA + kills all windows + notifies.
- **Window end events:** heartbeat loss (~60 s after tab close) ends the
  window; IP change ends it; password change from a second session kills the
  first session's window; Fortress caps enforce (spot-check by shortening the
  constants in a dev-only override).
- **Password reset:** passkey reset works sessionless (vault holder also asked
  for 2FA); TOTP-alone reset refused for a vault holder; hosted-mailbox login
  email refused until a non-email reset path exists; any reset ends all
  windows.
- **Relay SMTP gate:** saving `mailbox_forwarding_smtp_*` on a vault account
  while locked prompts unlock.
- **Fortress send:** locked compose returns `locked`; the automated-subdomain
  one-click adds `mail.<domain>` at Standard.
- **Lowering:** Private→Standard decrypts with the give-up-protection gate;
  MX does not move.

12.3 `batcat` commands for each edited file (do not run them).

## Open items the executor confirms against the running system (not decisions)

- Final level names (product decision; "Standard/Private/Fortress" are working
  names — outcome-evocative, one word, no jargon).
- The exact FormWriter radio/segmented-card helper for the picker (else
  `dropinput`).
- Whether Private→Standard bulk-decrypt is worth building pre-launch or is
  delete-and-recreate until needed (design open item).
- How `RelayMapExporter` reaches each alias's domain row (existing per-domain
  data vs a fetch + cache).
- The per-recipe processing-log mark point (Phase 5.1's "excluded rows stay
  eligible" guarantee).
- The per-user preference storage pattern for the 2FA cadence setting
  (`usr_` column vs user-settings mechanism).
- The mail push notification generation point (Phase 9 branch site).
- The external recovery-address verify flow shape (reuse the existing email
  verification pattern).
- Where the picker sits relative to the `domain_type` (MX vs IMAP) choice,
  since IMAP hides Fortress.
