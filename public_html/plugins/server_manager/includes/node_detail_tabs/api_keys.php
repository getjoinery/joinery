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
 * @version 1.6 - a join from a machine this plane provisioned names its provision, instance and age on
 *                the card, and where the install password stands; approval checks it with the provider
 * @version 1.5 - switched-off nodes read as switched off, not as broken: the going-quiet stamp is
 *                compared against the last poll rather than cleared, so a returning node needs no write
 * @version 1.4 - turn the agent on for a node from here, over the SSH this plane already has:
 *                the node is administered from both ends and the fleet should not need a visit each
 * @version 1.3 - the routing checkbox is gone: connected = routed (hard cutover, owner-set)
 * @version 1.2 - enrollment is a node-initiated join (Phase 1.5, A6): pending requests with
 *                fingerprint-comparison approval replace the pairing token UI
 * @version 1.1 - agent channel: pairing, the per-node cutover flag, and what the plane actually holds
 */

	$has_api_pub = (bool)$node->get('mgn_api_public_key');
	$has_api_sec = (bool)$node->get('mgn_api_secret_key');
	$api_tls_insecure = (bool)$node->get('mgn_tls_insecure');

	$pageoptions = ['title' => 'API Credential'];
	$page->begin_box($pageoptions);
	echo '<p class="text-muted small mb-3">Pastable API credentials let the management node use this node\'s HTTP management API instead of SSH for read-only operations (stats, version, backup listing, backup fetch). ';
	echo 'Create a key on the node: Admin → API Keys, owned by a superadmin user, with permission 1 (read-only). IP-restrict to this management node\'s egress IP.</p>';

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

	// ── The agent channel (specs/agent_on_node_architecture.md §3.1, Phase 1.5) ──
	$agent_paired      = (bool)$node->get('mgn_agent_public_key');
	$agent_last_poll   = $node->get_local('mgn_agent_last_poll');
	$agent_paired_time = $node->get_local('mgn_agent_paired_time');

	$page->begin_box(['title' => 'Agent Channel']);
	echo '<p class="text-muted small mb-3">The node\'s own agent polls this management node over an outbound HTTPS connection and takes work from it. '
	   . 'What it will run is compiled into the agent — a job names an operation, never a command — and the node refuses anything outside that list, whatever this management node asks for. '
	   . 'This management node holds only the public half of a key the node generated and kept, so there is no credential here that could act on the node.</p>';

	if ($agent_paired) {
		echo '<div class="mb-2"><span class="badge bg-success">Connected</span>';
		if ($agent_paired_time) {
			echo ' <span class="text-muted small ms-2">since ' . htmlspecialchars($agent_paired_time) . '</span>';
		}
		if ($node->get('mgn_agent_version')) {
			echo ' <span class="text-muted small ms-2">agent ' . htmlspecialchars($node->get('mgn_agent_version')) . '</span>';
		}
		$stored_key = base64_decode((string)$node->get('mgn_agent_public_key'), true);
		if ($stored_key !== false) {
			echo ' <span class="text-muted small ms-2">key '
			   . htmlspecialchars(AgentJoinRequest::display_fingerprint(AgentJoinRequest::fingerprint($stored_key)))
			   . '</span>';
		}
		echo '</div>';

		// Silence has two meanings and only the node can tell them apart. A node
		// whose agent was switched off says so before it stops; one that broke
		// cannot. Comparing the two stamps rather than clearing either keeps a
		// node that came back looking alive with no extra write.
		$quiet_time = $node->get('mgn_agent_quiet_time');
		$switched_off = $quiet_time && (!$node->get('mgn_agent_last_poll')
			|| $node->get('mgn_agent_last_poll') <= $quiet_time);

		if ($switched_off) {
			echo '<div class="alert alert-secondary py-2 small mb-2">'
			   . '<strong>Switched off by the operator</strong> at ' . htmlspecialchars($node->get_local('mgn_agent_quiet_time'))
			   . '. The agent told this management node before it stopped, so the silence since then is expected. '
			   . 'The pairing still stands — it comes back when the agent is switched on there.</div>';
		}

		echo '<div class="mb-2 text-muted small">Last poll: '
		   . ($agent_last_poll ? htmlspecialchars($agent_last_poll) : 'never — the agent has not reached this management node since it was connected')
		   . '. A poll is how this management node sees the node is alive; nothing has to reach in.'
		   . ($switched_off ? '' : ' A node that stops polling without saying it was switched off is unreachable — down, or broken.')
		   . '</div>';

		// Restarting the agent is a PRIMITIVE and has no SSH equivalent, on
		// purpose: the SSH way is pkill, which is an arbitrary command (A1). So
		// the button appears only for a connected node, and only while the agent
		// is actually reaching us — asking a silent agent to restart would queue
		// work for something that is not listening.
		//
		// The node may still refuse, and that is the primitive working: it
		// restarts only when it can prove something will start it again.
		if ($session->get_permission() >= 10 && !$switched_off && $node->get('mgn_agent_last_poll')) {
			echo '<form method="post" action="' . $base_url . '" id="restart_agent_form" class="mb-3">'
			   . '<input type="hidden" name="action" value="restart_agent">'
			   . SmAdminCsrf::field()
			   . '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="JoineryModal.confirm('
			   . htmlspecialchars(json_encode('Restart the agent on ' . $node->get('mgn_name') . '? '
				. 'It finishes reporting this job first, then stops, and its supervisor starts it again — '
				. 'within seconds under systemd, within a minute under the cron keepalive. '
				. 'The node will refuse if nothing there would start it back up.'), ENT_QUOTES)
			   . ', function(){ document.getElementById(\'restart_agent_form\').submit(); })">Restart the agent</button>'
			   . ' <span class="text-muted small ms-1">For an agent that is running but stuck — holding a stale lock, or running a replaced binary.</span>'
			   . '</form>';
		}
	} else {
		echo '<div class="mb-2"><span class="badge bg-secondary">Not connected</span> <span class="text-muted small ms-2">This node\'s work waits for its agent to pair; only the API can reach it now.</span></div>';

		// Enrollment starts ON THE NODE and shares no secret (A6): the node's
		// admin enters this management node's URL, the node's root agent
		// generates a keypair and asks to join, and the request appears here.
		$join_requests = class_exists('AgentJoinRequest') ? AgentJoinRequest::pending() : [];
		if (empty($join_requests)) {
			echo '<div class="alert alert-info small mb-2">'
			   . '<strong>To connect this node\'s agent:</strong> on the node, open '
			   . '<em>Admin &rarr; System &rarr; Management Node</em> and enter this management node\'s URL: '
			   . '<code>' . htmlspecialchars(rtrim(LibraryFunctions::get_absolute_url(), '/')) . '</code>. '
			   . 'The node asks to join — no secret is copied — and its request appears here for approval.</div>';
		}
		foreach ($join_requests as $jr) {
			$fpr = AgentJoinRequest::display_fingerprint((string)$jr->get('ajr_fingerprint'));
			echo '<div class="alert alert-warning mb-2">';
			echo '<div><strong>Join request</strong> from <strong>' . htmlspecialchars($jr->get('ajr_claimed_name')) . '</strong>'
			   . ' <span class="text-muted small">(' . htmlspecialchars((string)$jr->get('ajr_source_ip'))
			   . ', ' . htmlspecialchars($jr->get_local('ajr_create_time')) . ')</span></div>';
			echo '<div class="my-2">Key fingerprint: <code style="font-size:1.1em;">' . htmlspecialchars($fpr) . '</code></div>';
			// A join from an address this plane provisioned a machine at is that
			// machine asking to be managed. Say which provision, and that
			// approval will check the claim with the provider before binding.
			$join_prov = class_exists('CustomerCloudProvision')
				? CustomerCloudProvision::for_machine_address((string)$jr->get('ajr_source_ip')) : null;
			if ($join_prov) {
				$prov_age = $join_prov->get('cvp_create_time')
					? max(0, (int)floor((time() - strtotime($join_prov->get('cvp_create_time') . ' UTC')) / 60)) : null;
				$machine_ids = class_exists('ProvisionCustomerCloud') ? ProvisionCustomerCloud::machine_node_ids($join_prov) : [];
				$is_machine_node = in_array((int)$node->key, $machine_ids, true);
				echo '<div class="small mb-2">This address is <strong>provision #' . (int)$join_prov->key . '</strong> ('
				   . htmlspecialchars($join_prov->get('cvp_domain')) . '): instance '
				   . htmlspecialchars((string)$join_prov->get('cvp_instance_id'))
				   . ($prov_age !== null ? ', created ' . ($prov_age >= 120 ? round($prov_age / 60) . ' h' : $prov_age . ' min') . ' ago' : '')
				   . '. Install password: ' . htmlspecialchars(ProvisionCustomerCloud::install_password_summary($join_prov)) . '. '
				   . ($is_machine_node
						? 'Approving here asks the provider to confirm the instance is running at this address first.'
						: '<strong>This node is neither that provision\'s site nor a host record at its address</strong> — approval here will be refused.')
				   . '</div>';
			}
			echo '<div class="small mb-2">Approve <strong>only</strong> if this exactly matches the fingerprint shown on the node\'s own '
			   . 'Management Node page. The name and address above are claims anyone could make; the fingerprint is the identity.</div>';
			if ($session->get_permission() >= 10) {
				$jr_id = (int)$jr->key;
				echo '<form method="post" action="' . $base_url . '" id="approve_join_' . $jr_id . '" style="display:inline;margin-right:6px;">'
				   . '<input type="hidden" name="action" value="approve_join">'
				   . '<input type="hidden" name="ajr_id" value="' . $jr_id . '">'
				   . SmAdminCsrf::field()
				   . '<button type="button" class="btn btn-sm btn-primary" onclick="JoineryModal.confirm('
				   . htmlspecialchars(json_encode('Connect this agent as ' . $node->get('mgn_name')
					. '? Confirm the fingerprint ' . $fpr . ' matches the node\'s own page first.'), ENT_QUOTES)
				   . ', function(){ document.getElementById(\'approve_join_' . $jr_id . '\').submit(); })">Approve</button>'
				   . '</form>';
				echo '<form method="post" action="' . $base_url . '" id="reject_join_' . $jr_id . '" style="display:inline;">'
				   . '<input type="hidden" name="action" value="reject_join">'
				   . '<input type="hidden" name="ajr_id" value="' . $jr_id . '">'
				   . SmAdminCsrf::field()
				   . '<button type="button" class="btn btn-sm btn-outline-danger" onclick="JoineryModal.confirm('
				   . htmlspecialchars(json_encode('Reject this join request?'), ENT_QUOTES)
				   . ', function(){ document.getElementById(\'reject_join_' . $jr_id . '\').submit(); })">Reject</button>'
				   . '</form>';
			} else {
				echo '<div class="text-muted small">Approving a join request is superadmin-only.</div>';
			}
			echo '</div>';
		}
	}

	if ($agent_paired) {
		echo '<p class="text-muted small">Everything the agent can do runs through it; operations it cannot do yet use the API and SSH.</p>';

		echo '<button type="button" class="btn btn-sm btn-outline-danger" '
		   . 'onclick="JoineryModal.confirm(\'Forget this node\\\'s agent key? Its work goes back to the API and SSH, and reconnecting starts over from the node\\\'s Management Node page.\', function(){ document.getElementById(\'agent_unpair_form\').submit(); })">Disconnect</button>';

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

