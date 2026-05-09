# Terms Acceptance Capture

**Status:** Active
**Created:** 2026-05-09
**Priority:** Medium

## Problem

The platform has multiple ways an account can come into existence, only some of which collect terms acceptance:

| Path | Capture today? |
|---|---|
| `/register` | Yes — privacy checkbox in `views/register.php` |
| `/lists`, `/list`, `/event_waiting_list` | Yes — privacy checkbox in each view |
| `/cart` (billing user, after `cart_billing_streamlining.md`) | Yes — implicit consent under Continue button |
| `/profile/account_edit` (rare new-user branch) | Implicit — caller is already authenticated, accepted at signup |
| `cart_charge_logic.php:202` (gift/event recipient auto-create) | **No** — recipient never visited the cart |
| `adm/logic/admin_user_add_logic.php` | **No** — admin creates the account; if a password is set and "send activation" is unchecked, the user can log in directly |
| `adm/admin_orders.php`, `adm/admin_stripe_orders.php`, `adm/admin_stripe_invoices.php`, `adm/admin_shadow_sessions.php`, `adm/logic/admin_order_logic.php`, `plugins/bookings/admin/admin_bookings.php`, `utils/stripe_charges_synchronize.php` | **No** — back-office record creation; users never log in via these records as a primary onboarding path, but if they later set a password they would |

The two pathways that produce a user who can authenticate without ever passing through a consent UI are recipient auto-create and admin-add-user-with-password. The other admin/back-office paths produce records that may or may not become login-capable later, but are caught by the same gate as long as it covers the two interactive ways into the site (password-set flow and login form).

## Goal

Capture terms acceptance on every authenticated session. Stamp once at the moment of consent (existing implicit/explicit gates, plus the new gates this spec adds). Once stamped, never re-prompt. Treat "user has logged in successfully under the old code" as evidence of past acceptance and backfill, so the rollout doesn't surprise existing users.

## Schema

Add one column to `usr_users`:

```
'usr_terms_accepted_time' => array('type'=>'timestamp(6)'),
```

Nullable. NULL means "no acceptance on file." A timestamp captures *when* acceptance occurred. We don't track which version of the terms was accepted — if terms change materially in a way that requires re-acceptance, that's a separate spec that would clear or compare against this column.

## Write paths (where to stamp the timestamp)

The rule: stamp only when the user themselves clicks through a UI that displays a Terms / Privacy reference. Never stamp on behalf of a user from a server-side path they didn't see.

| Path | Action |
|---|---|
| `/register` (`register_logic.php:110`) | Stamp on successful submit. The view already requires the privacy checkbox. |
| `/lists`, `/list`, `/event_waiting_list` user-create branches | Stamp when the privacy checkbox is checked and the user is newly created. |
| `/cart` billing user submit (cart_charge_logic billing user branch) | Stamp on `User::CreateCompleteNew` for the billing user *only*. The recipient call at line 202 must NOT stamp. |
| `/password-set` (`password_set_logic.php`) | Stamp on successful submit (gate added — see below). |
| `/password-reset-2` (`password_reset_2_logic.php`) | Stamp on successful submit *only when* the user's `usr_terms_accepted_time IS NULL` and the gate UI was shown (see below). Never overwrite an existing timestamp. |
| New post-login interstitial (`/terms-accept`) | Stamp on submit. |

`account_edit_logic.php` does not need modification: its new-user branch is reached only when an already-authenticated user changes their email to a non-existing one. That user already accepted upstream.

## Gate paths (where to enforce when timestamp is NULL)

Three places. The first two are the interactive password set/reset flows — sufficient for the recipient auto-create case. The third is the login form interstitial — defense-in-depth for the admin-set-password case and any future user-creation path that bypasses checkout consent.

### Gate 1: `/password-set`

Render the same implicit-consent copy used at /cart, but as a **required checkbox** since this user has no other touchpoint for consent:

```
[ ] I agree to the [Terms of Use](/terms) and [Privacy Policy](/privacy).
```

Position above the Set Password button. Server-side: reject the submit if checkbox not checked. Stamp `usr_terms_accepted_time = now()` alongside the password write.

If the user already has `usr_terms_accepted_time` set (rare — they backfilled at /register or by login interstitial before reaching here), the checkbox is hidden.

### Gate 2: `/password-reset-2`

Same pattern as Gate 1. Render the checkbox conditionally — only if `usr_terms_accepted_time IS NULL`. Existing users resetting their password don't see it. New (unactivated, no-password) users following the reset-as-activation path see it.

The conditional render requires looking up the user from `act_code`. `Activation::ActivateUser` already does that — pull the user up earlier in the logic to decide whether to show the gate, then complete activation as normal.

### Gate 3: Login-form interstitial

Pattern follows the existing `usr_force_password_change` precedent (`SessionControl.php:1003-1010`). After successful login, if `usr_terms_accepted_time IS NULL`, redirect every page request to `/terms-accept` until the user submits.

Add to `SessionControl::check_permission()` immediately after the existing `must_change_password()` check:

```php
if ($this->must_accept_terms()) {
    $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($current_path !== '/terms-accept' && $current_path !== '/logout') {
        header('Location: /terms-accept');
        exit();
    }
}
```

`must_accept_terms()` returns true when `$_SESSION['user_id']` is set and the user's `usr_terms_accepted_time` is NULL. Stash the value on session at login (alongside the existing `force_password_change` stash at line 1059) to avoid a per-request DB hit.

`/terms-accept` view: a single checkbox + Continue button + the same Terms/Privacy links. On submit, stamp the timestamp, refresh the session-cached value, and redirect to the originally-requested URL (or `/profile`).

## Backfill

Anyone who has actually logged in under the old code is "implicitly accepted by past behavior." The authoritative signal is the `log_logins` table — a row in it (for a non-logout login type) means the user authenticated as themselves at least once.

`usr_lastlogin_time` looks tempting but is unreliable: a current snapshot shows 4305 total users with only 1564 having a real `log_logins` entry. The other 2741 have non-null `usr_lastlogin_time` despite never logging in, because SystemBase applies the `default => 'now()'` from `field_specifications` on insert. So we use `log_logins` instead.

Login types defined in `data/login_class.php`:

```
LOGIN_FORM             = 1
LOGIN_COOKIE           = 2
LOGIN_LOGOUT           = 3
LOGIN_FACEBOOK_CONNECT = 4
```

Backfill (one-time data migration in `/migrations/` — not a schema migration; the column is added by the data class `field_specifications` updater):

```sql
UPDATE usr_users
SET usr_terms_accepted_time = usr_signup_date::timestamp
WHERE usr_terms_accepted_time IS NULL
  AND EXISTS (
      SELECT 1 FROM log_logins
      WHERE log_usr_user_id = usr_users.usr_user_id
        AND log_login_type IN (1, 2, 4)
  );
```

We stamp the timestamp to `usr_signup_date` rather than the user's first real login: the timestamp records *that* consent is on file, not the precise moment, and signup date is more durably available (a future log retention policy could trim `log_logins`).

Users with zero non-logout `log_logins` entries are left with NULL `usr_terms_accepted_time` — they hit the gate on first interactive session. This correctly captures recipient auto-creates and admin-creates that have never been used to log in.

## Side-fix: `usr_lastlogin_time` accuracy

While auditing the backfill signal, we discovered `usr_lastlogin_time` is populated on every insert (via SystemBase applying the `default => 'now()'` from `field_specifications`), not just on real logins. That makes it useless for "has this user ever authenticated?" — and worse, it's silently wrong everywhere else too: a recipient auto-create user shows up as if they had a recent login.

The column is read nowhere in code (grep across `public_html/`) — only `LoginClass::StoreUserLogin` writes it on real login events, and the field spec declares it. So fixing it is low-risk:

1. Remove `'default' => 'now()'` from `usr_lastlogin_time`'s entry in `data/users_class.php:79`. New users inserted via `User::CreateNew` will land with NULL until `LoginClass::StoreUserLogin` updates them on first authentication. Existing behavior on actual logins is unchanged.
2. As part of the same migration that backfills `usr_terms_accepted_time`, clean up the existing fictional values:

```sql
UPDATE usr_users
SET usr_lastlogin_time = NULL
WHERE NOT EXISTS (
    SELECT 1 FROM log_logins
    WHERE log_usr_user_id = usr_users.usr_user_id
      AND log_login_type IN (1, 2, 4)
);
```

Users with real `log_logins` entries are left alone — `LoginClass::StoreUserLogin` has been overwriting their `usr_lastlogin_time` on every login, so the existing value is correct. Users with no real entries are scrubbed to NULL, which now correctly means "never authenticated."

After this fix, `usr_lastlogin_time IS NULL` becomes a usable signal for "has not yet logged in." Worth documenting in any future admin report or analytics work.

## Files touched

- `data/users_class.php` — add `usr_terms_accepted_time` to `$field_specifications`; remove the `'default' => 'now()'` from `usr_lastlogin_time`.
- `migrations/migration_<n>_terms_accepted_backfill.php` — one-time backfill of both `usr_terms_accepted_time` (stamp) and `usr_lastlogin_time` (NULL out fictional values).
- `logic/register_logic.php` — stamp on successful create.
- `logic/lists_logic.php`, `logic/list_logic.php`, `logic/event_waiting_list_logic.php` — stamp on successful create.
- `logic/cart_charge_logic.php` — stamp the *billing* user only on `CreateCompleteNew`. The recipient call at line 202 stays untouched.
- `logic/password_set_logic.php` — render gate, validate, stamp.
- `views/password-set.php` — conditional checkbox.
- `logic/password_reset_2_logic.php` — render gate (conditional), validate, stamp.
- `views/password-reset-2.php` — conditional checkbox.
- `includes/SessionControl.php` — add `must_accept_terms()`, wire into `check_permission()` and `store_session_variables()`.
- `views/terms-accept.php` + `logic/terms_accept_logic.php` — new interstitial page.
- `serve.php` — only if a route entry is needed (probably not — `/terms-accept` will auto-resolve to the view).

## Edge cases

- **User who hits the password-set gate, accepts terms, then later resets their password.** The reset-2 gate sees a non-null timestamp, hides the checkbox, never re-stamps. Correct.
- **User who logs in successfully (interstitial backfills no row), but the deploy clears the session before they submit `/terms-accept`.** Next login re-enters the interstitial. Correct.
- **API-authenticated requests.** `check_permission` is called for cookie-session paths. API key auth bypasses session entirely; API-only users never hit a UI gate. Out of scope: an API client that has never accepted terms is the API key owner's responsibility.
- **OAuth / social login.** Not currently in the codebase. If added later, add to the write-paths table.
- **Admin viewing /admin while their own `usr_terms_accepted_time` is NULL** (e.g., very early founder account, predates schema). Backfill catches them via `usr_lastlogin_time IS NOT NULL`.
- **User in the middle of a 2FA verify (`/verify-totp`).** The interstitial fires after `store_session_variables` completes. 2FA verify completes session setup before redirect, so the interstitial fires on the next page load — fine.
- **Hooks/server-render paths that call `check_permission`.** They'll redirect to /terms-accept just like browser requests. AJAX endpoints called from a logged-in session would receive the redirect as their JSON response — acceptable (consent is required before continuing). If any AJAX flow needs to function without the gate, add it to the exempt-paths list alongside `/logout`.

## Test plan

- [ ] Schema column lands and is queryable.
- [ ] Backfill populates existing users with `usr_lastlogin_time IS NOT NULL`.
- [ ] Recipient auto-create user has NULL `usr_terms_accepted_time` after a gift checkout completes.
- [ ] Recipient receives activation email, clicks link, lands on `/password-reset-2` with the consent checkbox visible. Submit without checkbox: error. Submit with checkbox: stamp + password set + redirect to login.
- [ ] Same recipient logs in. No interstitial (timestamp is now set).
- [ ] Admin adds user via `/admin/admin_user_add` with a password and "send activation" unchecked. Admin shares the password. User logs in → interstitial fires. Submit → stamp + redirect.
- [ ] Existing logged-in user (backfilled) logs in: no interstitial.
- [ ] New user signs up via /register: privacy checkbox required (existing), stamp recorded on submit.
- [ ] Guest checkout (cart): billing user gets stamp via `cart_charge_logic`. Recipient on the same order does not.
- [ ] AJAX endpoints under a session with NULL timestamp: receive redirect to /terms-accept (verify the response is non-fatal for the client; flag any flow that needs an exempt-path entry).
- [ ] `php -l` and `validate_php_file.php` clean on all touched files.
- [ ] `/terms-accept` cannot be bypassed via direct navigation to other paths (interstitial enforced).
- [ ] After accepting, the user lands on the originally-requested URL.
