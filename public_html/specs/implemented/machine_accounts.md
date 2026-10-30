# Machine accounts

Status: WP1 + WP2 BUILT 2026-10-30. Verified on localdev: machine_account 15/15, api_session_keys 83/83, provisioning_setup 70/70, db --changed gate; dev column and sm_029 applied. WP3 (live on getjoinery after release) is in the live verification queue.

## What this is for

Some user accounts belong to a machine, not a person. On getjoinery.com there
are two: the provisioning pipeline's account, which only uses an API key, and
"Getjoinery Alerts" (info@getjoinery.com), which exists so incident alerts reach
a shared mailbox. Both are admins (level 8 and level 5), neither has a second
factor, and so every admin page on getjoinery shows "2 admins have no second
factor."

The notice is right that a password alone can be phished. It is wrong about
these two, because nobody signs into them. Giving them a second factor would
only silence the notice. Making the notice dismissable would also hide the next
real admin without one.

This spec adds a **machine account** marker. A machine account cannot have a
website session or an API session by any route. Its machine API keys and its
email (alerts) keep working. Since no password, passkey or app password can sign
it in, there is nothing for phishing to steal, and the second-factor notice
leaves it out honestly.

## Shape

Core change: the user model and every sign-in door. The provisioning account is
a server_manager plugin concept, so the plugin marks its own account (on
creation, and with a migration for existing ones). Same code on every
deployment: a site node, the management node, Docker or bare metal. Nothing
runs on the host.

## Why not the existing "disabled" flag

`usr_is_disabled` already blocks every web sign-in (`User::actions_allowed()`,
`data/users_class.php:1269`, checked by `SessionControl::store_session_variables()`,
`includes/SessionControl.php:2045`). It cannot be reused, for two reasons:

- It also refuses every API key the account owns (`includes/ApiAuth.php:266-268`).
  The provisioning pipeline would stop.
- The `admins` and `superadmins` notification audiences leave out disabled
  accounts (`includes/Notify.php:81`, `'disabled' => false`). Getjoinery Alerts
  would stop getting alerts.

The support desk's reserved user (`plugins/support_desk/includes/SupportDesk.php:47-56`)
*is* correctly disabled: it is level 0, holds no keys and receives no mail.
It stays as it is.

## Inventory: every way to get a session as a user

Website sessions all go through `SessionControl::store_session_variables()`
(`includes/SessionControl.php:2044`). Its callers:

| Door | Call site |
|---|---|
| Password sign-in | `logic/login_logic.php:220` (and `:53`, sign-in at activation) |
| Second-factor completion | `includes/Login2fa.php:32` |
| Remember-me cookie | `includes/SessionControl.php:751` |
| Passkey sign-in | `includes/PasskeyService.php:371` |
| Native app web bridge | `logic/app_bridge_logic.php:70` |
| Sign-in during checkout | `plugins/store/logic/cart_charge_logic.php:108` |
| `User` sign-in helper | `data/users_class.php:614` |
| Admin "Log in as user" | `adm/logic/admin_user_login_as_logic.php:42` |

A session already open is re-checked on every request by
`validate_account_standing()` (`includes/SessionControl.php:1274-1296`). It logs
out a deleted or disabled account, or one whose session generation has moved on.

API sessions do not use that function:

| Door | Where |
|---|---|
| `POST /api/v1/auth/login` (password → session key) | `ApiAuth::attemptLogin()`, `includes/ApiAuth.php:816`; it refuses disabled accounts at `:832` |
| Every key-authenticated request (session keys, app passwords used by the mail gateway, machine keys) | `ApiAuth::authenticate()`, `includes/ApiAuth.php:180`; the account checks are at `:259-268` |
| Browser-session API calls | `authenticateBrowserSession()`, `includes/ApiAuth.php:342`; rides the website session, so it is covered by the website rules |

Key types are `machine`, `session` and `app_password` (`data/api_keys_class.php:32-34`).

Password reset (`logic/password_reset_1_logic.php:43`, `password_reset_2_logic.php:71`,
`password_reset_passkey_verify_logic.php:61`, `password_reset_totp_logic.php:64`)
is not a door: it only sets a password. On a machine account no door takes
that password, so reset needs no change. See "Not changed" below.

## Design

### 1. The marker

`usr_is_machine`, bool, default false, in `User::$field_specifications`
(`data/users_class.php`, beside `usr_is_disabled` at `:167`). It is added to
`$api_unwritable_fields` (`:41-44`), so no CRUD, API or AI write can set or
clear it, the same as `usr_is_disabled`.

`User::is_machine_account(): bool` reads it.

### 2. Website: refused at the one door, and on every request

- `store_session_variables()` (`includes/SessionControl.php:2044`): after the
  `actions_allowed()` check, a machine account throws
  `SystemDisplayablePermanentError('This is a machine account. It has no website sign-in.')`.
  That covers all eight callers above.
- `validate_account_standing()` (`:1288`): a machine account is logged out, in the
  same branch as a disabled one. This check runs on every request, so marking an
  account ends any session it already has at that session's next request. No
  separate session-ending step is needed.
- Admin "Log in as user" (`adm/logic/admin_user_login_as_logic.php`): refuses a
  machine account *before* the audit log row at `:36` with
  `LogicResult::error('A machine account has no website sign-in.')`. Without this,
  the page would log a login that never happened, then throw from
  `store_session_variables()`. The "Log in as user" button
  (`adm/logic/admin_user_logic.php:255-257`) is not offered for a machine account.

### 3. API: password sign-in refused; only machine keys accepted

- `ApiAuth::attemptLogin()` (`includes/ApiAuth.php:832`): after the password
  verifies, a machine account returns `reason => 'machine'`.
  `ApiAuthEndpoint::handle_login()` (`includes/ApiAuthEndpoint.php:134-145`)
  answers 403 with the same words as the website. Refusing only after the
  password verifies follows the disabled case: the answer reveals nothing about
  the account unless the caller already has its password.
- `ApiAuth::authenticate()`: after the `actions_allowed()` check (`:266-268`), a
  machine account presenting any key that is not `TYPE_MACHINE` is refused, 401
  "This key type is not accepted for a machine account". This covers session
  keys and app passwords minted before the account was marked. A machine key
  still works.

### 4. The second-factor notice and requirement

- `AdminSecondFactorNotice::adminsWithoutSecondFactor()`
  (`includes/AdminSecondFactorNotice.php:42-61`) adds
  `AND COALESCE(usr_is_machine, FALSE) = FALSE`, beside the system/deleted
  exclusion at `:47`. The class comment's "The platform's own accounts are not
  people" line covers machine accounts too.
- `must_enable_totp_for_admin()` (`includes/SessionControl.php:1937`) needs no
  change: it only runs inside a website session, which a machine account cannot
  have.

### 5. Admin pages

- **Marking and unmarking:** `adm/admin_users_edit.php` gains a "Machine account"
  select (No / Yes), inside the level-10 block at `:74` with the permission
  level. Help text: "Machine accounts never sign in on the website or with a
  password. Their machine API keys and email keep working." The logic
  (`adm/logic/admin_users_edit_logic.php`) saves it only under
  `Access::permits(10, $session)`, like `usr_permission` at `:109`. An admin
  cannot mark their own account. Refusing that prevents an admin from locking
  themselves out by mistake.
- **Display:** `adm/admin_user.php` shows a status row beside the
  disabled/admin-disabled rows (`:121-133`): "Machine account: signs in only with
  its machine API keys."

### 6. The provisioning account marks itself

- `ProvisioningSetup::setupApiCredentials()`
  (`plugins/server_manager/includes/ProvisioningSetup.php:251`): a new service
  user (`:290-304`) is created with `usr_is_machine` set. An existing or adopted
  one (`:259-273`) is marked if it is not already. This runs whenever an admin
  sets up or rotates the credentials (`plugins/server_manager/logic/admin_provisioning_setup_logic.php:169`).
- **Existing deployments:** plugin migration `sm_029_mark_provisioning_machine_account`
  in `plugins/server_manager/migrations/migrations.php`. It marks the user whose
  id is in `server_manager_provisioning_service_user_id`, and the legacy
  `provisioning@%` account that owns the configured key, using the matching
  rule sm_027 used. The `usr_is_machine` column is core, so it exists before
  plugin migrations run.

### 7. Getjoinery Alerts

This is a one-off for getjoinery: after the release, the owner (or Claude, with
the owner's OK) marks user 603 with the new select. The account keeps level 5,
so it stays in the `admins` alert audience (`includes/Notify.php:81`, which
filters only deleted, disabled and system accounts).

## Not changed

- **Password reset.** It can still set a machine account's password, but no
  door accepts that password, so a reset gets nobody in. Refusing reset would
  add a fourth check across four files for no protection.
- **Notification preferences.** A machine account cannot open its own
  `/profile/notifications`. Its subscriptions are whatever they were when it
  was marked. Getjoinery Alerts was already subscribed (2026-10-08).
- **The support desk user.** It stays disabled, as above.

## Docs

- `docs/account_security.md`: a new section "Machine accounts" after "Admin
  second-factor requirement" (`:148`). It says what the marker does at each
  door, why the notice skips these accounts, and who can set it. The
  requirement section's notice paragraph (`:162-167`) gets one sentence: machine
  accounts are not counted.
- `docs/api.md`, authentication: a machine account accepts only machine keys,
  and `auth/login` refuses it with 403.
- `plugins/server_manager/docs/overview.md` (`:1017`, the `ProvisioningSetup`
  entry): the service user is a machine account.

## Tests

New `tests/account_security/machine_account_test.php` (db tier, harness),
using a fixture user marked as a machine account:

1. `store_session_variables()` throws for it.
2. A website session opened before marking is logged out at its next
   `validate_account_standing()`.
3. `ApiAuth::attemptLogin()` with the right password returns `reason => machine`.
4. `ApiAuth::authenticate()` accepts its machine key, and refuses its session key
   and its app password.
5. `AdminSecondFactorNotice::adminsWithoutSecondFactor()` leaves out a level-5
   machine account and still lists a level-5 person.
6. `Notify::audience_ids('admins')` still includes it.
7. Admin "Log in as user" refuses it and writes no `login_as` log row.
8. The edit page logic refuses marking your own account and refuses below level 10.

`plugins/server_manager/tests`: `setupApiCredentials()` creates a marked service
user, and marks an adopted one.

## Work packages

- **WP1 (core):** the column, `is_machine_account()`, the two session checks, the
  two API checks, login-as, the notice, admin edit and view, docs, tests.
- **WP2 (plugin):** ProvisioningSetup marking, migration sm_029, plugin doc, plugin test.
- **WP3 (live, after release):** mark getjoinery user 603. Confirm the notice is
  gone on getjoinery, the provisioning pipeline still works (next order or the
  setup page's API probe), and an alert still reaches info@.

## Version bumps

`includes/SessionControl.php` (1.23 → 1.24), `includes/ApiAuth.php` (1.10.0 → 1.11.0),
`includes/ApiAuthEndpoint.php` (1.8.0 → 1.9.0), `data/users_class.php`,
`includes/AdminSecondFactorNotice.php` (1.0 → 1.1),
`plugins/server_manager/includes/ProvisioningSetup.php` (1.10 → 1.11), and the
server_manager plugin version (`plugin.json`, 1.30.124 → 1.30.125) for the migration.
