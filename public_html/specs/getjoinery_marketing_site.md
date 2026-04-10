# Spec: getjoinery.com Marketing Site

**Status:** Draft  
**Created:** 2026-04-05  
**Author:** Jeremy + Claude

---

## 1. Purpose

Build the public-facing marketing website for getjoinery.com. The site sells hosted Joinery (SaaS) as the primary offering while communicating the project's source-available, self-hostable philosophy as a differentiator and trust signal.

---

## 2. Target Audiences (Priority Order)

1. **Small org admins** — Running a club, nonprofit, community group, or small membership org. Non-technical. Looking for "something like Mighty Networks but less expensive and less creepy with my data." Primary conversion target for SaaS.

2. **Privacy-conscious individuals** — May run a personal site, small community, or just appreciate the philosophy. Could convert to SaaS or self-host. Drawn by the "you own your data" message.

3. **Developers / technical evaluators** — Evaluating platforms for a client or personal project. Want to see the architecture, API, plugin system, and self-hosting docs. May self-host or recommend SaaS to a client.

---

## 3. Site Map

```
/                       Home (landing page)
/features               Feature overview
/pricing                Pricing tiers + comparison
/developers             Developer-focused page (API, plugins, self-hosting, architecture)
/philosophy             Why Joinery exists (privacy, data ownership, open source values)
/about                  About the project and creator
/docs                   → Links to documentation (external or future docs site)
/blog                   → Future blog (placeholder in nav, not in v1 scope)
/signup                 → SaaS signup flow (placeholder, not in v1 scope)
/login                  → SaaS login (placeholder, not in v1 scope)
```

**v1 scope:** Home, Features, Pricing, Developers, Philosophy, About  
**Deferred:** Blog, Docs (full site), Signup flow, Login, Demo instance

---

## 4. Messaging Hierarchy

### Primary Message (Homepage Hero)
**Joinery is membership software you can trust with your data.**

Supporting: All-in-one platform for managing members, events, payments, and communications. Hosted for you or self-hosted — your choice, your data.

### Secondary Messages (in order of prominence)

1. **It just works** — Members, events, payments, email, e-commerce all integrated. No duct-taping five services together.

2. **Your data stays yours** — No vendor lock-in. Export everything. Self-host anytime. We'll never sell your members' data or hold it hostage.

3. **Built for real organizations** — Clubs, nonprofits, communities, membership orgs. Not another "creator economy" platform.

4. **Source available** — Inspect the code. Know exactly what's running. Self-host if you want. We believe transparency builds trust.

5. **Developer-friendly** — REST API, plugin system, theme engine, PostgreSQL. Extend it, integrate it, make it yours.

---

## 5. Page Specifications

### 5.1 Homepage

**Goal:** Explain what Joinery is in 10 seconds, build trust in 30 seconds, get a signup click in 60 seconds.

**Structure:**

#### Hero Section
- Headline: "Membership software you can trust with your data."
- Subheadline: "Manage members, events, payments, and communications — all in one place. We host it for you, or you host it yourself."
- Primary CTA: "Start Free Trial" (button, prominent)
- Secondary CTA: "See How It Works" (text link or outline button, scrolls to features)
- [OPEN] Background treatment — light gradient? subtle pattern? illustration?

#### Social Proof Bar (below hero)
- [OPEN] We don't have testimonials or logos yet. Options:
  - Skip entirely in v1
  - Show feature stats ("20+ themes", "REST API", "100% source available")
  - Add a "Used by X organizations" counter once we have users
  - **Decision needed**

#### "What You Get" Section (Feature Cards)
Quick visual overview — 6 cards in a 3x2 grid:

| Card | Icon | Headline | One-liner |
|------|------|----------|-----------|
| 1 | people | Member Management | Profiles, permissions, subscription tiers, and groups |
| 2 | calendar | Events & Registration | Create events, manage signups, handle waitlists and recurring schedules |
| 3 | credit-card | Payments & E-Commerce | Stripe and PayPal built in. Sell memberships, products, and event tickets |
| 4 | mail | Email & Communications | Newsletters, mailing lists, notifications — with Mailgun or self-hosted |
| 5 | puzzle | Plugins & Themes | Extend with plugins, customize with themes. 20+ themes included |
| 6 | shield | Your Data, Your Rules | Self-host anytime. Export everything. No lock-in, no data selling |

"See All Features →" link below the grid.

#### "How It's Different" Section
Three columns contrasting Joinery with the typical approach:

| Typical Platform | Joinery |
|-----------------|---------|
| Your data lives on their servers, governed by their terms | Your data is yours. Export it, self-host it, delete it — anytime |
| Opaque code you can't inspect | Source available. Read every line that touches your data |
| Locked in. Switching costs designed to keep you | No lock-in. We earn your business every month |

#### "Who It's For" Section
Brief descriptions of ideal users with subtle illustrations or icons:
- **Clubs & Associations** — Manage members, collect dues, organize events
- **Nonprofits** — Track supporters, run fundraisers, communicate with donors
- **Community Groups** — Build your community on your terms, not someone else's platform
- **Small Businesses** — Membership programs, subscriptions, and customer management

#### Pricing Teaser
- Headline: "Simple, honest pricing"
- Show 3 tiers with annual prices prominently, monthly as secondary
- Starter $29/mo, Organization $59/mo, Network $99/mo (annual pricing shown)
- "All features included. No transaction fees. No surprises."
- "See Pricing →" link to /pricing
- Mention "Free for personal and nonprofit use (self-hosted)"

#### Bottom CTA Section
- Headline: "Ready to own your membership platform?"
- Primary CTA: "Start Free Trial"
- Secondary: "Talk to Us" (email or contact form)

---

### 5.2 Features Page (`/features`)

**Goal:** Comprehensive feature inventory for the evaluator who wants details.

**Structure:** Alternating left-right sections (image/screenshot + text), one per feature category. Each section has:
- Feature category name
- 2-3 sentence description
- Bullet list of specific capabilities
- [OPEN] Screenshot or illustration placeholder

**Feature Categories (in order):**

1. **Member Management**
   - User profiles with custom fields
   - Permission-based access control (member, moderator, admin)
   - Subscription tiers with content access control (per-post, per-page, per-event tier gating)
   - Groups and group management
   - Member directory
   - Activity tracking and analytics

2. **Events & Registration**
   - Event creation with rich details
   - Online registration with capacity management
   - Recurring events (weekly classes, monthly meetups)
   - Waitlists
   - Custom registration questions
   - Calendar integration (iCal export)

3. **Payments & E-Commerce**
   - Stripe and PayPal integration
   - Subscription billing (recurring payments)
   - Product catalog and shopping cart
   - Coupon codes and discounts
   - Order management
   - No platform transaction fees [CONFIRM — is this the plan?]

4. **Email & Communications**
   - Newsletter sending with templates
   - Mailing lists with subscriber management
   - Automated email sequences
   - Direct messaging between members
   - Notification system
   - Pluggable email providers (Mailgun, PHPMailer/SMTP) via EmailProviderInterface — self-hosted option with no third-party dependency

5. **Content & Community**
   - Posts with categories and scheduling
   - Pages with content blocks
   - Photo galleries
   - Comments and reactions
   - Social features (following, messaging)

6. **Admin Dashboard**
   - Comprehensive management interface
   - Analytics and reporting
   - Bulk operations
   - Settings management
   - Error logging

7. **Themes & Customization**
   - 20+ included themes
   - Multiple CSS framework options (Tailwind, Bootstrap, zero-dependency HTML5)
   - Theme override system for deep customization
   - Mobile-responsive out of the box

8. **API & Integrations**
   - Full REST API with key authentication
   - CRUD operations for all data models
   - Webhook support
   - Rate limiting and security built in

9. **Privacy & Data Ownership**
   - All data stored in your database (PostgreSQL)
   - Full data export
   - No third-party tracking
   - Self-hosting option for complete control
   - Source available for inspection

**Bottom CTA:** "Start Free Trial" / "See Pricing"

---

### 5.3 Pricing Page (`/pricing`)

**Goal:** Clear pricing that builds trust. No "contact sales" for standard tiers.

#### Proposed Pricing Model

Based on competitor analysis:
- Wild Apricot: $48-$720/mo based on contact count, all features included
- Mighty Networks: $49-$360/mo flat + 1-3% transaction fee
- Circle: $89-$419/mo flat + 0.5-2% transaction fee
- Memberful: $49/mo + 4.9% transaction fee
- MemberPress: $200-$500/yr (self-hosted WordPress plugin)

**Joinery's positioning:** Undercut the market significantly. Joinery is leaner (one developer, no VC funding, no bloated team). The self-host option means infrastructure costs are lower per customer because some users host themselves.

#### Pricing Tiers — DECIDED

**Model:** Member-based tiers, 0% platform transaction fees, all features included at every tier. No free SaaS tier — generous free trial instead (14 or 30 days, no credit card required).

**Anchored pricing:** Monthly prices are the anchor; annual prices (~25% off) are the real target.

| | Starter | Organization | Network |
|---|---------|-------------|---------|
| **Monthly** | $39/mo | $79/mo | $129/mo |
| **Annual** | $29/mo ($348/yr) | $59/mo ($708/yr) | $99/mo ($1,188/yr) |
| **Savings** | ~25% off | ~25% off | ~23% off |
| **Members** | Up to 250 | Up to 2,000 | Up to 10,000 |
| **Admins** | 2 | 5 | Unlimited |
| **Events** | Unlimited | Unlimited | Unlimited |
| **Products** | Unlimited | Unlimited | Unlimited |
| **Email sends** | 1,000/mo | 5,000/mo | 25,000/mo |
| **Storage** | 2 GB | 10 GB | 50 GB |
| **Themes** | All included | All included | All included |
| **Plugins** | All included | All included | All included |
| **API access** | Yes | Yes | Yes |
| **Transaction fees** | 0% | 0% | 0% |
| **Custom domain** | Yes | Yes | Yes |
| **Priority support** | — | Yes | Yes |
| **White-label** | — | — | Yes |

[OPEN] Remaining pricing questions for later refinement:
- Are the member limits right? (250 / 2,000 / 10,000)
- Should there be a 4th tier for larger orgs, or handle that as custom/enterprise?
- Email send limits — depend on actual infrastructure costs (Mailgun pricing)

#### Self-Hosted Section (on pricing page)

Below the SaaS tiers:

**"Prefer to self-host?"**

Two options presented:

1. **DIY Install — Free**
   - Free for personal, educational, and nonprofit use (PolyForm Noncommercial)
   - Commercial license — [OPEN] pricing deferred, "contact us" for now
   - Full source code access
   - Same features as hosted, you manage the infrastructure
   - Community support via GitHub

2. **White Glove Install — $249 one-time**
   - Automated provisioning via Linode API
   - User flow: register at getjoinery.com → choose self-host → enter Linode API key + pick domain → script provisions server, installs everything, configures DNS/SSL → site is live
   - One-time fee, no recurring charges
   - All pure profit — no ongoing maintenance burden
   - [OPEN] Exact Linode tier/specs included in the $249 TBD

#### Comparison Table (Optional)

A "Joinery vs." table comparing key points against 2-3 competitors:

| | Joinery (Org) | Wild Apricot (Community) | Mighty Networks (Courses) | Circle (Professional) |
|---|---|---|---|---|
| Annual price | $59/mo | $99/mo | $109/mo | $89/mo |
| Members | 2,000 | 500 | Unlimited | Unlimited |
| Transaction fees | 0% | 0% | 2% | 2% |
| Self-host option | Yes | No | No | No |
| Source available | Yes | No | No | No |
| All features included | Yes | Yes | No | No |
| Data export | Full | Limited | Limited | Limited |

[OPEN] Need to verify competitor details are current before publishing. This table is powerful but must be accurate.

---

### 5.4 Developers Page (`/developers`)

**Goal:** Make developers fall in love. Show that this isn't a black-box SaaS — it's a real, well-architected platform they can extend and trust.

**Structure:**

#### Hero
- Headline: "Built by a developer, for developers"
- Subheadline: "PostgreSQL. PHP. REST API. Plugin system. Theme engine. No magic, no lock-in, no nonsense."

#### Architecture Overview
Brief, confident summary of the tech stack:
- **Database:** PostgreSQL with prepared statements everywhere
- **Backend:** PHP 8.x, MVC-like architecture, front-controller routing
- **Frontend:** Your choice — Tailwind, Bootstrap, or zero-dependency HTML5
- **API:** Full REST API with key auth, rate limiting, CORS
- **Plugins:** Self-contained modules with their own data models, routes, and admin UI
- **Themes:** Override chain — customize anything without forking

#### REST API Section
- Endpoint examples (curl snippets)
- Authentication overview
- Link to full API docs
- Mention: 40+ model endpoints, CRUD + action endpoints

#### Plugin System Section
- What a plugin looks like (directory structure)
- What plugins can do (data models, admin pages, API endpoints, scheduled tasks)
- Existing plugins as examples (Bookings, Email Forwarding, Items)
- Link to Plugin Developer Guide

#### Theme System Section
- How themes work (override chain)
- Available CSS frameworks
- Zero-dependency HTML5 themes (performance story)
- How to create a custom theme

#### Self-Hosting Section
- System requirements (PHP 8.x, PostgreSQL, Apache/Nginx)
- Installation overview
- Link to install docs
- Docker support? [OPEN — is Docker packaging planned?]

#### Code Quality / Philosophy
- No jQuery dependency (modern vanilla JS)
- Prepared statements everywhere (security)
- Active Record pattern for data models
- Version-controlled migrations
- Automated deployment and upgrade system

#### GitHub / Source Access
- Link to repository
- License summary (PolyForm Noncommercial)
- How to contribute / report issues

---

### 5.5 Philosophy Page (`/philosophy`)

**Goal:** Explain *why* Joinery exists. This is the ideological heart of the project. Speak plainly — no corporate PR voice.

**Tone:** First person (Jeremy speaking directly). Honest, opinionated, not preachy.

**Structure:**

#### The Problem
- When you use a membership platform, your members' data — their names, emails, payment info, activity — lives on someone else's servers, governed by someone else's terms.
- Most platforms make it hard to leave. Your data is the product. Switching costs are the business model.
- You can't see what the software is doing with your data. It's a black box.

#### Why I Built Joinery
- I wanted membership software that treats the organization as the customer, not the product.
- I wanted something I could inspect, modify, and trust.
- I wanted members to know their data isn't being harvested, sold, or analyzed for someone else's benefit.

#### The Commitments
Frame these as specific, concrete commitments — not vague promises:

1. **Source available, always.** You can read every line of code that touches your data. We will never obscure what the software does.

2. **Self-hostable, always.** Every feature we offer as a hosted service, we will make every effort to offer as a self-hostable option. If we can't, we'll explain why.

3. **Your data is yours.** Full export. No lock-in. If you leave, you take everything with you.

4. **No data selling. No tracking. No ads.** We make money from subscriptions, not from your members' data.

5. **Free for personal use.** Individuals, students, and nonprofits can self-host Joinery at no cost under the PolyForm Noncommercial license.

6. **Commercial licenses available.** Businesses that want to self-host can purchase a commercial license. We want to be sustainable, not extractive.

#### The Business Model
- Transparent explanation: We sell hosted Joinery subscriptions. That's the business.
- Commercial self-hosting licenses are a secondary revenue stream.
- No venture capital. No pressure to "maximize engagement" or "monetize the user base."
- Sustainable, not explosive. We want to be around in 20 years, not acquired in 3.

---

### 5.6 About Page (`/about`)

**Goal:** Put a face on the project. Build trust through transparency.

**Structure:**

#### About the Project
- Brief history: when it started, why, what it's grown into
- Current status (how many features, active development, etc.)
- Open source community status

#### About the Creator
- [OPEN] Jeremy bio — background, motivation, photo
- Why a solo developer building this is a feature, not a bug (opinionated, fast, no design-by-committee)

#### Contact
- Email for questions / sales / partnerships
- GitHub for issues / contributions
- [OPEN] Discord/community channel?

---

## 6. Visual Design Direction

**DECIDED** — Based on prototype iteration (variants A-N), selected **Variant M** as the foundation.

Prototypes preserved at: `/home/user1/theme-sources/prototypes/`

### Color Palette

Warm stone grays with amber accent — differentiates from the sea of blue SaaS competitors while communicating trust and competence.

| Token | Value | Usage |
|-------|-------|-------|
| `--primary` | `#44403c` (warm stone) | Primary text headers, nav |
| `--primary-dark` | `#292524` (dark stone) | Hero text, emphasis, dark sections |
| `--primary-light` | `#57534e` (medium stone) | Secondary elements |
| `--accent` | `#b45309` (amber) | CTAs, links, icons, highlights |
| `--accent-light` | `#d97706` (light amber) | Hover states, secondary accents |
| `--accent-bg` | `#fffbeb` (warm cream) | Feature icon backgrounds, featured card bg |
| `--text` | `#1c1917` | Body text |
| `--text-muted` | `#78716c` | Secondary text, descriptions |
| `--bg` | `#fafaf9` (warm white) | Page background |
| `--bg-alt` | `#f5f5f4` (warm gray) | Alternating sections |
| `--bg-dark` | `#1c1917` (near black) | Dark sections (CTA, footer) |
| `--border` | `#e7e5e4` | Card borders, dividers |

**The chosen palette should also become the new default theme colors**, so the product and marketing site align.

### Typography

| Role | Font | Weights | Rationale |
|------|------|---------|-----------|
| Headings | **Manrope** | 700, 800 | Geometric, confident, friendly without being soft. Carries personality. |
| Body | **Inter** | 400, 500, 600 | Neutral, highly readable, gets out of the way. Industry standard for body text. |
| Buttons/Labels | **Manrope** | 700 | Consistent with heading voice for action elements |

### Layout Patterns
- Clean, spacious, modern
- Pill-style trust badges (from J) — more visual interest than plain text
- Card-style differentiator section (from J) — friendlier than table format
- Border radius: 8px (cards), 10px (larger panels), 100px (pills)
- SVG stroke icons throughout (no icon font dependency)

### Imagery Strategy
- Product screenshots (once available) — real, not mockups
- Simple SVG stroke icons for feature cards
- No stock photos of people high-fiving in offices
- [OPEN] Geometric/abstract decorative elements TBD

### Mobile-First
Must look great on phones. Many small org admins will find us on mobile.

---

## 7. Technical Implementation

### Theme Structure
The marketing site will be a Joinery theme. This eats our own dogfood and demonstrates the platform's theming capability.

```
theme/getjoinery/
  theme.json
  assets/
    css/
    js/
    images/
  includes/
    PublicPage.php        (extends PublicPageBase, marketing layout)
  views/
    index.php             (homepage)
    features.php
    pricing.php
    developers.php
    philosophy.php
    about.php
```

### Routing
Standard Joinery routing — no special serve.php routes needed. Create views, they work.

### No Authentication Required
All marketing pages are public. No login/session needed (though the nav should show login/signup links for the SaaS product).

### Content Management
v1: Static content in PHP view files. No CMS needed yet.
Future: Could use Joinery's own page/post system for blog content.

---

## 8. Open Questions Summary

Collected from throughout the spec — these need answers before implementation:

### Decided

| # | Question | Decision |
|---|----------|----------|
| 1 | **Color palette** | Warm stone + amber (Variant M). See Section 6 for full token table. |
| 2 | **Heading font** | Manrope headings + Inter body |
| 3 | **Pricing tiers** | Anchored: $39/$79/$129 monthly, $29/$59/$99 annual (~25% off) |
| 4 | **Member-based vs flat** | Member-based (250 / 2,000 / 10,000) |
| 5 | **Transaction fees** | 0% platform fees confirmed |
| 6 | **Self-host pricing** | DIY free; White Glove Install (automated via Linode API) $249 one-time. Commercial license deferred — "contact us" for now. |
| 7 | **Free SaaS tier** | No. Generous free trial instead (14-30 days, no credit card). |

### Must Answer Before Building

None — all blocking decisions resolved. Remaining open items can be deferred to refinement.
| 8 | **Social proof** — What do we put in the social proof area with no customers yet? | 5.1 |

### Can Defer

| # | Question | Section |
|---|----------|---------|
| 9 | **Competitor comparison table** — Include on pricing page? Verify accuracy first. | 5.3 |
| 10 | **Docker packaging** — Is self-hosted Docker planned? Mention on dev page? | 5.4 |
| 11 | **Screenshots/demos** — Needed but can use placeholders in v1. | 5.1, 5.2 |
| 12 | **Blog** — When to start? Placeholder nav link for now. | 3 |
| 13 | **Community channel** — Discord? GitHub Discussions? Forum on Joinery itself? | 5.6 |
| 14 | **Jeremy bio and photo** — Content needed for about page. | 5.6 |
| 15 | **Decorative visual elements** — Geometric patterns, illustrations, etc.? | 6 |

---

## 9. What This Spec Does NOT Cover

- **SaaS infrastructure** — Multi-tenant hosting, provisioning, billing integration
- **Signup/onboarding flow** — Registration, trial activation, payment collection
- **Documentation site** — Full docs (API reference, install guides, etc.)
- **SEO strategy** — Keywords, meta descriptions, structured data
- **Analytics** — Tracking signups, conversion funnels (keeping it private-friendly)
- **Legal pages** — Terms of service, privacy policy, cookie policy

These are all important but are separate workstreams.

---

## 10. Implementation Phases

### Phase 1: Foundation
- Choose color palette and typography
- Build the `getjoinery` theme skeleton
- Implement homepage with all sections (placeholder images)
- Implement shared layout (header, footer, nav)

### Phase 2: Content Pages
- Features page
- Pricing page (with draft numbers — can update later)
- Developers page
- Philosophy page
- About page

### Phase 3: Polish
- Real screenshots once available
- Responsive testing and fixes
- Performance optimization
- Social proof section (once we have something to show)

### Phase 4: Launch Prep
- Domain configuration
- SEO basics (meta tags, sitemap, robots.txt)
- Final copy review
- Legal pages (privacy policy, terms)
