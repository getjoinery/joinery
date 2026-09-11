# Checkout Browser Test Specification — Part 2

**Status:** Active
**Created:** 2026-03-22
**Priority:** High
**Depends on:** checkout_browser_test.md (Part 1)

## Overview

Part 2 covers all paths and edge cases not covered in Part 1 testing. Focuses on: guest checkout, Stripe Checkout mode, coupon removal, login modal, profile survey reminders, and mobile layout.

## Test Suite 13: Guest Checkout Flow

The most critical untested path — a non-logged-in user purchasing a product.

### Test 13.1: Full guest checkout with Stripe Regular
1. Log out (navigate to `/logout`)
2. Navigate to a product page (Shadow Webinar Level 2, student price $100)
3. **Verify:** Name and email fields are empty (not pre-filled)
4. Fill in: First Name "Guest", Last Name "Tester", Email "guest-test-checkout@joinerytest.site"
5. Click "Add to Cart"
6. **Verify:** Redirected to `/cart`
7. **Verify:** Contact section is ACTIVE (not auto-completed)
8. **Verify:** Email field shown with value pre-filled from cart item
9. Click "Continue" in Contact section
10. **Verify:** Page reloads, Contact completed, next section active
11. Skip Coupon (Continue)
12. **Verify:** Billing section active
13. **Verify:** Name fields shown (editable, pre-filled from cart item "Guest Tester")
14. **Verify:** Email shown read-only from Contact
15. **Verify:** "Create an account" checkbox visible
16. **Verify:** Terms checkbox visible
17. Check Terms checkbox
18. Click "Continue"
19. **Verify:** Payment section active
20. Enter test card 4242424242424242, expiry 12/28, CVC 123, ZIP 37201
21. Click "Pay with Stripe"
22. **Verify:** Redirected to `/cart_confirm`
23. **Verify:** "Purchase Confirmed!" shown
24. **Verify database:** Order created with payment_method = 'stripe', status = paid
25. **Verify database:** New user created with email "guest-test-checkout@joinerytest.site"

### Test 13.2: Guest checkout with account creation
1. Log out
2. Add product, go to checkout
3. Fill Contact email (use a fresh email)
4. Continue to Billing
5. Check "Create an account for faster checkout next time"
6. **Verify:** Password field appears
7. Enter a password
8. Check Terms
9. Continue to Payment, complete with test card
10. **Verify:** Order succeeds
11. **Verify database:** User created with a hashed password (not the random fallback)

### Test 13.3: Guest with existing email — login prompt
1. Log out
2. Add product, go to checkout
3. Enter email of existing user (e.g., `jeremy.tunnell+claude@gmail.com`)
4. Click outside email field (blur)
5. **Verify:** "Welcome back! Log in for faster checkout" message appears
6. Click "Continue" (proceed as guest)
7. **Verify:** Checkout continues without blocking — guest purchase allowed with existing email

### Test 13.4: Guest with existing email — login modal
1. Log out
2. Add product, go to checkout
3. Enter email of existing user
4. Wait for "Welcome back" message
5. Click "Log in" link in the message
6. **Verify:** Login modal overlay appears
7. **Verify:** Email pre-filled in modal
8. Enter correct password
9. Click "Log In"
10. **Verify:** Page reloads with user logged in
11. **Verify:** Contact section auto-completed
12. **Verify:** Billing section shows profile name/email

### Test 13.5: Guest with existing email — login modal wrong password
1. Repeat 13.4 steps 1-7
2. Enter wrong password
3. Click "Log In"
4. **Verify:** Error "Invalid email or password" shown in modal
5. **Verify:** Modal stays open
6. Close modal (× button)
7. **Verify:** Modal closes, checkout page intact
8. Click "Continue" to proceed as guest
9. **Verify:** Checkout continues normally

---

## Test Suite 14: Stripe Checkout Mode

**Precondition:** Change `checkout_type` to `stripe_checkout` in admin settings.

### Test 14.1: Stripe Checkout redirect flow (logged-in)
1. Log in as admin
2. Change setting: Admin > Settings > `checkout_type` = `stripe_checkout`
3. Add product (Shadow Webinar Level 2, $100)
4. Go to `/cart`
5. **Verify:** Payment section shows "Review & Pay" or similar (NOT inline card form)
6. **Verify:** A "Pay with Stripe" button that will redirect (no card number fields)
7. Click "Pay with Stripe"
8. **Verify:** Redirected to Stripe's hosted checkout page (checkout.stripe.com)
9. On Stripe page, enter test card 4242424242424242
10. Complete payment on Stripe
11. **Verify:** Redirected back to `/cart_confirm`
12. **Verify:** "Purchase Confirmed!" shown
13. **Verify database:** `ord_payment_method = 'stripe_checkout'`

### Test 14.2: Stripe Checkout cancellation
1. Add product, proceed to payment
2. Click "Pay with Stripe"
3. **Verify:** Redirected to Stripe
4. Click back/close on Stripe page
5. **Verify:** Returns to `/cart`
6. **Verify:** Cart and billing info preserved

### Test 14.3: Restore setting
1. Change `checkout_type` back to `stripe_regular`

---

## Test Suite 15: Coupon Edge Cases

### Test 15.1: Remove applied coupon
1. Log in, add product ($100), go to checkout
2. Open Coupon section, apply "test-code"
3. **Verify:** Green badge with × button appears
4. Click × to remove coupon
5. **Verify:** Page reloads without coupon
6. **Verify:** Total restored to original price
7. **Verify:** Payment section reappears (if it was hidden due to $0 total)

### Test 15.2: Apply invalid coupon code
1. Open Coupon section
2. Type "FAKECODE123" in input
3. Click "Apply"
4. **Verify:** Error message "Coupon code not found." appears inline
5. **Verify:** No page reload or error page

### Test 15.3: Apply coupon via text input
1. Open Coupon section
2. Type "test-code" in the input field (not the test button)
3. Click "Apply"
4. **Verify:** Coupon applied, page reloads with discount

---

## Test Suite 16: Login Modal

### Test 16.1: Modal open/close
1. Log out, add product, go to checkout
2. Enter existing user email in Contact
3. Wait for "Welcome back" prompt
4. Click "Log in" link
5. **Verify:** Modal appears with semi-transparent overlay
6. **Verify:** Email pre-filled
7. **Verify:** Password field focused
8. Click × button
9. **Verify:** Modal closes
10. **Verify:** Checkout page still intact

### Test 16.2: Successful modal login
1. Open modal with existing email
2. Enter correct password
3. Click "Log In"
4. **Verify:** Page reloads
5. **Verify:** User now logged in (admin bar shows user name)
6. **Verify:** Contact auto-completed
7. **Verify:** Billing auto-completed with profile data

---

## Test Suite 17: Mobile Layout

### Test 17.1: Mobile order summary
1. Add product, go to `/cart`
2. Resize browser to 375px width
3. **Verify:** Desktop order summary sidebar hidden
4. **Verify:** Collapsible summary bar visible at top
5. **Verify:** Shows product name and total
6. Tap/click the summary bar
7. **Verify:** Expands showing item details
8. Tap again
9. **Verify:** Collapses

### Test 17.2: Mobile accordion
1. At 375px width
2. **Verify:** Accordion sections stack full width
3. **Verify:** Buttons are full width
4. **Verify:** Text is readable, no horizontal scrolling

---

## Test Suite 18: Profile Survey Reminders

### Test 18.1: No survey reminders when none pending
1. Log in, go to `/profile`
2. **Verify:** No "Feedback requested" alerts shown (unless there are genuinely pending surveys)

### Test 18.2: Survey reminder displays (if applicable)
1. If any events have `evt_survey_display = 'optional_at_confirmation'` or `'after_event'` with incomplete surveys:
2. Go to `/profile`
3. **Verify:** Alert shown with event name and "Take Survey" button
4. **Verify:** Button links to survey page

---

## Test Suite 19: Confirmation Page Survey

### Test 19.1: No survey when not configured
1. Purchase a product whose event has no survey configured
2. **Verify:** Confirmation page shows order summary and "What's Next?" only
3. **Verify:** No survey form rendered

### Test 19.2: Survey renders when configured
1. Configure a test event with `evt_survey_display = 'optional_at_confirmation'` and link a survey
2. Purchase the event's product
3. **Verify:** Survey form appears below order summary
4. **Verify:** "We'd Love Your Feedback" heading
5. Fill answers, click "Submit Feedback"
6. **Verify:** AJAX submission — form replaced with "Thank you" message (no page reload)
7. **Verify database:** `evr_survey_completed = true`

---

## Test Suite 20: Edge Cases and Error Recovery

### Test 20.1: Direct navigation to /cart_charge without cart
1. Navigate directly to `/cart_charge`
2. **Verify:** Redirected to `/cart_confirm` (existing behavior for empty cart)
3. **Verify:** "Purchase Not Found" message, NOT a fatal error

### Test 20.2: Invalid session_id for Stripe Checkout
1. Navigate to `/cart_charge?session_id=cs_test_invalid_12345`
2. **Verify:** Redirected to `/cart` with error message
3. **Verify:** NOT a fatal error page

### Test 20.3: Billing validation — missing terms
1. Add product, reach billing section (as guest)
2. Fill name but do NOT check Terms
3. Click "Continue"
4. **Verify:** Client-side error "You must agree to the terms"
5. **Verify:** Does not submit

### Test 20.4: Contact validation — invalid email
1. Enter "not-an-email" in Contact email
2. Click "Continue"
3. **Verify:** Error "Please enter a valid email address" shown
4. **Verify:** Does not advance

### Test 20.5: Remove last item from cart
1. Add a product, go to `/cart`
2. Click "Remove" on the only item
3. **Verify:** Cart becomes empty
4. **Verify:** "Your cart is empty" message shown
5. **Verify:** No accordion sections visible

### Test 20.6: Add to cart when product page opened via edit_item but item was removed
1. Navigate to `/product/basic-plan?edit_item=99` (nonexistent index)
2. **Verify:** Page loads without error
3. **Verify:** Shows "Add to Cart" (not "Update Cart") since the item doesn't exist
4. Fill fields, click "Add to Cart"
5. **Verify:** Item added as new (not crash)

---

## Test Execution Order

1. Suite 13 (Guest checkout — highest priority)
2. Suite 20 (Edge cases — find remaining bugs)
3. Suite 15 (Coupon edge cases)
4. Suite 16 (Login modal)
5. Suite 17 (Mobile layout)
6. Suite 14 (Stripe Checkout mode — requires setting change)
7. Suite 18 (Profile reminders)
8. Suite 19 (Confirmation survey)
