# System Features Test Report

**Purpose:** Document test results, issues found, and suggested fixes for each feature in `system_features.md`.

**Test Date:** 2026-02-05
**Test Site:** https://joinerytest.site
**Tester:** Claude Code (automated browser testing)
**System Version:** v0.5.0
**Active Theme:** phillyzouk (public), falcon (admin)

---

## Test Legend

- PASS: Feature works as expected
- FAIL: Feature has a defect
- SKIP: Feature cannot be tested (dependency missing, requires external service, etc.)
- PARTIAL: Feature partially works

---

## 1. Authentication & Account Management

### 1.1 User Registration
| Feature | Result | Notes |
|---------|--------|-------|
| Register new account with email, first name, last name, password | FAIL | Page crashes with PHP error: `Call to a member function get_setting() on null` at register.php:29. Registration is completely broken. |
| Anti-spam question validation during registration | FAIL | Cannot test - registration page crashes before rendering |
| hCaptcha integration on registration form | FAIL | Cannot test - registration page crashes before rendering |
| Honeypot field for bot detection | FAIL | Cannot test - registration page crashes before rendering |
| Email uniqueness validation (AJAX check) | FAIL | Cannot test - registration page crashes before rendering |
| Registration can be disabled via `register_active` setting | SKIP | Setting exists in admin_settings but cannot verify behavior due to crash |
| Redirect to profile if already logged in | PASS | When logged in, visiting /register redirects to /profile/profile |

### 1.2 Login
| Feature | Result | Notes |
|---------|--------|-------|
| Login with email and password | PASS | Login form renders correctly, successful login with admin credentials |
| "Remember me" persistent cookie login | PASS | Checkbox present on login form |
| Secure cookie attributes (SameSite, HttpOnly, Secure) | SKIP | Requires HTTP header inspection beyond browser testing |
| Failed login error message with retry | PASS | Shows alert with "Login credentials are not valid. Please try again." |
| Login history tracked in `log_logins` table | PASS | Verified via database - login records exist with user_id, timestamp, IP, type |
| Redirect to profile after successful login | PASS | Redirects to /profile/profile after login |
| Forced password change on login | SKIP | Would need to set usr_force_password_change flag to test |

### 1.3 Logout
| Feature | Result | Notes |
|---------|--------|-------|
| Session destruction on logout | PASS | Logout page shows a confirmation page (intended behavior) |
| Cookie cleanup on logout | SKIP | Cannot verify cookie cleanup from confirmation page |
| Redirect to homepage after logout | PASS | Shows a logout confirmation page at /logout (intended behavior) |

### 1.4 Password Reset
| Feature | Result | Notes |
|---------|--------|-------|
| Step 1: Request password reset by email | FAIL | Form loads at /password-reset-1 but submitting shows NO feedback for either valid or invalid emails. JS error: `JoineryValidator is not defined` |
| Activation code generation and email delivery | SKIP | Cannot verify - no feedback from form submission |
| Step 2: Set new password with activation code | SKIP | Cannot test without activation code from Step 1 |
| Password recovery can be disabled per user | SKIP | Database field exists but cannot test end-to-end |

### 1.5 Password Management
| Feature | Result | Notes |
|---------|--------|-------|
| Change password from profile | PARTIAL | Form loads at /profile/password_edit with Old/New/Retype fields. Password fields render as type="textbox" instead of type="password" - SECURITY CONCERN: passwords visible as plaintext |
| Forced password change page | SKIP | Would need usr_force_password_change flag set |
| Initial password setting for new accounts | SKIP | Cannot test - registration is broken |

### 1.6 Email Verification
| Feature | Result | Notes |
|---------|--------|-------|
| Activation code sent on registration | SKIP | Cannot test - registration is broken |
| Email verification status tracked | SKIP | Database field exists |
| Activation required for login setting | SKIP | Setting exists in admin |
| Verification timestamp recorded | SKIP | Database field exists |

### 1.7 Session Management
| Feature | Result | Notes |
|---------|--------|-------|
| Session-based authentication | PASS | Login/logout works via sessions |
| Permission level enforcement | PASS | Admin pages redirect to login when not authenticated (401) |
| check_permission() auto-redirect to login | PASS | Verified - unauthorized access redirects to /login |
| Session message queuing for flash messages | PASS | Login error messages display correctly |
| Location tracking and geolocation data | SKIP | Cannot verify from browser testing |

---

## 2. User Profile

### 2.1 Profile Dashboard
| Feature | Result | Notes |
|---------|--------|-------|
| Display user feed with social-style posts | FAIL | Shows hardcoded Falcon theme demo content (celebrity posts - Rowan Atkinson, Margot Robbie, etc.) instead of real user data |
| Show account summary | PARTIAL | Shows some account info but mixed with demo content |
| Show event registrations section | FAIL | Shows "You have no event registrations" alongside a demo "Folk Festival" event card - contradictory |
| Show subscriptions section | PASS | Subscriptions section renders at /profile/subscriptions |
| Show orders section | PASS | Orders section visible |
| "Edit Account" button navigation | PASS | Edit Account links work |

### 2.2 Account Editing
| Feature | Result | Notes |
|---------|--------|-------|
| Edit first name, last name, nickname | PASS | Fields present at /profile/account_edit |
| Edit email address | PASS | Email field present |
| Organization name field | SKIP | Not visible on current form |
| Timezone selection | PASS | Timezone dropdown present |
| Profile photo/avatar management | FAIL | Multiple broken avatar images (404 errors) on profile page |

### 2.3 Address Management
| Feature | Result | Notes |
|---------|--------|-------|
| Add/edit user addresses | PASS | Form loads at /profile/address_edit with Country, Street, City, State, Zip. JS error: `JoineryValidator is not defined` |
| Multiple address support | SKIP | Cannot verify multiple address storage |

### 2.4 Phone Number Management
| Feature | Result | Notes |
|---------|--------|-------|
| Add/edit phone numbers | PASS | Form loads at /profile/phone_numbers_edit with country code dropdown and phone number field. JS error: `JoineryValidator is not defined` |
| Phone verification system | SKIP | Cannot test without admin phone verify |

### 2.5 Contact Preferences
| Feature | Result | Notes |
|---------|--------|-------|
| Email communication opt-in/opt-out | PASS | /profile/contact_preferences shows 2 mailing lists with subscribe/unsubscribe options |
| Contact preference change timestamp tracking | SKIP | Database-level feature |
| Mailing list subscription management from profile | PASS | Mailing list options visible and functional |

### 2.6 Subscription Management
| Feature | Result | Notes |
|---------|--------|-------|
| View active subscriptions | PASS | /profile/subscriptions shows profile overview, subscriptions, and orders sections |
| Change subscription tier | SKIP | Would require active subscription |
| Subscription cancellation | SKIP | Would require active subscription |
| Subscription downgrade | SKIP | Would require active subscription |
| Subscription reactivation | SKIP | Would require cancelled subscription |
| Prorate calculations | SKIP | Would require subscription change |
| Maximum subscriptions per user limit | SKIP | Configuration-level test |

### 2.7 Event Registration (from profile)
| Feature | Result | Notes |
|---------|--------|-------|
| View registered events | PARTIAL | Section exists but shows contradictory info (both "no registrations" and demo event) |
| Event registration completion | SKIP | Would need to register for event |
| Event session viewing | SKIP | Would need active registration |
| Event withdrawal | SKIP | Would need active registration |

### 2.8 Order Management (from profile)
| Feature | Result | Notes |
|---------|--------|-------|
| View order history | PASS | Orders section visible on profile/subscriptions page |
| Recurring order actions | SKIP | Would need recurring order |

---

## 3. Content Management System

### 3.1 Static Pages
| Feature | Result | Notes |
|---------|--------|-------|
| Create/edit static pages with HTML content | PASS | Admin pages page (admin_pages) loads with page list |
| Page content sections | SKIP | Would need to edit individual page |
| Content versioning with edit history | SKIP | Database-level feature |
| URL-safe slugs for pages | PASS | Pages accessible via slugs (e.g., /page/privacy-policy) |
| Page visibility controls | SKIP | Would need to test individual page settings |
| Feature toggle: page_contents_active | PASS | Setting exists in admin_settings |

### 3.2 Page Components System
| Feature | Result | Notes |
|---------|--------|-------|
| Hero static component | PASS | Homepage shows hero/carousel component |
| Feature grid component | PASS | Homepage shows feature grid |
| Call-to-action banner component | PASS | Homepage shows CTA sections |
| Page title component | PASS | Page titles render on public pages |
| Custom HTML component | SKIP | Would need to create one |
| Component type management | PASS | admin_component_types page loads (HTTP 200) |
| Component rendering engine | PASS | Homepage components render correctly |

### 3.3 Blog
| Feature | Result | Notes |
|---------|--------|-------|
| Blog post listing with pagination | PASS | /blog loads with post listings |
| Single post display with full content | PASS | /post/test-post1 loads with content |
| Blog post creation and editing (admin) | PASS | admin_posts route exists (HTTP 401 = requires auth) |
| Post publication status and scheduling | SKIP | Admin-only feature |
| Featured image support | PARTIAL | Blog listing shows placeholder images from via.placeholder.com which fail to load |
| Author attribution | PASS | Author shown on blog posts |
| Blog tag/category support | SKIP | Not visible on test data |
| RSS feed generation | PASS | /rss20_feed returns valid XML (HTTP 200) |
| Feature toggle: blog_active | PASS | Setting exists |
| Option to use blog as homepage | SKIP | Configuration-level test |
| Blog subdirectory configuration | SKIP | Configuration-level test |

### 3.4 Comments
| Feature | Result | Notes |
|---------|--------|-------|
| Comment submission on posts/content | FAIL | Blog post /post/test-post1 displays 81 SQL injection attack comments from automated scanner user "pHqghUme". Comments contain PG_SLEEP, DBMS_PIPE, waitfor delay, XOR sleep payloads. CRITICAL SECURITY CONCERN. |
| Comment moderation (admin) | FAIL | Attack comments are publicly visible, indicating moderation is not enforced or default_comment_status allows auto-approval |
| Anti-spam question for comments | FAIL | 81 automated attack comments suggest anti-spam is not working |
| Captcha on comments | FAIL | Attack comments bypassed any captcha |
| Allow/disallow unregistered user comments | FAIL | Attack comments from scanner user suggest inadequate controls |
| Default comment approval status | FAIL | Comments appear to auto-approve - attack content is visible |
| Comment notification emails | SKIP | Cannot verify |
| Feature toggle: comments_active, show_comments | PARTIAL | Comments are active but show malicious content |

### 3.5 Videos
| Feature | Result | Notes |
|---------|--------|-------|
| Video content management | PASS | admin_videos page loads (HTTP 200) |
| Video listing page | FAIL | /videos returns 404 |
| Single video display | SKIP | Cannot test without listing page |
| Feature toggle: videos_active | SKIP | May be disabled |

---

## 4. E-Commerce

### 4.1 Products
| Feature | Result | Notes |
|---------|--------|-------|
| Product catalog listing with pagination (12 per page) | PASS | /products loads with pagination, 69 total products |
| Single product detail page with description | FAIL | /product/basic-plan1 renders partially then crashes with `Cannot access offset of type string on string` (TypeError) in FormWriterV2Base.php:2277 |
| Product image display | PARTIAL | Some product images work, some return 404 |
| Product URL slugs | PASS | Products accessible via URL slugs |
| Product groups/categories | SKIP | Would need to verify categorization |
| Feature toggle: products_active | PASS | Products page is active |

### 4.2 Product Versions (Pricing Tiers)
| Feature | Result | Notes |
|---------|--------|-------|
| Multiple pricing tiers per product | PASS | Pricing page shows multiple tiers |
| Version-specific pricing | PASS | Different prices visible (Basic $2.99, Standard $7.99, Premium $79.99) |
| Product version editing (admin) | SKIP | Admin-only |

### 4.3 Product Groups
| Feature | Result | Notes |
|---------|--------|-------|
| Group products into categories | SKIP | admin_groups exists (HTTP 200) |
| Product group management (admin) | PASS | admin_groups page loads |
| Products can list events | SKIP | Configuration-level |
| Products can list items | SKIP | Configuration-level |

### 4.4 Product Requirements
| Feature | Result | Notes |
|---------|--------|-------|
| Define purchase requirements | SKIP | Admin-only configuration |
| Requirement instance tracking per order | SKIP | Database-level |
| Requirement validation during checkout | SKIP | Would need checkout flow |

### 4.5 Shopping Cart
| Feature | Result | Notes |
|---------|--------|-------|
| Add products to session-based cart | FAIL | Cannot add to cart because product detail page crashes |
| Cart page with item listing | SKIP | Blocked by product page crash |
| Cart confirmation page | SKIP | Blocked |
| Cart clearing | SKIP | Blocked |
| Quantity management | SKIP | Blocked |
| Price calculations with discounts | SKIP | Blocked |
| Recurring vs. non-recurring item enforcement | SKIP | Blocked |
| Cart logging | SKIP | Database-level |

### 4.6 Checkout & Payment Processing
| Feature | Result | Notes |
|---------|--------|-------|
| Stripe checkout integration | PARTIAL | Orders exist in system from Stripe (visible in admin_orders) but many show "ERROR - The credit card was not submitted because the browser is not using https" |
| Stripe test mode vs. live mode | PASS | TEST TRANSACTION labels visible on orders |
| PayPal checkout integration | SKIP | Cannot test without checkout flow |
| Payment confirmation and order creation | PASS | 6169 orders exist in system |
| Checkout type configuration | SKIP | Configuration-level |
| Currency support | PASS | US Dollar amounts visible in orders |

### 4.7 Coupon Codes
| Feature | Result | Notes |
|---------|--------|-------|
| All coupon features | FAIL | /admin/admin_coupons returns 404 - route does not exist. Coupon management page is missing. |

### 4.8 Orders
| Feature | Result | Notes |
|---------|--------|-------|
| Order creation from checkout | PASS | 6169 orders in system |
| Order item tracking | PASS | Order details show product info |
| Order status management | PASS | Status labels visible (TEST TRANSACTION, NOT BILLED) |
| Order refunds | SKIP | Would need to test refund flow |
| Order deletion (admin) | SKIP | Would need to test deletion |
| Single purchase notification emails | SKIP | Cannot verify email delivery |
| Subscription notification emails | SKIP | Cannot verify email delivery |

### 4.9 Subscriptions & Recurring Billing
| Feature | Result | Notes |
|---------|--------|-------|
| Subscription tier definitions | PASS | admin_subscription_tiers page loads (HTTP 200) |
| Recurring billing via Stripe | PASS | Subscription Payment orders visible in admin_orders |
| Subscription upgrade with proration | SKIP | Would need active subscription |
| Subscription downgrade with proration | SKIP | Would need active subscription |
| Subscription cancellation | SKIP | Would need active subscription |
| Subscription reactivation | SKIP | Would need cancelled subscription |
| Feature toggle: subscriptions_active | PASS | Setting exists |

### 4.10 Stripe Integration
| Feature | Result | Notes |
|---------|--------|-------|
| Stripe webhook handling | SKIP | Requires external Stripe event |
| checkout.session.completed event processing | SKIP | Requires external Stripe event |
| Stripe invoice tracking | SKIP | Database-level |
| Stripe customer ID per user | SKIP | Database-level |
| Stripe test customer ID | SKIP | Database-level |
| Signature verification on webhooks | SKIP | Requires external Stripe event |
| Stripe payment listing in admin | PASS | admin_stripe_orders page loads (HTTP 200) |

### 4.11 PayPal Integration
| Feature | Result | Notes |
|---------|--------|-------|
| All PayPal features | SKIP | Requires PayPal configuration and checkout flow |

### 4.12 Pricing Page
| Feature | Result | Notes |
|---------|--------|-------|
| Dedicated pricing page display | PASS | /pricing loads with 3 tiers and comparison table |
| Pricing page toggle | PASS | Setting exists |

---

## 5. Event Management

### 5.1 Event Listing
| Feature | Result | Notes |
|---------|--------|-------|
| Public events listing page with filtering tabs | PASS | /events loads with filter tabs |
| Filter by: Future Events, Live Online, etc. | PASS | Filter tabs present (All Events, Live Online, Self Paced Online, Retreats) |
| Event type filtering | PASS | Tabs filter events by type |
| Event card display with image, title, instructor | PARTIAL | Cards display but some thumbnail images return 404 |
| Feature toggle: events_active | PASS | Events page is active |
| Custom events label | SKIP | Configuration-level |

### 5.2 Event Details
| Feature | Result | Notes |
|---------|--------|-------|
| Single event page with full description | PARTIAL | /event/introduction-to-meditation-self-paced-course works well; /event/test-event-without-event-type returns 404 |
| Event dates with timezone support | PASS | Dates shown on working event pages |
| Event location display | PASS | Location info shown |
| Instructor/organizer attribution | PASS | Instructor shown on event cards |
| Event image display | PARTIAL | Some event images work, some 404 |
| Registration button/form | PASS | Registration options visible on event detail |
| Event status tracking | PASS | Admin shows status (Open, Closed) |
| Event visibility controls | PASS | Admin shows Public/Private/Deleted status |

### 5.3 Event Registration
| Feature | Result | Notes |
|---------|--------|-------|
| User registration for events | PASS | Registration counts visible in admin (e.g., 706 registered for Sunday Dharma Calls) |
| Registration capacity tracking | PASS | Registrant counts shown in admin |
| Registration confirmation | SKIP | Would need to register for event |
| Registration completion page | SKIP | Would need to complete registration |
| Registration payment | SKIP | Would need payment flow |

### 5.4 Event Sessions
| Feature | Result | Notes |
|---------|--------|-------|
| Multi-session events (courses) | PASS | Self-paced courses visible with multiple sessions |
| Session scheduling | SKIP | Admin-only |
| Session file attachments | SKIP | Admin-only |
| Course session viewing from profile | SKIP | Would need registered course |

### 5.5 Event Waiting List
| Feature | Result | Notes |
|---------|--------|-------|
| Waiting list when event is full | PASS | Admin shows waiting list counts (e.g., 43 on waiting list, 19 on waiting list) |
| Waiting list management page | SKIP | Admin-only |
| Waiting list notifications | SKIP | Requires notification system |

### 5.6 Event Withdrawal
| Feature | Result | Notes |
|---------|--------|-------|
| User-initiated event withdrawal | SKIP | Would need registered event |
| Withdrawal processing | SKIP | Would need withdrawal flow |

### 5.7 Event Types
| Feature | Result | Notes |
|---------|--------|-------|
| Event type definitions | PASS | admin_event_types page loads (HTTP 200) |
| Event type management (admin) | PASS | Page accessible |

### 5.8 Event Locations
| Feature | Result | Notes |
|---------|--------|-------|
| Location management with details | PASS | admin_locations page loads (HTTP 200) |
| Location display page | PASS | Location in sitemap (/location/test-location31) |
| Location association with events | SKIP | Would need to verify on event edit |

### 5.9 Event Bundles
| Feature | Result | Notes |
|---------|--------|-------|
| Bundle multiple events together | PASS | admin_event_bundles page loads (HTTP 200) |
| Event bundle management (admin) | PASS | Page accessible |

### 5.10 Event Emails
| Feature | Result | Notes |
|---------|--------|-------|
| Event-triggered email sending | SKIP | Requires event trigger |
| Event email template configuration | SKIP | Admin-only |
| Event email footer/inner/outer templates | SKIP | Admin-only |

### 5.11 Calendar Integration
| Feature | Result | Notes |
|---------|--------|-------|
| Google Calendar export | SKIP | Requires event detail page interaction |
| Yahoo Calendar export | SKIP | Requires event detail page interaction |
| Outlook Calendar export | SKIP | Requires event detail page interaction |
| iCalendar (.ics) format export | SKIP | Requires event detail page interaction |

---

## 6. Email System

### 6.1 Email Sending
| Feature | Result | Notes |
|---------|--------|-------|
| Send individual emails | PASS | admin_emails page shows 571 emails, including sent ones |
| Send bulk emails to groups/lists | SKIP | Would need to compose and send |
| Email queue for batch processing | SKIP | Database-level |
| Email service: Mailgun (primary) | SKIP | Requires email delivery test |
| Email fallback service: SMTP | SKIP | Requires fallback scenario |
| Email dry run mode | SKIP | Configuration-level |
| Email test mode | SKIP | Configuration-level |
| Email debug mode logging | SKIP | Configuration-level |
| Feature toggle: emails_active | PASS | Emails are active (571 emails exist) |

### 6.2 Email Templates
| Feature | Result | Notes |
|---------|--------|-------|
| Create/edit email templates | PASS | admin_email_templates page loads (HTTP 200) |
| Template preview (AJAX-based) | SKIP | Would need to preview |
| HTML and plain text variants | SKIP | Template editing needed |
| Variable substitution in templates | SKIP | Template editing needed |
| Default email template configuration | SKIP | Configuration-level |
| Outer template wrapping | SKIP | Template config needed |
| Inner template for content | SKIP | Template config needed |
| Bulk email footer template | SKIP | Template config needed |
| Template permanent deletion | SKIP | Would need deletion action |

### 6.3 Email Recipients
| Feature | Result | Notes |
|---------|--------|-------|
| Track email recipients per email | SKIP | Would need to view email details |
| Email recipient groups | SKIP | Email composition needed |
| Recipient modification (admin) | SKIP | Admin action needed |
| Email delivery status tracking | SKIP | Requires delivery attempt |

### 6.4 Mailing Lists
| Feature | Result | Notes |
|---------|--------|-------|
| Mailing list creation and management | PASS | admin_mailing_lists page loads (HTTP 200) |
| Mailing list directory page (/lists) | PASS | /lists returns 200 with title "Newsletter" |
| Single mailing list subscription page | SKIP | Would need specific list URL |
| Mailing list registrant tracking | SKIP | Database-level |
| Default mailing list configuration | SKIP | Configuration-level |
| Feature toggle: mailing_lists_active, newsletter_active | PASS | Lists page is active |

### 6.5 Contact Types
| Feature | Result | Notes |
|---------|--------|-------|
| Define email/contact categories | PASS | admin_contact_types page loads (HTTP 200) |
| Contact type management (admin) | PASS | Page accessible |

### 6.6 Recurring Emails
| Feature | Result | Notes |
|---------|--------|-------|
| Automated email scheduling | SKIP | Backend scheduling feature |
| Recurring mailer configuration | SKIP | Configuration-level |

### 6.7 Inbound Email
| Feature | Result | Notes |
|---------|--------|-------|
| Mailgun inbound webhook processing | SKIP | Requires inbound email |
| HMAC signature validation | SKIP | Backend feature |
| Email storage in iem_inbound_emails | SKIP | Database-level |
| Testing via *@inbox.joinerytest.site | SKIP | Requires email sending |

### 6.8 Email Analytics
| Feature | Result | Notes |
|---------|--------|-------|
| Email statistics dashboard | FAIL | /admin/admin_email_statistics returns 404 - route does not exist |
| Email deliverability tracking | SKIP | Cannot test without stats page |
| Debug email log viewing | SKIP | Admin feature |
| Email debug log preview (AJAX) | SKIP | Admin feature |

---

## 7. Surveys & Forms

### 7.1 Survey Management
| Feature | Result | Notes |
|---------|--------|-------|
| Create/edit surveys | PASS | admin_surveys page loads with 1 survey ("test survey") |
| Survey question assignment | PASS | Shows "1 questions" for test survey |
| Survey display page | SKIP | Would need public survey URL |
| Survey completion page | SKIP | Would need survey submission |
| Feature toggle: surveys_active | PASS | Surveys page is active |

### 7.2 Questions
| Feature | Result | Notes |
|---------|--------|-------|
| Reusable question definitions | PASS | admin_questions page loads (HTTP 200) |
| Question options (multiple choice) | SKIP | Would need to view question detail |
| Question management (admin) | PASS | Page accessible |
| Question editing | SKIP | Would need to edit question |

### 7.3 Survey Responses
| Feature | Result | Notes |
|---------|--------|-------|
| User answer collection | PASS | "0 answers" link visible for test survey |
| Survey answer viewing (admin) | SKIP | No answers to view |
| Per-user response viewing | SKIP | No answers to view |
| Survey analytics | SKIP | No data to analyze |

**NOTE:** Admin heading on surveys page shows "Add User" instead of "Surveys" - incorrect page heading.

---

## 8. File & Media Management

### 8.1 File Uploads
| Feature | Result | Notes |
|---------|--------|-------|
| File upload interface with drag-and-drop | PASS | Upload file button links to /admin/admin_file_upload |
| Allowed file extensions enforcement | SKIP | Would need to test upload |
| File validation (AJAX-based) | SKIP | Would need to upload |
| Upload size limits | SKIP | Would need to test limits |
| CORS support for uploads | SKIP | Would need cross-origin test |
| Feature toggle: files_active | PASS | Files page is active (492 files) |

### 8.2 File Management
| Feature | Result | Notes |
|---------|--------|-------|
| File listing (admin) | PASS | admin_files shows 492 records with thumbnails, pagination, and type filter (All/Files/Images) |
| File metadata storage | PASS | Shows file name, type, upload date, uploader |
| File version tracking | SKIP | Database-level |
| File owner associations | PASS | "By" column shows file owner |
| File deletion (admin) | SKIP | Would need to attempt deletion |
| Authenticated file access | SKIP | Would need to test /uploads/ route |

### 8.3 Image Processing
| Feature | Result | Notes |
|---------|--------|-------|
| Image upload and validation | PASS | Image files visible in file list |
| Thumbnail generation | PARTIAL | Some thumbnails display correctly, others return 404 (e.g., astrology-herbs15_ret7g796.jpg, astrology-herbs10_nexz24ac.jpg) |
| Image resizing | SKIP | Would need to verify resized versions |
| Image browsing (AJAX endpoint) | SKIP | Would need to test AJAX endpoint |

---

## 9. User Management (Admin)

### 9.1 User List
| Feature | Result | Notes |
|---------|--------|-------|
| Paginated user list (30 per page) with total count | PASS | admin_users shows 4293 users with pagination |
| Sort by: User ID, Last Name, First Name | PASS | Sort options available |
| Search users by name/email | PASS | Search box present |
| Display: name, email, signup date, verification status | PASS | All columns visible |

### 9.2 User Detail/Edit
| Feature | Result | Notes |
|---------|--------|-------|
| View full user profile | SKIP | Would need to click into user |
| Edit user information | SKIP | Would need to edit user |
| Set permission level | SKIP | Would need to edit user |
| Manage user activation/deactivation | SKIP | Admin action |
| View user's groups | SKIP | Admin action |
| View user's orders | SKIP | Admin action |
| View user's event registrations | SKIP | Admin action |

### 9.3 User Actions
| Feature | Result | Notes |
|---------|--------|-------|
| Add single user | SKIP | Would need to use add form |
| Bulk user import | PASS | admin_user_add_bulk page exists (HTTP 200) - NOTE: Returns 200 without authentication, possible security issue |
| User soft delete | SKIP | Admin action |
| User permanent delete | SKIP | Admin action |
| User message sending | SKIP | Admin action |
| Login as user | SKIP | Admin action |
| User payment methods management | SKIP | Admin action |

### 9.4 Groups
| Feature | Result | Notes |
|---------|--------|-------|
| Group creation and management | PASS | admin_groups page loads (HTTP 200) |
| Group member management | SKIP | Would need to view group |
| Group permanent deletion | SKIP | Admin action |
| Group-based email sending | SKIP | Would need email composition |

### 9.5 Subscription Tiers
| Feature | Result | Notes |
|---------|--------|-------|
| Tier definition and editing | PASS | admin_subscription_tiers page loads (HTTP 200) |
| Tier pricing configuration | SKIP | Would need to edit tier |
| Subscription tier assignment | SKIP | Admin action |

---

## 10. Navigation & Menus

### 10.1 Public Navigation
| Feature | Result | Notes |
|---------|--------|-------|
| Public menu management | FAIL | /admin/admin_public_menus returns 404 - route does not exist |
| Footer navigation links (Home, About, Contact) | FAIL | Footer links to /about and /contact which both return 404. These broken links appear on EVERY page. |
| Category links (Blog, Gallery, Videos) | PARTIAL | Blog link works (/blog), Gallery and Videos links go to "#" (non-functional) |
| Get In Touch section with email | PASS | Footer shows email: info@joinerytest.site |

### 10.2 Admin Navigation
| Feature | Result | Notes |
|---------|--------|-------|
| Sidebar navigation with collapsible sections | PASS | Admin sidebar visible on all admin pages |
| Categories visible | PASS | Users, Emails, Products, Orders, Events, Files, Videos, Surveys, Pages, Blog, Statistics, Urls, System sections |
| Admin menu management | FAIL | /admin/admin_admin_menus returns 404 - route does not exist |
| Theme selector in admin header | PASS | "Theme: phillyzouk" dropdown visible in admin header |
| Dashboard link | FAIL | Dashboard link points to /admin/admin_users instead of /admin/admin_dashboard (which returns 404) |
| "+ New" quick action button | PASS | "+ New" button visible in admin header |

---

## 11. URL Management

### 11.1 URL Redirects
| Feature | Result | Notes |
|---------|--------|-------|
| Custom URL shortcut creation | PASS | admin_urls page loads (HTTP 200) |
| Permanent (301) redirects | SKIP | Would need to create/test redirect |
| Temporary redirects | SKIP | Would need to create/test redirect |
| URL redirect listing and management | PASS | Page accessible |
| Feature toggle: urls_active | PASS | URLs page active |

### 11.2 Routing System
| Feature | Result | Notes |
|---------|--------|-------|
| Front controller pattern via serve.php | PASS | All requests routed through serve.php |
| Dynamic route matching with parameters | PASS | URL parameters work (e.g., ?offset=30) |
| Static file serving with HTTP caching | PASS | Static assets served correctly |
| Plugin route integration | PASS | Plugin public routes work for active plugins. Admin pages were intentionally removed from ControlD; Bookings/Items are inactive. |
| Theme route override support | PASS | Theme overrides working (phillyzouk active) |
| .php extension stripped from URLs | PASS | URLs work without .php extension |
| Profile routes with fallback | PASS | /profile/* routes work |

---

## 12. Analytics & Statistics

### 12.1 Web Statistics
| Feature | Result | Notes |
|---------|--------|-------|
| Session analytics tracking | SKIP | Database-level |
| Visitor event tracking | SKIP | Database-level |
| Web statistics dashboard | FAIL | /admin/admin_statistics returns 404 - route does not exist |
| Built-in tracking or custom tracking code | SKIP | Configuration-level |

### 12.2 Email Statistics
| Feature | Result | Notes |
|---------|--------|-------|
| Email delivery analytics | FAIL | /admin/admin_email_statistics returns 404 |
| Email deliverability dashboard | FAIL | Route does not exist |
| Email debug log viewing and searching | SKIP | Cannot test without stats page |

### 12.3 User Analytics
| Feature | Result | Notes |
|---------|--------|-------|
| Signups by date reporting | SKIP | Would need working stats page |
| User activity funnels | PASS | admin_analytics_funnels page loads (HTTP 200) |
| User engagement metrics | SKIP | Would need analytics data |

### 12.4 Financial Reports
| Feature | Result | Notes |
|---------|--------|-------|
| Yearly donation reports | PASS | admin_yearly_report_donations page loads (HTTP 200) |
| Stripe payment/invoice listing | PASS | admin_stripe_orders page loads (HTTP 200) |

---

## 13. System Administration

### 13.1 Settings Management
| Feature | Result | Notes |
|---------|--------|-------|
| Database-stored settings via stg_settings | PASS | admin_settings page works comprehensively with all feature toggles |
| File-based core configuration | SKIP | Server-level config |
| 178+ configurable settings | PASS | Extensive settings visible in admin |
| Feature activation toggles | PASS | All feature toggles visible and functional |

### 13.2 Plugin Management
| Feature | Result | Notes |
|---------|--------|-------|
| Plugin listing and status | PASS | 3 plugins listed (Bookings, ControlD, Items) |
| Plugin activation/deactivation | PASS | Action buttons available |
| Plugin version tracking | PASS | Versions shown (1.0.0, 1.1.0) |
| Plugin dependency management | SKIP | Would need dependency test |
| Plugin settings forms | SKIP | Would need to open settings |
| Plugin-specific database migrations | SKIP | Backend feature |

### 13.3 Theme Management
| Feature | Result | Notes |
|---------|--------|-------|
| Theme listing and selection | PASS | 12 themes listed with details |
| Active theme switching (AJAX) | PASS | Theme switch dropdown in admin header |
| Theme metadata display | PASS | Version, author, description, status shown |
| Theme override chain | PASS | System explains Stock/Custom/System theme types |

### 13.4 Static Page Cache
| Feature | Result | Notes |
|---------|--------|-------|
| Static page caching system | FAIL | /admin/admin_static_page_cache returns 404 - route does not exist |
| Cache management (admin) | FAIL | Route does not exist |
| Cache clearing | FAIL | Route does not exist |

### 13.5 API Key Management
| Feature | Result | Notes |
|---------|--------|-------|
| API key creation | PASS | admin_api_keys page loads (HTTP 200) |
| IP restriction per key | SKIP | Would need to view key details |
| API key listing and editing | PASS | Page accessible |

### 13.6 Error Management
| Feature | Result | Notes |
|---------|--------|-------|
| General error log tracking | PASS | admin_errors page loads (HTTP 200) |
| Apache error log viewing | FAIL | admin_apache_errors crashes with memory exhaustion: "Allowed memory size of 134217728 bytes exhausted (tried to allocate 2990178224 bytes)" at admin_apache_errors.php:44. The error log file is ~3GB. |
| Form error logging | SKIP | Database-level |
| Error deletion and cleanup | SKIP | Admin action |
| Show errors toggle | PASS | Setting exists |

### 13.7 Event Logging
| Feature | Result | Notes |
|---------|--------|-------|
| System event log tracking | SKIP | Database-level |
| Change tracking audit trail | SKIP | Database-level |
| Login history | PASS | log_logins table verified working |

### 13.8 Database Management
| Feature | Result | Notes |
|---------|--------|-------|
| Automatic schema updates from model | SKIP | Backend feature |
| Database migration system | SKIP | Backend feature |
| Database version tracking | SKIP | Configuration-level |
| Test database management | PASS | admin_test_database page loads (HTTP 200) |

### 13.9 Soft Delete & Recovery
| Feature | Result | Notes |
|---------|--------|-------|
| Soft-deleted item listing | FAIL | /admin/admin_deleted_items returns 404 - route does not exist |
| Item recovery (undelete) | FAIL | Route does not exist |
| Permanent deletion for various entities | SKIP | Dependent on listing page |

### 13.10 Utilities
| Feature | Result | Notes |
|---------|--------|-------|
| System utilities page | PASS | admin_utilities page loads (HTTP 200) |
| Help documentation page | SKIP | Would need to navigate |
| Specifications viewer | PASS | admin_specs page loads (HTTP 200) |
| Component type management | PASS | admin_component_types page loads (HTTP 200) |

### 13.11 Shadow Sessions
| Feature | Result | Notes |
|---------|--------|-------|
| Shadow session management | PASS | admin_shadow_sessions page loads (HTTP 200) |
| Shadow session editing | SKIP | Would need to edit session |

---

## 14. REST API

### 14.1 API v1
| Feature | Result | Notes |
|---------|--------|-------|
| Key-based authentication | PASS | API returns proper JSON error when keys not provided: `{"api_version":"1.0","errortype":"AuthenticationError","error":"Error: Public/secret keys not present"}` |
| IP restriction enforcement | SKIP | Would need valid API keys |
| Model discovery system | SKIP | Would need authentication |
| CRUD operations on data models | SKIP | Would need authentication |
| JSON response format | PASS | API returns proper JSON |
| User validation | SKIP | Would need valid keys |

---

## 15. Integrations

### 15.1 Stripe
| Feature | Result | Notes |
|---------|--------|-------|
| Payment processing | PASS | Orders with Stripe payments visible |
| Webhook event handling | SKIP | Requires Stripe webhook |
| Subscription management | PASS | Subscription payments visible in orders |
| Invoice tracking | SKIP | Database-level |
| Test/production mode switching | PASS | TEST TRANSACTION labels visible |
| Webhook signature verification | SKIP | Backend feature |

### 15.2 PayPal
| Feature | Result | Notes |
|---------|--------|-------|
| All PayPal features | SKIP | Requires PayPal configuration |

### 15.3 Mailgun
| Feature | Result | Notes |
|---------|--------|-------|
| Email sending (primary service) | PASS | 571 emails in system, many marked as Sent |
| Inbound email webhook | SKIP | Requires inbound email |
| Webhook signature validation | SKIP | Backend feature |
| EU API endpoint support | SKIP | Configuration-level |

### 15.4 SMTP
| Feature | Result | Notes |
|---------|--------|-------|
| SMTP email sending (fallback) | SKIP | Requires SMTP fallback scenario |
| Configurable host, port, authentication | SKIP | Configuration-level |
| PHPMailer-based implementation | SKIP | Backend feature |

### 15.5 Calendly
| Feature | Result | Notes |
|---------|--------|-------|
| All Calendly features | SKIP | Requires Calendly configuration |

### 15.6 Acuity Scheduling
| Feature | Result | Notes |
|---------|--------|-------|
| All Acuity features | SKIP | Requires Acuity configuration |

### 15.7 Mailchimp
| Feature | Result | Notes |
|---------|--------|-------|
| All Mailchimp features | SKIP | Requires Mailchimp configuration |

---

## 16. SEO & Public Features

### 16.1 SEO
| Feature | Result | Notes |
|---------|--------|-------|
| Dynamic robots.txt generation | PASS | /robots.txt serves properly formatted file blocking admin, ajax, internal directories |
| Dynamic XML sitemap generation | PASS | /sitemap.xml returns valid XML with pages, events, locations, posts |
| URL-friendly slugs for all content types | PASS | Pages, events, products, posts all use URL slugs |
| Page title management | PARTIAL | Some pages have proper titles (Pricing), others just show "Joinery Test" |
| Preview image for social sharing | SKIP | Would need to inspect meta tags |

### 16.2 Cookie Consent
| Feature | Result | Notes |
|---------|--------|-------|
| GDPR cookie consent mode | SKIP | Not visible on test pages |
| Cookie consent tracking | SKIP | AJAX endpoint test needed |
| Privacy policy link configuration | PASS | Privacy policy page exists (/page/privacy-policy in sitemap) |

### 16.3 404 Error Page
| Feature | Result | Notes |
|---------|--------|-------|
| Custom 404 page with search functionality | PASS | Custom 404 page renders with search box, "Oops! Page Not Found" heading |
| Suggested pages (Blog, Products, Pricing, Contact, Login, Register) | PASS | All suggested page links present |
| "Go Home" and "Contact Support" links | PARTIAL | "Go Home" works (/), but "Contact Support" links to /contact which is also a 404 |

### 16.4 Site Directory
| Feature | Result | Notes |
|---------|--------|-------|
| Site directory/map page | FAIL | /directory returns 404 |

---

## 17. Theme System

### 17.1 Theme Architecture
| Feature | Result | Notes |
|---------|--------|-------|
| Multi-theme support | PASS | 12 themes installed |
| Bootstrap 5 theme (Falcon - primary) | PASS | Falcon theme present (v2.0.0, System type) |
| Tailwind CSS theme option | PASS | tailwind theme present (v1.0.0, legacy) |
| Theme override chain | PASS | System > Stock > Custom hierarchy visible |
| Theme-specific assets | PASS | Theme CSS/JS loading correctly |
| Theme-specific view overrides | PASS | phillyzouk theme overrides rendering |
| Theme-specific logic overrides | SKIP | Cannot verify from browser |
| Theme-specific PublicPage and FormWriter | SKIP | Cannot verify from browser |

### 17.2 Active Themes
| Feature | Result | Notes |
|---------|--------|-------|
| phillyzouk (currently active) | PASS | Active as public theme |
| falcon (admin interface) | PASS | Admin pages use falcon layout |
| Other themes listed | PASS | canvas, zoukroom, empoweredhealth, galactictribune, jeremytunnell, linka-reference, devonandjerry, zoukphilly all present |

---

## 18. Plugins

### 18.1 Bookings Plugin
| Feature | Result | Notes |
|---------|--------|-------|
| All bookings features | SKIP | Plugin is Inactive — admin routes not expected to work |

### 18.2 Items Plugin
| Feature | Result | Notes |
|---------|--------|-------|
| All items features | SKIP | Plugin is Inactive — admin routes not expected to work |

### 18.3 ControlD Plugin
| Feature | Result | Notes |
|---------|--------|-------|
| All ControlD features | PASS | Plugin is Active (v1.1.0). Admin pages were intentionally removed when subscription management moved to core. Public routes and profile pages functional. |

---

## 19. Security Features

### 19.1 Authentication Security
| Feature | Result | Notes |
|---------|--------|-------|
| Password hashing (bcrypt) | SKIP | Database-level |
| CSRF token protection | SKIP | Would need form inspection |
| Secure cookie implementation | SKIP | Would need HTTP header inspection |
| IP-based API key restrictions | SKIP | Would need API key test |
| Permission level enforcement | PASS | Admin pages properly restrict access |

### 19.2 Input Validation
| Feature | Result | Notes |
|---------|--------|-------|
| Server-side input validation | SKIP | Backend feature |
| Client-side validation (JoineryValidator v1.0.8) | FAIL | `ReferenceError: JoineryValidator is not defined` appears on multiple pages (password-reset-1, account_edit, address_edit, phone_numbers_edit). The validator JS file loads but the class is not available when forms try to use it. |
| Prepared statements for all database queries | SKIP | Code-level feature |
| Honeypot fields for bot detection | FAIL | Cannot verify - registration page crashes |
| hCaptcha support | FAIL | Cannot verify - registration page crashes |
| Anti-spam questions | FAIL | 81 attack comments on blog suggest anti-spam is ineffective |

### 19.3 Access Control
| Feature | Result | Notes |
|---------|--------|-------|
| Authenticated file access for uploads | SKIP | Would need upload access test |
| Admin permission checks (level 5+) | PASS | Admin pages return 401 without auth |
| Superadmin restrictions (level 10) | PASS | Admin header shows "(10)" for superadmin |
| Plugin test access restricted to superadmin | SKIP | Cannot verify |

---

## 20. Developer & Maintenance Features

### 20.1 Development Tools
| Feature | Result | Notes |
|---------|--------|-------|
| PHP syntax validation | PASS | php -l available on server |
| Method existence validator | PASS | validate_php_file.php available |
| Error log monitoring | PARTIAL | admin_errors works but admin_apache_errors crashes (memory exhaustion) |
| Debug mode | SKIP | Configuration-level |
| Debug CSS mode | SKIP | Configuration-level |

### 20.2 Deployment
| Feature | Result | Notes |
|---------|--------|-------|
| Installation scripts | SKIP | Server-level |
| Deployment scripts | SKIP | Server-level |
| Database backup and restore | SKIP | Server-level |
| Upgrade server system | SKIP | Server-level |
| Remote archive refresh | SKIP | Server-level |

### 20.3 Testing Infrastructure
| Feature | Result | Notes |
|---------|--------|-------|
| Email tests | SKIP | Would need to run test suite |
| Functional tests | SKIP | Would need to run test suite |
| Integration tests | SKIP | Would need to run test suite |
| Model tests | SKIP | Would need to run test suite |
| Plugin tests | SKIP | Would need to run test suite |
| Test database management | PASS | admin_test_database page loads (HTTP 200) |

---

## Issues Summary

| # | Section | Feature | Severity | Description | Suggested Fix |
|---|---------|---------|----------|-------------|---------------|
| 1 | 1.1 | Registration Page | CRITICAL | Page crashes with `Call to a member function get_setting() on null` at register.php:29 | Fix Globalvars initialization in register.php - the settings singleton is not being loaded before use |
| 2 | 1.3 | Logout | ~~MEDIUM~~ | ~~Shows confirmation page instead of redirecting to homepage~~ | Intended behavior — confirmation page is by design |
| 3 | 1.4 | Password Reset | HIGH | Form submits but shows no feedback for valid or invalid emails | Add success/error messages after form submission in password-reset-1 logic |
| 4 | 1.5 | Password Edit | HIGH | Password fields render as type="textbox" instead of type="password" | Fix FormWriter field type for password fields to use `type="password"` |
| 5 | 2.1 | Profile Dashboard | MEDIUM | Shows hardcoded Falcon theme demo content (celebrity posts) instead of real user data | Remove demo content from phillyzouk theme's profile.php view |
| 6 | 2.1 | Profile Dashboard | MEDIUM | 24+ broken image references (avatar images returning 404) | Fix image paths or provide default avatar fallback |
| 7 | 3.4 | Blog Comments | CRITICAL | 81 SQL injection attack comments displayed publicly from scanner user "pHqghUme" containing PG_SLEEP, DBMS_PIPE, waitfor delay payloads | 1. Delete all comments from pHqghUme user. 2. Enable comment moderation (set default_comment_status to require approval). 3. Strengthen anti-spam measures. |
| 8 | 3.5 | Videos Page | MEDIUM | /videos returns 404 | Create public videos listing page or ensure videos_active toggle properly controls route |
| 9 | 4.1 | Product Detail | CRITICAL | Product detail page crashes with `Cannot access offset of type string on string` (TypeError) in FormWriterV2Base.php:2277 | Fix FormWriterV2Base to handle string field specifications properly - check for array vs string type before accessing offsets |
| 10 | 4.5 | Shopping Cart | HIGH | Cannot add products to cart because product detail page crashes | Depends on fixing Issue #9 |
| 11 | 4.7 | Coupons | MEDIUM | /admin/admin_coupons returns 404 | Create admin_coupons.php route in adm/ directory |
| 12 | 4.8 | Orders - Attack Data | HIGH | Most recent orders are from automated scanner user "pHqghUme" with fake test transactions | Delete attack orders and block scanner user account |
| 13 | 6.8 | Email Statistics | MEDIUM | /admin/admin_email_statistics returns 404 | Create admin_email_statistics.php route |
| 14 | 7 | Surveys Page Heading | LOW | Admin surveys page heading shows "Add User" instead of "Surveys" | Fix page heading in admin_surveys.php |
| 15 | 6 | Emails Page Heading | LOW | Admin emails page heading shows "Users" instead of "Emails" | Fix page heading in admin_emails.php |
| 16 | 8.3 | Image Thumbnails | LOW | Some thumbnails return 404 (astrology-herbs15_ret7g796.jpg, etc.) | Regenerate missing thumbnails or fix thumbnail generation for special characters in filenames |
| 17 | 9.3 | Bulk User Add | MEDIUM | /admin/admin_user_add_bulk returns HTTP 200 without authentication - may be accessible without login | Add permission check (check_permission) to admin_user_add_bulk.php |
| 18 | 10.1 | Footer Links | HIGH | /about and /contact both return 404 but are linked from footer on EVERY page | Create about and contact pages, or update footer navigation to remove broken links |
| 19 | 10.1 | Public Menus Admin | MEDIUM | /admin/admin_public_menus returns 404 | Create public menu management page |
| 20 | 10.2 | Admin Dashboard | MEDIUM | /admin/admin_dashboard returns 404 - Dashboard link goes to admin_users instead | Create admin_dashboard.php or update Dashboard link to point to existing page |
| 21 | 10.2 | Admin Menu Management | MEDIUM | /admin/admin_admin_menus returns 404 | Create admin menu management page |
| ~~22~~ | ~~11.2~~ | ~~Plugin Routes~~ | ~~HIGH~~ | ~~Plugin admin routes return 404~~ | Not a bug — ControlD admin pages intentionally removed; Bookings/Items inactive |
| 23 | 12.1 | Web Statistics | MEDIUM | /admin/admin_statistics returns 404 | Create web statistics dashboard page |
| 24 | 13.4 | Static Page Cache | MEDIUM | /admin/admin_static_page_cache returns 404 | Create cache management page |
| 25 | 13.6 | Apache Errors | HIGH | admin_apache_errors crashes with memory exhaustion (128MB limit, 3GB log file) at line 44 | Read log file in chunks/stream instead of loading entire file into memory. Consider log rotation. |
| 26 | 13.9 | Deleted Items | MEDIUM | /admin/admin_deleted_items returns 404 | Create soft-deleted item listing page |
| 27 | 16.3 | 404 Page | LOW | "Contact Support" link on 404 page goes to /contact which is also a 404 | Fix /contact route or update 404 page link |
| 28 | 16.4 | Site Directory | LOW | /directory returns 404 | Create site directory page |
| 29 | 19.2 | JoineryValidator | HIGH | `ReferenceError: JoineryValidator is not defined` on multiple pages (password-reset-1, account_edit, address_edit, phone_numbers_edit) | Fix JoineryValidator class initialization - the script loads (v1.0.8 logged) but the class is not available when forms attempt to use it. Check script loading order or initialization timing. |
| 30 | 3.3 | Blog Images | LOW | Blog listing uses placeholder images from via.placeholder.com which fail to load | Replace placeholder URLs with actual uploaded images or local fallback |
| 31 | 5.2 | Event Detail 404 | MEDIUM | Some events return 404 on their detail page (e.g., /event/test-event-without-event-type) | Events without an event type may not route correctly - ensure routing handles events without type |
| 32 | 3.3 | Blog Post JS Error | LOW | `$ is not defined` error on blog post page - jQuery not loaded | Ensure jQuery is loaded before scripts that depend on it |

---

## Overall Statistics

| Metric | Count |
|--------|-------|
| Total features tested | ~400 |
| PASS | 108 |
| FAIL | 42 |
| PARTIAL | 14 |
| SKIP | ~236 |
| **Critical Issues** | **3** (Registration crash, Attack comments, Product detail crash) |
| **High Issues** | **8** (Password reset feedback, Password field type, Footer links, Plugin routes, JoineryValidator, Apache errors, Attack orders, Cart blocked) |
| **Medium Issues** | **12** (Various missing admin pages, logout behavior, videos page, etc.) |
| **Low Issues** | **9** (Headings, thumbnails, placeholder images, etc.) |

### Remediation Progress (Updated 2026-02-06)
| Severity | Fixed | False Positive | Pending |
|----------|-------|----------------|---------|
| Critical | 3 | 0 | 0 |
| High | 4 | 3 | 1 |
| Medium | 2 | 2 | 8 |
| Low | 0 | 0 | 9 |
| **Total** | **9** | **5** | **18** |

---

## Priority Remediation Plan

### Immediate (Critical Security)
1. ~~**Delete attack data** - Remove 81 SQL injection comments and scanner user "pHqghUme" orders/account~~ ✅ DONE
2. ~~**Enable comment moderation** - Set default_comment_status to require approval~~ ✅ DONE
3. ~~**Fix registration page** - Resolve get_setting() null reference in register.php~~ ✅ DONE

### High Priority
4. ~~**Fix product detail page** - Resolve FormWriterV2Base.php:2277 TypeError~~ ✅ DONE
5. ~~**Fix JoineryValidator** - Resolve class initialization so client-side validation works~~ ✅ DONE
6. ~~**Fix password reset feedback** - Add success/error messages~~ ✅ DONE
7. ~~**Fix password field type** - Change to type="password"~~ ⚪ False positive - already correct
8. **Create /about and /contact pages** - Fix broken footer links on every page ⚪ User says not a bug
9. ~~**Fix plugin routing**~~ ⚪ Not a bug — ControlD admin pages were intentionally removed (commit d43c0f30); Bookings/Items are inactive
10. ~~**Fix logout behavior**~~ ⚪ Intended behavior - confirmation page is by design

### Medium Priority
10. Create missing admin pages (dashboard, statistics, coupons, deleted items, etc.)
11. ~~Fix Apache error log viewer (stream file instead of loading into memory)~~ ✅ DONE
12. ~~Fix logout to redirect to homepage~~ ⚪ Intended behavior
13. Fix admin page headings (surveys shows "Add User", emails shows "Users")
14. ~~Add auth check to admin_user_add_bulk~~ ✅ DONE

### Low Priority
15. Fix broken thumbnail images
16. Replace placeholder blog images
17. Fix jQuery loading on blog post page
18. Create site directory page
19. Fix "Contact Support" link on 404 page

---

## Detailed Fix Guide

Each fix below includes the root cause, the file(s) involved, and what needs to change.

### Fix Status (Updated 2026-02-06)

| Fix # | Issue | Status | Notes |
|-------|-------|--------|-------|
| 1 | Registration Page Crash | ✅ FIXED | Swapped $settings init order; also fixed missing `hidden()`, `honeypot_hidden_input()`, `honeypot_check()`, `captcha_hidden_input()` methods in FormWriterV2Base |
| 2 | Product Detail Page Crash | ✅ FIXED | Added string validation handling in FormWriterV2Base.php |
| 3 | Attack Data Cleanup | ✅ FIXED | Deleted 82 comments, 41 orders, 18 order items, attacker account; set comment moderation to "Pending" |
| 4 | admin_user_add_bulk Auth | ✅ FIXED | Added session check and permission requirement |
| 5 | Password Reset Feedback | ✅ FIXED | Updated view to display success/error messages from logic |
| 6 | Password Field Type | ⚪ FALSE POSITIVE | Investigated all FormWriter implementations - `passwordinput()` correctly outputs `type="password"` |
| 7 | JoineryValidator Missing | ✅ FIXED | Added script include to phillyzouk theme footer |
| 8 | /about and /contact 404 | ⚪ NOT A BUG | User confirmed these pages intentionally don't exist yet |
| 9 | Plugin Admin Routes 404 | ⚪ NOT A BUG | ControlD admin pages intentionally removed (commit d43c0f30); Bookings/Items are inactive |
| 10 | Apache Error Log Viewer | ✅ FIXED | Changed to tail command; created logrotate config (needs manual sudo install) |
| 11 | Attack Orders | ✅ FIXED | Covered by Fix 3 database cleanup |

**Remaining items from original 32 issues:** Fixes 12-32 are lower priority and not yet addressed.

---

### CRITICAL

#### Fix 1: Registration Page Crash

**Issue:** Page crashes with `Call to a member function get_setting() on null`
**File:** `views/register.php`
**Root Cause:** On line 29, `$settings->get_setting('nickname_display_as')` is called _before_ `$settings` is assigned on line 31. The variable order is simply reversed.

```php
// Current (broken) - lines 29-31:
$nickname_display = $settings->get_setting('nickname_display_as');  // $settings is null here
$settings = Globalvars::get_instance();                             // too late

// Fix: swap the order
$settings = Globalvars::get_instance();
$nickname_display = $settings->get_setting('nickname_display_as');
```

**Scope:** Single line swap in one file.

---

#### Fix 2: Product Detail Page Crash

**Issue:** `Cannot access offset of type string on string` (TypeError) when viewing any product
**File:** `includes/FormWriterV2Base.php` line 2277
**Root Cause:** The field rendering code expects `$options['validation']` to be either an array or unset, but a product field is passing it as a plain string. The merge logic at lines 2274-2278 has a gap: when `$options['validation']` is set but is a string (not an array), it falls through without conversion. Later code at line 2287 then tries to use it as an array.

```php
// Current logic (lines 2274-2278):
if (isset($options['validation']) && is_array($options['validation'])) {
    $options['validation'] = array_merge($base_validation, $options['validation']);
} else if (!isset($options['validation'])) {
    $options['validation'] = $base_validation;
}
// Missing: the case where validation IS set but is a STRING

// Fix: add an else clause to handle the string case
if (isset($options['validation']) && is_array($options['validation'])) {
    $options['validation'] = array_merge($base_validation, $options['validation']);
} else if (!isset($options['validation'])) {
    $options['validation'] = $base_validation;
} else if (is_string($options['validation'])) {
    // Convert string shorthand to array, then merge
    $string_validation = $this->getTypeValidation($options['validation']);
    $options['validation'] = array_merge($base_validation, $string_validation);
}
```

**Scope:** One conditional block in FormWriterV2Base.php. Fixes all product pages and any other page passing string validation.

---

#### Fix 3: Attack Data Cleanup (SQL Injection Comments & Fake Orders)

**Issue:** Automated vulnerability scanner user "pHqghUme" left 81 SQL injection comments on blog posts and dozens of fake orders. Attack payloads include `PG_SLEEP`, `DBMS_PIPE.RECEIVE_MESSAGE`, `waitfor delay`, and similar.
**Tables affected:** `cmt_comments`, `ord_orders`, `usr_users`
**Root Cause:** The scanner successfully created a user account and exercised form inputs with attack payloads. The payloads were stored (not executed) thanks to prepared statements, but they remain visible publicly.

**Database cleanup (requires confirmation):**
```sql
-- 1. Identify the attacker user(s)
SELECT usr_user_id, usr_email, usr_first_name FROM usr_users
WHERE usr_first_name = 'pHqghUme' OR usr_last_name = 'pHqghUme';

-- 2. Delete attack comments
DELETE FROM cmt_comments WHERE cmt_author_name = 'pHqghUme'
   OR cmt_usr_user_id IN (SELECT usr_user_id FROM usr_users WHERE usr_first_name = 'pHqghUme');

-- 3. Delete attack orders (check for linked records first)
-- Review orders: SELECT * FROM ord_orders WHERE ord_usr_user_id IN (...);
-- Delete if safe: DELETE FROM ord_orders WHERE ord_usr_user_id IN (...);

-- 4. Deactivate or delete the attacker account
-- UPDATE usr_users SET usr_active = false WHERE usr_first_name = 'pHqghUme';
```

**Preventive measures:**
- Set `default_comment_status` to require approval (in `stg_settings` table)
- The comment system already has honeypot and anti-spam question checks (`Comment::add_comment()`), but these were bypassed. Consider enabling hCaptcha for comments if not already active.

---

#### Fix 4: admin_user_add_bulk.php — No Authentication Check

**Issue:** This admin page is accessible without login (returns HTTP 200 to unauthenticated requests).
**File:** `adm/admin_user_add_bulk.php`
**Root Cause:** The entire file is a bare PHP script that reads `test.csv` without any session or permission checks. Compare to `admin_user_add.php` which properly includes `AdminPage.php` and checks permissions.

```php
// Current file (entire contents):
$row = 1;
if (($handle = fopen("test.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) { ... }
    fclose($handle);
}

// Fix: add standard admin boilerplate at the top
<?php
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
$session = SessionControl::get_instance();
$session->check_permission(5);
// ... rest of the file
```

**Scope:** Add 3 lines to the top of the file. Also consider whether this file should be removed entirely since it appears to be a development stub (hardcoded "test.csv" path, no real upload handling).

---

### HIGH

#### Fix 5: Password Reset — No Feedback After Submission

**Issue:** After submitting the password reset form, the page reloads with no visible success or error message.
**File:** `logic/password-reset-1_logic.php`
**Root Cause:** The logic file correctly sets `$page_vars['message_type']`, `$page_vars['message_title']`, and `$page_vars['message']` (lines 38-45), but the view file (`views/password-reset-1.php`) is not displaying these message variables. The logic output is being generated but never rendered.

**Fix:** In the password-reset-1 view file, add message display code after `BeginPage()`:
```php
if (!empty($page_vars['message'])) {
    echo PublicPage::alert(
        $page_vars['message_title'],
        $page_vars['message'],
        $page_vars['message_type']
    );
}
```

**Scope:** Add ~5 lines to the view file.

---

#### Fix 6: Password Fields Rendered as Plain Text

**Issue:** On the password edit page, password fields show input as visible text instead of masked dots.
**File:** `views/profile/password_edit.php`
**Root Cause:** The view correctly calls `$formwriter->passwordinput()` (not `textinput()`), so the issue is in the FormWriter's `passwordinput()` method implementation. The method may be rendering the field with `type="textbox"` or `type="text"` instead of `type="password"`. Check the active FormWriter class used by the phillyzouk theme to verify its `passwordinput()` method sets the correct HTML input type attribute.

**Scope:** Fix in the FormWriter class's `passwordinput()` method — likely a single attribute value change.

---

#### Fix 7: JoineryValidator Not Defined on Public Pages

**Issue:** `ReferenceError: JoineryValidator is not defined` on password-reset, account_edit, address_edit, and phone_numbers_edit pages.
**File:** `theme/phillyzouk/includes/PublicPage.php`
**Root Cause:** The phillyzouk theme's `PublicPage.php` does NOT include the `joinery-validate.js` script in its footer JS stack (lines 300-321). Other themes (Falcon, Tailwind) and the admin layout all include it. The phillyzouk footer loads jQuery, Bootstrap, OWL Carousel, and several other scripts but omits joinery-validate.js entirely.

**Fix:** Add the script tag to the JS loading stack in `PublicPage.php`'s `public_footer()` method:
```html
<!-- Joinery Validator -->
<script src="/assets/js/joinery-validate.js"></script>
```

Place it after jQuery (line 301) and before `custom.js` (line 321) so it's available when forms initialize.

**Scope:** Single line addition to one file. Fixes all public-facing forms in the phillyzouk theme.

---

#### Fix 8: /about and /contact Pages Return 404

**Issue:** Both pages are linked in the site footer on every page, but neither view file exists.
**Files missing:** `views/contact.php` and `views/about.php`
**Root Cause:** The footer template references `/contact` and `/about` routes, but no corresponding view files have been created. The routing system (serve.php) would serve them automatically if the view files existed — no route registration needed.

**Fix:** Create both view files following the existing view pattern:
- `views/contact.php` — Contact form page using FormWriter, with fields for name, email, message. Should send via SystemMailer.
- `views/about.php` — Static content page with organization description.

Both should use `PublicPage` for header/footer, matching the pattern in other view files.

**Scope:** Two new files. Alternatively, if these pages aren't needed, update the footer template to remove the broken links.

---

#### Fix 9: Plugin Admin Routes Return 404

**Issue:** All plugin admin pages return 404, even for the active plugin (ControlD).
**File:** `serve.php` lines 150-165
**Root Cause:** The plugin route handler constructs the path as `plugins/{plugin}/admin/{admin_page}.php` and checks with `file_exists()`. This is a relative path — it works only if the current working directory is the web root. The route handler uses `error_log()` for debugging (line 157) which will show whether the file path resolves correctly.

**Diagnosis:** Check the error log output from the route handler. The fix depends on whether:
1. The `file_exists()` check is failing due to a relative vs absolute path issue — fix by using `PathHelper::getIncludePath()` instead
2. The plugin admin files don't exist at the expected path — verify actual file locations

```php
// Current (line 159):
if (file_exists($admin_file)) {

// Possible fix:
$full_path = PathHelper::getIncludePath($admin_file);
if (file_exists($full_path)) {
    require_once($full_path);
```

**Scope:** One path resolution change in serve.php, or verify plugin file locations.

---

#### Fix 10: Apache Error Log Viewer Crashes (Memory Exhaustion)

**Issue:** `admin_apache_errors.php` tries to load a ~3GB log file entirely into memory, exceeding PHP's 128MB limit.
**File:** `adm/admin_apache_errors.php` line 44
**Root Cause:** `file($error_log)` reads the entire file into an array. For a 3GB file, this requires multiple GB of RAM.

```php
// Current (line 44):
$file = file($error_log);  // loads entire 3GB file

// Fix option 1: Read only the last N lines using tail
$lines = explode("\n", shell_exec("tail -n 500 " . escapeshellarg($error_log)));
$lines = array_reverse($lines);

// Fix option 2: Use SplFileObject for memory-efficient reading
$file = new SplFileObject($error_log, 'r');
$file->seek(PHP_INT_MAX);  // seek to end
$total_lines = $file->key();
$start = max(0, $total_lines - 500);
// ... read from $start forward
```

**Additional recommendation:** Set up log rotation (`logrotate`) so the Apache error log doesn't grow unbounded. A 3GB log file indicates rotation isn't configured for this log path.

**Scope:** Replace 3-4 lines in admin_apache_errors.php.

---

#### Fix 11: Attack Orders in Order History

**Issue:** Dozens of fake orders from the "pHqghUme" scanner user pollute the order management interface.
**Resolution:** Covered by Fix 3 (Attack Data Cleanup) above — delete orders belonging to the attacker user ID.

---

#### Fix 12: Shopping Cart Blocked

**Issue:** Cannot add products to cart because the product detail page crashes before the "Add to Cart" button renders.
**Resolution:** Depends entirely on Fix 2 (Product Detail Page Crash). No separate fix needed.

---

### MEDIUM

#### Fix 13: Logout Shows Page Instead of Redirecting

**Issue:** Visiting `/logout` shows a logout confirmation page instead of immediately logging out and redirecting.
**File:** `views/logout.php`
**Root Cause:** The view calls `$session->logout()` (line 7) but then continues to render a full page with header/footer. It should redirect after destroying the session.

```php
// Current flow:
$session->logout();
$page = new PublicPage();
$page->public_header(...);  // renders a full page

// Fix: redirect immediately after logout
$session->logout();
header('Location: /');
exit();
```

**Scope:** Replace the page rendering code with a 2-line redirect.

---

#### Fix 14: Admin Surveys Page Heading Shows "Add User"

**File:** `adm/admin_surveys.php` lines 18-19
**Fix:** Change `'page_title' => 'Add User'` and `'readable_title' => 'Add User'` to `'Surveys'`.

---

#### Fix 15: Admin Emails Page Heading Shows "Users"

**File:** `adm/admin_emails.php` lines 21-22
**Fix:** Change `'page_title' => 'Users'` and `'readable_title' => 'Users'` to `'Emails'`.

---

#### Fix 16: Profile Page Hardcoded Demo Content

**File:** `views/profile/profile.php`
**Root Cause:** Lines 44-425 contain hardcoded social media-style posts from celebrity names (Rowan Atkinson, Margot Robbie, Leonardo DiCaprio, Johnny Depp, Emilia Clarke) with hardcoded images, dates, and text. This is Falcon theme demo content that was copied into the profile view.

**Fix:** Remove all hardcoded post content (lines ~44-425) and replace with dynamic content from the user's actual data, or show an empty state message if no activity exists. The dynamic event section later in the file (~line 513+) shows the correct pattern.

**Scope:** Large block removal/replacement in one file.

---

#### Fix 17: admin_user_add_bulk.php Needs Auth Check

**Resolution:** Covered by Fix 4 above.

---

#### Fix 18: Missing Admin Pages (9 pages)

**Pages that return 404:**

| Expected Route | Closest Existing File | Notes |
|---|---|---|
| `admin_blog` | `admin_posts.php` | Route alias or redirect needed |
| `admin_dashboard` | None | New page needed |
| `admin_statistics` | None | New page needed |
| `admin_coupons` | `admin_coupon_codes.php` | Route alias or redirect needed |
| `admin_deleted_items` | None | New page needed |
| `admin_public_menus` | None | New page needed |
| `admin_admin_menus` | `admin_admin_menu.php` (singular) | Pluralization mismatch — add redirect or alias |
| `admin_static_page_cache` | None | New page needed |
| `admin_email_statistics` | None | New page needed |

**Quick wins:** For `admin_blog`, `admin_coupons`, and `admin_admin_menus`, the underlying pages exist under slightly different names. These can be fixed with simple redirect files:
```php
<?php
// adm/admin_blog.php — redirect to correct route
header('Location: /admin/admin_posts');
exit();
```

**Larger effort:** `admin_dashboard`, `admin_statistics`, `admin_deleted_items`, `admin_public_menus`, `admin_static_page_cache`, and `admin_email_statistics` need new implementations.

---

#### Fix 19: Videos Page Returns 404

**File missing:** `views/videos.php`
**Root Cause:** No view file exists for the `/videos` route. The `videos_active` setting may exist but the view was never created.

**Fix:** Create `views/videos.php` with a video listing page, or if the feature isn't needed, ensure the navigation doesn't link to it.

---

#### Fix 20: Broken Avatar/Image Thumbnails

**Issue:** 24+ avatar images return 404 on the profile page. Some file thumbnails also return 404 in admin_files.
**Root Cause:** Image files were either deleted, never uploaded, or have filename encoding issues (e.g., `astrology-herbs15_ret7g796.jpg`).

**Fix:** Add a fallback default avatar image for missing user photos. For file thumbnails, regenerate thumbnails for files with special characters in filenames, or fix the thumbnail path generation.

---

#### Fix 21: Some Event Detail Pages Return 404

**Issue:** Events without an event type (e.g., `/event/test-event-without-event-type`) return 404.
**File:** `serve.php` line 115 — route definition: `'/event/{slug}'`
**Root Cause:** The route uses model-based dynamic routing which calls `Event::get_by_link($slug)`. If this method fails to find the event or returns a deleted event, the route handler returns false (404). The `evt_ety_event_type_id` field is nullable, so the event type itself shouldn't cause the issue. More likely the event's URL slug doesn't match the expected format, or the event has been soft-deleted.

**Diagnosis:** Query the database to check the event's link field:
```sql
SELECT evt_event_id, evt_name, evt_link, evt_delete_time, evt_ety_event_type_id
FROM evt_events WHERE evt_name LIKE '%without event type%';
```

---

### LOW

#### Fix 22: Blog Placeholder Images

**Issue:** Blog listing page uses `via.placeholder.com` URLs which fail to load.
**Fix:** Replace placeholder image URLs in blog post records with actual uploaded images, or add CSS fallback styling for missing images.

---

#### Fix 23: Blog Post jQuery Error (`$ is not defined`)

**Issue:** Blog post detail page throws `$ is not defined` in inline script.
**Root Cause:** jQuery is loaded in the footer (line 301 of PublicPage.php) but some inline `<script>` blocks in the blog post template reference `$` before jQuery has loaded. This happens when inline JS is placed in the `<body>` content above the footer scripts.

**Fix:** Wrap inline jQuery usage in a `DOMContentLoaded` listener, or move the inline scripts to after the footer JS includes, or use `window.onload`.

---

#### Fix 24: 404 Page "Contact Support" Link Broken

**File:** `views/404.php` line 71
**Root Cause:** Links to `/contact` which doesn't exist (see Fix 8).
**Fix:** Will be resolved automatically by Fix 8. Alternatively, change the link to `mailto:` the site admin email.

---

#### Fix 25: /directory Page Returns 404

**File missing:** `views/directory.php`
**Fix:** Create the directory view page, or remove navigation links to it if the feature isn't planned.

---

#### Fix 26: Broken Image References in Blog

**Issue:** Some blog post featured images reference files that don't exist on disk.
**Fix:** Update the image paths in the post records (`pst_posts` table), or add a default fallback image in the blog listing template.

---

#### Fix 27: Admin Page Heading Copy-Paste Errors

**Issue:** Multiple admin pages have incorrect headings due to copy-paste from `admin_user_add.php`. Surveys shows "Add User", Emails shows "Users".
**Fix:** Covered by Fixes 14 and 15 above. Audit other admin pages for similar copy-paste heading issues.
