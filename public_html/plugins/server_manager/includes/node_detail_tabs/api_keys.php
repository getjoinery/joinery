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
 * @version 1.1 - agent channel: pairing, the per-node cutover flag, and what the plane actually holds
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

	// ── The agent channel (specs/agent_on_node_architecture.md §3.1) ──
	$agent_paired      = (bool)$node->get('mgn_agent_public_key');
	$agent_channel_on  = (bool)$node->get('mgn_agent_channel_enabled');
	$agent_last_poll   = $node->get_local('mgn_agent_last_poll');
	$agent_paired_time = $node->get_local('mgn_agent_paired_time');
	$pair_pending      = (bool)$node->get('mgn_agent_pair_token_hash');

	$page->begin_box(['title' => 'Agent Channel']);
	echo '<p class="text-muted small mb-3">The node\'s own agent polls this control plane over an outbound HTTPS connection and takes work from it. '
	   . 'What it will run is compiled into the agent — a job names an operation, never a command — and the node refuses anything outside that list, whatever this plane asks for. '
	   . 'This plane holds only the public half of a key the node generated and kept, so there is no credential here that could act on the node.</p>';

	if ($agent_paired) {
		echo '<div class="mb-2"><span class="badge bg-success">Paired</span>';
		if ($agent_paired_time) {
			echo ' <span class="text-muted small ms-2">since ' . htmlspecialchars($agent_paired_time) . '</span>';
		}
		if ($node->get('mgn_agent_version')) {
			echo ' <span class="text-muted small ms-2">agent ' . htmlspecialchars($node->get('mgn_agent_version')) . '</span>';
		}
		echo '</div>';
		echo '<div class="mb-2 text-muted small">Last poll: '
		   . ($agent_last_poll ? htmlspecialchars($agent_last_poll) : 'never — the agent has not reached this plane since pairing')
		   . '. A poll is how this plane sees the node is alive; nothing has to reach in.</div>';
	} elseif ($pair_pending) {
		echo '<div class="mb-2"><span class="badge bg-warning text-dark">Pairing token outstanding</span>'
		   . ' <span class="text-muted small ms-2">Issued and not yet used. It expires on its own; issuing another replaces it.</span></div>';
	} else {
		echo '<div class="mb-2"><span class="badge bg-secondary">Not paired</span> <span class="text-muted small ms-2">This node\'s work routes over the API and SSH.</span></div>';
	}

	if ($session->get_permission() >= 10) {
		$fw_pair = $page->getFormWriter('agent_pair_form');
		$fw_pair->begin_form();
		$fw_pair->hiddeninput('action', '', ['value' => 'pair_agent']);
		$fw_pair->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_pair->submitbutton('btn_pair_agent',
			$agent_paired ? 'Issue a new pairing token' : 'Issue pairing token',
			['class' => 'btn btn-sm btn-primary']);
		$fw_pair->end_form();
		echo '<p class="text-muted small mt-2">The token is shown once, is good for one hour, and can be used once. '
		   . 'While it is outstanding it is the only thing on this plane that could pair as this node, which is why it is short-lived and why the pairing above is stamped where you can see it.</p>';
	} else {
		echo '<p class="text-muted small">Issuing a pairing token is superadmin-only.</p>';
	}

	if ($agent_paired) {
		$fw_channel = $page->getFormWriter('agent_channel_form');
		$fw_channel->begin_form();
		$fw_channel->hiddeninput('action', '', ['value' => 'set_agent_channel']);
		$fw_channel->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_channel->checkboxinput('agent_channel_enabled',
			'Route this node\'s work to its agent where an operation has crossed',
			['checked' => $agent_channel_on]);
		$fw_channel->submitbutton('btn_agent_channel', 'Save', ['class' => 'btn btn-sm btn-primary']);
		$fw_channel->end_form();
		echo '<p class="text-muted small">Off until the agent has been proven here. Operations that have not crossed yet keep using the API and SSH either way.</p>';

		echo '<button type="button" class="btn btn-sm btn-outline-danger" '
		   . 'onclick="JoineryModal.confirm(\'Forget this node\\\'s agent key? Its work goes back to the API and SSH, and re-pairing needs a new token.\', function(){ document.getElementById(\'agent_unpair_form\').submit(); })">Unpair</button>';

		$fw_unpair = $page->getFormWriter('agent_unpair_form');
		$fw_unpair->begin_form();
		$fw_unpair->hiddeninput('action', '', ['value' => 'unpair_agent']);
		$fw_unpair->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_unpair->end_form();
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

