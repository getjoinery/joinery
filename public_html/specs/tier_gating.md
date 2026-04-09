# Tier Gating — General-Purpose Content Access Control

## Overview

A system for restricting access to any entity in Joinery based on the viewer's subscription tier. When a user lacks the required tier, they see a configurable prompt to subscribe or upgrade instead of the content.

This spec is designed to be entity-agnostic: the same mechanism works for posts, pages, events, products, files, videos, mailing lists, page content sections, and plugin entities.

---

## Design Principles

1. **Use existing infrastructure.** The subscription tier system (`SubscriptionTier::UserHasMinimumTier()`, `SubscriptionTier::GetUserTier()`) and group membership system are already in place. Tier gating plugs into them — it doesn't replace them.

2. **Per-entity fields, not a polymorphic table.** Joinery's existing access control patterns (`fil_min_permission`, `fil_grp_group_id`, `evt_visibility`) use per-entity fields. Tier gating follows the same pattern. This avoids joins, is simpler to query, and is consistent with how the codebase already works.

3. **Tier levels are hierarchical.** A user at tier level 30 can see everything a tier level 20 user can see. A single `min_tier_level` integer is sufficient — no need for many-to-many entity-to-tier mappings.

4. **Complement, don't replace.** Tier gating adds a new check alongside existing access controls (permission level, group membership, visibility enum, published state). It doesn't change or remove any existing check.

5. **Deny with context.** When access is denied, the system must communicate *why* and *what to do about it* — not just return false. The view layer needs enough information to render a meaningful upgrade prompt.

---

## Data Model Changes

### New Fields Per Entity

Add two fields to each gatable entity's `$field_specifications`:

```php
// Minimum subscription tier level required to view this content.
// NULL or 0 = no tier requirement (public/free).
// Matches sbt_tier_level values (e.g., 10, 20, 30).
'{prefix}_tier_min_level' => array(
    'type' => 'int4',
    'is_nullable' => true,
    'default' => null,
),

// Optional: allow non-members to see a preview/teaser before the gate.
// NULL = no preview (show gate immediately).
// For posts/pages: number of characters of body text to show before the gate.
// For events: show event name, date, and description but hide registration and details.
// For files: show file metadata but block download.
'{prefix}_tier_preview_length' => array(
    'type' => 'int4',
    'is_nullable' => true,
    'default' => null,
),
```

### Entities to Add Fields To

| Entity | Table | Prefix | Fields to Add |
|---|---|---|---|
| Post | `pst_posts` | `pst` | `pst_tier_min_level`, `pst_tier_preview_length` |
| Page | `pag_pages` | `pag` | `pag_tier_min_level`, `pag_tier_preview_length` |
| Event | `evt_events` | `evt` | `evt_tier_min_level`, `evt_tier_preview_length` |
| Product | `pro_products` | `pro` | `pro_tier_min_level` (no preview — either visible or not) |
| File | `fil_files` | `fil` | `fil_tier_min_level` (no preview — metadata visible, download blocked) |
| Video | `vid_videos` | `vid` | `vid_tier_min_level` (no preview — thumbnail visible, playback blocked) |
| Mailing List | `mlt_mailing_lists` | `mlt` | `mlt_tier_min_level` (no preview) |
| Page Content | `pac_page_contents` | `pac` | `pac_tier_min_level`, `pac_tier_preview_length` |
| Event Session | `evs_event_sessions` | `evs` | `evs_tier_min_level` (no preview — session exists in list but content/files blocked) |

**Fields are NOT added to:**
- Comments, Reactions, Entity Photos — these are child entities whose access follows their parent.
- Orders, Bookings — private user data, not public content.
- Locations — reference data, rarely gated. Can be added later if needed.

### No Migrations Needed

Per CLAUDE.md, schema changes are handled automatically by the `update_database` system from `$field_specifications`. Adding the fields to the data class is sufficient.

---

## Access Check: `check_tier_access()`

### New Method on SystemBase

Add a method to `SystemBase` that any entity can use:

```php
/**
 * Check whether the current user has sufficient tier access to view this entity.
 *
 * @param SessionControl $session  The current session
 * @return array  [
 *     'allowed'          => bool,
 *     'reason'           => string|null ('no_tier'|'tier_too_low'|'not_logged_in'),
 *     'required_level'   => int|null,
 *     'user_level'       => int|null,
 *     'required_tier'    => SubscriptionTier|null,
 *     'upgrade_options'  => array,
 *     'preview_length'   => int|null,
 * ]
 */
public function check_tier_access($session) {
    $prefix = static::$prefix;
    $min_level_field = $prefix . '_tier_min_level';
    $preview_field = $prefix . '_tier_preview_length';

    $min_level = $this->get($min_level_field);

    // No tier requirement — access granted
    if ($min_level === null || $min_level <= 0) {
        return ['allowed' => true];
    }

    $user_id = $session->get_user_id();

    // Not logged in
    if (!$user_id) {
        return [
            'allowed' => false,
            'reason' => 'not_logged_in',
            'required_level' => $min_level,
            'user_level' => null,
            'required_tier' => self::_get_tier_by_level($min_level),
            'upgrade_options' => [],
            'preview_length' => $this->get($preview_field),
        ];
    }

    // Check tier
    if (SubscriptionTier::UserHasMinimumTier($user_id, $min_level)) {
        return ['allowed' => true];
    }

    // Access denied — insufficient tier
    return [
        'allowed' => false,
        'reason' => 'tier_too_low',
        'required_level' => $min_level,
        'user_level' => self::_get_user_tier_level($user_id),
        'required_tier' => self::_get_tier_by_level($min_level),
        'upgrade_options' => SubscriptionTier::getUpgradeOptions($user_id),
        'preview_length' => $this->get($preview_field),
    ];
}
```

The return array gives the view layer everything it needs to render the right prompt: the required tier name, the user's current tier, and upgrade options (products/links).

### Integration with Existing `authenticate_read()`

For entities that already have `authenticate_read()` (Files, Videos, Orders), add the tier check as an additional gate:

```php
// In files_class.php authenticate_read():
// After existing permission and group checks:
if ($this->get('fil_tier_min_level')) {
    $session = SessionControl::get_instance();
    $access = $this->check_tier_access($session);
    if (!$access['allowed']) return false;
}
```

For entities that don't yet have `authenticate_read()` (Posts, Pages, Events), the tier check is applied at the view layer (see below). Adding `authenticate_read()` to these entities is out of scope for this spec but would be a natural follow-up.

---

## View Layer: Rendering the Gate

### Gate Prompt Component

Create a reusable component for the tier gate prompt. This renders when `check_tier_access()` returns `allowed = false`.

**Component responsibilities:**
- Display a message explaining the content is restricted (e.g., "This content is available to [Tier Name] members and above")
- Show the user's current tier (if any) vs. the required tier
- Render upgrade CTA buttons linking to the relevant product pages
- If the user is not logged in, show login + signup CTAs instead
- Respect the site's theme (use FormWriter for buttons, theme CSS for styling)

**Placement:**
- **Posts/Pages with preview:** Show the first N characters of `pst_body`/`pag_body` (per `*_tier_preview_length`), then fade to the gate prompt. A CSS gradient overlay on the last visible paragraph creates the teaser effect.
- **Posts/Pages without preview:** Replace the entire body with the gate prompt. Title and featured image remain visible (they serve as the teaser).
- **Events:** Show event name, date, location, and short description. Hide registration button, full description, sessions, and attendee info. Replace with gate prompt.
- **Products:** Hide the product from listings entirely (see Multi-class filtering below). If accessed via direct URL, show gate prompt.
- **Files:** Show file name and description. Replace download link with gate prompt.
- **Videos:** Show thumbnail and title. Replace player with gate prompt.
- **Page Content sections:** Replace the section body with a compact inline gate prompt. Other non-gated sections on the same page render normally.

### Example View Integration (Posts)

```php
// In views/post.php (simplified):
$access = $post->check_tier_access($session);

if ($access['allowed']) {
    // Render full post body
    echo $post->get('pst_body');
} else {
    // Render preview if configured
    $preview_length = $access['preview_length'];
    if ($preview_length && $post->get('pst_body')) {
        $preview = mb_substr(strip_tags($post->get('pst_body')), 0, $preview_length);
        echo '<div class="tier-gate-preview">' . $preview . '&hellip;</div>';
    }

    // Render gate prompt component
    require_once(PathHelper::getThemeFilePath('tier_gate_prompt.php', 'components'));
    render_tier_gate_prompt($access);
}
```

---

## Multi-Class Filtering: Listings and Feeds

When rendering lists of entities (blog index, event calendar, product catalog), tier-gated items need handling. There are two strategies, configurable per listing:

### Strategy A: Show with Lock Indicator (Default)

Tier-gated items appear in listings but with a visual lock indicator (icon or badge). Clicking through to the detail page shows the gate prompt. This is better for marketing — users can see what they're missing.

### Strategy B: Hide Entirely

Tier-gated items are excluded from listings for users who lack the tier. This is appropriate for products where showing a locked item creates confusion, and for RSS feeds where gated content shouldn't appear.

### Implementation in Multi Classes

Add an optional filter to `getMultiResults()` methods:

```php
// In MultiPost::getMultiResults():
if (isset($options['max_visible_tier_level'])) {
    // Only return posts the user can see
    $filters['(pst_tier_min_level'] = '<= ' . intval($options['max_visible_tier_level']) 
        . ' OR pst_tier_min_level IS NULL)';
}
```

Callers pass the user's current tier level:

```php
$user_tier_level = 0;
$tier = SubscriptionTier::GetUserTier($session->get_user_id());
if ($tier) $user_tier_level = $tier->get('sbt_tier_level');

// Show all posts the user can access
$posts = new MultiPost(['max_visible_tier_level' => $user_tier_level]);

// Or show all posts with lock indicators (no tier filter)
$posts = new MultiPost(['include_all_tiers' => true]);
```

### RSS Feed

The RSS feed (`/views/rss20_feed.php`) should use Strategy B — exclude tier-gated posts entirely, or include only the title and a "subscribe to read" message in the description. Exposing full gated content in RSS defeats the purpose.

---

## Admin UI

### Per-Entity Edit Pages

On each entity's admin edit page, add a "Content Access" section:

**Fields:**
- **Minimum Tier:** Dropdown populated from `MultiSubscriptionTier` (all active tiers, sorted by level). Options: "Public (no tier required)" + each tier. Renders the tier's display name and level.
- **Preview Length:** Integer input (only shown for entities that support preview: posts, pages, page content). Label: "Characters visible before paywall (leave empty for no preview)".

This section should appear near other visibility/publishing controls, after the "Published" checkbox and before the save button.

### Bulk Operations

The admin post/event/page listing pages should support bulk tier assignment:
- Checkbox selection on multiple items
- "Set Tier Requirement" dropdown in the bulk actions area
- Applies the selected tier to all checked items

### Tier Admin Page Enhancement

On the tier management page (`/adm/admin_subscription_tiers.php`), add a count of gated content per tier:
- "23 posts, 5 events, 12 files require this tier or higher"
- Helps operators understand the value proposition of each tier

---

## Early Access / Timed Public Release

A common pattern (from Patreon): content is tier-gated initially but becomes public after a delay. Patrons get it first; everyone else gets it later.

### Additional Field

```php
// Timestamp when the tier gate lifts and content becomes public.
// NULL = tier gate is permanent.
'{prefix}_tier_public_at' => array(
    'type' => 'timestamp(6)',
    'is_nullable' => true,
    'default' => null,
),
```

Add to: Posts, Pages, Events (the entities where early access makes sense).

### Logic

In `check_tier_access()`:

```php
$public_at_field = $prefix . '_tier_public_at';
$public_at = $this->get($public_at_field);
if ($public_at && $public_at <= gmdate('Y-m-d H:i:s')) {
    // Tier gate has expired — content is now public
    return ['allowed' => true];
}
```

### Admin UI

On the post/page edit page, when a tier is selected, show an additional field:
- **Make public on:** Date/time picker. "Leave empty to keep this content gated permanently." Displayed only when a tier is selected.

---

## Interaction with Existing Access Controls

Tier gating is **additive** — it stacks with all existing access checks. The most restrictive rule wins.

| Existing Check | Tier Check | Result |
|---|---|---|
| `evt_visibility = PRIVATE` | Any | Hidden (visibility check denies first) |
| `pst_is_published = false` | Any | Hidden (publication check denies first) |
| `fil_min_permission = 5` | `fil_tier_min_level = 20` | Must be admin (perm 5+) AND tier 20+ |
| `fil_grp_group_id = 7` | `fil_tier_min_level = 10` | Must be in group 7 AND tier 10+ |
| No restrictions | `pst_tier_min_level = 20` | Must be tier 20+ |

The precedence is: soft delete > published state > visibility enum > permission level > group membership > tier requirement. Tier gating is the last check; if any earlier check denies access, the tier check is never reached.

---

## SEO Considerations

Tier-gated content should still be crawlable by search engines for discoverability:

- **Title and meta description** are always rendered in `<head>`, regardless of tier gate.
- **Structured data** (JSON-LD) includes the title, author, publish date, and description — but not the full body.
- The preview text (if configured) is visible to crawlers.
- No `noindex` on gated pages — the page should rank in search results and drive subscribe conversions.
- The gate prompt itself should include schema.org `isAccessibleForFree: false` markup.

---

## Implementation Order

### Phase 1: Posts (MVP)
1. Add `pst_tier_min_level`, `pst_tier_preview_length`, `pst_tier_public_at` to `posts_class.php`
2. Add `check_tier_access()` to `SystemBase`
3. Create the gate prompt component
4. Update `views/post.php` (and theme variants) to call `check_tier_access()` and render the gate or preview
5. Update admin post edit to include tier dropdown and preview length fields
6. Update blog listing view to show lock indicators on gated posts
7. Update RSS feed to exclude or truncate gated posts

### Phase 2: Pages and Page Content
8. Add fields to `pages_class.php` and `page_contents_class.php`
9. Update page views and admin edit pages
10. Per-section gating on page content (some sections gated, others free on the same page)

### Phase 3: Events
11. Add fields to `events_class.php`
12. Update event detail view — show basic info but gate registration, session details, and full description
13. Update event listing view with lock indicators

### Phase 4: Files, Videos, Products
14. Add `fil_tier_min_level` and `vid_tier_min_level` — integrate into existing `authenticate_read()` methods
15. Add `pro_tier_min_level` — hide from product listings, gate on product detail page
16. Update admin edit pages for all three

### Phase 5: Remaining Entities
17. Mailing lists, event sessions, and plugin entities as needed

---

## Developer Guide Addition

After implementation, add a section to `/docs/subscription_tiers.md` documenting:

- How to make any new entity tier-gatable (add the fields, call `check_tier_access()` in the view)
- How plugin developers can use tier gating in plugin views
- The gate prompt component API
- How tier gating interacts with existing access controls
