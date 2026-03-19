# Checkout UI Redesign Specification

**Status:** Active
**Created:** 2026-03-18
**Priority:** High

## Executive Summary

Redesign the checkout flow from the current two-page layout (product page form + cart/payment page) into a modern single-page accordion checkout. The new design consolidates all information collection into progressive, collapsible sections on one page, following the pattern used by Amazon, Under Armour, and Louis Vuitton. The redesign also adds support for survey/custom questions at checkout and accommodates all information types the system may need to collect, even if not all are enabled initially.

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
6. **Survey/question support** as a dedicated checkout section
7. **All possible information types supported** in the section model, even if not all enabled
8. **Mobile-first responsive design**
9. **Accessible** -- keyboard navigable, screen reader compatible, WCAG 2.1 AA

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

The checkout consists of up to 8 sections, with sections dynamically shown/hidden based on product configuration and site settings. The section order is carefully designed to capture the most valuable information first (email for cart recovery) and progress logically.

---

#### Section 1: Contact Information (Always shown)

**Purpose:** Capture email early for cart abandonment recovery. Identify returning users.

**Fields:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Email Address | email input | Yes | First field captured -- enables recovery emails |
| Phone Number | tel input | Conditional | Shown if PhoneNumberRequirement is configured on product |

**Behavior:**
- If user is logged in: Pre-fill email, show "Logged in as [name] ([email])" with [Change] link. Auto-complete this section.
- If email matches existing account: Show inline message: "Welcome back! [Log in](/login) for faster checkout, or continue as guest." Do NOT block checkout.
- On completing this section: Store email in session immediately for potential cart recovery use.

**Collapsed summary:** `jeremy@example.com` or `jeremy@example.com | (555) 123-4567`

---

#### Section 2: Registrant / Attendee Information (Shown when product has FullNameRequirement or other identity requirements)

**Purpose:** Collect identity information about who is being registered/purchasing.

**Fields:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| First Name | text | Yes | From FullNameRequirement |
| Last Name | text | Yes | From FullNameRequirement |
| Date of Birth | date picker | Conditional | From DOBRequirement if configured |
| Newsletter Signup | checkbox | No | From NewsletterSignupRequirement if configured |

**Smart field reuse:** If the user is logged in and this section collects the same name as the billing section would, pre-fill from user profile. If the product's FullNameRequirement is the billing contact, mark it and skip re-asking in the billing section.

**Collapsed summary:** `Jeremy Tunnell` or `Jeremy Tunnell | DOB: Jan 15, 1985`

---

#### Section 3: Shipping / Address (Shown when product has AddressRequirement)

**Purpose:** Collect physical address when needed (physical products, events requiring location info).

**Fields:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Country | dropdown | Yes | Default to most common country for site |
| Address Line 1 | text | Yes | Street address |
| Address Line 2 | text | No | Apartment, suite, unit |
| City | text | Yes | |
| State/Province | dropdown or text | Yes | Dynamic based on country selection |
| ZIP/Postal Code | text | Yes | Auto-lookup city/state when possible |

**Enhancement:** Integrate address autocomplete (Google Places API) if API key is configured in settings. The setting `google_places_api_key` controls this. If not set, show standard fields.

**Collapsed summary:** `123 Main St, Nashville, TN 37201, US`

---

#### Section 4: Product Options (Shown when product has multiple versions OR UserPriceRequirement)

**Purpose:** Select product version/tier and handle pricing options.

**Fields:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Product Version | radio buttons or dropdown | Yes | Only if multiple ProductVersions exist |
| Custom Amount | currency input | Conditional | From UserPriceRequirement (donations, pay-what-you-want) |

**Display:** Show version cards with name, description, and price for each option. Highlight recommended/popular version if configured.

**Collapsed summary:** `Premium Plan - $29.99/month` or `Custom Amount: $50.00`

---

#### Section 5: Additional Questions (Shown when product has QuestionRequirements)

**Purpose:** Collect survey/custom question responses configured for this product.

**Fields:** Dynamically rendered from the product's QuestionRequirement instances. Each question renders according to its type:

| Question Type | Rendered As |
|---------------|-------------|
| text | Text input or textarea |
| dropdown | Select dropdown |
| checkbox | Checkbox |
| date | Date picker |
| confirmation | Checkbox with agreement text |

**Design considerations:**
- Group questions visually with a brief intro: "Please answer the following questions about your registration."
- Clearly mark required vs optional questions.
- Use conditional display when possible -- if question A's answer triggers question B, use progressive disclosure within this section.
- Maximum of ~5-7 questions in this section to avoid overwhelming users. If more questions are needed, consider splitting into the post-purchase survey system instead.

**Collapsed summary:** Show count: `3 of 3 questions answered` or brief answers if only 1-2 questions.

---

#### Section 6: Coupon Code (Shown when `coupons_active` setting is enabled)

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

#### Section 7: Billing & Account (Always shown)

**Purpose:** Collect billing identity and optional account creation.

**Fields when NOT logged in:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Billing First Name | text | Yes | Pre-fill from Section 2 if available |
| Billing Last Name | text | Yes | Pre-fill from Section 2 if available |
| Billing Email | email | Yes | Pre-fill from Section 1; read-only with [Change] link back to Section 1 |
| Create Password | password | Optional | "Create an account for faster checkout next time" -- checkbox to reveal |
| Terms & Privacy | checkbox | Yes | "I agree to the [Terms of Use] and [Privacy Policy]" |

**Fields when logged in:**
- Show confirmed billing identity (name + email from profile) with [Change] link.
- Terms & Privacy checkbox still required.
- This section can auto-complete if user is logged in and has agreed to terms previously (store agreement timestamp).

**Smart behavior:**
- "Same as registrant information" checkbox at top -- if checked, pre-fill from Section 2 and collapse fields to read-only summary.
- If email in Section 1 matches an existing account, prompt login but don't require it.

**Collapsed summary:** `Jeremy Tunnell (jeremy@example.com) | Account will be created`

---

#### Section 8: Payment (Always shown when total > 0; hidden for free orders)

**Purpose:** Collect payment and complete the order.

**Layout:**
- Payment method tabs/buttons at top: [Credit Card] [PayPal] [Apple Pay/Google Pay] -- shown based on site configuration
- Selected method's form below
- [Place Order] button at the very bottom, styled prominently

**For Stripe Checkout mode (`checkout_type == 'stripe_checkout'`):**
- **Special case:** Stripe Checkout redirects the user away from the page to Stripe's hosted payment form, then back. This is incompatible with an inline accordion payment section.
- **Approach:** The accordion collects all non-payment information (contact, registrant, address, questions, coupon, billing). The final section is NOT a payment form — instead it is an **Order Review** section summarizing everything, with a prominent [Pay with Stripe] button.
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

These sections are defined in the architecture but hidden by default. They can be enabled via settings or product configuration in the future:

| Future Section | Would Appear Between | Purpose |
|----------------|---------------------|---------|
| **Delivery Method** | Sections 3 and 4 | Shipping method selection (standard, express, pickup) |
| **Gift Options** | Sections 5 and 6 | Gift wrapping, gift message, send to different address |
| **Donation/Tip** | Sections 6 and 7 | Optional donation add-on at checkout |
| **Membership Selection** | Section 4 | Tier selection for subscription products (overlap with product options) |

## Order Summary Sidebar

### Desktop (>768px)
A sticky sidebar on the right side, always visible as the user scrolls through accordion sections.

**Contents:**
- Product name and version
- Product thumbnail (if available)
- Registrant name (once entered in Section 2)
- Quantity (currently always 1 per cart item; architecture supports multiples)
- Individual item price
- Coupon discount (when applied)
- Subtotal
- Total

**Behavior:**
- Updates in real-time as sections are completed (registrant name appears, coupon applied, etc.)
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
|  |  [2] Registrant Info        [done]  |  | Product X    |   |
|  |    Jeremy Tunnell        [Edit]     |  | $29.99       |   |
|  |                                     |  |              |   |
|  |  [3] Additional Questions  [ACTIVE] |  | ----------   |   |
|  |    +----------------------------+   |  | Total:       |   |
|  |    | Q1: How did you hear...    |   |  | $29.99       |   |
|  |    | [Dropdown           v]     |   |  |              |   |
|  |    |                            |   |  |              |   |
|  |    | Q2: Special needs?         |   |  |              |   |
|  |    | [Text area             ]   |   |  |              |   |
|  |    |                            |   |  |              |   |
|  |    |          [Continue]        |   |  |              |   |
|  |    +----------------------------+   |  |              |   |
|  |                                     |  |              |   |
|  |  [4] Coupon Code             [---]  |  |              |   |
|  |  [5] Billing & Account       [---]  |  |              |   |
|  |  [6] Payment                 [---]  |  |              |   |
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
| [2] Registrant     [done] |
|   Jeremy T.     [Edit]    |
|                           |
| [3] Questions    [ACTIVE] |
| +----------------------+  |
| | Q1: How did you...   |  |
| | [Dropdown        v]  |  |
| |                       |  |
| | Q2: Special needs?   |  |
| | [_______________]    |  |
| |                       |  |
| |      [Continue]       |  |
| +----------------------+  |
|                           |
| [4] Coupon         [---]  |
| [5] Billing        [---]  |
| [6] Payment        [---]  |
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
- `/views/cart.php` -- Redesigned with accordion UI, order summary sidebar, login modal, checkout-mode header
- `/logic/cart_logic.php` -- Refactored to support AJAX section validation. Extract billing validation into a reusable function callable from both the page POST flow (fallback) and the AJAX endpoint
- `/includes/PublicPage.php` (or theme override) -- Add `noheader` header option for minimal header
- `/views/cart_confirm.php` -- Add inline post-purchase survey rendering

**Unchanged:**
- `/views/product.php` -- Product page stays as-is (per-item info collected here)
- `/logic/product_logic.php` -- No changes needed (already redirects to `/cart`)
- `/logic/cart_charge_logic.php` -- Payment processing stays the same
- `serve.php` -- No new routes needed
- `/data/` model classes -- No changes to data layer
- `/includes/requirements/` -- Product requirements render on product page as before
- `/includes/ShoppingCart.php` -- Cart session management unchanged
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
    "data": { "email": "...", "phone": "..." }
}

Response:
{
    "valid": true,
    "summary": "jeremy@example.com",
    "next_section": "registrant"
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
       'contact' => ['email' => '...', 'phone' => '...'],
       'billing' => ['first_name' => '...', 'last_name' => '...', ...],
   ];
   ```
   Note: Per-item data (registrant, address, questions) is already stored in the cart items via the product page flow. The checkout session only tracks the shared checkout fields.
3. On [Place Order], the checkout logic:
   - Validates all sections server-side
   - Creates ShoppingCart item with collected form data (maps to existing cart structure)
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

## Migration Strategy

### Phase 1: Redesign cart.php in place
- Rebuild `/views/cart.php` with accordion UI
- Refactor `/logic/cart_logic.php` to support AJAX section validation
- Create `/ajax/checkout_ajax.php` for section validation endpoints
- Create `/includes/CheckoutSectionRenderer.php`
- Add checkout-mode header to PublicPage

### Phase 2: Test and iterate
- Test all payment modes (Stripe Checkout, Stripe Regular, PayPal, free orders)
- Test logged-in vs guest flows
- Refine mobile experience
- Gather feedback on section ordering

### Phase 3: Polish
- Add post-purchase survey to cart_confirm.php
- Add survey reminders to profile page and confirmation emails
- Accessibility audit and fixes
- Edge case handling (session timeout, payment errors)

## Edge Cases & Special Handling

### Free Products (price = 0)
- Skip Payment section entirely
- Billing section's [Continue] becomes [Complete Order]
- Show a brief "No payment required" note

### Subscription Products
- Product Options section shows subscription terms (monthly/yearly, trial period)
- Payment section notes "You will be charged [amount] every [period]"
- Only one subscription per checkout (enforce in product options)

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
- [ ] All product requirement types render correctly in appropriate sections
- [ ] Question/survey requirements render and validate
- [ ] Address autocomplete (if configured)
- [ ] Stripe Checkout mode payment
- [ ] Stripe Regular mode payment
- [ ] PayPal payment
- [ ] Error handling for all payment failure types
- [ ] Session timeout recovery
- [ ] Browser back button behavior within accordion

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
1. Build accordion UI component in `cart.php` (open/close, state management)
3. Implement Contact, Billing, and Payment sections
4. Create `/ajax/checkout_ajax.php` with section validation endpoints
5. Order summary sidebar (sticky desktop, collapsible mobile)
6. End-to-end flow working with all payment modes

**Phase 2 (Enhancements):**
7. Coupon section with AJAX apply/remove
8. Login modal for existing email detection
9. Checkout-mode header in PublicPage
10. Smart pre-fill (contact email -> billing email, logged-in user auto-complete)
11. Mobile-optimized layout
12. Progress indicator

**Phase 3 (Survey & Polish):**
13. Post-purchase survey on cart_confirm.php
14. Survey reminders on profile page and in confirmation emails
15. Accessibility audit and fixes
16. Edge case handling (session timeout, payment errors, back button)

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

### Reference specs:
- `/specs/implemented/checkout_improvements.md` -- Security fixes already implemented
- `/specs/event_extra_info_and_surveys.md` -- Survey system status

## Resolved Design Decisions

1. **Multi-item checkout:** Per-item info (registrant, address, questions) is collected on the product page. The accordion checkout handles only shared concerns: contact, coupon, billing, payment.
2. **Address autocomplete:** Skipped for initial implementation. Standard address fields only. See Future Work section.
3. **Cart abandonment emails:** Out of scope. Early email capture in Section 1 lays the groundwork for a future cart recovery email system.
4. **Login modal:** Use a modal overlay on the checkout page. The existing login logic already supports AJAX requests and `lbx_` field naming.
5. **Post-purchase surveys:** Display prominently on the confirmation page (`cart_confirm.php`). Also persist reminders via email and profile page until the survey is completed.

## Post-Purchase Survey on Confirmation Page

When a product or event has an associated survey (`evt_svy_survey_id` or a future `pro_svy_survey_id` field), the confirmation page (`cart_confirm.php`) should:

1. **Display the survey inline** below the order summary, with a clear heading: "We'd love your feedback" or similar.
2. **Use the existing survey rendering** (`survey_logic.php`, `SurveyQuestion`, `SurveyAnswer` classes) to render the questions directly on the confirmation page.
3. **Submit via AJAX** so the user stays on the confirmation page and sees a "Thank you!" message on completion.
4. **If not completed on the confirmation page**, persist reminders:
   - **Email:** Include survey link in the purchase confirmation email (or a follow-up email).
   - **Profile page:** Show a prominent reminder/alert linking to the survey until `evr_survey_completed` is true.
5. **Link survey answers to the order/registration** via `evr_event_registrant_id` or `ord_order_id` so results can be viewed per-event or per-product in admin.

## Future Work

- **Address autocomplete:** Integrate Google Places API (or alternative) for address field auto-completion. Would require a new setting (`google_places_api_key`) and JS integration in the Address section. Reduces mobile errors by ~20% per industry research.
- **Cart abandonment email recovery:** Build on the early email capture from Section 1 to send automated "you left items in your cart" emails. Industry data shows 41% open rate and 20-30% revenue recovery.
- **Delivery method section:** Add a section between Address and Product Options for shipping method selection (standard, express, pickup) when physical product shipping is supported.
- **Gift options section:** Gift wrapping, gift message, and alternate shipping address for gift recipients.
- **Post-purchase survey analytics:** Admin dashboard for survey results per product/event, export functionality, completion rate tracking.
