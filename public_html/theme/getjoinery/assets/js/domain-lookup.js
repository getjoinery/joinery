/*
 * The existing-domain quick start (/page/quickstart-existing-domain, the
 * lookup block of the getjoinery content pack): looks the domain up and shows
 * the steps as they apply to it. The block loads this file by src.
 *
 * @version 1.0
 */
// The steps section is a later block on the page, so wait for the whole
// document before looking anything up.
function gjDomainQuickStart() {
    var form = document.getElementById('gj-domain-form');
    var input = document.getElementById('gj-domain');
    var button = document.getElementById('gj-domain-go');
    var errorBox = document.getElementById('gj-domain-error');
    var found = document.getElementById('gj-domain-found');
    var idle = document.getElementById('gj-domain-idle');
    var steps = document.getElementById('gj-steps');
    if (!form || !input) { return; }

    var WORDS = ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];

    function conditions(d) {
        var host = d.dns_host || {};
        var registrar = d.registrar || null;
        var hostKnown = !!host.key;
        var fresh = d.situation === 'fresh';
        var locked = !!d.nameservers_locked;
        var path = d.path || (fresh ? 'linode' : 'manual');
        var c = {
            'path-linode': path === 'linode',
            'not-path-linode': path !== 'linode',
            'path-linode-token': path === 'linode_token',
            'path-auto': path === 'auto',
            'path-gated': path === 'gated',
            'path-manual': path === 'manual',
            'token': path === 'linode' || path === 'linode_token',
            'no-token': path !== 'linode' && path !== 'linode_token',
            'keep-dns': path !== 'linode' && path !== 'linode_token',
            'handwrite': path === 'gated' || path === 'manual',
            'fresh': fresh,
            'existing': !fresh,
            'locked': locked,
            'not-locked': !locked,
            'existing-or-locked': !fresh || locked,
            'website': !!(d.website && d.website.present),
            'no-website': !(d.website && d.website.present),
            'mail': !!(d.mail && d.mail.present),
            'no-mail': !(d.mail && d.mail.present),
            'host-known': hostKnown,
            'host-unknown': !hostKnown,
            'writable': host.mode === 'writable',
            'gated': host.mode === 'gated',
            'registrar-known': !!registrar,
            'registrar-unknown': !registrar,
            'registrar-is-host': !!d.registrar_is_dns_host,
            'registrar-not-host': !d.registrar_is_dns_host,
            'registrar-namecheap': !!(registrar && /namecheap/i.test(registrar.display || ''))
        };
        return c;
    }

    function apply(d) {
        var c = conditions(d);
        var siteDomain = (d.website && d.website.present) ? 'home.' + d.domain : d.domain;
        var fills = {
            'domain': d.domain,
            'site-domain': siteDomain,
            'registrar': d.registrar ? d.registrar.display : '',
            'registrar-or': d.registrar ? d.registrar.display : 'your registrar',
            'host': d.dns_host && d.dns_host.label ? d.dns_host.label : 'your DNS host',
            'mail-target': d.mail && d.mail.targets && d.mail.targets.length ? d.mail.targets[0] : '',
            'gate-note': d.dns_host ? (d.dns_host.gate_note || '') : ''
        };
        document.querySelectorAll('[data-fill]').forEach(function (el) {
            var key = el.getAttribute('data-fill');
            if (key in fills) { el.textContent = fills[key]; }
        });
        document.querySelectorAll('[data-when]').forEach(function (el) {
            var show = el.getAttribute('data-when').split(/\s+/).every(function (k) { return !!c[k]; });
            el.hidden = !show;
        });
        // Number the steps that apply, in page order.
        var n = 0;
        document.querySelectorAll('[data-step]').forEach(function (step) {
            if (step.hidden) { return; }
            n += 1;
            var rank = step.querySelector('.install-rank');
            if (rank) { rank.textContent = String(n); }
        });
        document.querySelectorAll('[data-fill="step-count"]').forEach(function (el) { el.textContent = WORDS[n - 1] || String(n); });
        found.style.display = '';
        steps.style.display = '';
        idle.style.display = 'none';
        errorBox.style.display = 'none';
    }

    function fail(message) {
        errorBox.textContent = message;
        errorBox.style.display = '';
        found.style.display = 'none';
        steps.style.display = 'none';
        idle.style.display = '';
    }

    function lookup() {
        var domain = (input.value || '').trim();
        if (!domain) { input.focus(); return; }
        button.disabled = true;
        button.textContent = 'Checking…';
        window.joineryApi.post('domain_setup_check', { domain: domain }).then(function (data) {
            apply(data);
            input.value = data.domain;
            try { history.replaceState(null, '', '#' + encodeURIComponent(data.domain)); } catch (e) {}
        }).catch(function (err) {
            fail(err && err.message ? err.message : 'We could not check that domain just now. Try again in a moment.');
        }).then(function () {
            button.disabled = false;
            button.textContent = 'Check it';
        });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); lookup(); });
    // A shared link carries the domain in the fragment, so the page opens already answered.
    var fromHash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
    if (/^[a-z0-9.-]+\.[a-z0-9-]+$/i.test(fromHash)) { input.value = fromHash; lookup(); }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', gjDomainQuickStart);
} else {
    gjDomainQuickStart();
}
