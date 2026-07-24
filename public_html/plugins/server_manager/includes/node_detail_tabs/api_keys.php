<?php
/**
 * node_detail — API Keys tab partial.
 *
 * Included by views/admin/node_detail.php in the shell's scope; the shell
 * owns node loading, the tab whitelist, and the permission gate. Lives under
 * includes/ (not views/) so it is not reachable as a standalone URL.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
 * @version 1.0
 */

	$has_api_pub = (bool)$node->get('mgn_api_public_key');
	$has_api_sec = (bool)$node->get('mgn_api_secret_key');
	$api_tls_insecure = (bool)$node->get('mgn_tls_insecure');

	$pageoptions = ['title' => 'API Credential'];
	$page->begin_box($pageoptions);
	echo '<p class="text-muted small mb-3">Pastable API credentials let the control plane use this node\'s HTTP management API instead of SSH for read-only operations (stats, version, backup listing, backup fetch). ';
	echo 'Create a key on the node: Admin → API Keys, owned by a superadmin user, with permission 1 (read-only). IP-restrict to this control plane\'s egress IP.</p>';

	if ($has_api_pub && $has_api_sec) {
		echo '<div class="mb-2"><span class="badge bg-success">Configured</span>';
		echo ' <span class="text-muted small ms-2">Public: <code>' . htmlspecialchars(substr($node->get('mgn_api_public_key'), 0, 12)) . '…</code></span>';
		echo ' <span id="apiProbeIndicator" class="ms-2 small text-muted" data-node-id="' . intval($node->key) . '">Probing…</span>';
		echo '</div>';
	} else {
		echo '<div class="mb-2"><span class="badge bg-secondary">Not configured</span> <span class="text-muted small ms-2">Jobs route via SSH.</span></div>';
	}

	$fw_api = $page->getFormWriter('api_keys_form', [
		'values' => [
			'mgn_api_public_key' => $node->get('mgn_api_public_key') ?? '',
		],
	]);
	$fw_api->begin_form();
	$fw_api->hiddeninput('action', '', ['id' => 'api_save_action', 'value' => 'save_api_credential']);
	$fw_api->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
	$fw_api->textinput('mgn_api_public_key', 'Public key', [
		'placeholder' => 'paste public_key here',
	]);
	$fw_api->passwordinput('mgn_api_secret_key', 'Secret key', [
		'placeholder' => $has_api_sec ? '(leave blank to keep current secret)' : 'paste secret_key here',
	]);
	$fw_api->checkboxinput('mgn_tls_insecure', 'Skip TLS certificate verification (only for dev/local instances without a trusted CA cert)', [
		'checked' => (bool)$api_tls_insecure,
	]);
	if ($api_tls_insecure) {
		echo '<div class="alert alert-warning py-2 small"><strong>TLS verification disabled.</strong> Do not use for nodes reachable from the public internet.</div>';
	}
	$fw_api->submitbutton('btn_api_save', 'Save', ['class' => 'btn btn-sm btn-primary']);
	$fw_api->end_form();
	if ($has_api_pub) {
		echo ' <button type="button" class="btn btn-sm btn-outline-danger" '
		   . 'onclick="JoineryModal.confirm(\'Clear API credentials? Jobs will fall back to SSH.\', function(){ document.getElementById(\'api_keys_clear_form\').submit(); })">Clear</button>';
	}

	if ($has_api_pub) {
		$fw_api_clear = $page->getFormWriter('api_keys_clear_form');
		$fw_api_clear->begin_form();
		$fw_api_clear->hiddeninput('action', '', ['id' => 'api_clear_action', 'value' => 'clear_api_credential']);
		$fw_api_clear->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_api_clear->end_form();
	}
	$page->end_box();

	// Async API probe — populates #apiProbeIndicator when credentials exist.
	if ($has_api_pub && $has_api_sec):
	?>
	<script>
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
	</script>
	<?php
	endif;

