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

### New Field Per Entity

Add one field to each gatable entity's `$field_specifications`:

```php
// Minimum subscription tier level required to view this content.
// NULL or 0 = no tier requirement (public/free).
// Matches sbt_tier_level values (e.g., 10, 20, 30).
'{prefix}_tier_min_level' => array(
    'type' => 'int4',
    'is_nullable' => true,
    'default' => null,
),
```

### Site-Wide Preview Setting

Add a setting to `stg_settings` (via migration) to control preview behavior globally:

- **`tier_gate_preview_length`** — Number of characters of body text to show before the gate for posts, pages, and page content. `0` or empty = no preview (show gate immediately). Applies to all gated content uniformly.

Entities without body text (events, files, videos, products) have hardcoded preview behavior in the view layer — e.g., events show name/date/location, files show metadata. This setting does not affect them.

### Entities to Add Fields To

| Entity | Table | Prefix | Field to Add |
|---|---|---|---|
| Post | `pst_posts` | `pst` | `pst_tier_min_level` |
| Page | `pag_pages` | `pag` | `pag_tier_min_level` |
| Event | `evt_events` | `evt` | `evt_tier_min_level` |
| Product | `pro_products` | `pro` | `pro_tier_min_level` |
| File | `fil_files` | `fil` | `fil_tier_min_level` |
| Video | `vid_videos` | `vid` | `vid_tier_min_level` |
| Mailing List | `mlt_mailing_lists` | `mlt` | `mlt_tier_min_level` |
| Page Content | `pac_page_contents` | `pac` | `pac_tier_min_level` |
| Event Session | `evs_event_sessions` | `evs` | `evs_tier_min_level` |

**Fields are NOT added to:**
- Comments, Reactions, Entity Photos — these are child entities whose access follows their parent.
- Orders, Bookings — private user data, not public content.
- Locations — reference data, rarely gated. Can be added later if needed.

### No Migrations Needed

Per CLAUDE.md, schema changes are handled automatically by the `update_database` system from `$field_specifications`. Adding the fields to the data class is sufficient.

---

## Access Check: `authenticate_tier()`

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
 * ]
 */
public function authenticate_tier($session) {
    $prefix = static::$prefix;
    $min_level_field = $prefix . '_tier_min_level';

    $min_level = $this->get($min_level_field);

    // No tier requirement — access granted
    if ($min_level === null || $min_level <= 0) {
        return ['allowed' => true];
    }

    // Admins always see gated content
    if ($session->get_permission() >= 5) {
        return ['allowed' => true];
    }

    // Check early access expiry — if the delay has elapsed, content is now public
    $delay_field = $prefix . '_tier_public_after_hours';
    $delay_hours = $this->get($delay_field);
    if ($delay_hours > 0) {
        $publish_time = $this->_get_publish_time();
        if ($publish_time) {
            $public_at = LibraryFunctions::time_shift($publish_time, $delay_hours . ' hours');
            if ($public_at <= gmdate('Y-m-d H:i:s')) {
                return ['allowed' => true];
            }
        }
    }

    $user_id = $session->get_user_id();

    // Not logged in
    if (!$user_id) {
        return [
            'allowed' => false,
            'reason' => 'not_logged_in',
            'required_level' => $min_level,
            'user_level' => null,
            'required_tier' => SubscriptionTier::GetByColumn('sbt_tier_level', $min_level),
            'upgrade_options' => [],
        ];
    }

    // Check tier
    if (SubscriptionTier::UserHasMinimumTier($user_id, $min_level)) {
        return ['allowed' => true];
    }

    // Access denied — insufficient tier
    $user_tier = SubscriptionTier::GetUserTier($user_id);
    return [
        'allowed' => false,
        'reason' => 'tier_too_low',
        'required_level' => $min_level,
        'user_level' => $user_tier ? $user_tier->get('sbt_tier_level') : 0,
        'required_tier' => SubscriptionTier::GetByColumn('sbt_tier_level', $min_level),
        'upgrade_options' => SubscriptionTier::getUpgradeOptions($user_id),
    ];
}

/**
 * Get the publish time for this entity, used by early access calculation.
 * Entities override this if their publish time field doesn't follow the
 * standard {prefix}_published_time naming convention.
 */
protected function _get_publish_time() {
    $prefix = static::$prefix;

    // Try {prefix}_published_time (posts, pages)
    $published_field = $prefix . '_published_time';
    if (in_array($published_field, static::$fields)) {
        return $this->get($published_field);
    }

    // Fall back to {prefix}_create_time (page content, events, etc.)
    return $this->get($prefix . '_create_time');
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
    $access = $this->authenticate_tier($session);
    if (!$access['allowed']) return false;
}
```

For entities that don't yet have `authenticate_read()` (Posts, Pages, Events), the tier check is applied at the view layer (see below). Adding `authenticate_read()` to these entities is out of scope for this spec but would be a natural follow-up.

---

## View Layer: Rendering the Gate

### Gate Prompt Component

Create a reusable component for the tier gate prompt. This renders when `authenticate_tier()` returns `allowed = false`.

**Component responsibilities:**
- Display a message explaining the content is restricted (e.g., "This content is available to [Tier Name] members and above")
- Show the user's current tier (if any) vs. the required tier
- Render upgrade CTA buttons linking to the relevant product pages
- If the user is not logged in, show login + signup CTAs instead
- Respect the site's theme (use FormWriter for buttons, theme CSS for styling)

**Placement:**
- **Posts/Pages with preview:** If `tier_gate_preview_length` is set, show the first N characters of `pst_body`/`pag_body`, then fade to the gate prompt. A CSS gradient overlay on the last visible paragraph creates the teaser effect.
- **Posts/Pages without preview:** If `tier_gate_preview_length` is 0 or empty, replace the entire body with the gate prompt. Title and featured image remain visible (they serve as the teaser).
- **Events:** Show event name, date, location, and short description. Hide registration button, full description, sessions, and attendee info. Replace with gate prompt.
- **Products:** Follow the site-wide listing strategy (lock indicator or hidden). If gated and accessed via direct URL, show gate prompt instead of purchase options.
- **Files:** Show file name and description. Replace download link with gate prompt.
- **Videos:** Show thumbnail and title. Replace player with gate prompt.
- **Page Content sections:** Replace the section body with a compact inline gate prompt. Other non-gated sections on the same page render normally.

### Example View Integration (Posts)

```php
// In views/post.php (simplified):
$access = $post->authenticate_tier($session);

if ($access['allowed']) {
    // Render full post body
    echo $post->get('pst_body');
} else {
    // Render preview if configured via site-wide setting
    $settings = Globalvars::get_instance();
    $preview_length = intval($settings->get_setting('tier_gate_preview_length'));
    if ($preview_length > 0 && $post->get('pst_body')) {
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

### Strategy A: Show with Lock Indicator

Tier-gated items appear in listings but with a visual lock indicator (icon or badge). Clicking through to the detail page shows the gate prompt. This is better for marketing — users can see what they're missing.

### Strategy B: Hide Entirely

Tier-gated items are excluded from listings for users who lack the tier.

### Configuration

A site-wide setting **`tier_gate_hide_from_listings`** (boolean, default false) controls the default behavior:

- **false (default):** Strategy A — show gated items with lock indicators in listings
- **true:** Strategy B — hide gated items from listings for users who lack the tier

Regardless of this setting, **RSS feeds** always use Strategy B — gated content in feeds defeats the purpose.

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

**Field:**
- **Minimum Tier:** Dropdown populated from `MultiSubscriptionTier` (all active tiers, sorted by level). Options: "Public (no tier required)" + each tier. Renders the tier's display name and level.

This section should appear near other visibility/publishing controls, after the "Published" checkbox and before the save button.

### Permission Text Integration

Entities that display a permission summary sentence (files, videos) should include the tier requirement. The tier clause appends to the existing sentence pattern:

- No tier: sentence unchanged (e.g., "Anyone can access this file.")
- With tier, no other restriction: "Anyone with a [Tier Name] subscription or higher can access this file."
- With tier + group: "Only logged in users in the \"Members\" group with a [Tier Name] subscription or higher can access this file."
- With tier + permission: "Minimum permission (5) with a [Tier Name] subscription or higher can access this file."

The tier clause inserts before "can access this [entity]." alongside any existing permission qualifier.

### Site Settings

Add a "Tier Gating" section to the admin settings page:

- **`tier_gate_preview_length`:** Integer input. Label: "Characters of body text to show before the paywall (0 for no preview)".
- **`tier_gate_hide_from_listings`:** Checkbox. Label: "Hide gated content from listings for users who lack the required tier". Default off. Note: RSS feeds always hide gated items regardless of this setting.

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
// Hours after publish time before the tier gate lifts and content becomes public.
// NULL or 0 = tier gate is permanent.
'{prefix}_tier_public_after_hours' => array(
    'type' => 'int4',
    'is_nullable' => true,
    'default' => null,
),
```

Add to: Posts, Pages, Events (the entities where early access makes sense).

### Logic

Handled inside `authenticate_tier()` (see above). The method calls `_get_publish_time()` to resolve the entity's publish time, then computes `publish_time + delay_hours` and compares against now. No second timestamp field needed — computed on the fly, so no sync issues if publish time changes.

### Publish Time Resolution

The `_get_publish_time()` helper resolves the correct field per entity:
- **Posts:** `pst_published_time`
- **Pages:** `pag_published_time`
- **Events:** falls back to `evt_create_time` (events don't have a published_time field)
- **Page Content:** falls back to `pac_create_time`

Entities can override `_get_publish_time()` if they need custom logic (e.g., events could use `evt_start_time` instead).

### Multi-Class Filtering

For listing queries that need to identify currently-public posts (early access expired), use SQL:

```sql
WHERE published_time + (tier_public_after_hours * interval '1 hour') <= now()
```

### Admin UI

On the post/page/event edit page, when a tier is selected, show an additional dropdown:
- **Make public after:** Dropdown with presets: Never, 1 hour, 3 hours, 12 hours, 1 day, 3 days, 7 days, 14 days, 30 days, 90 days. Displayed only when a tier is selected.

---

## Interaction with Existing Access Controls

Tier gating is **additive** — it stacks with all existing access checks. The most restrictive rule wins.

**Admin bypass:** Users with permission level 5 or higher always pass the tier check. This ensures admins can preview, edit, and manage gated content without needing a tier subscription.

| Existing Check | Tier Check | Result |
|---|---|---|
| `evt_visibility = PRIVATE` | Any | Hidden (visibility check denies first) |
| `pst_is_published = false` | Any | Hidden (publication check denies first) |
| `fil_min_permission = 5` | `fil_tier_min_level = 20` | Must be admin (perm 5+); tier check bypassed by admin permission |
| `fil_grp_group_id = 7` | `fil_tier_min_level = 10` | Must be in group 7 AND tier 10+ |
| No restrictions | `pst_tier_min_level = 20` | Must be tier 20+ |
| Admin (permission 5+) | Any | Allowed (admin bypass in `authenticate_tier()`) |

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

### Step 1: Core Infrastructure
1. Add `authenticate_tier()` to `SystemBase`
2. Add `tier_gate_preview_length` and `tier_gate_hide_from_listings` site-wide settings (via migration)
3. Create the gate prompt component

### Step 2: Data Model Fields
Add `{prefix}_tier_min_level` to all gatable entities:
4. `posts_class.php` — also add `pst_tier_public_after_hours`
5. `pages_class.php` — also add `pag_tier_public_after_hours`
6. `page_contents_class.php`
7. `events_class.php` — also add `evt_tier_public_after_hours`
8. `files_class.php` — add tier check to existing `authenticate_read()`
9. `videos_class.php` — add tier check to existing `authenticate_read()`
10. `products_class.php`
11. `mailing_lists_class.php`
12. `event_sessions_class.php`

### Step 3: Views and Listings
13. Update `views/post.php` (and theme variants) — gate or preview
14. Update blog listing view — lock indicators on gated posts
15. Update RSS feed — exclude or truncate gated posts
16. Update page views — per-section gating (some sections gated, others free on the same page)
17. Update event detail view — show basic info but gate registration, session details, and full description
18. Update event listing view — lock indicators
19. Update file/video views — gate download/playback via `authenticate_read()`
20. Update product listings and detail page — follow site-wide listing strategy; gate on detail page

### Step 4: Admin UI
21. Add tier dropdown to all entity admin edit pages
22. Add early access dropdown to post, page, and event edit pages
23. Add bulk tier assignment to post, event, and page listing pages
24. Add gated content counts to tier admin page
25. Add preview length setting to admin settings page

---

## Developer Guide Addition

After implementation, add a section to `/docs/subscription_tiers.md` documenting:

- How to make any new entity tier-gatable (add the fields, call `authenticate_tier()` in the view)
- How plugin developers can use tier gating in plugin views
- The gate prompt component API
- How tier gating interacts with existing access controls
