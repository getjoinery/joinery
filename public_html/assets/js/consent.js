/*
 * The cookie consent banner and preferences (ConsentHelper::renderConsentBanner()).
 * Its settings are the JSON block #joinery-consent-config, written just
 * before this file. Scripts held back as <script type="text/plain"
 * data-joinery-consent="..."> are switched on once their category is allowed.
 *
 * @version 1.1 - CCPA defaults to on until opted out; the dialog shows the current choice; the policy link is built as an element
 * @version 1.0
 */
(function(){
    var cfgEl = document.getElementById('joinery-consent-config');
    var config;
    try { config = JSON.parse(cfgEl ? cfgEl.textContent : ''); } catch (e) { return; }
    if (!config) return;
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
        joineryApi.post('consent_record', { analytics: data.a === 1, marketing: data.m === 1 })
            .catch(function() {});
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
            '</div>';
        if (config.privacyPolicyUrl) {
            // Built as an element, so nothing in the address can break out of it.
            var policy = document.createElement('a');
            policy.className = 'joinery-cc-link';
            policy.href = config.privacyPolicyUrl;
            policy.textContent = 'Privacy Policy';
            banner.querySelector('.joinery-cc-buttons').appendChild(policy);
        }
        document.body.appendChild(banner);
        // The banner floats over the bottom of the page. Room is added below
        // the content so whatever sits there (a Checkout button) can be
        // scrolled clear of it instead of staying unclickable underneath.
        var body = document.body;
        body.setAttribute('data-joinery-cc-padding', body.style.paddingBottom || '');
        var current = parseFloat(window.getComputedStyle(body).paddingBottom) || 0;
        body.style.paddingBottom = (current + banner.offsetHeight + 40) + 'px';

        document.getElementById('joinery-cc-accept').onclick = function() { saveConsent(true, true); hideBanner(); };
        document.getElementById('joinery-cc-reject').onclick = function() { saveConsent(false, false); hideBanner(); };
        document.getElementById('joinery-cc-manage').onclick = function() { showModal(); };
    }

    function hideBanner() {
        var banner = document.getElementById('joinery-cc-banner');
        if (banner) banner.remove();
        var body = document.body;
        if (body.hasAttribute('data-joinery-cc-padding')) {
            body.style.paddingBottom = body.getAttribute('data-joinery-cc-padding');
            body.removeAttribute('data-joinery-cc-padding');
        }
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
        // The boxes show what holds now (under CCPA, on until opted out), so
        // Save without a look changes nothing.
        document.getElementById('joinery-cc-analytics').checked = !!(consent && consent.a);
        document.getElementById('joinery-cc-marketing').checked = !!(consent && consent.m);

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

    // Initialize. Under CCPA tracking is allowed until the visitor opts out,
    // so no stored choice means both on (not saved: it is no choice yet).
    consent = loadConsent();
    if (!consent && config.mode === 'ccpa') consent = { v: '1', a: 1, m: 1, implicit: true };
    if (config.mode === 'gdpr' && !consent) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showBanner);
        } else {
            showBanner();
        }
    } else if (consent) {
        updateScripts();
    }

    // Opens the preferences: the CCPA footer link
    // (ConsentHelper::renderCCPALink()), or any page that offers the choice.
    window.joineryCookieManage = showModal;
    document.addEventListener('click', function(e) {
        var link = e.target.closest ? e.target.closest('[data-joinery-cc-manage]') : null;
        if (!link) return;
        e.preventDefault();
        showModal();
    });
})();
