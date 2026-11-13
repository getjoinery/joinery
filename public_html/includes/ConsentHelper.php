<?php
/**
 * ConsentHelper - Cookie consent management for GDPR/CCPA compliance
 *
 * Provides a minimal-friction cookie consent system that enables compliance
 * with major privacy regulations while minimizing disruption to users.
 *
 * @version 1.5 - CCPA: tracking runs until the visitor opts out (it was held back until a cookie existed), the opt-out link is printed, and an opt-out is honoured server-side
 * @version 1.4 - the banner's script is assets/js/consent.js, its settings a JSON block; the CCPA link has no inline handler
 * @version 1.3 - the banner adds room below the page, so nothing at the bottom stays under it (B5)
 * @version 1.2 - a consent record keeps no IP address
 * @version 1.1 - an unset privacy-policy link or webDir reads as empty (no trim(null) deprecation)
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
        // CCPA allows by default, until the visitor opts out.
        if ($mode === 'ccpa') return !(isset($this->consent['a']) && $this->consent['a'] == 0);

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
        if ($mode === 'ccpa') return !(isset($this->consent['m']) && $this->consent['m'] == 0);

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

        $privacyPath = trim((string)$settings->get_setting('cookie_privacy_policy_link'));
        $privacyUrl = '';
        if (!empty($privacyPath)) {
            $webDir = rtrim((string)$settings->get_setting('webDir'), '/');

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
     * Render the consent banner: its CSS, its settings as a JSON block, and
     * assets/js/consent.js
     *
     * @return string HTML/CSS/JS for consent banner
     */
    public function renderConsentBanner() {
        if (!$this->isEnabled()) return '';

        $config = $this->getJsConfig();
        if ($config['mode'] === 'off') return '';

        // Data for assets/js/consent.js, which the browser never runs as
        // script: no inline script (specs/strict_script_policy.md).
        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        $script_v = @filemtime(PathHelper::getIncludePath('assets/js/consent.js')) ?: '1';
        // CCPA owes every page a way to opt out; the banner is the one place
        // every theme already prints, so the link rides with it.
        $ccpa = $this->renderCCPALink();
        $ccpaLink = $ccpa !== '' ? '<div class="joinery-cc-ccpa">' . $ccpa . '</div>' : '';

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
.joinery-cc-ccpa{position:fixed;left:12px;bottom:8px;z-index:9998;font-size:12px}
.joinery-cc-ccpa-link{color:#495057;text-decoration:underline;background:rgba(255,255,255,.85);padding:2px 6px;border-radius:4px}
</style>
{$ccpaLink}<script type="application/json" id="joinery-consent-config">{$configJson}</script>
<script src="/assets/js/consent.js?v={$script_v}"></script>
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
            '/<script([^>]*)>/i', // jy-allow-script: a pattern, matching tracking code to hold back
            '<script$1 type="text/plain" data-joinery-consent="' . htmlspecialchars($category) . '">', // jy-allow-script: a pattern or a held-back tag, not a script the page runs
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
        $event->set('vse_source', json_encode([
            'a' => $analytics ? 1 : 0,
            'm' => $marketing ? 1 : 0,
            'v' => '1'
        ]));
        // An observation of what the visitor chose, recorded like any visitor event.
        SystemBase::server_initiated_write(function () use ($event) { $event->save(); });
    }

    /**
     * Render CCPA footer link
     * For California compliance, displays "Do Not Sell or Share My Personal Information" link
     *
     * @return string HTML for CCPA link, or empty if not applicable
     */
    public function renderCCPALink() {
        if ($this->getMode() !== 'ccpa') return '';

        // assets/js/consent.js opens the preferences when it is clicked
        return '<a href="#" data-joinery-cc-manage class="joinery-cc-ccpa-link">Do Not Sell or Share My Personal Information</a>';
    }
}
