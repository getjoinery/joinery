/*
 * The node's API keys tab (includes/node_detail_tabs/api_keys.php): probes the
 * node's API once and says how it answered, in #apiProbeIndicator.
 *
 * @version 1.0
 */
(function() {
	var el = document.getElementById('apiProbeIndicator');
	if (!el) return;
	var nodeId = el.getAttribute('data-node-id');
	joineryApi.post('server_manager/probe_api', { node_id: nodeId })
		.then(function(j) {
			if (j.ok) {
				el.className = 'ms-2 small text-success';
				el.textContent = 'API healthy (' + j.elapsed_ms + 'ms)';
			} else {
				el.className = 'ms-2 small text-danger';
				var label = j.reason === 'auth' ? 'auth failed'
				          : j.reason === 'transport' ? 'unreachable'
				          : j.reason === 'status' ? 'bad response'
				          : 'failed';
				el.textContent = 'API ' + label + (j.message ? ': ' + j.message : '');
			}
		})
		.catch(function() {
			el.className = 'ms-2 small text-danger';
			el.textContent = 'API probe failed';
		});
})();
