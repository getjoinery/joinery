# System Features Inventory

**Purpose:** Comprehensive feature list for testing coverage. Each feature is listed at a granular level suitable for creating individual test cases.

**Last Updated:** 2026-04-04

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

| Type | Location | What It Tests | When to Run |
|------|----------|---------------|-------------|
| **Model Tests** | `/tests/models/` | CRUD operations on all data model classes; auto-discovers all `data/*_class.php` files | Before any release; after schema changes |
| **Email Tests** | `/tests/email/` | Email service config (SMTP/Mailgun), template rendering, delivery, DNS auth records | After email system changes; before bulk sends |
| **Integration Tests** | `/tests/integration/` | Routing, error handling, PHPMailer, Mailgun API, external service wiring | After routing or integration changes |
| **Functional Tests** | `/tests/functional/` | End-to-end business flows (product purchase, subscription lifecycle) | Before releases touching e-commerce |
| **Browser Tests** | MCP Playwright | Page rendering, form interactions, UI state, visual layout | After theme or view changes |
| **Manual Tests** | Human judgment | Third-party payment UIs, visual design, admin workflows, edge cases requiring context | Ad hoc; documented in this checklist |

---

### Priority Levels

- **P1 — Critical Path**: Broken functionality blocks users or revenue. Must pass before any release.
- **P2 — High Value**: Important user-facing features; test when the related area changes.
- **P3 — Standard**: Supporting features; test periodically or when directly touched.

---

### Coverage Summary by Section

| # | Section | Priority | Coverage | Notes |
|---|---------|----------|----------|-------|
| 1 | Authentication & Account Management | **P1** | ❌ Gap | No automated auth flow tests; all manual |
| 2 | User Profile | **P2** | ❌ Gap | Model CRUD auto-covered; UI flows are manual |
| 3 | Content Management System | **P2** | ⚠️ Partial | Blog/page model CRUD auto-covered; rendering is manual |
| 4 | E-Commerce | **P1** | ⚠️ Partial | `ProductTester` covers product logic; cart/checkout UI is manual |
| 5 | Event Management | **P1** | ❌ Gap | Model CRUD auto-covered; registration flows are manual |
| 6 | Email System | **P2** | ✅ Covered | All 11 send patterns, SMTP/Mailgun, templates, DNS tested |
| 7 | Surveys & Forms | **P3** | ⚠️ Partial | Model CRUD auto-covered; survey flow UI is manual |
| 8 | File & Media Management | **P2** | ❌ Gap | No upload/access-control tests; all manual |
| 9 | User Management (Admin) | **P2** | ⚠️ Partial | Model CRUD auto-covered; admin UI is manual |
| 10 | Navigation & Menus | **P3** | ❌ Gap | Manual only |
| 11 | URL Management | **P3** | ❌ Gap | Manual only |
| 12 | Analytics & Statistics | **P3** | ❌ Gap | Manual only |
| 13 | System Administration | **P2** | ⚠️ Partial | Error handling tested; settings/plugins are manual |
| 14 | REST API | **P2** | ❌ Gap | No API endpoint tests exist |
| 15 | Integrations | **P1** | ⚠️ Partial | Mailgun tested; Stripe webhooks, PayPal, Calendly are manual |
| 16 | SEO & Public Features | **P3** | ❌ Gap | Manual only |
| 17 | Theme System | **P3** | ❌ Gap | Visual/browser testing only |
| 18 | Plugins | **P2** | ❌ Gap | No ControlD or Items plugin tests |
| 19 | Security Features | **P1** | ❌ Gap | No security-specific tests; depends on model/integration tests |
| 20 | Developer & Maintenance | **P3** | ⚠️ Partial | Tools exist; no automated validation of deployment scripts |

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
- [ ] Login with email and password
- [ ] "Remember me" persistent cookie login
- [ ] Secure cookie attributes (SameSite, HttpOnly, Secure)
- [ ] Failed login error message with retry
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
- [ ] Session-based authentication
- [ ] Permission level enforcement (0=user, 5=admin, 8=editor, 10=superadmin)
- [ ] `check_permission()` auto-redirect to login for unauthorized access
- [ ] Session message queuing for flash messages
- [ ] Location tracking and geolocation data

---

## 2. User Profile

### 2.1 Profile Dashboard
- [ ] Display user feed with social-style posts
- [ ] Show account summary (name, email, mailing list status)
- [ ] Show event registrations section
- [ ] Show subscriptions section
- [ ] Show orders section
- [ ] "Edit Account" button navigation

### 2.2 Account Editing
- [ ] Edit first name, last name, nickname
- [ ] Edit email address
- [ ] Organization name field
- [ ] Timezone selection
- [ ] Profile photo/avatar management

### 2.3 Address Management
- [ ] Add/edit user addresses (`address_edit`)
- [ ] Multiple address support

### 2.4 Phone Number Management
- [ ] Add/edit phone numbers (`phone_numbers_edit`)
- [ ] Phone verification system (`admin_phone_verify`)

### 2.5 Contact Preferences
- [ ] Email communication opt-in/opt-out (`contact_preferences`)
- [ ] Contact preference change timestamp tracking
- [ ] Mailing list subscription management from profile

### 2.6 Subscription Management
- [ ] View active subscriptions (`subscriptions`)
- [ ] Change subscription tier (`change-tier`)
- [ ] Subscription cancellation (when `subscription_cancellation_enabled`)
- [ ] Subscription downgrade (when `subscription_downgrades_enabled`)
- [ ] Subscription reactivation (when `subscription_reactivation_enabled`)
- [ ] Prorate calculations for upgrades/downgrades/cancellations
- [ ] Maximum subscriptions per user limit (`max_subscriptions_per_user`)

### 2.7 Event Registration (from profile)
- [ ] View registered events
- [ ] Event registration completion (`event_register_finish`)
- [ ] Event session viewing (`event_sessions`, `event_sessions_course`)
- [ ] Event withdrawal (`event_withdraw`)

### 2.8 Order Management (from profile)
- [ ] View order history
- [ ] Recurring order actions (`orders_recurring_action`)

---

## 3. Content Management System

### 3.1 Static Pages
- [ ] Create/edit static pages with HTML content
- [ ] Page content sections (`page_contents`)
- [ ] Content versioning with edit history (`content_versions`)
- [ ] URL-safe slugs for pages
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
- [ ] Blog post creation and editing (admin)
- [ ] Post publication status and scheduling
- [ ] Featured image support
- [ ] Author attribution
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
- [ ] Video content management
- [ ] Video listing page
- [ ] Single video display
- [ ] Feature toggle: `videos_active`

---

## 4. E-Commerce

### 4.1 Products
- [ ] Product catalog listing with pagination (12 per page)
- [ ] Single product detail page with description
- [ ] Product image display
- [ ] Product URL slugs
- [ ] Product groups/categories
- [ ] Feature toggle: `products_active`

### 4.2 Product Versions (Pricing Tiers)
- [ ] Multiple pricing tiers per product
- [ ] Version-specific pricing
- [ ] Product version editing (admin)

### 4.3 Product Groups
- [ ] Group products into categories
- [ ] Product group management (admin)
- [ ] Products can list events (`products_list_events_active`)
- [ ] Products can list items (`products_list_items_active`)

### 4.4 Product Requirements
- [ ] Define purchase requirements (name, phone, DOB, address, GDPR, etc.)
- [ ] Requirement instance tracking per order
- [ ] Requirement validation during checkout

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
- [ ] Create discount codes
- [ ] Percentage and fixed-amount discounts
- [ ] Usage limits per coupon
- [ ] Coupon usage tracking
- [ ] Product-specific coupon restrictions
- [ ] Coupon expiration dates
- [ ] Feature toggle: `coupons_active`

### 4.8 Orders
- [ ] Order creation from checkout
- [ ] Order item tracking
- [ ] Order status management
- [ ] Order refunds (`admin_order_refund`)
- [ ] Order deletion (admin)
- [ ] Single purchase notification emails
- [ ] Subscription notification emails

### 4.9 Subscriptions & Recurring Billing
- [ ] Subscription tier definitions
- [ ] Recurring billing via Stripe
- [ ] Subscription upgrade with proration
- [ ] Subscription downgrade with proration
- [ ] Subscription cancellation with timing (Immediate)
- [ ] Subscription reactivation
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
- [ ] Session file attachments (`esf_event_session_files`)
- [ ] Course session viewing from profile

### 5.5 Event Waiting List
- [ ] Waiting list when event is full
- [ ] Waiting list management page
- [ ] Waiting list notifications

### 5.6 Event Withdrawal
- [ ] User-initiated event withdrawal
- [ ] Withdrawal processing

### 5.7 Event Types
- [ ] Event type definitions (Live Online, Self Paced Online, Retreats, etc.)
- [ ] Event type management (admin)

### 5.8 Event Locations
- [ ] Location management with details
- [ ] Location display page
- [ ] Location association with events

### 5.9 Event Bundles
- [ ] Bundle multiple events together
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
- [ ] Send individual emails
- [ ] Send bulk emails to groups/lists
- [ ] Email queue for batch processing (`equ_queued_emails`)
- [ ] Pluggable email provider system via `EmailProviderInterface` (see `specs/implemented/email_provider_abstraction.md`)
- [ ] Email providers: Mailgun, PHPMailer (SMTP), with provider auto-detection from site settings
- [ ] Email dry run mode (`email_dry_run`)
- [ ] Email test mode with test recipient (`email_test_mode`, `email_test_recipient`)
- [ ] Email debug mode logging (`email_debug_mode`)
- [ ] Feature toggle: `emails_active`

### 6.2 Email Templates
- [ ] Create/edit email templates
- [ ] Template preview (AJAX-based)
- [ ] HTML and plain text variants
- [ ] Variable substitution in templates
- [ ] Default email template configuration
- [ ] Outer template wrapping (header/footer)
- [ ] Inner template for content
- [ ] Bulk email footer template
- [ ] Template permanent deletion

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
- [ ] Mailgun inbound webhook processing
- [ ] HMAC signature validation
- [ ] Email storage in `iem_inbound_emails`
- [ ] Testing via `*@inbox.joinerytest.site`

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
- [ ] Group creation and management
- [ ] Group member management
- [ ] Group permanent deletion
- [ ] Group-based email sending

### 9.5 Subscription Tiers
- [ ] Tier definition and editing
- [ ] Tier pricing configuration
- [ ] Subscription tier assignment

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
- [ ] Custom URL shortcut creation
- [ ] Permanent (301) redirects
- [ ] Temporary redirects
- [ ] URL redirect listing and management
- [ ] Feature toggle: `urls_active`

### 11.2 Routing System
- [ ] Front controller pattern via `serve.php`
- [ ] Dynamic route matching with parameters
- [ ] Static file serving with HTTP caching
- [ ] Plugin route integration
- [ ] Theme route override support
- [ ] `.php` extension stripped from URLs
- [ ] Profile routes with fallback

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
- [ ] General error log tracking (`err_general_errors`)
- [ ] Apache error log viewing (`admin_apache_errors`)
- [ ] Form error logging (`lfe_log_form_errors`)
- [ ] Error deletion and cleanup
- [ ] Show errors toggle (`show_errors`)

### 13.7 Event Logging
- [ ] System event log tracking (`evl_event_logs`)
- [ ] Change tracking audit trail (`cht_change_tracking`)
- [ ] Login history (`log_logins`)

### 13.8 Database Management
- [ ] Automatic schema updates from model `$field_specifications`
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
- [ ] Key-based authentication (public + secret keys)
- [ ] IP restriction enforcement
- [ ] Model discovery system
- [ ] CRUD operations on data models
- [ ] JSON response format
- [ ] User validation

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
- [ ] Email sending via `MailgunEmailProvider` (implements `EmailProviderInterface`)
- [ ] Inbound email webhook
- [ ] Webhook signature validation
- [ ] EU API endpoint support

### 15.4 SMTP / PHPMailer
- [ ] Email sending via `PHPMailerEmailProvider` (implements `EmailProviderInterface`)
- [ ] Configurable host, port, authentication
- [ ] Provider auto-selected based on site settings (`mailgun_api_key` present = Mailgun, otherwise PHPMailer)

### 15.5 Calendly
- [ ] Webhook for new invitees (`calendly_webhook.php`)
- [ ] Webhook for cancellations (`calendly_webhook_cancel.php`)
- [ ] Calendly initialization (`calendly_init.php`)
- [ ] Booking synchronization
- [ ] Calendly URI tracking per user

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
- [ ] Dynamic robots.txt generation with configurable rules
- [ ] Dynamic XML sitemap generation
- [ ] URL-friendly slugs for all content types
- [ ] Page title management
- [ ] Preview image for social sharing

### 16.2 Cookie Consent
- [ ] GDPR cookie consent mode
- [ ] Cookie consent tracking (`cookie_consent.php` AJAX)
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
- [ ] Theme override chain: theme/{theme}/path > plugins/{plugin}/path > core path
- [ ] Theme-specific assets (CSS, JS, images, fonts)
- [ ] Theme-specific view overrides
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
- [ ] Booking types with Calendly integration
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

### 18.3 ControlD Plugin (Active)
- [ ] Device management (add, edit, delete, soft delete)
- [ ] DNS filtering profile management
- [ ] Filter configuration (50+ categories: ads, malware, phishing, gambling, etc.)
- [ ] Service category management (200+ services: audio, social, gaming, etc.)
- [ ] Rule management
- [ ] Device backup system
- [ ] Device activation workflow
- [ ] Profile management from user dashboard
- [ ] ControlD API key integration
- [ ] Plugin-specific routing and views
- [ ] Plugin-specific pricing page
- [ ] Tier-based feature access (`tier_features.json`)
Note: ControlD admin pages (admin_ctld_account, admin_ctld_accounts) were removed when subscription management was moved to core (commit d43c0f30).

---

## 19. Security Features

### 19.1 Authentication Security
- [ ] Password hashing (bcrypt)
- [ ] CSRF token protection (`_csrf_token`)
- [ ] Secure cookie implementation
- [ ] IP-based API key restrictions
- [ ] Permission level enforcement at page/route level

### 19.2 Input Validation
- [ ] Server-side input validation (Validator class)
- [ ] Client-side validation (Joinery Validation v1.0.8)
- [ ] Prepared statements for all database queries (PDO)
- [ ] Honeypot fields for bot detection
- [ ] hCaptcha support
- [ ] Anti-spam questions

### 19.3 Access Control
- [ ] Authenticated file access for uploads
- [ ] Admin permission checks (level 5+)
- [ ] Superadmin restrictions (level 10)
- [ ] Plugin test access restricted to superadmin

---

## 20. Developer & Maintenance Features

### 20.1 Development Tools
- [ ] PHP syntax validation (`php -l`)
- [ ] Method existence validator (`validate_php_file.php`)
- [ ] Error log monitoring
- [ ] Debug mode (`debug` setting)
- [ ] Debug CSS mode (`debug_css` setting)

### 20.2 Deployment
- [ ] Installation scripts (`install_tools/`)
- [ ] Deployment scripts (`deploy.sh`)
- [ ] Database backup and restore (`sysadmin_tools/`)
- [ ] Upgrade server system
- [ ] Remote archive refresh

### 20.3 Testing Infrastructure
- [ ] Email tests (`/tests/email/`)
- [ ] Functional tests (`/tests/functional/`)
- [ ] Integration tests (`/tests/integration/`)
- [ ] Model tests (`/tests/models/`)
- [ ] Plugin tests (`/plugins/{plugin}/tests/`)
- [ ] Test database management

---

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
| **Total** | **~400+ testable features** |
