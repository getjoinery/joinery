# Spec: getjoinery Theme — Site Copy to Database

**Status:** Active  
**Created:** 2026-05-04  
**Depends on:** `specs/component_library_html5.md` (must be completed first)

---

## Goal

Move all hardcoded site-specific copy out of the getjoinery theme PHP view files into
database-backed component instances. After this work, the `getjoinery` theme contains no
brand-specific text — only structural PHP that calls `ComponentRenderer::render('slug')`. A second
deployment (e.g. getjoinery.org) points its `theme_template` at `getjoinery`, seeds its own copy
into the same slug names, and gets a fully independent site with no code changes.

---

## Prerequisite

`custom_html.php` must output raw HTML with no wrapper (per `specs/component_library_html5.md`).
All stored HTML in this spec includes its own `<section>` and container markup.

---

## Approach

Every section of every getjoinery view becomes a `ComponentRenderer::render('slug')` call. All
content is stored as `custom_html` component instances (`PageContent` rows with `pac_location_name`
as the slug and `pac_config['html']` holding the markup).

Structured component types (`marketing_hero`, `gj_feature_grid`, etc.) are **not** registered in
`com_components`. They remain as file-based templates usable programmatically but are not used by
these views. Their rendered HTML is stored verbatim in `custom_html` instances, giving admins a
single editing surface (the rich text editor) for all page content.

---

## Slug Convention

All slugs are prefixed `gj-` to namespace them away from other deployments' instances.
Format: `gj-{page}-{section}`.

---

## Instance Catalog

### `index.php`

| Slug | Contains |
|------|----------|
| `gj-home-hero` | Hero section (`<section class="hero">`) with heading, subheading, two CTA buttons |
| `gj-home-trust-badges` | Trust badges strip (shield, check, lock, download, heart icons + text) |
| `gj-home-feature-grid` | "Everything You Need / One platform, no duct tape" feature grid |
| `gj-home-comparison` | "How Joinery is different" comparison cards section |
| `gj-home-audience` | "Built for real organizations" audience grid |
| `gj-home-pricing-teaser` | Three-tier pricing teaser (Starter / Organization / Network with prices) |
| `gj-home-cta` | Bottom CTA: "Ready to own your membership platform?" |

View becomes:
```php
foreach (['gj-home-hero','gj-home-trust-badges','gj-home-feature-grid',
          'gj-home-comparison','gj-home-audience','gj-home-pricing-teaser',
          'gj-home-cta'] as $slug) {
    echo ComponentRenderer::render($slug);
}
```

---

### `about.php`

| Slug | Contains |
|------|----------|
| `gj-about-hero` | Hero: "About Joinery" / "An independent, source-available…" |
| `gj-about-project` | "The project" prose section (what Joinery does, source-available pitch) |
| `gj-about-creator` | "The creator" section (Jeremy Tunnell bio, photo placeholder) |
| `gj-about-why-solo` | "Why a solo developer?" prose section |
| `gj-about-contact` | "Get in touch" section (email + GitHub links) |
| `gj-about-cta` | Bottom CTA: "Ready to get started?" |

---

### `philosophy.php`

| Slug | Contains |
|------|----------|
| `gj-philosophy-hero` | Hero: "Why Joinery exists" / "Most membership platforms treat your members' data…" |
| `gj-philosophy-problem` | "The problem" prose section |
| `gj-philosophy-why-built` | "Why I built Joinery" prose section |
| `gj-philosophy-commitments` | "The commitments" section (6 commitments with check SVGs) |
| `gj-philosophy-business-model` | "The business model" prose section |
| `gj-philosophy-cta` | Bottom CTA: "Software that respects you" |

---

### `features.php`

The hero and CTA remain as named instances. The 9 feature showcase sections are stored as
individual instances so each can be edited independently. Each instance includes its own
`<section class="section">` or `<section class="section section-alt">` wrapper with the
alternating background baked in.

| Slug | Contains |
|------|----------|
| `gj-features-hero` | Hero: "Everything your organization needs" |
| `gj-features-member-mgmt` | Member Management showcase section (section, no alt) |
| `gj-features-events` | Events & Registration showcase section (section-alt) |
| `gj-features-payments` | Payments & E-Commerce showcase section (section) |
| `gj-features-email` | Email & Communications showcase section (section-alt) |
| `gj-features-content` | Content & Pages showcase section (section) |
| `gj-features-admin` | Admin Dashboard showcase section (section-alt) |
| `gj-features-themes` | Themes & Customization showcase section (section) |
| `gj-features-api` | API & Integrations showcase section (section-alt) |
| `gj-features-privacy` | Privacy & Data Ownership showcase section (section) |
| `gj-features-cta` | Bottom CTA: "See it in action" |

View becomes a sequential list of `render()` calls; no loop, no alternation logic in PHP.

---

### `pricing.php`

The billing toggle JS logic stays in PHP (it reads no copy — it's pure UI behavior). The content
sections are all instances.

| Slug | Contains |
|------|----------|
| `gj-pricing-hero` | Hero: "Simple, honest pricing" |
| `gj-pricing-toggle` | Billing toggle UI (Monthly/Annual toggle widget — markup only, JS inline) |
| `gj-pricing-tiers` | Full pricing grid (Starter / Organization / Network cards with all features) |
| `gj-pricing-self-host` | "Prefer to self-host?" section (DIY Install + White Glove cards) |
| `gj-pricing-commercial` | "Commercial Self-Hosting License" card section |
| `gj-pricing-cta` | Bottom CTA: "Try it free for 14 days" |

Note: `gj-pricing-tiers` contains the pricing JavaScript (billing toggle handler). Store it in the
HTML — it's tied to the pricing data, not to the theme shell.

---

### `developers.php`

| Slug | Contains |
|------|----------|
| `gj-dev-hero` | Hero: "Built by a developer, for developers" with GitHub CTA |
| `gj-dev-architecture` | "Architecture overview" — 6 arch cards (Database, Backend, Frontend, API, Plugins, Themes) |
| `gj-dev-security` | "Security" — 8 security cards |
| `gj-dev-rest-api` | REST API feature showcase with code block |
| `gj-dev-plugins` | Plugin System feature showcase with directory tree code block |
| `gj-dev-themes` | Theme System feature showcase |
| `gj-dev-self-hosting` | "Self-hosting" — 3 cards (Requirements, Installation, Updates) |
| `gj-dev-cta` | Bottom CTA: "Explore the source" |

---

### `showcase.php`

| Slug | Contains |
|------|----------|
| `gj-showcase-hero` | Hero: "Built with Joinery" |
| `gj-showcase-scrolldaddy` | ScrollDaddy project card (image, description, feature list, button) |
| `gj-showcase-more` | "More projects coming" section with submit link |
| `gj-showcase-cta` | Bottom CTA: "Build something with Joinery" |

---

### `terms.php` and `privacy.php`

| Slug | Contains |
|------|----------|
| `gj-terms-content` | Full terms of service content (all sections) |
| `gj-privacy-content` | Full privacy policy content (all sections) |

These views become a hero (inline, no copy-to-instance needed since it's just the page title) plus
a single `render()` call for the body. Or store the hero in an instance too for full consistency.

| Slug | Contains |
|------|----------|
| `gj-terms-hero` | Hero heading for terms page |
| `gj-privacy-hero` | Hero heading for privacy page |

---

## `public_header()` Titles

The `public_header()` call in each view has a hardcoded `title` and `description` containing
"Joinery". These are meta tags, not rendered content, so they are out of scope for the component
system. They should be handled separately by pulling the brand name from a `site_name` setting
(already exists or can be added) via string substitution in each view. This is a minor change and
can be done alongside the component work or deferred.

---

## Seeder Script

Create `utils/seed_getjoinery_content.php`. This script:

1. Looks up the `com_component_id` for `custom_html` from `com_components`
2. For each slug in the catalog above, checks whether a `PageContent` row with that
   `pac_location_name` already exists
3. If it does not exist, inserts a new row with `pac_config['html']` set to the current markup
   extracted from the view file
4. Outputs a log line per slug: `[created]` or `[already exists — skipped]`
5. Is idempotent — safe to run multiple times

The script is run once during initial setup of each deployment. It is not a migration (migrations
run on every deploy; this seeder is one-time).

**Usage:**
```
php utils/seed_getjoinery_content.php
```

The seeder should be committed to source control so it ships with the theme and can be run on any
new deployment that uses the getjoinery theme.

---

## View Structure After

Each view file shrinks to roughly:

```php
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header(['title' => '...', 'description' => '...', 'showheader' => true]);

foreach ([
    'gj-page-hero',
    'gj-page-section-a',
    'gj-page-section-b',
    'gj-page-cta',
] as $slug) {
    echo ComponentRenderer::render($slug);
}

$page->public_footer();
?>
```

No content, no copy, no component config arrays. All brand-specific text lives in the database.

---

## Admin Experience

After seeding, all content is editable at `/admin/admin_page_content`. Each instance appears by
its slug and optional `pac_title`. Editors use the rich text editor to update copy. No code
deployment required for content changes.

Recommended: set `pac_title` on each instance to a human-readable label (e.g. "Home — Hero",
"Pricing — Tier Cards") so the admin list is navigable.

---

## Deployment to a Second Site

To deploy the same theme to getjoinery.org (or any second brand):

1. Deploy the codebase (theme ships with it; no code changes needed)
2. Set `theme_template = getjoinery` in that site's settings
3. Run `php utils/seed_getjoinery_content.php` — this seeds default copy
4. Edit each instance via admin to replace getjoinery.com copy with the new brand's copy

The two sites are now fully independent at the content layer. Code changes to the theme structure
ship to both; copy changes to either site do not affect the other.
