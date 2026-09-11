# Cookie Consent Compliance Specification

**Version:** 1.0
**Date:** 2026-01-12
**Status:** Active

## Overview

This specification defines a minimal-friction cookie consent system that enables compliance with major privacy regulations including GDPR (EU), ePrivacy Directive, CCPA/CPRA (California), and emerging US state laws while minimizing disruption to users.

## Legal Background

### Key Regulations

1. **GDPR/ePrivacy (EU/EEA/UK)** - Requires explicit opt-in consent BEFORE setting non-essential cookies
   - Fines up to €20 million or 4% of global turnover
   - Must block cookies until consent obtained
   - Must provide "Reject All" option equal to "Accept All"
   - Recent enforcement: SHEIN fined €150 million (September 2025)

2. **CCPA/CPRA (California)** - Requires opt-out mechanism (not opt-in)
   - Applies to businesses with >$26.6M revenue, or processing 100K+ CA residents' data
   - Must provide "Do Not Sell or Share My Personal Information" link
   - Penalties: $2,500-$7,500 per violation

3. **Emerging US State Laws** (Minnesota July 2025, Maryland April 2026)
   - Generally follow CCPA opt-out model
   - Extra protections for minors

### Cookie Categories

| Category | Description | Consent Required? |
|----------|-------------|-------------------|
| **Strictly Necessary** | Session management, authentication, security, shopping cart | No - exempt from consent |
| **Functional/Preferences** | Language preferences, user settings (non-essential) | Yes |
| **Analytics** | Google Analytics, internal visitor tracking | Yes |
| **Marketing/Advertising** | Facebook Pixel, ad tracking, retargeting | Yes |

## Current System Analysis

### Cookies Currently Used

| Cookie | Purpose | Category |
|--------|---------|----------|
| `PHPSESSID` | PHP session management | Strictly Necessary |
| `tt` | Remember me / persistent login | Strictly Necessary* |
| `visitor_id` | Internal visitor tracking | Analytics |

*The `tt` cookie is set only when user explicitly chooses "Remember me" - this is user-requested functionality.

### Third-Party Scripts

The system has a `tracking_code` setting that allows administrators to add custom tracking (e.g., Google Analytics, Facebook Pixel). These require consent before loading.

## Implementation Design

### Design Principles

1. **Minimal Friction** - Banner should not block content; users can continue browsing
2. **Geographic Targeting** - Only show consent UI to users in jurisdictions requiring it
3. **Cookie-less by Default** - Non-essential cookies blocked until consent given (for GDPR regions)
4. **Clear & Simple** - Plain language, no legal jargon, easy choices
5. **Reversible** - Users can change preferences at any time

### Settings

Add the following settings to `stg_settings`:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `cookie_consent_mode` | varchar | 'off' | 'off', 'gdpr' (opt-in), 'ccpa' (opt-out), 'auto' (geo-based) |
| `cookie_privacy_policy_link` | varchar | '' | Link to privacy policy page |

### Consent Modes

1. **GDPR Mode (opt-in)** - Default for EU/EEA/UK visitors
   - Shows consent banner on first visit
   - Blocks all non-essential cookies until explicit consent
   - Provides Accept All / Reject All / Manage options
   - Stores consent in cookie for future visits

2. **CCPA Mode (opt-out)** - For California visitors
   - Allows cookies by default
   - Shows "Do Not Sell/Share" link in footer
   - No blocking banner required

3. **Auto Mode** - Recommended
   - Uses MaxMind GeoIP or similar to detect visitor location
   - Applies appropriate mode based on jurisdiction

### Database Schema

No new tables required. Consent records are stored in the existing `vse_visitor_events` table:

| Field | Usage for Consent |
|-------|-------------------|
| `vse_visitor_id` | Visitor identifier (same as consent cookie) |
| `vse_usr_user_id` | User ID if logged in |
| `vse_type` | `2` = cookie consent event (1 = page view) |
| `vse_ip` | IP address at time of consent |
| `vse_source` | JSON consent data: `{"a":1,"m":0,"v":"1"}` |
| `vse_timestamp` | When consent was given |

Add constant to VisitorEvent class:
```php
const TYPE_PAGE_VIEW = 1;
const TYPE_COOKIE_CONSENT = 2;
```

### Cookie Design

**Consent Cookie: `joinery_cookie_consent`**
- Value: JSON encoded preferences
- Example: `{"v":"1","a":1,"m":0,"t":1736697600}`
  - `v`: consent version
  - `a`: analytics consent (1=yes, 0=no)
  - `m`: marketing consent (1=yes, 0=no)
  - `t`: timestamp of consent
- Expiry: 365 days
- Category: Strictly necessary (records user preference)

### UI Components

#### 1. Consent Banner (GDPR Mode)

A non-blocking overlay at the bottom of the page:

```
┌─────────────────────────────────────────────────────────────────────────┐
│ We use cookies to improve your experience and analyze site traffic.     │
│ [Accept All]  [Reject All]  [Manage Preferences]  [Privacy Policy →]   │
└─────────────────────────────────────────────────────────────────────────┘
```

**Specifications:**
- Position: Fixed bottom-right corner
- Does NOT block page content or require dismissal
- Disappears after any choice is made
- Returns on preference icon click

#### Visual Design Principles

The banner must be **theme-agnostic** and work across all site themes without modification:

**Color Palette:**
- Background: `#f8f9fa` (light neutral gray) with subtle `rgba(0,0,0,0.05)` top border
- Text: `#212529` (near-black, high contrast)
- Buttons use neutral grays rather than theme accent colors
- No brand colors, no theme-specific styling

**Typography:**
- Inherit `font-family` from body (respects theme fonts)
- Font size: `14px` base, readable but unobtrusive
- No bold headlines - simple, understated text

**Button Styling:**
- `.cc-btn-accept` - Dark background (#343a40), white text
- `.cc-btn-secondary` - Transparent with gray border
- All buttons: 8px 16px padding, 4px radius, 14px font

**Layout:**
- Fixed position: bottom-right corner (`right: 20px; bottom: 20px`)
- Max-width: `400px`, compact card-style
- Generous padding (`16px 20px`)
- Flexbox layout, stacks on very small screens
- Box shadow: `0 2px 10px rgba(0,0,0,0.15)` for subtle elevation
- Border-radius: `8px`

**Accessibility:**
- Minimum 4.5:1 contrast ratio for all text
- Focus states visible for keyboard navigation
- `aria-label` and `role="dialog"` for screen readers

**CSS Isolation:**
- All styles scoped with `.joinery-cc-*` prefix (joinery cookie-consent)
- All HTML IDs prefixed with `joinery-cc-*`
- Cookie named `joinery_cookie_consent` to avoid collisions
- Rendered inline in `<style>` tag to avoid extra HTTP request
- No dependency on Bootstrap, Tailwind, or theme CSS
- No FOUC since styles are inline with banner

#### 2. Preferences Modal

When "Manage Preferences" is clicked:

```
┌──────────────────────────────────────────────────────────────────┐
│                    Cookie Preferences                        [X] │
├──────────────────────────────────────────────────────────────────┤
│ Necessary Cookies                                    [Always On] │
│ Required for the website to function properly.                   │
│ Includes session management and security features.              │
├──────────────────────────────────────────────────────────────────┤
│ Analytics Cookies                                         [ OFF] │
│ Help us understand how visitors use our site.                    │
├──────────────────────────────────────────────────────────────────┤
│ Marketing Cookies                                         [ OFF] │
│ Used to deliver relevant advertisements.                         │
├──────────────────────────────────────────────────────────────────┤
│              [Save Preferences]  [Accept All]                    │
└──────────────────────────────────────────────────────────────────┘
```

**Modal Styling:**
- Centered overlay with `rgba(0,0,0,0.5)` backdrop
- White background (`#ffffff`), `border-radius: 8px`
- Max-width: `480px`, responsive on mobile
- Same neutral button styling as banner
- Toggle switches use simple gray/dark-gray states (no colored toggles)

#### 3. CCPA Footer Link

For California compliance, add to footer:
```
Do Not Sell or Share My Personal Information
```

#### 4. Persistent Preferences Access

Small floating icon or footer link for returning users to modify preferences.

### JavaScript Implementation

The JavaScript is fully inlined in `ConsentHelper::renderConsentBanner()` above. No separate JS file needed.

Key features:
- Self-executing anonymous function (no global namespace pollution)
- Cookie name: `joinery_cookie_consent`
- All CSS classes prefixed: `.joinery-cc-*`
- All HTML IDs prefixed: `joinery-cc-*`
- Script blocking attribute: `data-joinery-consent="analytics|marketing"`
- Server-side consent recording via AJAX to `/ajax/cookie_consent`

### PHP Integration

#### ConsentHelper Class

```php
// /includes/ConsentHelper.php

class ConsentHelper {
    private static $instance = null;
    private $consent = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->loadConsent();
    }

    private function loadConsent() {
        if (isset($_COOKIE['joinery_cookie_consent'])) {
            $this->consent = json_decode($_COOKIE['joinery_cookie_consent'], true);
        }
    }

    /**
     * Check if cookie consent system is enabled
     */
    public function isEnabled() {
        $mode = $this->getMode();
        return $mode !== 'off' && $mode !== '';
    }

    /**
     * Check if analytics cookies are allowed
     */
    public function allowsAnalytics() {
        if (!$this->isEnabled()) return true; // If system disabled, allow all

        $mode = $this->getMode();
        if ($mode === 'ccpa') return true; // CCPA allows by default

        return isset($this->consent['a']) && $this->consent['a'] == 1;
    }

    /**
     * Check if marketing cookies are allowed
     */
    public function allowsMarketing() {
        if (!$this->isEnabled()) return true;

        $mode = $this->getMode();
        if ($mode === 'ccpa') return true;

        return isset($this->consent['m']) && $this->consent['m'] == 1;
    }

    /**
     * Get current consent mode based on settings and geo
     */
    public function getMode() {
        $settings = Globalvars::get_instance();
        $mode = $settings->get_setting('cookie_consent_mode', false, true);

        if (empty($mode) || $mode === 'off') {
            return 'off';
        }

        if ($mode === 'auto') {
            return $this->detectModeByGeo();
        }

        return $mode;
    }

    /**
     * Auto-detect mode based on visitor location
     */
    private function detectModeByGeo() {
        // Check session cache first
        if (isset($_SESSION['consent_mode'])) {
            return $_SESSION['consent_mode'];
        }

        $session = SessionControl::get_instance();
        $location = $session->get_location_data();

        $mode = 'gdpr'; // Default to strictest

        if ($location && isset($location['country_code'])) {
            $country = strtoupper($location['country_code']);

            // EU/EEA countries require GDPR
            $gdpr_countries = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR',
                              'DE','GR','HU','IE','IT','LV','LT','LU','MT','NL',
                              'PL','PT','RO','SK','SI','ES','SE','GB','IS','LI','NO'];

            if (in_array($country, $gdpr_countries)) {
                $mode = 'gdpr';
            } elseif ($country === 'US') {
                // Check state for CCPA
                if (isset($location['region']) && $location['region'] === 'CA') {
                    $mode = 'ccpa';
                } else {
                    $mode = 'off'; // No consent required
                }
            } else {
                $mode = 'off';
            }
        }

        $_SESSION['consent_mode'] = $mode;
        return $mode;
    }

    /**
     * Get JavaScript config for consent banner
     */
    public function getJsConfig() {
        $settings = Globalvars::get_instance();

        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->getMode(),
            'privacyPolicyUrl' => $settings->get_setting('cookie_privacy_policy_link') ?: '',
        ];
    }

    /**
     * Render consent banner HTML, inline CSS, and inline JS
     * Everything is self-contained - no external files needed
     */
    public function renderConsentBanner() {
        if (!$this->isEnabled()) return '';

        $config = $this->getJsConfig();
        if ($config['mode'] === 'off') return '';

        $configJson = json_encode($config);

        return <<<HTML
<style>
.joinery-cc-banner{position:fixed;bottom:20px;right:20px;max-width:400px;background:#f8f9fa;border:1px solid rgba(0,0,0,0.1);border-radius:8px;padding:16px 20px;box-shadow:0 2px 10px rgba(0,0,0,0.15);font-family:inherit;font-size:14px;color:#212529;z-index:9999}
.joinery-cc-banner p{margin:0 0 12px 0;line-height:1.5}
.joinery-cc-buttons{display:flex;flex-wrap:wrap;gap:8px}
.joinery-cc-btn{padding:8px 16px;border-radius:4px;font-size:14px;cursor:pointer;border:none;text-decoration:none}
.joinery-cc-btn-accept{background:#343a40;color:#fff}
.joinery-cc-btn-accept:hover{background:#23272b}
.joinery-cc-btn-secondary{background:transparent;color:#495057;border:1px solid #adb5bd}
.joinery-cc-btn-secondary:hover{background:#e9ecef}
.joinery-cc-link{color:#495057;font-size:13px;text-decoration:underline;margin-left:auto;align-self:center}
.joinery-cc-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center}
.joinery-cc-modal{background:#fff;border-radius:8px;max-width:480px;width:90%;max-height:90vh;overflow-y:auto;padding:24px}
.joinery-cc-modal h3{margin:0 0 16px 0;font-size:18px;color:#212529}
.joinery-cc-modal-close{float:right;background:none;border:none;font-size:20px;cursor:pointer;color:#6c757d}
.joinery-cc-category{padding:16px 0;border-bottom:1px solid #dee2e6}
.joinery-cc-category:last-of-type{border-bottom:none}
.joinery-cc-category-header{display:flex;justify-content:space-between;align-items:center}
.joinery-cc-category-name{font-weight:600;color:#212529}
.joinery-cc-category-desc{margin:8px 0 0 0;font-size:13px;color:#6c757d}
.joinery-cc-toggle{position:relative;width:44px;height:24px}
.joinery-cc-toggle input{opacity:0;width:0;height:0}
.joinery-cc-toggle-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#adb5bd;border-radius:24px;transition:.2s}
.joinery-cc-toggle-slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.joinery-cc-toggle input:checked+.joinery-cc-toggle-slider{background:#343a40}
.joinery-cc-toggle input:checked+.joinery-cc-toggle-slider:before{transform:translateX(20px)}
.joinery-cc-toggle input:disabled+.joinery-cc-toggle-slider{background:#6c757d;cursor:not-allowed}
.joinery-cc-modal-buttons{margin-top:20px;display:flex;gap:8px;justify-content:flex-end}
.joinery-cc-hidden{display:none!important}
</style>
<script>
(function(){
    var config = {$configJson};
    var consent = null;

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function loadConsent() {
        var c = getCookie('joinery_cookie_consent');
        if (!c) return null;
        try { return JSON.parse(c); } catch(e) { return null; }
    }

    function saveConsent(analytics, marketing) {
        var data = { v:'1', a:analytics?1:0, m:marketing?1:0, t:Math.floor(Date.now()/1000) };
        setCookie('joinery_cookie_consent', JSON.stringify(data), 365);
        consent = data;
        recordConsent(data);
        updateScripts();
    }

    function recordConsent(data) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/ajax/cookie_consent', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('a=' + data.a + '&m=' + data.m + '&v=' + data.v);
    }

    function updateScripts() {
        document.querySelectorAll('script[data-joinery-consent]').forEach(function(script) {
            var cat = script.getAttribute('data-joinery-consent');
            var allow = (cat === 'analytics' && consent && consent.a) || (cat === 'marketing' && consent && consent.m);
            if (allow && script.type === 'text/plain') {
                var newScript = document.createElement('script');
                if (script.src) newScript.src = script.src;
                else newScript.innerHTML = script.innerHTML;
                script.parentNode.replaceChild(newScript, script);
            }
        });
    }

    function showBanner() {
        var banner = document.createElement('div');
        banner.className = 'joinery-cc-banner';
        banner.id = 'joinery-cc-banner';
        banner.innerHTML = '<p>We use cookies to improve your experience and analyze site traffic.</p>' +
            '<div class="joinery-cc-buttons">' +
            '<button class="joinery-cc-btn joinery-cc-btn-accept" id="joinery-cc-accept">Accept All</button>' +
            '<button class="joinery-cc-btn joinery-cc-btn-secondary" id="joinery-cc-reject">Reject All</button>' +
            '<button class="joinery-cc-btn joinery-cc-btn-secondary" id="joinery-cc-manage">Manage</button>' +
            (config.privacyPolicyUrl ? '<a class="joinery-cc-link" href="' + config.privacyPolicyUrl + '">Privacy Policy</a>' : '') +
            '</div>';
        document.body.appendChild(banner);

        document.getElementById('joinery-cc-accept').onclick = function() { saveConsent(true, true); hideBanner(); };
        document.getElementById('joinery-cc-reject').onclick = function() { saveConsent(false, false); hideBanner(); };
        document.getElementById('joinery-cc-manage').onclick = function() { showModal(); };
    }

    function hideBanner() {
        var banner = document.getElementById('joinery-cc-banner');
        if (banner) banner.remove();
    }

    function showModal() {
        var overlay = document.createElement('div');
        overlay.className = 'joinery-cc-modal-overlay';
        overlay.id = 'joinery-cc-modal-overlay';
        overlay.innerHTML = '<div class="joinery-cc-modal">' +
            '<button class="joinery-cc-modal-close" id="joinery-cc-modal-close">&times;</button>' +
            '<h3>Cookie Preferences</h3>' +
            '<div class="joinery-cc-category"><div class="joinery-cc-category-header"><span class="joinery-cc-category-name">Necessary</span>' +
            '<label class="joinery-cc-toggle"><input type="checkbox" checked disabled><span class="joinery-cc-toggle-slider"></span></label></div>' +
            '<p class="joinery-cc-category-desc">Required for the website to function. Cannot be disabled.</p></div>' +
            '<div class="joinery-cc-category"><div class="joinery-cc-category-header"><span class="joinery-cc-category-name">Analytics</span>' +
            '<label class="joinery-cc-toggle"><input type="checkbox" id="joinery-cc-analytics"><span class="joinery-cc-toggle-slider"></span></label></div>' +
            '<p class="joinery-cc-category-desc">Help us understand how visitors use our site.</p></div>' +
            '<div class="joinery-cc-category"><div class="joinery-cc-category-header"><span class="joinery-cc-category-name">Marketing</span>' +
            '<label class="joinery-cc-toggle"><input type="checkbox" id="joinery-cc-marketing"><span class="joinery-cc-toggle-slider"></span></label></div>' +
            '<p class="joinery-cc-category-desc">Used to deliver relevant advertisements.</p></div>' +
            '<div class="joinery-cc-modal-buttons">' +
            '<button class="joinery-cc-btn joinery-cc-btn-secondary" id="joinery-cc-save">Save Preferences</button>' +
            '<button class="joinery-cc-btn joinery-cc-btn-accept" id="joinery-cc-accept-all">Accept All</button></div></div>';
        document.body.appendChild(overlay);

        document.getElementById('joinery-cc-modal-close').onclick = hideModal;
        document.getElementById('joinery-cc-save').onclick = function() {
            saveConsent(document.getElementById('joinery-cc-analytics').checked, document.getElementById('joinery-cc-marketing').checked);
            hideModal(); hideBanner();
        };
        document.getElementById('joinery-cc-accept-all').onclick = function() { saveConsent(true, true); hideModal(); hideBanner(); };
        overlay.onclick = function(e) { if (e.target === overlay) hideModal(); };
    }

    function hideModal() {
        var modal = document.getElementById('joinery-cc-modal-overlay');
        if (modal) modal.remove();
    }

    // Initialize
    consent = loadConsent();
    if (config.mode === 'gdpr' && !consent) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showBanner);
        } else {
            showBanner();
        }
    } else if (consent) {
        updateScripts();
    }
})();
</script>
HTML;
    }

    /**
     * Wrap tracking code to respect consent
     */
    public function wrapTrackingCode($code, $category = 'analytics') {
        if (!$this->isEnabled()) return $code;

        // Convert script to blocked version using namespaced data attribute
        $code = preg_replace(
            '/<script([^>]*)>/i',
            '<script$1 type="text/plain" data-joinery-consent="' . $category . '">',
            $code
        );

        return $code;
    }

    /**
     * Record consent for audit trail using vse_visitor_events
     */
    public function recordConsent($visitor_id, $analytics, $marketing) {
        require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

        $session = SessionControl::get_instance();

        $event = new VisitorEvent(NULL);
        $event->set('vse_visitor_id', $visitor_id);
        $event->set('vse_usr_user_id', $session->get_user_id());
        $event->set('vse_type', VisitorEvent::TYPE_COOKIE_CONSENT);
        $event->set('vse_ip', $_SERVER['REMOTE_ADDR'] ?? '');
        $event->set('vse_source', json_encode([
            'a' => $analytics ? 1 : 0,
            'm' => $marketing ? 1 : 0,
            'v' => '1'
        ]));
        $event->save();
    }
}
```

### Integration with Visitor Events

Modify `VisitorEvent::recordPageVisit()` to respect consent:

```php
public static function recordPageVisit($page) {
    try {
        // Check consent before tracking
        $consent = ConsentHelper::get_instance();
        if ($consent->isEnabled() && !$consent->allowsAnalytics()) {
            return; // Don't track without consent
        }

        // ... existing tracking code
    }
}
```

### Integration with Tracking Code Setting

Modify how `tracking_code` setting is rendered:

```php
// In PublicPageBase or similar
public function renderTrackingCode() {
    $settings = Globalvars::get_instance();
    $tracking_code = $settings->get_setting('tracking_code');

    if (empty($tracking_code)) return '';

    $consent = ConsentHelper::get_instance();
    return $consent->wrapTrackingCode($tracking_code, 'analytics');
}
```

### Admin Settings Page

Add new section to `/adm/admin_settings.php`:

```php
// Cookie Consent Section
$formwriter->section_header('Cookie Consent');

$formwriter->dropinput('cookie_consent_mode', 'Cookie consent mode', [
    'options' => [
        'Off' => 'off',
        'Auto-detect by location (Recommended)' => 'auto',
        'GDPR (Opt-in required)' => 'gdpr',
        'CCPA (Opt-out)' => 'ccpa'
    ],
    'value' => $settings->get_setting('cookie_consent_mode'),
    'help' => 'Auto detects visitor location. GDPR requires consent before setting cookies. CCPA allows opt-out.'
]);

$formwriter->textinput('cookie_privacy_policy_link', 'Privacy policy URL', [
    'value' => $settings->get_setting('cookie_privacy_policy_link'),
    'help' => 'Link to your privacy policy page'
]);
```

## File Changes Summary

### New Files

| File | Description |
|------|-------------|
| `/includes/ConsentHelper.php` | Core consent management class (includes inline CSS) |
| `/ajax/cookie_consent.php` | AJAX endpoint for recording consent |

### Modified Files

| File | Changes |
|------|---------|
| `/includes/PublicPageBase.php` | Add consent banner rendering, tracking code wrapping |
| `/data/visitor_events_class.php` | Add TYPE_COOKIE_CONSENT constant, check consent before tracking |
| `/adm/admin_settings.php` | Add consent settings section |
| `/migrations/migrations.php` | Add consent settings migrations |
| `/docs/plugin_developer_guide.md` | Add cookie consent integration note |

### Documentation Update

Add brief section to `/docs/plugin_developer_guide.md`:

```markdown
### Cookie Consent Integration

If your plugin adds analytics or marketing scripts, wrap them for consent compliance:

\`\`\`php
$consent = ConsentHelper::get_instance();
echo $consent->wrapTrackingCode('<script>...tracking code...</script>', 'analytics');
\`\`\`

Or manually add the attribute to script tags:
\`\`\`html
<script type="text/plain" data-joinery-consent="analytics">
  // This script only runs after user consents to analytics
</script>
\`\`\`

Categories: `analytics`, `marketing`
```

### Legacy Files to Remove

| File/Directory | Reason |
|----------------|--------|
| `/theme/jeremytunnell/scripts/GDPR/` | Legacy directory with unused example files |
| `/theme/jeremytunnell/assets/js/jquery.ihavecookies.js` | Unused jQuery cookie consent plugin |
| `/theme/jeremytunnell/assets/js/jquery.ihavecookies.min.js` | Minified version of unused plugin |
| `/theme/jeremytunnell/docs/README.md` | Documentation for unused ihavecookies plugin |

**Note:** The GDPR-related CSS in `/theme/jeremytunnell/assets/css/widget-styles.css` (lines 2-78) can optionally be removed, but the file contains other styles so requires careful editing rather than deletion.

**Note:** Canvas theme's built-in GDPR code (`/theme/canvas/assets/js/modules/cookies.js`, etc.) is vendor code and should be left alone.

## Implementation Phases

### Phase 1: Core Infrastructure
1. Create ConsentHelper class (includes inline CSS and JS)
2. Add TYPE_COOKIE_CONSENT constant to VisitorEvent
3. Add settings to database via migration
4. Create `/ajax/cookie_consent.php` endpoint

### Phase 2: UI Components
1. Create consent banner HTML/CSS
2. Create preferences modal
3. Add CCPA footer link component

### Phase 3: Integration
1. Integrate with PublicPageBase header/footer
2. Modify visitor_events to check consent
3. Wrap tracking_code setting output
4. Add admin settings UI

### Phase 4: Testing & Compliance
1. Test banner display in different modes
2. Verify cookie blocking works correctly
3. Test consent persistence
4. Verify audit trail recording
5. Browser testing across devices

## Compliance Checklist

### GDPR Requirements
- [ ] Consent obtained before setting non-essential cookies
- [ ] Clear "Accept All" and "Reject All" options
- [ ] Granular category-level choices available
- [ ] Easy access to modify preferences
- [ ] Consent records retained for 5 years
- [ ] Plain language descriptions

### CCPA Requirements
- [ ] "Do Not Sell or Share My Personal Information" link visible
- [ ] Opt-out mechanism functional
- [ ] No degraded service for opt-out users

## Future Enhancements

1. **Google Consent Mode v2** - Integration with Google's consent signaling
2. **IAB TCF v2.2** - Support for industry standard consent framework
3. **Consent Analytics** - Dashboard showing consent rates by region
4. **Cookie Scanner** - Automatic detection of all cookies on site
5. **Multi-language Support** - Translated consent banners

## Sources

- [GDPR Cookie Consent Requirements 2025](https://secureprivacy.ai/blog/gdpr-cookie-consent-requirements-2025)
- [Cookie Consent in 2025: New Rules](https://transcend.io/blog/2025-cookie-consent-laws)
- [CCPA Requirements 2026 Guide](https://secureprivacy.ai/blog/ccpa-requirements-2026-complete-compliance-guide)
- [Strictly Necessary Cookies Explained](https://www.cookieyes.com/blog/cookie-consent-exemption-for-strictly-necessary-cookies/)
- [Cookie Banner Best Practices](https://transcend.io/blog/cookie-banner-101)
- [US Cookie Consent Requirements](https://www.cookieyes.com/blog/us-cookie-consent-requirements/)
