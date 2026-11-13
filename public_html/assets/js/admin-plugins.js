/*
 * /admin/admin_plugins: Run setup again and Run removal again queue the
 * plugin's host installer or remover as a root request, and the setup badges
 * are filled from plugin_provisioning_check. #admin-plugins-config says
 * whether this admin may run the installer.
 *
 * @version 1.0
 */
// Run setup again: queue the plugin's host installer as a root request, then
// show that request's panel. A second press while it waits returns the same one.
document.addEventListener('click', function(e) {
    var button = e.target.closest('[data-run-installer]');
    if (!button) { return; }
    e.preventDefault();
    button.disabled = true;
    button.textContent = 'Queuing…';
    joineryApi.post('plugin_installer_run', { plugin: button.getAttribute('data-run-installer') })
        .then(function(data) {
            if (!data || !data.request_id) { throw new Error('no request'); }
            window.location = '/admin/admin_plugins?installer_request=' + encodeURIComponent(data.request_id);
        })
        .catch(function(err) {
            button.disabled = false;
            button.textContent = 'Run setup again';
            JoineryModal.alert('Could not queue the setup run' + (err && err.message ? ': ' + err.message : '.'));
        });
});
// Run removal again: queue the uninstalled plugin's host remover (and then its
// file removal) as a root request, then show that request's panel.
document.addEventListener('click', function(e) {
    var button = e.target.closest('[data-run-remover]');
    if (!button) { return; }
    e.preventDefault();
    button.disabled = true;
    button.textContent = 'Queuing…';
    joineryApi.post('plugin_remover_run', { plugin: button.getAttribute('data-run-remover') })
        .then(function(data) {
            if (!data || !data.request_id) { throw new Error('no request'); }
            window.location = '/admin/admin_plugins?installer_request=' + encodeURIComponent(data.request_id);
        })
        .catch(function(err) {
            button.disabled = false;
            button.textContent = 'Run removal again';
            JoineryModal.alert('Could not queue the removal' + (err && err.message ? ': ' + err.message : '.'));
        });
});

(function() {
    var nodes = document.querySelectorAll('[data-provisioning-plugin]');
    if (!nodes.length) { return; }
    var canRunInstaller = !!((window.joineryPageData && joineryPageData('admin-plugins-config')) || {}).can_run_installer;

    joineryApi.post('plugin_provisioning_check', {})
        .then(function(data) {
            if (!data || !data.plugins) { throw new Error('bad response'); }
            nodes.forEach(function(node) {
                var name = node.getAttribute('data-provisioning-plugin');
                render(node, name, data.plugins[name] || {});
            });
        })
        .catch(function() {
            nodes.forEach(function(node) {
                node.innerHTML = '<span class="prov-badge prov-unknown">Cannot check setup</span>';
            });
        });

    function render(node, pluginName, provisionersMap) {
        var items = Object.keys(provisionersMap).map(function(k) { return provisionersMap[k]; });
        if (!items.length) { node.innerHTML = ''; return; }

        var states = items.map(function(i) { return i.state; });
        var roll = states.indexOf('error') !== -1 ? 'error'
                 : states.indexOf('unmet') !== -1 ? 'unmet'
                 : states.indexOf('reachable') !== -1 ? 'reachable'
                 : 'verified';

        var labels = {
            verified:  'Setup complete',
            reachable: 'Reachable — not fully verified',
            unmet:     'Needs setup',
            error:     'Check failed'
        };

        node.innerHTML = '';
        var badge = document.createElement('span');
        badge.className = 'prov-badge prov-' + roll;
        badge.textContent = labels[roll];
        badge.setAttribute('role', 'button');
        badge.tabIndex = 0;
        badge.title = 'Click for details';

        var panel = buildPanel(pluginName, items);
        node.appendChild(badge);
        node.appendChild(panel);

        function toggle() {
            panel.style.display = (panel.style.display === 'none') ? 'block' : 'none';
        }
        badge.addEventListener('click', toggle);
        badge.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
        });
    }

    function buildPanel(pluginName, items) {
        var panel = document.createElement('div');
        panel.className = 'prov-panel';
        panel.style.display = 'none';

        var offeredRun = false;
        items.forEach(function(it) {
            var row = document.createElement('div');
            row.className = 'prov-item prov-item-' + it.state;

            var label = document.createElement('div');
            label.className = 'prov-item-label';
            label.textContent = it.label;
            row.appendChild(label);

            if (it.details) {
                var details = document.createElement('div');
                details.className = 'prov-item-details';
                details.textContent = it.details;
                row.appendChild(details);
            }

            var status = document.createElement('div');
            status.className = 'prov-item-status';
            if (it.state === 'verified') {
                status.textContent = 'Verified';
            } else if (it.state === 'reachable') {
                status.textContent = 'Reachable (listening, not verified)';
            } else if (it.state === 'unmet') {
                status.textContent = 'Needs setup — ' + (it.reason || '');
            } else {
                status.textContent = 'Check failed — ' + (it.reason || '');
            }
            row.appendChild(status);

            // The plugin's own installer fixes this one: offer it once per panel.
            if (it.state === 'unmet' && it.fixed_by_installer && canRunInstaller && !offeredRun) {
                offeredRun = true;
                var run = document.createElement('button');
                run.type = 'button';
                run.className = 'btn btn-sm btn-outline-secondary plugin-run-installer';
                run.setAttribute('data-run-installer', pluginName);
                run.textContent = 'Run setup again';
                row.appendChild(run);
            }
            panel.appendChild(row);
        });
        return panel;
    }
})();
