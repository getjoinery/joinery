# Canvas Theme HTML5 Conversion Spec

## Goal

Build a lightweight, dependency-free component library by converting Canvas 7 theme demo files to vanilla HTML5+CSS. Each converted file reproduces the visual appearance of the original using only semantic HTML5, a single shared CSS file, and minimal vanilla JS -- no Bootstrap, no jQuery, no Canvas CSS framework.

### Why This Is Valuable

The originals require ~780KB of CSS (3 files) and ~8MB of JS (jQuery + Bootstrap + 50+ plugins). Our conversions achieve the same visual result with 53KB of CSS and 7KB of JS -- an ~89% reduction in total page weight with zero dependencies.

| Metric | Original Canvas 7 | Our Conversion | Reduction |
|--------|-------------------|----------------|-----------|
| CSS payload | 780 KB (3 files) | 53 KB (1 file) | 93% |
| JS payload | ~8 MB (50+ files) | 7 KB (1 file) | 99.9% |
| Avg HTML per page | ~100 KB | ~23 KB | 77% |
| Dependencies | jQuery, Bootstrap, 50+ plugins | Zero | -- |

### Relationship to Joinery Platform

This conversion is **independent** of the Joinery platform. The base view framework-agnostic fix (see `specs/implemented/base_css_framework_agnostic_views.md`) was solved separately with `base.css` + `base.js`. This conversion serves as a standalone vanilla CSS/JS component reference that could inform future theme development.

## Source & Destination

- **Source:** `/home/user1/theme-sources/canvas/Canvas 7 Files/` (1,394 HTML files)
- **Destination:** `/home/user1/theme-sources/canvas-html5/`
- **Browsable at:** `https://joinerytest.site/theme-sources/canvas-html5/`
- **Original for comparison:** `https://joinerytest.site/theme-sources/canvas/Canvas%207%20Files/`

## Directory Structure

```
canvas-html5/
    style.css              # Shared vanilla CSS (~1,370 lines, 53KB)
    script.js              # Shared vanilla JS (~176 lines, 7KB)
    about.html             # Template page (copy header/footer from here)
    *.html                 # 76 converted pages
    images/ -> symlink     # Points to Canvas 7 Files/images/
    demos/ -> symlink      # Points to Canvas 7 Files/demos/
```

## Current Progress: 76 Files Complete

### Foundation Files
- **`style.css`** -- ~1,370 lines of comprehensive vanilla CSS covering all major Canvas components
- **`script.js`** -- Minimal vanilla JS for interactive components (mobile menu, counters, sliders, tabs, accordions, modals, portfolio filter)
- **`images/`** -- Symlink to `Canvas 7 Files/images/`
- **`demos/`** -- Symlink to `Canvas 7 Files/demos/`

### Completed Files (76)

**Phase 1-3 (56 files):**
404.html, about.html, about-2.html, about-me.html, block-contact-1.html,
block-content-1.html, block-navigation-1.html, blog.html, blog-grid.html,
blog-masonry.html, blog-single.html, buttons.html, cart.html, checkout.html,
clients.html, columns.html, coming-soon.html, contact.html, contact-2.html,
contact-3.html, contact-5.html, counters.html, dividers.html, events.html,
faqs.html, footer-2.html, footer-3.html, footer-5.html, forms.html,
header-dark.html, header-light.html, header-transparent.html, headings.html,
icons.html, index-corporate.html, index-corporate-2.html, landing.html,
lists.html, login-register.html, notifications.html, portfolio.html,
portfolio-single.html, pricing.html, progress-bars.html, quotes.html,
search.html, sections.html, services.html, shop.html, shop-single.html,
sitemap.html, tables.html, tabs.html, team.html, testimonials.html, toggles.html

**Phase 4 (20 files):**
carousel.html, event-single.html, events-calendar.html, events-list.html,
featured-boxes.html, flip-cards.html, gallery.html, gradients.html,
image-sliders.html, index.html, page-submenu.html, parallax-elements.html,
pie-skills.html, process-steps.html, profile.html, promo-boxes.html,
shape-dividers.html, split-section.html, style-boxes.html, widgets.html

### Suggested Next Batch

Skip alternates (e.g., 404-2, 404-3) and focus on unique page types not yet covered:

- **Homepage variants:** index-blog.html, index-portfolio.html, index-shop.html, index-magazine.html
- **Contact variants:** contact-4.html, contact-6.html, contact-7.html
- **Additional unique pages:** maintenance.html, navigation.html, social-icons.html, ticker.html, labels-badges.html, media-embeds.html, hover-animations.html, offcanvas.html, side-navigation.html, scroll-elements.html
- **Form showcases:** form-elements.html, form-fields.html, conditional-form.html
- **Additional block-* pages** (select ones with unique component patterns)
- **Additional portfolio-* layout variants** (select ones with unique grid patterns)

**General rule:** Most of the 1,394 original files are near-duplicates (sidebar variants, recaptcha variants, etc.). Focus on files that introduce genuinely new visual components. ~100 total converted files would cover 95%+ of unique components.

## Components Built in style.css

- [x] CSS Reset + Custom Properties
- [x] Header/Nav (transparent, sticky, dark, light variants)
- [x] Page Hero/Title with parallax
- [x] Breadcrumbs
- [x] Grid layouts (2-col through 6-col)
- [x] Heading blocks (fancy title, bottom border)
- [x] Counter/Stats section
- [x] Team profiles with skill bars
- [x] Client logo grid
- [x] Testimonial slider
- [x] Footer (widgets, newsletter, social, copyright)
- [x] Pricing cards (3/4/5 col)
- [x] Blog listing/grid/masonry
- [x] Blog single post
- [x] Shop product cards + single
- [x] Cart + Checkout
- [x] Portfolio grid + single
- [x] Contact forms + map sections
- [x] Sidebar widgets
- [x] Tabs + Accordions/Toggles
- [x] Feature boxes/Icon boxes
- [x] Timeline + Process steps
- [x] Tables
- [x] Alerts/Notifications + Style Boxes
- [x] Modal
- [x] Progress bars (horizontal)
- [x] Social icons
- [x] Buttons (all variants)
- [x] Cards
- [x] Parallax sections
- [x] Video embed sections
- [x] Maps section
- [x] CTA banners
- [x] Login/Register forms
- [x] Search results
- [x] Pagination
- [x] FAQ accordion
- [x] 404 page
- [x] Coming soon page
- [x] Dividers, Lists, Quotes, Columns
- [x] Events listing + calendar + single
- [x] Sitemap
- [x] Sections showcase
- [x] Carousels/Sliders (vanilla JS with touch/swipe)
- [x] Flip cards (CSS 3D transforms)
- [x] Image gallery with lightbox
- [x] Pie/circular skill charts (SVG)
- [x] Shape dividers (inline SVG)
- [x] Split sections (50/50 layout)
- [x] Promo boxes / CTA boxes
- [x] Gradient showcases
- [x] Page sub-menus (sticky secondary nav)
- [x] Profile/user pages
- [x] Widget collections

## Approach: Clean-Room Visual Reproduction

This is NOT a translation/porting exercise. For each file:

1. **Read the original HTML** to understand section structure (don't parse CSS classes -- just understand what sections exist)
2. **Take a browser screenshot** of the original for reference (save to `/tmp`, not `public_html`)
3. **Copy about.html** as starting template (gets you header + footer + boilerplate)
4. **Replace the `<main>` content** with appropriate sections using existing style.css classes
5. **Add `<style>` block** in `<head>` only if the page needs styles not in shared CSS
6. **Verify** with browser screenshot comparison

### What "identical" means
- Same layout, spacing, colors, typography, and visual hierarchy
- Same responsive behavior at common breakpoints (mobile, tablet, desktop)
- Images in the same positions with same sizing
- Minor differences acceptable: exact animation timing, hover micro-interactions, carousel auto-play

### Agent Conversion Method
Files can be converted efficiently using background agents. Each agent:
1. Reads the original Canvas 7 HTML file
2. Reads the shared style.css and about.html template
3. Creates a new vanilla HTML5 file with header/footer from about.html
4. Adds page-specific content and styles
5. Sets permissions to 666

20 files can run in parallel in ~3-5 minutes. Use `general-purpose` agent type.

## CSS Architecture

### CSS Custom Properties
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

### Key CSS Classes
- **Layout:** `.container`, `.grid-2` through `.grid-6`, `.gap-sm`/`.gap-md`/`.gap-lg`
- **Sections:** `.content-section`, `.section-dark`, `.section-muted`
- **Page hero:** `.page-hero`, `.page-hero-bg`, `.page-hero-row`, `.breadcrumb`
- **Header:** `.site-header`, `.header-inner`, `.header-dark`, `.header-light`

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

## Common Pitfalls

- **Don't create new shared CSS classes** unless truly needed -- style.css covers most components. Use page-specific `<style>` blocks instead.
- **Header/footer are always identical** -- copy verbatim from about.html
- **Header variants**: header-dark.html uses `.header-dark`, header-light.html uses `.header-light`, header-transparent.html uses default `.site-header`
- **Footer variants**: footer-2.html through footer-5.html show different footer layouts but reuse similar class names with page-specific style overrides
- **Team section reversed layout**: Use `.team-section.reversed` class; the CSS uses `order` properties (not `direction: rtl`)
- **Image paths**: Use relative paths like `images/about/1.jpg` or `demos/...` -- both directories are symlinked
- **Screenshots**: Save to `/tmp`, NOT to `public_html`

## Quality Criteria

1. **Visual fidelity** -- side-by-side screenshot comparison passes
2. **No external CSS frameworks** -- zero Bootstrap, Tailwind, Foundation, etc.
3. **No jQuery** -- zero jQuery references
4. **Valid HTML5** -- passes W3C validator (major errors only; warnings OK)
5. **Semantic markup** -- proper use of HTML5 sectioning elements
6. **Simplicity** -- minimal CSS, minimal JS, no unnecessary complexity
7. **Responsive** -- works at mobile (375px), tablet (768px), desktop (1200px+)
