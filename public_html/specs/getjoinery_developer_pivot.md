# Spec: getjoinery.com Developer Pivot

**Status:** Draft  
**Created:** 2026-05-04  
**Supersedes:** Partially — this replaces the SaaS/org-focus of `getjoinery_marketing_site.md`

---

## 1. Overview

This is a full strategic pivot for getjoinery.com. The product and audience are changing:

**Old framing:** Hosted SaaS membership software for clubs, nonprofits, and small orgs.  
**New framing:** PHP web application framework for developers who want a production-ready foundation to build on.

The hosted SaaS offering is being dropped entirely. There will be no hosted tiers, no free trial, no "Start Free Trial" CTA. The product is the framework itself — self-hosted, source-available, free for noncommercial use.

---

## 2. New Target Audience

**Primary:** Mid-to-lower tier developers (solo devs, hobbyists, freelancers, vibe coders) who:
- Can write PHP or work with AI to generate code
- Want to ship web apps quickly without rebuilding boilerplate
- Are tired of recreating auth, user management, payments, and email on every project
- Want something readable and modifiable — not a black-box framework
- Value working from a production-proven foundation

**What they're NOT:** Enterprise architects, framework specialists, pure frontend devs.

**The insight:** When you vibe-code a new app, the hard part isn't the features — it's the plumbing. Auth, password reset, session management, user roles, Stripe integration, email, admin dashboards — every app needs all of it and none of it is interesting to build. Joinery ships it all.

---

## 3. Positioning Statement

**Headline:** Skip the boilerplate. Build what matters.

**Supporting:** Joinery is a PHP web framework that ships with authentication, payments, email, a REST API, an admin dashboard, and a plugin system — already built, tested, and running in production. Stop rebuilding the same plumbing on every project and start on the interesting part.

**Alternate headlines to consider:**
- "All the boring stuff is already done."
- "Stop rebuilding auth. Start building your app."
- "The PHP foundation you'd build yourself — already built."

---

## 4. Pricing Model (Complete Replacement)

The old hosted SaaS pricing ($29/$59/$99/mo tiers) is being removed entirely.

### New Pricing

**Option 1: Free / Noncommercial**
- Free for personal, educational, and noncommercial use
- PolyForm Noncommercial license
- CTA: "Get Started" → links to install instructions / GitHub

**Option 2: White Glove Install — ~~$299~~ $99 one-time** (coupon INSTALL publicly listed)
- Customer provides: server IP + root SSH credentials + domain name
- We install and configure everything: Apache2, PHP 8.x, PostgreSQL, full Joinery site, Apache VHost, SSL
- Their server, their code, full control after install
- No recurring charges, no ongoing relationship unless they want it
- List price $299 shown struck through on pricing page; coupon "INSTALL" publicly shown bringing it to $99

**Option 3: Business License — "Email us"**
- For commercial self-hosting (running Joinery on their infrastructure for a commercial product)
- Email hello@getjoinery.com
- No public price listed — handled case by case

### Products to Create in the System
- Create a product in Joinery e-commerce: "White Glove Install"
  - Type: Item (one-time purchase)
  - Base price: $299
  - Post-purchase: customer fills out a form with their server IP, root credentials, domain name
  - Coupon: "INSTALL" → $200 off = $99 final
  - Fulfillment: manual by Jeremy (or future automated provisioning)
- Link the pricing page "Get Installed" CTA to the product purchase page

---

## 5. Navigation Changes

**Remove from primary nav:** Showcase (or deprioritize — move to footer only, or keep as secondary)  
**Remove from primary nav:** Philosophy (move to footer — it's secondary content for developers)  
**Keep:** Features, Pricing, Developers, About  
**Add:** "Install" or "Get Started" as a nav CTA button  

**Proposed nav:**
```
[Joinery logo]   Features  |  Pricing  |  Developers  |  Showcase  |  About    [Get Started →]
```

Or even leaner:
```
[Joinery logo]   What's Included  |  Pricing  |  Developers  |  About    [Get Started →]
```

The "Get Started →" button in the nav should be a filled amber button linking to the install/pricing page.

---

## 6. Page-by-Page Changes

### 6.1 Homepage (`/`)

**Complete rewrite.** Every section of the current homepage is aimed at org admins. All of it changes.

#### Hero Section
- **Headline:** "Skip the boilerplate. Build what matters."
- **Subheadline:** "Joinery is a PHP web framework that ships with auth, payments, email, a REST API, and an admin dashboard — already built. Start from working software, not a blank file."
- **Primary CTA:** "Get Started Free" → `/install` or GitHub
- **Secondary CTA:** "See What's Included" → `/features`
- **Tone:** Developer-to-developer. Direct, no fluff.

#### Trust / Social Proof Bar (below hero)
Replace the current "Source Available / 0% Transaction Fees / Self-Host Option / Full Data Export / Free for Personal Use" pills with developer-relevant signals:
- Production-Proven
- PostgreSQL + PHP 8
- Source Available
- Argon2id Passwords
- Zero Dependencies (frontend)
- Built-in REST API

#### "What You Don't Have to Build" Section
Reframe the features grid from "here's what the app does" to "here's what's already done for you":

| Already Built | Description |
|---|---|
| Auth & Sessions | Login, logout, password reset, email verification — done |
| User Management | Roles, permissions, subscription tiers, groups |
| Payments | Stripe + PayPal built in. Subscriptions, products, coupons |
| Email | Mailgun or SMTP. Newsletters, transactional, notifications |
| Admin Dashboard | Full management UI for every data model |
| REST API | 40+ endpoints with key auth, rate limiting, and CORS |
| Plugin System | Add features as isolated modules without touching core |
| Theme System | Override chain — customize any view without forking |

#### "How It Works" / Code Teaser
A short section with a code snippet or directory structure showing how easy it is to add a new page or model. Something like:

```
# Adding a new page takes one file:
views/my_feature.php   →   /my-feature works immediately

# Adding a data model:
data/widgets_class.php  →  table created automatically

# A plugin is self-contained:
plugins/my_plugin/
  plugin.json
  data/
  views/
  admin/
  logic/
```

Tagline: "No route config. No migrations for schema. No setup ritual."

#### Quality / Trust Section
This is where you make the quality argument. Claims to make:

1. **Running in production** — ScrollDaddy (DNS filtering service with real paying customers) runs entirely on Joinery. The web app, user accounts, billing, device management, and API are all Joinery. The framework isn't theoretical.

2. **Security is structural, not optional** — PDO prepared statements everywhere (no string concatenation paths), Argon2id password hashing (not bcrypt, not MD5), CSRF tokens built into every form via FormWriter, XSS protection via automatic output escaping, HttpOnly + SameSite cookies.

3. **Readable code** — No framework magic. No annotations, no dependency injection containers, no auto-wiring. You can read top-to-bottom and understand what it does.

4. **One coherent codebase** — Built and maintained by one developer. Zero "we inherited this from the team that left." Every file has a clear purpose and a clear owner.

#### Replaced: "Built for real organizations" section
Remove the clubs/nonprofits/communities audience grid entirely. Replace with something developer-focused or remove this section.

#### Replaced: Pricing Teaser
Remove the $29/$59/$99 pricing teaser. Replace with a simple CTA section:
- "Free to use. Optional white-glove install. No recurring fees."
- "Get Started Free" button + "See Pricing" link

#### Bottom CTA
- **Headline:** "Your next app, already half-built."
- **CTA:** "Get Started Free" → install/GitHub
- **Secondary:** "Get Installed for $299" → White Glove Install product

---

### 6.2 Features Page (`/features`) — Rename or Reframe

**Rename to:** "What's Included" (or keep "Features" — either works)

**Reframe entirely.** Every feature section currently describes what the *end user* of a membership org can do. Rewrite each one to describe what the *developer* gets.

Current framing: "Create events, manage signups, handle waitlists."  
New framing: "Event registration is already built. Routes, views, data models, admin UI — all there."

**New section order / framing:**

1. **Authentication & Users** — Login, logout, password reset, email verification, session management. Role-based permissions (1-10 scale). Custom user fields. All done.

2. **Data Model Layer** — Active Record pattern. Define a class, get a full CRUD interface. Automatic table creation and migration. Multi-object collection queries. No SQL boilerplate for standard operations.

3. **Payments** — Stripe and PayPal. Subscription billing, one-time products, coupons, webhooks. Zero configuration to get a checkout working if credentials are set.

4. **Email** — Mailgun or any SMTP server via PHPMailer. Template system. Transactional and newsletter sending. No third-party lock-in.

5. **Admin Dashboard** — Every data model gets a full admin interface. List views with search, detail views with edit forms, bulk operations, analytics. Built on the same FormWriter system devs use.

6. **REST API** — 40+ model endpoints. Key-based auth. Rate limiting, CORS, JSON in/out. Extend with custom endpoints in plugins.

7. **Plugin System** — Self-contained modules with their own data models, views, admin pages, routes, and scheduled tasks. Activate/deactivate without data loss. Multiple plugins ship with the framework.

8. **Theme System** — Override chain (theme → plugin → base). Choose Bootstrap, Tailwind, or zero-dependency HTML5. FormWriter automatically adapts to the active CSS framework.

9. **Scheduled Tasks** — Cron-like task system. Define tasks in PHP, schedule them, they run. Logging and error tracking built in.

10. **Security** — (Give this its own callout section, not just a bullet) — See developers page for full detail.

**Bottom CTA:** "Get Started Free" + "Get Installed for $299"

---

### 6.3 Pricing Page (`/pricing`)

**Complete replacement.** Remove all hosted SaaS tier content.

**New structure:**

#### Hero
- **Headline:** "Simple pricing. No recurring fees."
- **Subheadline:** "Use it free, or pay once to get set up. No subscriptions, no per-seat fees, no platform tax."

#### Three Options

**Free / Noncommercial**
- $0
- Self-install from GitHub
- Full source code
- Personal, educational, and noncommercial use (PolyForm Noncommercial license)
- Community support
- CTA: "Get Started" → install instructions

**White Glove Install**
- ~~$299~~ **$99** *(use code INSTALL at checkout)*
- You provide: a fresh Ubuntu 24.04 VPS with root SSH access + a domain name
- We install: Apache2, PHP 8.x, PostgreSQL, full Joinery site, VHost config, SSL via Let's Encrypt
- Your server, your code, your control — we just set it up
- One-time charge, no ongoing fees
- Typical completion: within 24 hours
- CTA: "Get Installed" → product purchase page

**Business License**
- Custom pricing
- For commercial products built on Joinery
- Includes rights to run on your own infrastructure for a commercial app
- Perpetual license
- CTA: "Email Us" → mailto:hello@getjoinery.com?subject=Business+License

#### FAQ Section (below pricing cards)
Answer the common questions upfront:
- "What counts as noncommercial?" — Personal projects, learning, open source hobby projects, nonprofits
- "What's in the white glove install?" — A working Joinery instance on your server, configured for production
- "What do I need to provide?" — A fresh VPS (Ubuntu 22+), root SSH access, and a domain pointed at it
- "Do you support me after install?" — Community support via GitHub. Paid support available on request.
- "Can I see the code before buying?" — Yes. It's all on GitHub.

---

### 6.4 Developers Page (`/developers`)

**This is now the primary sales page.** The developer audience is who we're selling to, and this page is where they'll land when evaluating whether Joinery is worth using. Make it excellent.

**Current state:** Good foundation but still feels like "here's our tech stack" rather than "here's why you want this."

**Reframe:** Lead with the developer value proposition, not the architecture diagram.

#### Hero
- **Headline:** "A framework you can actually read."
- **Subheadline:** "No magic, no annotations, no framework ceremony. PHP 8, PostgreSQL, and patterns that have run in production for years. Open it, read it, modify it."

#### "The Stack" section
Keep the architecture overview but lead each item with the developer benefit:
- **PostgreSQL** — Because your data deserves a real database. Full SQL, JSONB, prepared statements throughout. No ORM abstractions to fight with.
- **PHP 8.x, MVC-like** — Front-controller routing. Clean separation of data, logic, and views. No autoloading magic — you can see exactly what's loaded.
- **Zero-dependency frontend by default** — Modern vanilla JS. No jQuery, no bundler, no build step. Bootstrap and Tailwind available if you want them.
- **Active Record models** — Define a class, get a full CRUD interface. `new User($id, true)` loads the user. `$user->save()` writes it. No query builders, no SQL strings for standard ops.

#### Security section
Expand this significantly — security is a major quality signal for this audience.

Make the argument: "Most frameworks require you to remember to use security features. In Joinery, they're structural — you'd have to work to bypass them."

- **SQL injection:** PDO prepared statements everywhere. There are no concatenated query strings in the codebase. The model layer never touches raw input.
- **XSS:** All output through FormWriter is escaped automatically. View templates use `htmlspecialchars()`. You can't forget because the helpers do it for you.
- **CSRF:** FormWriter generates and validates CSRF tokens on every form. You get it for free on every form you build.
- **Passwords:** Argon2id. Not bcrypt (which the PHP default used to be), not MD5, not SHA-1. The current best practice, automatically. Old bcrypt hashes are upgraded on next login.
- **Cookies:** HttpOnly, SameSite=Lax, Secure. Session cookies are inaccessible to JavaScript by default.
- **File uploads:** Validated by type and size, stored outside web root, served through controlled handlers.

#### REST API section
Keep the curl examples. Add more. Show it's real and usable.

#### Plugin System section
Make this more concrete. Show a working example of a real plugin (reference the Bookings or ScrollDaddy plugin). Show what you get:
- Own namespace, own routes, own admin pages
- Activate/deactivate without data loss
- Schema changes picked up automatically on deploy

#### "This Runs in Production" section
New section. ScrollDaddy as the case study:
- Real web app, real paying users
- 11 data models, custom theme, subscription billing, scheduled tasks, DNS API
- All built as a Joinery plugin + theme — no core modifications
- Same framework you'd use. Same patterns.

#### Theme System section
Add a note specifically for developers: "If you're building a custom app, the theme system means you can completely replace the public UI without touching anything in core or plugins. Your design, your HTML, your CSS — all fully replaceable through the override chain."

#### Self-hosting section
Keep but update to align with new pricing:
- Requirements: PHP 8.x, PostgreSQL, Apache or Nginx
- DIY: Clone from GitHub, run the installer
- White Glove: Pay once, we handle it — link to pricing

---

### 6.5 Showcase Page (`/showcase`)

**Keep but reframe.** This page already has the right framing ("Joinery is an application framework") — it just needs updating.

**Rename section header** from "Built with Joinery" to something like "What you can build" or keep it.

**Add a new entry for getjoinery.com itself:**
- "This site runs on Joinery" — the marketing site for Joinery is itself a Joinery theme. Custom public pages, the admin dashboard, the e-commerce for White Glove Install — all running on the framework.

**Keep ScrollDaddy entry** as-is. It's a strong example.

**Add a "What could you build?" teaser:**
Briefly list app types that fit the Joinery model:
- SaaS web apps with user accounts and billing
- Community platforms
- Content sites with member-only sections
- Internal tools with admin interfaces
- Anything that needs auth + payments + email + an admin dashboard

---

### 6.6 Philosophy Page (`/philosophy`)

**Reframe from org-privacy focus to developer-autonomy focus.**

The current page talks about organizations trusting their members' data. That's still true but it's not the primary message for the new audience.

**New angle:** Developer autonomy and code ownership.

- You get the source. All of it. You can read it, understand it, modify it.
- No black boxes. No framework that does things you don't understand.
- No lock-in to a hosted service — you run this on your own server.
- No "trust us" about how the auth works. Read it.
- No VC pressure to add dark patterns, usage tracking, or "engagement features."

Keep the "Commitments" section but reorder/reframe:
1. Source available, always — you can audit every line
2. No lock-in — you own the install, you own the code
3. No VC, no growth pressure — product decisions based on what makes good software
4. Commercial licenses available — fair pricing for commercial use

**Business model section:** Update to reflect the new model (no SaaS subscriptions). Revenue from White Glove Installs, business licenses, and potentially support contracts.

---

### 6.7 About Page (`/about`)

**Minor updates only.**

- Remove references to "hosted membership platform" framing
- Lean into the "solo developer = coherent codebase" argument harder — this is a feature for the new audience
- Add a note that the framework has been in active development for X years and is currently running production applications
- Keep the contact section

---

## 7. New Install/Get Started Page (`/install`)

**New page** — does not exist yet. GitHub will link here, not the other way around.

This is where all "Get Started Free" CTAs land. It walks a developer through installing Joinery on their own server.

**Content:**
1. **Requirements** — Ubuntu 24.04 VPS, root SSH access, a domain name pointed at the server
2. **What gets installed** — The install script provisions: Apache2, PHP 8.x, PostgreSQL, Joinery site directory and database, Apache VHost configuration, SSL via Let's Encrypt (auto-provisioned once DNS is live)
3. **Install steps** — Numbered: download release tarball, run `install.sh -y -q server` for server setup, then `install.sh -y -q site SITENAME DOMAIN` to create the site
4. **"Want us to do this for you?"** — Prominent inline CTA: "Skip the setup — get White Glove Install for $99" → links to product purchase page

---

## 8. Copy/Messaging Principles

The voice needs to change for the new audience. Current site sounds like enterprise SaaS. The new audience wants developer-to-developer honesty.

**Do:**
- Be direct and concrete ("PDO prepared statements everywhere" not "enterprise security")
- Acknowledge tradeoffs ("This is PHP, not Go. It's a web framework, not a microservices toolkit.")
- Show real code and real directory structures
- Claim specific things you can prove ("Argon2id" not "industry-standard password security")
- Write like you'd explain it to a developer friend

**Don't:**
- Use enterprise SaaS language ("streamline your workflow," "empower your team")
- Make claims you can't back up with specifics
- Pretend it's everything to everyone
- Use stock photos of people collaborating on laptops
- Say "simple" about anything complex

---

## 9. Implementation Status

### Architecture Constraint: Content Goes in the Database

**CRITICAL: Do NOT write page copy into static PHP view files.**

The getjoinery theme uses a ComponentRenderer architecture. Every page view (`index.php`, `features.php`, etc.) is a thin PHP wrapper that calls `ComponentRenderer::render('gj-slug')`. The actual content lives in the database as component instances (`pac_page_contents` table), editable via the admin UI.

**Rule:** All copy from Section 11 goes into the database as component instances, not hardcoded into PHP files. New PHP view files must not be created unless there is no viable database-driven alternative and explicit approval is given.

---

### ✅ Done

**Content (all applied directly to docker-prod getjoinery container DB):**
- `gj-home` — full rewrite: new hero, trust bar, features grid, code teaser, quality cards, CTA
- `gj-features` — full reframe, developer-first framing
- `gj-pricing` — full replacement: 3-option layout + FAQ + coupon display
- `gj-developers` — expanded security section, "runs in production" section, updated self-hosting
- `gj-philosophy` — reframed from org privacy to developer autonomy
- `gj-about` — updated
- `gj-showcase` — replaced with ScrollDaddy, Server Manager, Email Forwarding + "what could you build" teaser

**Install page (`/page/install`):**
- CMS page created in `pag_pages` (pag_link = `install`, pag_page_id = 10)
- Content stored as custom_html component (pac_page_content_id = 20)
- All `/install` CTAs across all gj- components and PublicPage.php updated to `/page/install`

**Theme changes (docker-prod):**
- `PublicPage.php` line 98: nav CTA "Get Started →" added, linking to `/page/install`
- `style.css`: FAQ styles (`.faq`, `.faq-item`), coupon badge (`.coupon-badge`, `.price-strike`), CMS page typography (`.entry-content`, `.jy-container`, `.page-title`, `.bg-white`, etc.) — CSS version bumped to v=4

---

### 🔲 Remaining

**E-commerce setup — BLOCKED on Stripe keys:**
- Configure Stripe API keys in getjoinery admin settings (currently all blank)
- Create "White Glove Install" product at $299 base price
- Create coupon "INSTALL" for $200 off (resulting price: $99)
- Add product questions: server IP address, root SSH password, domain name
- Wire up all "Get Installed for $99" CTAs to `/product/white-glove-install` (currently pointing to `/products`)

- Page `<title>` and meta description tags updated in all 7 theme view files (index, features, pricing, developers, showcase, philosophy, about)

---

## 10. Open Questions

All resolved.

| # | Question | Decision |
|---|----------|----------|
| 1 | Install page content — working GitHub guide or write from scratch? | Real page at `/install` on the site; GitHub links to us, not vice versa. Install steps derived from the server manager install script. |
| 2 | White Glove fulfillment detail | Installs what the server manager bare-metal script installs: Apache2, PHP 8.x, PostgreSQL, Joinery site + DB, VHost, SSL queued post-DNS. Customer provides Ubuntu 24.04 VPS + root SSH + domain. |
| 3 | Coupon — publicly listed or hidden? | Publicly listed: show $299 struck through, coupon INSTALL brings to $99. |
| 4 | Business license ballpark range? | No ballpark. Email only. |
| 5 | Showcase and Philosophy in primary nav? | Keep both. |
| 6 | `/install` — real page or GitHub link? | Real page on the site. |

---

## 11. Full Page Copy

Complete copy for every page. Intended as the direct source for implementation — write these words into the views.

---

### 11.1 Homepage (`/`)

#### Hero

**Headline:** Skip the boilerplate. Build what matters.

**Subheadline:** Joinery is a PHP web framework that ships with authentication, payments, email, a REST API, and an admin dashboard — already built, tested, and running in production. Start from working software, not a blank file.

**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** See What's Included → `/features`

---

#### Trust Bar (pill badges below hero)

- Production-Proven
- PostgreSQL + PHP 8
- Source Available
- Argon2id Passwords
- Zero-Dependency Frontend
- Built-in REST API

---

#### "What You Don't Have to Build" Section

**Label:** What's Included  
**Headline:** Everything you'd build anyway — already done.  
**Subheadline:** Every app needs auth, payments, email, and an admin dashboard. Here's how much of that you're skipping.

| Feature | Description |
|---|---|
| Auth & Sessions | Login, logout, password reset, email verification, session management. Done. |
| User Management | Roles, permissions (1–10 scale), subscription tiers, groups. Profiles with custom fields. |
| Payments | Stripe and PayPal built in. Subscriptions, products, coupons, webhooks. Zero platform fees. |
| Email | Mailgun or any SMTP via PHPMailer. Transactional, newsletter, notifications. No vendor lock-in. |
| Admin Dashboard | Full management UI for every data model. Search, bulk operations, analytics. |
| REST API | 40+ endpoints. Key auth, rate limiting, CORS. JSON in/out. Extend in plugins. |
| Plugin System | Add features as self-contained modules. Own data models, routes, admin pages. |
| Theme System | Override any view without touching core. Bootstrap, Tailwind, or zero-dep HTML5. |

**Link:** See everything that's included → `/features`

---

#### Code Teaser Section

**Headline:** Add a feature in minutes, not days.  
**Subheadline:** No route config. No migration files for schema changes. No setup ritual.

```
# A new page is one file
views/my_feature.php       →  /my-feature works immediately

# A new data model is one class
data/widgets_class.php     →  table created automatically on deploy

# A plugin is self-contained
plugins/my_plugin/
  plugin.json              #  metadata and settings
  data/                    #  own data models
  views/                   #  own public pages
  admin/                   #  own admin interface
  logic/                   #  own business logic
```

---

#### Quality / Trust Section

**Headline:** Production-proven. Not a weekend project.

**Card 1 — Running in Production**  
ScrollDaddy — a commercial DNS filtering service with real paying users — runs entirely on Joinery. User accounts, device management, Stripe billing, scheduled tasks, and a REST API served to a Go DNS server. All built as a Joinery plugin and theme, no core modifications. Same framework you'd download today.

**Card 2 — Security by Structure**  
SQL injection protection via PDO everywhere (no string concatenation paths). XSS protection via automatic output escaping. CSRF tokens on every form via FormWriter. Argon2id passwords. HttpOnly + SameSite cookies. You don't have to remember to use these — they're the only path.

**Card 3 — One Coherent Codebase**  
Built and maintained by one developer. One architectural vision, consistent patterns throughout. Zero "we inherited this from the team that left." Read it top-to-bottom and understand what it does.

**Card 4 — Source Available**  
Read every line. No compiled binaries, no obfuscation, no trust-us black boxes. PolyForm Noncommercial license — free for personal and noncommercial use.

---

#### Bottom CTA Section

**Headline:** Your next app, already half-built.  
**Subheadline:** Free for personal and noncommercial use. White Glove Install for $99.  
**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** Get Installed for $99 → product page

---

### 11.2 Features Page (`/features`)

**Page headline:** What you don't have to build.  
**Subheadline:** Every serious web app needs the same foundation. Joinery ships it all.

---

#### Authentication & Users

**Headline:** Auth, sessions, and user management — done.

Login and logout. Password reset with secure time-limited tokens. Email verification on registration. Session management with secure cookies. Role-based access control on a 1–10 permission scale. Restrict any page, any API endpoint, any feature to any permission level with one function call.

- Login / logout / persistent sessions
- Password reset via secure email tokens
- Email verification on registration
- Permission levels 1–10 (member through superadmin)
- Subscription tiers with feature-gating
- User profiles with custom fields
- Groups and group membership
- Member directory with search
- Activity tracking

---

#### Data Model Layer

**Headline:** Active Record with zero boilerplate.

Define a class, get a full CRUD interface. Declare your fields in `$field_specifications` and the table is created and kept in sync automatically. No migration files for schema changes — update the class and deploy.

```php
// Load a record
$user = new User($id, TRUE);
$email = $user->get('usr_email');

// Save changes
$user->set('usr_name', 'New Name');
$user->save();

// Query multiple records
$users = new MultiUser(
    ['usr_active' => 1],
    ['usr_created' => 'DESC']
);
$users->load();
```

- Active Record pattern with automatic table management
- Multi-object collection queries with filterable options
- Soft delete and permanent delete with cascade support
- JSON column support with automatic encode/decode
- Unique constraint checking built in
- Validation via `prepare()` before save

---

#### Payments

**Headline:** Stripe and PayPal, without the integration work.

Subscription billing, one-time products, coupons, webhooks — all built in. Zero platform fees. You keep 100% of what your payment processor pays you.

- Stripe subscriptions and one-time charges
- PayPal integration
- Coupon codes (percentage and fixed discounts)
- Product catalog with multiple price types
- Order history and management
- Webhook handling for payment events
- 0% platform transaction fees

---

#### Email

**Headline:** Send email without a third-party dependency.

Mailgun for deliverability, or any SMTP server via PHPMailer. The provider is pluggable — swap it in config without touching application code.

- Mailgun integration (API-based sending)
- Any SMTP server via PHPMailer
- Transactional email templates
- Newsletter and mailing list sending
- Subscriber management
- Notification system for events and updates
- No required third-party service

---

#### Admin Dashboard

**Headline:** Every model gets an admin interface.

The admin dashboard follows consistent patterns across every feature. List views with search, filters, and bulk operations. Detail views with edit forms. It's not a scaffold — it's a real, functional interface that runs the same system it's managing.

- Full CRUD interface for every data model
- Search and filter on list views
- Bulk operations
- Analytics and reporting
- Settings management
- Error logging and diagnostics
- Consistent Bootstrap-based UI throughout

---

#### REST API

**Headline:** A full REST API, already wired up.

Every data model is accessible via the API. Key-based auth, rate limiting, CORS, JSON in and out. Add custom endpoints in plugins.

```bash
# List users
curl -H "X-API-Key: your_key" \
  https://yoursite.com/api/users

# Create a product
curl -X POST \
  -H "X-API-Key: your_key" \
  -H "Content-Type: application/json" \
  -d '{"pro_name": "Premium Plan"}' \
  https://yoursite.com/api/products
```

- Key-based authentication
- 40+ model endpoints
- CRUD + action operations
- Rate limiting and CORS
- JSON request/response
- Extend with custom endpoints in plugins

---

#### Plugin System

**Headline:** Add features without touching core.

Plugins are self-contained modules with their own data models, views, admin pages, routes, and scheduled tasks. Activate and deactivate without data loss. Build your custom features as plugins — they stay isolated from the framework.

```
plugins/my_feature/
  plugin.json           # metadata, settings, version
  data/                 # data model classes (tables auto-created)
  views/                # public-facing views (routes auto-discovered)
  admin/                # admin interface pages
  logic/                # business logic
  tasks/                # scheduled tasks
  assets/               # CSS, JS, images
```

- Own data models with automatic table management
- Own routes and views (auto-discovered, no config)
- Own admin interface pages
- Scheduled task support
- Activate/deactivate without data loss
- Settings declared in `plugin.json` with factory defaults

---

#### Theme System

**Headline:** Replace the entire UI without forking anything.

The theme override chain lets you swap any view, template, or asset at the theme level without modifying core files or plugin files. Choose Bootstrap, Tailwind, or zero-dependency HTML5 — FormWriter and the rest of the system adapt automatically.

- Override chain: theme → plugin → base
- Bootstrap, Tailwind, or zero-dependency HTML5
- FormWriter adapts to your CSS framework automatically
- Component system for reusable page sections
- Mobile-responsive out of the box
- Asset fingerprinting for cache busting

---

#### Scheduled Tasks

**Headline:** Cron jobs that don't suck.

Define tasks as PHP classes, declare their schedule, and they run. No crontab entries, no external scheduler. Logging and error tracking built in. Tasks can live in plugins, isolated from core.

- PHP class-based task definitions
- Flexible scheduling (every N minutes, hourly, daily, weekly)
- Run history and error logging
- Admin UI to view task status and manually trigger
- Plugin-scoped tasks stay isolated

---

#### Security

**Headline:** Security is structural, not optional.

Most frameworks give you security tools and expect you to remember to use them. In Joinery, the secure path is the only path.

**SQL Injection** — PDO prepared statements everywhere. No string concatenation paths exist in the codebase. The model layer is structurally incapable of passing raw input to the database.

**XSS** — All output through FormWriter is escaped automatically. View templates use `htmlspecialchars()`. You can't forget — the helpers do it.

**CSRF** — FormWriter generates and validates CSRF tokens on every form. Zero extra setup required.

**Passwords** — Argon2id. Not bcrypt, not MD5. The current best practice. Legacy bcrypt hashes auto-upgrade on next login.

**Cookies** — HttpOnly, SameSite=Lax, Secure. Session cookies are inaccessible to JavaScript.

**File Uploads** — Validated by type and size, stored outside web root, served through controlled handlers — not direct URLs.

---

#### Features Page Bottom CTA

**Headline:** Everything here is in the first commit.  
**Subheadline:** Install Joinery and all of this is already working.  
**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** Get Installed for $99 → product page

---

### 11.3 Pricing Page (`/pricing`)

**Headline:** Simple pricing. No recurring fees.  
**Subheadline:** Use it free, or pay once to get set up. No subscriptions, no per-seat fees, no platform tax.

---

#### Pricing Cards

**Card 1: Free**

**Price:** $0  
**Label:** For personal and noncommercial use

- Full source code
- All features included
- Run on your own server
- PolyForm Noncommercial license
- Community support via GitHub

**CTA:** Get Started → `/install`

---

**Card 2: White Glove Install**

**Price:** ~~$299~~ $99  
**Sub-price line:** Use code **INSTALL** at checkout  
**Label:** One-time, no recurring fees

- Provide a fresh Ubuntu 24.04 VPS + domain
- We install Apache2, PHP 8.x, PostgreSQL, and Joinery
- Full site config, Apache VHost, SSL via Let's Encrypt
- Your server, your code, your control
- Typical completion within 24 hours

**CTA:** Get Installed → product purchase page

---

**Card 3: Business License**

**Price:** Custom  
**Label:** For commercial products

- Run Joinery commercially on your own infrastructure
- Perpetual license — pay once
- All current features included
- Email to discuss

**CTA:** Email Us → `mailto:hello@getjoinery.com?subject=Business+License`

---

#### FAQ

**Q: What counts as noncommercial?**  
Personal projects, learning, hobby apps, open source side projects, nonprofits. If you're not generating revenue from the app, the noncommercial license covers you.

**Q: What do I need for White Glove Install?**  
A fresh Ubuntu 24.04 VPS (any provider — Linode, DigitalOcean, Hetzner, wherever), root SSH access, and a domain name pointed at the server. We handle everything from there.

**Q: What exactly gets installed?**  
Apache2, PHP 8.x, PostgreSQL, a fully configured Joinery site with its own database and config file, Apache VHost configuration, and SSL via Let's Encrypt (provisioned automatically once DNS is live).

**Q: Do you support me after install?**  
Community support is available via GitHub for all users. Paid support and upgrade assistance is available — email hello@getjoinery.com.

**Q: What's the business license for?**  
If you're building a commercial product on Joinery — a SaaS app, a paid service, a client project that generates revenue — you need a business license. Email us and we'll work it out.

**Q: What license do my plugins and themes need to be under?**  
Any license you want. The PolyForm Noncommercial license covers the Joinery core. Plugins and themes you write are your own code — you can release them under MIT, keep them proprietary, sell them commercially, or do whatever you like. Your code is yours.

---

#### Pricing Page Bottom CTA

**Headline:** Start building today.  
**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** Questions? Email us → `mailto:hello@getjoinery.com`

---

### 11.4 Developers Page (`/developers`)

**Headline:** A framework you can actually read.  
**Subheadline:** No magic, no annotations, no framework ceremony. PHP 8, PostgreSQL, and patterns that have run in production for years. Open it, read it, modify it.  
**Primary CTA:** View on GitHub  
**Secondary CTA:** Install Guide → `/install`

---

#### Architecture Overview

**Label:** The Stack  
**Headline:** Architecture overview  
**Subheadline:** A clean, well-structured PHP application. No framework magic — just patterns that work.

**PostgreSQL** — Your data in a real database. Full SQL, JSONB columns, prepared statements throughout. No ORM to fight. No magic that generates surprise queries behind your back.

**PHP 8.x, MVC-like** — Front-controller routing through `serve.php`. Clean separation of data classes, logic files, and view templates. No autoloading mystery — you can trace exactly what loads and why.

**Zero-dependency frontend by default** — Modern vanilla JS. No jQuery, no Webpack, no build step. Bootstrap and Tailwind are supported when you want them. FormWriter adapts to whichever you choose.

**Active Record models** — Define a class, get full CRUD. `new User($id, true)` loads the record. `$user->save()` writes it. Multi-object collections for queries. No SQL strings for standard operations.

**Plugin system** — Self-contained modules with their own routes, data models, admin pages, and scheduled tasks. Build features in isolation. Deploy without touching core.

**Theme override chain** — theme → plugin → base. Swap any view or asset at the theme level without forking anything downstream.

---

#### Security

**Headline:** Security  
**Subheadline:** Membership platforms handle real personal data. Security is not a feature here — it's the baseline.

**SQL Injection Protection** — PDO prepared statements everywhere. There are no concatenated query strings in the codebase. The model layer is structurally incapable of passing raw input to the database.

**XSS Prevention** — All user-generated output is escaped via `htmlspecialchars()`. The FormWriter system handles output encoding automatically — individual views cannot forget to escape.

**CSRF Protection** — FormWriter generates and validates CSRF tokens on every form. You get it for free on every form you build with the system.

**Password Hashing** — Argon2id. Not bcrypt (which PHP used to default to), not MD5, not SHA-1. The current best practice. Legacy hashes are automatically upgraded on the user's next login.

**Cookie Security** — HttpOnly, SameSite=Lax, Secure. Session cookies are inaccessible to JavaScript and scoped to prevent cross-site attacks.

**File Handling** — Uploads are validated by type and size, stored outside the web root where possible, and served through controlled handlers — not direct URLs.

**Source Available** — You can read every line of code that touches user data. No obfuscation, no compiled binaries, no trust-us black boxes.

---

#### REST API

**Headline:** REST API  
**Subheadline:** Every feature is accessible through the API. Build integrations, automate workflows, or build your own frontend.

```bash
# List members
curl -H "X-API-Key: your_key" \
  https://yoursite.com/api/users

# Get a single member
curl -H "X-API-Key: your_key" \
  https://yoursite.com/api/users/42

# Create an event
curl -X POST \
  -H "X-API-Key: your_key" \
  -H "Content-Type: application/json" \
  -d '{"evt_name": "Monthly Meetup"}' \
  https://yoursite.com/api/events
```

- Key-based authentication
- 40+ model endpoints
- CRUD + action operations
- Rate limiting and CORS
- JSON request/response

---

#### Plugin System

**Headline:** Plugin System  
**Subheadline:** Plugins are self-contained modules that can add data models, views, admin pages, API endpoints, and scheduled tasks. Each plugin has its own MVC structure.

```
plugins/bookings/
  plugin.json
  data/
    booking_class.php       # data model — table auto-created
  views/
    booking.php             # /booking route works immediately
  admin/
    admin_bookings.php      # appears in admin dashboard
  logic/
    booking_logic.php       # business logic layer
  tasks/
    SendBookingReminders.php # scheduled task
```

- Own data models with automatic table management
- Own routes and views (auto-discovered, no config)
- Admin interface pages
- Scheduled task support
- Activate/deactivate without data loss
- Your plugins and themes are your code — release them under any license you choose

---

#### Theme System

**Headline:** Theme System  
**Subheadline:** Themes control the entire public UI. The override chain lets you customize any view, template, or asset without modifying core files.

If you're building a custom app, you can completely replace the public-facing UI — your design, your HTML, your CSS — through the theme override chain, without touching anything in core or plugins.

- Override chain: theme → plugin → base
- Bootstrap, Tailwind, or zero-dependency HTML5
- FormWriter adapts to your CSS framework
- Component system for reusable sections
- Full UI replacement without forking

---

#### This Runs in Production

**Headline:** Built for production. Running in production.  
**Subheadline:** ScrollDaddy is a commercial DNS filtering service. It runs entirely on Joinery.

User accounts, device management, filter configuration, subscription billing, scheduled blocklist updates, and a REST API served to a companion Go DNS server — all built as a Joinery plugin and theme. No core modifications. Real users, real billing, real traffic. Same framework you'd download today.

**Joinery features used:**
- Plugin system — 11 data models, admin interface, scheduled tasks
- Custom theme — full public-facing site with its own design
- Stripe subscription tiers — feature-gated plans with billing
- Scheduled tasks — daily blocklist updates from external sources
- REST API — device configuration served to the DNS server

---

#### Self-Hosting

**Headline:** Self-hosting  
**Subheadline:** Run Joinery on your own infrastructure. Same software, complete control.

**Requirements** — Ubuntu 24.04 recommended. Apache2, PHP 8.x, PostgreSQL. Standard LAMP stack, nothing exotic.

**Installation** — Download the release, run the install script. Apache, PHP, and PostgreSQL are configured automatically. Full guide at `/install`.

**Updates** — Automated upgrade system. One command pulls the latest release and applies any schema changes.

**Primary CTA:** Read the Install Guide → `/install`  
**Secondary CTA:** Get Installed for $99 → product page

---

#### Developers Page Bottom CTA

**Headline:** Explore the source.  
**Subheadline:** Source-available under the PolyForm Noncommercial license.  
**Primary CTA:** View on GitHub  
**Secondary CTA:** Install Guide → `/install`

---

### 11.5 Showcase Page (`/showcase`)

**Headline:** Built with Joinery  
**Subheadline:** Joinery ships with more than a framework. Here's what's already running on it.

---

#### ScrollDaddy (external app)

**Title:** ScrollDaddy  
**Link:** scrolldaddy.app  
**Tag:** Commercial product

DNS-based web filtering. Block social media, gambling, porn, news, and more — before it gets to your device.

ScrollDaddy is a commercial web filtering service with paying subscribers. The entire web application — user accounts, device management, filter configuration, subscription billing — is built on Joinery. A companion Go DNS server handles the actual filtering at the network level, reading device configurations from the Joinery database via REST API. Both components ship under the PolyForm Noncommercial license.

**Joinery features used:**
- Plugin system — 11 data models, admin interface, scheduled tasks
- Custom theme — full public-facing site with its own design
- Stripe subscription tiers — feature-gated plans with billing
- Scheduled tasks — daily blocklist updates from external sources
- User accounts — registration, login, profile, device management
- REST API — device configuration served to the DNS server

---

#### Server Manager (ships with Joinery)

**Title:** Server Manager  
**Tag:** Included plugin

A full remote server management system, built as a Joinery plugin. Manage a fleet of remote Joinery instances from a single admin dashboard — backups, updates, health monitoring, database operations, and new site provisioning — all without leaving the browser.

The plugin generates structured SSH job queues. A companion Go agent runs on the same server, polls the job queue, and executes each step via SSH — streaming live output back to the UI. The plugin owns all the intelligence; the agent is a generic executor. Adding a new operation is a PHP-only change.

**What it does:**
- Fleet dashboard with live health indicators (disk, memory, load, PostgreSQL status)
- One-click new site provisioning on any SSH-accessible server — installs Apache, PHP, PostgreSQL, and Joinery from scratch
- Database and full project backups, with optional encrypted upload to B2, S3, or Linode Object Storage
- One-click updates — pulls latest release and applies migrations on any managed node
- Database copy between nodes (for cloning or migrating)
- Automatic SSL provisioning via Let's Encrypt once DNS is live
- Live job output streaming — watch each SSH step run in real time

**What it's built from:**
- Plugin system — 4 data models, full admin interface
- Go agent — generic SSH/SCP executor that polls the job queue (separate binary, same repo)
- REST API — management endpoints on each node for fast, parallelizable status checks
- Scheduled tasks — automated SSL provisioning, order polling for hosted installs

---

#### Email Forwarding (ships with Joinery)

**Title:** Email Forwarding  
**Tag:** Included plugin

Self-hosted email forwarding built as a Joinery plugin. Point your domain's MX records at your server and manage all your email aliases from the admin dashboard — no third-party email service required.

Postfix handles inbound delivery. The plugin looks up each alias and forwards via SMTP. All configuration — domains, aliases, catch-alls, rate limits — lives in Joinery's database and is managed through the admin UI.

**What it does:**
- Multiple domains, multiple destinations per alias
- Catch-all addresses per domain
- SRS rewriting for SPF compatibility on forwarded mail
- Inbound DKIM verification + outbound DKIM signing via opendkim
- Per-alias and per-domain rate limiting
- RBL spam filtering (Spamhaus, SpamCop, Barracuda)
- Forwarding logs with admin viewer
- Live DNS validation badges — see at a glance whether MX, SPF, and DKIM are configured correctly

**What it's built from:**
- Plugin system — data models for domains, aliases, and forwarding logs
- Admin interface — domain and alias management, log viewer, DNS validation
- Postfix integration — pipes inbound mail to a PHP script via `master.cf`

---

#### "What Could You Build?" Section

**Headline:** What could you build?  
**Subheadline:** Joinery is a foundation for any web app that needs accounts, payments, email, and an admin interface.

- SaaS web apps — user accounts + subscription billing + admin dashboard
- Community platforms — members + content + notifications + events
- Internal tools — data models + admin interface + REST API
- Membership sites — tiers + payments + events + email
- Anything that would otherwise take 6 weeks to scaffold

---

#### Submit Section

**Headline:** Built something with Joinery?  
**Body:** If you've built a project on the framework — open source or commercial — we'd love to feature it.  
**CTA:** Submit Your Project → `mailto:hello@getjoinery.com?subject=Showcase+Submission`

---

#### Showcase Bottom CTA

**Headline:** Start building.  
**Subheadline:** Plugin system, theme engine, data models, REST API, and user management — all ready to go.  
**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** Developer Docs → `/developers`

---

### 11.6 Philosophy Page (`/philosophy`)

**Headline:** Why Joinery exists  
**Subheadline:** Because most frameworks expect you to trust them. This one expects you to read it.

---

#### The Problem with Black Boxes

Most web frameworks are opaque. They route requests, manage sessions, hash passwords — and they expect you to trust that they're doing it right. The code is either closed, obfuscated, or buried under so many abstraction layers that reading it is effectively impossible.

That's fine for simple applications. It becomes a problem when your app handles user data, processes payments, or manages authentication. At that point, "trust us" is not a good enough answer.

---

#### What I Built Instead

Joinery is source-available. Every line is readable. The auth system, the session management, the payment handling, the password hashing — you can open any file and read exactly what it does.

This isn't just a philosophy position. It has practical consequences: when something goes wrong, you can trace the code. When you need to customize behavior, you're not fighting the framework — you're reading it.

---

#### The Commitments

These are specific, concrete commitments — not vague promises.

**Source available, always.** You can read every line of code. We will never ship obfuscated or compiled versions. If you have a question about how something works, the answer is in the source.

**No lock-in.** You install Joinery on your own server. You own the code, the database, and the data. If you stop using Joinery tomorrow, nothing disappears. Take your install and go.

**No VC, no growth pressure.** Joinery is one developer with no investors. Every product decision can be made in the interest of the people using the software — not a growth target or acquisition story.

**Free for personal use.** Individuals, students, hobbyists, and nonprofits can use Joinery at no cost under the PolyForm Noncommercial license.

**Commercial licenses for commercial use.** If you're building a commercial product, a business license covers you. Fair pricing for fair use.

**Your plugins and themes are yours.** The PolyForm Noncommercial license covers the Joinery core. Anything you build on top of it — plugins, themes, integrations — is your code, under whatever license you choose. Sell it, open source it, keep it proprietary. We make no claim on it.

---

#### The Business Model

Revenue comes from White Glove Install (one-time install service), business licenses for commercial self-hosting, and support contracts for organizations that want ongoing help.

No subscription fees. No per-seat fees. No platform transaction tax. No data selling. No advertising. No VC.

The goal is to be sustainable and to be around in 20 years — not to raise a Series B.

---

#### Philosophy Bottom CTA

**Headline:** Software you can read.  
**Subheadline:** Try it free. Or get it installed for $99.  
**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** View Source → GitHub

---

### 11.7 About Page (`/about`)

**Headline:** About Joinery  
**Subheadline:** An independent, source-available PHP web framework. Built to last.

---

#### The Project

Joinery started as membership management software and grew into a general-purpose PHP web application framework. Today it ships with a full authentication system, payments (Stripe + PayPal), email, a REST API, a plugin system, a theme engine, scheduled tasks, and an admin dashboard — all production-ready.

It runs PHP 8.x on PostgreSQL. The codebase is source-available under the PolyForm Noncommercial license. You can read every line, self-host it, and modify it without asking permission.

Current production deployments include ScrollDaddy (a commercial DNS filtering service) and this site.

---

#### The Creator

Joinery is built and maintained by Jeremy Tunnell — a software developer who got tired of rebuilding the same plumbing on every project.

Being a solo developer is a feature, not a bug. One developer means one architectural vision, consistent patterns throughout the codebase, and zero "we inherited this from the team that left." Every file has a clear purpose and a clear owner.

The goal is software that's still running and still maintained in 20 years — not a startup looking for an exit.

*(Photo coming soon)*

---

#### Why Solo?

Most software companies raise capital, hire fast, and optimize for growth metrics. This creates misaligned incentives — the company needs to grow revenue, which means extracting more from customers.

Joinery has no investors to satisfy. No board of directors. No growth targets. This means every decision can be made in the interest of the people who use the software.

It also means the codebase stays coherent. One developer means one vision, one architectural style, and no accumulated technical debt from revolving teams.

---

#### Get in Touch

**Email:** hello@getjoinery.com  
**GitHub:** github.com/getjoinery/joinery  
**Business license inquiries:** hello@getjoinery.com — subject "Business License"

---

#### About Page Bottom CTA

**Headline:** Ready to start building?  
**Subheadline:** Free to install. $99 to get set up for you.  
**Primary CTA:** Get Started Free → `/install`  
**Secondary CTA:** See Pricing → `/pricing`

---

### 11.8 Install Page (`/install`)

**Headline:** Get Joinery Running  
**Subheadline:** Self-install on your own server, or pay $99 and we'll do it for you.

---

#### Requirements

Before you start, you'll need:

- **A server** — Ubuntu 24.04 recommended (any Debian-based distro should work)
- **Root SSH access** to the server
- **A domain name** pointed at the server's IP address

---

#### What Gets Installed

The install script provisions everything from scratch:

- Apache2 with VHost configuration
- PHP 8.x
- PostgreSQL with a dedicated database and user
- Full Joinery installation — site directory, config file, initial database schema
- SSL via Let's Encrypt (auto-provisioned once DNS is live)

---

#### Install Steps

1. Download the latest release and extract it
2. Navigate to `maintenance_scripts/install_tools/`
3. Run server setup: `./install.sh -y -q server` *(installs Apache, PHP, PostgreSQL — ~20 min)*
4. Create your site: `./install.sh -y -q site SITENAME YOURDOMAIN.COM`
5. Visit `https://yourdomain.com/admin` to complete setup

---

#### Want Us to Do This?

**Skip the setup — White Glove Install for $99**

Provide your server's IP address, root SSH credentials, and domain name. We handle everything: Apache, PHP, PostgreSQL, the Joinery install, VHost configuration, and SSL. Typical turnaround within 24 hours.

**CTA:** Get Installed for $99 → product purchase page
