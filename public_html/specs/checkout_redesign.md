# Checkout UI Redesign Specification

**Status:** Active
**Created:** 2026-03-18
**Priority:** High

## Executive Summary

Redesign the checkout flow into a streamlined two-page experience: a refreshed product page collecting all per-item data, and a modern single-page accordion checkout handling contact, coupon, billing, and payment. The product page groups requirements into logical card sections. The cart page uses progressive disclosure with collapsible accordion sections, following the pattern used by Amazon, Under Armour, and Louis Vuitton. The redesign also integrates the survey system and removes the legacy extra info collection system.

## Problem Statement

### Current Flow Issues

1. **Split across multiple pages**: Product requirements (name, email, address, questions) are collected on the product page, then billing info + payment on a separate cart page. Users must mentally track state across pages.
2. **No progressive disclosure**: The product page shows all form fields at once, which can overwhelm users for products with many requirements.
3. **Billing duplication**: The cart page collects billing name/email even when the product page already collected a full name and email via requirements. There is no intelligence about reusing already-collected data.
4. **No order review before payment**: Users go directly from "Add to Cart" to a payment page with no consolidated review of what they entered.
5. **Limited survey integration**: The QuestionRequirement system exists but questions are buried in the product form with no visual separation from core fields.
6. **Cart abandonment risk**: Email is not captured early enough -- if a user fills out product requirements but abandons before billing, there is no recovery path.

### Industry Context

- Average cart abandonment rate: **70.19%** (Baymard Institute 2025-2026)
- Forced account creation causes **26%** abandonment
- Complicated/lengthy checkout causes **22%** abandonment
- A well-organized accordion checkout can improve conversion by **35%** (Baymard)

## Design Goals

1. **Single-page accordion checkout** consolidating all steps from product selection through payment
2. **Progressive disclosure** -- only show one section's fields at a time
3. **Early email capture** for cart recovery potential
4. **Guest-first checkout** with optional account creation
5. **Smart field reuse** -- don't ask for the same info twice
6. **Survey integration** -- required surveys on product page, optional/post-event surveys on confirmation page
7. **Mobile-first responsive design**
8. **Accessible** -- keyboard navigable, screen reader compatible, WCAG 2.1 AA

## Accordion Section Design

### Section Architecture

Each accordion section follows a consistent pattern:

```
[Section Number] [Section Title]                    [Status Badge]
--------------------------------------------------------------------
|                                                                  |
|  [Form fields when ACTIVE/OPEN]                                  |
|                                                                  |
|  [Continue] button                                               |
--------------------------------------------------------------------
```

When collapsed after completion:
```
[Section Number] [Section Title]              [Completed checkmark]
  Summary of entered data                              [Edit]
--------------------------------------------------------------------
```

**Visual States:**
- **Not Yet Reached**: Grayed out header, not clickable, no content visible
- **Active**: Full-color header with brand accent, form fields visible, [Continue] button at bottom
- **Completed**: Subtle background, checkmark icon, summary of entered data, [Edit] link to reopen

### Section Order and Content

The checkout accordion has up to 4 sections. Per-item data (registrant name, address, questions, required surveys) is collected on the product page before items enter the cart -- not in the accordion. The accordion handles only shared checkout concerns.

---

#### Section 1: Contact Information (Always shown)

**Purpose:** Capture billing email for account creation/attachment and cart abandonment recovery.

**Fields:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Email Address | email input | Yes | Used as the billing/account email |

**Pre-fill logic:** If cart items contain emails (from EmailRequirement on the product page), default to the first one entered. The user can change it.

**Behavior:**
- If user is logged in: Pre-fill email from profile, show "Logged in as [name] ([email])" with [Change] link. Auto-complete this section.
- If email matches existing account: Show inline message: "Welcome back! [Log in](/login) for faster checkout, or continue as guest." Do NOT block checkout.
- On completing this section: Store email in session immediately for potential cart recovery use.

**Collapsed summary:** `jeremy@example.com`

---

#### Section 2: Coupon Code (Shown when `coupons_active` setting is enabled)

**Purpose:** Apply discount codes.

**Fields:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Coupon Code | text + [Apply] button | No | Inline validation on apply |

**Behavior:**
- On [Apply]: AJAX validation -- show green checkmark + discount amount on success, red error on failure.
- Applied coupons shown as removable tags/badges.
- Order summary on right updates immediately when coupon is applied/removed.
- This section is lightweight -- it can auto-complete (skip) if no coupon is entered and user clicks [Continue].
- In test mode: Show available test coupon buttons as in current UI.

**Collapsed summary:** `SAVE20 applied (-$5.00)` or `No coupon applied`

---

#### Section 3: Billing & Account (Always shown)

**Purpose:** Confirm billing identity and optional account creation.

**Read-only summary:**
- Name: pre-filled from cart item registrant data (first item with FullNameRequirement). [Change] link makes fields editable inline. If no cart item has a name, show editable First Name / Last Name fields instead.
- Email: pre-filled from Contact section. [Change] link opens Contact section for editing.

**Active fields when NOT logged in:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Create Password | password | Optional | "Create an account for faster checkout next time" -- checkbox to reveal |
| Terms & Privacy | checkbox | Yes | "I agree to the [Terms of Use] and [Privacy Policy]" |

**When logged in:**
- Name and email shown from profile (read-only).
- Terms & Privacy checkbox still required.
- This section can auto-complete if user is logged in and has agreed to terms previously (store agreement timestamp).

**Collapsed summary:** `Jeremy Tunnell (jeremy@example.com) | Account will be created`

---

#### Section 4: Payment (Always shown when total > 0; hidden for free orders)

**Purpose:** Collect payment and complete the order.

**Layout:**
- Payment method tabs/buttons at top: [Credit Card] [PayPal] [Apple Pay/Google Pay] -- shown based on site configuration
- Selected method's form below
- [Place Order] button at the very bottom, styled prominently

**For Stripe Checkout mode (`checkout_type == 'stripe_checkout'`):**
- **Special case:** Stripe Checkout redirects the user away from the page to Stripe's hosted payment form, then back. This is incompatible with an inline accordion payment section.
- **Approach:** The accordion collects all non-payment information (contact, coupon, billing). The final section is NOT a payment form — instead it is an **Order Review** section summarizing everything, with a prominent [Pay with Stripe] button.
- Clicking [Pay with Stripe] does a final server-side validation of all sections, saves everything to session/cart, then redirects to Stripe's hosted checkout.
- On return from Stripe, the existing `cart_charge_logic.php` flow processes the order as it does today.
- The accordion's last section header reads "Review & Pay" instead of "Payment".

**For Stripe Regular mode (`checkout_type == 'stripe_regular'`):**
- Inline card form (Stripe Elements): Card number, Expiry, CVC
- [Place Order] button
- Payment is collected directly within the accordion — no redirect needed.

**For PayPal (`use_paypal_checkout == true`):**
- PayPal smart buttons (PayPal, Venmo if USD, Card, Pay Later)
- Standard PayPal flow renders inline within the accordion section.

**For mixed payment options (Stripe Checkout + PayPal both enabled):**
- Show payment method selection: [Pay with Stripe] button and PayPal smart buttons side-by-side or stacked.
- Stripe Checkout redirects away; PayPal processes inline.

**For free orders (total == $0 after discounts):**
- Skip this section entirely. The billing section's [Continue] button becomes [Complete Order].

**Below payment form / review:**
- "Your order is protected by 256-bit SSL encryption" with lock icon
- Return/refund policy link if configured
- Contact email for support

**Collapsed summary:** (This section doesn't collapse -- it's the final action)

---

### Sections Not Currently Needed But Architecturally Supported

These sections could be added to the accordion in the future:

| Future Section | Purpose |
|----------------|---------|
| **Delivery Method** | Shipping method selection (standard, express, pickup) |
| **Gift Options** | Gift wrapping, gift message, send to different address |
| **Donation/Tip** | Optional donation add-on at checkout |

## Product Page Design

The product page is the first step in the purchase flow and collects all per-item data. It gets a UI refresh to match the clean, card-based style of the accordion checkout.

### Layout

**Desktop (>768px):** Two-column layout.
- **Left column:** Product info (image, name, description, price, version selector)
- **Right column:** Grouped requirement form fields in cards, with [Add to Cart] at the bottom

**Mobile (<768px):** Single column -- product info stacked above the form.

### Desktop Layout
```
+--------------------------------------------------------------+
|  [Standard site navigation header]                            |
+--------------------------------------------------------------+
|  Home > Products > Product Name                               |
+--------------------------------------------------------------+
|                                                               |
|  +------------------------+  +---------------------------+   |
|  |                        |  |                           |   |
|  |  [Product Image]       |  |  Your Information         |   |
|  |                        |  |  +---------------------+  |   |
|  |  Product Name           |  |  | First Name [      ] |  |   |
|  |  $29.99                |  |  | Last Name  [      ] |  |   |
|  |                        |  |  | Email      [      ] |  |   |
|  |  [Version 1] [V2] [V3]|  |  +---------------------+  |   |
|  |                        |  |                           |   |
|  |  Description text here |  |  Additional Questions     |   |
|  |  lorem ipsum dolor sit |  |  +---------------------+  |   |
|  |  amet, consectetur...  |  |  | Q1: How did you...  |  |   |
|  |                        |  |  | [Dropdown       v]  |  |   |
|  |                        |  |  |                     |  |   |
|  |                        |  |  | Q2: Dietary needs?  |  |   |
|  |                        |  |  | [Text area      ]   |  |   |
|  |                        |  |  +---------------------+  |   |
|  |                        |  |                           |   |
|  |                        |  |      [Add to Cart]        |   |
|  |                        |  |                           |   |
|  +------------------------+  +---------------------------+   |
|                                                               |
+--------------------------------------------------------------+
```

### Mobile Layout
```
+---------------------------+
|  [Site Navigation]        |
+---------------------------+
| Home > Products > Name    |
+---------------------------+
|                           |
|  [Product Image]          |
|                           |
|  Product Name             |
|  $29.99                   |
|                           |
|  [Version 1] [V2] [V3]   |
|                           |
|  Description text...      |
|                           |
|  Your Information         |
|  +---------------------+  |
|  | First Name [      ]  |  |
|  | Last Name  [      ]  |  |
|  | Email      [      ]  |  |
|  +---------------------+  |
|                           |
|  Additional Questions     |
|  +---------------------+  |
|  | Q1: How did you...   |  |
|  | [Dropdown        v]  |  |
|  +---------------------+  |
|                           |
|      [Add to Cart]        |
|                           |
+---------------------------+
```

### Product Info (Left Column)

- **Product image:** Displayed if available, otherwise a styled placeholder
- **Product name:** Prominent heading
- **Price:** Large, bold, primary color. If on sale, show original price with strikethrough and sale price
- **Version selector:** If multiple product versions exist, show as styled radio cards (name, description, price per version). Selected version updates the displayed price. If only one version, hide the selector
- **Description:** Product description below the version selector

### Requirement Form (Right Column)

Requirements are grouped into visual card sections. Each card has a subtle header label and groups related fields:

**Card grouping logic:**
- **"Your Information"** -- FullNameRequirement, EmailRequirement, PhoneNumberRequirement, DOBRequirement, NewsletterSignupRequirement
- **"Address"** -- AddressRequirement (only shown if configured)
- **"Additional Questions"** -- QuestionRequirement instances and required survey questions (`evt_survey_display = 'required_before_purchase'`)

If only one group has fields, skip the card headers and show fields directly.

**Card styling:** Same border-radius, shadow, and spacing as the accordion sections on the cart page. Use `var(--color-light)` background with white input fields.

**[Add to Cart] button:** Full-width at the bottom of the right column. Same primary button styling as the cart's [Place Order]. Disabled with spinner on click to prevent double-submission.

### Navigation

The product page keeps the **standard site header** (not the minimal checkout header). The user is still browsing, not in checkout mode. Breadcrumbs show: Home > Products > Product Name.

## Order Summary Sidebar

### Desktop (>768px)
A sticky sidebar on the right side, always visible as the user scrolls through accordion sections.

**Contents:**
- Product name and version
- Product thumbnail (if available)
- Registrant name (from cart item data, collected on product page)
- Quantity (currently always 1 per cart item; architecture supports multiples)
- Individual item price
- Coupon discount (when applied)
- Subtotal
- Total

**Behavior:**
- Updates in real-time as sections are completed (coupon applied, etc.)
- Per-item data (registrant name, answers to questions) shown under each item with an [Edit] link. Clicking [Edit] navigates to `/product/{slug}?edit_item={index}` with fields pre-filled from the cart item data. Submitting updates the existing cart item in place and redirects back to `/cart`.
- Item removal link (if multiple items in cart)
- "Add another item" link back to products page

### Mobile (<768px)
- Collapsible summary at the top of the page
- Always-visible single line: "Order: [Product Name] - $XX.XX" with expand/collapse chevron
- Tapping expands to show full order details
- Sticky at top of viewport when scrolling

## Page Layout

### Desktop Layout
```
+--------------------------------------------------------------+
|  [Logo]           Secure Checkout              [Lock Icon]    |
+--------------------------------------------------------------+
|                                                               |
|  +-------------------------------------+  +--------------+   |
|  |                                     |  |              |   |
|  |  [1] Contact Information     [done] |  | Order        |   |
|  |    jeremy@example.com    [Edit]     |  | Summary      |   |
|  |                                     |  |              |   |
|  |  [2] Coupon Code            [ACTIVE]|  | Product X    |   |
|  |    +----------------------------+   |  | Jeremy T.    |   |
|  |    | [Enter coupon    ] [Apply] |   |  | $29.99       |   |
|  |    |          [Continue]        |   |  |              |   |
|  |    +----------------------------+   |  | ----------   |   |
|  |                                     |  | Total:       |   |
|  |  [3] Billing & Account       [---]  |  | $29.99       |   |
|  |  [4] Payment                 [---]  |  |              |   |
|  |                                     |  |              |   |
|  +-------------------------------------+  +--------------+   |
|                                                               |
+--------------------------------------------------------------+
```

### Mobile Layout
```
+---------------------------+
|  Secure Checkout  [Lock]  |
+---------------------------+
| Order: Product X - $29.99 |
|            [v expand]     |
+---------------------------+
|                           |
| [1] Contact Info   [done] |
|   jeremy@...    [Edit]    |
|                           |
| [2] Coupon       [ACTIVE] |
| +----------------------+  |
| | [Enter coupon] [Apply]|  |
| |      [Continue]       |  |
| +----------------------+  |
|                           |
| [3] Billing        [---]  |
| [4] Payment        [---]  |
|                           |
+---------------------------+
```

## Navigation & Header

### Checkout Header
Replace the standard site header/navigation with a minimal checkout header:
- Logo (left) -- links back to homepage with confirmation dialog ("Leave checkout?")
- "Secure Checkout" text (center) with lock icon
- No main navigation links -- removes distractions per best practice

This is done by passing a `'noheader' => true` option to `public_header()` which renders the minimal header.

### Progress Indicator
Above the accordion, show a lightweight progress bar or step indicator:
- Shows "Step X of Y" based on which section is active
- On mobile, shows as a thin progress bar at the top
- Uses `aria-current="step"` for accessibility

## Technical Implementation

### Architecture Changes

The checkout redesign evolves the existing `/cart` page in place rather than creating a separate `/checkout` route. This avoids unnecessary routing complexity — the URL stays `/cart`, and `cart.php` + `cart_logic.php` are redesigned with the accordion UI.

**New files:**
- `/ajax/checkout_ajax.php` -- AJAX endpoints for section validation, coupon apply/remove, email check, Stripe session creation

**Modified files:**
- `/views/product.php` -- UI refresh with card-based grouped requirements, renders survey questions when `evt_survey_display = 'required_before_purchase'`
- `/logic/product_logic.php` -- Remove confirm-order review step (Add to Cart validates and adds to cart in one step, then redirects to `/cart`). Support for survey requirement validation and processing
- `/views/cart.php` -- Redesigned with accordion UI, order summary sidebar, login modal, checkout-mode header
- `/logic/cart_logic.php` -- Refactored to support AJAX section validation. Extract billing validation into a reusable function callable from both the page POST flow (fallback) and the AJAX endpoint
- `/views/cart_confirm.php` -- Add inline post-purchase survey rendering
- `/includes/PublicPage.php` (or theme override) -- Add `noheader` header option for minimal header
- `/includes/ShoppingCart.php` -- Add `update_item($index, $form_data)` method for editing per-item data from the cart page

**Unchanged:**
- `/logic/cart_charge_logic.php` -- Payment processing stays the same
- `serve.php` -- No new routes needed
- `/data/` model classes -- No changes to data layer
- `/includes/requirements/` -- Product requirements render on product page as before
- `/includes/StripeHelper.php` -- Stripe integration unchanged
- `/includes/PaypalHelper.php` -- PayPal integration unchanged

### Routing

```
/product/{slug}     -- Product info page with "Add to Cart" button (collects per-item requirements)
/cart               -- Accordion checkout (redesigned in place)
/cart_charge        -- Payment processing (unchanged)
/cart_confirm       -- Confirmation + post-purchase survey (unchanged URL, modified view)
```

**Flow:**
Product page [Add to Cart] --> `/cart` --> accordion collects contact, coupon, billing, payment --> `/cart_confirm`

Per-item information (registrant, address, questions) is always collected on the product page before items enter the cart. The accordion checkout handles only shared checkout concerns.

### Section Rendering Logic

Since per-item info is collected on the product page, the accordion sections are simple and consistent. Section visibility is determined in `cart_logic.php` with straightforward conditionals — no dedicated renderer class needed:

- **Contact** -- always shown
- **Coupon** -- shown if `$settings->get_setting('coupons_active')` is true
- **Billing** -- always shown
- **Payment** -- shown if `$cart->get_total() > 0`

The logic file passes a `$sections` array to the view indicating which sections to render and their initial state (active, completed, or not-yet-reached).

### AJAX Section Validation

Each section validates independently via AJAX when [Continue] is clicked:

```
POST /ajax/checkout_ajax
{
    "action": "validate_section",
    "section": "contact",
    "data": { "email": "..." }
}

Response:
{
    "valid": true,
    "summary": "jeremy@example.com",
    "next_section": "coupon"
}

// Or on error:
{
    "valid": false,
    "errors": {
        "email": "Please enter a valid email address"
    }
}
```

**Other AJAX endpoints:**
- `apply_coupon` -- Validate and apply coupon code, return updated totals
- `remove_coupon` -- Remove coupon, return updated totals
- `check_email` -- Check if email exists in system (for login prompt)
- `get_order_summary` -- Return current order summary HTML (for sidebar updates)

### Form Validation

**Client-side:**
- Validate on blur (field exit), not on keystroke
- Use the existing `JoineryValidation` system
- "Reward early, punish late" pattern: clear errors immediately when input becomes valid, delay showing errors until blur
- Required field validation only fires on [Continue] click, not before user has interacted

**Server-side:**
- Each section's [Continue] triggers AJAX validation via the product requirement's existing `validate()` methods
- Final [Place Order] does full server-side validation of all sections before processing payment
- All existing requirement validation logic is reused, not duplicated

### Data Flow

1. User data is stored in session as sections are completed (via AJAX validation calls)
2. Session stores a `checkout_data` array keyed by section:
   ```php
   $_SESSION['checkout_data'] = [
       'contact' => ['email' => '...'],
       'billing' => ['first_name' => '...', 'last_name' => '...', ...],
   ];
   ```
   Note: Per-item data (registrant, address, questions) is already stored in the cart items via the product page flow. The checkout session only tracks the shared checkout fields.
3. On [Place Order], the checkout logic:
   - Validates all sections server-side
   - Merges checkout_data (contact, billing) with existing cart items
   - Calls existing `cart_charge_logic.php` for payment processing
   - All existing post-purchase hooks, emails, and requirement `post_purchase()` methods fire as before

### Accessibility Requirements

- All form fields have `<label>` elements with `for` attributes
- Required fields marked with `aria-required="true"`
- Form fields use appropriate `autocomplete` attributes (`email`, `given-name`, `family-name`, `street-address`, etc.)
- Accordion sections use `<fieldset>` with `<legend>` for grouped inputs
- Error messages associated with fields via `aria-describedby` and `aria-invalid="true"`
- Section state changes announced via `aria-live="polite"` region
- Progress indicator uses `aria-current="step"`
- Entire checkout navigable via keyboard (Tab, Enter/Space, Arrow keys)
- Focus moves to newly opened section when accordion transitions
- Visible focus indicators on all interactive elements
- Minimum 4.5:1 contrast ratio for text, 3:1 for UI components
- Content readable and functional at 200% zoom

### CSS/Styling Approach

Use the existing theme system. The accordion checkout is a theme-overridable view:
- Base implementation in `/views/cart.php` (redesigned in place)
- Theme override at `/theme/{theme}/views/cart.php`
- Use CSS custom properties (already established: `--color-primary`, `--color-light`, `--color-border`, `--color-muted`, `--color-danger`)
- No new CSS framework dependencies -- use existing Bootstrap classes from Falcon theme
- Inline styles for checkout-specific layout (consistent with current cart.php approach) transitioning to component classes over time

### JavaScript

Inline `<script>` block at the bottom of `cart.php`, consistent with the existing pattern (the current cart.php already has an inline script for submit-button disabling). Vanilla JS, no jQuery.

Key functions:
- `openSection(id)` / `closeSection(id)` -- toggle accordion panels, manage active/completed/pending states
- `validateAndContinue(id)` -- POST to `/ajax/checkout_ajax`, handle response, advance to next section
- `updateOrderSummary()` -- refresh sidebar totals after coupon changes
- Event listeners for [Continue], [Edit], coupon [Apply]/[Remove] buttons

## Data Collection Architecture

Data is collected at two points in the user journey:

### Product Page (per-item, before purchase)

All per-item data is collected on the product page before adding to cart, using the product requirements system:

- **Standard requirements:** name, email, address, DOB, phone, newsletter signup
- **Custom questions:** QuestionRequirement instances from `qst_questions` table (admins configure these per product for any data they need -- dietary restrictions, experience level, etc.)
- **Required pre-event surveys:** When an event has `evt_svy_survey_id` set and `evt_survey_display = 'required_before_purchase'`, survey questions render on the product page as a requirement. Must be answered before adding to cart. Answers saved to `sva_survey_answers` via `post_purchase()`.

### Confirmation Page / Post-Event (after purchase)

Optional and post-event surveys are presented after purchase:

- **Optional surveys:** Rendered inline on `cart_confirm.php`. If not completed, followed up via email and profile page reminders.
- **Post-event surveys:** Triggered by a scheduled task after the event date -- sends email with survey link, shows reminder on profile page.
- Uses the existing survey infrastructure (`survey_logic.php`, `SurveyQuestion`, `SurveyAnswer`) unchanged.

### Cleanup

- `/profile/event_register_finish` page -- deleted
- `evt_collect_extra_info` flag and hardcoded extra info fields (`evr_health_notes`, `evr_first_event`, `evr_recording_consent`, `evr_other_events`, `evr_extra_info_completed`) -- removed
- `more_info_required` email flag in `cart_charge_logic.php` -- removed
- Profile page "extra info incomplete" reminders (currently commented out) -- removed

### Admin Configuration

**Event edit page (`admin_event_edit.php`):**
- Survey dropdown (currently commented out) gets uncommented -- selects which survey to link to the event
- New `evt_survey_display` dropdown controlling when/how the survey is presented:
  - `none` -- No survey (default)
  - `required_before_purchase` -- Survey questions on product page, must answer to buy
  - `optional_at_confirmation` -- Survey shown on confirmation page, reminders if skipped
  - `after_event` -- Survey sent via email/profile after event date passes

### Database Changes

- Uncomment survey dropdown in `admin_event_edit.php` (lines 262-290)
- Add `evt_survey_display` field to events: `none`, `required_before_purchase`, `optional_at_confirmation`, `after_event`
- Remove `evt_survey_required` from events table (replaced by `evt_survey_display`)
- Add `evr_survey_completed` field to `evr_event_registrants` for tracking
- Remove `evt_collect_extra_info` from events table
- Remove extra info columns from event_registrants table (`evr_extra_info_completed`, `evr_health_notes`, `evr_first_event`, `evr_recording_consent`, `evr_other_events`)

## Migration Strategy

### Phase 1: Accordion checkout
- Rebuild `/views/cart.php` with accordion UI
- Refactor `/logic/cart_logic.php` to support AJAX section validation
- Create `/ajax/checkout_ajax.php` for section validation endpoints
- Add checkout-mode header to PublicPage
- Remove extra info system (`evt_collect_extra_info`, hardcoded fields, `/profile/event_register_finish`, `more_info_required` flag)

### Phase 2: Test, iterate, and enable surveys
- Test all payment modes (Stripe Checkout, Stripe Regular, PayPal, free orders)
- Test logged-in vs guest flows
- Refine mobile experience
- Uncomment survey dropdown in `admin_event_edit.php`
- Add `evt_survey_display` field to event edit
- Implement required pre-event surveys on product page

### Phase 3: Post-purchase surveys and polish
- Add optional/post-event survey rendering to `cart_confirm.php`
- Add survey reminders to profile page and confirmation emails
- Add scheduled task for post-event survey email triggers
- Accessibility audit and fixes
- Edge case handling (session timeout, payment errors)

## Edge Cases & Special Handling

### Free Products (price = 0)
- Skip Payment section entirely
- Billing section's [Continue] becomes [Complete Order]
- Show a brief "No payment required" note

### Subscription Products
- Subscription terms (monthly/yearly, trial period) are shown on the product page via the version selector
- Payment section notes "You will be charged [amount] every [period]"
- Only one subscription per checkout (enforced when adding to cart)

### Multiple Cart Items
- Per-item information (registrant, address, questions) is collected on the product page when each item is added to cart, NOT in the accordion checkout.
- The accordion checkout for multi-item carts shows only: Contact, Coupon, Billing, Payment.
- This keeps the accordion focused and prevents it from becoming excessively long with many items.

### Returning Users (Logged In)
- Contact section auto-completes with profile email
- Billing section auto-completes with profile name/email
- Logged-in users may see only the Coupon (if enabled) and Payment sections as active, with Contact and Billing pre-completed

### Existing Email / Login Required
- When email check reveals existing account: show inline message in Contact section
- Offer [Log In] button that opens a **login modal overlay** on the checkout page (the existing login logic already supports AJAX via `X-Requested-With` header and `lbx_` prefixed field names)
- On successful modal login: refresh checkout state with user profile data, auto-complete applicable sections
- Never block checkout entirely -- allow guest purchase with existing email (creates order linked to existing account)

### Test Mode
- Show test mode banner at top of checkout (same as current)
- Test coupon buttons shown in Coupon section
- Test Stripe/PayPal keys used automatically

### Cart Timeout / Session Expiry
- If session expires mid-checkout, show friendly message with [Start Over] button
- Preserve entered data in localStorage as backup, offer to restore

### Validation Errors After Payment Redirect
- If Stripe Checkout redirect returns but session data is lost, attempt recovery from Stripe session metadata
- Show clear error with support contact info if recovery fails

## Metrics & Success Criteria

### Key Metrics to Track
- Checkout completion rate (orders / checkout page visits)
- Average time to complete checkout
- Section-level drop-off rates (which section do users abandon at?)
- Payment method distribution
- Coupon usage rate
- Mobile vs. desktop completion rates

### Success Criteria
- Checkout completion rate improves by 15%+ over current flow
- Average checkout time decreases by 20%+
- Mobile completion rate approaches desktop rate (within 10% parity)
- Zero increase in payment errors or failed transactions
- Accessibility audit passes WCAG 2.1 AA

## Testing Plan

### Functional Testing
- [ ] Single product checkout (all section combinations)
- [ ] Multi-item cart checkout
- [ ] Guest checkout (new user)
- [ ] Logged-in user checkout
- [ ] Existing email detection and login flow
- [ ] Free product checkout (payment section hidden)
- [ ] Subscription product checkout
- [ ] Coupon application and removal
- [ ] All product requirement types render correctly on product page
- [ ] Question/survey requirements render and validate on product page
- [ ] Edit cart item from order summary (navigate to product page, pre-fill, update in place)
- [ ] Stripe Checkout mode payment
- [ ] Stripe Regular mode payment
- [ ] PayPal payment
- [ ] Error handling for all payment failure types
- [ ] Session timeout recovery
- [ ] Browser back button behavior within accordion
- [ ] Product page card grouping (Your Information, Address, Additional Questions)
- [ ] Product page version selector updates price
- [ ] Required pre-event survey renders on product page (`required_before_purchase`)
- [ ] Optional survey renders on confirmation page (`optional_at_confirmation`)
- [ ] Post-event survey email sent after event date (`after_event`)
- [ ] Survey completion tracked via `evr_survey_completed`
- [ ] Survey reminders on profile page for incomplete surveys

### Mobile Testing
- [ ] Accordion sections open/close smoothly
- [ ] Order summary collapses properly
- [ ] Touch targets meet 44x44px minimum
- [ ] Keyboard types correct for each field (email, tel, numeric)
- [ ] Form is usable at 320px viewport width

### Accessibility Testing
- [ ] Full keyboard navigation through all sections
- [ ] Screen reader announces section transitions
- [ ] Error messages associated with fields
- [ ] Focus management on section open/close
- [ ] Color contrast meets WCAG AA
- [ ] Zoom to 200% maintains usability

### Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Safari iOS
- [ ] Chrome Android

## Implementation Priority

**Phase 1 (Core):**
1. Product page UI refresh (card-based grouped requirements, remove confirm step)
2. Build accordion UI component in `cart.php` (open/close, state management)
3. Implement Contact, Billing, and Payment sections
4. Create `/ajax/checkout_ajax.php` with section validation endpoints
5. Order summary sidebar with per-item [Edit] links (sticky desktop, collapsible mobile)
6. Add `update_item()` to ShoppingCart for editing cart items in place
7. End-to-end flow working with all payment modes
8. Remove extra info system (hardcoded fields, `/profile/event_register_finish`, `more_info_required` flag)

**Phase 2 (Enhancements):**
9. Coupon section with AJAX apply/remove
10. Login modal for existing email detection
11. Checkout-mode header in PublicPage
12. Smart pre-fill (contact email -> billing, logged-in user auto-complete)
13. Mobile-optimized layout
14. Progress indicator
15. Uncomment and enable survey-event link in `admin_event_edit.php`
16. Add `evt_survey_display` field to event edit
17. Implement required pre-event surveys on product page

**Phase 3 (Post-Purchase Surveys & Polish):**
18. Optional/post-event survey on `cart_confirm.php`
19. Survey reminders on profile page and in confirmation emails
20. Scheduled task for post-event survey email triggers
21. Accessibility audit and fixes
22. Edge case handling (session timeout, payment errors, back button)

## Related Files

### Current checkout system (to understand/modify):
- `/views/product.php` -- Product page with purchase form
- `/views/cart.php` -- Current cart/checkout page
- `/views/cart_confirm.php` -- Order confirmation
- `/logic/product_logic.php` -- Product page logic
- `/logic/cart_logic.php` -- Cart page logic
- `/logic/cart_charge_logic.php` -- Payment processing
- `/includes/ShoppingCart.php` -- Cart session management
- `/includes/requirements/AbstractProductRequirement.php` -- Requirement base class
- `/includes/requirements/` -- All requirement implementations
- `/includes/StripeHelper.php` -- Stripe integration
- `/includes/PaypalHelper.php` -- PayPal integration
- `/data/products_class.php` -- Product model
- `/data/orders_class.php` -- Order model
- `/data/order_items_class.php` -- Order item model
- `/data/questions_class.php` -- Questions for surveys

### Survey system (to enable and connect):
- `/adm/admin_event_edit.php` -- Survey dropdown (currently commented out, lines 262-290)
- `/adm/admin_surveys.php` -- Survey list admin
- `/adm/admin_survey_edit.php` -- Survey editor admin
- `/adm/admin_survey_answers.php` -- View survey answers
- `/logic/survey_logic.php` -- Survey display and submission logic
- `/views/survey_finish.php` -- Survey completion view
- `/data/surveys_class.php` -- Survey model
- `/data/survey_questions_class.php` -- Survey question model
- `/data/survey_answers_class.php` -- Survey answer model
- `/data/events_class.php` -- `evt_svy_survey_id`, `evt_survey_display` fields
- `/data/event_registrants_class.php` -- future `evr_survey_completed` field

### Reference specs:
- `/specs/implemented/checkout_improvements.md` -- Security fixes already implemented

## Resolved Design Decisions

1. **Multi-item checkout:** Per-item info (registrant, address, questions) is collected on the product page. The accordion checkout handles only shared concerns: contact, coupon, billing, payment.
2. **Address autocomplete:** Skipped for initial implementation. Standard address fields only. See Future Work section.
3. **Cart abandonment emails:** Out of scope. Early email capture in Section 1 lays the groundwork for a future cart recovery email system.
4. **Login modal:** Use a modal overlay on the checkout page. The existing login logic already supports AJAX requests and `lbx_` field naming.
5. **Post-purchase surveys:** Display prominently on the confirmation page (`cart_confirm.php`). Also persist reminders via email and profile page until the survey is completed.
6. **Event extra info:** Removed entirely. Admins use QuestionRequirements on products to collect any per-item data they need.

## Survey Integration

Events link to a survey via `evt_svy_survey_id` (which survey) and `evt_survey_display` (when/how to present it). See "Data Collection Architecture" above for how these fit into the two collection points.

### `required_before_purchase`

- Survey questions render on the **product page** as part of per-item data collection
- User must answer all questions before adding to cart
- Answers saved to `sva_survey_answers` via the requirement's `post_purchase()` hook, linked to `evr_event_registrant_id`
- `evr_survey_completed` set to `true` at purchase time

### `optional_at_confirmation`

1. **Display the survey inline** on `cart_confirm.php` below the order summary, with a heading like "We'd love your feedback"
2. **Use the existing survey rendering** (`survey_logic.php`, `SurveyQuestion`, `SurveyAnswer` classes) to render questions directly on the confirmation page
3. **Submit via AJAX** so the user stays on the confirmation page and sees a "Thank you!" message on completion
4. **If not completed on the confirmation page**, persist reminders:
   - **Email:** Include survey link in the purchase confirmation email
   - **Profile page:** Show a prominent reminder/alert linking to the survey until `evr_survey_completed` is true
5. **Link survey answers to the registration** via `evr_event_registrant_id` so results can be viewed per-event in admin

### `after_event`

- Survey is NOT shown at checkout or on the confirmation page
- Confirmation page may note: "We'll send you a feedback survey after the event"
- A scheduled task checks for events that have ended and sends survey emails to registrants where `evr_survey_completed` is not true
- Profile page shows survey reminder after the event date passes
- Survey link goes to the existing `/survey` view

## Future Work

- **Address autocomplete:** Integrate Google Places API (or alternative) for address field auto-completion. Would require a new setting (`google_places_api_key`) and JS integration in the Address section. Reduces mobile errors by ~20% per industry research.
- **Cart abandonment email recovery:** Build on the early email capture from Section 1 to send automated "you left items in your cart" emails. Industry data shows 41% open rate and 20-30% revenue recovery.
- **Delivery method section:** Add a section between Address and Product Options for shipping method selection (standard, express, pickup) when physical product shipping is supported.
- **Gift options section:** Gift wrapping, gift message, and alternate shipping address for gift recipients.
- **Survey analytics dashboard:** Admin dashboard for survey results per product/event, export functionality, completion rate tracking.
