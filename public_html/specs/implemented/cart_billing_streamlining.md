# Cart Billing Streamlining

**Status:** Active
**Created:** 2026-05-09
**Priority:** Medium

## Problem

The accordion's billing-user step is doing more than it needs to, and what it gates on does not match what the system actually requires. Specific symptoms:

1. A guest who already provided email + first + last via `EmailRequirement` and `FullNameRequirement` on the product page still has to re-confirm those fields and tick a privacy checkbox before progressing.
2. The "I agree to the Terms of Use and Privacy Policy" checkbox renders for logged-in users (where the server does not enforce it) and for guests (where it duplicates an agreement most sites get implicitly via order-placement copy).
3. The password field is theatrical: when the user does not toggle "Create an account for faster checkout", the JS auto-generates a random password (`views/cart.php:552-554`). The server creates an account either way (`cart_charge_logic.php:113-126`).
4. `ShoppingCart::billing_user_prefill_from_items()` only inspects the first cart item — its `return false` is inside the foreach. If the first item lacks email/name but a later item has them, prefill is silently missed.
5. Logged-in users with an empty `usr_first_name` or `usr_last_name` fall out of `is_billing_user_complete()` and are dropped into the form with no clear signal why.

## Goals

- Auto-skip the billing step whenever the cart already has email + first + last, regardless of whether the user is logged in or a guest.
- Drop the password collection at checkout entirely. `User::CreateNew` already auto-generates a temporary password and sends an activation email when one is omitted (`data/users_class.php:319-345`); the cart page becomes a thin pass-through.
- Replace the explicit "I agree" checkbox with implicit-consent copy below the action button — industry-standard pattern (Amazon, Shopify, Stripe). The Terms / Privacy links remain visible.
- Fix the prefill-from-items loop bug.
- Cover the logged-in-with-missing-name edge case.

## Non-goals

- Cart abandonment recovery (covered as future work in `checkout_redesign.md`).
- A persisted "consent timestamp" on the user record.
- Sending an activation email specifically to the *billing* user. `User::CreateCompleteNew` already passes `send_emails=true`, so today's behavior of emailing newly-created billing users via `CreateNew` continues unchanged.
- Reworking the per-product `EmailRequirement` / `FullNameRequirement` UX.

## Behavior changes

### `ShoppingCart::is_billing_user_complete()`

Old (guest branch):
```
billing_first_name && billing_last_name && billing_email && password && privacy
```
New (guest branch):
```
billing_first_name && billing_last_name && billing_email
```
The logged-in branch is unchanged: first + last + email.

### `ShoppingCart::billing_user_prefill_from_items()`

Move the `return false` outside the foreach so the loop continues until it finds an item with `data['email']`. Today it short-circuits on item 0.

### `cart_logic.php` (server-side validation)

- Remove the `if (empty($_POST['privacy']))` and `if (empty($_POST['password']))` guards in the `billing_email` POST branch.
- Continue to call `determine_billing_user($_POST, false)` so name/email POSTs still flow into the cart.
- Privacy is no longer a server-enforced field; it is communicated via consent copy below the action button.

### `cart.php` (view) — billing section

For guests, replace the four-block structure (Email / Name / Password / Terms) with two blocks: Email and Name. Remove:

- The "Create an account for faster checkout next time" toggle and the password input it reveals.
- The `<input type="checkbox" id="billing_privacy">` block and its error div.

Add immediately above the Continue / Complete Order button:

```
By continuing, you agree to our <a href="/terms">Terms of Use</a>
and <a href="/privacy">Privacy Policy</a>.
```

For logged-in users, the existing read-only summary (name + email + "Not you?" link) stays. Privacy checkbox and password field were never relevant to them and are removed.

When `usr_first_name` or `usr_last_name` is missing on the logged-in user, the section opens with editable name inputs prefilled blank (the email read-only remains). This is the only path where a logged-in user sees a form. Submitting persists the names into `$cart->billing_user` for this checkout but does not write back to the user record (out of scope; the user can update their profile separately).

### `cart.php` JS — `submitBilling()`

Remove:
- The `privacy` field collection and the "must agree to the terms" client error.
- The `create_account_toggle` / `password` field collection and the random-password fallback.
- The `password` and `privacy` keys in the hidden-form payload.

Result: the POST contains `billing_email`, `billing_first_name`, `billing_last_name`, optionally `complete_order`. The server creates the user with no password supplied; `User::CreateNew` generates a temporary one and triggers the activation email.

### `ShoppingCart::determine_billing_user()`

The current method writes `password` and `privacy` keys into `billing_user`. After this change those keys are no longer set or read anywhere. Stop writing them. They were already harmless on logged-in flows.

## Files touched

- `includes/ShoppingCart.php` — `is_billing_user_complete`, `billing_user_prefill_from_items`, `determine_billing_user`.
- `logic/cart_logic.php` — drop privacy/password server checks.
- `views/cart.php` — billing section markup and JS.

## Edge cases

- **Guest with no per-product email/name collection:** `is_billing_user_complete()` returns 0 → billing step is active with email + name fields → behavior unchanged from today aside from the dropped password/privacy fields.
- **Guest who edits after auto-skip:** the accordion's existing "Edit" link on completed sections re-opens the billing step. No new affordance needed.
- **Logged-in user with empty `usr_first_name` / `usr_last_name`:** see "view" section above. Form opens with name fields editable, email read-only.
- **Existing email collision:** the existing `require_login` flow (`cart_logic.php:227-234`) still surfaces a login prompt in the payment section when a guest's email matches an existing user. Unchanged.
- **Free orders:** the billing button still becomes "Complete Order" for `$cart->get_total() <= 0`. Unchanged.

## Out of scope / risks

- **Loss of explicit consent record:** the platform stops capturing a per-checkout consent click. If a future jurisdictional requirement forces an explicit acknowledgment, reintroduce as a one-time signup-side capture rather than a per-checkout click.
- **Behavior change for guests who liked setting their own password at checkout:** they now receive an activation email and set their password from the email link. This matches Stripe / Shopify default flow and reduces drop-off.

## Test plan

- [ ] Guest, product with `EmailRequirement` + `FullNameRequirement`: add to cart → /cart shows billing as completed and payment as active. Edit link reopens billing.
- [ ] Guest, product with no requirements: /cart shows billing as active with email + name fields only (no password, no checkbox).
- [ ] Guest checkout completes a free order via the billing-step "Complete Order" button.
- [ ] Guest checkout completes a paid order; user record is created with a temporary password; activation email is delivered.
- [ ] Logged-in user with full profile: /cart shows billing as completed and payment as active.
- [ ] Logged-in user with missing `usr_first_name`: /cart opens billing with name fields editable, email read-only.
- [ ] `billing_user_prefill_from_items` correctly prefills when item 0 has no email but item 1 does.
- [ ] `php -l` clean and `validate_php_file.php` clean on all touched files.
