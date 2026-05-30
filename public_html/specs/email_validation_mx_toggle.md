# Toggle: MX Lookup vs. Syntax-Only Email Validation

## Overview

Add one site setting that controls whether email validation performs a live
**DNS MX lookup** or stops at **RFC syntax validation**. Default preserves
today's behavior (MX check on). When off, an address that is syntactically valid
is accepted even if its domain has no MX/A record.

This is a small, platform-level change: one new core setting, one tiny policy
wrapper, and two call sites repointed at it.

## Motivation

Today every email validation does two steps — format check, then a **live DNS
MX-with-A-fallback lookup** (`DnsResolver::domainAcceptsMail()`). Two real
problems:

1. **Scaling / latency.** The MX step is a network round-trip on *every*
   user create, every email change, and every `IsValidEmail()` call. Under bulk
   import, migration, or high signup volume that is N synchronous DNS lookups in
   the request path — latency the operator may not want to pay, and load on the
   resolver.
2. **Some operators are fine with unroutable addresses.** Internal/test domains,
   split-horizon DNS, staged data, or simply "I don't care if it bounces" — the
   MX gate fails *closed* for reserved TLDs (e.g. `example.test`) and any domain
   that resolves to "no mail," blocking saves the operator would rather allow.

Syntax validation should always run (don't store non-addresses); only the MX
step is made optional.

## What exists today (grounding)

The MX lookup is funneled through a single function and reached from exactly two
call sites — there is no third path:

| Location | Role |
|----------|------|
| `DnsResolver::domainAcceptsMail($domain)` | the MX-with-A-fallback lookup itself (fail-open on DNS error) |
| `SystemBase::validateField()` — `case 'email'` (~line 1120) | model `save()` validation for any field with `'validation' => ['email' => true]` (e.g. `usr_email`) |
| `LibraryFunctions::IsValidEmail($email)` (~line 380) | standalone validity check; used by `data/users_class.php` and any caller wanting "is this a usable address" |

Both call sites compute `$domain` from the address and call
`DnsResolver::domainAcceptsMail($domain)`. That is the entire integration
surface. (`DnsResolver` is a low-level utility and must stay free of settings
dependencies — the toggle lives one layer up, in the email-validation policy.)

## Design — one policy wrapper, two repointed call sites

### 1. New setting (core, factory default)

Declare in `settings.json` at the `public_html/` root (seeded into
`stg_settings` by `update_database`; values are strings):

```json
{ "name": "email_validation_mx_check", "default": "1" }
```

- `"1"` (default) — current behavior: syntax **and** MX check. Backward
  compatible; no existing deployment changes behavior.
- `"0"` — syntax only; the MX/DNS step is skipped entirely (no DNS call made).

### 2. New policy wrapper — `LibraryFunctions`

Add one small static that is the *single* place the setting is consulted:

```php
/**
 * Email-domain deliverability gate honoring the email_validation_mx_check
 * setting. Returns true (accept) without any DNS lookup when the MX check is
 * disabled; otherwise delegates to the DnsResolver MX-with-A-fallback check.
 * This is the one chokepoint both email-validation paths funnel through.
 */
static function emailDomainAcceptsMail(string $domain): bool {
    $settings = Globalvars::get_instance();
    if ((string)$settings->get_setting('email_validation_mx_check') === '0') {
        return true; // syntax-only mode: skip the DNS round-trip
    }
    require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
    return DnsResolver::domainAcceptsMail($domain);
}
```

Rationale for placing the policy here (not inside `DnsResolver`): the question
"should validation require a deliverable domain?" is an email-validation policy,
not a DNS concern. Keeping `DnsResolver::domainAcceptsMail()` a pure utility
means it stays callable when a caller genuinely *wants* a DNS answer regardless
of the validation setting.

### 3. Repoint the two existing call sites at the wrapper

- `LibraryFunctions::IsValidEmail()` — replace its
  `return DnsResolver::domainAcceptsMail($domain);` with
  `return self::emailDomainAcceptsMail($domain);`.
- `SystemBase::validateField()` `case 'email'` — replace
  `if (!DnsResolver::domainAcceptsMail($domain))` with
  `if (!LibraryFunctions::emailDomainAcceptsMail($domain))`.

That is the complete behavioral change. Because both paths now route through the
wrapper, every downstream caller (model `save()` on `usr_email`, `IsValidEmail()`
users, etc.) inherits the toggle with no further edits. Any *future* email
validation must call the wrapper, never `domainAcceptsMail()` directly — note
this in the validation docs so the invariant holds.

### 4. Surface the setting in admin

Add the toggle to the Email settings tab (`adm/admin_settings_email.php`),
following that page's existing FormWriter pattern — a checkbox/boolean labelled
e.g. **"Verify email domains accept mail (MX lookup)"** with help text:
"On (default): reject addresses whose domain has no mail server. Off: accept any
syntactically valid address without a DNS lookup — faster for bulk imports, and
allows internal/unroutable domains." No bespoke UI; reuse the standard settings
form mechanics.

## Files

### To modify
| File | Change |
|------|--------|
| `settings.json` (public_html root) | declare `email_validation_mx_check` default `"1"` |
| `includes/LibraryFunctions.php` | add `emailDomainAcceptsMail()`; repoint `IsValidEmail()` at it; bump `@version` |
| `includes/SystemBase.php` | `case 'email'` uses `LibraryFunctions::emailDomainAcceptsMail()`; bump `@version` |
| `adm/admin_settings_email.php` | add the boolean setting to the form; bump `@version` |
| `docs/validation.md` | document the setting + the "always call the wrapper" invariant |

### Unchanged
- `includes/DnsResolver.php` — stays a pure DNS utility; `domainAcceptsMail()`
  is untouched and still available for callers that want a true DNS answer.

### Schema / migrations
None. The setting is seeded declaratively from `settings.json`.

## Testing

A focused core test (`tests/` — e.g. `tests/integration/email_validation_toggle_test.php`):

- With `email_validation_mx_check = '1'`: a syntactically valid address on a
  no-MX domain (`someone@example.test`) is **rejected** —
  `LibraryFunctions::IsValidEmail()` returns false, and a `User` save with that
  `usr_email` throws the deliverability error (current behavior preserved).
- With `email_validation_mx_check = '0'`: the same address is **accepted** —
  `IsValidEmail()` returns true and the model save passes; assert **no DNS
  lookup** is performed (syntax-only path).
- In both modes, a clearly malformed address (`not-an-email`) is still rejected
  (syntax validation is never skipped).
- Restore the setting to its prior value in teardown.

Run `php -l` and `validate_php_file.php` on every modified PHP file.

## Documentation

- In `docs/validation.md`, under email validation: describe the two modes, the
  `email_validation_mx_check` setting, when to disable it (bulk import,
  internal/unroutable domains, latency-sensitive flows), and the rule that **all
  email-domain deliverability checks must go through
  `LibraryFunctions::emailDomainAcceptsMail()`**, never `domainAcceptsMail()`
  directly, so the toggle is always honored.
- Cross-reference the setting from `docs/settings.md` if it maintains a setting
  index.

## Versioning

- Bump `@version` on `LibraryFunctions.php`, `SystemBase.php`, and
  `admin_settings_email.php`.
- No plugin version bump (core change).

## Out of scope

- **Per-form or per-field override** of the mode — this is a single site-wide
  policy. A field-level option could layer on later via the existing
  `'validation' => ['email' => ...]` array if ever needed.
- **Async/queued MX verification** or a "warn but allow" middle state — the
  toggle is binary (check / syntax-only). Both are possible future enhancements
  on top of this seam.
- **Caching MX results** — a separate performance concern in `DnsResolver`,
  independent of this toggle.
- **Changing `DnsResolver::domainAcceptsMail()` semantics** (still fail-open on
  DNS error).
```
