# Email Lists Plugin — Spec

**Status:** Draft 1, 2026-10-27. Open questions at the bottom (Q1–Q4) need the
owner before build.

## What this is for

Three outcomes, in plain terms:

1. **Bulk email leaves core.** Mailing lists, newsletter signups, and
   "email everyone in this group/event" become one plugin. Core keeps sending
   (password resets, receipts, notifications) and nothing else about lists.
2. **Lists need your own sending account.** A site that sends through the
   included Joinery email (the hosted SMTP2GO allowance, 2,000 sends a month)
   can build lists and collect signups, but cannot send a campaign until it
   connects its own provider. One list send to 3,000 people would otherwise use
   up the month and stop the site's password resets.
3. **It is a paid plugin.** Licensed `Joinery-Commercial` with
   `requires_entitlement: true`, the way `boards` and `forum` are
   (`plugins/boards/plugin.json:6-8`).

The larger aim behind (1): core should hold only what every site needs, and a
feature should plug in through the same extension points a third-party author
would use. Every place this plugin needs to reach into a core page becomes a
general extension point (§4), not a special case for this plugin.

## 1. Shape

- Plugin directory: `email_lists` (name is Q4). It owns `/email_lists/*`,
  `/profile/email_lists/*`, `/admin/email_lists/*`.
- The tables keep their names (`mlt_`, `mlr_`, `eml_`, `erc_`, `erg_`). The
  plugin's data classes declare them; the plugin takes them over on activation.
- Ships in every core archive like every bundled plugin; inactive until
  activated (or auto-activated on upgrade where the site already uses lists, §6).

## 2. What moves into the plugin

| Piece | Files today |
|---|---|
| Models | `data/mailing_lists_class.php`, `data/mailing_list_registrants_class.php`, `data/emails_class.php`, `data/email_recipients_class.php`, `data/email_recipient_groups_class.php` |
| List-provider sync (MailChimp) | `includes/MailingListService.php`, `includes/mailing_list_providers/*` (4 files), `utils/mailing_list_synchronize.php` |
| Audiences ("who gets this email") | `includes/RecipientGroupProviderRegistry.php`, `includes/recipient_group_providers/*` (group, mailing_list, user) |
| Admin: campaigns | `adm/admin_emails.php`, `admin_email.php`, `admin_email_edit.php`, `admin_email_preview.php`, `admin_email_view.php`, `admin_email_recipients_modify.php`, `admin_emails_queue.php`, `admin_emails_send.php`, and their `adm/logic/` files |
| Admin: lists | `adm/admin_mailing_lists.php`, `admin_mailing_list.php`, `admin_mailing_list_edit.php`, and their `adm/logic/` files |
| Admin: group/event send | `adm/admin_users_message.php` + `adm/logic/admin_users_message_logic.php` (the group and event cases; the one-person case is Q2) |
| Public pages | `views/lists.php`, `views/list.php`, `logic/lists_logic.php`, `logic/list_logic.php` |
| Member preferences | `views/profile/contact_preferences.php`, `logic/contact_preferences_logic.php` — the page is lists only (`contact_preferences_logic.php:52-79`) |
| Signup component | `views/components/list_signup.{php,json}`, `logic/components/list_signup_logic.php` |
| Campaign sending | `tasks/SendQueuedEmails.php` pass 1 (`:21-35`) becomes the plugin's own task |
| Signal | `newsletter.signup` (`signals.json:22`) moves to the plugin's declared signals |
| Store requirement | `plugins/store/includes/requirements/NewsletterSignupRequirement.php` (registered by this plugin when the store is active, §4 G7) |
| Settings | `emails_active`, `mailing_lists_active`, `mailing_list_provider`, `mailchimp_*`, `default_mailing_list`, `bulk_footer`, `bulk_outer_template`, `group_email_inner_template`, `group_email_footer_template`, `individual_email_inner_template` move from `settings.json` to `plugin.json`. Each one's readers are re-checked at build; one still read by core stays. `newsletter_active` and `mailchimp_list_id` are marked DEAD (`settings.json:385`, `:1723`) and are deleted, not moved. |
| Menus | `emails`, `emails-list`, `mailing-lists` move from `admin_menus.json` to the plugin's `adminMenu`. `email-templates` stays core and needs a new parent, since its parent `emails` leaves. |
| Tests | `tests/functional/mailing_list_signup_test.php`, `tests/email/email_queue_test.php`, `tests/email/admin_users_message_test.php`, `plugins/event_manager/tests/event_group_send_test.php` move to `plugins/email_lists/tests/` |

## 3. What stays in core

- **Sending:** `EmailSender`, the providers in `includes/email_providers/`,
  `EmailMessage`, templates (`emt_`), `EmailTemplateRenderer`.
- **The transactional retry queue** (`QueuedEmail`, `equ_`). It shares the word
  "queued" with campaigns but it is how resets and notifications retry
  (`includes/EmailSender.php:950-958`, `includes/Notify.php:437-445`,
  `plugins/mailbox/includes/ForwardConfirmation.php:204`). Passes 2 and 3 of
  `tasks/SendQueuedEmails.php` stay; its description stops mentioning bulk mail.
- **Contact types and notification preferences** (`ctt_`, `/profile/notification_preferences`).
- **Email traffic statistics** (`adm/admin_analytics_email_stats.php`). It
  reports page views by UTM tag (`:70-80`), which any email can carry; it does not
  read campaign tables.
- **The list-signup conversion type** `VisitorEvent::TYPE_LIST_SIGNUP`
  (`data/visitor_events_class.php:35`). Attribution reports count it generically;
  the plugin is what writes it.

## 4. Extension points this needs (general, for any plugin author)

Each of these is a gap a third-party plugin would hit too. Where one already
exists, the plugin just uses it.

| # | Core page / moment | Today | Extension point |
|---|---|---|---|
| G1 | Member profile page "Mailing Lists" card | hard-coded in `views/profile/profile.php:289-299`, data from `logic/profile_logic.php:135-146`; same in `logic/profile_dashboard_logic.php:108-117` | **Exists:** `ProfileDashboardRegistry`. The plugin registers a section; the card and both logic blocks are deleted. `plugins/dns_filtering` has its own copy (`logic/profile_logic.php:101-113`, `views/profile/profile.php:87-91`) and renders registry sections instead. |
| G2 | Admin user detail: subscribed lists, emails received/sent | inline in `adm/logic/admin_user_logic.php:283-323` and `adm/admin_user.php:177-183, 470-540` | **Exists:** `AdminUserPanelRegistry` (render + own POST actions). The plugin contributes one panel. |
| G3 | Admin user edit: list checkboxes | inline in `adm/logic/admin_users_edit_logic.php:38-42, 151-163`, `adm/admin_users_edit.php:44-47` | Moves into the G2 panel as a panel action. No new point. |
| G4 | Group page and event page: "emails to this audience" + "Email group" button | `adm/admin_group_members.php:35, 74-85`, `plugins/event_manager/admin/logic/admin_event_logic.php:206-226`, `admin_event.php:492, 563, 668, 679`, `includes/AdminPage.php:287-317` (`email_audience_table`) | **New:** generalize `AdminUserPanelRegistry` into a registry keyed by entity type (`user`, `group`, `event`, any a plugin names). The user page keeps working as the `user` case. `email_audience_table` moves into the plugin's panel. |
| G5 | Registration form: "add me to the mailing list" | field in `logic/register_logic.php:271-276`, action in `data/users_class.php:627-632`; same in `adm/admin_user_add.php:91-95` | **New:** a signup-field registry. A plugin contributes a field to the register form (and the admin add-user form), and a handler that runs with the new user and the submitted values after the account is created. `account.signup` (`register_logic.php:163`) carries no form input, so a signal subscriber cannot do this. |
| G6 | Member's list preferences page, linked from every email footer | `/profile/contact_preferences`, linked from the `default_footer` template and `admin_menus.json:508`; the hash link (`contact_preferences_logic.php:15-22`) is the unsubscribe path | **New:** core keeps the URL `/profile/contact_preferences` as a host page that plugins contribute preference sections to (same shape as G1). Links already in people's inboxes keep working. With no contributor, it says there is nothing to change and links to notification preferences. |
| G7 | Checkout "Keep me updated by email" | `NewsletterSignupRequirement` registered by the store (`plugins/store/includes/requirements/AbstractProductRequirement.php:212`) | **Exists:** `AbstractProductRequirement::register()` is public. The plugin registers its requirement when the store is active (`class_exists('AbstractProductRequirement')`). Existing products that use it keep the same requirement key. |
| G8 | Scheduled-task config field "pick a mailing list" | field type `mailing_list` hard-coded in `adm/admin_scheduled_tasks.php:347, 443` with data loaded at `adm/logic/admin_scheduled_tasks_logic.php:152-154` | **New:** task config fields accept `"type": "select", "options_from": "Class::method"`, the way settings do (`settings.json:1248`). `mailing_list` stops being a core type. |
| G9 | A task that needs another plugin | `plugins/event_manager/tasks/WeeklyEventsDigest.php` mails a list (`:36-57`) | **New:** a task's JSON may declare `"requires_plugins": ["email_lists"]`. `ScheduledTaskRegistry::discover()` leaves it out while that plugin is inactive. |
| G10 | Components shipped by a plugin | only `views/components/` and the active theme are scanned (`includes/ThemeManager.php:560-566`) | **New:** component discovery also scans active plugins' `views/components/`. `list_signup` moves into the plugin. `com_requires_plugin` (`includes/ComponentRenderer.php:160-165`) already hides a placed component whose plugin is off. |
| G11 | Deleting a user removes them from lists and tells MailChimp | explicit loop in `User::permanent_delete()` (`data/users_class.php:1553-1567`) | **Exists:** deletion rules. `mlr_usr_user_id` changes from `cascade` (`data/mailing_list_registrants_class.php:33`) to `permanent_delete`, and `MailingListRegistrant::permanent_delete()` does the provider unsubscribe. The loop in `User` is deleted. |
| G12 | SEO entity, photo entity | `data/seo_page_metadata_class.php:80`, `includes/EntityPhotoRegistry.php:42` | **Exist:** both registries take registrations from plugins (store and event_manager already do). |
| G13 | Audiences contributed by other plugins | event_manager registers `event` and `event_waiting_list` audiences unconditionally (`plugins/event_manager/serve.php:42-45`) | event_manager registers them only when `class_exists('RecipientGroupProviderRegistry')`. That check is truthful because an inactive plugin's classes do not resolve. |
| G14 | Settings page section for the list provider | inline in `adm/admin_settings_email.php:85-110` | **Exists:** the plugin's settings tab (`settingsMenu`, `docs/plugin_developer_guide.md` § Plugin Settings). |
| G15 | Event waiting list "add me to the newsletter" | `plugins/event_manager/logic/event_waiting_list_logic.php:93`, hand-written checkbox in `views/event_waiting_list.php:105-106` | Shown only when `class_exists('MailingList')`, and rewritten with FormWriter. |

Routes: core `serve.php` keeps `'/list/{slug}'` (`serve.php:145`) and gains
`'/lists'`, both with `'plugin' => 'email_lists'`. Plugins cannot own
top-level dynamic routes (`plugins/event_manager/serve.php:9`); `/product/{slug}`
is the precedent (`serve.php:144`).

Columns: `usr_mailing_list_provider_id` (`data/users_class.php:184`) is written
(`data/mailing_lists_class.php:241`) and never read, and is empty on dev. It
leaves `User`'s field list; the next `--cleanup` run drops it.

## 5. The included-mail check

### Knowing a site is on included mail

There are two ways a site gets included mail, and today only one of them can be
told apart:

- A self-hosted site connected to getjoinery: `email_service` is
  `joinery_services` (`includes/ServicesClient.php:25, 252`).
- A Managed site: `email_service` is plain `smtp` pointed at SMTP2GO
  (`utils/hosted_mail_settings.php:110`). A customer using their **own** SMTP2GO
  account looks the same by host.

Both paths write their settings through `HostedMailSettingsMap::apply()`
(`includes/HostedMailSettingsMap.php:1-20`). So:

- `apply()` also records the identity it wrote: a managed setting
  `included_mail_identity` = `host|username`, blank when the service is turned
  off.
- Core gains `EmailSender::usesIncludedMail(): bool`. It returns true when
  `email_service` is `joinery_services`, or when it is `smtp` and the current
  `smtp_host|smtp_username` equals `included_mail_identity`.
- This needs no extra upkeep. When the owner changes the SMTP settings to their
  own account, the comparison stops matching, so no flag has to be cleared.
- Managed sites provisioned before this change have no identity recorded and
  read as not included until their next settings push. There are no production
  hosted customers yet. Build step: confirm that, and re-push the test boxes.

### Where it refuses

- **Queueing a campaign or group send:** refused with "Connect your own email
  provider to send to a list. Included email is for your everyday mail." It
  links to `/admin/admin_settings_email`. The compose page shows the same
  message as a banner before anyone writes the email.
- **At send time,** in the plugin's send task: a campaign queued before the
  site moved back to included mail is held, not sent, and its status says why.
- **Allowed:** building lists, signups, the public pages, MailChimp sync (sends
  through MailChimp happen on the customer's own MailChimp account), and a test
  send to yourself (one recipient).

The rule lives in the plugin. Core only answers `usesIncludedMail()`.

## 6. Upgrade path for sites that already use lists

Precedent: the store and event_manager extraction auto-activated those plugins
where data existed (`utils/update_database.php:1089-1147`, marker
`_store_event_autoactivate_v1`). Its assumptions, checked against this case:

- **Runs after plugin sync, not as a numbered migration.** The same holds here:
  migrations run before plugin sync.
- **Tables already exist.** True here: core owned them until now. Activation's
  table sync is additive only.
- **One-time marker, so an admin who later turns the plugin off is not
  overridden.** Kept, with marker `_email_lists_autoactivate_v1`.
- **Condition:** any row in `mlt_mailing_lists` or `eml_emails`, each guarded
  with `to_regclass`. Dev has 5 lists, 2,474 subscribers and 574 emails.
- **Requirement gates.** `requires_entitlement` does not block activation
  (`docs/plugin_developer_guide.md` § Licensing), so auto-activation works on a
  site that has not bought it. Whether existing sites are grandfathered is Q3.

Core migrations that name these tables (`migrations/migrations.php:714-718`,
`:951`, `migrations/generalize_erg_recipient_provider.php`,
`migrations/migrate_entity_photos.php:18`) gain `to_regclass` and column guards.
On a new install without the plugin, those tables never exist.

The harness reclaim list (`tests/lib/harness.php:946-947`) names `Email` and
`MailingList`. Those entries move to the plugin's test setup or are guarded.

## 7. Bugs found while mapping this

Fixed in the working tree on 2026-10-27, uncommitted:

- **B1** A failed campaign send fataled: `EmailRecipient::ERROR` does not exist
  (`adm/admin_emails_send.php:138`; constants are at
  `data/email_recipients_class.php:26-28`). The campaign stopped at the first
  failed recipient and stopped there again on every run. Now `SEND_FAILURE`.
- **B2** Checkout's "Keep me updated by email" did nothing:
  `NewsletterSignupRequirement::post_purchase()` was an empty stub. It now
  subscribes the buyer to the default list. It is scoped so the buyer's other
  lists are left alone, because `add_user_to_mailing_lists()` unsubscribes
  from every list it is not given (`data/users_class.php:245-251`).
- **B3** Registration and admin add-user showed "add me to the mailing list"
  even with no default list set, and ticking it did nothing. The box is now
  shown only when `default_mailing_list` is set.
- **B4** "All lists" as the default list tried to subscribe new members to
  deleted and inactive lists, and `add_registrant()` throws on an inactive one.
  That failure would surface during registration. It now skips both.
- **B5** The SEO page linked mailing lists to `/admin/admin_list_edit`, which does
  not exist. Now `/admin/admin_mailing_list_edit`.

Open, folded into this build:

- **B6** A campaign never re-checks unsubscribes at send time. The check is
  commented out (`adm/admin_emails_send.php:86-95`). Someone who unsubscribes
  between queueing and sending still gets the email. The plugin's send task
  re-checks list membership and contact-type unsubscribes per recipient.
- **B7** The campaign send engine is an admin page that the task `require`s with
  a `SCHEDULED_TASK_CONTEXT` constant (`tasks/SendQueuedEmails.php:26-31`). In
  the plugin it becomes a class that the task and the admin page both call.

## 8. Docs

- New `plugins/email_lists/docs/overview.md`.
- `docs/plugin_developer_guide.md`:
  - § Mailing list providers (`:1750`) moves into the plugin doc.
  - New sections for G4, G5, G6, G8, G9 and G10.
- `docs/email_system.md` loses campaign material and gains `usesIncludedMail()`.
- `plugins/server_manager/docs/hosted_tier.md` states the rule (`:117` already says
  bulk mail is for the customer's own account).
- CLAUDE.md index gets the plugin, through the agent-files admin page, not on disk.

## 9. Build order

1. Extension points G4, G5, G6, G8, G9 and G10 in core, each with its own test,
   used by nothing new yet.
2. `usesIncludedMail()` + `included_mail_identity` in `HostedMailSettingsMap`.
   Coordinate with whoever holds the uncommitted hosted-mail work
   (`utils/hosted_mail_settings.php` and friends were modified at the start of
   this session).
3. Create the plugin and move the files, settings, menus, tests and signal.
4. Rewire every core touch point in §4 and delete the core copies.
5. Upgrade auto-activation (§6) and migration guards.
6. Included-mail refusal (§5).
7. Gate: `php tests/run.php db --changed`, then an upgrade rehearsal on a test
   box with lists data (memory: PHP 8.5 boxes show warnings dev does not).

## Open questions

- **Q1. Do "Email registrants" and "Email group" go with the paid plugin?**
  - **Yes:** they are campaigns in every technical sense: the same records and
    the same allowance risk. But emailing your event's registrants becomes a
    paid feature.
  - **No:** core keeps a small audience send. Core then keeps the campaign
    models, which is most of what this spec moves.
  - **Recommendation: yes.** Without them the plugin is half a feature and core
    keeps the hard part.
- **Q2. "Send email to user" (one person, from the admin user page,
  `adm/logic/admin_user_logic.php:244`):**
  - **Stays core:** sent directly through `EmailSender`, which already queues a
    failure for retry. The catch is that the user page no longer lists the
    one-to-one emails a member was sent, because no per-message history is
    kept outside campaigns (`data/debug_email_logs_class.php:1-8`).
  - **Moves to the plugin:** keeps the history, but a site without the plugin
    cannot email one member from admin.
  - **Recommendation: stays core.** Emailing one member is account support, not
    bulk mail.
- **Q3. Existing sites already using lists:** auto-activate free (grandfather),
  or auto-activate and ask them to buy? Today no production sites use it
  (memory: no production users yet), so grandfathering costs nothing.
  **Recommendation: grandfather.**
- **Q4. Plugin name.** It is permanent and appears in URLs (`/email_lists/...`).
  The candidates are `email_lists` and `newsletters`. **Recommendation:
  `email_lists`.** "Newsletter" undersells group and event sends.
