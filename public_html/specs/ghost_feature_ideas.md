# Ghost Feature Parity Ideas

This document captures features from the Ghost blogging platform that Joinery does not yet have, organized by priority. These are ideas for consideration, not committed roadmap items.

**Reference:** Ghost (ghost.org) is a Node.js-based open source publishing platform focused on blogging, newsletters, and membership monetization.

---

## Already Present in Joinery

For reference, these Ghost features already exist in Joinery:

- Blog posts with scheduling, drafts, author tracking, featured images
- Tags/categories (via groups system with `grp_category = 'post_tag'`)
- Content revisions/history (`content_versions_class.php`)
- Email/newsletter system with templates, queuing, mailing lists, recurring mailers
- Membership tiers with subscription management
- Rich text editor (Trumbowyg WYSIWYG)
- RSS feeds (`/views/rss20_feed.php`)
- XML sitemap (`/views/sitemap.php`)
- Comments with moderation, nesting, anti-spam
- User roles/permissions (permission levels)
- Media/file handling with multiple image sizes
- Analytics (session, email stats, funnels)
- REST API with key authentication
- Webhooks (inbound from Stripe, PayPal, Mailgun)
- Navigation menus (public + admin)
- Static pages with component system
- Reactions (like/favorite/bookmark)
- In-app notifications
- Plugin system
- robots.txt

---

## Gaps — High Priority (Core Publishing)

### 1. Gated / Paywalled Content
**Ghost:** Each post can be set to public, free-members-only, or specific paid tier(s). Non-members see a paywall prompt when they hit restricted content.
**Joinery:** No per-post content access gating exists.
**Notes:** This is Ghost's flagship monetization feature. Would require a new field on `pst_posts` (e.g. `pst_min_access_tier`) and view-layer enforcement, plus a "subscribe to read" prompt template.

---

### 2. Email-Only Posts
**Ghost:** A post can be delivered as a newsletter without creating a public web page — the content exists only in inboxes.
**Joinery:** All posts are web-first; no equivalent exists.
**Notes:** Requires a flag on posts (e.g. `pst_is_email_only`) and routing logic to hide the post from public URLs while still allowing email delivery.

---

### 3. Audience Segmentation for Email
**Ghost:** Newsletter sends can be targeted by membership tier, custom labels, or which newsletter a member subscribed to.
**Joinery:** Email uses basic mailing lists only; no tier-based or label-based segmentation.
**Notes:** Would build on the existing subscription tiers system. Requires linking mailing list recipients to tier membership at send time.

---

### 4. Member Import / Export
**Ghost:** Members (subscribers) can be imported and exported as CSV files, including their tier, labels, and subscription status.
**Joinery:** No CSV import/export for subscribers found.
**Notes:** Useful for migrating from other platforms (including Ghost) and for bulk subscriber management.

---

### 5. Full-Text Post Search for Readers
**Ghost:** Native search indexes post titles, excerpts, tags, and authors for readers. Third-party options (Algolia, Typesense) can add full-body search.
**Joinery:** Search exists for users and sessions but no public-facing post content search.
**Notes:** A minimal implementation could search `pst_title`, `pst_short_description`, and `pst_body` via a new AJAX endpoint and a search input in the theme.

---

### 6. Per-Post SEO Fields
**Ghost:** Each post has dedicated fields for SEO title, SEO description, and a social sharing (OG) image distinct from the featured image.
**Joinery:** No dedicated SEO meta fields on posts or pages; relies on content defaults.
**Notes:** Requires new columns on `pst_posts` (e.g. `pst_seo_title`, `pst_seo_description`, `pst_og_fil_file_id`) and theme-layer `<meta>` tag rendering.

---

### 7. Structured Data / Schema.org Markup
**Ghost:** Posts automatically render `Article` structured data (JSON-LD) for rich search results.
**Joinery:** Not implemented.
**Notes:** Can be added to the post view template without any database changes. Requires pulling author, publish date, image, and description into a JSON-LD block.

---

### 8. Per-Post Excerpt and Custom OG Image
**Ghost:** Each post has a custom excerpt (displayed in listings/feeds) and a separate social sharing image for Open Graph/Twitter Card previews.
**Joinery:** `pst_short_description` serves as the excerpt but there is no per-post OG image field.
**Notes:** Partially covered by `pst_short_description`. Needs an OG image field and theme-layer `<meta property="og:image">` rendering.

---

## Gaps — Medium Priority (Publishing Workflow)

### 9. Multiple Named Newsletters
**Ghost:** Operators can create multiple distinct newsletters (e.g. "Weekly Digest", "Breaking News"), each with its own branding, settings, and subscriber list.
**Joinery:** The mailing list model exists but is not structured around named newsletter products.
**Notes:** May be achievable by extending the existing mailing lists system with newsletter-specific metadata (header image, reply-to address, footer text).

---

### 10. Email Personalization Tokens
**Ghost:** Email body supports `{first_name}` and similar merge tags that resolve to the recipient's data at send time.
**Joinery:** No evidence of personalization tokens in email templates.
**Notes:** Would require a token-replacement pass in the email send pipeline, substituting values from the recipient's user record.

---

### 11. Content Snippets
**Ghost:** Authors can save frequently reused content blocks as named snippets and insert them into any post.
**Joinery:** Not found.
**Notes:** Could be a simple data table of named HTML/markdown snippets, surfaced as an insert option in the Trumbowyg editor.

---

### 12. Post Custom Template Assignment
**Ghost:** Each post can be assigned a specific theme template (e.g. a full-width layout, a landing page layout).
**Joinery:** All posts use the same template.
**Notes:** Requires a `pst_template` field on posts and theme template discovery logic similar to Ghost's `custom-*.hbs` convention.

---

### 13. Web-Only / Email-Only Content Blocks
**Ghost:** Individual content blocks within a post can be flagged as visible only on the web or only in the email newsletter version.
**Joinery:** Not supported — single content field, same for both channels.
**Notes:** Complex to implement cleanly; requires the editor to support block-level metadata and the email renderer to filter accordingly.

---

### 14. Post-Level Performance Analytics
**Ghost:** Each post shows its own view count, email send count, open rate, and click rate.
**Joinery:** Analytics infrastructure exists but not wired to individual posts.
**Notes:** Would require tagging `visitor_events` with a post ID and building a per-post stats aggregation query.

---

### 15. Subscriber Growth Tracking
**Ghost:** Admin dashboard shows subscriber count over time as a time-series chart.
**Joinery:** Total subscriber counts exist but no historical growth chart found.
**Notes:** Could be derived from existing user/subscriber creation timestamps with a charted aggregation query.

---

### 16. AMP Support
**Ghost:** Posts have an Accelerated Mobile Pages version for fast mobile rendering in Google Search.
**Joinery:** Not implemented.
**Notes:** Low impact given Google's reduced prioritization of AMP in recent years. Low priority.

---

## Gaps — Medium Priority (Admin & Team)

### 17. Granular Author Roles
**Ghost has five roles:**
- **Contributor** — can create/edit own drafts, cannot publish
- **Author** — can write, edit, and publish own posts
- **Editor** — can edit/publish others' posts, invite authors
- **Administrator** — full access
- **Owner** — admin + billing, cannot be deleted

**Joinery:** Binary system — permission level 5 (content editor) vs 10 (superadmin). No contributor or editor distinctions.
**Notes:** A more granular role system would benefit multi-author publications.

---

### 18. Two-Factor Authentication for Staff
**Ghost:** 2FA is enabled by default on all staff accounts, triggered on new/unrecognized devices.
**Joinery:** No 2FA system found.
**Notes:** Security feature. Could integrate TOTP (Google Authenticator-compatible) or email-based OTP.

---

### 19. Full Site Export / Backup
**Ghost:** Admin can export all content (posts, pages, members, settings) as a JSON archive.
**Joinery:** No content export tool found.
**Notes:** Useful for backup and migration. Could export posts, pages, members, and settings as JSON or CSV.

---

### 20. Admin Routes / URL Configuration
**Ghost:** Operators can upload a `routes.yaml` file in the admin to define custom URL structures and collections.
**Joinery:** Route configuration is hardcoded in `serve.php` and requires developer access to change.
**Notes:** A simplified admin UI for common routing customizations (e.g. custom blog prefix, tag URL format) would improve operator flexibility without requiring code changes.

---

## Gaps — Lower Priority (Nice-to-Have)

### 21. Tag Archive Pages
**Ghost:** `/tag/slug/` automatically generates a browsable archive of posts with that tag, with pagination.
**Joinery:** Tags exist (via groups) but no public-facing tag archive URL is wired up.
**Notes:** Requires a route in `serve.php` and a view template. Relatively straightforward.

---

### 22. Author Archive Pages
**Ghost:** `/author/slug/` automatically generates a page listing all posts by that author.
**Joinery:** Not found.
**Notes:** Similar to tag archives — needs a route and a view template filtering posts by `pst_usr_user_id`.

---

### 23. Tag-Specific and Author-Specific RSS Feeds
**Ghost:** `/tag/slug/rss/` and `/author/slug/rss/` generate per-taxonomy RSS feeds.
**Joinery:** Single site-wide RSS feed only.
**Notes:** Extends the existing RSS view to accept a filter parameter.

---

### 24. Outgoing Webhooks (Content Events)
**Ghost:** Operators can configure outgoing webhook URLs to fire on events like `post.published`, `member.created`, `member.updated`.
**Joinery:** Only inbound webhooks exist (Stripe, PayPal, Mailgun). No outgoing event webhooks.
**Notes:** Would enable Zapier/n8n-style automation. Requires an outgoing webhook configuration UI and an event dispatch system hooked into the post save and member registration flows.

---

### 25. Newsletter Signup Embed / Widget
**Ghost:** A signup form widget can be embedded on any page or external site to capture newsletter subscribers.
**Joinery:** No standalone embeddable subscriber capture widget found.
**Notes:** Could be a simple iframe or JS snippet pointing to an existing registration endpoint.

---

### 26. Per-Integration API Key Management
**Ghost:** Admin can create named integrations, each with its own Content API and Admin API key pair, for fine-grained access control.
**Joinery:** Single API key model — no per-integration key scoping.
**Notes:** Useful for multi-tool setups. Lower priority unless the API is used heavily by third parties.

---

### 27. Cloud Storage Adapter
**Ghost:** Supports Cloudinary, Amazon S3, and Cloudflare as storage backends for uploaded media.
**Joinery:** Local filesystem only.
**Notes:** Relevant if the platform needs to scale beyond a single server or serve media via CDN.

---

### 28. Privacy-First First-Party Analytics
**Ghost:** Built-in analytics are cookie-free and first-party (no third-party tracking scripts), so no cookie consent banner is needed.
**Joinery:** Session-based analytics exist but the privacy/cookie posture is not documented.
**Notes:** Increasingly important for GDPR compliance and user trust.

---

## Summary of Biggest Gaps

| # | Feature | Impact | Complexity |
|---|---|---|---|
| 1 | Gated/paywalled content | High | Medium |
| 5 | Full-text post search for readers | High | Low |
| 6 | Per-post SEO fields (title, description, OG image) | High | Low |
| 3 | Email audience segmentation by tier/label | High | Medium |
| 4 | Member import/export (CSV) | Medium | Low |
| 17 | Granular author roles | Medium | Medium |
| 24 | Outgoing webhooks (content/member events) | Medium | Medium |
| 14 | Per-post performance analytics | Medium | Medium |
| 21 | Tag archive pages | Medium | Low |
| 22 | Author archive pages | Medium | Low |
| 2 | Email-only posts | Medium | Low |
| 7 | Structured data / schema.org markup | Medium | Low |
| 18 | Two-factor authentication for staff | Medium | High |
