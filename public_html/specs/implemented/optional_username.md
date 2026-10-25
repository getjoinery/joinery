# Optional username

**Status:** Implemented 2026-10-25. Research done the same day (three code
sweeps, then every changed function read in full). Reviewed by reviewer1 the
same day (4 NEEDED, 13 VALID, 3 NIT, all taken). Q1-Q4 answered by the owner
2026-10-25: every recommendation taken. WP1-WP5 built 2026-10-25 on dev
(section 8 lists what the build found and where it departs from sections
3-5); reviewer1's build review closed. Verified on dev: the new and extended
suites, and the db --changed gate (817/818; the one failure, agent bundle
drift, is unrelated). Lands right after the declared_permissions commit,
which it shares files with and whose `rule_checked_write()` and
`access_stored()` it uses, so the two go out in one publish. Still to run:
the full gate, fresh installs, an upgrade of a real site's copy, a real email
change, and a check of live sites for duplicate emails (live verification
queue, optional_username A1-A5).

## 1. What this does

Today every account must have an email address, and the email is the only thing
a person can type to sign in. So whenever an account has no real address, we
make one up: the system and deleted-user rows (`@joinery.local`), the install's
admin (`admin@example.com`), the provisioning and support-desk machine accounts,
the page-probe viewer (`@probe.invalid`), someone an admin adds who has no email,
and most test accounts. The made-up addresses then cause their own trouble: the
mail-domain check refuses them (page_probe 1.2, B105), mail sent to them fails,
and `admin@example.com` only passes because of the Null MX misreading (B106).

This spec adds an optional username. An account needs an email, a username, or
both. Anyone with a username can sign in with it wherever they now type an
email. An account with no email gets nothing by email, and the places that must
reach a person (password-reset links, receipts, newsletters) say so instead of
crashing.

Out of scope: family or child accounts. One username-only account per child is
now possible for an admin to create, but cart lines and event registration still
attach a line with no email to the buyer (cart_charge_logic.php:219-221); a
proper dependents model is its own spec. Drive sharing by username is also left
out (it finds people by email: drive_share_sync_logic.php:73,
drive_public_keys_logic.php:60).

## 2. What the platform does today

File references are to the tree on 2026-10-25.

### 2.1 The email column and its rules

- `usr_email` is `varchar(64)`, `'required'=>true`, `'validation'=>['email'=>true]`
  (data/users_class.php:122). The column itself is nullable (no `is_nullable`).
- `save()` refuses a blank required field: "Required field usr_email must be set"
  (includes/SystemBase.php:3159-3166). This runs on every save, not only through
  `prepare()`.
- The `email` validation rule skips NULL and `''`, and for anything else checks
  the format and then the domain's mail records unless
  `email_validation_mx_check` is `0` or `withoutEmailDomainCheck()` is active
  (SystemBase.php:3208-3227; `emailDomainCheckApplies()` LibraryFunctions.php:576-581,
  `withoutEmailDomainCheck()` :583-597). `IsValidEmail()` is at :555-565.
  `DnsResolver::domainAcceptsMail()` answers true on a failed lookup and false
  on a domain with neither MX nor A records (DnsResolver.php:248-257).
- `User::prepare()` overrides the base class without calling it. On a new row it
  refuses an address `GetByEmail` finds; on every call it refuses one
  `IsValidEmail` rejects, which includes NULL (users_class.php:630-648).
- **There is no unique index and no index at all on the email.** On dev:
  `pg_indexes` for `usr_users` lists only the primary key, and the lookup is a
  sequential scan. Uniqueness is only the check-then-insert in `prepare()`,
  `register_logic.php:125`, `CreateNew` (users_class.php:408) and the two
  email-change forms (account_edit_logic.php:123, admin_users_edit_logic.php:74),
  and a REST or AI write skips even that (B120).
- `User` overrides `save()` (users_class.php:1161) but not `set()`. `save()` never
  calls `prepare()`: only `safe_load_and_set()` does (SystemBase.php:3036), and
  many paths save a user without it (account_edit_logic.php:60,
  ProvisioningSetup.php:302, SupportDesk.php:59, page_probe.php:116).
- `User` declares no `$index_specifications`. The updater supports
  expression and partial unique indexes there, e.g. `LOWER(iem_recipient)`
  (plugins/mailbox/data/inbound_email_messages_class.php:635), and it **skips a
  unique index with a warning, never fails, when existing live rows hold
  duplicates** (includes/DatabaseUpdater.php:1536-1543). Its duplicate check
  ignores NULLs (DatabaseUpdater.php:1232-1242).

### 2.2 Finding a user by what they typed

- `User::GetByEmail()` (users_class.php:650-661) is
  `SingleRowFetch('usr_users', 'LOWER(usr_email)', strtolower(trim($email)))`,
  which returns the first row with no order and no deleted filter
  (includes/SingleRowAccessor.php:82-92).
- Every sign-in path that starts from typed text goes through it:
  - web password sign-in, logic/login_logic.php:111-117
  - API `POST /api/v1/auth/login`, `ApiAuth::attemptLogin()`, includes/ApiAuth.php:816-818
  - passkey sign-in with an address typed, logic/passkey_login_options_logic.php:28
    and `PasskeyService::getAuthenticationOptions()`, includes/PasskeyService.php:333-336
  - emailed reset, logic/password_reset_1_logic.php:33-35, and again inside
    `Activation::email_forgotpw_send()`, includes/Activation.php:255-256
  - authenticator-code reset, logic/password_reset_totp_logic.php:41-47
- Everything after the first factor works from a user id: the TOTP step
  (`$_SESSION['totp_pending_user_id']`, login_logic.php:184-187), remember-me
  tokens, trusted-device cookies, step-up, API keys, log-in-as. None of these
  change.
- The web sign-in does not refuse a deleted account itself; the next request
  logs it out (`validate_account_standing()`, SessionControl.php:1276-1281). The
  API sign-in refuses one (ApiAuth.php:821-826).
- The per-account failed-sign-in count is keyed on the text typed:
  `RequestLogger::username_key()` = `'user:'+sha256(lower(trim))`
  (RequestLogger.php:247-254), used at login_logic.php:113. With two
  identifiers, one account would get two separate counts.

### 2.3 Making a user

- `CreateNew()` throws without an email (users_class.php:397), returns any
  account `GetByEmail` finds (:408), stores the address lowercased (:431), and
  sends the welcome and activation emails unless `send_emails` is false
  (:458-469). `CreateCompleteNew()` dedups by email first (:491).
- Machine and placeholder accounts write a made-up address and `save()` directly:
  - install SQL, users 1-3 (utils/create_install_sql.php:435-454), and the same
    rows for the test database (includes/TestDatabaseHelper.php:575-576)
  - provisioning, `provisioning-<hex>@<host>` (plugins/server_manager/includes/ProvisioningSetup.php:289-305)
  - support desk, `support-desk-<hex>@<host>`, falling back to `localhost.invalid`
    (plugins/support_desk/includes/SupportDesk.php:49-62). That fallback would be
    refused by the mail-domain check when it is on, since the save is not wrapped
    in `withoutEmailDomainCheck()`.
  - page probe, `probe-<hex>@probe.invalid`, wrapped in
    `withoutEmailDomainCheck()`, its only caller (utils/page_probe.php:104-117)
- The install script finds the seeded admin by `admin@example.com`
  (maintenance_scripts/install_tools/_site_init.sh:492, :558), moves it to
  `JOINERY_ADMIN_EMAIL` when one is given and accepted, and otherwise leaves it
  at `admin@example.com` (:536, :568-586).
- An admin adding a person must type an email (adm/admin_user_add.php:61-72),
  and `admin_user_add_logic.php:16` calls `CreateCompleteNew`.

### 2.4 What a blank email would break

Sends: `EmailMessage::validate()` refuses a blank recipient
(includes/EmailMessage.php:309-313), and `EmailSender::send()` throws on it
(EmailSender.php:386-389). `sendTemplate()` (:582-592) has no guard. Notifications
(Notify.php:383-386) and campaign queueing (emails_class.php:184) already skip an
account with no address.

Hard failures, each read:

| Where | What happens with no email |
|---|---|
| `StripeHelper::get_customer()`, plugins/store/includes/StripeHelper.php:640-662 | `customers->all(['email'=>null])`. The SDK drops null params (vendor/stripe/stripe-php/lib/Util/Util.php:133-155, called from HttpClient/CurlClient.php:210), so the list is unfiltered and `data[0]`, someone else's customer, is saved to this user (:657-658). Reached from `get_or_create_stripe_customer()` (:724-735) at checkout (cart_charge_logic.php:139) and from admin_user_payment_methods.php:55. **The SDK half traced by reviewer1; Stripe itself not called.** |
| `MailingList::sync_subscribe/sync_unsubscribe`, data/mailing_lists_class.php:224-266 | Pass null to the provider's `string $email`. The resulting `TypeError` is not caught (only `MailingListProviderException` and `InvalidArgumentException` are). |
| `MailingList::add_registrant` welcome email, mailing_lists_class.php:167-177 | Unguarded `sendTemplate`, throws. |
| Admin "Resend activation", adm/logic/admin_user_logic.php:106-116, offered at :236 | Unguarded `email_activate_send`, throws. The link shows for every unverified user, which would be every no-email user. |
| Activation-required sign-in, login_logic.php:159-167 and ApiAuth.php:835-838 | A no-email account can never activate, so it can never sign in when `activation_required_login` is on. |
| TOTP enrolment, logic/security_logic.php:498 | `setLabel(null)` throws `TypeError` (traced by reviewer1). |
| Passkey registration, PasskeyService.php:213 and :283 | `PublicKeyCredentialUserEntity::create(usr_email, ...)`: the WebAuthn `user.name` is the email. `_displayName()` falls back to it (:793-796). |
| `RecoveryReadiness::vaultAggregate()`, includes/RecoveryReadiness.php:497-512 | `GROUP BY u.usr_email` would merge every no-email vault holder into one row. |
| Checkout for a signed-in buyer, plugins/store/logic/checkout_logic.php:97-104 and cart_charge_logic.php:116-127 | The billing email is copied from the account, so it is blank; the charge then resolves the buyer by billing email and `CreateCompleteNew` throws. |
| Admin email change, adm/logic/admin_users_edit_logic.php:72-83 | A blank `usr_email_new` differs from the stored address, so it runs `GetByEmail('')` (NULL: `_RowFetch` returns NULL for any falsy key, SingleRowAccessor.php:9-11) and sets `''`, which `prepare()` then refuses as invalid. There is no way to remove an address. |

Display: about 45 places (the sweep's count, not each read) print `usr_email` as who someone is: the user picker
(`MultiUser::get_dropdown_array()`, users_class.php:1557-1561), user search
results (logic/user_search_logic.php:63), the admin users list, and names that
fall back to the email. `display_name()` itself never uses the email
(users_class.php:1054-1074).

### 2.5 Bugs found during the research

- **B117:** a live user can be unable to sign in with a password. `GetByEmail`
  takes whichever row comes first, deleted or not. On dev,
  `apitest_appadmin@getjoinery.com` returns deleted user 4669 every time, so
  live user 4671 is checked against the wrong password.
- **B118:** in Stripe Checkout mode a signed-in buyer is treated as a guest.
  plugins/store/logic/checkout_logic.php:223 calls
  `User::GetByEmail($session->get_user_id())`, an id passed as an email, which
  never matches.
- **B119:** the email-change confirm step sets the new address and calls
  `prepare()` on an existing row (includes/Activation.php:50-70), where the
  duplicate check does not run (users_class.php:631), so an address taken
  between the request and the click is accepted. The address is also stored as
  typed, not lowercased (email_change_send, Activation.php:320-322).
- **B120:** a member can change their own sign-in email through a REST update
  (not the AI write path: `User` declares no `$ai_writable_fields`) with no second-factor check, no confirm link and no
  duplicate check, and the verified flag stays true. `User` is API-writable
  (users_class.php:40, apiv1.php:737); the owner passes `authenticate_write`
  (users_class.php:1084-1090); `usr_email` is not in `$api_unwritable_fields`
  (:41-44), the only write floor besides the credential pattern
  (SystemBase.php:2425, ModelRegistry.php:157); the update runs `prepare()` then
  `save()` (apiv1.php:805-806), and `prepare()` skips the duplicate check on an
  existing row (users_class.php:631). Found by reviewer1 (read, not run: running
  it would write a user). It is also the only path found that can make two live
  accounts share an address.
- **B121:** `CreateNew()` returns a deleted account when only a deleted row holds
  the address (users_class.php:408), and checkout then bills it
  (cart_charge_logic.php:116-127). See Q4.

## 3. Design

### 3.1 The username column

`usr_username varchar(32)`, nullable, on `User`:

- Letters, digits, `.`, `_`, `-`; 3 to 32 characters; starts with a letter or
  digit. No `@`, so a sign-in box that takes either is never ambiguous: text with
  `@` is an email, text without is a username.
- Stored as typed. Unique regardless of capitals, by the same rule as the email:
  a username, like an address, stays taken while any account holds it, deleted
  included. Otherwise a closed account's handle could be claimed by someone else
  at once, and 3.6 shows `@username` as who someone is. Undeleting an account
  therefore never meets its identifier in use.
- The database floor is a unique index among live rows, declared in a new
  `User::$index_specifications`:
  `['columns'=>['LOWER(usr_username)'], 'unique'=>true, 'where'=>'usr_delete_time IS NULL']`.
  It is live-only because email already has deleted+live pairs (dev: 4669/4671),
  which a full index would skip; the stricter deleted-included rule is the
  save check below. The column is new, so no site has username duplicates.
- `User::save()` checks the format and the uniqueness and refuses with a plain
  message (3.2 says why it is `save()`).
- A member changes their own username on their account page, behind the same
  second-factor re-check an email change asks (logic/account_edit_logic.php:81),
  because it is a sign-in identifier. It and `usr_email` (B120) go in a new
  `$api_create_only_fields`, so no API update can change either around that
  check while an API create may still set them (section 8). Both stay readable.

### 3.2 An account needs an email, a username, or both

- `usr_email` loses `'required'=>true` (users_class.php:122). The `email`
  validation rule stays; it already skips a blank value.
- **The "neither" check is in `User::save()`** (the existing override,
  users_class.php:1161), not `prepare()`: `save()` is the one path every write
  takes, and `prepare()` is skipped by many (2.1). `prepare()` keeps the friendly
  messages and runs `IsValidEmail` only when there is an email.
- **A blank email is stored as NULL, never `''`.** A new `User::set()` override
  turns `''` into NULL for `usr_email` and `usr_username`. Two reasons: `''` rows
  would collide in the unique indexes (the duplicate check ignores only NULL,
  DatabaseUpdater.php:1232-1242), and Stripe gets `email=` for `''` where the SDK
  drops a NULL (vendor Stripe Util.php:133-155), so this rule and the `get_customer` guard in 3.4
  land together.
- `usr_email` also gets the index it lacks:
  `['columns'=>['LOWER(usr_email)'], 'unique'=>true, 'where'=>'usr_delete_time IS NULL']`.
  A site with live duplicates (possible only through B120) gets the updater's
  warning instead (2.1). The release notes say to look for that warning. The
  duplicate check moves from `prepare()` to `save()` and runs on every change of
  the address, not only on a new row, ignoring the account itself and counting
  deleted accounts (3.1). That also closes B119.
- `CreateNew()` takes an email, a username, or both, and throws only when both
  are missing. It dedups by email when given one and refuses a taken username.
  With no email it sends nothing and creates the account activated: activation
  proves control of an address, and there is none to prove.

### 3.3 One sign-in lookup

- `User::GetByLogin(string $identifier): ?User`: text containing `@` is looked up
  by email, anything else by username. Live accounts only, so neither sign-in
  ever sees a deleted account: the deleted-account refusal in `attemptLogin()`
  (ApiAuth.php:821-826) becomes unreachable and goes, and both sign-ins keep
  `spend_password_check()` when nothing is found.
- `GetByEmail()` keeps its callers' meaning, but prefers the live row: one query,
  `ORDER BY usr_delete_time IS NOT NULL, usr_user_id` (`SingleRowFetch` takes no
  order; `_RowFetch` does, SingleRowAccessor.php:7). This fixes B117 without
  changing what any caller sees when only a deleted row has the address, so a
  deleted account's address stays blocked for a new account, as today (the
  duplicate check, in `save()` per 3.2, still finds it). It has 38 non-test call sites (grep, 2026-10-25):
  the six sign-in ones below switch to `GetByLogin`, and the rest mean an
  address, not a sign-in, and keep calling it.
- The sign-in paths in 2.2 switch to `GetByLogin`: login_logic.php:117,
  ApiAuth.php:818, passkey_login_options_logic.php:28, PasskeyService.php:336,
  password_reset_1_logic.php:35 (and `email_forgotpw_send` takes the user, not
  the address, so it stops looking the user up a second time),
  password_reset_totp_logic.php:47.
- The web sign-in's failed count. Today the limit is checked before the lookup,
  on the text typed (login_logic.php:113-117). The new order is lookup, then the
  limit, then the password. A failure is recorded under the account
  (`'user:'+id`) when one was found, so one account has one count whichever
  identifier is typed, and also under `username_key(text)` as now. The limit
  trips when either count is over. Keeping the text key means the app sign-in,
  which counts under `username_key` of the mailbox address
  (`appAccessBucketKeys`, ApiAuth.php:481), still shares a count with a web
  sign-in that types that same address. The comment at ApiAuth.php:70 and the
  test at tests/api/rate_limit_state_test.php:183-184 stay true; the test gains
  the account-key case.
- Field labels and messages say "Email or username" on the sign-in form, the
  login box, the API docs (`email` param keeps its name and also takes a
  username), and the two reset forms.

### 3.4 Sending to someone with no email

- `EmailSender::sendTemplate()` and `quickSend()` return false and log
  `[EMAIL_NO_ADDRESS] <template>` when the recipient is blank, instead of
  building a message that `send()` throws on. A malformed non-blank address still
  throws as it does now. This is the root fix for the unguarded sends in 2.4 (the
  list welcome, resend activation) and for the receipts, tier-change and
  failed-payment notices, which are already inside try/catch (cart_charge_logic.php:501-503,
  :528-530, :553-555; change_tier_logic.php:260-268; the two payment webhooks)
  and today log an exception: all of them get "nothing sent" instead.
  `Activation::email_forgotpw_send()` then returns false for a username-only
  account while the recovery-address copy (Activation.php:281-296) still goes;
  its return value comes to mean "primary not sent", and the reset page's single
  answer still hides which happened.
- `StripeHelper::get_customer()` returns its not-found sentinel without calling
  Stripe when the user has no email. `create_customer_at_stripe()` leaves the
  email out and drops the `()` from the description.
- `sync_subscribe/sync_unsubscribe` return true (nothing to sync) for a user with
  no email; `add_registrant` skips the welcome email.
- The admin "Resend activation" action and link only appear for an account with
  an email. The activation gate at sign-in skips an account with no email.
- TOTP label and passkey `user.name`: the email when there is one, else the
  username. Existing passkeys keep the label they were made with.
- `vaultAggregate()` groups by user id and shows the label from 3.6.

### 3.5 What still needs an email

Each of these says so plainly instead of failing:

- **Emailed password reset.** A username-only account resets with its
  authenticator code or passkey, or through an admin. If it has a verified
  recovery address, the reset link goes there (Activation.php:281-296 already
  sends to it). The reset page keeps its single answer for every outcome, so it
  says nothing about which accounts exist, and adds one line: "No email on your
  account? Use your authenticator app or passkey, or ask an administrator."
- **Buying.** A signed-in buyer with no email is asked to add one to their
  account before checkout (Q2).
- **Mailing-list sign-up** for a signed-in user with no email asks for one first.
- **Server Manager hosting orders** already require a valid address
  (plugins/server_manager/includes/ManagedSiteDraft.php:280-286). No change.
- **Mail apps** sign in with the mailbox's own address, not the account email
  (MailAppContext.php). No change.
- **Public sign-up** stays email-only (Q3).

### 3.6 Showing who someone is

`User::login_label()`: the email if there is one, else `@username`. The display
sites in 2.4 that print `usr_email` as an identity switch to it: the user picker
(users_class.php:1560), user search (user_search_logic.php:63), the admin users
list and user page, and the name-falls-back-to-email sites. User search gains a
`username_like` filter, and text without `@` also matches usernames.

### 3.7 Forms

- Admin add user (adm/admin_user_add.php): username field; email no longer
  required; the form refuses submit with neither. The "Send an activation email"
  box only applies with an email.
- Admin edit user and the member's own account page: username field; email can
  be cleared when a username exists. Clearing goes through the save check in
  3.2, so it cannot leave an account with neither. A blank `usr_email_new` means
  "remove", not "change to `''`" (fixes the admin_users_edit_logic.php:72 case).
- The email-change confirm step re-checks the address is free and lowercases it
  (B119).

### 3.8 Accounts that become username-only

- **New installs.** The install SQL seeds user 1 as username `admin` with no
  email, user 2 as `system`, user 3 as `deleted-user`, all with no email
  (create_install_sql.php:435-454, TestDatabaseHelper.php:575-576).
  `JobCommandBuilder::build_install_node` passes nothing when there is no buyer
  address, unchanged. Every place that finds or shows the admin by
  `admin@example.com` changes:
  - `_site_init.sh` finds the admin by id 1 (:492, :558). `ADMIN_EMAIL` has no
    default (:536); the comparisons with the old default go (:568, :577, :584,
    :594). With no address given, or one the domain check refuses, the admin
    stays username-only (:586), and the printed credentials say
    `Username: admin` (:610).
  - `install.sh` prints the username when the credentials file has no Email
    line, instead of falling back to `admin@example.com` (:1053); the
    `--admin-email` help text loses "(default admin@example.com)" (:694,
    :4640, :6587).
  - `reset_admin_password.php` gains `--user-id` and `--username`, and finds
    accounts with `GetByLogin` (:162, :185). With no account named it still
    picks the only permission-10 account (:138-155) and prints its login label
    (3.6), not a blank email.
  - The home-page setup card stops printing `admin@example.com` as the sign-in
    (views/index.php:96) and shows the admin's login label.
- **Existing sites.** A data migration finds users 2 and 3 by id
  (`User::USER_SYSTEM`, `USER_DELETED`, users_class.php:71-72), never by address
  (dev's are `system-user@test.com` and `deleted-user@test.com`), gives them
  their usernames and clears their addresses. User 1 at exactly
  `admin@example.com` gets the username `admin` and its address cleared (Q1).
- **Machine accounts made from now on**: provisioning, support desk and page
  probe set a username (`provisioning-<hex>`, `support-desk-<hex>`,
  `probe-<hex>`) and no email. `withoutEmailDomainCheck()` loses its only caller
  and is removed, with its test (tests/integration/email_validation_toggle_test.php:66-101). Existing provisioning and support-desk rows keep their
  addresses: they are found by id (ProvisioningSetup.php:112-122) and the legacy
  adoption path still finds the old address (:133-136).

## 4. Work packages

- **WP1 Bugs.** B117 (`GetByEmail` prefers the live row), B118 (one line in
  checkout_logic.php:223: `new User($session->get_user_id(), TRUE)`), B119 (the
  confirm step re-checks and lowercases, and the confirm page it never had),
  B120 (`usr_email` into `$api_create_only_fields`), B121 (`CreateNew` and checkout refuse an address a
  deleted account holds, with the Q4 message).
  Independent of the rest; lands first. Note: data/users_class.php and
  checkout_logic.php carry the declared-permissions session's uncommitted edits,
  so WP1 waits for those to be committed.
- **WP2 Column, rules, lookup.** 3.1, 3.2, 3.3.
- **WP3 No-email safety.** 3.4 and 3.5. Must land with or before WP2 is reachable
  from a form, since WP2 is what lets a blank email exist.
- **WP4 Forms and display.** 3.6, 3.7; docs: docs/api.md (sign-in param),
  docs/account_security.md (sign-in paths, reset without email),
  docs/email_system.md (the blank-recipient rule).
- **WP5 Username-only system and machine accounts.** 3.8, including the
  migration and the install script.

`data/users_class.php` is a core data class: WP1, WP2 and WP5 will hold the next
publish until committed.

## 5. Tests

- `tests/models/user_username_test.php` (db): format rules; uniqueness ignores
  capitals and counts deleted rows; neither email nor username is refused; `''` becomes
  NULL; `CreateNew` with only a username sends nothing and is activated.
- Sign-in matrix (extend tests/account_security/login_test.php): email,
  username, wrong capitals, deleted account with a live twin (B117), failed-count
  shared between the two identifiers; the same for the API sign-in.
- B120: a member's REST and AI writes to their own `usr_email` and
  `usr_username` are refused.
- Identifiers: a deleted account's username and email are refused for a new
  account and for a rename; clearing the email on a username-less account is
  refused by a plain `save()` with no `prepare()`.
- No-email sends: `sendTemplate` to a blank address returns false and logs;
  `get_customer` makes no Stripe call (stub client); list sync is a no-op.
- Checkout: B118 (signed-in buyer in Stripe Checkout mode is the billing user).
- Install: `create_install_sql` output (tests/core/install_sql_deterministic_test.php
  pins it) and the installer contract test for the id-1 lookup.
- Each new check mutation-tested (break the code, watch the check fail).

## 6. Open questions

**Q1. Existing sites whose admin is still `admin@example.com`.** The migration
gives user 1 the username `admin` in both cases.
- (a) Keep the address too. Nobody's sign-in changes. The fake address stays,
  with its domain-check and send failures (B106).
- (b) Clear the address. The fake address is gone everywhere. Anyone who signs in
  as `admin@example.com` (test boxes, saved notes) types `admin` instead.
- **Recommend (b):** there are no production users yet, and the address never
  reached anyone.
- **Decided (b)**, owner 2026-10-25.

**Q2. A signed-in buyer with no email.**
- (a) Checkout asks them to add an email to their account first (link to the
  account page). One rule, nothing new in checkout. One extra step for that buyer.
- (b) Checkout takes an email and saves it onto their account. Smoother. Checkout
  then writes account emails, which needs its own verification and collision rules.
- **Recommend (a).**
- **Decided (a)**, owner 2026-10-25.

**Q3. Public sign-up with a username.**
- (a) Sign-up stays email-only; a member can add a username later on their
  account page. Every self-made account keeps a reset path and a contact.
- (b) Sign-up offers "username instead of email". Allows anonymous members. A
  member who forgets their password and has no second factor is locked out, and
  spam sign-ups get cheaper.
- **Recommend (a).**
- **Decided (a)**, owner 2026-10-25.

**Q4. Checkout or sign-up with an address that belongs to a deleted account
(B121).** Today the purchase lands on the deleted account.
- (a) Refuse with a plain message: "That address belongs to a closed account.
  Contact us to reopen it." No closed account is revived without a person
  deciding. The buyer is stopped.
- (b) Reopen the account and continue. No sale lost. An account an admin closed
  on purpose (abuse, a request to be forgotten) comes back on its own.
- **Recommend (a).**
- **Decided (a)**, owner 2026-10-25.

## 7. For review

- **R1.** Answered by reviewer1: yes, B120 (2.5).
- **R2.** Traced by reviewer1: `Util::objectsToIds(['email' => null])` returns
  `[]`; `['email' => '']` keeps `''`. Stripe itself was not called.
- **R3.** Traced by reviewer1: `setLabel(null)` throws `TypeError`.

## 8. Build notes (2026-10-25)

What the build found, and where it departs from the design above.

- **B119 was bigger.** The email-change link opens `/email_change`, a page that
  never existed (dev answered 404; no trace in git history), and
  `Activation::ChangeEmailUser()` had no caller and wrote an `EmailChange` model
  that no longer exists. The website account page also had no email field: a
  member could ask for a change only through the API action. Built:
  `views/email_change.php` + `logic/email_change_logic.php` (opening the link
  shows the address and a Confirm button; the POST makes the change, so a mail
  scanner fetching the link spends nothing), `ChangeEmailUser()` rewritten
  (lowercases, refuses an address another account took, spends the code either
  way), and the email and username fields on the account page.
- **B120 is the REST update only.** `User` declares no `$ai_writable_fields`, so
  the AI write surface never reached it. And `$api_unwritable_fields` (3.1) would
  also have stripped the email from `POST /User`, which creates accounts. Built
  instead: a new `SystemBase::$api_create_only_fields` (set on create, dropped
  from every API update, REST and AI), and `User` lists `usr_email` and
  `usr_username` there. Documented in docs/api.md.
- **The account count (3.3)** uses the failure row's own `rql_usr_user_id`:
  one row carries the typed text's key and the account id, and the limit asks
  both counts. No second key, so a failure still costs the network one row.
- **The closed-account rule (B121, Q4)** also covers the two mailing-list
  sign-up pages and registration, which subscribed or pointed at a deleted
  account the same way.
- **B122 (fixed):** Stripe Checkout's "use my account" filled the billing name
  from `usr_fname`/`usr_lname`, columns that do not exist, so the name was blank
  (plugins/store/logic/checkout_logic.php).
- **B123 (open, not this spec):** the admin users list prints `display_name()`
  unescaped (adm/admin_users.php:51, through `disprow()`), and a member can set
  their own first name over REST without `strip_tags`. The enforced
  Content-Security-Policy (migration 184) blocks a script, but markup still
  renders in an admin's page.
- **Display sites (3.6)** switched to `login_label()`: the user picker, both
  user searches (which also match usernames), the admin users list and user
  page, the settings user field, support desk, management jobs, ownerships,
  coupons, order notices, bookings, event emails, the second-factor notice,
  the profile pages, Joinery AI owner lists and two mailbox names.
- **Tests:** tests/models/user_email_lookup_test.php (B117, B119, B121),
  tests/models/user_username_test.php (3.1, 3.2, GetByLogin),
  tests/integration/no_email_account_test.php (3.4, Q2),
  tests/account_security/login_test.php (username sign-in, the shared count,
  B117), tests/functional/api/crud_authorization_test.php (B120), the install
  SQL and provisioning tests updated, the `withoutEmailDomainCheck()` checks
  removed with it. Each new rule mutation-tested.
- **Build review (reviewer1, 2026-10-25): 1 NEEDED, 8 VALID, 3 NIT, all taken.**
  A signed-in person with no email who asks for checkout or a mailing list is
  sent to their account page with a plain-text note saying why
  (`User::add_email_first()`), instead of an error page that would have
  escaped the link. Restoring a deleted account whose email or username a live
  account now holds is refused in words (`User::undelete()`), not by the
  database. Migration 223 also stores '' addresses as NULL. The session payload
  in docs/api.md lists `username`.
- **Test fixtures that shared an address.** The save rule refused fixtures
  that had silently made two accounts with one email: booking_flow and
  sandbox_data called `make_user()` twice with one label. `make_user_row()`
  now gives a repeated label a numbered address. And the model tester stopped
  filling the now-optional email, so its user had neither identifier: a new
  `$test_fixture['generate']` key names optional fields to fill, and `User`
  lists `usr_email` (docs/testing.md).
- **Migration 223** (`seeded_accounts_usernames.php`) ran on dev: users 2 and 3
  became `system` and `deleted-user` (their addresses were `@test.com`, not
  `@joinery.local`); user 1 is a real address on dev, so it was left alone.

