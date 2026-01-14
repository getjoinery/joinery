<?php
/**
 * ConsentHelper - Cookie consent management for GDPR/CCPA compliance
 *
 * Provides a minimal-friction cookie consent system that enables compliance
 * with major privacy regulations while minimizing disruption to users.
 *
 * @version 1.0
 */

class ConsentHelper {
    private static $instance = null;
    private $consent = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor - use get_instance()
     */
    private function __construct() {
        $this->loadConsent();
    }

    /**
     * Load consent from cookie
     */
    private function loadConsent() {
        if (isset($_COOKIE['joinery_cookie_consent'])) {
            $this->consent = json_decode($_COOKIE['joinery_cookie_consent'], true);
        }
    }

    /**
     * Check if cookie consent system is enabled
     *
     * @return bool True if consent system is active
     */
    public function isEnabled() {
        $mode = $this->getMode();
        return $mode !== 'off' && $mode !== '';
    }

    /**
     * Check if analytics cookies are allowed
     *
     * @return bool True if analytics tracking is permitted
     */
    public function allowsAnalytics() {
        if (!$this->isEnabled()) return true; // If system disabled, allow all

        $mode = $this->getMode();
        if ($mode === 'ccpa') return true; // CCPA allows by default

        return isset($this->consent['a']) && $this->consent['a'] == 1;
    }

    /**
     * Check if marketing cookies are allowed
     *
     * @return bool True if marketing tracking is permitted
     */
    public function allowsMarketing() {
        if (!$this->isEnabled()) return true;

        $mode = $this->getMode();
        if ($mode === 'ccpa') return true;

        return isset($this->consent['m']) && $this->consent['m'] == 1;
    }

    /**
     * Get current consent mode based on settings and geo
     *
     * @return string 'off', 'gdpr', 'ccpa', or 'auto'
     */
    public function getMode() {
        $settings = Globalvars::get_instance();
        $mode = $settings->get_setting('cookie_consent_mode');

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
     * Uses session cache to avoid repeated lookups
     *
     * @return string 'gdpr', 'ccpa', or 'off'
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
            $gdpr_countries = [
                'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR',
                'DE','GR','HU','IE','IT','LV','LT','LU','MT','NL',
                'PL','PT','RO','SK','SI','ES','SE','GB','IS','LI','NO'
            ];

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
     *
     * @return array Configuration data for JS
     */
    public function getJsConfig() {
        $settings = Globalvars::get_instance();

        $privacyPath = trim($settings->get_setting('cookie_privacy_policy_link'));
        $privacyUrl = '';
        if (!empty($privacyPath)) {
            $webDir = rtrim($settings->get_setting('webDir'), '/');

            // Check if already a full URL (starts with http:// or https://)
            if (preg_match('#^https?://#i', $privacyPath)) {
                // Already a full URL - use as-is
                $privacyUrl = $privacyPath;
            } else {
                // Ensure path starts with exactly one forward slash
                $privacyPath = '/' . ltrim($privacyPath, '/');
                $privacyUrl = $webDir . $privacyPath;
            }
        }

        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->getMode(),
            'privacyPolicyUrl' => $privacyUrl,
        ];
    }

    /**
     * Check if user has already given consent
     *
     * @return bool True if consent has been recorded
     */
    public function hasConsent() {
        return $this->consent !== null;
    }

    /**
     * Render consent banner HTML, inline CSS, and inline JS
     * Everything is self-contained - no external files needed
     *
     * @return string HTML/CSS/JS for consent banner
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
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-label', 'Cookie consent');
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
        overlay.innerHTML = '<div class="joinery-cc-modal" role="dialog" aria-label="Cookie preferences">' +
            '<button class="joinery-cc-modal-close" id="joinery-cc-modal-close" aria-label="Close">&times;</button>' +
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
     * Converts regular script tags to blocked versions that only execute after consent
     *
     * @param string $code The tracking code (script tags)
     * @param string $category 'analytics' or 'marketing'
     * @return string Modified code with consent blocking
     */
    public function wrapTrackingCode($code, $category = 'analytics') {
        if (!$this->isEnabled()) return $code;

        // Convert script to blocked version using namespaced data attribute
        $code = preg_replace(
            '/<script([^>]*)>/i',
            '<script$1 type="text/plain" data-joinery-consent="' . htmlspecialchars($category) . '">',
            $code
        );

        return $code;
    }

    /**
     * Record consent for audit trail using vse_visitor_events
     *
     * @param string $visitor_id Visitor identifier
     * @param bool $analytics Analytics consent given
     * @param bool $marketing Marketing consent given
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

    /**
     * Render CCPA footer link
     * For California compliance, displays "Do Not Sell or Share My Personal Information" link
     *
     * @return string HTML for CCPA link, or empty if not applicable
     */
    public function renderCCPALink() {
        if ($this->getMode() !== 'ccpa') return '';

        return '<a href="#" onclick="window.joineryCookieManage && window.joineryCookieManage(); return false;" class="joinery-cc-ccpa-link">Do Not Sell or Share My Personal Information</a>';
    }
}
