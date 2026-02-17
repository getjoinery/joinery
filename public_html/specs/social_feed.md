# Social Feed Plugin

Display recent social media posts on public-facing pages as a unified timeline. A thin PHP proxy keeps access tokens server-side while JavaScript handles all rendering. Supports Instagram and Facebook Pages via the same Meta App.

**Key design decisions:**
- **No database tables** — no post caching, no data models. The proxy calls the platform API on each request.
- **No dedicated admin page** — Meta App credentials go on the existing settings page. OAuth is handled by a small ajax endpoint. Everything else lives in the component config.
- **Fully self-contained component** — each instance stores its own social account connection and display settings in `pac_config`.
- **JavaScript rendering** — the component template outputs a container div and a script that fetches from the proxy and renders the grid client-side.
- **Multi-platform** — Instagram and Facebook Pages share the same Meta App, OAuth flow, and proxy endpoint. The proxy normalizes responses to a common JSON shape.

## Prerequisites

Before implementation, the site operator must:

1. **Create a Meta Developer App** at developers.facebook.com (free)
2. **Add the Instagram product** to the app (for Instagram feeds)
3. **Configure an OAuth redirect URI** in the app settings
4. **Enter the App ID and Secret** on the site's settings page

Per-component prerequisites depend on the platform:
- **Instagram:** Switch the account to Business or Creator (free, instant in Instagram settings)
- **Facebook:** The admin must be an admin of the Facebook Page they want to display

## Architecture Overview

```
Plugin: /plugins/social_feed/
  ├── plugin.json
  ├── includes/
  │   └── SocialApiClient.php              # API wrapper class (Instagram + Facebook)
  └── ajax/
      ├── social_feed_proxy.php            # Public: adds token, auto-refreshes, calls API, returns JSON
      └── social_feed_oauth.php            # Admin-only: handles OAuth redirect + callback

Component type: social_feed
  ├── /views/components/social_feed.json   # Component type definition + config schema
  └── /views/components/social_feed.php    # Component template (outputs JS)
```

### How It Works

```
Browser page load
  → Component template renders <div> + <script>
  → JavaScript fetches /ajax/social_feed_proxy?component_id=123
  → Proxy reads platform + token from component's pac_config (server-side)
  → Proxy auto-refreshes Instagram token if within 7 days of expiry
  → Proxy calls the appropriate platform API with token
  → Proxy normalizes response to common JSON format
  → Proxy returns JSON to browser (token never exposed)
  → JavaScript renders the grid
```

### Storage

| What | Where | Why |
|------|-------|-----|
| Meta App ID & Secret | `stg_settings` | One Meta App per site, shared across all feeds |
| Platform, access token, account info | `pac_config` (component) | Per-instance, allows multiple feeds with different accounts/platforms |
| Display settings (columns, post count) | `pac_config` (component) | Per-instance display preferences |
| Posts | Not stored | Fetched live from platform API via proxy |

---

## Phase 1: Settings & Component Type

### Site-Wide Settings (stg_settings)

Two settings added to the existing settings page. No dedicated admin page.

| Setting Name | Description | Example Value |
|---|---|---|
| meta_app_id | Meta App ID | 123456789 |
| meta_app_secret | Meta App Secret | abc123def456 |

**Access pattern:** `$settings->get_setting('meta_app_id')`

Added via migration following the Stripe/Mailgun pattern. Edited on the existing settings admin page.

### Component Type: `social_feed`

Registered in the component system. Each instance stores its social account connection and display preferences in `pac_config`.

**Config Schema:**
```json
{
  "fields": [
    {"name": "platform", "label": "Platform", "type": "dropinput", "options": {"instagram": "Instagram", "facebook": "Facebook Page"}, "default": "instagram"},
    {"name": "heading", "label": "Section Heading", "type": "textinput"},
    {"name": "subheading", "label": "Subheading", "type": "textinput"},
    {"name": "post_count", "label": "Number of Posts to Show", "type": "textinput", "help": "e.g., 6, 9, or 12", "default": "9"},
    {"name": "columns", "label": "Columns", "type": "dropinput", "options": {"3": "3 Columns", "4": "4 Columns"}, "default": "3"},
    {"name": "show_caption", "label": "Show Caption on Hover", "type": "checkboxinput"},
    {"name": "show_view_link", "label": "Show 'Follow' Link", "type": "checkboxinput", "default": true},
    {"name": "link_to_source", "label": "Link Posts to Source", "type": "checkboxinput", "default": true},
    {"name": "access_token", "label": "Access Token", "type": "textinput", "advanced": true, "help": "Managed automatically via OAuth — do not edit manually"},
    {"name": "account_id", "label": "Account/Page ID", "type": "textinput", "advanced": true},
    {"name": "username", "label": "Account Name", "type": "textinput", "advanced": true},
    {"name": "account_type", "label": "Account Type", "type": "textinput", "advanced": true},
    {"name": "token_expires", "label": "Token Expires", "type": "textinput", "advanced": true, "help": "Instagram only — Facebook Page tokens do not expire"},
    {"name": "token_refreshed", "label": "Token Last Refreshed", "type": "textinput", "advanced": true}
  ]
}
```

**Notes:**
- `platform` is the first field — determines which OAuth flow and API endpoint to use
- Display fields (heading through link_to_source) are always visible in the component editor
- Account/token fields are marked `"advanced": true` — hidden behind "Show advanced fields" toggle
- The OAuth flow writes to these advanced fields automatically; admins rarely need to touch them

---

## Phase 2: Social API Client

### SocialApiClient class (`includes/SocialApiClient.php`)

A standalone helper that wraps all Instagram and Facebook Graph API calls. Uses PHP's `curl` — no external libraries needed.

#### Instagram Methods

**Base URL:** `https://graph.instagram.com`

```php
class SocialApiClient {
    private $access_token;

    function __construct($access_token);

    /**
     * Get Instagram user profile info
     * GET /me?fields=id,username,account_type,media_count&access_token=...
     */
    function get_instagram_profile();

    /**
     * Get recent Instagram media
     * GET /me/media?fields=id,caption,media_type,media_url,permalink,timestamp,
     *   thumbnail_url,children{media_url,media_type}&limit={$limit}&access_token=...
     */
    function get_instagram_media($limit = 25);

    /**
     * Exchange Instagram authorization code for short-lived token
     * POST https://api.instagram.com/oauth/access_token
     */
    static function exchange_code_for_token($app_id, $app_secret, $redirect_uri, $code);

    /**
     * Exchange short-lived token for long-lived token
     * GET /access_token?grant_type=ig_exchange_token&client_secret={secret}&access_token={short_token}
     */
    static function get_long_lived_token($app_secret, $short_lived_token);

    /**
     * Refresh a long-lived Instagram token (must be done before it expires)
     * GET /refresh_access_token?grant_type=ig_refresh_token&access_token={long_token}
     */
    static function refresh_long_lived_token($long_lived_token);
```

#### Facebook Methods

**Base URL:** `https://graph.facebook.com/v21.0`

```php
    /**
     * Get Facebook Pages the user manages
     * GET /me/accounts?fields=id,name,access_token&access_token=...
     * Returns array of pages with their never-expiring Page tokens
     */
    function get_facebook_pages();

    /**
     * Get Facebook Page profile info
     * GET /{page_id}?fields=id,name,username,fan_count&access_token=...
     */
    function get_facebook_page_profile($page_id);

    /**
     * Get recent Facebook Page posts
     * GET /{page_id}/posts?fields=id,message,full_picture,permalink_url,
     *   created_time,attachments{subattachments{media,url,type}}&limit={$limit}&access_token=...
     */
    function get_facebook_posts($page_id, $limit = 25);
}
```

**Error handling:** All methods return `false` on failure and log errors via `error_log()`. The caller checks the return value.

**Platform differences:**
- Instagram uses long-lived tokens that expire after 60 days and must be refreshed
- Facebook Page tokens (obtained via `/me/accounts`) never expire — no refresh needed

---

## Phase 3: Proxy Endpoint

### `ajax/social_feed_proxy.php`

A thin PHP proxy that keeps access tokens server-side. Public visitors never see the token.

**Route:** `/ajax/social_feed_proxy?component_id={pac_page_content_id}`

**Logic:**
1. Read `component_id` from query params
2. Load the PageContent record and get its `pac_config`
3. Read `platform`, `access_token`, `token_expires`, `account_id`, and `post_count` from config
4. If no token, return JSON error: `{"error": "not_connected"}`
5. **Auto-refresh token if needed (Instagram only):**
   - If `token_expires` has already passed, return JSON error: `{"error": "token_expired"}`
   - If `token_expires` is within 7 days, refresh inline:
     1. Call `SocialApiClient::refresh_long_lived_token()`
     2. Update `access_token`, `token_expires` (now + 60 days), and `token_refreshed` in the component's `pac_config` and save
     3. Use the new token for the API call
   - Otherwise, use the existing token
   - **Facebook Page tokens never expire — skip refresh logic entirely**
6. Create `SocialApiClient` with the (possibly refreshed) token
7. Call the appropriate method based on platform:
   - **Instagram:** `get_instagram_media($limit)`
   - **Facebook:** `get_facebook_posts($account_id, $limit)`
8. **Normalize the response** to a common JSON format and return

**Normalized response format:**
```json
{
  "data": [
    {
      "id": "17841400123456",
      "text": "Post caption or message here",
      "image_url": "https://scontent-...",
      "permalink": "https://www.instagram.com/p/...",
      "timestamp": "2026-02-17T12:00:00+0000",
      "media_type": "image",
      "platform": "instagram"
    }
  ],
  "account_name": "myorgname",
  "platform": "instagram"
}
```

**Normalization mapping:**

| Normalized Field | Instagram Source | Facebook Source |
|---|---|---|
| `text` | `caption` | `message` |
| `image_url` | `media_url` (or `thumbnail_url` for VIDEO) | `full_picture` |
| `permalink` | `permalink` | `permalink_url` |
| `timestamp` | `timestamp` | `created_time` |
| `media_type` | `media_type` (IMAGE/VIDEO/CAROUSEL_ALBUM → image/video/album) | Always `image` (or detect from attachments) |
| `platform` | `"instagram"` | `"facebook"` |

**Token refresh notes (Instagram):**
- Tokens can be refreshed after 24 hours and before the 60-day expiry
- The 7-day window means any site with at least one visitor per ~53 days keeps its token alive automatically
- If the token has fully expired, the feed silently stops rendering; admin must re-authorize via OAuth

**Security:**
- No authentication required (public endpoint — returns only public social media posts)
- The component_id is validated (must be an active `social_feed` component)
- The access token is never included in the response

---

## Phase 4: OAuth Endpoint

### `ajax/social_feed_oauth.php`

Handles the OAuth flow for both Instagram and Facebook. This is the only admin-facing code in the plugin — no dedicated admin page needed.

**Route:** `/ajax/social_feed_oauth?action={authorize|callback|disconnect}`
**Permission:** Level 10 (superadmin) — checked on every request

#### Authorize (`action=authorize`)

**URL:** `/ajax/social_feed_oauth?action=authorize&component_id={pac_page_content_id}`

1. Verify the component exists and is a `social_feed` component
2. Read `platform` from the component's `pac_config`
3. Read `meta_app_id` from site settings
4. Redirect to the appropriate OAuth URL based on platform:

**Instagram:**
```
https://api.instagram.com/oauth/authorize
  ?client_id={APP_ID}
  &redirect_uri={CALLBACK_URL}
  &response_type=code
  &scope=instagram_business_basic
  &state={pac_page_content_id}:instagram
```

**Facebook:**
```
https://www.facebook.com/v21.0/dialog/oauth
  ?client_id={APP_ID}
  &redirect_uri={CALLBACK_URL}
  &response_type=code
  &scope=pages_show_list,pages_read_engagement
  &state={pac_page_content_id}:facebook
```

The `state` parameter carries both the component ID and the platform through the OAuth round-trip, formatted as `{component_id}:{platform}`.

#### Callback (`action=callback`)

**URL:** `/ajax/social_feed_oauth?action=callback&code={code}&state={component_id}:{platform}`

The OAuth provider redirects here after the user authorizes.

1. Parse `state` to get component ID and platform
2. Read `code` for the auth code

**Instagram flow:**
3. Exchange code for short-lived token via `SocialApiClient::exchange_code_for_token()`
4. Exchange short-lived token for long-lived token via `SocialApiClient::get_long_lived_token()`
5. Call `get_instagram_profile()` to fetch `username`, `user_id`, `account_type`
6. Write to `pac_config`:
   - `access_token`, `account_id` (user ID), `username`, `account_type`
   - `token_expires` (current time + 60 days)
   - `token_refreshed` (current time)

**Facebook flow:**
3. Exchange code for user access token via standard Facebook OAuth token exchange:
   `GET https://graph.facebook.com/v21.0/oauth/access_token?client_id=...&client_secret=...&redirect_uri=...&code=...`
4. Call `get_facebook_pages()` to list the user's Pages
5. Use the first Page (or let the admin pick if multiple — see note below)
6. Write to `pac_config`:
   - `access_token` (the Page token — never expires)
   - `account_id` (Page ID), `username` (Page name), `account_type` ("facebook_page")
   - `token_expires` (empty — Facebook Page tokens don't expire)
   - `token_refreshed` (current time)

7. Save the component
8. Redirect to the component editor: `/admin/admin_component_edit?pac_page_content_id={component_id}`

**Multiple Facebook Pages note:** If the user manages multiple Pages, the OAuth callback picks the first one. The admin can re-authorize if they need a different Page. A Page selection UI could be added later but is not in scope.

#### Disconnect (`action=disconnect`)

**URL:** `/ajax/social_feed_oauth?action=disconnect&component_id={pac_page_content_id}`

1. Load the component
2. Clear account fields in `pac_config`: `access_token`, `account_id`, `username`, `account_type`, `token_expires`, `token_refreshed`
3. Save the component
4. Redirect back to the component editor

**Redirect URI for Meta App:** `https://{site_domain}/ajax/social_feed_oauth?action=callback` — this must be configured in the Meta Developer App settings. One redirect URI works for both platforms.

### Connecting from the Component Editor

The component editor shows the advanced fields which include the access_token with help text. To make the OAuth flow discoverable, two approaches (pick one during implementation):

**Option A: Help text link** — The `access_token` field's help text includes a dynamic link: "To connect, [click here](/ajax/social_feed_oauth?action=authorize&component_id=X)". Requires the component to be saved first (needs a component_id).

**Option B: Status display in template** — When an admin views the page and the component has no token, a small admin-only notice renders: "Not connected. [Connect now](/ajax/social_feed_oauth?action=authorize&component_id=X)". The template checks `$session->check_permission(10)` to show this only to superadmins.

---

## Phase 5: Public Display Component

### Component Template: `views/components/social_feed.php`

The template outputs a placeholder div and a script tag. No server-side data fetching — everything happens client-side.

```php
<?php
$component_id = $component->key;
$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$post_count = intval($component_config['post_count'] ?? 9);
$columns = intval($component_config['columns'] ?? 3);
$show_caption = !empty($component_config['show_caption']);
$show_view_link = !empty($component_config['show_view_link']);
$link_to_source = !empty($component_config['link_to_source']);
$username = $component_config['username'] ?? '';
$platform = $component_config['platform'] ?? 'instagram';

// Only render if an account is connected
if (empty($component_config['access_token'])) return;

// Platform-specific follow link
$follow_url = '';
$follow_text = '';
if ($platform === 'instagram' && $username) {
    $follow_url = 'https://www.instagram.com/' . htmlspecialchars($username);
    $follow_text = 'Follow us on Instagram';
} elseif ($platform === 'facebook' && $component_config['account_id']) {
    $follow_url = 'https://www.facebook.com/' . htmlspecialchars($component_config['account_id']);
    $follow_text = 'Follow us on Facebook';
}
?>

<section class="social-feed-section" id="social-feed-<?= $component_id ?>">
  <?php if ($heading): ?>
    <div class="container"><h2><?= htmlspecialchars($heading) ?></h2></div>
  <?php endif; ?>
  <?php if ($subheading): ?>
    <div class="container"><p class="lead"><?= htmlspecialchars($subheading) ?></p></div>
  <?php endif; ?>

  <div class="container">
    <div class="row" id="social-grid-<?= $component_id ?>">
      <!-- Populated by JavaScript -->
    </div>

    <?php if ($show_view_link && $follow_url): ?>
      <div class="text-center mt-3">
        <a href="<?= $follow_url ?>"
           target="_blank" rel="noopener"><?= $follow_text ?></a>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
(function() {
  const config = {
    componentId: <?= json_encode($component_id) ?>,
    postCount: <?= $post_count ?>,
    columns: <?= $columns ?>,
    showCaption: <?= $show_caption ? 'true' : 'false' ?>,
    linkToSource: <?= $link_to_source ? 'true' : 'false' ?>
  };

  fetch('/ajax/social_feed_proxy?component_id=' + config.componentId + '&limit=' + config.postCount)
    .then(r => r.json())
    .then(data => {
      if (data.error || !data.data) return;

      const grid = document.getElementById('social-grid-' + config.componentId);
      const colClass = 'col-md-' + (12 / config.columns);

      data.data.forEach(post => {
        if (!post.image_url) return; // Skip text-only posts

        const col = document.createElement('div');
        col.className = colClass + ' mb-3';

        let html = '';
        if (config.linkToSource && post.permalink) {
          html += '<a href="' + post.permalink + '" target="_blank" rel="noopener">';
        }
        html += '<img src="' + post.image_url + '" alt="" class="img-fluid w-100" style="object-fit:cover;aspect-ratio:1" loading="lazy">';
        if (config.showCaption && post.text) {
          html += '<div class="social-feed-caption">' + post.text.substring(0, 100) + '</div>';
        }
        if (config.linkToSource && post.permalink) {
          html += '</a>';
        }

        col.innerHTML = html;
        grid.appendChild(col);
      });
    })
    .catch(() => { /* Silently fail — don't show broken feed */ });
})();
</script>
```

---

## Implementation Order

1. **Settings migration** — Insert `meta_app_id` and `meta_app_secret` into `stg_settings` (empty defaults)
2. **Component type** — Create `social_feed.json` schema and register component type
3. **API client** — `SocialApiClient.php` (Instagram + Facebook methods)
4. **Proxy endpoint** — `ajax/social_feed_proxy.php` (includes inline token auto-refresh for Instagram, response normalization)
5. **OAuth endpoint** — `ajax/social_feed_oauth.php` (authorize, callback, disconnect — both platforms)
6. **Component template** — Public-facing JS rendering with platform-aware follow links

---

## API Reference Summary

### Instagram

| Action | Endpoint | Method |
|--------|----------|--------|
| Authorize | `https://api.instagram.com/oauth/authorize?client_id=...&redirect_uri=...&response_type=code&scope=instagram_business_basic&state={component_id}:instagram` | GET (redirect) |
| Exchange code | `https://api.instagram.com/oauth/access_token` | POST |
| Long-lived token | `https://graph.instagram.com/access_token?grant_type=ig_exchange_token&client_secret=...&access_token=...` | GET |
| Refresh token | `https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=...` | GET |
| User profile | `https://graph.instagram.com/me?fields=id,username,account_type,media_count&access_token=...` | GET |
| User media | `https://graph.instagram.com/me/media?fields=id,caption,media_type,media_url,permalink,timestamp,thumbnail_url,children{media_url,media_type}&limit=25&access_token=...` | GET |

### Facebook

| Action | Endpoint | Method |
|--------|----------|--------|
| Authorize | `https://www.facebook.com/v21.0/dialog/oauth?client_id=...&redirect_uri=...&response_type=code&scope=pages_show_list,pages_read_engagement&state={component_id}:facebook` | GET (redirect) |
| Exchange code | `https://graph.facebook.com/v21.0/oauth/access_token?client_id=...&client_secret=...&redirect_uri=...&code=...` | GET |
| List user's Pages | `https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token&access_token=...` | GET |
| Page profile | `https://graph.facebook.com/v21.0/{page_id}?fields=id,name,username,fan_count&access_token=...` | GET |
| Page posts | `https://graph.facebook.com/v21.0/{page_id}/posts?fields=id,message,full_picture,permalink_url,created_time,attachments{subattachments{media,url,type}}&limit=25&access_token=...` | GET |

### Platform Comparison

| Aspect | Instagram | Facebook Page |
|--------|-----------|---------------|
| Account requirement | Business or Creator account | Admin of the Page |
| OAuth scope | `instagram_business_basic` | `pages_show_list`, `pages_read_engagement` |
| Token type | Long-lived (60-day, refreshable) | Page token (never expires) |
| Token refresh | Required before 60-day expiry | Not needed |
| Rate limit | 200 requests/hour per account | 200 requests/hour per Page |
| Post content | Images, Videos, Carousels | Text, Images, Links, Videos |

**Rate limits:** 200 requests/hour per account on both platforms. For most organization websites this is fine. If traffic exceeds this, a simple file-based cache (few lines of code) can be added to the proxy endpoint.

## Future Enhancements (Not in Scope)

- File-based proxy cache (add when/if rate limits become an issue)
- Facebook Page selection UI (when admin manages multiple Pages)
- YouTube feed integration
- Combined multi-platform unified timeline (mixing Instagram + Facebook posts chronologically)
- Instagram Stories / Reels display
- Hashtag-based feed filtering
- Server-side rendering fallback (for SEO)
