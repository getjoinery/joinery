# Menu System Redesign

**Status: BUILT 2026-07-30 — implemented with the § 6 defaults; owner is evaluating on dev before this moves to implemented/.**

## 1. Problem

Navigation has accreted rather than been designed. The root cause is structural:
one menu table (`amu_admin_menus`, location `user_dropdown`) is rendered three
different ways — the avatar dropdown, the member section nav, and the mobile-app
nav — so the surfaces duplicate each other by construction. And the platform has
no settings layer: every page that isn't an "app" (account settings, billing,
import, conversations) has nowhere canonical to live, so features get bolted
onto whatever surface is nearest — a flat nav entry (AI Memory, Devices), an
Actions dropdown (import), or nothing at all.

## 2. Current-state inventory

### 2.1 The seven nav surfaces

| # | Surface | Contents | Source | Rendered at |
|---|---|---|---|---|
| 1 | Black admin quickbar (perm 10 only) | + New (page/post/user/file), theme switcher, Dashboard, viewer name | hardcoded | `includes/PublicPageBase.php:1715` `render_admin_bar()` |
| 2 | Admin left sidebar | ~50 items in groups | `amu_admin_menus` location `admin_sidebar`, seeded from `admin_menus.json` + each plugin's `plugin.json` `adminMenu` | `includes/PublicPageJoinerySystem.php:403` |
| 3 | Admin top-right | cart, vault chip slot, notification bell, nine-dots launcher, avatar dropdown | launcher = `core-home`/`core-profile`/`core-admin-*` rows; avatar = all `user_dropdown` rows minus `core-admin-*` | `includes/PublicPageJoinerySystem.php:455-541` |
| 4 | Profile header (member pages) | logo, public marketing menu, vault chip, messages, bell, Admin button (perm >= 5), avatar dropdown | marketing menu = `pmu_public_menus` table | `includes/PublicPage.php:78-178` |
| 5 | Member section nav | 12 flat items (see 2.2) | `user_dropdown` rows minus Home / Sign out / `core-admin-*` | `includes/PublicPageBase.php:215-261` |
| 6 | Account tab strip | Edit Account / Change Password / Contact Preferences / Security (+ Address and Phone, suppressed at render) | the same 6-entry array duplicated verbatim in 7 logic files (`logic/account_edit_logic.php:143` et al.) | `includes/PublicPageBase.php:504-524` |
| 7 | In-page Actions menus | calendar import, mailbox import, security actions, per-row menus | per-view | five markup conventions: `jy-actions-dropdown`, Bootstrap `.dropdown` (admin), `.mbx-*`, `.scd-actions-menu`, `.drv-menu`, `.conversation-more-menu` |

The admin left sidebar (#2) is healthy — DB-driven, grouped, permission- and
setting-gated, admin-editable. It is out of scope for this redesign.

### 2.2 Member section nav items (= avatar dropdown, = app nav)

| Order | Label | URL | Owner | Gate |
|---|---|---|---|---|
| 50 | My Profile | `/profile` | core | — |
| 55 | Calendar | `/profile/calendar` | core | — |
| 56 | Drive | `/drive` | core | `drive_active` |
| 58 | Email | `/profile/mailbox/mailbox` | mailbox | plugin |
| 60 | Orders | `/profile/orders` | store | `products_active` |
| 70 | Subscriptions | `/profile/subscriptions` | store | `subscriptions_active` |
| 70 | Passwords | `/profile/vault` | vault | plugin (order collides with Subscriptions; tie broken by slug) |
| 72 | AI Chat | `/profile/joinery_ai/chat` | joinery_ai | plugin |
| 73 | AI Memory | `/profile/joinery_ai/memory` | joinery_ai | plugin |
| 75 | Filtering | `/profile/dns_filtering/profile` | dns_filtering | plugin |
| 76 | Devices | `/profile/dns_filtering/devices` | dns_filtering | plugin |
| 80 | My Events | `/profile/events` | event_manager | `events_active` |

The list mixes three kinds of thing with no hierarchy: apps (Email, Calendar,
Drive, Passwords, AI Chat), record lists (Orders, Subscriptions, My Events),
and plugin sub-pages (AI Memory, Devices). "Devices" is the DNS-filtering
plugin's protected-device list — a naming collision with the security page's
trusted devices.

### 2.3 Orphan pages (reachable by URL, in no nav)

- `/profile/conversations` + `/profile/conversation` (header envelope icon only)
- `/profile/billing`, `/profile/change-tier` (store)
- `/profile/address_edit`, `/profile/phone_numbers_edit` (tabs deliberately suppressed at `includes/PublicPageBase.php:506-508`)
- `/profile/test-authenticator` (link inside Security only)
- `/profile/mailbox/import` (mailbox Actions dropdown + notification links only)
- `/profile/event_sessions`, `/profile/event_sessions_course`
- `/profile/bookings/my_bookings`, `/profile/bookings/availability` (bookings has no profileMenu at all)
- `/profile/server_manager/connect_cloud` (a member page whose only menu entry is inside the perm-10 admin Server Manager group)
- `/notifications` (bell icon only; also outside `isMemberAreaPath()` so it renders public-theme chrome with no member nav)

### 2.4 Small rot found along the way

- Admin Utilities (`adm/admin_utilities.php:29-32`): 3 of its 4 links point at the identical URL (`/utils/update_database` with no distinguishing params).
- The Utilities menu row is perm 6; the page requires perm 10 — perm 6–9 users see the link and bounce.
- Nine-dots launcher and admin avatar dropdown both contain Home and My Profile.
- `get_admin_icon_svg()` has no `search` icon, so SEO Pages falls back to the dashboard icon.
- Admin topbar footer prints a hardcoded `v0.5.0` independent of `/VERSION`.
- Four phrasings for the same verb: "Import from another calendar (.ics)", "Import old mail from another provider", "Import .vcf / .csv", "Import".

### 2.5 The admin/profile config split today

- **Mailbox:** all config is admin-only (Mailboxes / Accounts / Filters / Logs / Setup / Settings tabs). Per-mailbox, member-shaped things — signature (the one exception: reader gear icon), forwarding, IMAP connect, filters, import — sit behind perm 5. The reader deliberately withholds the "needs attention" setup banner from members (`plugins/mailbox/includes/mailbox_reader_mount.php:47-56`), so a member with a broken mailbox sees nothing and can do nothing. Mail import is the counter-example that already works: one shared panel (`mail_import_panel.php`), two mounts, ownership-scoped.
- **Notifications:** `adm/admin_notification_preferences.php` is per-user config that happens to live in adm/; members have no equivalent — `/notifications` is read-only.
- **AI:** member chat exposes per-chat settings in a composer disclosure; site keys are admin. A member tooltip references "settings" they cannot open (Brave key).
- **DNS filtering:** the model citizen — zero admin pages, everything per-user under `/profile/dns_filtering/*`; its two site settings are pure infrastructure.
- **Vault/passkeys:** clean split already (site toggles admin, everything else on `/profile/security`).
- **Drive, Calendar:** no admin page and no member settings page at all.

## 3. Design principle: split by scope, not by topic

A setting belongs to **profile** when it affects only that user's own stuff
(their forwarding, their connected accounts, their filters, their devices,
their notification preferences) — gated by *ownership*. It belongs to
**admin** when it affects other users or the platform (domains, DKIM, relay,
themes, plugins, tiers, site settings) — gated by *permission*.

"Everything config goes in admin" fails the multi-user install, where members
have no admin access. The scope rule doesn't, and single-user installs lose
nothing: the owner sees both areas. One feature can split — mailbox
per-mailbox config is profile-side, mailbox domain/relay/fleet config is
admin-side.

## 4. Target design

### 4.1 Black quickbar — remove (pending decision A)

Its one real job — jumping to admin from the public site — is served by a
single small "Admin" chip for perm >= 5, matching the Admin button the member
header already has. "+ New" and the theme switcher go away (theme switching
moves to the admin Themes page as a preview/switch control). Removing the bar
also removes the 32px `body` margin hack and its `:has()` compensation in
`joinery-styles.css`.

### 4.2 Profile header becomes an app header

Member pages stop rendering the public marketing menu (`pmu_public_menus`
stays on the public site only). The header keeps: logo → `/`, vault lock chip,
messages, bell, Admin (perm >= 5), avatar. The member section nav is the nav.

### 4.3 Avatar dropdown = identity only

Contents: name/email header, **Settings** (→ 4.5), Sign out. Everything else
comes out — the section nav carries the apps. Same on the admin side: the
avatar keeps account + sign out, the nine-dots launcher keeps cross-area
jumps, and the two stop overlapping (Home / My Profile leave the avatar).

Mechanically: the dropdown stops rendering the full `user_dropdown` list and
renders a fixed identity set; the `user_dropdown` location remains the source
for the section nav and app nav.

### 4.4 Member section nav = major sections only

Target: **Dashboard · Email · Calendar · Drive · Passwords · AI · Filtering ·
Events** — each gated by its plugin/setting as today. Demotions:

- **AI Memory** → a tab inside AI.
- **Devices** → inside Filtering (one entry, internal tabs). Kills the naming collision.
- **Orders / Subscriptions / Billing / My Events** → dashboard cards (the `ProfileDashboardRegistry` sections already exist) + a Billing section in the Settings hub. Whether Events stays top-level is decision B.

Mechanism: `amu_admin_menus` already supports parent/child for the sidebar;
`profileMenu` rows just never used `parent`. Extending `profileMenu` with
`parent` (and teaching `member_subnav_items()` / the app-nav endpoint about
one level of children) gives grouping with existing machinery — no new tables.
Plugins declare their own hierarchy in `plugin.json` as today.

### 4.5 New: member Settings hub (`/profile/settings`) — pending decision C

One left-rail settings page absorbing the account cluster and re-homing the
orphans:

- **Account** — name, email, photos (`account_edit`)
- **Password** (`password_edit`)
- **Address / Phone** (currently suppressed tabs)
- **Contact preferences** (`contact_preferences`)
- **Security** (`security`, unchanged content)
- **Notifications** — new member page; the per-user notification preferences
  pattern already exists as `adm/admin_notification_preferences.php`
- **Billing** — payment methods, tier (`/profile/billing`, `/profile/change-tier`)
- **Per-app sections**, contributed by plugins the way dashboard sections are:
  - **Mail** — signature, forwarding, connected accounts (IMAP), filters,
    **import** (the Actions-menu shortcut stays as a convenience, not the only door)
  - **AI** — default model/privacy prefs
  - **Calendar** — timezone/feeds when they exist

This one page replaces the tab strip duplicated across 7 logic files, gives
every orphan settings page a home, and is the destination the § 3 scope rule
needs. Which mailbox per-user config migrates member-side in this pass is
decision C.

### 4.6 One Actions component, one vocabulary

Standardize in-page action menus on `jy-actions-dropdown` (member and admin;
the admin Bootstrap `.dropdown` usages migrate opportunistically when
touched). One verb: "Import…".

### 4.7 Immediate fixes (no decisions needed)

- Dedupe avatar dropdown vs nine-dots (4.3).
- Drop the marketing menu from member pages (4.2).
- Collapse the 7 copies of the tab array into one helper (interim step until 4.5 replaces the strip).
- Admin Utilities: fix the three duplicate links; align menu perm with the page's perm 10.
- Fix the order-70 collision (Subscriptions vs Passwords).
- Link the orphans from wherever their section lands (conversations stays on the envelope icon; billing under Settings; bookings pages get profileMenu/section entries; Cloud Connect gets a member-reachable entry).
- Add the missing `search` launcher icon.

## 5. Out of scope

- Admin left sidebar structure (healthy).
- Mobile app navigation changes beyond inheriting the same `user_dropdown` rows (the `nativeScreen` mapping is untouched; demoted rows disappear from app nav the same way they disappear from the web nav).
- Public-site theme menus (`pmu_public_menus` unchanged, just no longer rendered on member pages).
- Mailbox Setup-tab topology work (member-visible setup messaging is worth doing but is its own spec).

## 6. Decisions (defaults taken at build time; each is cheap to reverse)

- **A. Quickbar:** replaced by a floating perm >= 5 "Admin" chip (bottom-left) on public-site pages only — member pages have the header Admin button, admin pages are the destination. The `show_admin_bar` setting still disables it.
- **B. Top-nav final list:** Events stays top-level (demoting it later is one plugin.json edit). Bookings also gained a top-level entry (gated `bookings_active`) since it previously had no nav at all.
- **C. Settings hub scope:** built in this pass. Mailbox per-user config (IMAP connect, filters, forwarding) stays admin-side; the hub's Mail Import section covers the import flow. Migrating the rest member-side is a follow-up spec.

## 7. Implementation order (once decisions land)

1. Immediate fixes (4.7) — independent of everything else.
2. Header + avatar + quickbar changes (4.1–4.3).
3. `profileMenu` parent support + section nav regrouping (4.4).
4. Settings hub (4.5), then per-app settings sections.
5. Actions-menu standardization (4.6) — opportunistic, ongoing.

Docs to update as each phase lands: `docs/routing.md` (member-area chrome),
`docs/plugin_developer_guide.md` (`profileMenu` parent + settings sections),
`docs/admin_pages.md` (quickbar removal), plus a new settings-hub section in
`docs/settings.md`.
