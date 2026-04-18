# Specification: ScrollDaddy Marketing Plan

## Overview
This document outlines the strategic marketing plan for Scrolldaddy.app, focusing on cost-effective, high-impact growth strategies for a DNS-level content filtering and productivity tool.

## Product Value Proposition
*   **Core Message:** "Save your sanity online."
*   **Key Differentiator:** DNS-level blocking that bypasses app-level permissions and works across all devices with a 5-minute setup.
*   **Target Segments:** Productivity seekers (NoSurf), parents, privacy-conscious users, and individuals fighting digital addiction.

## Phase 1: Architecture Changes
Technical foundations required to support efficient marketing and remove conversion friction.

### 1. SEO Metadata Hooks in Views
*   **Goal:** Unique titles/descriptions for search engines and social sharing previews.
*   **Action:** Update `PublicPage::public_header()` calls in plugin views (e.g., `index.php`, `pricing.php`) to include `meta_description` and `og_image` in the `$hoptions` array.

### 2. Dynamic "Campaign" Coupon Auto-Apply
*   **Goal:** Remove checkout friction for referral sources.
*   **Action:** Update `pricing_logic.php` or `SessionControl.php` to detect `?coupon=CODE` URL parameters and automatically apply them to the session.

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
