# Specification: ScrollDaddy Marketing Plan

## Overview
This document outlines the strategic marketing plan for Scrolldaddy.app, focusing on cost-effective, high-impact growth strategies for a DNS-level content filtering and productivity tool.

## Product Value Proposition
*   **Core Message:** "Save your sanity online."
*   **Key Differentiator:** DNS-level blocking that bypasses app-level permissions and works across all devices with a 5-minute setup.
*   **Target Segments:** Productivity seekers (NoSurf), parents, privacy-conscious users, and individuals fighting digital addiction.

## Phase 1: Architecture Changes
Technical foundations required to support efficient marketing and remove conversion friction.

**Detailed specs:**
- [`scrolldaddy_marketing_infrastructure.md`](scrolldaddy_marketing_infrastructure.md) — Parts A–D below (SEO hooks, coupon auto-apply, UTM fixes, conversion events)
- [`ab_testing_framework.md`](ab_testing_framework.md) — platform-level multi-armed bandit A/B framework (item #5 below). Split out because it's platform infra, not ScrollDaddy-specific; ScrollDaddy is its first consumer.

### 1. SEO Metadata Hooks in Views
*   **Goal:** Unique titles/descriptions for search engines and social sharing previews.
*   **Summary:** Make `<meta name="description">` and the Open Graph block data-driven via `$hoptions`, fix the existing key-mismatch bug that renders meta descriptions empty on scrolldaddy pages, and add canonical + Twitter Card tags. Populate `index.php`, `pricing.php`, `product.php`, `page.php`.

### 2. Dynamic "Campaign" Coupon Auto-Apply
*   **Goal:** Remove checkout friction for referral sources.
*   **Summary:** New `CampaignCapture` helper, gated behind `isset($_GET['coupon'])` in `serve.php` for zero overhead on normal requests. Validates codes via `CouponCode`, stores on session, auto-applies to `ShoppingCart` (on next `add_item()` if no cart yet), and surfaces a trust-building flash banner on pricing/cart. Invalid codes fail silently.

### 3. UTM Attribution Fixes
*   **Goal:** Make existing UTM capture actually useful so Phase 3 launches (Product Hunt, Reddit, AlternativeTo) can be measured.
*   **Summary:** Fix a bind-value bug in `SessionControl::save_visitor_event()` that drops `vse_medium`/`vse_content` data, and add first-touch session stickiness so UTM survives past the landing page to the moment of conversion.

### 4. Named Conversion Events
*   **Goal:** Give the funnel UI and the bandit framework real signal to work with.
*   **Summary:** Add `TYPE_CART_ADD`, `TYPE_CHECKOUT_START`, `TYPE_PURCHASE`, `TYPE_SIGNUP` to `vse_visitor_events`, wire them into the four call sites, stamp session UTM onto the conversion row, and extend the existing admin funnel UI to let steps match on event type in addition to page URL.

### 5. Bandit A/B Framework (platform-level, separate spec)
*   **Goal:** Optimize landing-page copy without classical A/B test overhead. Use each conversion event from #4 as the reward signal.
*   **Spec:** [`ab_testing_framework.md`](ab_testing_framework.md) — full design, data model, admin UI, implementation checklist.
*   **Summary:** Epsilon-greedy multi-armed bandit. Client-side variant assignment (works with static cache — assignment happens in inline JS, rewards tracked server-side via Part D hooks). Sticky 30-day per-experiment cookie so returning visitors see the same variant. Admin UI for experiment + variant CRUD and a manual "crown the winner" action. First experiment target: homepage hero headline.

## Phase 2: SEO Strategy
Capturing passive growth by ranking for high-intent problem-solution keywords.

### 1. On-Page Optimization
*   **Title Tags:** Format as `ScrollDaddy | [Problem/Feature] | Save Your Sanity Online`.
*   **Meta Descriptions:** Highlight the "5-minute setup" and "DNS-level privacy" in every description.
*   **Header Consistency:** Ensure H1/H2 tags use keywords like "DNS Filtering" and "Block Social Media."

### 2. Content & Comparison Pages
*   **Comparison "War" Pages:** Create pages for "ScrollDaddy vs NextDNS" and "ScrollDaddy vs Freedom.to" using the existing admin `page_contents` system.
*   **AI-Generated Blog Guides:** Draft guides like "How to block TikTok on your home network" and "Why DNS filtering is better than browser extensions."

## Phase 3: Marketing Execution
Active community engagement and product launches using AI-assisted copy.

### 1. Low-Hanging Fruit (Zero-User Path)
*   **AlternativeTo Blitz:** Submit to AlternativeTo.net to capture users looking for competitor alternatives.
*   **Reddit Support Agent:** Use AI to draft helpful responses to users asking for blocking/productivity advice in subreddits like `r/NoSurf`.
*   **Startup Directory Dump:** Submit to BetaList, Indie Hackers, and 10Words.

### 2. High-Impact Launches
*   **Product Hunt Launch:** A coordinated launch with a founder's story and a specific community discount code (e.g., `PH2026`).
*   **Micro-Influencer Outreach:** Reach out to productivity and digital detox creators for honest reviews.

## Implementation Checklist
- [ ] **Phase 1:** Update landing page views with `meta_description` hooks.
- [ ] **Phase 1:** Implement `?coupon=` auto-apply logic.
- [ ] **Phase 2:** Claim AlternativeTo.net profile and set up listing.
- [ ] **Phase 2:** Set up Google Search Console to monitor keyword rankings.
- [ ] **Phase 2:** Create first 3 Comparison "War" pages in Admin.
- [ ] **Phase 3:** Create a "Community Outreach" account on Reddit.
- [ ] **Phase 3:** Draft "Founder's Story" and assets for Product Hunt launch.
- [ ] **Phase 3:** Design/Export 3 high-quality marketing screenshots of the dashboard.
