# Data Portability: Member Data, Admin Lists and Backups

**Status:** Deferred (owner, 2026-10-22: "I don't want to deal with any of this right now"). Nothing is built.

This was split out of the first draft of `export_mail_contacts_calendar_drive.md`, which keeps email, contacts, calendar and Drive. Six decisions were already made here before the split (see **Decided**). Four questions are open.

## The problem in plain terms

- **A member can't take their data with them, and can't close their own account.** Privacy law in most places gives people both rights.
- **An admin can't export anything they see in a list:** members, subscribers, orders, registrants or survey answers.
- **A group moving onto Joinery can't bring its members list.**
- **A backup can't be downloaded from the browser, and a site can't be restored from a backup file.** Restore works only from the site's own backup storage, through Server Manager or a root shell.

## What exists today

| Area | In | Out |
|---|---|---|
| Members | None. | None. |
| Mailing list subscribers | Mailchimp sync only. | Mailchimp sync only. |
| Store, events, surveys | — | None. |
| Site backup | — | Encrypted chains in the backup bucket. No browser download. |
| Site restore | From its own bucket via Server Manager, or root shell scripts. Site copy between servers. | — |
| Account deletion | — | Admin only (`adm/admin_users_permanent_delete.php`, perm 10). |
| Programmatic | REST API v1 create/update. | REST API v1 JSON reads, 200 rows a page. |

## Principles

The principles of `export_mail_contacts_calendar_drive.md` apply here too: standard formats, round trips, encryption levels, background jobs, and the CSV formula rule. This spec adds three:

**D1. Self-contained pieces.** Each area (core and each plugin) supplies its own part of a member's download by registering one section from its bootstrap, the way `ContentReportRegistry` works. The download code knows nothing about any plugin. Closing an account needs no new mechanism, because `User::permanent_delete()` and the deletion rules already handle every table.

**D2. Dangerous actions need a fresh confirmation.** A member's full download, closing an account, backup download and restore all require a fresh second factor (`SessionControl::require_recent_second_factor` / `step_up_outstanding`). For accounts with no second factor, step-up does nothing today (`account_security.md`). A password re-confirm for those accounts has to be designed when this is picked up.

**D3. Secrets never leave in an export.** CSV exports use the same field rules as the API: `SystemBase::is_unreadable_field()`, `CREDENTIAL_FIELD_PATTERN` and `$api_unreadable_fields`.

### What this spec does not do

- **No new backup format.** A backup download hands over the existing encrypted files exactly as they are.
- **No posts and pages import or export, and no exports for boards or mail filters** (owner, 2026-10-21: "we're not there yet"). No importers for other platforms' whole sites.

## Work packages

### WP1. "Download my data" for members

**What the member gets.** A new page, `/profile/data`, appears as **Your data** in the settings menu (`admin_menus.json` `settingsMenu`, after Security).

- It has a **Prepare my download** button, which asks for a fresh second factor (D2) and then queues the job.
- When the file is ready the member gets an email.
- The page shows a **Download** button for `export_retention_days` (default 7), after which the file is deleted.

**What's in the zip:**

- `README.txt`: what each file is, and what was left out and why.
- `account.json`: profile, addresses, phone numbers, preferences, tier history, group memberships.
- One file or folder per registered section, in the section's own standard format. Examples: `orders.csv`, `event_registrations.csv`, `mailing_lists.csv`, `calendar.ics`, `contacts.vcf`, `forum_posts.json`, `messages.json`, `ai_chats/*.md`.
- Mail and Drive files are not bundled (Q2). `README.txt` and the page link to their own downloads in `export_mail_contacts_calendar_drive.md`.

**How sections plug in.** A new core interface, `DataExportSection`:

- `key()`, `title()`
- `level()`: standard, private or fortress
- `estimate(User)`: row count and bytes, so the page can show a size
- `write(User, ExportSink, deadline)`: resumable, so a slice can stop at the deadline and pick up later

Plugins call `DataExportRegistry::register(new XSection())` from their bootstrap. Core registers its own sections the same way.

**Fortress sections** are not built by the server. `README.txt` names each one and links to that area's own browser export.

**Coverage test.** A test lists every table with a user-owner column:

- the `*_usr_user_id` columns (106 tables on 2026-10-21)
- plus the owner columns that don't follow that naming: `*_owner_user_id`, `msg_usr_user_id_sender`/`_recipient`, `evt_usr_user_id_leader`, `bkn_usr_user_id_client`/`_booked`, `bbd_usr_user_id_owner`, `grp_usr_user_id_created`, `sfq_author_user_id` and `*_sealed_owner_user_id`.

It fails if a table is neither covered by a section nor named in an exclusion list with a reason (logs, caches, security records). This keeps the download complete as new tables appear.

**Storage.** The file is written as a private File under `storage/`. If any part came from Private data, the whole file is sealed. It is served through the signed-URL path (`File::mintSignedUrl`) and deleted by `RetentionSweep`.

### WP2. "Close my account" for members

**What the member gets.** On the same `/profile/data` page, a **Close my account** section:

1. A plain summary of what happens, built from `permanent_delete_dry_run()`: "Your mail, files, calendar and contacts are deleted. Your orders and forum posts stay, shown as 'Deleted user'."
2. A link to download their data first (WP1).
3. A fresh second factor (D2), then a typed confirmation.

**What happens: closed now, deleted after 30 days (Q3).**

1. **Closing.** The account is soft-deleted (`usr_delete_time`), which already refuses sign-in and ends open sessions at their next request (`SessionControl` :1231). A new column, `usr_closed_time`, records that the member asked for it, so a member's closing is told apart from an admin's soft delete, which never purges on its own.
2. **Confirmation email.** It goes to the closed address: "Your account is closed and will be deleted on {date}. Contact us before then to undo it."
3. **During the 30 days:**
   - Mail addressed to them is still delivered and stored. It is deleted with everything else at the end, and nothing is lost if the closing is undone.
   - Their address stays taken, so nobody else can sign up with it.
   - An admin can undo with the existing **Undelete**, which also clears `usr_closed_time`.
4. **Deletion.** A new scheduled task, `DeleteClosedAccounts` (daily), runs the existing `User::permanent_delete()` for each account whose `usr_closed_time` is older than `account_close_grace_days` (a new setting, default 30). That is one transaction, the same path an admin uses.
5. **If deletion is refused.** The blockers below are checked again at this point, since things can change during the window. If one stops it, the account stays closed and an admin notice names the account and the blocker.

**What blocks it.** Each blocker is shown as a plain sentence with what to do about it:

- The member is the only member of a protected mailbox (`inbound_email_mailbox_grants_class.php:332`).
- They own a live scoped machine key (`User::soft_delete`, :1241).
- `prevent` rules apply (cloud provisions, registered domains).
- A paid subscription is still active. It has to be cancelled first, so Stripe stops charging.
- The account is an admin, permission 5 or higher (Q4). The **Close my account** section is replaced by: "Staff accounts are closed by another admin. Ask one to change your account to a member account, then you can close it here." This also means no site can lose its last superadmin this way.

**Setting.** `member_self_delete_enabled`, default on.

### WP3. CSV export from admin lists

**The facts:**

- There is no single place to add an export button. `tableheader()`/`disprow()` (includes/PublicPageBase.php) only draw HTML.
- Each page's logic builds its own `Multi*` query (`admin_users_logic.php:15-75`, `admin_orders.php:18-59` inline, and so on).
- `docs/admin_pages.md:480` already names the intended pattern: a `?action=export` branch in the logic, with the button in `header_action`.

**Design:**

- **Core `CsvWriter`** (built with the export spec's WP2): RFC 4180 records, the formula rule, and a BOM so Excel reads UTF-8 correctly. This package adds:
  - D3.
  - Streaming a `Multi*` collection in batches of 500. Nothing is written to a temporary file.
- **Core `CsvReader`** (built with the export spec's WP2). Parses whole records, so a quoted newline doesn't break a row. Used by WP4.
- **Each page in scope:**
  - Move its criteria-building into one function that both the list and the export call, so the export holds exactly the rows the admin filtered to, without paging.
  - Declare its export columns as human-readable headers and values. Times are UTC ISO 8601.
  - Add an **Export CSV** button in `header_action`, gated by the page's own view permission.
- **Cleanup:** delete `data/admin_tableexport_data.php` and `data/admin_tableexport_form_data.php` (B1).

**Pages in scope (Q5, open).** 82 admin pages draw a table. The proposed 13:

| Area | Lists |
|---|---|
| People | Members (`admin_users`) · Group members · Mailing list members |
| Store | Orders · Products · Coupons (needs an `$api_readable` declaration first) · Product ownership · Yearly donations report · Stripe invoices |
| Events | Events · Event registrants and waiting list (on each event's page, prefixed pager `r`) · Bookings |
| Surveys | Survey answers |

Left out: settings and system pages, posts and pages, forum moderation queues and support threads, and the analytics charts.

### WP4. Import members and subscribers from CSV

**What the admin gets.** `/admin/admin_users_import`:

1. Upload a CSV.
2. Map columns to fields. Known headers from common platforms' member exports (`email`, `first_name`, `name`, `subscribed`, `labels`, `tier` and so on) are mapped automatically; the rest are chosen from a list.
3. A preview table with a status per row: new, already a member, or invalid (with the reason).
4. Confirm. Small files run in the request; large ones are queued.
5. A results page and a log of the run.

The preview flow copies the mail-filter import (`mailbox_filters_logic.php:116-176`, server-rendered).

**Options on the confirm step:**

- Add everyone to mailing list(s) X and group(s) Y.
- Give them tier Z (`SubscriptionTier::addUser`, which only upgrades).
- **Invite them.** Off by default. When on, each person gets a "set your password" email (`Activation::email_forgotpw_send`, valid 30 days).

**Subscribers are members.** A list subscriber must be a User (`mlr_usr_user_id` is required), so one importer covers both cases. "Import subscribers to list X" is the same page with the list already chosen.

**Consent.** Adding people to a mailing list requires the admin to tick "These people agreed to receive email from us". The tick, the admin and the time are recorded in the run log (Q7).

**Quiet subscribe.** `MailingList::add_registrant()` sends the list's welcome email and syncs to the outside provider. The import needs a quiet mode with no welcome email. The provider sync still happens.

**Existing members.** A row whose email already exists is matched to that member. What gets updated is Q6.

**Depends on B3:** a person must be creatable with only an email address.

### WP5. Download a backup from the browser

**What the admin gets.** On `/admin/admin_backups`, each run in the history gets **Download**. It asks for a fresh second factor and needs permission 10.

It shows every file needed to restore that run:

- every link of the chain up to and including that run (data, code, db and meta archives, and each manifest)
- with short-lived signed links straight to the bucket (`S3Signer::presign_get()`)
- with the sha256 of each file
- plus a `RESTORE.txt` saying what is needed: the files, the recovery key, and WP6 or `restore_chain.sh`.

**Why links straight to the bucket.** The files are already encrypted, so a signed link only ever exposes ciphertext, and the site never relays gigabytes through PHP.

**Offloaded files.** These live under `objects/`, not in the archives. They are listed the same way when the run covers them.

This covers the never-built `backups/fetch` from `management_api.md` (B2).

### WP6. Restore from a backup file, or from the site's own storage

**Who this is for.** An admin of a site with no management node, or a self-hosted owner, who wants to restore without a root shell. There are two sources:

- **Upload:** a backup set downloaded with WP5, or taken on another server.
- **This site's own bucket:** today only Server Manager can start this.

**Flow:**

1. **Choose the source.**
   - Uploads use the existing chunked transport (`DriveUploadTransport`) with a new upload purpose, `backup_restore` (`UploadPurposeRegistry`). Pieces land in `storage/drive_uploads/`.
   - A bucket source is staged by `stage_chain`.
2. **Approve with the recovery key in the browser,** using the existing `ApprovalChallenge` `RESTORE` flow. A chain from another server uses `openKeyBox` / `--adopt-secret-key`, as site copy does. This is the proof of authority: a request the web user wrote cannot restore anything, because the chain can't be opened without the recovery key.
3. **A safety backup of the current site runs first** (Q8).
4. **A new root request kind, `restore_chain {staging_id}`, runs `restore_chain.sh --artifacts DIR`.**
   - Its handler reads only the staging id.
   - The directory, manifest and expected sha256 values come from the root-owned staging record, never from the request. This is the same reasoning as `run_plugin_installer` in `plugin_installer_from_the_browser.md`.
5. **The existing root-request panel shows progress and the transcript.**

**Firm line.** This is for a site restoring itself. Moving a site between servers stays with site copy (`site_copy.md`, `site_copy_anything_to_anything.md`).

## Bugs found while scoping

- **B1.** `data/admin_tableexport_data.php` is an orphan table-to-CSV dumper.
  - It does a raw `SELECT *`, has no permission check of its own, and deletes everything in the exports folder each time it runs.
  - Nothing links to it, and it returns 404 on dev, so it is dead code rather than a hole.
  - **Fixed by WP3:** delete it and its form file.
- **B2.** `management_api.md` lists `backups/fetch` as built; it never was (`includes/management_api/backups/` has only `list_handler.php`). Implemented specs aren't edited. **Covered by WP5.**
- **B3.** The compact newsletter form fails for a one-word address such as `bob@x.com`. `User::guessNameFromEmail()` returns an empty last name (`users_class.php:380`), and `User::CreateNew()` throws "Missing last name" (:388). This is live today, so it is tracked on its own to-do and not left waiting for this spec.

## Decided

- **Q1 (owner, 2026-10-22).** This spec is deferred. Email, contacts, calendar and Drive go ahead in `export_mail_contacts_calendar_drive.md`.
- **Q2 (owner, 2026-10-21).** Mail and Drive files are linked, not put in the member's one zip. Bundling them would make the file many gigabytes and slow to build, any failure would restart it, and Fortress parts couldn't go in anyway.
- **Q3 (owner, 2026-10-21).** A closed account is deleted after a 30-day waiting period, during which an admin can undo it. This protects against a mistaken click or a taken-over account. Mail arriving during the window is kept, not bounced.
- **Q4 (owner, 2026-10-21).** Admins (permission 5 or higher) can't close their own account. Another admin lowers them to member first, and then they can close it themselves. This keeps the removal of a staff account deliberate, since deletion re-labels their actions as "Deleted user".
- The member page is `/profile/data`, listed as **Your data** in the settings menu. This wasn't a real choice.

## Open questions

- **Q5.** Is the WP3 list of 13 pages right?
- **Q6.** When an imported row matches an existing member: skip them, fill only empty fields, or overwrite?
- **Q7.** Is a recorded consent tick enough for importing subscribers, or do imported people get a confirm-your-subscription email (double opt-in)?
- **Q8.** Should a restore always take a safety backup of the current site first, even though that adds time and storage?

## Files

**New:**

- includes/DataExportSection.php, DataExportRegistry.php, CsvWriter.php, CsvReader.php
- views/profile/data.php, logic/data_export_logic.php, logic/account_close_logic.php
- tasks/RunDataExports.php, tasks/DeleteClosedAccounts.php
- adm/admin_users_import.php, adm/logic/admin_users_import_logic.php

**Changed:**

- data/users_class.php (new `usr_closed_time`; `undelete()` clears it)
- data/mailing_lists_class.php (quiet `add_registrant`)
- includes/RootRequest.php plus the runner (`restore_chain`)
- adm/admin_backups.php and its logic
- each WP3 list page and its logic
- each plugin bootstrap that registers a WP1 section
- settings.json (`member_self_delete_enabled`, `account_close_grace_days`)
- admin_menus.json (**Your data** in `settingsMenu`)

**Deleted:** data/admin_tableexport_data.php, data/admin_tableexport_form_data.php.

## Docs

- `docs/admin_pages.md`: CSV export and import.
- `docs/plugin_developer_guide.md`: registering a `DataExportSection`.
- `docs/account_security.md`: closing an account.
- `docs/backups.md`: download and restore.

## Tests

- **WP1 coverage test:** every user-owned table is covered or excluded with a reason.
- **Round trip:** members CSV, exported and imported into an empty test site, compares equal.
- **Safety:**
  - Fields that are never readable are absent from every CSV.
  - A CSV cell beginning with `=` is defused.
- **Closing an account:**
  - Every blocker refuses with its sentence.
  - A closed account can't sign in, and its open sessions end.
  - An admin's soft delete is never purged by `DeleteClosedAccounts`.
  - Undelete within the window restores the account and clears `usr_closed_time`.
  - After the window, the account is gone and its orders and posts show as "Deleted user".
  - A blocker that appears during the window leaves the account closed and raises an admin notice.
- **Restore:**
  - The root request reads only the staging id.
  - A forged request with no recovery-key approval restores nothing.
  - The upload round-trips from a WP5 download.
