# Terms & Privacy URL Settings

**Status:** Active
**Created:** 2026-05-09
**Priority:** Medium

## Problem

The platform displays "Terms of Use" and "Privacy Policy" links in several places — registration, mailing list signup, event waiting list, the cart's implicit-consent copy, the upcoming `/password-set` and `/terms-accept` consent gates from `terms_acceptance_capture.md`, and theme footers. Today every one of those consumers hardcodes `/terms` and `/privacy` as the link target.

Neither view exists. `/terms` and `/privacy` 404 on the test site. Some deployments do have a `pag_pages` row at slug `privacy-policy` (verified — `/page/privacy-policy` returns 200), but no consumer points there. Others may host their legal docs externally and want the links to go to a different domain entirely.

We need each deployment to be able to point those links wherever their actual policy documents live, without modifying view code.

## Goal

Two new site settings that hold URLs. Every existing hardcoded `/terms` / `/privacy` reference reads from those settings via a helper function. When a setting is empty, the consent UI degrades gracefully — the link disappears and the surrounding copy adapts. No deployment is ever shipped with a link pointing to a 404.

## Non-goals

- Hosting terms/privacy content. The platform already has a Pages CMS (`pag_pages`); deployments that want to host their own docs internally use that. We don't add a new "legal documents" CMS or seed default content.
- Multi-locale terms. If a deployment ships terms in multiple languages, that's a localization-layer concern; the setting holds a single URL.
- Versioning of terms (covered by `terms_acceptance_capture.md` open-question handling).
- Cookie / consent banner functionality. Out of scope; this spec only addresses link targets for the existing acceptance flows.

## Settings

Add to `settings.json`:

```
{ "name": "terms_url",   "default": "" },
{ "name": "privacy_url", "default": "" }
```

Empty by default — a fresh install is link-less until an admin configures it. The admin settings UI (`/admin/admin_settings`) gets a short help string for each: "URL of your Terms of Use page. Leave empty to omit. Common values: `/page/terms-of-use` (internal Pages CMS), or an external URL like `https://example.com/terms`."

## Helper API

Add to a low-cost shared module — `LibraryFunctions` is the natural home:

```php
LibraryFunctions::terms_url()    // returns string or null
LibraryFunctions::privacy_url()  // returns string or null
```

Implementation: read the corresponding setting via `Globalvars::get_instance()->get_setting(...)`, return null if empty/whitespace, else the trimmed URL. Cheap; no caching needed beyond the settings singleton's existing cache.

Also add a render helper for consent copy:

```php
LibraryFunctions::consent_copy(string $action_verb = 'continuing'): string
```

Returns one of:
- Both URLs set: `By {action_verb}, you agree to our <a href="...">Terms of Use</a> and <a href="...">Privacy Policy</a>.`
- Only privacy set: `By {action_verb}, you agree to our <a href="...">Privacy Policy</a>.`
- Only terms set: `By {action_verb}, you agree to our <a href="...">Terms of Use</a>.`
- Neither set: empty string.

Output is HTML-safe — link text is constant, only the URL is variable, and URLs are output via `htmlspecialchars($url, ENT_QUOTES, 'UTF-8')`.

The verb defaults to "continuing" (matches the cart's existing copy). Callers in registration / mailing list / etc. can pass a different verb if their context calls for it ("By signing up", "By submitting", etc.).

## Consumer changes

The grep for current hardcoded references:

```
views/cart.php:271                                — implicit-consent copy under Continue/Complete Order
views/register.php:99                             — privacy checkbox label
theme/getjoinery/includes/PublicPage.php:133-134  — site footer
theme/tailwind/views/register.php:70              — privacy checkbox label (tailwind override)
```

Plus the not-yet-shipped consumers from `terms_acceptance_capture.md`:

```
views/password-set.php          — required consent checkbox alongside password fields
views/password-reset-2.php      — conditional consent checkbox for never-accepted users
views/terms-accept.php          — the post-login interstitial
```

Plus the consent checkbox copy in `views/lists.php`, `views/list.php`, `views/event_waiting_list.php` that today just say "I consent to the privacy policy" without a link — those should gain a link when `privacy_url` is set, or stay plain text when it isn't.

Each of those callsites switches from a hardcoded `<a href="/terms">` to using the helper. For static link footers (the theme PublicPage.php) the helper returns null when unconfigured and the rendering code conditionally omits the link block.

## Default behavior when unset

This is the deliberate UX choice that keeps the spec safe for fresh installs:

- Cart consent copy: render nothing (no consent paragraph) when neither URL is set. The implicit-consent legal value is gone, but so is the broken link, and cart still functions. Admins discover the absence when they review their checkout UX.
- Register / mailing list / waiting list privacy checkbox: render the checkbox with plain text ("I consent to the privacy policy.") when `privacy_url` is empty. The checkbox is still gated; the link just isn't there.
- Theme footer: omit the menu items entirely when both are empty.
- `/password-set`, `/password-reset-2`, `/terms-accept` consent checkboxes: same as register — checkbox with plain text when URLs are empty.

Rationale for not failing-loudly when unset: the consent capture system from `terms_acceptance_capture.md` should be able to ship without being blocked on a deployment getting their legal docs lined up. The UI degrades; nothing breaks.

We can layer on a louder admin nudge later — e.g., the admin dashboard shows a "Configure your Terms / Privacy URLs" warning card when either is empty and `register_active` is `1` — but that's a follow-on, not a blocker.

## Files touched

- `settings.json` — add `terms_url` and `privacy_url` entries.
- `includes/LibraryFunctions.php` — add `terms_url()`, `privacy_url()`, and `consent_copy()` static methods.
- `views/cart.php` — replace hardcoded URLs with `LibraryFunctions::consent_copy()` call (replaces the existing "By continuing, you agree to..." paragraph wholesale).
- `views/register.php` — switch the FormWriter `checkboxinput` label to use `LibraryFunctions::privacy_url()` and conditionally render the link.
- `theme/tailwind/views/register.php` — same treatment as the base register view.
- `theme/getjoinery/includes/PublicPage.php` — conditionally render the footer items.
- `views/lists.php`, `views/list.php`, `views/event_waiting_list.php` — same treatment as register; checkbox label gets a link only when `privacy_url` is set.
- `adm/admin_settings.php` (or wherever the relevant settings tab lives) — a few lines for help-text strings.

Future consumers from `terms_acceptance_capture.md` will pick up the helpers when those files are written.

## Edge cases

- **URL containing user-supplied special characters:** the helper outputs URLs through `htmlspecialchars(... ENT_QUOTES ...)` to prevent attribute-context injection. If an admin somehow puts `javascript:` or `data:` in the field, the link still renders but the browser will not navigate to a script context unless the admin actively types it. Worth a one-line validator on the settings save side: reject anything that doesn't start with `http://`, `https://`, or `/`.
- **Internal `/page/{slug}` URLs that link to a soft-deleted page:** the URL renders, the click 404s. Acceptable — same failure mode as the admin pasting any other broken URL, and easily caught in deployment QA.
- **Theme override picks up the helpers automatically:** since the helpers live on `LibraryFunctions` (always available), every theme's PublicPage / register view can use them without the spec dictating per-theme behavior. Themes that don't update yet just continue to render hardcoded links and 404 — same state as today, no regression.
- **External URL with `target="_blank"`:** all consumers should use `target="_blank" rel="noopener"` for external URLs to avoid window.opener leakage. The `consent_copy()` helper hardcodes `target="_blank"` since external is the more common case and internal pages opening in a new tab is mild UX cost; not worth a per-call branch.
- **Admin enables `register_active` but leaves URLs empty:** acceptable; existing registration form still works, just without the privacy-policy link in the checkbox label. The admin nudge described above (out of scope here) is the eventual fix.
- **Setting changed mid-session:** settings are read on each request from the singleton; no caching gotchas. The change is reflected immediately on the next request.

## Test plan

- [ ] With both settings empty, every consumer renders gracefully (no broken links, no fatal errors).
- [ ] With both settings set to internal `/page/...` URLs, every consumer renders the links pointing there.
- [ ] With both settings set to external `https://...` URLs, links render with `target="_blank"`.
- [ ] With only `privacy_url` set, terms link is omitted everywhere; privacy link renders.
- [ ] With only `terms_url` set, mirror-image of the above.
- [ ] Cart `consent_copy()` paragraph degrades correctly across all four configurations (both / only privacy / only terms / neither).
- [ ] Register / mailing-list checkboxes degrade correctly across all four configurations.
- [ ] Saving a malformed URL (`javascript:alert(1)`) is rejected at settings-save time.
- [ ] `php -l` and `validate_php_file.php` clean on all touched files.
