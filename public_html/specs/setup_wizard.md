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
  step in their scope reports non-green, redirect to `/setup`. Implemented in
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
  the admin header (owner) or the profile/member header (members) until every
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
| 2 | `encryption_key` | Your encryption key | user | `vault_status` reports an active vault |
| 3 | `mail_send` | Sending email | site | `EmailSender::transactionalSendBlocker() === null` **and** a test send has succeeded |
| 4 | `mail_receive` | Receiving email | site | domain registered + a store-mode alias exists + `mailbox/setup_status` = ok (amber while DNS pends) |
| 5 | `mail_import` | Bring your old mail | user | any completed import run, or explicitly skipped (skip = green here; importing is optional forever) |
| 6 | `calendar` | Calendar | user | a `cpr_calendar_preferences` row exists or any calendar entry exists (opt-out is a valid green) |
| 7 | `ai_provider` | AI assistant | site | a provider resolves in `LlmProviderFactory::allModels()` or "Not now" chosen |
| 8 | `backups` | Backups | site | `backup_target_id > 0` + `BackupRecoveryKey::is_ready()` + `BackupRun` task active |
| 9 | `done` | You're set up | both | all steps in scope green |

For optional steps (5, 6, 7) an explicit "not now" choice counts as green —
the wizard measures *decided*, not *enabled*. The choice is derived where
possible (7: empty provider settings after the step was visited) and the
dashboard card for that area remains the place that shows the real state.

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

### Step 2 — Your encryption key

> Your private mail, files, and passwords are encrypted with a key only you
> hold — we can't read them and we can't recover them. If you lose every way
> to unlock it, that data is gone for good.

Controls: the existing vault ceremony (`vault_setup_options` /
`vault_setup_verify`): permanent-loss acknowledgement checkbox, create, then
the recovery codes + **Download key file** with an "I've saved these" gate.
Prerequisite (a passkey) is guaranteed by step 1; if step 1 was skipped, this
step says so and offers to go back. The step states plainly that passwordless
sign-in turns off for vault holders — that consequence exists today and this
is the moment to say it.

### Step 3 — Sending email

> Your site needs a way to send mail — receipts, reminders, sign-in codes.
> Pick a provider and we'll check it actually works before moving on.

Controls: provider select + that provider's credential fields, rendered from
the same `settings.json` declarations `/admin/admin_settings_email` renders
(`SettingsFieldRenderer`), saved through `SettingsWriter` with
`EmailSender::validateService()` first — same fields, one renderer, no
parallel form. Sender name/address (`defaultemail`, `defaultemailname`).
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
   `$field_specifications`; schema syncs automatically).
3. **Header pill** — "Finish setup — n of m" in admin and member chrome,
   scope-aware, no stored state.
4. **AI test-connection action** (`joinery_ai`): a descriptor-exposed action
   wrapping `reachabilityProbe()` plus a one-token `createMessage()` against
   the configured provider. Wiring pattern:
   `CloudStorageLifecycle::testConnection()` /
   `ImapIngestor::testConnection()`. The Plugin Settings page gains the same
   button — the action belongs to the plugin, not the wizard.
5. **Headless mailbox provisioning function** — register-domain +
   create-store-alias + grant in one call, extracted from the existing page
   logic so the Setup tab and the wizard share it. Today alias creation and
   grants exist only inside `admin_mailbox_alias.php` page logic; this is the
   one mail operation with no callable entry point.
6. **`return_to` support** (allow-listed to `/setup`) on the few existing
   page-POST handlers the wizard submits through rather than reimplements —
   `admin_mailbox_setup_logic` actions and `admin_backups_logic` actions —
   so they bounce back to the wizard instead of their own tab.

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

## Open questions (owner)

1. Should the login redirect fire for members created before the wizard
   ships (they'd see steps 1–2 amber), or only for accounts created after?
   Recommendation: fire for everyone — the security steps are exactly what
   existing accounts are missing.
2. Pill placement in member chrome: profile menu badge vs. header pill. The
   admin header has an obvious slot; the member theme header may not.

## Docs to update on implementation

- `docs/admin_pages.md` — step/card registration (shared section with the
  dashboard spec's registry; whoever lands second merges).
- `docs/plugin_developer_guide.md` — plugins contributing setup steps.
- `docs/account_security.md` — add `/setup` to the interstitial/redirect
  inventory in the sign-in path doctrine.
- `plugins/mailbox/docs/overview.md` — the headless provisioning function.
- `plugins/joinery_ai/docs/` (or plugin overview) — the test-connection
  action.
