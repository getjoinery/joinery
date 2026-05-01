# Subscription Tier System Documentation

## Overview

The subscription tier system manages user subscriptions with feature-based access control. Users get assigned to tiers by purchasing products, and each tier grants access to specific features and limits.

## How It Works

### 1. Creating Subscription Tiers (Admin)

**Navigate:** Products → Subscription Tiers → Create New Tier

**Configure the tier:**
- **Tier Name**: Internal identifier (e.g., "basic", "premium", "pro")
- **Display Name**: What users see (e.g., "Basic Plan", "Premium Member")
- **Tier Level**: Numeric hierarchy - higher numbers = better tiers (e.g., 10, 20, 30)
- **Description**: Rich text shown to users describing the tier
- **Features**: Configure limits and access for this tier (see below)
- **Active**: Toggle to enable/disable the tier

**Example Tier Setup:**
```
Tier Name: premium
Display Name: Premium Plan
Tier Level: 20
Description: Full access with up to 10 devices
Features:
  - Maximum Devices: 10
  - Custom Rules: Enabled
  - Advanced Filters: Enabled
```

### 2. Configuring Tier Features

Features are automatically discovered from JSON definition files:

**Plugin Features:** Each plugin defines its features in `/plugins/{plugin}/tier_features.json`

Example: `/plugins/controld/tier_features.json`
```json
{
  "max_devices": {
    "type": "integer",
    "label": "Maximum Devices",
    "description": "Maximum number of devices allowed for this tier",
    "default": 1,
    "min": 0,
    "max": 999
  }
}
```

When you edit a tier, the admin UI automatically shows all available features from all plugins. Simply set the values for each tier.

**Note:** Plugin features are automatically prefixed with the plugin name (e.g., `max_devices` becomes `controld_max_devices`).

### 3. Linking Tiers to Products

**Navigate:** Products → Edit Product

**Assign the tier:**
1. Select a subscription tier from the dropdown
2. Save the product

**What happens:** When a user purchases this product, they're automatically assigned to the selected tier.

### 4. User Assignment

**Automatic Assignment (Purchase):**
- User purchases a product with an assigned tier
- User is removed from their current tier (if any)
- User is assigned to the new tier
- If the new tier is lower level than current, assignment is skipped (upgrade-only)

**Manual Assignment (Admin):**
- Navigate to Subscription Tiers → View Members
- Add/remove users manually
- Useful for promotions, migrations, or support cases

### 5. Viewing Tier Members

**Navigate:** Products → Subscription Tiers → View Members

**See:**
- All users in the tier
- When they were assigned
- How they got the tier (purchase, manual, etc.)
- Current subscription status

### 6. Admin Settings

Six settings control subscription management behaviors (Products → Settings):

1. **subscription_downgrades_enabled** - Allow users to downgrade to lower tiers
2. **subscription_downgrade_timing** - When downgrades take effect (`immediate` or `end_of_period`)
3. **subscription_cancellation_enabled** - Allow users to cancel subscriptions
4. **subscription_cancellation_timing** - When cancellations take effect (`immediate` or `end_of_period`)
5. **subscription_reactivation_enabled** - Allow users to reactivate cancelled subscriptions
6. **subscription_cancellation_prorate** - Issue refunds for immediate cancellations

## User Experience

Users manage their subscriptions from a dedicated subscription management page:

**View Current Tier:**
- See their current subscription tier and features
- View billing information and renewal date

**Upgrade:**
- Always available
- See higher tiers and their features
- Purchase immediately with prorated billing

**Downgrade:**
- Only if `subscription_downgrades_enabled = true`
- Takes effect based on `subscription_downgrade_timing` setting

**Cancel:**
- Only if `subscription_cancellation_enabled = true`
- Timing and refunds controlled by settings

**Reactivate:**
- Only if `subscription_reactivation_enabled = true`
- Available for cancelled subscriptions before they expire

---

## Developer Reference

### Key Files

**Models:**
- `/data/subscription_tiers_class.php` - `SubscriptionTier` and `MultiSubscriptionTier`

**Admin Pages:**
- `/adm/admin_subscription_tiers.php` - List tiers
- `/adm/admin_subscription_tier_edit.php` - Create/edit tier
- `/adm/admin_subscription_tier_members.php` - View members

**User Pages:**
- `/views/change-subscription.php` - Subscription management UI
- `/logic/change_subscription_logic.php` - Business logic

**Feature Definitions:**
- `/includes/core_tier_features.json` - Core features
- `/plugins/{plugin}/tier_features.json` - Plugin features

### Database Structure

**Main table:** `sbt_subscription_tiers`
- Links to groups system via `sbt_grp_group_id`
- Features stored as JSONB in `sbt_features`
- Integrates with products via `pro_sbt_subscription_tier_id`

### Common Code Patterns

**Check user's feature value:**
```php
// Get max devices for user (default to 1 if no tier)
$max_devices = SubscriptionTier::getUserFeature($user_id, 'controld_max_devices', 1);

// Check boolean feature
$has_premium = SubscriptionTier::getUserFeature($user_id, 'controld_advanced_filters', false);

// Use in logic
if ($device_count >= $max_devices) {
    return "You've reached your device limit. Upgrade to add more.";
}
```

**Check minimum tier level:**
```php
// Require tier level 20 or higher
if (!SubscriptionTier::UserHasMinimumTier($user_id, 20)) {
    header('Location: /upgrade-required');
    exit;
}

// Or use helper that redirects automatically:
SubscriptionTier::requireMinimumTier($user_id, 20, '/change-subscription');
```

**Get user's tier:**
```php
$tier = SubscriptionTier::GetUserTier($user_id);

if ($tier) {
    echo "Your plan: " . $tier->get('sbt_display_name');
    echo "Tier level: " . $tier->get('sbt_tier_level');
}
```

**Get available upgrades:**
```php
$upgrades = SubscriptionTier::getUpgradeOptions($user_id);

foreach ($upgrades as $option) {
    $tier = $option['tier'];
    $products = $option['products'];
    // Display upgrade cards
}
```

### Creating Plugin Features

Create `/plugins/{yourplugin}/tier_features.json`:

```json
{
  "your_feature_name": {
    "type": "integer",
    "label": "Feature Display Name",
    "description": "Help text for admins",
    "default": 1,
    "min": 0,
    "max": 100
  },
  "another_feature": {
    "type": "boolean",
    "label": "Enable Premium Feature",
    "description": "Allow access to premium features",
    "default": false
  }
}
```

**Feature types:**
- `integer` - Numeric limits (shows number input with optional min/max)
- `boolean` - True/false flags (shows checkbox)
- `string` - Text values (shows text input)

Features are automatically prefixed with plugin name to prevent collisions.

### Rolling Out a New Feature to Existing Tiers

Adding a tier-gated feature is **three** steps. Skipping any of them silently breaks the feature for the wrong audience.

1. **Schema** — declare the key in `tier_features.json` (or core `settings.json`-equivalent).
2. **Code** — gate the UI/logic via `$tier->getFeature('feature_key', $default)` or `SubscriptionTier::getUserFeature(...)`.
3. **Per-tier values** — explicitly set the flag on every existing tier row in `sbt_subscription_tiers.sbt_features` via the admin Subscription Tiers UI (`/admin/admin_subscription_tier_edit?id=N`).

The `default` in the schema is **not** a per-tier default — it's a fallback used when a tier row has no entry for the key. So if you ship a `boolean` feature with `default: false` and forget step 3, *every paying tier silently gets `false`* until an admin manually toggles it on. The feature looks broken from the user's side, but the only "bug" is missing per-tier values.

For brand-new tiers, the same rule applies — when creating a tier, set every relevant feature explicitly rather than relying on schema defaults.

### Important: MultiGroup Filter Keys

When querying for groups, use the correct option keys:

```php
// ❌ WRONG - Uses column names directly
$groups = new MultiGroup([
    'grp_name' => 'Basic Plan',
    'grp_category' => 'subscription_tier'
]);

// ✅ CORRECT - Uses MultiGroup option keys
$groups = new MultiGroup([
    'group_name' => 'Basic Plan',
    'category' => 'subscription_tier'
]);
```

**MultiGroup option keys:**
- `group_name` → `grp_name`
- `category` → `grp_category`
- `group_id` → `grp_group_id`
- `user_id` → `grp_usr_user_id`
- `deleted` → `grp_delete_time` (bool)

Always check `/data/[table]_class.php` to see which option keys each Multi class accepts.

### Automatic Integration

The system automatically:
- Creates a group for each tier in `grp_groups` with `grp_category = 'subscription_tier'`
- Assigns users to tier groups when they purchase products
- Removes users from old tiers when upgrading
- Tracks all changes in the `change_tracking` table
- Prevents downgrades via purchase (upgrade-only)

### Troubleshooting

**"Tier already exists" error when creating new tier:**
- Check you're using correct MultiGroup option keys (see above)
- Verify no existing tier has that name

**Features not showing in admin UI:**
- Check JSON file exists and has valid syntax
- Ensure file is readable by web server

**User not getting tier after purchase:**
- Verify product has `pro_sbt_subscription_tier_id` set
- Check error logs for assignment failures

**Feature returning null:**
- Always provide default value in `getUserFeature()`
- Verify feature key matches JSON definition
- Check user has a tier assigned

**Feature returning the schema default (e.g. `false`) for paying customers:**
- The per-tier value was never set on `sbt_subscription_tiers.sbt_features` for that tier — the schema `default` is filling in.
- Open `/admin/admin_subscription_tier_edit?id=N` for each affected tier and toggle the feature on. See "Rolling Out a New Feature to Existing Tiers" above.

---

## Tier Gating (Content Access Control)

Tier gating restricts access to any entity based on the viewer's subscription tier. When a user lacks the required tier, they see a prompt to subscribe or upgrade.

### How It Works

Each gatable entity has a `{prefix}_tier_min_level` field. When set to a tier level value (e.g., 10, 20, 30), only users at that tier or higher can see the full content. Admins (permission 5+) always bypass the gate.

### Making an Entity Tier-Gatable

1. Add the field to `$field_specifications`:
```php
'{prefix}_tier_min_level' => array('type'=>'int4', 'is_nullable'=>true),
```

2. For early access support (optional), also add:
```php
'{prefix}_tier_public_after_hours' => array('type'=>'int4', 'is_nullable'=>true),
```

3. Call `authenticate_tier()` in the view:
```php
$session = SessionControl::get_instance();
$access = $entity->authenticate_tier($session);

if ($access['allowed']) {
    // Render full content
} else {
    require_once(PathHelper::getIncludePath('includes/tier_gate_prompt.php'));
    render_tier_gate_prompt($access);
}
```

4. For entities with `authenticate_read()` (files, videos), add inside that method:
```php
if ($this->get('{prefix}_tier_min_level')) {
    $tier_access = $this->authenticate_tier($session);
    if (!$tier_access['allowed']) return false;
}
```

5. Add a tier dropdown to the admin edit page:
```php
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
$tier_options = ['' => 'No tier required'];
$all_tiers = MultiSubscriptionTier::GetAllActive();
foreach ($all_tiers as $tier) {
    $tier_options[$tier->get('sbt_tier_level')] = $tier->get('sbt_display_name') . ' (Level ' . $tier->get('sbt_tier_level') . ')';
}
$formwriter->dropinput('{prefix}_tier_min_level', 'Minimum Tier Required', [
    'options' => $tier_options
]);
```

### Gate Prompt Component

`includes/tier_gate_prompt.php` provides two functions:

- `render_tier_gate_prompt($access, $options)` — Renders the paywall prompt. Pass `['preview_html' => $html]` in options to show a preview before the gate.
- `get_tier_gate_preview_html($body_text)` — Returns truncated preview HTML based on the `tier_gate_preview_length` setting.

### authenticate_tier() Return Value

Returns an array with:
- `allowed` (bool) — Whether access is granted
- `reason` (string|null) — `'not_logged_in'` or `'tier_too_low'`
- `required_level` (int|null) — The tier level needed
- `user_level` (int|null) — The user's current tier level
- `required_tier` (SubscriptionTier|null) — The required tier object
- `upgrade_options` (array) — Available upgrade products

### Multi-Class Filtering

Use `max_visible_tier_level` in Multi class queries to filter by user's tier:
```php
$user_tier_level = 0;
$tier = SubscriptionTier::GetUserTier($session->get_user_id());
if ($tier) $user_tier_level = $tier->get('sbt_tier_level');

$posts = new MultiPost(['published' => true, 'deleted' => false, 'max_visible_tier_level' => $user_tier_level]);
```

Supported on: `MultiPost`, `MultiEvent`, `MultiProduct`.

### Interaction with Other Access Controls

Tier gating is additive. The precedence is: soft delete > published state > visibility > permission level > group membership > tier requirement. Tier is always the last check.

### Site Settings

- `tier_gate_preview_length` — Characters of body text to show before the paywall (0 = no preview)
- `tier_gate_hide_from_listings` — Hide gated content from listings for users who lack the tier (RSS feeds always hide gated items)

### Plugin Developer Usage

Plugin developers can use tier gating in plugin views by calling `authenticate_tier()` on any entity that has the `{prefix}_tier_min_level` field. The gate prompt component renders correctly in any theme context.

---

## Related Documentation

- `/specs/subscription_tiers.md` - Full specification and implementation details
- `CLAUDE.md` - System architecture and patterns