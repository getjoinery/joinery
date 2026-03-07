# Canvas Theme HTML5 Conversion Spec

## Goal

Reproduce the visual appearance of every Canvas 7 theme file using only vanilla HTML5 and CSS. No Bootstrap, no Canvas CSS framework, no external CSS frameworks. The output should be as simple as possible while being visually identical to the originals.

**Ultimate goal:** Once Canvas files are converted, use the vanilla CSS patterns to convert Joinery's base view files (`/views/`) away from Bootstrap/Canvas CSS dependency, making the fallback view layer framework-agnostic.

## Source & Destination

- **Source:** `/home/user1/theme-sources/canvas/Canvas 7 Files/`
- **Destination:** `/home/user1/theme-sources/canvas-html5/`
- **Browsable at:** `https://joinerytest.site/theme-sources/canvas-html5/`
- **Original for comparison:** `https://joinerytest.site/theme-sources/canvas/Canvas%207%20Files/`

## Current Progress

### Completed: 55 HTML files + style.css + script.js

**Foundation files:**
- `style.css` — ~700+ lines of comprehensive vanilla CSS covering all major Canvas components
- `script.js` — Minimal vanilla JS for interactive components (mobile menu, counters, sliders, tabs, accordions, modals, portfolio filter)
- `images/` — Symlink to `Canvas 7 Files/images/`
- `demos/` — Symlink to `Canvas 7 Files/demos/`

**Completed pages:**
404.html, about-2.html, about.html, about-me.html, block-contact-1.html,
block-content-1.html, block-navigation-1.html, blog-grid.html, blog.html,
blog-masonry.html, blog-single.html, buttons.html, cart.html, checkout.html,
clients.html, columns.html, coming-soon.html, contact-2.html, contact-3.html,
contact-5.html, contact.html, counters.html, dividers.html, events.html,
faqs.html, footer-2.html, footer-3.html, footer-5.html, forms.html,
header-dark.html, header-light.html, header-transparent.html, headings.html,
icons.html, index-corporate-2.html, index-corporate.html, landing.html,
lists.html, login-register.html, notifications.html, portfolio.html,
portfolio-single.html, pricing.html, progress-bars.html, quotes.html,
search.html, sections.html, services.html, shop.html, shop-single.html,
sitemap.html, tables.html, tabs.html, team.html, testimonials.html, toggles.html

### Remaining (suggested next batch)
- index.html (homepage with hero slider)
- index-blog.html, index-portfolio.html, index-shop.html, index-magazine.html
- contact-4.html, contact-6.html, contact-7.html
- Additional header-* and footer-* variants
- Additional block-* pages
- Additional portfolio-* layout variants

### Visual Component Coverage
The 55 completed files cover well over 80% of unique visual elements. The style.css includes styles for: headers (transparent/dark/light/sticky), page heroes, breadcrumbs, grids (2-6 col), headings, counters/stats, team profiles, skill bars, client logos, testimonial sliders, footers, pricing cards, blog layouts, shop/product cards, cart/checkout, portfolio grids, contact forms, map sections, sidebar widgets, tabs, accordions/toggles, feature/icon boxes, timelines, process steps, tables, alerts/notifications, modals, progress bars, social icons, buttons, cards, parallax sections, video embeds, CTA banners, login/register forms, search results, pagination, FAQ, 404, coming soon, dividers, lists, quotes, columns, events, sitemap.

## Scope

- **1,394 HTML files** in original Canvas 7 (many are near-duplicates with minor variations)
- **~55-70 files** needed for 80%+ visual element coverage
- **Asset directories** symlinked: `images/`, `demos/`
- **Files NOT included:** `style.css`, `css/` (Canvas/Bootstrap CSS), `js/` (jQuery/Bootstrap JS), `sass/`, `dist/`

## Approach: Clean-Room Visual Reproduction

This is NOT a translation/porting exercise. For each file:

1. View the original in a browser to capture its visual appearance
2. Write new HTML5 + embedded CSS from scratch that reproduces the same visual result
3. Use semantic HTML5 elements (`header`, `nav`, `main`, `section`, `article`, `footer`, etc.)
4. All styling via the shared `style.css` + page-specific `<style>` blocks where needed
5. Verify visually using browser screenshots — output must be identical to original

### What "identical" means
- Same layout, spacing, colors, typography, and visual hierarchy
- Same responsive behavior at common breakpoints (mobile, tablet, desktop)
- Images in the same positions with same sizing
- Minor differences acceptable: exact animation timing, hover micro-interactions, carousel auto-play

## Lessons Learned & Continuation Guidance

### Header/Footer Reuse Pattern
Every page uses the **exact same** header and footer HTML. Copy from `about.html` as the template. The standard header includes:
- Transparent overlay header with logo, nav links (Home, About, Pages dropdown, Blog, Shop, Contact)
- Search icon, cart icon with badge, mobile menu toggle button
- `.site-header` with `.header-inner > .container` structure

The standard footer includes:
- 4-column widget grid (About Us, Quick Links, Recent Posts, Newsletter)
- Social links row
- Copyright bar

### CSS Architecture
- `style.css` is comprehensive — most new pages need zero or minimal page-specific `<style>` blocks
- When page-specific styles are needed, add them in a `<style>` block in the `<head>`, NOT in the shared CSS
- Grid classes: `.grid-2`, `.grid-3`, `.grid-4`, `.grid-5`, `.grid-6` with `.gap-sm`, `.gap-md`, `.gap-lg`
- Container: `.container` (max-width 1140px, centered)
- Sections: `.content-section` (standard padding), `.section-dark`, `.section-muted`

### CSS Custom Properties (Actual Values Used)
```css
:root {
    --color-primary: #1abc9c;
    --color-primary-dark: #17a88a;
    --color-dark: #333;
    --color-body: #555;
    --color-muted: #999;
    --color-light: #f8f9fa;
    --color-border: #e5e5e5;
    --font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-display: 'Playfair Display', Georgia, serif;
    --container-max: 1140px;
    --header-height: 64px;
    --footer-bg: #1a1a1a;
}
```

### JavaScript Components in script.js
- Mobile menu toggle (`.menu-toggle` / `.nav-links.open`)
- Sticky header on scroll (adds `.sticky` class after hero)
- Testimonial slider (auto-advance + dot navigation)
- Animated counters (`[data-count]` with IntersectionObserver + requestAnimationFrame)
- Skill bar animation (`.skill-fill` with IntersectionObserver)
- Tabs (`.tabs-nav` / `.tab-link` / `.tab-content`)
- Accordions (`.accordion-header` / `.accordion-item.active`)
- Alert dismiss (`.alert-close`)
- Modals (`[data-modal]` / `.modal-overlay.active`)
- Smooth scroll for anchor links
- Progress bar animation (`.progress-bar` with IntersectionObserver)
- Portfolio filter (`.portfolio-filter` with `[data-category]`)

### Conversion Process (Efficient Batch Method)
1. **Read the original HTML** to understand section structure (don't need to parse CSS classes — just understand what sections exist)
2. **Take a browser screenshot** of the original for reference
3. **Copy about.html** as starting template (gets you header + footer + boilerplate)
4. **Replace the `<main>` content** with appropriate sections using existing style.css classes
5. **Add `<style>` block** only if the page needs styles not in shared CSS
6. **Verify** with browser screenshot comparison

Most pages take 2-3 minutes once you understand the pattern. The header/footer are always identical.

### Image Paths
Images are symlinked, so paths like `images/about/1.jpg` work directly. Some pages reference `demos/` subdirectory images. Both directories are symlinked from the Canvas 7 source.

### Common Pitfalls
- **Don't create new CSS classes** unless truly needed — the shared style.css covers most components
- **Team section reversed layout**: Use `.team-section.reversed` class; the CSS uses `order` properties (not `direction: rtl`)
- **Header variants**: header-dark.html uses `.header-dark`, header-light.html uses `.header-light`, header-transparent.html uses default `.site-header`
- **Footer variants**: footer-2.html through footer-5.html show different footer layouts but reuse similar class names with page-specific style overrides

## File Processing Order

### Phase 1: Foundation (COMPLETE)
1. ~~Create `style.css`~~ Done
2. ~~Create `script.js`~~ Done
3. ~~Convert `about.html`~~ Done
4. ~~Verify visually~~ Done

### Phase 2: Core Pages (COMPLETE)
5. ~~`pricing.html`~~ Done
6. ~~`contact.html`~~ Done
7. ~~`blog.html`~~ Done
8. ~~`services.html`~~ Done

### Phase 3: Component & Layout Pages (COMPLETE)
All component showcase pages and layout variations listed in the completed files above.

### Phase 4: Remaining Pages (NEXT)
- `index.html` — homepage with hero slider (most complex remaining page)
- Homepage variants: index-blog, index-portfolio, index-shop, index-magazine
- Contact variants: contact-4 through contact-7
- Additional block-*, portfolio-*, header-*, footer-* variants

### Phase 5 (Future): Joinery Base View Conversion
Once Canvas conversion is complete (or sufficient), convert the Joinery platform's `/views/` files from Bootstrap/Canvas CSS to vanilla HTML5+CSS patterns established here.

## Directory Structure

```
canvas-html5/
    style.css              # Comprehensive shared vanilla CSS (~700+ lines)
    script.js              # Minimal vanilla JS (mobile menu, counters, sliders, etc.)
    about.html             # Template page (copy header/footer from here)
    *.html                 # 55 converted pages
    images/ -> symlink     # Points to Canvas 7 Files/images/
    demos/ -> symlink      # Points to Canvas 7 Files/demos/
```

## Quality Criteria

1. **Visual fidelity** — side-by-side screenshot comparison passes
2. **No external CSS frameworks** — zero Bootstrap, Tailwind, Foundation, etc.
3. **No jQuery** — zero jQuery references
4. **Valid HTML5** — passes W3C validator (major errors only; warnings OK)
5. **Semantic markup** — proper use of HTML5 sectioning elements
6. **Simplicity** — minimal CSS, minimal JS, no unnecessary complexity
7. **Responsive** — works at mobile (375px), tablet (768px), desktop (1200px+)
