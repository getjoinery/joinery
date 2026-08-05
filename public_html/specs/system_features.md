# System Features Inventory

**Purpose:** Comprehensive feature list for testing coverage. Each feature is listed at a granular level suitable for creating individual test cases.

**Last Updated:** 2026-07-16 (sections 21–27 added for systems shipped since
April: Drive/blobs, Sealed Vault + password vault, calendar/bookings, mailbox,
Joinery AI, mobile apps/billing, passkeys/account security; coverage rows and
legacy sections 1–20 checkbox state re-audited against the current 98-file
test estate, with covering tests cited per item; obsolete Calendly/ControlD
references struck.)

---

## Testing Strategy

### How to Use This Document

This inventory is the master checklist for system testing coverage. Each feature item is a candidate test case. Items are not marked complete until an automated test or documented manual test procedure covers them.

**Workflow:**
1. Pick a section by priority (see table below)
2. Write tests for unchecked items
3. Check the box (`[x]`) when a repeatable test exists (automated or documented manual)

---

### Test Types

The test estate runs on the shared harness (`tests/lib/harness.php`) with the
`@joinery-test` header and the tier/env model — see `docs/testing.md`. Run via
`php tests/run.php [safe|db|test-db|live]` or the superadmin dashboard at
`/tests/`. `safe` is the pre-deploy gate.

| Where | What lives there |
|------|------------------|
| `/tests/{area}/` | Core suites by area: `models/` (auto-discovered CRUD), `unit/`, `integration/`, `functional/` (api, drive, files, ios/android gates, ab_testing), `calendar/`, `vault/`, `email/`, `scaffold/`, `schema/` |
| `plugins/{plugin}/tests/` | Plugin suites (mailbox, joinery_ai, store, vault) |
| `*_gate.sh` | Shell gates (device/emulator/browser-crypto gates) |
| MCP Playwright | Browser verification: rendering, forms, UI state, visual layout |
| Manual | Third-party payment UIs, visual design, judgment-required edge cases — documented in this checklist |

---

### Priority Levels

- **P1 — Critical Path**: Broken functionality blocks users or revenue. Must pass before any release.
- **P2 — High Value**: Important user-facing features; test when the related area changes.
- **P3 — Standard**: Supporting features; test periodically or when directly touched.

---

### Coverage Summary by Section

| # | Section | Priority | Coverage | Notes |
|---|---------|----------|----------|-------|
| 1 | Authentication & Account Management | **P1** | ⚠️ Partial | Login/session/permission enforcement automated (session_keys, browser_session, crud_authorization, routing); registration, password reset, email verification still manual |
| 2 | User Profile | **P2** | ⚠️ Partial | Subscription management (SubscriptionTierTester) + profile data actions (member_screens) automated; UI flows manual |
| 3 | Content Management System | **P2** | ⚠️ Partial | Blog/page model CRUD auto-covered; rendering is manual |
| 4 | E-Commerce | **P1** | ⚠️ Partial | `ProductTester` covers product logic; cart/checkout UI is manual |
| 5 | Event Management | **P1** | ❌ Gap | Model CRUD auto-covered; registration flows are manual |
| 6 | Email System | **P2** | ✅ Covered | All 11 send patterns, SMTP/Mailgun, templates, DNS tested |
| 7 | Surveys & Forms | **P3** | ⚠️ Partial | Model CRUD auto-covered; survey flow UI is manual |
| 8 | File & Media Management | **P2** | ❌ Gap | No upload/access-control tests; all manual |
| 9 | User Management (Admin) | **P2** | ⚠️ Partial | Model CRUD auto-covered; admin UI is manual |
| 10 | Navigation & Menus | **P3** | ❌ Gap | Manual only |
| 11 | URL Management | **P3** | ✅ Covered | routing_test.php covers the full routing system + DB redirects (301/302/307/308) |
| 12 | Analytics & Statistics | **P3** | ❌ Gap | Manual only |
| 13 | System Administration | **P2** | ⚠️ Partial | Error handling tested; settings/plugins are manual |
| 14 | REST API | **P2** | ✅ Covered | `tests/functional/api/` suite: browser-session credential, CRUD authorization, idempotency, guest credential, session keys, app platform, ajax-migration actions |
| 15 | Integrations | **P1** | ⚠️ Partial | Mailgun tested; OAuth2 core tested (`tests/integration/oauth/`); Stripe webhooks, PayPal are manual |
| 16 | SEO & Public Features | **P3** | ⚠️ Partial | robots/sitemap serving + cookie-consent recording automated; rest manual |
| 17 | Theme System | **P3** | ⚠️ Partial | Theme override chain + asset/manifest resolution automated (routing, components_manifest); visual is browser-only |
| 18 | Plugins | **P2** | ⚠️ Partial | Major plugins have their own suites (see sections 21–27); long-tail plugins untested |
| 19 | Security Features | **P1** | ⚠️ Partial | Vault/crypto heavily tested (§22); CSRF + auth-flow + input validation covered (browser_session, guest_credential, session_keys, email_validation_toggle); XSS suites are the gap |
| 20 | Developer & Maintenance | **P3** | ⚠️ Partial | Tools exist; no automated validation of deployment scripts |
| 21 | Drive & Blob Storage | **P1** | ✅ Covered | `tests/functional/drive/` (folders, upload, versions, sharing, changes, encryption, fix pack) + blob layer, signed URLs, browser-crypto gate |
| 22 | Sealed Vault & Password Vault | **P1** | ✅ Covered | `tests/vault/` (crypto, ceremonies, unlock window, rotation crash, health, wrappings, sealedbox) + client-custody plugin suite |
| 23 | Calendar, Scheduling & Bookings | **P1** | ⚠️ Partial | `tests/calendar/` (core, schedules, slot generator, recurrence, ICS import, native entries); booking *flow* (public /book, lifecycle, emails) has no dedicated suite |
| 24 | Mailbox (Self-Hosted Email) | **P1** | ✅ Covered | 18 plugin suites: inbound (attachments, raw storage, IMAP), reader, reseal/encryption, spam, relay + fleet; live fleet verification pending a real shard |
| 25 | Joinery AI | **P2** | ⚠️ Partial | Plugin suites (memory, cancel, encryption, search, provider resilience) + pipeline/owner-scope/turn-activity integration; chat UI + recipes-in-anger are manual |
| 26 | Mobile Apps & Billing | **P1** | ⚠️ Partial | Billing harness (49 checks) + kit tests; iOS/Android member gates green; store-console e2e (sandbox purchases) pending external setup |
| 27 | Passkeys & Account Security Levels | **P1** | ⚠️ Partial | Browser-verified via virtual authenticator (no PRF support there — PRF paths manual-on-device); 12 executor acceptance criteria verified at build; no standing automated suite |

**Legend:** ✅ Covered = repeatable automated tests exist | ⚠️ Partial = some coverage, gaps remain | ❌ Gap = manual testing only or no tests

---

## 1. Authentication & Account Management

### 1.1 User Registration
- [ ] Register new account with email, first name, last name, password
- [ ] Anti-spam question validation during registration
- [ ] hCaptcha integration on registration form
- [ ] Honeypot field for bot detection
- [ ] Email uniqueness validation (AJAX check via `email_check_ajax.php`)
- [ ] Registration can be disabled via `register_active` setting
- [ ] Redirect to profile if already logged in

### 1.2 Login
- [x] Login with email and password — covered by `tests/functional/api/session_keys_test.php`, `tests/functional/api/browser_session_test.php`
- [ ] "Remember me" persistent cookie login
- [ ] Secure cookie attributes (SameSite, HttpOnly, Secure)
- [x] Failed login error message with retry — covered by `tests/functional/api/session_keys_test.php`
- [ ] Login history tracked in `log_logins` table
- [ ] Redirect to profile after successful login
- [ ] Forced password change on login (`usr_force_password_change`)

### 1.3 Logout
- [ ] Session destruction on logout
- [ ] Cookie cleanup on logout
- [ ] Redirect to homepage after logout

### 1.4 Password Reset
- [ ] Step 1: Request password reset by email (`password-reset-1`)
- [ ] Activation code generation and email delivery
- [ ] Step 2: Set new password with activation code (`password-reset-2`)
- [ ] Password recovery can be disabled per user (`usr_password_recovery_disabled`)

### 1.5 Password Management
- [ ] Change password from profile (`password_edit`)
- [ ] Forced password change page (`change-password-required`)
- [ ] Initial password setting for new accounts (`password-set`)

### 1.6 Email Verification
- [ ] Activation code sent on registration
- [ ] Email verification status tracked (`usr_email_is_verified`)
- [ ] Activation required for login (`activation_required_login` setting)
- [ ] Verification timestamp recorded

### 1.7 Session Management
- [x] Session-based authentication — covered by `tests/functional/api/browser_session_test.php`, `tests/functional/api/guest_credential_test.php`
- [x] Permission level enforcement (0=user, 5=admin, 8=editor, 10=superadmin) — covered by `tests/functional/api/crud_authorization_test.php`
- [x] `check_permission()` auto-redirect to login for unauthorized access — covered by `tests/integration/routing_test.php`
- [ ] Session message queuing for flash messages
- [ ] Location tracking and geolocation data

---

## 2. User Profile

### 2.1 Profile Dashboard
- [ ] Display user feed with social-style posts
- [x] Show account summary (name, email, mailing list status) — covered by `tests/functional/api/member_screens_test.php`
- [x] Show event registrations section — covered by `tests/functional/api/member_screens_test.php`
- [x] Show subscriptions section — covered by `tests/functional/api/member_screens_test.php`
- [x] Show orders section — covered by `tests/functional/api/member_screens_test.php`
- [ ] "Edit Account" button navigation

### 2.2 Account Editing
- [ ] Edit first name, last name, nickname
- [ ] Edit email address
- [ ] Organization name field
- [ ] Timezone selection
- [ ] Profile photo/avatar management

### 2.3 Address Management
- [x] Add/edit user addresses (`address_edit`) — covered by `tests/models/models_test.php`
- [x] Multiple address support — covered by `tests/models/models_test.php`

### 2.4 Phone Number Management
- [x] Add/edit phone numbers (`phone_numbers_edit`) — covered by `tests/models/models_test.php`
- [ ] Phone verification system (`admin_phone_verify`)

### 2.5 Contact Preferences
- [ ] Email communication opt-in/opt-out (`contact_preferences`)
- [ ] Contact preference change timestamp tracking
- [ ] Mailing list subscription management from profile

### 2.6 Subscription Management
- [ ] View active subscriptions (`subscriptions`)
- [x] Change subscription tier (`change-tier`) — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription cancellation (when `subscription_cancellation_enabled`) — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription downgrade (when `subscription_downgrades_enabled`) — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription reactivation (when `subscription_reactivation_enabled`) — covered by `plugins/store/tests/subscription_tiers/`
- [x] Prorate calculations for upgrades/downgrades/cancellations — covered by `plugins/store/tests/subscription_tiers/`
- [ ] Maximum subscriptions per user limit (`max_subscriptions_per_user`)

### 2.7 Event Registration (from profile)
- [x] View registered events — covered by `tests/functional/api/member_screens_test.php`
- [ ] Event registration completion (`event_register_finish`)
- [ ] Event session viewing (`event_sessions`, `event_sessions_course`)
- [ ] Event withdrawal (`event_withdraw`)

### 2.8 Order Management (from profile)
- [x] View order history — covered by `tests/functional/api/member_screens_test.php`
- [ ] Recurring order actions (`orders_recurring_action`)

---

## 3. Content Management System

### 3.1 Static Pages
- [x] Create/edit static pages with HTML content — covered by `tests/models/models_test.php`
- [x] Page content sections (`page_contents`) — covered by `tests/models/models_test.php`
- [x] Content versioning with edit history (`content_versions`) — covered by `tests/models/models_test.php`
- [x] URL-safe slugs for pages — covered by `tests/models/models_test.php`
- [ ] Page visibility controls
- [ ] Feature toggle: `page_contents_active`

### 3.2 Page Components System
- [ ] Hero static component (`hero_static`)
- [ ] Feature grid component (`feature_grid`)
- [ ] Call-to-action banner component (`cta_banner`)
- [ ] Page title component (`page_title`)
- [ ] Custom HTML component (`custom_html`)
- [ ] Component type management
- [ ] Component rendering engine (`ComponentRenderer`)

### 3.3 Blog
- [ ] Blog post listing with pagination
- [ ] Single post display with full content
- [x] Blog post creation and editing (admin) — covered by `tests/models/models_test.php`
- [ ] Post publication status and scheduling
- [ ] Featured image support
- [x] Author attribution — covered by `tests/models/models_test.php`
- [ ] Blog tag/category support
- [ ] RSS feed generation (`rss20_feed.php`)
- [ ] Feature toggle: `blog_active`
- [ ] Option to use blog as homepage (`use_blog_as_homepage`)
- [ ] Blog subdirectory configuration
- [ ] Tier gating: per-post access control via `pst_tier_min_level` with gate prompt, preview, and early access timer (`pst_tier_public_after_hours`)

### 3.4 Comments
- [ ] Comment submission on posts/content
- [ ] Comment moderation (admin)
- [ ] Anti-spam question for comments
- [ ] Captcha on comments (`use_captcha_comments`)
- [ ] Allow/disallow unregistered user comments (`comments_unregistered_users`)
- [ ] Default comment approval status (`default_comment_status`)
- [ ] Comment notification emails
- [ ] Feature toggle: `comments_active`, `show_comments`

### 3.5 Videos
- [x] Video content management — covered by `tests/models/models_test.php`
- [ ] Video listing page
- [ ] Single video display
- [ ] Feature toggle: `videos_active`

---

## 4. E-Commerce

### 4.1 Products
- [x] Product catalog listing with pagination (12 per page) — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [x] Single product detail page with description — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [ ] Product image display
- [x] Product URL slugs — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [ ] Product groups/categories
- [ ] Feature toggle: `products_active`

### 4.2 Product Versions (Pricing Tiers)
- [x] Multiple pricing tiers per product — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [x] Version-specific pricing — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [ ] Product version editing (admin)

### 4.3 Product Groups
- [x] Group products into categories — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [ ] Product group management (admin)
- [ ] Products can list events (`products_list_events_active`)
- [ ] Products can list items (`products_list_items_active`)

### 4.4 Product Requirements
- [x] Define purchase requirements (name, phone, DOB, address, GDPR, etc.) — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [x] Requirement instance tracking per order — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [x] Requirement validation during checkout — covered by `plugins/store/tests/products/ProductTester.php`

### 4.5 Shopping Cart
- [ ] Add products to session-based cart
- [ ] Cart page with item listing
- [ ] Cart confirmation page
- [ ] Cart clearing
- [ ] Quantity management
- [ ] Price calculations with discounts
- [ ] Recurring vs. non-recurring item enforcement
- [ ] Cart logging (`cls_cart_logs`)

### 4.6 Checkout & Payment Processing
- [ ] Stripe checkout integration (regular mode)
- [ ] Stripe test mode vs. live mode switching
- [ ] PayPal checkout integration (`use_paypal_checkout`)
- [ ] Payment confirmation and order creation
- [ ] Checkout type configuration (`checkout_type`)
- [ ] Currency support (`site_currency`: US Dollar)

### 4.7 Coupon Codes
- [x] Create discount codes — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [x] Percentage and fixed-amount discounts — covered by `plugins/store/tests/products/ProductTester.php`
- [x] Usage limits per coupon — covered by `plugins/store/tests/products/ProductTester.php`
- [x] Coupon usage tracking — covered by `plugins/store/tests/products/ProductTester.php`, `tests/models/models_test.php`
- [x] Product-specific coupon restrictions — covered by `plugins/store/tests/products/ProductTester.php`
- [x] Coupon expiration dates — covered by `plugins/store/tests/products/ProductTester.php`
- [ ] Feature toggle: `coupons_active`

### 4.8 Orders
- [x] Order creation from checkout — covered by `tests/models/models_test.php`
- [x] Order item tracking — covered by `tests/models/models_test.php`
- [ ] Order status management
- [ ] Order refunds (`admin_order_refund`)
- [ ] Order deletion (admin)
- [ ] Single purchase notification emails
- [ ] Subscription notification emails

### 4.9 Subscriptions & Recurring Billing
- [ ] Subscription tier definitions
- [ ] Recurring billing via Stripe
- [x] Subscription upgrade with proration — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription downgrade with proration — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription cancellation with timing (Immediate) — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription reactivation — covered by `plugins/store/tests/subscription_tiers/`
- [ ] Feature toggle: `subscriptions_active`

### 4.10 Stripe Integration
- [ ] Stripe webhook handling (`stripe_webhook.php`)
- [ ] `checkout.session.completed` event processing
- [ ] Stripe invoice tracking (`siv_stripe_invoices`)
- [ ] Stripe customer ID per user (`usr_stripe_customer_id`)
- [ ] Stripe test customer ID (`usr_stripe_customer_id_test`)
- [ ] Signature verification on webhooks
- [ ] Stripe payment listing in admin (`admin_stripe_orders`)

### 4.11 PayPal Integration
- [ ] PayPal sandbox and production modes
- [ ] PayPal checkout button generation
- [ ] PayPal order building with items
- [ ] Return/cancel URL handling

### 4.12 Pricing Page
- [ ] Dedicated pricing page display
- [ ] Pricing page toggle (`pricing_page`)

---

## 5. Event Management

### 5.1 Event Listing
- [ ] Public events listing page with filtering tabs
- [ ] Filter by: Future Events, Live Online, Self Paced Online, Retreats, Past Events
- [ ] Event type filtering
- [ ] Event card display with image, title, instructor
- [ ] Feature toggle: `events_active`
- [ ] Custom events label (`events_label`)

### 5.2 Event Details
- [ ] Single event page with full description
- [ ] Event dates with timezone support
- [ ] Event location display
- [ ] Instructor/organizer attribution
- [ ] Event image display
- [ ] Registration button/form
- [ ] Event status tracking
- [ ] Event visibility controls

### 5.3 Event Registration
- [ ] User registration for events
- [ ] Registration capacity tracking
- [ ] Registration confirmation
- [ ] Registration completion page
- [ ] Registration payment (linked to products)

### 5.4 Event Sessions
- [ ] Multi-session events (courses)
- [ ] Session scheduling
- [x] Session file attachments (`esf_event_session_files`) — covered by `tests/models/models_test.php`
- [ ] Course session viewing from profile

### 5.5 Event Waiting List
- [ ] Waiting list when event is full
- [ ] Waiting list management page
- [ ] Waiting list notifications

### 5.6 Event Withdrawal
- [ ] User-initiated event withdrawal
- [ ] Withdrawal processing

### 5.7 Event Types
- [x] Event type definitions (Live Online, Self Paced Online, Retreats, etc.) — covered by `tests/models/models_test.php`
- [ ] Event type management (admin)

### 5.8 Event Locations
- [x] Location management with details — covered by `tests/models/models_test.php`
- [ ] Location display page
- [ ] Location association with events

### 5.9 Event Bundles
- [x] Bundle multiple events together — covered by `tests/models/models_test.php`
- [ ] Event bundle management (admin)

### 5.10 Event Emails
- [ ] Event-triggered email sending
- [ ] Event email template configuration
- [ ] Event email footer/inner/outer templates

### 5.11 Calendar Integration
- [ ] Google Calendar export
- [ ] Yahoo Calendar export
- [ ] Outlook Calendar export
- [ ] iCalendar (.ics) format export

---

## 6. Email System

### 6.1 Email Sending
- [x] Send individual emails — covered by `tests/email/email_suite_test.php`, `tests/email/email_pattern_test.php`
- [x] Send bulk emails to groups/lists — covered by `tests/email/email_pattern_test.php`
- [x] Email queue for batch processing (`equ_queued_emails`) — covered by `tests/email/email_pattern_test.php`
- [x] Pluggable email provider system via `EmailProviderInterface` (see `specs/implemented/email_provider_abstraction.md`) — covered by `tests/email/email_suite_test.php`
- [x] Email providers: Mailgun, PHPMailer (SMTP), with provider auto-detection from site settings — covered by `tests/email/email_suite_test.php`, `tests/integration/mailgun_test.php`
- [x] Email dry run mode (`email_dry_run`) — covered by `tests/email/email_suite_test.php`
- [x] Email test mode with test recipient (`email_test_mode`, `email_test_recipient`) — covered by `tests/email/email_suite_test.php`
- [x] Email debug mode logging (`email_debug_mode`) — covered by `tests/email/email_suite_test.php`
- [x] Feature toggle: `emails_active` — covered by `tests/email/email_suite_test.php`

### 6.2 Email Templates
- [x] Create/edit email templates — covered by `tests/email/email_suite_test.php`, `tests/email/template_iteration_test.php`
- [x] Template preview (AJAX-based) — covered by `tests/email/email_suite_test.php`
- [x] HTML and plain text variants — covered by `tests/email/email_suite_test.php`, `tests/email/template_iteration_test.php`
- [x] Variable substitution in templates — covered by `tests/email/email_suite_test.php`, `tests/email/template_iteration_test.php`
- [x] Default email template configuration — covered by `tests/email/email_suite_test.php`
- [x] Outer template wrapping (header/footer) — covered by `tests/email/email_suite_test.php`, `tests/email/template_iteration_test.php`
- [x] Inner template for content — covered by `tests/email/email_suite_test.php`, `tests/email/template_iteration_test.php`
- [x] Bulk email footer template — covered by `tests/email/email_suite_test.php`
- [x] Template permanent deletion — covered by `tests/email/email_suite_test.php`

### 6.3 Email Recipients
- [ ] Track email recipients per email
- [ ] Email recipient groups
- [ ] Recipient modification (admin)
- [ ] Email delivery status tracking

### 6.4 Mailing Lists
- [ ] Mailing list creation and management
- [ ] Mailing list directory page (`/lists`)
- [ ] Single mailing list subscription page
- [ ] Mailing list registrant tracking
- [ ] Default mailing list configuration
- [ ] Feature toggle: `mailing_lists_active`, `newsletter_active`

### 6.5 Contact Types
- [ ] Define email/contact categories
- [ ] Contact type management (admin)

### 6.6 Recurring Emails
- [ ] Automated email scheduling (`ers_recurring_email_logs`)
- [ ] Recurring mailer configuration

### 6.7 Inbound Email
- [x] Mailgun inbound webhook processing — covered by `tests/integration/inbound_forwarding_relay_test.php`, `plugins/mailbox/tests/inbound_*`
- [x] HMAC signature validation — covered by `tests/integration/inbound_forwarding_relay_test.php`
- [x] Email storage in `iem_inbound_emails` — covered by `tests/integration/email_inline_attachments_test.php`, `plugins/mailbox/tests/inbound_raw_storage_test.php`
- [ ] Testing via `*@inbox.dev.getjoinery.com`

### 6.8 Email Analytics
- [ ] Email statistics dashboard
- [ ] Email deliverability tracking
- [ ] Debug email log viewing
- [ ] Email debug log preview (AJAX)

---

## 7. Surveys & Forms

### 7.1 Survey Management
- [ ] Create/edit surveys
- [ ] Survey question assignment
- [ ] Survey display page
- [ ] Survey completion page (`survey_finish`)
- [ ] Feature toggle: `surveys_active`

### 7.2 Questions
- [ ] Reusable question definitions
- [ ] Question options (multiple choice)
- [ ] Question management (admin)
- [ ] Question editing

### 7.3 Survey Responses
- [ ] User answer collection
- [ ] Survey answer viewing (admin)
- [ ] Per-user response viewing
- [ ] Survey analytics

---

## 8. File & Media Management

### 8.1 File Uploads
- [ ] File upload interface with drag-and-drop
- [ ] Allowed file extensions enforcement (`allowed_upload_extensions`: gif, jpeg, jpg, png, pdf, xls, doc, xlsx, docx, mp3, mp4, m4a)
- [ ] File validation (AJAX-based)
- [ ] Upload size limits
- [ ] CORS support for uploads
- [ ] Feature toggle: `files_active`

### 8.2 File Management
- [ ] File listing (admin)
- [ ] File metadata storage (name, type, size, hash)
- [ ] File version tracking
- [ ] File owner associations
- [ ] File deletion (admin)
- [ ] Authenticated file access (`/uploads/*` route with permission checks)

### 8.3 Image Processing
- [ ] Image upload and validation
- [ ] Thumbnail generation
- [ ] Image resizing
- [ ] Image browsing (AJAX endpoint: `image_list_ajax.php`)

---

## 9. User Management (Admin)

### 9.1 User List
- [ ] Paginated user list (30 per page) with total count
- [ ] Sort by: User ID, Last Name, First Name (ascending/descending)
- [ ] Search users by name/email
- [ ] Display: name, email, signup date, email verification status

### 9.2 User Detail/Edit
- [ ] View full user profile
- [ ] Edit user information
- [ ] Set permission level
- [ ] Manage user activation/deactivation
- [ ] View user's groups
- [ ] View user's orders
- [ ] View user's event registrations

### 9.3 User Actions
- [ ] Add single user
- [ ] Bulk user import (`admin_user_add_bulk`)
- [ ] User soft delete
- [ ] User permanent delete
- [ ] User message sending
- [ ] Login as user (`admin_user_login_as`)
- [ ] User payment methods management

### 9.4 Groups
- [x] Group creation and management — covered by `tests/models/models_test.php`
- [ ] Group member management
- [ ] Group permanent deletion
- [ ] Group-based email sending

### 9.5 Subscription Tiers
- [x] Tier definition and editing — covered by `plugins/store/tests/subscription_tiers/`
- [x] Tier pricing configuration — covered by `plugins/store/tests/subscription_tiers/`
- [x] Subscription tier assignment — covered by `plugins/store/tests/subscription_tiers/`

---

## 10. Navigation & Menus

### 10.1 Public Navigation
- [ ] Public menu management (`pmu_public_menus`)
- [ ] Footer navigation links (Home, About, Contact)
- [ ] Category links (Blog, Gallery, Videos)
- [ ] Get In Touch section with email

### 10.2 Admin Navigation
- [ ] Sidebar navigation with collapsible sections
- [ ] Categories: Users, Emails, Products, Orders, Events, Files, Videos, Surveys, Pages, Blog, Statistics, Urls, System
- [ ] Admin menu management (`amu_admin_menus`)
- [ ] Theme selector in admin header
- [ ] Dashboard link
- [ ] "+ New" quick action button

---

## 11. URL Management

### 11.1 URL Redirects
- [x] Custom URL shortcut creation — covered by `tests/integration/routing_test.php` (testRedirects), `tests/models/models_test.php`
- [x] Permanent (301) redirects — covered by `tests/integration/routing_test.php` (testRedirects)
- [x] Temporary redirects — covered by `tests/integration/routing_test.php` (testRedirects)
- [x] URL redirect listing and management — covered by `tests/integration/routing_test.php` (testRedirects), `tests/models/models_test.php`
- [x] Feature toggle: `urls_active` — covered by `tests/integration/routing_test.php` (testRedirects)

### 11.2 Routing System
- [x] Front controller pattern via `serve.php` — covered by `tests/integration/routing_test.php`
- [x] Dynamic route matching with parameters — covered by `tests/integration/routing_test.php`
- [x] Static file serving with HTTP caching — covered by `tests/integration/routing_test.php`
- [x] Plugin route integration — covered by `tests/integration/routing_test.php`
- [x] Theme route override support — covered by `tests/integration/routing_test.php`
- [x] `.php` extension stripped from URLs — covered by `tests/integration/routing_test.php`
- [x] Profile routes with fallback — covered by `tests/integration/routing_test.php`

---

## 12. Analytics & Statistics

### 12.1 Web Statistics
- [ ] Session analytics tracking (`sev_session_analytics`)
- [ ] Visitor event tracking (`vse_visitor_events`)
- [ ] Web statistics dashboard
- [ ] Built-in tracking or custom tracking code

### 12.2 Email Statistics
- [ ] Email delivery analytics
- [ ] Email deliverability dashboard
- [ ] Email debug log viewing and searching

### 12.3 User Analytics
- [ ] Signups by date reporting
- [ ] User activity funnels (`admin_analytics_funnels`)
- [ ] User engagement metrics

### 12.4 Financial Reports
- [ ] Yearly donation reports (`admin_yearly_report_donations`)
- [ ] Stripe payment/invoice listing

---

## 13. System Administration

### 13.1 Settings Management
- [ ] Database-stored settings via `stg_settings`
- [ ] File-based core configuration (`Globalvars_site.php`)
- [ ] 178+ configurable settings
- [ ] Feature activation toggles

### 13.2 Plugin Management
- [ ] Plugin listing and status
- [ ] Plugin activation/deactivation
- [ ] Plugin version tracking
- [ ] Plugin dependency management
- [ ] Plugin settings forms
- [ ] Plugin-specific database migrations

### 13.3 Theme Management
- [ ] Theme listing and selection
- [ ] Active theme switching (AJAX: `theme_switch_ajax.php`)
- [ ] Theme metadata display
- [ ] Theme override chain (theme > plugin > core)

### 13.4 Static Page Cache
- [ ] Static page caching system
- [ ] Cache management (admin)
- [ ] Cache clearing

### 13.5 API Key Management
- [ ] API key creation with public/secret key pairs
- [ ] IP restriction per key (`usr_allowed_ips`)
- [ ] API key listing and editing

### 13.6 Error Management
- [x] General error log tracking (`err_general_errors`) — covered by `tests/integration/error_handling_test.php`
- [ ] Apache error log viewing (`admin_apache_errors`)
- [ ] Form error logging (`lfe_log_form_errors`)
- [ ] Error deletion and cleanup
- [ ] Show errors toggle (`show_errors`)

### 13.7 Event Logging
- [ ] System event log tracking (`evl_event_logs`)
- [ ] Change tracking audit trail (`cht_change_tracking`)
- [ ] Login history (`log_logins`)

### 13.8 Database Management
- [x] Automatic schema updates from model `$field_specifications` — covered by `tests/schema/index_management_test.php`
- [ ] Database migration system for data changes
- [ ] Database version tracking (`database_version`, `db_migration_version`)
- [ ] Test database management (`admin_test_database`)

### 13.9 Soft Delete & Recovery
- [ ] Soft-deleted item listing
- [ ] Item recovery (undelete)
- [ ] Permanent deletion for various entities

### 13.10 Utilities
- [ ] System utilities page (`admin_utilities`)
- [ ] Help documentation page
- [ ] Specifications viewer (`admin_specs`)
- [ ] Component type management

### 13.11 Shadow Sessions
- [ ] Shadow session management
- [ ] Shadow session editing

---

## 14. REST API

### 14.1 API v1
- [x] Key-based authentication (public + secret keys) — covered by `tests/functional/api/session_keys_test.php`
- [x] IP restriction enforcement — covered by `tests/functional/api/session_keys_test.php`
- [x] Model discovery system — covered by `tests/functional/api/crud_authorization_test.php`
- [x] CRUD operations on data models — covered by `tests/functional/api/crud_authorization_test.php`
- [x] JSON response format — covered by `tests/functional/api/crud_authorization_test.php`
- [x] User validation — covered by `tests/functional/api/guest_credential_test.php`, `tests/functional/api/browser_session_test.php`

---

## 15. Integrations

### 15.1 Stripe
- [ ] Payment processing (checkout sessions)
- [ ] Webhook event handling
- [ ] Subscription management
- [ ] Invoice tracking
- [ ] Test/production mode switching
- [ ] Webhook signature verification

### 15.2 PayPal
- [ ] Payment checkout
- [ ] Sandbox/production modes
- [ ] Order creation

### 15.3 Mailgun
- [x] Email sending via `MailgunEmailProvider` (implements `EmailProviderInterface`) — covered by `tests/integration/mailgun_test.php`, `tests/email/email_suite_test.php`
- [ ] Inbound email webhook
- [ ] Webhook signature validation
- [x] EU API endpoint support — covered by `tests/integration/mailgun_test.php`

### 15.4 SMTP / PHPMailer
- [x] Email sending via `PHPMailerEmailProvider` (implements `EmailProviderInterface`) — covered by `tests/email/email_suite_test.php`
- [ ] Configurable host, port, authentication
- [x] Provider auto-selected based on site settings (`mailgun_api_key` present = Mailgun, otherwise PHPMailer) — covered by `tests/email/email_suite_test.php`

### 15.5 ~~Calendly Integration~~ — REMOVED
Calendly webhooks/init files were deleted; scheduling is native (see §23 and `specs/implemented/scheduling_system.md`).

### 15.6 Acuity Scheduling
- [ ] API integration with key/user ID
- [ ] OAuth authentication support

### 15.7 Mailchimp
- [ ] API key integration
- [ ] Mailing list ID synchronization
- [ ] User Mailchimp ID tracking (`usr_mailchimp_user_id`)

---

## 16. SEO & Public Features

### 16.1 SEO
- [x] Dynamic robots.txt generation with configurable rules — covered by `tests/integration/routing_test.php`
- [x] Dynamic XML sitemap generation — covered by `tests/integration/routing_test.php`
- [ ] URL-friendly slugs for all content types
- [ ] Page title management
- [ ] Preview image for social sharing

### 16.2 Cookie Consent
- [ ] GDPR cookie consent mode
- [x] Cookie consent tracking (`cookie_consent.php` AJAX) — covered by `tests/functional/api/guest_credential_test.php`
- [ ] Privacy policy link configuration

### 16.3 404 Error Page
- [ ] Custom 404 page with search functionality
- [ ] Suggested pages (Blog, Products, Pricing, Contact, Login, Register)
- [ ] "Go Home" and "Contact Support" links

### 16.4 Site Directory
- [ ] Site directory/map page

---

## 17. Theme System

### 17.1 Theme Architecture
- [ ] Multi-theme support (13+ themes available)
- [ ] Bootstrap 5 theme (Falcon - primary)
- [ ] Tailwind CSS theme option
- [x] Theme override chain: theme/{theme}/path > plugins/{plugin}/path > core path — covered by `tests/integration/routing_test.php`, `tests/integration/components_manifest_test.php`
- [x] Theme-specific assets (CSS, JS, images, fonts) — covered by `tests/integration/routing_test.php`, `tests/integration/components_manifest_test.php`
- [x] Theme-specific view overrides — covered by `tests/integration/routing_test.php`
- [ ] Theme-specific logic overrides
- [ ] Theme-specific PublicPage and FormWriter classes

### 17.2 Active Themes
- [ ] phillyzouk (currently active)
- [ ] falcon (admin interface)
- [ ] canvas, zoukroom, empoweredhealth, galactictribune, jeremytunnell, linka-reference, devonandjerry, zoukphilly

---

## 18. Plugins

### 18.1 Bookings Plugin (Inactive)
- [ ] Booking creation and management
- [ ] ~~Booking types with Calendly integration~~ Booking types are native (`plugins/bookings/`); Calendly integration was removed
- [ ] Booking status workflow (Created > Booked > Completed > Canceled)
- [ ] Booking admin pages (list, view, edit)
- [ ] Booking type admin pages
- [ ] Schedule link configuration

### 18.2 Items Plugin (Inactive)
- [ ] Item creation with name, description, body
- [ ] URL-safe slug generation with uniqueness
- [ ] Item relationships (many-to-many)
- [ ] Relationship type definitions
- [ ] Content version tracking
- [ ] Item admin pages (list, view, edit)
- [ ] Dropdown helper methods for form selectors

### 18.3 DNS Filtering Plugin — `dns_filtering` (ScrollDaddy) (Active)
- [ ] Device management (add, edit, delete, soft delete)
- [ ] DNS filtering profile management
- [ ] Filter configuration (50+ categories: ads, malware, phishing, gambling, etc.)
- [ ] Service category management (200+ services: audio, social, gaming, etc.)
- [ ] Rule management
- [ ] Device backup system
- [ ] Device activation workflow
- [ ] Profile management from user dashboard
- ~~ControlD API key integration~~ — REMOVED (external ControlD dependency removed; resolvers are self-hosted)
- [ ] Plugin-specific routing and views
- [ ] Plugin-specific pricing page
- [ ] Tier-based feature access (`tier_features.json`)
- [x] Scan-URL target validation (SSRF guard) — covered by `tests/unit/scan_url_validate_target_test.php`
Note: ControlD admin pages (admin_ctld_account, admin_ctld_accounts) were removed when subscription management was moved to core (commit d43c0f30).

---

## 19. Security Features

### 19.1 Authentication Security
- [ ] Password hashing (bcrypt)
- [x] CSRF token protection (`_csrf_token`) — covered by `tests/functional/api/browser_session_test.php`, `tests/functional/api/guest_credential_test.php`
- [ ] Secure cookie implementation
- [x] IP-based API key restrictions — covered by `tests/functional/api/session_keys_test.php`
- [x] Permission level enforcement at page/route level — covered by `tests/functional/api/crud_authorization_test.php`, `tests/integration/routing_test.php`

### 19.2 Input Validation
- [x] Server-side input validation (Validator class) — covered by `tests/integration/email_validation_toggle_test.php`, `tests/unit/descriptor_validator_pipeline_test.php`
- [ ] Client-side validation (Joinery Validation v1.0.8)
- [ ] Prepared statements for all database queries (PDO)
- [ ] Honeypot fields for bot detection
- [ ] hCaptcha support
- [ ] Anti-spam questions

### 19.3 Access Control
- [x] Authenticated file access for uploads — covered by `tests/functional/files/signed_urls_test.php`
- [x] Admin permission checks (level 5+) — covered by `tests/functional/api/crud_authorization_test.php`, `tests/functional/api/session_keys_test.php`
- [x] Superadmin restrictions (level 10) — covered by `tests/functional/api/crud_authorization_test.php`, `tests/functional/api/session_keys_test.php`
- [ ] Plugin test access restricted to superadmin

---

## 20. Developer & Maintenance Features

### 20.1 Development Tools
- [ ] PHP syntax validation (`php -l`)
- [ ] Method existence validator (`validate_php_file.php`)
- [ ] Error log monitoring
- [ ] Debug mode (`debug` setting)

### 20.2 Deployment
- [ ] Installation scripts (`install_tools/`)
- [ ] Deployment scripts (`build_dev_from_source.sh`)
- [ ] Database backup and restore (`sysadmin_tools/`)
- [ ] Upgrade server system
- [ ] Remote archive refresh

### 20.3 Testing Infrastructure
- [x] Shared harness + discovery runner (`tests/run.php`, tier/env model, `/tests/` dashboard)
- [x] Plugin tests (`/plugins/{plugin}/tests/`)
- [ ] Test database management

---

## 21. Drive & Blob Storage

*Shipped 2026-07 (`implemented/drive_core.md`, `implemented/drive_encryption.md`). Sections below reflect existing suite coverage.*

### 21.1 Drive Core
- [x] Folder tree CRUD, moves, soft-delete (`tests/functional/drive/folders_test.php`)
- [x] Chunked upload init/complete, quota enforcement at complete (`upload_api_test.php`)
- [x] Version history + never-overwrite (`versions_test.php`)
- [x] Sharing: access grants (viewer/editor) + anonymous share links (`sharing_test.php`)
- [x] Change feed (`changes_test.php`)
- [x] Review fix pack regression suite (`fix_pack_test.php`)
- [x] Blob layer refcounting (`tests/functional/files/blob_layer_test.php`)
- [x] Signed URLs for private files (`tests/functional/files/signed_urls_test.php`)

### 21.2 Drive Encryption (client custody)
- [x] Server-side encryption paths, FileKeyGrant readability (`encryption_test.php`)
- [x] Browser crypto round-trip gate (`drive_crypto_gate.sh`)
- [ ] Fragment-key share-link UX regression (manual/browser)

---

## 22. Sealed Vault & Password Vault

*Shipped 2026-07 (`implemented/sealed_vault_core.md`, `implemented/password_vault*.md`).*

- [x] Vault crypto primitives + sealed box (`tests/vault/vault_crypto_test.php`, `sealedbox_test.php`)
- [x] Enrollment/unlock ceremonies (`vault_ceremonies_test.php`)
- [x] Unlock window semantics (`vault_unlock_window_test.php`)
- [x] Key-rotation crash safety (`vault_rotation_crash_test.php`)
- [x] Health checks + wrappings (`vault_health_test.php`, `vault_wrappings_test.php`)
- [x] Client-custody password vault (`plugins/vault/tests/vault_client_custody_test.php`)
- [ ] Cross-consumer regression (mail reseal + drive + passwords after core key rotation) — covered piecemeal per consumer, no single sweep

---

## 23. Calendar, Scheduling & Bookings

*Shipped 2026-06/07 (`implemented/scheduling_system.md`, `implemented/native_booking_flow.md`).*

### 23.1 Core Calendar & Scheduling
- [x] Calendar item model + sources (`tests/calendar/calendar_core_test.php`, `native_entry_test.php`)
- [x] Schedules/windows/overrides (`schedule_model_test.php`)
- [x] Slot generation (`slot_generator_test.php`)
- [x] Recurrence incl. nth-occurrence (`recurrence_nth_occurrence_test.php`)
- [x] ICS import (`ics_import_test.php`)

### 23.2 Booking Flow (gap)
- [ ] Public `/book/{slug}` end-to-end (type → slot → confirm)
- [ ] Lifecycle: cancel / reschedule / reminders (`BookingEmailsTask`)
- [ ] Intake surveys + paid holds
- [ ] Provider seam contract (`NativeSchedulingProvider`)

---

## 24. Mailbox (Self-Hosted Email)

*Shipped progressively; see `plugins/mailbox/docs/overview.md`. 18 plugin suites.*

- [x] Inbound pipeline: attachments, raw storage, grants, IMAP poll/sync (`plugins/mailbox/tests/inbound_*`, `imap_*`)
- [x] Reader + profile surfaces (`mailbox_reader_test.php`, `profile_mailbox_test.php`)
- [x] Encryption at rest + reseal (`mailbox_reseal_test.php`, core vault suites)
- [x] Spam/content filtering + auth results (`spam_filtering_test.php`, `authentication_results_test.php`)
- [x] Relay: fix pack, inbound-only enforcement, shared fleet (`relay_*`)
- [x] Forwarding relay integration (`tests/integration/inbound_forwarding_relay_test.php`)
- [ ] Fleet live verification (real shard VPS + second tenant) — pending infra
- [ ] Compose maturity features (drafts, signatures, contacts) — unbuilt, spec active

---

## 25. Joinery AI

*Shipped progressively (`implemented/joinery_ai_*`).*

- [x] Durable memory: remember/recall/forget + injection (`plugins/joinery_ai/tests/ai_memory_test.php`)
- [x] Chat cancel, encryption, conversation search (`chat_cancel_test.php`, `chat_encryption_test.php`, `chat_search_conversations_test.php`)
- [x] Provider resilience (`llm_provider_resilience_test.php`)
- [x] Pipeline runner, owner scoping, turn activity (`tests/integration/joinery_ai_*`)
- [x] Email security scan job + digest (`tests/integration/email_security_scan_job_test.php`, `tests/unit/email_security_digest_test.php`)
- [ ] Chat UI flows (browser/manual)
- [ ] Recipes end-to-end against live local model (manual; see eval log spec)
- [ ] Manual exercise pass of AI email features (`specs/manual_exercise_ai_email_features.md` — pending)

---

## 26. Mobile Apps & Billing

*Apps built (`implemented/ios_app_platform.md`, `android_app_platform.md`, member screens, ScrollDaddy apps); billing committed 2026-07-16.*

- [x] Billing harness: Apple IAP + Play subscriptions (49 checks, `plugins/store/tests/mobile_billing_test.php`)
- [x] API platform + member screens (`tests/functional/api/app_platform_test.php`, `member_screens_test.php`)
- [x] iOS gates (`tests/functional/ios/*_gate.sh`); Android member gate (`tests/functional/android/member_gate.sh`)
- [ ] Store-console e2e (sandbox purchase → entitlement) — pending console setup
- [ ] Release-spec gates (TestFlight/Play closed testing, physical devices) — see the four release specs

---

## 27. Passkeys & Account Security Levels

*Shipped 2026-07 (`implemented/passkeys_core.md`, security-levels executor).*

- [x] Build-time verification: 12 acceptance criteria browser-verified (virtual authenticator)
- [x] Session key handling (`tests/functional/api/session_keys_test.php`)
- [ ] Standing automated suite for sign-in / step-up / unlock windows (virtual authenticator lacks PRF — PRF-dependent paths need on-device manual passes)
- [ ] Reset authorizers + passkey-as-2FA regression

---

## Testing Roadmap

### P1: Immediate Priorities (Critical gaps with no coverage)

#### 1. Authentication Flow Tests (Section 1)
- **Type needed:** Browser/functional tests using MCP Playwright
- **File to create:** `/tests/functional/auth/AuthTester.php`
- **Key scenarios:**
  - Registration with valid/invalid data; anti-spam question; AJAX email uniqueness check
  - Login success (redirects to profile); login failure (error message shown)
  - "Remember me" cookie: survives session close, honored on next visit
  - Password reset: step 1 sends email; step 2 accepts valid code; rejects expired/invalid code
  - Forced password change: redirect on login when `usr_force_password_change` is set
  - Session permission enforcement: `/admin/*` routes reject unauthenticated users
- **Test data required:** Dedicated test user account; email test mode enabled

#### 2. Stripe Webhook Integration Tests (Section 15.1)
- **Type needed:** Integration tests
- **File to create:** `/tests/integration/stripe_webhook_test.php`
- **Key scenarios:**
  - Simulate `checkout.session.completed` with test fixture → verify order created
  - Simulate subscription events (`customer.subscription.updated`, `.deleted`)
  - Reject webhook with invalid signature
  - Verify idempotency: replaying same event does not create duplicate order
- **Approach:** Use Stripe test clock and test event JSON fixtures; call webhook handler directly with crafted POST bodies

#### 3. E-Commerce Cart/Checkout Flow (Section 4.5–4.8)
- **Type needed:** Functional/browser tests extending existing `ProductTester`
- **File to extend:** `/tests/functional/products/ProductTester.php`
- **Key scenarios:**
  - Add product to cart → cart page shows item with correct price
  - Remove item from cart → cart clears correctly
  - Apply valid coupon code → discount applied; apply invalid code → error shown
  - Proceed to Stripe checkout (test mode) → return URL creates order
  - Order appears in user profile and admin order list
- **Dependency:** Stripe test keys must be configured; product with known ID must exist

#### 4. Security Validation Tests (Section 19)
- **Type needed:** Integration tests
- **File to create:** `/tests/integration/security_test.php`
- **Key scenarios:**
  - CSRF: submit form without `_csrf_token` → rejected
  - Permission gates: request `/admin/admin_users` as unauthenticated → 302 to login
  - Permission gates: request superadmin route as permission-5 admin → blocked
  - File upload: upload disallowed extension (`.php`) → rejected
  - File upload: access `/uploads/private-file.pdf` without auth → blocked
  - SQL injection probe: parameter with `' OR 1=1 --` in search fields → no leakage

---

### P2: Short-Term Priorities (High-value gaps)

#### 5. REST API Tests (Section 14)
- **Type needed:** Integration tests
- **File to create:** `/tests/integration/api_test.php`
- **Key scenarios:**
  - Valid API key (public + secret) → successful CRUD response
  - Invalid secret key → 401 Unauthorized
  - Request from non-allowed IP → 403 Forbidden
  - GET `/api/v1/User/{id}` → returns user JSON matching expected shape
  - POST to create record → record appears in database
  - DELETE record → soft-deleted in database

#### 6. Event Management Functional Tests (Section 5)
- **Type needed:** Functional tests
- **File to create:** `/tests/functional/events/EventTester.php`
- **Key scenarios:**
  - Event creation (admin) → appears on public listing
  - User registers for event → appears in user profile and admin event registrant list
  - Registration at capacity → subsequent registration goes to waiting list
  - User withdraws → registration removed; waiting list user notified
  - Calendar export: `.ics` file generated with correct date/location fields

#### 7. File Upload/Access Tests (Section 8)
- **Type needed:** Integration tests
- **File to create:** `/tests/integration/file_upload_test.php`
- **Key scenarios:**
  - Upload `.jpg` within size limit → file stored, metadata saved, thumbnail generated
  - Upload `.php` → rejected (extension not in `allowed_upload_extensions`)
  - Upload file exceeding size limit → rejected with error message
  - Access `/uploads/{private-file}` authenticated as owner → served
  - Access `/uploads/{private-file}` unauthenticated → redirect to login or 403
  - Access `/uploads/{private-file}` as different user without permission → blocked

#### 8. Admin Interface Smoke Tests (Sections 9, 13)
- **Type needed:** Browser smoke tests via MCP Playwright
- **Approach:** Authenticate as permission-10 admin; verify each admin page loads without PHP errors
- **Key pages to verify:** `/admin/admin_users`, `/admin/admin_settings`, `/admin/admin_plugins`, `/admin/admin_utilities`, `/admin/admin_errors`, `/admin/admin_event_list`, `/admin/admin_products`
- **Pass criteria:** Page returns HTTP 200; no "Fatal error" or "Warning:" in body

---

### P3: Long-Term Improvements

#### 9. Mailing List / Inbound Email Tests (Section 6.4, 6.7)
- Simulate Mailgun inbound webhook POST → verify email stored in `iem_inbound_emails`
- Mailing list subscription: user subscribes → appears in list registrants
- Unsubscribe: user unsubscribes → removed from list

#### 10. ControlD Plugin Tests (Section 18.3)
- Device CRUD via model auto-discovery (should already work if class follows patterns)
- Activation workflow: device created → activated → profile applied
- Profile management: filter toggle → change persisted to ControlD API

#### 11. Test Infrastructure Improvements
- Create `/tests/index.php` master dashboard linking all test suites (email, models, integration, functional)
- Add test data fixtures: a known-state database snapshot that can be restored before test runs
- Document per-section manual test procedures for features that will remain manual
- Add routing test coverage for plugin routes (currently `routing_test.php` covers core only)

---

### Test Dependency Map

Run tests in this order to avoid dependency failures:

```
Model Tests          — No dependencies; run first
    ↓
Email Tests          — Requires: Mailgun/SMTP configured; email_test_mode=1; test recipient set
    ↓
Integration Tests    — Requires: web server running; database accessible
    ↓
Functional Tests     — Requires: Stripe test keys; test user; test products exist
    ↓
Browser Tests        — Requires: full stack running; test data loaded
```

### Test Data Requirements

Before running functional or browser tests, verify:
- Test admin account exists: `jeremy.tunnell+claude@gmail.com` (permission level 10)
- `email_test_mode = 1` in settings (prevents real emails during tests)
- `email_test_recipient` set to a monitored inbox
- Stripe test publishable and secret keys configured (not live keys)
- At least one active product exists with a known product ID
- At least one subscription tier exists for subscription flow tests

---

## Summary Statistics

| Category | Feature Count |
|----------|--------------|
| Authentication & Accounts | 30+ |
| User Profile | 25+ |
| Content Management | 35+ |
| E-Commerce | 50+ |
| Event Management | 30+ |
| Email System | 30+ |
| Surveys & Forms | 10+ |
| File Management | 15+ |
| Admin User Management | 20+ |
| Navigation & Menus | 10+ |
| URL Management | 10+ |
| Analytics | 10+ |
| System Administration | 30+ |
| REST API | 5+ |
| Integrations | 25+ |
| SEO & Public | 10+ |
| Theme System | 15+ |
| Plugins | 25+ |
| Security | 15+ |
| Developer Tools | 15+ |
| Drive & Blob Storage | 25+ |
| Sealed Vault & Password Vault | 20+ |
| Calendar, Scheduling & Bookings | 30+ |
| Mailbox (Self-Hosted Email) | 40+ |
| Joinery AI | 30+ |
| Mobile Apps & Billing | 25+ |
| Passkeys & Account Security | 15+ |
| **Total** | **~600+ testable features** |
