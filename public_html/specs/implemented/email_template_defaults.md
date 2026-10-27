# Email template defaults: every site gets its own, neutral account emails

**Status:** BUILT 2026-10-27. Q1 decided by the owner 10-27: A, templates in the repository.
Verified on dev: migration 225 replaced the seven Integral Zen rows, the seed and render tests
pass, and a dry run of the install builder wrote the 20 templates with no Integral Zen in the file.
The live checks (a fresh site after a release, WP2 after its upgrade, a welcome email) are in the
live verification queue under "email template defaults".

## The problem

Every new Joinery site, Managed customers included, sends account emails written for Integral
Zen. Found 10-27 (B167): dev's password reset arrived titled "Integral Zen Password Change Link",
and the WP2 test site (Managed order 38345, installed fresh from a release on 10-26) lists the same
templates in its admin.

What a member of a new site receives today:

| Email | Sent by | What it says |
|---|---|---|
| Password reset (`forgotpw_content`) | `includes/Activation.php:276`, `:296` | subject "Integral Zen Password Change Link"; "You have requested a password change on IntegralZen.org"; signed "IntegralZen" |
| Email verification (`activation_content`) | `includes/Activation.php:254` | subject "Integral Zen Verification Link"; "Thanks for signing up on Integral Zen!" |
| Welcome (`new_account_content`) | `data/users_class.php:523` | subject "Welcome to Integral Zen!"; "welcome you to our sangha"; links to integralzen.org events, courses and "Doshin's priests"; **the member's password in plain text** (B168, below) |
| Email change (`email_change_content`) | `includes/Activation.php:337` | subject "Integral Zen Email Change Verification"; signed "Integral Zen" |
| Group email footer (`event_bulk_footer`) | the `group_email_footer_template` default, `settings.json:1411` | signed "Integral Zen" |
| Outer wrapper on every email (`default_outer_template`) | `includes/EmailTemplateRenderer.php:65` | `<title>integralzen.org</title>`, `charset=ISO-8859-1` |
| "Standard HTML Template" for admin group emails (`newsletter-1`) | `adm/admin_email_edit.php:66` | Integral Zen logo, links to integralzen.org, its Twitter, Facebook and YouTube, "Copyright 2019 Integralzen, Inc" |

Four more Integral Zen templates ship but are soft-deleted and never sent:
`event_reciept_content`, `event_deposit_reciept_content`, `monthly_donation_reciept` and
`single_donation_reciept`. `migrations/migration_receipt_templates_unify.php` retired them, and the
install dump carries them with a `emt_delete_time`.

### Why it happens

`utils/create_install_sql.php` builds the release's `joinery-install.sql.gz` partly by dumping
tables from the database of the site that publishes (dev). `emt_email_templates` is one of those
tables (`$essential_tables`, `utils/create_install_sql.php:356-361`, dumped by `pg_dump --data-only`
at `:381-386`). Dev's rows are the 2020 originals (create times 2020-12-16 to 2020-12-19), written
for integralzen.org. So whatever is in dev's table is what every new site starts with, and nothing
in the repository says what a template should contain.

Newer templates did not have this problem. They ship as migrations that insert a row only if the
name is missing, with neutral text and `*site_name*`:
`migrations/migration_subscription_email_templates.php`,
`migrations/migration_receipt_templates_unify.php`, and server_manager's
`sm_009_instance_transfer_email_templates` (`plugins/server_manager/migrations/migrations.php:201-252`).

### A second gap: subjects never name the site

`EmailTemplateRenderer::fill_template()` (`includes/EmailTemplateRenderer.php:176-231`)
substitutes `*placeholders*` in the body only. The subject is read raw from `emt_subject`
(`:102`) and handed out unchanged by `getSubject()` (`:265`), `createMessage()` (`:300`) and
`EmailMessage::fromTemplate()` (`includes/EmailMessage.php:54`). So a neutral subject cannot say
"Reset your password for *site_name*" today. The other way round, the blank template's subject is
the literal `*subject*`, which only works because its callers pass a subject of their own
(`EmailSender::sendTemplate()` `$subject`, `includes/EmailSender.php:613-621`).

### B168: the welcome email sends the password

`User::CreateNew()` puts the password into the welcome email's values
(`data/users_class.php:467-482`): the one the member typed, or a generated one when there was
none. The shipped welcome template prints it ("Your password is: *password*", or "We have created
a password for you"). Public registration sends it (`logic/register_logic.php:154`,
`CreateCompleteNew(..., true, ...)`), and so does checkout for a new buyer
(`plugins/store/logic/cart_charge_logic.php:770`). The generated-password branch's own comment
says that password is "Not surfaced to the user — they reset via the forgot-password flow"
(`data/users_class.php:474-475`). The template surfaces it anyway.

## Q1 (decided 10-27: A): where a new site's templates come from

- **A. Templates in the repository (chosen).** One file per template under
  `public_html/email_templates/`, read by the install builder and by `update_database`. Every new
  site gets the same neutral text whatever happens to dev's database, and a change to a default is
  a reviewed commit. This is how core settings (`settings.json`, seeded by
  `utils/update_database.php:859-881`) and core admin menus (`admin_menus.json`, generated into the
  dump at `utils/create_install_sql.php:804-817` instead of dumped) already work. The catch: a new
  folder, a new seeding step, and the dump step changes.
- **B. Rewrite dev's rows in neutral wording.** A database edit on dev; the dump picks it up at
  the next release. The catch: the next edit to a template on dev changes every future site again,
  silently, and nothing records what the defaults are.

Owner, 10-27: "yes, lets keep the default templates in a folder".

## Design

### 1. The defaults live in `public_html/email_templates/`

- `email_templates.json` lists each template: `name`, `type` (1 outer, 2 inner, 3 footer, as in
  `emt_type`), `subject`, `file`.
- One `<name>.html` per template holds the body, so it can be read and reviewed as HTML.
- It lists every core template a site uses: the seven above, rewritten (section 4), and the core
  templates first shipped by core migrations, copied as they are because they are neutral already:
  `subscription_upgraded`, `subscription_downgraded`, `subscription_cancelled`,
  `subscription_reactivated`, `subscription_expired`, `subscription_payment_failed`
  (`migrations/migration_subscription_email_templates.php`), `purchase_receipt_default`,
  `purchase_receipt_product_default` (`migrations/migration_receipt_templates_unify.php`),
  `calendar_reminder`, `calendar_summary` (`migrations/migration_calendar_email_templates.php`,
  `migration_calendar_reminder_details.php`), and `blank_template`, `default_footer`,
  `blank_footer`. Those migrations cannot supply a new site: the dump marks every core migration
  up to 224 as already run (135 `mig_migrations` rows in the 0.8.539 dump), so on a new site they
  never run, and their rows reach it only through the dump today.
- Not listed, so a new site does not get them: the four retired receipt templates and
  `subscription_created` (soft-deleted by `migration_receipt_templates_unify.php`), and
  `mailing_list_subscribe` (no caller outside `tests/email/email_pattern_test.php`).
- From then on, a new core template is added to the folder, not shipped by a migration: the seed
  (section 3) adds it to every site, old and new. Changing the wording of an existing default
  takes both the file (new sites) and a migration (sites that already hold the row).
  `docs/email_system.md` says so.

Plugin templates (server_manager's instance transfer and services templates) stay with their
plugin's migrations. The dump carries no `plm_plugin_migrations` rows, so those run when the
plugin is activated on a new site, and they are neutral already.

### 2. New sites: the install builder writes them from the files

`utils/create_install_sql.php` removes `emt_email_templates` from `$essential_tables` and writes
one `INSERT` per listed template from the files, the way step 8.8 writes the admin menus. The
install's own `update_database` run (`maintenance_scripts/install_tools/_site_init.sh:786-787`)
then finds every listed template present, and plugin activation adds the plugin ones.

### 3. Every site: `update_database` adds a missing default, never changes one

A new step beside the core settings seed: for each listed template, insert it only when no row of
that name exists, live or soft-deleted. A soft-deleted row means an admin removed it, so it is
not brought back. An existing row is never overwritten: templates are edited by site admins
(`adm/admin_email_template_edit.php`), and their wording is theirs.

### 4. The neutral text

Written for any organisation, signed with `*site_name*`, linking only through `*web_dir*`:

- **Password reset:** subject "Reset your *site_name* password". The link, the 24-hour expiry,
  and a line saying that if you did not ask, you can ignore it.
- **Email verification:** subject "Confirm your email for *site_name*". The two variants it has
  today (first send and resend) stay.
- **Welcome:** subject "Welcome to *site_name*". A greeting and a link to the member's profile.
  **No password, in either branch.** A member who was given a generated password sets one through
  the reset link, as the code comment already intends.
- **Email change:** subject "Confirm your new email address for *site_name*".
- **Group email footer:** the unsubscribe/withdraw text it has today, signed `*site_name*`.
- **Outer wrapper:** `<title>*site_name*</title>` and `charset=UTF-8`. Everything is sent as UTF-8,
  so the declared ISO-8859-1 is wrong today as well as foreign.
- **Standard HTML template (`newsletter-1`):** a plain single-column layout: the site name as a
  heading, the body, the footer. No logo, social links or copyright line; a site adds its own by
  editing the template. The picker label stays "Standard HTML Template".

### 5. Subjects take placeholders

`fill_template()` runs the subject through `_substitute_variables()` with the same values as the
body, and stores the result in `email_subject`. A placeholder with no value renders empty, as in
the body (`_substitute_variables()`, `includes/EmailTemplateRenderer.php:391`). The blank template's
`*subject*` then renders as the `subject` value when one is given, and empty otherwise. Every
caller that uses it also passes its own subject, which still wins (`EmailSender::sendTemplate()`
sets it after the template, `includes/EmailSender.php:620-621`). The implementation lists each
`blank_template` caller and confirms this one by one before relying on it.

### 6. B168: the password is no longer a value the welcome email can print

`User::CreateNew()` stops putting `password` into `$email_fill`. `password_temporary` stays, so a
template can still say "set a password with the link below". A site whose own edited welcome
template still says `*password*` then renders it empty instead of mailing a credential. That is
deliberate: no template, shipped or edited, should be able to send a password.

### 7. Sites that already have the Integral Zen text

A core migration replaces a row only when its subject and body are still byte-for-byte the shipped
Integral Zen text, compared by a SHA-256 of each original kept in the migration. A row an admin
has changed in any way is left alone. The migration does nothing on Integral Zen's own site
(`site_name` matching `/integral\s*zen/i`, or a site address on integralzen.org), whose templates
are its own even where they are unchanged. Soft-deleted rows are not touched.

## Tests

- `tests/email/email_templates_seed_test.php` (db): every template the code sends by name
  (`Activation`, `User::CreateNew`, `EmailTemplateRenderer`'s outer and footer lookups, the
  `group_email_*` and `individual_email_inner_template` setting defaults) is listed in
  `email_templates.json` or shipped by a known migration; every listed file exists. No listed
  subject or body contains `integralzen`, `Integral Zen` or a hard-coded `http` link. The seed
  inserts a missing template, leaves an edited one alone, and does not bring back a soft-deleted
  one.
- Renderer: a subject with `*site_name*` comes out with the site's name, and a subject with no
  placeholders is unchanged.
- B168: a user created with a password gets a welcome email whose body does not contain it.
- Migration: an unchanged Integral Zen row is replaced, an edited one is not, and nothing changes
  when `site_name` is Integral Zen.
- `tests/email/email_pattern_test.php` keeps passing against the new text.
- The generated install SQL contains no `integralzen` (a check in the publish path, or a test that
  runs the builder's template step).

## Verification (live)

- After a release: a fresh test site's password reset arrives as "Reset your <its name> password",
  with its own name in the body and no Integral Zen anywhere.
- An existing site that had the unchanged Integral Zen text (the WP2 test site, if it is still up)
  shows the neutral templates after `update_database`.
- A new account on a test site gets a welcome email with no password in it.

## Build notes (10-27)

What the build did beyond the design above, and why:

- **The reset link lasts 24 hours.** `Activation::email_forgotpw_send()` made a 30-day code while
  the email (old and new) says 24 hours. The code now matches what the email says.
- **The outer template takes placeholders.** It was only ever spliced around the body, so
  `<title>*site_name*</title>` would have gone out literally. Placeholders are substituted on
  either side of the `*!**mail_body**!*` marker. The neutral outer drops the XHTML doctype, whose
  DTD URL was the only hard-coded link in it.
- **A soft-deleted template is not sent.** The renderer's lookups by name ignored
  `emt_delete_time`; they pass `deleted => false`, matching the seed's rule that a deleted row
  means the site removed it.
- **B169: `EmailSender::quickSend()` with an HTML body sent an empty email.** It rendered the
  `default_email_template` setting (an outer template) as the inner template, which dropped the
  body. The store's license-key email to buyers was one of these. It now puts the body in
  `blank_template` inside that outer template, with `blank_footer`.
- **B170: the post-event survey email.** The seed test's "every template the code sends is listed
  or plugin-shipped" check found `post_event_survey`, which existed nowhere, so
  `SendPostEventSurveys` failed on its first registrant. The task also never recorded a send, so
  with the template present it would have mailed every non-responder every day, and it read the
  first name from `usr_fname`, which is no column. Fixed together: event_manager migration
  `em_001_post_event_survey_template` (plugin 1.1.22), `evr_survey_sent_time` (one invitation per
  registrant), events that ended in the last 7 days only, and `usr_first_name`.
- **`individual_email_inner_template` subjects.** The order-item cancel notice passes its subject
  as a value with no `$subject` argument, so it went out titled `*subject*`; with subject
  substitution it is titled "Cancelled Subscription". Every other `blank_template` caller passes
  its own subject, which still wins.
- **B168 test.** `User::CreateNew()` sends through the platform mailer, which a test cannot
  intercept, so the check reads `CreateNew`'s source: no `password` key in the welcome email's
  values, and the shipped welcome template has no `*password*`.
- **The publish-path check** is in the builder: `create_install_sql.php` refuses to finish when
  its output matches `/integral\s*zen/i`.

## Out of scope

- Plugin-shipped templates and their migrations; they are already neutral.
- A per-site logo or colours in the outer wrapper.
- Translating templates.
