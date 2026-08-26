# Admin Second-Factor Management

**Goal:** Superadmins get a recovery lever on the admin user page
(`/admin/admin_user?usr_user_id=N`): remove a passkey, disable TOTP, sign out
trusted devices. When the viewed user is the admin themselves, a link to
enroll new factors. A user stripped of a factor that a feature requires
(Fortress, an encrypted vault) is forced to enroll a replacement on their
next page load before anything else is reachable.

This spec is implementation-ready: the code facts below were verified against
the tree on 2026-08-26; the policy decisions are final — do not re-litigate
them during implementation.

---

## Verified code facts (read these files before starting)

| Fact | Where |
|---|---|
| Admin user page POST actions are handled in the logic file, inside `LibraryFunctions::isFormSubmission()`, *before* the `AdminUserPanelRegistry::handlePost()` dispatch | `adm/logic/admin_user_logic.php` ~line 69–91 |
| `AdminPage::action_button()` emits a tokenless single-button POST form (the sanctioned FormWriter exception); supports `confirm` and `confirm_typed` (typed-phrase modal) | `includes/PublicPageBase.php:1173` |
| `PasskeyService::revoke(int $credential_id, User $actor)` loads via `_loadOwnedPasskey($credential_id, $actor)` — actor must own the credential; runs `self::$pre_revoke_callbacks` then `soft_delete()` then post-revoke callbacks | `includes/PasskeyService.php:608` |
| The vault registers a pre-revoke veto → `VaultUnlock::assertRevocationSafe()` and a post-revoke cleanup → `VaultUnlock::cleanupRevokedCredential()` | `includes/VaultUnlock.php:722–727` |
| `assertRevocationSafe()` throws `PasskeyRevocationVetoException` for **two distinct reasons**: (a) the stranding floor — last live passkey wrapping with <3 unused recovery codes (delegates to `assertWrappingDeleteSafe()`, `VaultUnlock.php:805`); (b) the possession-factor invariant — vault exists, TOTP off, zero remaining live passkeys | `includes/VaultUnlock.php:736+` |
| Unused recovery codes = `uew` wrappings with `uew_unlocker_type === UserEncryptionWrapping::TYPE_RECOVERY` and `uew_is_used` false | `includes/VaultUnlock.php:833` |
| `User::disable_totp()` clears all four TOTP fields **and** rotates `usr_second_factor_hmac_key` (kills every trusted-device cookie) | `data/users_class.php:765` |
| `User::rotate_second_factor_hmac_key()` — trusted-device sign-out alone | `data/users_class.php:755` |
| Fortress enrollment gate lives inside `SessionControl::check_permission()`; posture is session-cached (`$_SESSION['max_security_level']`) but the factor check is a **live read**, so it fires on the next page load after a factor change. Exempt paths: `/profile/security`, `/setup`, `/logout`, prefix `/api/v1/` | `includes/SessionControl.php:1563–1572`, helper at `:1606` |
| `SessionControl::require_recent_second_factor(string $return_url, int $ttl = 300)` returns a `LogicResult` redirect to `/verify-stepup` or `null` to proceed. **It is a no-op when the acting user has no second factor** — see step 4's extra guard | `includes/SessionControl.php:1308` |
| Security-alert email pattern: `EmailSender::quickSend($to, $subject, $body)` in try/catch, failure logged, never blocks the action | `logic/password_edit_logic.php:59–73` |
| `VaultUnlock::lockAll($user_id)` — the credential-event kill switch for every session's unlock window | `includes/VaultUnlock.php` |
| Passkey display metadata: `PasskeyService::listCredentials(User $user): MultiPasskey` (`PasskeyService.php:602`); `Passkey::vault_capability(): string` (`data/passkeys_class.php:134`); `User::has_totp_enabled()` (`data/users_class.php:686`); backup codes = JSON array in `usr_totp_backup_codes` |
| Admin-credential-action precedent: `/admin/admin_users_password_edit`, test precedent `tests/account_security/admin_password_reset_test.php` |

## Policy decisions (final)

1. **The stranding floor is absolute.** No admin override, no force flag,
   ever. A stranded client-custody vault is unrecoverable data loss; the
   locked-out-vault story is the managed-recovery path (separate spec).
2. **The possession-factor invariant is warned, not refused, on the admin
   path.** Kept strictly, its two refusals deadlock a user who lost
   everything (TOTP-off refused with no passkey; last-passkey-revoke refused
   with TOTP off). The admin may knowingly remove the final factor because
   the re-enrollment gate (step 3) closes the exposure at the user's next
   page load, and recovery-code vault unlock still requires a step-up that
   is only satisfiable after a new factor is enrolled.
3. **No admin enrollment surface, ever.** Adding a factor is self-only via
   the existing `/profile/security` ceremonies with their guards intact
   (first passkey = account password; additional = fresh step-up).
4. **Acting admin must be permission 10, must themselves have a second
   factor, and must pass a fresh step-up.** The step-up gate alone is
   insufficient because it no-ops for factorless accounts.
5. **CSRF posture:** action buttons are the platform's sanctioned tokenless
   single-button forms (SameSite=Lax + POST). Do not add `validateCSRF()`
   here; the step-up requirement is the strong gate.

---

## Implementation steps

### Step 1 — `includes/PasskeyService.php`: context-aware revocation + admin path

1a. Extend the pre-revoke callback invocation with a third `$context` array
argument (PHP user-defined callables silently accept extra args, so existing
2-param callbacks keep working):

```php
// in revoke():
foreach (self::$pre_revoke_callbacks as $callback) {
    call_user_func($callback, (int)$actor->key, $credential_id, []);
}
```

1b. Add `adminRevoke()` — same body as `revoke()` but the credential is
loaded as owned by the **target**, and the context marks the admin path:

```php
/**
 * Superadmin-initiated revocation of another user's credential. Runs the
 * same pre/post-revoke registries as revoke(); pre-revoke callbacks receive
 * ['admin_reset' => true] so policy vetoes that a forced reset may
 * knowingly accept (the possession-factor invariant) can distinguish
 * themselves from vetoes that are absolute (the stranding floor).
 * $acting_admin is logged, never authorized here — the caller gates.
 */
public function adminRevoke(int $credential_id, User $target, User $acting_admin): void
```

Body: `_loadOwnedPasskey($credential_id, $target)`; pre-revoke loop passing
`['admin_reset' => true]`; `soft_delete()`; post-revoke loop (unchanged
2-arg call); one `error_log` line (see step 6 format).

Update the docblock of `revoke()`/callback registry to document the third
parameter. Update `docs/passkeys.md` § revocation registry accordingly
(current-state wording only).

### Step 2 — `includes/VaultUnlock.php`: split the two veto reasons

Change `assertRevocationSafe(int $user_id, int $credential_id)` to
`assertRevocationSafe(int $user_id, int $credential_id, array $context = [])`
and update the registration closure at `VaultUnlock.php:722` to pass the
third arg through.

Inside: the **stranding floor** branch (delegation to
`assertWrappingDeleteSafe()`) runs unconditionally — unchanged. The
**possession-factor invariant** branch (TOTP off + zero remaining passkeys)
is skipped when `!empty($context['admin_reset'])`.

### Step 3 — `includes/SessionControl.php`: the vault re-enrollment gate

3a. Add:

```php
/**
 * A vault holder with zero second factors is a state unreachable through
 * self-service (the possession-factor invariant refuses both removal
 * orders) — it exists only after an admin factor reset. Gate navigation
 * until a factor is enrolled, mirroring must_enroll_2fa_for_fortress():
 * vault existence is session-cached, the factor check stays live so
 * enrolling clears the gate immediately.
 */
function must_enroll_2fa_for_vault() { ... }
```

Mechanics: return `false` with no `usr_user_id`. Cache vault existence in
`$_SESSION['has_encryption_vault']` (bool) on first computation —
`MultiUserEncryptionVault(['user_id' => ...])`, `count() > 0`, wrapped in
try/catch defaulting false, mirroring the `max_security_level` block at
`:1610–1624`. When true: `$user = new User($_SESSION['usr_user_id'], true);
return !$this->user_has_second_factor($user);`

3b. In `check_permission()`, insert **directly after** the Fortress gate
block (`:1563–1572`), same shape, same exempt paths (`/profile/security`,
`/setup`, `/logout`, `/api/v1/` prefix — the API exemption is what lets
enrollment work), ordered after Fortress so a user who is both gets the
stricter message:

```php
if ($this->must_enroll_2fa_for_vault()) {
    ... redirect to /profile/security?msgtext=urlencode(
    'An administrator reset your two-factor sign-in. Your encrypted vault '
    . 'requires a second factor — add a passkey or an authenticator app '
    . 'to continue.') ...
}
```

Note the Fortress gate requires an *independent* factor
(`user_has_independent_second_factor`); this gate requires *any* factor
(`user_has_second_factor`). That difference is intentional.

### Step 4 — `adm/logic/admin_user_logic.php`: the three POST actions

Inside the existing `LibraryFunctions::isFormSubmission()` block, **before**
the `AdminUserPanelRegistry::handlePost()` dispatch, add handlers for
`$input['action']` values `admin_remove_passkey`, `admin_disable_totp`,
`admin_revoke_trusted_devices`.

**Shared guard, first thing in each handler** (factor into a small local
helper in the same file, e.g. `_admin_2fa_action_gate($session)` returning a
`LogicResult` to bubble up or `null` to proceed):

1. `$session->check_permission(10);`
2. Acting admin must have a factor:
   ```php
   $acting = new User($session->get_user_id(), TRUE);
   if (!$session->user_has_second_factor($acting)) {
       // DisplayMessage error: 'Enroll a second factor on your own account
       // before resetting anyone else's.', redirect back to the user page
   }
   ```
3. ```php
   $stepup = $session->require_recent_second_factor(
       '/admin/admin_user?usr_user_id=' . $user->key);
   if ($stepup) { return $stepup; }
   ```
4. Target must be live: refuse when `$user->get('usr_delete_time')` is set.

**`admin_remove_passkey`** (`pkc_passkey_credential_id` in `$input`):

```php
try {
    $svc = new PasskeyService(); // match how existing logic files construct it — check passkey_revoke_logic.php
    $svc->adminRevoke((int)$input['pkc_passkey_credential_id'], $user, $acting);
} catch (PasskeyRevocationVetoException $e) {
    // DisplayMessage error: $e->getMessage() . ' This would permanently
    // strand the user\'s encrypted data. There is no override.'
    // redirect back; NO mutation happened
}
VaultUnlock::lockAll($user->key);
$user->rotate_second_factor_hmac_key();
// alert email + [ADMIN_2FA_RESET] log line (steps 5, 6)
// DisplayMessage success; redirect to /admin/admin_user?usr_user_id=N
```

(Read `logic/passkey_revoke_logic.php` first and mirror its service
construction and exception type imports exactly.)

**`admin_disable_totp`**: no confirmation code required (the user lost it —
that is the point; the acting admin's step-up replaces it). Refuse if
`!$user->has_totp_enabled()`. Then `$user->disable_totp();` (already rotates
the HMAC key), `VaultUnlock::lockAll($user->key);`, email + log, redirect.
Do **not** replicate the self-service invariant refusal from
`logic/security_logic.php:293` — policy decision 2.

**`admin_revoke_trusted_devices`**: `$user->rotate_second_factor_hmac_key();`
email + log, redirect. No lockAll (factors untouched).

### Step 5 — `adm/logic/admin_user_logic.php` + `adm/admin_user.php`: the Security card

**Logic:** load into `$page_vars['security']` (permission-10 viewers only;
otherwise leave unset and render nothing):

- `totp_enabled` (`$user->has_totp_enabled()`), `totp_enabled_time`
  (display via `$user->get_local('usr_totp_enabled_time')`),
  `backup_code_count` (count of `json_decode($user->get('usr_totp_backup_codes') ?? '[]', true)`)
- `passkeys`: iterate `(new PasskeyService(...))->listCredentials($user)` —
  label, `get_local` created/last-used, `vault_capability()`
- `is_self`: `$session->get_user_id() === $user->key`
- Posture facts for the card's plain-language line:
  - `fortress`: `InboundEmailDomain::maxSecurityLevelForUser($user->key) === 'fortress'`,
    guarded by the plugin-class availability pattern used in
    `SessionControl::must_enroll_2fa_for_fortress()` (`:1612–1623`) — the
    mailbox plugin may be inactive
  - `vault_count`: `MultiUserEncryptionVault(['user_id' => $user->key])->count()`
  - `unused_recovery_codes`: count `MultiUserEncryptionWrapping` rows across
    the user's vaults with `uew_unlocker_type === UserEncryptionWrapping::TYPE_RECOVERY`
    and falsy `uew_is_used` (the counting rule from
    `VaultUnlock::assertWrappingDeleteSafe()`, `VaultUnlock.php:817–837`)

**View:** a card in `adm/admin_user.php` matching the existing card markup
(see the Groups card, `:171–199`). Contents:

- Posture line first, plain language, from the loaded facts — e.g. "This
  user holds a Fortress domain: an independent second factor is mandatory;
  removing factors locks them to the enrollment page until they re-enroll."
  / "This user has an encrypted vault; N unused recovery codes remain." /
  "No feature on this account requires a second factor."
- TOTP row: status + enabled date + backup-code count; if enabled, an
  `AdminPage::action_button('Disable TOTP', '/admin/admin_user', [...])`
  with `hidden` `['action' => 'admin_disable_totp', 'usr_user_id' => $user->key]`.
- One row per passkey: label, created, last used, vault capability, and a
  Remove `action_button` (`action` `admin_remove_passkey`, plus
  `pkc_passkey_credential_id` and `usr_user_id` hidden).
- Trusted-devices row: "Sign out trusted devices" `action_button`.
- **Confirm escalation:** plain `confirm` for ordinary removals. When the
  action would leave a vault holder with zero factors (computable from the
  loaded card facts: this is the last passkey and TOTP is off, or this is
  TOTP-disable and no live passkeys), use `confirm_typed` with phrase
  `RESET` and message: "This user's vault will be protected by memorized
  secrets only until they enroll a new factor. They will be required to
  enroll one at their next sign-in."
- When `is_self`: a plain link (GET is correct — it performs nothing) to
  `/profile/security`: "Add a passkey or set up TOTP on your own account."

No `admin_menus.json` change — this extends the existing user page.

### Step 6 — Alert email + log line (inside each step-4 handler)

Email, mirroring `logic/password_edit_logic.php:59–73` (try/catch,
`EmailSender::quickSend`, failure logged and never blocking): to
`$user->get('usr_email')`, subject `{site_name} security alert`, body naming
what was removed ("A site administrator removed a passkey from your account"
/ "...disabled two-factor authentication on your account" / "...signed out
your trusted devices") + "If you did not request this, contact us and change
your password immediately." Sent unconditionally — it is the victim's only
signal in the compromised-admin scenario.

Log line (greppable): `error_log('[ADMIN_2FA_RESET] action=<action>
admin=<acting id> target=<target id> credential=<id|-> result=<done|vetoed>')`
— write the `vetoed` line in the catch branch too.

### Step 7 — Tests: `tests/account_security/admin_second_factor_reset_test.php`

Use the shared harness (`harness_boot()`, `section()`, `check()`,
`harness_finish()`) with an `@joinery-test` header; copy the header shape
and DB-fixture approach from `tests/account_security/admin_password_reset_test.php`
(same tier and env as that file — it is the closest precedent). The test
database has no content rows; create every user/passkey/vault fixture
in-test. Cover:

1. Callback plumbing: register probe pre/post-revoke callbacks;
   `adminRevoke()` fires both, pre-revoke receives
   `['admin_reset' => true]` as its third arg, and the credential row is
   soft-deleted (`pkc_delete_time` set), not gone.
2. Stranding floor: vault user, one live vault-capable passkey wrapping,
   <3 unused recovery-code wrappings → `adminRevoke()` throws
   `PasskeyRevocationVetoException`, passkey still live.
3. Invariant bypass: vault user, TOTP off, one live passkey, ≥3 unused
   recovery codes → `adminRevoke()` with admin context succeeds; plain
   `revoke()` as the owner on the same fixture is vetoed.
4. `disable_totp()` effect: all four TOTP fields cleared and
   `usr_second_factor_hmac_key` changed.
5. Vault gate: vault holder with zero factors →
   `must_enroll_2fa_for_vault()` true; enroll TOTP → false. (Set up
   `$_SESSION` the way `second_factor_divert_test.php` or `stepup_test.php`
   does — follow the existing session-fixture pattern in this directory.)
6. Fortress gate still holds: the gate helper reads factors live — assert
   `must_enroll_2fa_for_fortress()` flips true when the fixture Fortress
   user's independent factor is removed via the admin path.
7. Acting-admin gate unit: `require_recent_second_factor()` returns null
   for a factorless acting user (documents why the handler adds the
   explicit own-factor refusal).

Logic-handler HTTP-level checks (permission refusal, step-up redirect) are
covered by asserting the gate helpers directly as above — do not spin up a
web client.

### Step 8 — Documentation (current-state wording only; no "now"/"previously")

- `docs/account_security.md`: add an "Administrative factor reset" section —
  who (superadmin with own factor + fresh step-up), what (remove passkey /
  disable TOTP / sign out trusted devices), the absolute stranding floor,
  the admin-path invariant exception and why it is sound (the re-enrollment
  gates), the vault re-enrollment gate alongside the Fortress gate
  description, the alert email.
- `docs/passkeys.md`: revocation section — `adminRevoke()`, the third
  context argument to pre-revoke callbacks, and that the floor ignores
  context while the invariant honors `admin_reset`.

## Validation checklist (executor runs all)

1. `php -l` on every touched PHP file.
2. `php maintenance_scripts/dev_tools/validate_php_file.php <file>` on every
   touched file — investigate every flag. (Do not run it on `utils/`-style
   executable scripts; everything touched here is class/logic/view code.)
3. `php tests/run.php safe` — green.
4. New test file: run the whole tier it declares via `tests/run.php` (never
   leave it undeclared; the runner flags headerless test-looking files).
5. Browser spot-check on dev (`https://dev.getjoinery.com/admin/admin_user?usr_user_id=1`):
   card renders, buttons POST, veto message displays, step-up redirect
   round-trips back to the user page.

## Out of scope

- Admin enrollment of factors for another user (never).
- Any override of the vault-stranding floor (never).
- Viewing or exporting credential secrets, recovery codes, TOTP secrets.
- Bulk reset across users.
- Managed/assisted vault recovery (sentinel managed recovery spec).
