# First-Login Setup Wizard

## Problem

A fresh personal install has invisible requirements — a way to send mail, a
mail domain, account security, an encryption key, backups — that today fail
only at the moment of first use, and whose setup surfaces are scattered across
`/profile/security`, the mailbox Setup tab, `/admin/admin_settings_email`,
`/admin/admin_backups`, and plugin settings. Nothing guides the owner through
them on first login.

Every one of those surfaces already works. The wizard is a **front end to
them** — a sequential, chrome-less, first-login pass over the existing
ceremonies, panels, and predicates. It builds no parallel setup tab for
anything.

## Relationship to `specs/admin_dashboard.md`

The dashboard spec's card seam (`status()` split from `render()`, live
predicates, plugin cards aggregate-never-duplicate) is the wizard's step seam.
That spec's "setup cards ARE the wizard" decision is preserved: this spec adds
a presentation of the registry, not a second mechanism. The card/step contract
gains two fields (below); whichever spec is implemented first creates the
registry and the other consumes it.

## Design

### The step seam

A core registry (`SetupSteps`, `includes/`) that core and plugins register
steps into at load time — the same registry the admin dashboard's setup band
will read. A step declares:

| Field | Purpose |
|-------|---------|
| `key` | stable identity (`signin_security`, `mail_receive`) |
| `title` | page heading |
| `scope` | `site` \| `user` — **new field.** `site` steps render only for the owner (permission 10); `user` steps render for every account |
| `order` | position in the flow |
| `status()` | `green` \| `amber` \| `none` — derived live from the predicates below, **never a stored flag** |
| `render($page)` | the step's form/controls, reusing existing panels and renderers |
| `copy` | the two-sentence intro — **new field**, so the dashboard card and the wizard page share one text source |
| `active()` | plugin/setting gate (e.g. mailbox active, `mailbox_import_enabled`) — inactive steps vanish from the flow and the count |

**The member flow is free by construction.** Filtering the registry to
`scope = user` yields the per-member wizard (steps 1, 2, 5, 6 below). Both
flows ship together; there is no separate member implementation.

### The `/setup` page

- Full page, **no site navigation** — a minimal header only: site name,
  "Step n of m", and *Finish later*. No sidebar, no menus, no distractions.
- One step per screen, two sentences of copy, one form. Self-documenting
  rules apply: no explainer prose beyond the copy field.
- *Skip* on every step. The only hard gates in the entire flow are the
  shown-once screens (TOTP backup codes, vault recovery codes + key file),
  which require an "I've saved these" check before continuing.
- Steps whose `status()` is already green render as a one-line
  "already done" row and auto-advance.
- The final screen is the live checklist: every step, green/amber, each row
  linking to its **permanent home** (`/profile/security`, the mailbox Setup
  tab, `/admin/admin_backups`, …) — teaching where these controls live from
  now on.

### Trigger and dismissal

- **Trigger:** on login, when the user has never dismissed the wizard and any
  step in their scope reports non-green, redirect to `/setup`. Accounts that
  existed before the wizard shipped never get the redirect: a one-time data
  migration seeds `usr_setup_dismissed_time` for every existing account, so
  they see only the pill. Implemented in
  `SessionControl::check_permission()` alongside the existing interstitials
  (`/change-password-required`, `/terms-accept`), with the same exemptions:
  `/logout`, `/api/v1/` (the wizard's own enrollment fetches must survive the
  gate), and `/setup` itself.
- **Dismissal ("some effort"):** *Finish later* opens a confirm dialog that
  enumerates, from live `status()` calls, what is actually not set up ("No
  backups are running. Your account has no second factor.") with an
  "I understand" checkbox before it releases. Honest friction, not a typed
  phrase.
- Dismissal stores exactly one thing: a per-user timestamp
  (`usr_setup_dismissed_time` on `usr_users` via `$field_specifications`).
  Step completion is never stored — it is always derived.
- After dismissal, a persistent **"Finish setup — n of m"** pill renders in
  the admin header (owner) or the member theme header (members) until every
  step in the viewer's scope is green. The pill opens `/setup` at the first
  non-green step. When all green, the pill and the login redirect both
  disappear forever with no state write.

### One-go apply, not step-through

Where the underlying operations permit, a wizard step collects the whole
desired configuration in one form and applies it in one POST, reporting a
single results panel afterward. Multi-ceremony flows are reserved for steps
that are inherently interactive (WebAuthn prompts, QR enrollment, shown-once
secrets). The receiving-email step (step 4) is the flagship of this principle.

## Steps

Owner flow, in order. Scope `user` steps are the member flow.

| # | Key | Title | Scope | Green when |
|---|-----|-------|-------|-----------|
| 0 | `welcome` | Welcome | user (+site fields for owner) | name + timezone set; owner: site name set |
| 1 | `signin_security` | Sign-in security | user | `SessionControl::user_has_second_factor()` |
| 2 | `encryption_key` | Your personal encryption key | user | a user-scope vault exists (mandatory — the decision row is a capability fallback, not an opt-out) |
| 3 | `mail_send` | Sending email | site | `EmailSender::transactionalSendBlocker() === null` **and** a test send has succeeded |
| 4 | `mail_receive` | Receiving email | site | domain registered + a store-mode alias exists + `mailbox/setup_status` = ok (amber while DNS pends) |
| 5 | `mail_import` | Bring your old mail | user | any completed import run, or explicitly skipped (skip = green here; importing is optional forever) |
| 6 | `calendar` | Calendar | user | a `cpr_calendar_preferences` row exists or any calendar entry exists (opt-out is a valid green) |
| 7 | `ai_provider` | AI assistant | site | a provider resolves in `LlmProviderFactory::allModels()` or "Not now" chosen |
| 8 | `backups` | Backups | site | `backup_target_id > 0` + `BackupRecoveryKey::is_ready()` + `BackupRun` task active |
| 9 | `done` | You're set up | both | all steps in scope green |

For optional steps (5, 6, 7) an explicit "not now" choice counts as green —
the wizard measures *decided*, not *enabled*. Calendar derives this for free
(opting out saves the preferences row, which is the green condition). Mail
import and AI provider leave no trace when declined, so the registry owns a
decision store (`sud_setup_decisions`, below): the row records that the
question was answered, never completion. Real state always wins — a resolving
provider or a completed import run makes the row irrelevant, and the
dashboard card for that area remains the place that shows the real state.

A declined step is green but not *done*, and the wizard says so rather than
pretending. A step that wants the distinction declares `real_status` — the
same predicate as `status` with the decision ignored — and
`SetupSteps::isDeclinedOnly()` uses it. Such a step keeps rendering its
controls (under a "You chose not to set this up" note linking to its
permanent home) instead of the "already done" row, and the final checklist
labels it *not set up, by your choice*. Declining is therefore reversible in
the same place it was chosen.

### Step 0 — Welcome

> Let's get your Joinery set up. We'll secure your account, connect email and
> your calendar, and make sure everything is backed up — you can leave at any
> point and finish later.

Controls: display name (prefilled), timezone (prefilled from the browser;
`usr_timezone` is load-bearing for all calendar wall-clock math and summary
send hours). Owner additionally: site name. Writes: user row;
`SettingsWriter` for the site name.

### Step 1 — Sign-in security

> A passkey lets you sign in with your fingerprint or security key, and
> protects your account even if your password leaks. Add one now — codes from
> an authenticator app work as the fallback.

Controls: **Add a passkey** — drives the existing `passkey_register_options`
/ `passkey_register_verify` actions (first passkey takes `current_password`
inline; the step renders that field). **Enable authenticator codes** — drives
`security_logic`'s `start_enable` / `confirm_enable` (QR + 6-digit field).
On confirm, the ten backup codes render in-step with copy/download and an
"I've saved these" gate. New passkeys auto-attempt vault activation exactly as
the security page does today (`runVaultActivation` chain).

### Step 2 — Your personal encryption key

> Every account gets one: a key held only by you, which locks anything you
> choose to keep private (it is not the site's backup key). Creating it now
> changes nothing about how you use the site — it simply sits there until you
> want one of the things below.

**Holding a key is universal; using it is optional.** A key that is never used
costs its holder nothing, and an estate where everyone already holds one makes
every later feature simpler — no per-user capability check before offering a
private folder, a sealed mailbox, or a protected conversation, and no cohort
that has to be onboarded a second time. So the step does not offer a decline.
The page is framed accordingly: not "do you want this?" but "here is your key,
and here is what it will protect when you want it to."

The step is deliberately short: one sentence on what the key is, the bulleted
**What it will protect, if you want it to**, and the acknowledgement. Nobody
reads a wall of warning, and the acknowledgement is the part that must land.

The title says **personal** and the copy disowns the backup key by name: a
deployment that has set the backup recovery key for scheduled backups reads
"your encryption key" as already done, and then cannot tell why the wizard
disagrees. These are two unrelated keys — one site-wide over backup archives,
one per account over that account's own content — and the wording carries the
difference.

Controls: the existing vault ceremony (`vault_setup_options` /
`vault_setup_verify`): permanent-loss acknowledgement checkbox, create, then
the recovery codes + **Download key file** with an "I've saved these" gate.
Prerequisite (a passkey) is guaranteed by step 1; if step 1 was skipped, this
step says so and offers to go back.

The vault-activation flip (passwordless sign-in is withdrawn once an account
holds a key — [Account Security § The role split](../docs/account_security.md))
is **not** stated on this step. Every phrasing tried here read as a riddle at
the moment of decision, and the flip changes nothing the person is choosing
between: it changes what next sign-in looks like. It belongs where sign-in is
the subject, not where the key is.

The bullets lead — private mail, private Drive folders, saved passwords,
encrypted chats — because they are what the key is *for*, and the
acknowledgement below them is meaningless without knowing what is at stake.

### Mandatory, but never a trap

No decline is offered. But two conditions make a key genuinely impossible, and
neither is something the holder can talk themselves out of, so the step routes
around both rather than stranding the account:

| Condition | Detected by | What the step shows |
|---|---|---|
| No passkey at all | passkey count = 0 | "A passkey comes first" → back to step 1. Not an escape: the account can hold a key once it has one. |
| Every enrolled passkey is `incapable` | `Passkey::vault_capability()` — an authenticator that cannot derive a PRF secret, and no setting or PIN changes that | The hardware limit stated plainly, **Add a passkey elsewhere** as the primary action, **Use a bypass phrase instead** as the compatibility route, and **Continue without one** last |
| Account has no password | `vault_setup_options` answers `requires_password` | The hint says to set one; **Continue without one** is revealed alongside it |
| The passkey turns out not to support PRF | the ceremony fails with *"did not return a derived secret"* (`PasskeyService::verifyDerivation()`) | Same: the hardware limit in plain words, and **Continue without one** revealed |

**The bypass-phrase route is the answer for the largest blocked group.** PRF
support is narrower than passkey support — iPhones before iOS 18, Windows 10,
older Firefox and Android — so "your passkey cannot derive a key" is a mass
condition, not an edge case, and telling those users to buy a new phone is not
a product. They get a vault unlocked by a memorized phrase instead, and can
upgrade to a passkey wrapping whenever they have a capable device. The gate,
the evidence rule and the accepted trade are specified in
[Sealed Vault § When a passkey cannot hold the key](../docs/sealed_vault.md) —
the important half is that eligibility is decided by the credentials on the
server, never offered as a choice, so this can never become "the easy way out"
for someone holding a working passkey.

That last row is the one that cannot be predicted. `vault_capability()` returns
`incapable` only where it can prove it and `unknown` otherwise, and PRF support
is only truly provable by attempting a derivation — so a passkey that looks
usable can still fail at the ceremony. That failure is a supported outcome of
this step, not an error to report: **every refusal the page cannot talk the
user out of reveals the fallback.** A mandatory step must never become a dead
end.

The fallback records the same `decision` = `user` row the optional steps use,
which is what lets a blocked account still reach all-green. It is a capability
fallback, not a preference — it appears only when the platform has proven the
key cannot be created, never as an alternative to creating one.

`real_status` remains the vault itself, so an account that took the fallback
keeps being offered **Create my encryption key** whenever it returns, under the
"You chose not to set this up" note. Fixing the hardware or setting a password
turns the step green for real.

An account that neither creates a key nor is blocked stays non-green and keeps
its place in the "Finish setup — n of m" pill. **Skip for now** still moves the
wizard along, because navigation is not a decision — that is the difference
between deferring the step and settling it.

### Step 3 — Sending email

The step wears two faces, because "not configured" and "configured but
unproven" are different debts and must not read the same. The intro copy is
computed from live state (`'copy'` in the registry may be a callable, read
through `SetupSteps::copyFor()`):

**No working provider** (`transactionalSendBlocker()` non-null or
`detectServiceType() === 'none'`):

> Your site needs a way to send mail — receipts, reminders, sign-in codes.
> Pick a provider and we'll check it actually works before moving on.

The settings form leads. Controls: provider select + that provider's
credential fields, rendered from the same `settings.json` declarations
`/admin/admin_settings_email` renders (`SettingsFieldRenderer`), saved
through `SettingsWriter` with `EmailSender::validateService()` first — same
fields, one renderer, no parallel form. Sender name/address (`defaultemail`,
`defaultemailname`).

**Provider configured, delivery unproven:**

> Your site is already set up to send mail. One check remains: send yourself
> a test message and confirm it arrived — a provider accepting mail is not
> the same as delivering it.

The **Prove it works** block leads; the settings form folds into a collapsed
`<details>` whose summary names the current provider and From address, so an
already-configured site is never re-asked for keys it has. (A third copy
variant covers the proven state, since the green step still shows its intro
above the "Already done" row.)

**Send me a test** button (records last success; per the dashboard spec,
test-send success — not key presence — is the green condition). Direct port-25
self-hosting is advanced setup and never appears here.

### Step 4 — Receiving email (one-go apply)

> Give this site a mail domain and it becomes your mail server. Tell us the
> domain and your address, and we'll set everything up in one go.

One form, one Apply, one results panel — not a step-through ceremony:

**Form:** domain; mailbox address local part (prefilled from the owner's
name); DNS handling choice — *Publish the records for me* (provider +
ephemeral credential fields, credential used for exactly one request and
never stored, exactly as `DnsPublishBox` works today) or *Show me the
records*.

**Apply (single POST):**
1. Register the domain through the existing `add_domain` path so
   `FleetClient::fileDomainClaims()` still runs.
2. Create the store-mode alias and grant it to the acting owner — via the new
   headless provisioning function (below).
3. Compute the DNS plan (`InboundEmailSetupCheck::dnsPlan()`).
4. If a credential was given, apply the plan through `DnsPublishBox::handle`;
   otherwise render the plan read-only via `dns_publish_box_render()`.

**Results panel:** what was created, the DNS state, and the
`mailbox/setup_status` verdict for the new alias. Records still propagating
is an **amber Continue, never a block**; the final checklist and the
dashboard card stay honest via the same `setup_status` action.

Deliberately absent: security level (domains are born `standard`; raising is
the domain page's ceremony), receive topology (undecided = direct = works),
send protection (banned from wizards by
`specs/implemented/mailbox_relay_surface_simplification.md`).

### Step 5 — Bring your old mail

> You can move your existing mail in — upload an export from Gmail, Outlook,
> or any mail app, and it lands in your new mailbox with folders intact. An
> import can be completely undone.

Controls: the shared import panel (`mail_import_panel.php`) mounted as-is —
the wizard is its third mount, alongside `/profile/mailbox/import` and the
admin mount — driven by the existing five `mailbox/mail_import_*` API
actions. A quiet "connect a live account instead" link to the IMAP admin
page (permission 10). Hidden entirely when `mailbox_import_enabled` is off or
the user holds no store-mode alias.

### Step 6 — Calendar

> Your calendar is ready now. If you have an existing one, export it as an
> .ics file and drop it here; we can also email you reminders and a daily or
> weekly summary.

Controls: the existing .ics upload (same `IcsImporter` path the calendar
page's `import_entries` branch uses) + the three reminder/summary preferences
via the `calendar_settings` action. If step 3 is not green, the reminder
controls show the same `send_blocker` notice `/profile/calendar_settings`
computes. No external sync is offered or promised — it does not exist, by
design (`specs/external_scheduling_integrations.md`).

### Step 7 — AI assistant

> Joinery can use an AI assistant to triage mail, draft replies, and manage
> your calendar — running on your own machine if you have one, so nothing
> leaves your network. This is optional; everything works without it.

Controls: three-way choice, own-machine first — **My own machine** (base URL +
model id → `joinery_ai_local_*`), **Cloud provider** (Anthropic or Fireworks
key), **Not now**. Writes the existing `joinery_ai_*` settings through
`SettingsWriter`. **Test it** button → the new `joinery_ai` test-connection
action (below). Renders only when the plugin is active.

### Step 8 — Backups

> Everything here should survive this server dying. Point backups at a
> storage bucket, and create the recovery key that encrypts them — shown
> once, held only by you.

Controls, in order on one page: bucket form (provider/bucket/keys →
`save_target`, then the existing `test_target` connection check — nothing
persists as green without a passing test); the existing
`RecoveryKeySetupPanel` embedded whole (it is already a self-contained
generate → confirm-saved → prove ceremony, and `server_manager` already
reuses it by reference); **Back up nightly** (activates the `BackupRun` task
+ `save_schedule` defaults); **Run one now** (`run_backup`). Superadmin only
by nature; it is a `site` step.

### Step 9 — Done

The live checklist described above, plus one system check surfaced **only if
it fails**: the scheduled-task heartbeat (no tick in > 20 minutes, same
threshold as the dashboard spec's cron card) — because imports, reminder
emails, and backups all silently do nothing without it, and three wizard
steps just promised things that depend on it.

## New backend work

Everything else in this spec is mounting existing panels and calling existing
ceremonies. The genuinely new pieces:

1. **`SetupSteps` registry + `/setup` route/view/logic** — the shell,
   chrome-less layout, step filtering by scope/permission/`active()`,
   redirect gate in `SessionControl::check_permission()` with the `/logout` +
   `/api/v1/` + `/setup` exemptions.
2. **`usr_setup_dismissed_time`** on `usr_users` (via
   `$field_specifications`; schema syncs automatically), plus the one-time
   migration seeding it for accounts that predate the wizard.
3. **Header pill** — "Finish setup — n of m" in admin and member chrome,
   scope-aware, no stored state.
4. **AI test-connection action** (`joinery_ai/test_connection`, permission
   10, descriptor-exposed): builds the provider from the **saved** settings
   (`LlmProviderFactory::build()`) — the action takes no parameters, so the
   API key only ever travels the normal settings-save path. Sequence:
   `reachabilityProbe()` first (a real check only for the local provider — the
   cloud providers deliberately return null and rely on the live call), then
   `createMessage()` with `max_tokens: 1` on the configured default model.
   Returns model id + round-trip time, or the transport/auth error. The
   wizard's flow is save-then-test, same as the plugin settings page. The
   Plugin Settings page gains the same button — the action belongs to the
   plugin, not the wizard.
5. **Headless mailbox provisioning function** —
   `mailbox_provision_mailbox($domain, $local_part, $user_id)` in
   `plugins/mailbox/includes/`: register-domain + create-store-alias + grant
   in one call, extracted from the existing page logic so the Setup tab and
   the wizard share it. Today alias creation and grants exist only inside
   `admin_mailbox_alias` page logic; this is the one mail operation with no
   callable entry point. Contract:
   - **Idempotent.** Domain already registered → reused; alias already
     exists → reused, grant ensured. Re-running step 4's Apply after a
     partial failure (e.g. DNS publish died mid-POST) skips what's done and
     finishes the rest — the single Apply is safe to click again, always.
   - **Carries the protected-domain invariant**
     (`mailbox_protected_grant_error()`), same as the page logic.
   - The vault-unlock gate on routing changes does not apply: this function
     only creates, never edits an existing mailbox's destinations or mode.
6. **`return_to` support** (allow-listed to `/setup`) on the few existing
   page-POST handlers the wizard submits through rather than reimplements —
   `admin_mailbox_setup_logic` actions and `admin_backups_logic` actions —
   so they bounce back to the wizard instead of their own tab.
7. **`sud_setup_decisions`** (core data class): step key + user id (NULL for
   site-scope decisions) + decided time, unique per step/user. The registry
   exposes record/lookup; steps 5 and 7 write it on "Not now". It stores
   decisions, never completion — every green is still derived, with the
   decision row as the tie-breaker for optional steps only.

## Not in scope

- Cloud-storage bucket for public uploads — optional, nothing degrades,
  stays on `/admin/admin_cloud_storage`.
- Send protection / Fortress raising — banned from wizards and checklists by
  its own spec.
- Receive topology / relay choice — undecided means direct, which works.
- External calendar sync (Google/Microsoft) — does not exist; deliberately
  deprioritized.
- Inviting/creating additional members — the member flow triggers whenever a
  member first logs in, however they came to exist.

## Docs to update on implementation

- `docs/admin_pages.md` — step/card registration (shared section with the
  dashboard spec's registry; whoever lands second merges).
- `docs/plugin_developer_guide.md` — plugins contributing setup steps.
- `docs/account_security.md` — add `/setup` to the interstitial/redirect
  inventory in the sign-in path doctrine.
- `plugins/mailbox/docs/overview.md` — the headless provisioning function.
- `plugins/joinery_ai/docs/` (or plugin overview) — the test-connection
  action.
