# Checkout Browser Test Specification

**Status:** Active
**Created:** 2026-03-21
**Priority:** High

## Overview

End-to-end browser tests for the redesigned checkout flow. Tests use the Playwright MCP browser to navigate the site as a real user would — visiting product pages, filling forms, adding items to cart, completing the accordion checkout, and verifying payment processing.

Tests cover all payment methods (Stripe Regular, Stripe Checkout, PayPal, free orders), all product/requirement combinations, cart constraints, error handling, and UI behavior.

## Test Environment

- **URL:** `https://joinerytest.site`
- **Admin login:** `jeremy.tunnell+claude@gmail.com` / `sKU^1cK1pVJCNAv`
- **Test mode:** Enabled via admin session (permission level 10)
- **Stripe test token:** `tok_visa` (simulates valid Visa in test mode)
- **Stripe test keys:** Already configured in site settings
- **PayPal sandbox:** Already configured with sandbox API credentials
- **Currency:** USD

## Test Products Required

Before running tests, ensure these products exist and are active. Create any missing ones via the admin interface.

| Product | Type | Versions | Requirements | Purpose |
|---------|------|----------|-------------|---------|
| **Test Simple Product** | Item | 1 version @ $10.00 | FullName, Email | Basic one-item checkout |
| **Test Multi-Version Product** | Item | 3 versions @ $5, $10, $20 | FullName, Email | Version selection |
| **Test Event Product** | Event | 1 version @ $25.00 | FullName, Email, Phone, Address | Event with all standard requirements |
| **Test Free Product** | Item | 1 version @ $0.00 | FullName, Email | Free checkout flow |
| **Test Subscription Monthly** | Item | 1 version @ $9.99/month | FullName, Email | Stripe subscription |
| **Test Subscription Multi** | Item | 2 versions: $2.99/month, $29.99/year | FullName, Email | Subscription version selection |
| **Test Donation Product** | Item | 1 version, UserPrice | Email | Pay-what-you-want |
| **Test Questions Product** | Item | 1 version @ $15.00 | FullName, Email, 2x QuestionRequirement | Product with custom questions |
| **Test Full Requirements** | Event | 1 version @ $30.00 | FullName, Email, Phone, Address, DOB, Newsletter, QuestionRequirement | All requirement types |
| **Test Max Cart Product** | Item | 1 version @ $5.00, max_cart_count=2 | FullName, Email | Cart quantity limits |

## Test Structure

Each test follows this pattern:
1. **Setup** — Log in as admin, ensure test mode active, clear cart
2. **Product page** — Navigate to product, fill requirements, add to cart
3. **Checkout page** — Complete accordion sections (contact, coupon, billing, payment)
4. **Verification** — Check confirmation page, verify order in database

---

## Test Suite 1: Product Page

### Test 1.1: Simple product with card grouping
1. Navigate to Test Simple Product page
2. **Verify:** Two-column layout — product info left, form right
3. **Verify:** "Your Information" card contains First Name, Last Name, Email fields
4. **Verify:** No "Address" or "Additional Questions" cards shown (only one group = no card headers)
5. Fill in name and email
6. Click "Add to Cart"
7. **Verify:** Redirected to `/cart`
8. **Verify:** Order summary shows product name and registrant name

### Test 1.2: Product with multiple requirement groups
1. Navigate to Test Full Requirements Product page
2. **Verify:** "Your Information" card with name, email, phone, DOB, newsletter
3. **Verify:** "Address" card with country, street, city, state, zip
4. **Verify:** "Additional Questions" card with the configured question
5. Fill all required fields
6. Click "Add to Cart"
7. **Verify:** Redirected to `/cart` with all data in order summary

### Test 1.3: Multi-version product selection
1. Navigate to Test Multi-Version Product page
2. **Verify:** Version dropdown shows all 3 versions with prices
3. Select the $20 version
4. Fill required fields
5. Click "Add to Cart"
6. **Verify:** Order summary shows correct version name and $20.00 price

### Test 1.4: Donation / pay-what-you-want product
1. Navigate to Test Donation Product page
2. **Verify:** Amount input field shown
3. Enter $42.00
4. Fill email
5. Click "Add to Cart"
6. **Verify:** Order summary shows $42.00

### Test 1.5: Edit cart item from checkout
1. Add Test Simple Product to cart (from prior test or fresh)
2. On checkout page, click "Edit" link on the item in order summary
3. **Verify:** Redirected to product page with `?edit_item=0`
4. **Verify:** Form fields pre-filled with previously entered data
5. **Verify:** Button says "Update Cart" instead of "Add to Cart"
6. Change the last name
7. Click "Update Cart"
8. **Verify:** Redirected to `/cart`
9. **Verify:** Order summary shows updated name

### Test 1.6: Max cart count enforcement
1. Add Test Max Cart Product to cart twice (two separate add-to-cart actions)
2. **Verify:** Both items appear in order summary
3. Navigate to Test Max Cart Product page again
4. Try to add a third
5. **Verify:** Error message about exceeding max cart count

### Test 1.7: Sold out product
1. Find or create a product with `pro_num_remaining_calc = 0`
2. Navigate to product page
3. **Verify:** "Sold Out" message displayed
4. **Verify:** No Add to Cart button

---

## Test Suite 2: Checkout Accordion — UI & Navigation

### Test 2.1: Accordion structure and initial state
1. Add a product to cart
2. Navigate to `/cart`
3. **Verify:** Minimal checkout header (logo + "Secure Checkout" + lock icon)
4. **Verify:** No site navigation menu
5. **Verify:** Progress indicator shows "Step X of Y"
6. **Verify:** Progress bar visible
7. **Verify:** Order summary sidebar on right (desktop) with product details
8. **Verify:** Correct sections shown based on configuration:
   - Contact (always)
   - Coupon (if `coupons_active` setting is true)
   - Billing & Account (always)
   - Payment (if total > 0)

### Test 2.2: Section progression for logged-in user
1. Log in as test admin
2. Add product to cart, go to `/cart`
3. **Verify:** Contact section auto-completed with logged-in email
4. **Verify:** Coupon section is active (first non-completed section)
5. Click "Continue" in coupon section (without entering a code)
6. **Verify:** Coupon marked completed with "No coupon" summary
7. **Verify:** Billing section now active
8. **Verify:** Billing shows name and email from profile (read-only)
9. Check the Terms checkbox
10. Click "Continue"
11. **Verify:** Payment section now active
12. **Verify:** Progress bar updated

### Test 2.3: Section progression for guest user
1. Log out
2. Add product to cart, go to `/cart`
3. **Verify:** Contact section is active with empty email field
4. Enter email address
5. Click "Continue"
6. **Verify:** Page reloads with contact completed, coupon or billing active
7. Complete coupon (Continue)
8. **Verify:** Billing section shows editable name fields (no name from cart = editable)
9. Fill first name, last name
10. Check Terms checkbox
11. Click "Continue"
12. **Verify:** Payment section active

### Test 2.4: Edit completed sections
1. After reaching payment section, click "Edit" on Contact section header
2. **Verify:** Contact section re-opens
3. **Verify:** Email is still filled in
4. Change email, click Continue
5. **Verify:** Updated email shown in completed summary

### Test 2.5: Existing email detection
1. Log out
2. Add product to cart, go to `/cart`
3. Enter an email that exists in the system (e.g., `jeremy.tunnell+claude@gmail.com`)
4. Click outside the email field (blur)
5. **Verify:** "Welcome back! Log in for faster checkout" message appears
6. Click "Log in" link
7. **Verify:** Login modal overlay appears
8. **Verify:** Email pre-filled in modal
9. Enter correct password
10. Click "Log In"
11. **Verify:** Page reloads with user logged in, sections auto-completed

### Test 2.6: Login modal with wrong password
1. Repeat 2.5 steps 1-8
2. Enter wrong password
3. Click "Log In"
4. **Verify:** Error message "Invalid email or password" shown in modal
5. **Verify:** Modal stays open, user can retry

### Test 2.7: Empty cart
1. Navigate to `/cart` with no items
2. **Verify:** "Your cart is empty" message shown with cart icon
3. **Verify:** "Browse Products" button links to `/products`
4. **Verify:** No accordion sections visible

### Test 2.8: Back button behavior
1. Complete contact and coupon sections
2. Press browser back button
3. **Verify:** Returns to previous section (not a different page)
4. Press forward button
5. **Verify:** Returns to the section that was active

### Test 2.9: Mobile layout (resize to 375px width)
1. Add product to cart, go to `/cart`
2. Resize browser to 375px width
3. **Verify:** Order summary sidebar hidden
4. **Verify:** Collapsible order summary bar at top with product name and total
5. Tap the summary bar
6. **Verify:** Expands to show full order details
7. **Verify:** Accordion sections stack vertically, full width

---

## Test Suite 3: Coupon System

### Test 3.1: Apply valid coupon via AJAX
1. Add product to cart, proceed to coupon section
2. Enter valid test coupon code in the input field
3. Click "Apply"
4. **Verify:** Page reloads with coupon applied
5. **Verify:** Green badge shows coupon code
6. **Verify:** Order summary total reflects discount

### Test 3.2: Apply invalid coupon
1. In coupon section, enter "INVALIDCODE"
2. Click "Apply"
3. **Verify:** Error message "Coupon code not found." appears below input
4. **Verify:** No page reload (error shown inline)

### Test 3.3: Remove applied coupon
1. Apply a valid coupon first
2. Click the × button on the coupon badge
3. **Verify:** Page reloads with coupon removed
4. **Verify:** Order summary total reverts to original price

### Test 3.4: Test mode coupon buttons
1. **Verify:** Test mode banner is visible
2. **Verify:** "Test coupons:" section shows available test coupon buttons
3. Click a test coupon button
4. **Verify:** Coupon applied (page reloads with coupon active)

---

## Test Suite 4: Payment — Stripe Regular

**Precondition:** Set `checkout_type` to `stripe_regular` in admin settings.

### Test 4.1: Successful Stripe Regular payment
1. Add Test Simple Product ($10.00) to cart
2. Complete Contact section (email)
3. Skip Coupon section
4. Complete Billing section (name, terms)
5. **Verify:** Payment section shows Stripe card form (card number, expiry, CVC)
6. Enter test card: `4242 4242 4242 4242`, expiry `12/28`, CVC `123`
7. Click "Place Order"
8. **Verify:** Button shows "Processing..."
9. **Verify:** Redirected to `/cart_confirm`
10. **Verify:** "Purchase Confirmed!" message with checkmark
11. **Verify:** Order summary shows correct product and price
12. **Verify database:** `SELECT ord_status, ord_payment_method FROM ord_orders ORDER BY ord_order_id DESC LIMIT 1` → status = paid, method = stripe

### Test 4.2: Stripe Regular with declined card
1. Add product, complete through payment section
2. Enter declined test card: `4000 0000 0000 0002`, expiry `12/28`, CVC `123`
3. Click "Place Order"
4. **Verify:** Error message displayed on checkout page (NOT an error page/exception)
5. **Verify:** User remains on checkout page with cart intact
6. **Verify:** Can retry with a different card

### Test 4.3: Stripe Regular subscription
1. Add Test Subscription Monthly ($9.99/month) to cart
2. Complete all sections
3. Enter test card `4242 4242 4242 4242`
4. Click "Place Order"
5. **Verify:** Redirected to confirmation page
6. **Verify:** Order shows subscription details
7. **Verify database:** Order item has subscription-related fields set

### Test 4.4: Stripe Regular with coupon discount
1. Add product ($10.00) to cart
2. Apply test coupon that gives a discount
3. Complete billing
4. **Verify:** Payment section shows discounted total
5. Complete payment with test card
6. **Verify:** Order total reflects discount

---

## Test Suite 5: Payment — Stripe Checkout

**Precondition:** Set `checkout_type` to `stripe_checkout` in admin settings.

### Test 5.1: Stripe Checkout redirect flow
1. Add Test Simple Product to cart
2. Complete Contact, Coupon, Billing sections
3. **Verify:** Payment section shows "Review & Pay" heading
4. **Verify:** [Pay with Stripe] button visible (no inline card form)
5. Click "Pay with Stripe"
6. **Verify:** Redirected to Stripe's hosted checkout page
7. On Stripe page, enter test card `4242 4242 4242 4242`
8. Complete payment on Stripe
9. **Verify:** Redirected back to `/cart_confirm`
10. **Verify:** "Purchase Confirmed!" shown
11. **Verify database:** `ord_payment_method = 'stripe_checkout'`

### Test 5.2: Stripe Checkout cancellation
1. Add product, complete through payment
2. Click "Pay with Stripe"
3. On Stripe page, click "Back" or close the page
4. **Verify:** Returns to checkout page
5. **Verify:** Cart and billing info preserved
6. **Verify:** Can retry payment

### Test 5.3: Stripe Checkout subscription
1. Add Test Subscription Monthly to cart
2. Complete all sections
3. Click "Pay with Stripe"
4. **Verify:** Stripe checkout page shows subscription terms (recurring amount, interval)
5. Complete payment
6. **Verify:** Subscription created in Stripe test dashboard

---

## Test Suite 6: Payment — PayPal

**Precondition:** `use_paypal_checkout` = `1` in settings.

### Test 6.1: PayPal one-time payment
1. Add non-subscription product to cart
2. Complete Contact, Coupon, Billing
3. **Verify:** PayPal smart buttons visible in payment section
4. Click PayPal button
5. **Verify:** PayPal popup/redirect opens
6. Log in with PayPal sandbox account
7. Complete payment
8. **Verify:** Redirected to confirmation page
9. **Verify database:** `ord_payment_method = 'paypal'`

### Test 6.2: PayPal subscription — single subscription
1. Add Test Subscription Monthly to cart (only item)
2. Complete through payment
3. **Verify:** PayPal subscription button visible
4. Complete PayPal subscription flow
5. **Verify:** Order created with PayPal payment method

### Test 6.3: PayPal subscription restriction — cannot mix with other items
1. Add Test Simple Product (non-subscription) to cart
2. Navigate to Test Subscription Monthly product page
3. Try to add subscription to cart
4. **Verify:** Error message: "Sorry, the cart may contain only one subscription..."
5. **Verify:** Subscription NOT added to cart

### Test 6.4: PayPal subscription restriction — cannot add non-subscription to subscription cart
1. Clear cart, add Test Subscription Monthly to cart
2. Navigate to Test Simple Product page
3. Try to add non-subscription product
4. **Verify:** Error message about cart containing a subscription
5. **Verify:** Item NOT added

### Test 6.5: Mixed payment methods display
1. Ensure both Stripe and PayPal are enabled
2. Add non-subscription product to cart, complete through billing
3. **Verify:** Payment section shows BOTH Stripe form/button AND PayPal buttons
4. **Verify:** User can choose either payment method

---

## Test Suite 7: Free Checkout

### Test 7.1: Free product — no payment section
1. Add Test Free Product ($0.00) to cart
2. Navigate to `/cart`
3. **Verify:** Payment section NOT shown (only Contact, Coupon, Billing)
4. **Verify:** Billing section "Continue" button says "Complete Order"
5. Complete Contact and Coupon sections
6. Check Terms, click "Complete Order"
7. **Verify:** Redirected to confirmation page
8. **Verify database:** `ord_payment_method = 'free'`, `ord_status = 'paid'`

### Test 7.2: Coupon makes order free
1. Add $10 product to cart
2. Apply a coupon that gives 100% discount (or $10+ discount)
3. **Verify:** Total becomes $0.00
4. **Verify:** Payment section hidden or shows "No payment required"
5. Complete billing, click "Complete Order"
6. **Verify:** Order processed as free

---

## Test Suite 8: Multi-Item Cart

### Test 8.1: Multiple different products
1. Add Test Simple Product ($10) to cart
2. Add Test Questions Product ($15) to cart
3. Navigate to `/cart`
4. **Verify:** Order summary shows both items with individual prices
5. **Verify:** Total shows $25.00
6. **Verify:** Each item has Edit and Remove links
7. Complete checkout
8. **Verify:** Both items on confirmation page

### Test 8.2: Remove item from cart
1. Add two products to cart
2. Click "Remove" on the first item in order summary
3. **Verify:** Page reloads with only the second item
4. **Verify:** Total updated

### Test 8.3: Cart with subscription restriction (PayPal enabled)
1. Add non-subscription product
2. Try to add subscription product
3. **Verify:** Error message about subscription mixing
4. Remove non-subscription product
5. Add subscription product
6. **Verify:** Subscription in cart alone

---

## Test Suite 9: Error Handling

### Test 9.1: Payment error returns to checkout
1. Add product, complete through payment
2. Trigger a payment error (declined card `4000 0000 0000 0002` for Stripe Regular)
3. **Verify:** Redirected back to `/cart` (NOT an error page)
4. **Verify:** Error message displayed in styled alert at top of checkout
5. **Verify:** Alert has warning icon, red background, clear message text
6. **Verify:** Cart items preserved
7. **Verify:** Billing info preserved
8. **Verify:** User can immediately retry

### Test 9.2: Invalid Stripe session
1. Navigate directly to `/cart_charge?session_id=invalid_session_123`
2. **Verify:** Redirected to `/cart` with error message
3. **Verify:** NOT a fatal error page

### Test 9.3: Missing billing information
1. Add product to cart
2. Try to trigger payment without completing billing
3. **Verify:** Validation prevents progression
4. **Verify:** Appropriate error messages shown

### Test 9.4: Expired coupon during payment
1. Add product, apply a coupon, complete billing
2. (Admin: deactivate the coupon between steps)
3. Complete payment
4. **Verify:** Error about invalid coupon, redirected to checkout page
5. **Verify:** Not a fatal error

### Test 9.5: Session timeout simulation
1. Add product to cart
2. Clear session cookies
3. Navigate to `/cart`
4. **Verify:** Empty cart message (not an error page)
5. **Verify:** Can start fresh

---

## Test Suite 10: Accessibility

### Test 10.1: Keyboard navigation
1. Add product, go to `/cart`
2. Tab through all interactive elements
3. **Verify:** Focus visible on all elements (buttons, inputs, links)
4. **Verify:** Can activate Continue buttons with Enter key
5. **Verify:** Can expand/collapse completed sections with Enter/Space
6. **Verify:** Tab order follows visual order (contact → coupon → billing → payment)

### Test 10.2: ARIA attributes
1. Inspect accordion sections
2. **Verify:** Each section header has `role="button"`, `aria-expanded`, `aria-controls`
3. **Verify:** Each section body has `role="region"`, `aria-labelledby`
4. **Verify:** Required fields have `aria-required="true"`
5. **Verify:** Error messages linked via `aria-describedby`
6. **Verify:** `aria-live="polite"` region exists for announcements

### Test 10.3: Screen reader announcements
1. Open browser with screen reader simulation
2. Complete contact section
3. **Verify:** Status element announces "Coupon Code section is now active"

---

## Test Suite 11: Product Page UI

### Test 11.1: Two-column responsive layout
1. Navigate to product page at desktop width (1200px)
2. **Verify:** Product info on left, form on right
3. Resize to 375px
4. **Verify:** Single column — product info stacked above form

### Test 11.2: Card grouping with all requirement types
1. Navigate to Test Full Requirements product
2. **Verify:** "Your Information" card: First Name, Last Name, Email, Phone, DOB, Newsletter
3. **Verify:** "Address" card: Country, Street, Apt, City, State, Zip
4. **Verify:** "Additional Questions" card: configured question(s)
5. **Verify:** Each card has a distinct visual boundary (shadow, padding)

### Test 11.3: Single group — no card headers
1. Navigate to Test Simple Product (only FullName + Email requirements)
2. **Verify:** Fields shown directly without "Your Information" card header
3. **Verify:** No unnecessary visual grouping

### Test 11.4: Version selector
1. Navigate to Test Multi-Version Product
2. **Verify:** Dropdown shows all versions with prices
3. Select different version
4. **Verify:** (If visible) displayed price updates

---

## Test Suite 12: Post-Purchase

### Test 12.1: Confirmation page order summary
1. Complete a purchase
2. **Verify:** Confirmation page shows:
   - Green checkmark
   - "Purchase Confirmed!" heading
   - Order table with item name, registrant, and price
   - Total
   - "View All Purchases" and "Back to Home" buttons

### Test 12.2: Optional survey on confirmation page
1. Configure a test event with `evt_survey_display = 'optional_at_confirmation'` and a linked survey
2. Purchase the event product
3. **Verify:** Survey form rendered below order summary on confirmation page
4. **Verify:** "We'd Love Your Feedback" heading shown
5. Fill in survey answers
6. Click "Submit Feedback"
7. **Verify:** Form replaced with "Thank you for your feedback!" message (AJAX, no page reload)
8. **Verify database:** Survey answers stored in `sva_survey_answers`
9. **Verify database:** `evr_survey_completed = true` on event registrant

### Test 12.3: Survey reminder on profile page
1. Configure event with optional survey, purchase, but do NOT complete the survey
2. Navigate to `/profile`
3. **Verify:** Alert shown: "Feedback requested: Please complete the survey for [Event Name]"
4. **Verify:** "Take Survey" button links to survey page
5. Complete the survey
6. Return to `/profile`
7. **Verify:** Alert no longer shown

---

## Test Execution Order

Run tests in this order for efficiency (each builds on prior state):

1. **Suite 11** (Product Page UI) — verifies forms render correctly
2. **Suite 1** (Product Page) — adds items to cart
3. **Suite 2** (Accordion UI) — tests checkout navigation
4. **Suite 3** (Coupons) — tests discounts
5. **Suite 7** (Free Checkout) — simplest payment path
6. **Suite 4** (Stripe Regular) — primary payment method
7. **Suite 5** (Stripe Checkout) — alternate Stripe mode
8. **Suite 6** (PayPal) — alternate payment processor
9. **Suite 8** (Multi-Item) — complex cart scenarios
10. **Suite 9** (Error Handling) — negative paths
11. **Suite 10** (Accessibility) — compliance
12. **Suite 12** (Post-Purchase) — surveys and confirmation

## Database Verification Queries

```sql
-- Most recent order
SELECT ord_order_id, ord_status, ord_payment_method, ord_total_cost, ord_test_mode, ord_timestamp
FROM ord_orders ORDER BY ord_order_id DESC LIMIT 1;

-- Order items for an order
SELECT odi_order_item_id, odi_pro_product_id, odi_prv_product_version_id, odi_amount
FROM odi_order_details WHERE odi_ord_order_id = ?;

-- Event registrant for an order
SELECT evr_event_registrant_id, evr_evt_event_id, evr_survey_completed
FROM evr_event_registrants WHERE evr_ord_order_id = ?;

-- Survey answers for a user
SELECT sva_survey_answer_id, sva_svy_survey_id, sva_qst_question_id, sva_answer
FROM sva_survey_answers WHERE sva_usr_user_id = ? ORDER BY sva_survey_answer_id DESC;

-- Clean up test orders (after testing)
-- DELETE FROM ord_orders WHERE ord_test_mode = true AND ord_timestamp > '2026-03-21';
```

## Notes

- All tests should be run in test mode to avoid real charges
- Stripe test cards: `4242424242424242` (success), `4000000000000002` (decline)
- PayPal sandbox accounts needed for PayPal tests
- After testing, clean up test orders with the database query above
- The `checkout_type` setting must be changed between Stripe Regular and Stripe Checkout test suites — use admin settings page
