# ScrollDaddy Billing Testing Plan

## Pre-Test Setup: What to Import/Configure from Prod

**Already in place on test site:**
- 3 subscription tiers (Basic $2.99/mo, Premium $7.99/mo, Family/Pro $18.99/mo) with yearly pricing
- Stripe test-mode keys and test price IDs for all 6 product versions
- PayPal test keys configured
- Subscription management settings (upgrade, downgrade, cancel all enabled, immediate timing)
- 1 test coupon code (`test-code`, $5 off)

**Needs to be done before testing:**

| Item | Why | How |
|------|-----|-----|
| **Configure Stripe webhook secret** (Phase 10 only) | Only needed for webhook reliability testing. Core flows (purchase, upgrade, downgrade, cancel, reactivate) all work synchronously without webhooks. | In Stripe test dashboard, add webhook endpoint `https://joinerytest.site/ajax/stripe_webhook` for events: `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`. Copy the `whsec_...` secret to admin Settings > Payments. |
| **Verify Stripe test products exist** | The test price IDs (e.g. `price_1SHSq...`) must exist in your Stripe test account | Check Stripe test dashboard > Products. If missing, delete the test price IDs from product versions and let StripeHelper auto-create them on first checkout. |
| **Import prod coupon codes** (optional) | Only 1 test coupon exists; prod may have real promo codes worth testing | Export from prod `ccd_coupon_codes` table or manually create test coupons in admin |
| **Create test user accounts** | Need users at various subscription states | Create 4-5 test users via `/register` during testing |
| **Verify `checkout_type` setting** | Currently `stripe_regular` — confirm this matches what prod will use, or test both modes | Check admin Settings > Payments; consider testing with `stripe_checkout` too |
| **PayPal sandbox accounts** | PayPal test mode needs sandbox buyer/seller accounts | Sandbox buyer account configured (see credentials below) |

### Stripe Test Card Numbers

Use these for all payment tests:
- **Success:** `4242 4242 4242 4242` (any future exp, any CVC)
- **Decline:** `4000 0000 0000 0002`
- **Requires auth (3D Secure):** `4000 0025 0000 3155`
- **Insufficient funds:** `4000 0000 0000 9995`

### PayPal Sandbox Buyer Account

- **Email:** `sb-wkoy826272676@personal.example.com`
- **Password:** `EYnn>zb9`

---

## Phase 1: Pricing Page & Product Display

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 1.1 | Pricing page loads (logged out) | Navigate to `/scrolldaddy/pricing` | 3 tiers displayed with correct prices, monthly/yearly toggle works |
| 1.2 | Monthly/yearly toggle | Click yearly toggle on pricing page | Prices switch to $29.99/$79.99/$199.99, "save 17%" messaging shown |
| 1.3 | "Get Started" links | Click Get Started on each tier | Redirects to product page or cart with correct product |
| 1.4 | Individual product pages | Navigate to each product's URL | Product detail page shows correct name, price, description, subscription terms |

---

## Phase 2: New User Signup & First Purchase

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 2.1 | Register new account | Go to `/register`, fill form, submit | Account created, logged in, redirected |
| 2.2 | Purchase Basic monthly | Add Basic Plan (monthly) to cart, checkout with `4242...` | Payment succeeds, order confirmation shown, receipt email sent |
| 2.3 | Verify tier assignment | Go to `/profile/subscriptions` | Shows active Basic Plan subscription, correct next billing date |
| 2.4 | Verify device limit | Go to `/profile/scrolldaddy/devices` | Max devices = 1 (Basic tier feature) |
| 2.5 | Verify Stripe subscription | Check Stripe test dashboard | Customer created, subscription active, correct price |
| 2.6 | Purchase Basic yearly | New user, add Basic Plan (yearly) to cart, pay | Payment $29.99, subscription set to yearly billing |
| 2.7 | Purchase Premium monthly | New user, add Premium Plan, pay | Tier assigned = Premium, max devices = 3 |
| 2.8 | Purchase Family/Pro yearly | New user, add Family/Pro (yearly), pay | Tier = Family/Pro, max devices = 10, custom rules = yes |

---

## Phase 3: Payment Failures & Edge Cases

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 3.1 | Declined card | Checkout with `4000 0000 0000 0002` | Error message displayed on cart page, no order created |
| 3.2 | Insufficient funds | Checkout with `4000 0000 0000 9995` | Friendly error message, user can retry |
| 3.3 | 3D Secure required | Checkout with `4000 0025 0000 3155` | 3D Secure modal appears, complete auth, payment succeeds |
| 3.4 | 3D Secure failed | Checkout with `4000 0025 0000 3155`, fail auth | Payment fails gracefully, error shown |
| 3.5 | Empty cart checkout | Navigate to `/cart_charge` with empty cart | Redirected to confirmation or cart, no crash |
| 3.6 | Double-submit prevention | Submit payment, quickly click again | Second submission blocked or idempotent (no double charge) |

---

## Phase 4: Coupon/Promo Codes

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 4.1 | Valid coupon code | Add product to cart, enter `test-code` | $5 discount applied, total reduced |
| 4.2 | Invalid coupon code | Enter `FAKECODE` | Error message: invalid code |
| 4.3 | Coupon + subscription | Apply coupon to subscription product, checkout | First payment reduced by coupon amount, subscription created |
| 4.4 | Expired/inactive coupon | Deactivate coupon in admin, try applying | Rejected with appropriate message |

---

## Phase 5: Subscription Upgrades

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 5.1 | Basic to Premium (same billing cycle) | User with Basic monthly, go to `/profile/change-tier`, select Premium monthly | Subscription updated in Stripe, tier changes to Premium, prorated charge |
| 5.2 | Verify upgraded features | After upgrade, check `/profile/scrolldaddy/devices` | Max devices now = 3 (Premium), advanced filters enabled |
| 5.3 | Basic to Family/Pro | User with Basic, upgrade to Family/Pro | Tier = Family/Pro, all features unlocked |
| 5.4 | Premium to Family/Pro | User with Premium, upgrade to Family/Pro | Correct proration, tier updated |
| 5.5 | Monthly to Yearly (same tier) | User on Basic monthly, switch to Basic yearly | Billing cycle changes, yearly price applied |
| 5.6 | Upgrade confirmation display | Click upgrade button | Confirmation shows old vs new plan, price difference |

---

## Phase 6: Subscription Downgrades

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 6.1 | Premium to Basic | User with Premium, go to `/profile/change-tier`, select Basic | Downgrade applied (immediate per settings), tier changes to Basic |
| 6.2 | Family/Pro to Premium | User with Family/Pro, downgrade to Premium | Features reduced accordingly |
| 6.3 | Family/Pro to Basic | Highest to lowest tier | Tier = Basic, devices reduced to 1 |
| 6.4 | Verify reduced features | After downgrade, check device limit | Max devices reflects new (lower) tier |
| 6.5 | Downgrade with excess devices | Have 3 devices on Premium, downgrade to Basic (limit 1) | Graceful handling — warning or device management prompt |

---

## Phase 7: Subscription Cancellation

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 7.1 | Cancel active subscription | User with active sub, go to `/profile/change-tier`, click Cancel | Subscription cancelled immediately (per settings), refund issued (prorate=1) |
| 7.2 | Verify tier removed | After cancellation, check profile | Tier removed, device access revoked/limited |
| 7.3 | Verify Stripe cancellation | Check Stripe dashboard | Subscription status = canceled |
| 7.4 | Cancellation email | Cancel subscription | User receives cancellation/expiration email |
| 7.5 | Webhook processing | After cancel, check `wbl_webhook_logs` | `customer.subscription.deleted` event logged as success |

---

## Phase 8: Subscription Reactivation

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 8.1 | Reactivate after cancellation | Cancelled user, go to `/profile/change-tier`, Reactivate or purchase again | New subscription created, tier reassigned |
| 8.2 | Purchase different tier after cancel | Cancelled Basic user, purchase Premium | Premium tier assigned, new subscription |
| 8.3 | Verify reactivated features | After reactivation, check devices/features | Correct tier features restored |

---

## Phase 9: Billing Management

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 9.1 | View billing page | Navigate to `/profile/billing` | Shows current payment method (card brand, last 4), billing cycle info |
| 9.2 | View invoices | Check invoice list on billing page | Recent invoices displayed with amounts and dates |
| 9.3 | Manage payment method | Click "Manage Payment Method", Stripe portal opens | Stripe Customer Portal opens, can update card |
| 9.4 | Update payment method | In Stripe portal, add new card | Payment method updated for future charges |
| 9.5 | View subscriptions page | Navigate to `/profile/subscriptions` | Active subscription(s) listed with status, next billing date |

---

## Phase 10: Webhook Reliability

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 10.1 | Webhook signature validation | Send fake POST to `/ajax/stripe_webhook` | Rejected (400), logged as failed |
| 10.2 | Duplicate event handling | Trigger same Stripe event twice | Second event detected as duplicate, no double-processing |
| 10.3 | `invoice.payment_failed` | Use Stripe CLI or dashboard to simulate failed payment | Subscription set to `past_due`, failure notification email sent |
| 10.4 | `invoice.payment_succeeded` | Simulate successful invoice | Subscription status confirmed active, period end updated |
| 10.5 | Webhook log review | Query `wbl_webhook_logs` after all tests | All events logged with correct status and no unhandled errors |

---

## Phase 11: Trial Periods (if configured)

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 11.1 | Purchase with trial | Set `prv_trial_period_days` on a product version, purchase | No immediate charge, subscription status = `trialing` |
| 11.2 | Trial display in cart | Add trial product to cart | Cart shows "(X day free trial)" |
| 11.3 | Trial to active transition | Wait for trial end (or use Stripe test clock) | Subscription transitions to active, first charge occurs |
| 11.4 | Cancel during trial | Start trial, cancel before it ends | No charge, tier removed |

---

## Phase 12: PayPal Flow (if in scope)

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 12.1 | PayPal checkout | Add product, select PayPal, complete with sandbox account | Order created, tier assigned |
| 12.2 | PayPal subscription limitations | PayPal subscriber tries tier change | Warning that PayPal subscriptions cannot be changed directly |

---

## Phase 13: Admin Verification

| # | Test Case | Steps | Expected Result |
|---|-----------|-------|-----------------|
| 13.1 | Admin order list | `/admin/admin_stripe_orders` | All test orders visible with correct statuses |
| 13.2 | Admin user subscriptions | View test user in admin | Subscription details, tier, and group membership shown |
| 13.3 | Admin tier management | `/admin/admin_subscription_tiers` | All tiers listed, editable |
| 13.4 | Admin coupon management | Check coupon admin page | Coupon usage counts updated after test purchases |

---

## Recommended Test Execution Order

1. **Setup** — Configure webhook secret, verify Stripe test products
2. **Phase 1** — Pricing page sanity check
3. **Phase 2** — Happy-path purchases (creates test users for later phases)
4. **Phase 3** — Payment failures
5. **Phase 4** — Coupons
6. **Phase 5** — Upgrades (using users from Phase 2)
7. **Phase 6** — Downgrades
8. **Phase 7** — Cancellations
9. **Phase 8** — Reactivation
10. **Phase 9** — Billing management
11. **Phase 10** — Webhook verification (review logs from all prior phases)
12. **Phase 11** — Trials (if applicable)
13. **Phase 12** — PayPal (if in scope)
14. **Phase 13** — Admin verification
