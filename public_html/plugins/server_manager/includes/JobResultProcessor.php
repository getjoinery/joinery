<?php
/**
 * JobResultProcessor - Parses completed job output into structured data.
 *
 * Called when a job transitions to 'completed'. Extracts meaningful data
 * from raw command output and updates related records.
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

class JobResultProcessor {

	/**
	 * Process a completed job. Dispatches to type-specific handler if one exists.
	 */
	public static function process($job) {
		$type = $job->get('mjb_job_type');
		$method = 'process_' . $type;
		if (!method_exists(self::class, $method)) {
			return;
		}
		// The Go agent marks jobs completed by writing the DB directly, so result
		// processing runs lazily on the first PHP view of the finished job — often
		// a GET (job detail page, status poll). These writes are intentional
		// server-side reconciliation, not user mutations: opt in explicitly so the
		// GET-mutation guard doesn't flag them.
		SystemBase::$allow_get_mutation = true;
		try {
			self::$method($job);
		} finally {
			SystemBase::$allow_get_mutation = false;
		}
	}

	/**
	 * Parse check_status output into structured data and update the node record.
	 *
	 * Handles both transports:
	 *   - API path: output is a JSON envelope {api_version,data:{...}} — extract data.
	 *   - SSH path: output is concatenated command output — parse with regexes.
	 *
	 * SSL detection uses two paths:
	 *   1. SSH cert token: Let's Encrypt cert file found on disk → 'letsencrypt'
	 *   2. HTTPS probe fallback: curl HEAD to https://domain/ — catches Cloudflare/edge SSL
	 * The API path implicitly proves HTTPS works (no separate probe needed; handled in
	 * fetch_status_via_api). For job-based API output, we probe explicitly here.
	 */
	private static function process_check_status($job) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

		$output = $job->get('mjb_output') ?: '';

		$api_data = self::extract_api_envelope_data($output);
		if (is_array($api_data) && !empty($api_data)) {
			$result = $api_data;
		} else {
			$result = self::parse_check_status_ssh_output($output);
		}
		$version     = $result['joinery_version'] ?? null;
		$is_api_path = ($api_data !== null);

		// Load node early — needed for HTTPS probe (mgn_site_url, mgn_tls_insecure)
		$node_id = $job->get('mjb_mgn_node_id');
		$node    = null;
		if ($node_id) {
			try { $node = new ManagedNode($node_id, TRUE); } catch (Exception $e) {}
		}

		// SSL detection
		$ssl_token     = self::parse_ssl_tokens($output);
		$ssl_new_state = null;  // null = no explicit state change from detection

		if ($ssl_token !== null) {
			// SSH path: explicit cert check result from the job steps
			$result['ssl_domain']   = $ssl_token['domain'];
			$result['ssl_le_cert']  = $ssl_token['found'];
			if ($ssl_token['found']) {
				$result['ssl_state']            = 'active';
				$result['ssl_detection_method'] = 'letsencrypt';
				$ssl_new_state = 'active';
				if (!empty($ssl_token['expiry_raw'])) {
					$result['ssl_expiry_raw'] = $ssl_token['expiry_raw'];
					$ts = strtotime($ssl_token['expiry_raw']);
					if ($ts) $result['ssl_expiry_ts'] = $ts;
				}
			} else {
				// No LE cert on disk — probe HTTPS to catch Cloudflare / other edge SSL
				$probe = JobCommandBuilder::probe_https($ssl_token['domain']);
				$result['ssl_https_probe'] = $probe['ok'];
				if ($probe['ok']) {
					$result['ssl_state']            = 'active';
					$result['ssl_detection_method'] = 'https_probe';
					$ssl_new_state = 'active';
				} else {
					$result['ssl_state'] = null;
				}
			}
		} elseif ($is_api_path && $node) {
			// API path: the Go agent called the API via HTTPS; probe to confirm valid cert
			$domain = parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '';
			if ($domain && !filter_var($domain, FILTER_VALIDATE_IP)
					&& $domain !== 'localhost' && !$node->get('mgn_tls_insecure')) {
				$probe = JobCommandBuilder::probe_https($domain);
				$result['ssl_https_probe'] = $probe['ok'];
				if ($probe['ok']) {
					$result['ssl_state']            = 'active';
					$result['ssl_domain']           = $domain;
					$result['ssl_detection_method'] = 'https_probe';
					$ssl_new_state = 'active';
				}
			}
		}

		if ($node) {
			$prev_ssl_state = $node->get('mgn_ssl_state');
			$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));
			$node->set('mgn_last_status_data', json_encode($result));
			if ($version) {
				$node->set('mgn_joinery_version', $version);
			}
			if ($ssl_new_state !== null) {
				$node->set('mgn_ssl_state', $ssl_new_state);
			} elseif ($ssl_token !== null && !$ssl_token['found']) {
				// SSH cert missing AND HTTPS probe failed — cert disappeared from active node
				if ($node->get('mgn_ssl_state') === 'active') {
					$node->set('mgn_ssl_state', 'failed');
				}
			}
			$node->save();

			// The first confirmation of an active cert doubles as the
			// reverse-DNS moment for cloud-born nodes: the domain has just
			// proven it resolves to this box, which is the provider's
			// precondition for accepting it as rDNS. Best-effort and
			// transition-only — a stale grant or manual node leaves the PTR
			// to the mailbox Setup tab checklist, and a later custom PTR is
			// never overwritten by routine status checks.
			if ($ssl_new_state === 'active' && $prev_ssl_state !== 'active') {
				$rdns_domain = $result['ssl_domain']
					?? (parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '');
				if ($rdns_domain && !filter_var($rdns_domain, FILTER_VALIDATE_IP)) {
					require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
					$result['rdns_attempt'] = NodeReverseDns::setQuietly($node, $rdns_domain);
				}
			}
		}

		// Save structured result on the job
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Pull the `data` field out of an api_success-style JSON envelope that the
	 * agent appended to mjb_output. The envelope is wrapped in step-header text
	 * ("=== [Step 1/1] ... ==="), so we scan for the first "{" and parse from
	 * there. Returns the decoded data array on success, null otherwise.
	 */
	private static function extract_api_envelope_data($output) {
		$start = strpos($output, '{');
		if ($start === false) return null;
		$candidate = substr($output, $start);
		// Trim trailing step-footer text ("[Step 1/1 OK ...") if present.
		$decoded = json_decode($candidate, true);
		if (is_array($decoded) && isset($decoded['api_version'], $decoded['data'])) {
			return is_array($decoded['data']) ? $decoded['data'] : null;
		}
		// Try progressively shorter prefixes — agents may append bytes after the JSON.
		$end = strrpos($candidate, '}');
		while ($end !== false && $end > 0) {
			$decoded = json_decode(substr($candidate, 0, $end + 1), true);
			if (is_array($decoded) && isset($decoded['api_version'], $decoded['data'])) {
				return is_array($decoded['data']) ? $decoded['data'] : null;
			}
			$end = strrpos(substr($candidate, 0, $end), '}');
		}
		return null;
	}

	/**
	 * Parse the multi-command SSH output into the structured result array.
	 */
	private static function parse_check_status_ssh_output($output) {
		$result = [];

		if (preg_match('/(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_usage_percent'] = intval($m[1]);
		}
		if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_total']     = $m[2];
			$result['disk_used']      = $m[3];
			$result['disk_available'] = $m[4];
		}

		if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/m', $output, $m)) {
			$result['memory_total_mb'] = intval($m[1]);
			$result['memory_used_mb']  = intval($m[2]);
			$result['memory_free_mb']  = intval($m[3]);
		}

		if (preg_match('/up\s+(.+?),\s+\d+\s+user/m', $output, $m)) {
			$result['uptime'] = trim($m[1]);
		}

		if (preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/m', $output, $m)) {
			$result['load_1m']  = floatval($m[1]);
			$result['load_5m']  = floatval($m[2]);
			$result['load_15m'] = floatval($m[3]);
		}

		if (preg_match('/accepting connections/i', $output)) {
			$result['postgres_status'] = 'accepting connections';
		} elseif (preg_match('/no response|not accepting/i', $output)) {
			$result['postgres_status'] = 'not responding';
		}

		if (preg_match("/VERSION\s*=\s*['\"]?([^'\";\s]+)/", $output, $m)) {
			$result['joinery_version'] = trim($m[1]);
		}

		if (preg_match('/^CRON_LAST_RUN=(.+)$/m', $output, $m)) {
			$result['cron_last_run'] = trim($m[1]);
		}

		if (preg_match('/^CURRENT_DB=(\S+)$/m', $output, $m)) {
			$result['current_db'] = trim($m[1]);
		}
		if (preg_match_all('/^DB:(\S+)$/m', $output, $m)) {
			$result['db_list'] = $m[1];
		}

		return $result;
	}

	/**
	 * Scan raw job output for SSL_CERT_FOUND / SSL_CERT_MISSING tokens emitted by
	 * the check_status SSL step. Returns an array with 'found', 'domain', and
	 * (when found) 'expiry_raw', or null if no token is present.
	 */
	private static function parse_ssl_tokens($output) {
		if (preg_match('/SSL_CERT_FOUND domain=(\S+) expiry=(.+)$/m', $output, $m)) {
			return ['found' => true, 'domain' => $m[1], 'expiry_raw' => trim($m[2])];
		}
		if (preg_match('/SSL_CERT_MISSING domain=(\S+)/m', $output, $m)) {
			return ['found' => false, 'domain' => $m[1]];
		}
		return null;
	}

	/**
	 * Parse backup output to extract file path and size.
	 */
	private static function process_backup_database($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [];

		// Look for backup file path in output (.sql.gz, .sql.gz.enc, or .tar.gz)
		if (preg_match('/(\/\S+\.(?:sql\.gz(?:\.enc)?|tar\.gz))\b/', $output, $m)) {
			$result['backup_file'] = $m[1];
		}

		// Look for file size from ls output
		if (preg_match('/(\d+(?:\.\d+)?[KMGT]?)\s+.*\.(?:sql\.gz|tar\.gz)/', $output, $m)) {
			$result['backup_size'] = $m[1];
		}

		if (!empty($result)) {
			$job->set('mjb_result', json_encode($result));
			$job->save();
		}
	}

	/**
	 * Parse backup_project output.
	 */
	private static function process_backup_project($job) {
		self::process_backup_database($job);
	}

	/**
	 * Post-process apply_update: no-op — version tracking is now handled by
	 * the X-Joinery-Version header on dashboard refresh, so a chained
	 * check_status job is no longer needed.
	 */
	private static function process_apply_update($job) {
	}

	/**
	 * Post-process install_node: mark the node online on success or install_failed on failure.
	 * All fresh installs get ssl_state=pending so ProvisionPendingSsl picks them up automatically.
	 * Also sends the welcome email for auto-provisioned orders (mjb_external_order_item_id set).
	 * Runs for both 'completed' and 'failed' terminal states.
	 */
	private static function process_install_node($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$status = $job->get('mjb_status');
		$output = $job->get('mjb_output') ?: '';

		if ($status === 'completed' && strpos($output, 'INSTALL_SUCCESS') !== false) {
			$node->set('mgn_install_state', null);
			if ($node->get('mgn_ssl_state') !== 'active') {
				$node->set('mgn_ssl_state', 'pending');
			}
			$node->save();
			// Send welcome email for auto-provisioned orders
			if ($job->get('mjb_external_order_item_id')) {
				self::send_provisioning_welcome_email($job, $node);
			}
		} else {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}

		$job->set('mjb_result', json_encode([
			'install_state' => $node->get('mgn_install_state'),
			'ssl_state'     => $node->get('mgn_ssl_state'),
		]));
		$job->save();
	}

	/**
	 * Post-process provision_relay: on success (PROVISION_RELAY_SUCCESS marker),
	 * store the relay's returned WireGuard public key + endpoint + public IP on the
	 * ManagedNode, flag it a relay, and clear the install state; on failure set
	 * install_failed. Runs for both 'completed' and 'failed' terminal states.
	 */
	private static function process_provision_relay($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$status = $job->get('mjb_status');
		$output = $job->get('mjb_output') ?: '';

		if ($status === 'completed' && strpos($output, 'PROVISION_RELAY_SUCCESS') !== false) {
			$node->set('mgn_is_relay', true);
			$node->set('mgn_install_state', null);
			// The relay runs no Joinery app, so skip the app health checks for it.
			$node->set('mgn_skip_joinery_checks', true);

			$wg_pubkey = self::extract_marker($output, 'RELAY_WG_PUBKEY');
			$public_ip = self::extract_marker($output, 'RELAY_PUBLIC_IP');
			if ($wg_pubkey !== '') {
				$node->set('mgn_wg_public_key', substr($wg_pubkey, 0, 255));
			}
			if ($public_ip !== '') {
				$node->set('mgn_wg_endpoint', $public_ip . ':51820');
			}
			// The relay's tunnel IP is fixed by provision_relay.sh.
			$node->set('mgn_wg_ip', '10.99.0.1');
			$node->save();

			// Register (or refresh) the MailboxRelay row the mailbox plugin drives —
			// created DISABLED so the admin enables it after verifying (Fix 10).
			// The output carries the TENANT_* markers (pull account, spool subdir).
			// Fleet SHARDS (skeleton_only) are not this deployment's relay — the
			// operator's box is not a tenant of them — so no relay row is minted.
			$job_params = json_decode((string)$job->get('mjb_parameters'), true) ?: array();
			if (empty($job_params['skeleton_only'])) {
				self::register_relay_row($node, $public_ip, $wg_pubkey, $output);
			}

			// Peer the relay on the MAIN box's WireGuard interface — the other half
			// of the tunnel. provision_relay_main.sh installs the root helper + a
			// sudoers rule for exactly this call. Best-effort: on failure the tunnel
			// health checks go red and the log says what to run. Fleet shards skip
			// this too — the operator's box holds no tunnel into its shards.
			if ($wg_pubkey !== '' && $public_ip !== '' && empty($job_params['skeleton_only'])) {
				$peer_cmd = 'sudo -n /usr/local/sbin/joinery-relay-peer '
					. escapeshellarg($wg_pubkey) . ' ' . escapeshellarg($public_ip . ':51820') . ' 2>&1';
				$peer_out = array(); $peer_code = 1;
				exec($peer_cmd, $peer_out, $peer_code);
				if ($peer_code !== 0) {
					error_log('process_provision_relay: main-box WireGuard peer add failed ('
						. $peer_code . '): ' . implode(' ', $peer_out)
						. ' — run plugins/mailbox/provisioning/provision_relay_main.sh on the main box');
				}
			}
		} else {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}

		$job->set('mjb_result', json_encode([
			'is_relay'      => (bool)$node->get('mgn_is_relay'),
			'wg_public_key' => $node->get('mgn_wg_public_key'),
			'wg_endpoint'   => $node->get('mgn_wg_endpoint'),
			'install_state' => $node->get('mgn_install_state'),
		]));
		$job->save();
	}

	/** rebuild_relay post-processing is identical to a fresh provision. */
	private static function process_rebuild_relay($job) {
		self::process_provision_relay($job);
	}

	/**
	 * Create or update the mailbox plugin's MailboxRelay row for a provisioned node
	 * (Fix 10). Owned by the mailbox plugin, so it is required lazily and skipped
	 * (no fatal) when that plugin is inactive. The row is left DISABLED — enabling
	 * it (which makes the relay front every hosted domain) is an explicit admin act.
	 */
	private static function register_relay_row($node, string $public_ip, string $wg_pubkey, string $job_output = ''): void {
		$relay_class = PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php');
		if (!is_file($relay_class)) {
			return; // mailbox plugin not present
		}
		try {
			require_once($relay_class);
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare(
				"SELECT mrl_mailbox_relay_id FROM mrl_mailbox_relays
				  WHERE mrl_mgn_managed_node_id = ? AND mrl_delete_time IS NULL LIMIT 1"
			);
			$stmt->execute(array($node->key));
			$existing = $stmt->fetchColumn();

			$relay = $existing ? new MailboxRelay(intval($existing), TRUE) : new MailboxRelay(NULL);
			$relay->set('mrl_mgn_managed_node_id', $node->key);
			$relay->set('mrl_name', $node->get('mgn_name'));
			// The main box reaches the relay over the tunnel at its WireGuard IP.
			$relay->set('mrl_host', (string)$node->get('mgn_wg_ip') ?: '10.99.0.1');
			if ($public_ip !== '') { $relay->set('mrl_public_ip', $public_ip); }
			// The steady-state login is the RESTRICTED TENANT ACCOUNT the
			// add-tenant step created (forced command: the tenant shell), never
			// root. The markers carry the coordinates; a self-hosted relay is a
			// fleet of one with slug 'main'.
			$tenant_user = self::extract_marker($job_output, 'TENANT_SSH_USER') ?: 'jt-main';
			$tenant_spool = self::extract_marker($job_output, 'TENANT_SPOOL') ?: '/var/spool/joinery-relay/main';
			$tenant_slug = self::extract_marker($job_output, 'TENANT_SLUG') ?: 'main';
			$relay->set('mrl_tenant_slug', substr($tenant_slug, 0, 28));
			$relay->set('mrl_ssh_user', substr($tenant_user, 0, 50));
			$relay->set('mrl_ssh_port', intval($node->get('mgn_ssh_port')) ?: 22);
			// The relay's steady-state connections run as the WEB USER (cron tasks,
			// health battery), so the row points at the web-user-owned pull key the
			// provision job authorized — never the node's admin key, which the web
			// user cannot read (ssh demands caller-owned mode-600 key files).
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
			$pull_key = RelaySsh::pullKeyPath();
			$relay->set('mrl_ssh_key_path', is_file($pull_key) ? $pull_key : (string)$node->get('mgn_ssh_key_path'));
			$relay->set('mrl_spool_path', substr($tenant_spool, 0, 500));
			if ($wg_pubkey !== '') { $relay->set('mrl_wg_public_key', substr($wg_pubkey, 0, 255)); }
			$relay->set('mrl_wg_endpoint', (string)$node->get('mgn_wg_endpoint'));
			$relay->set('mrl_wg_ip', (string)$node->get('mgn_wg_ip'));
			if (!$existing) {
				$relay->set('mrl_is_enabled', false); // admin enables after verifying
			}
			$relay->save();
			// Mint the ambient transport keypair now so the first map push can seal
			// Standard/Private mail immediately once enabled.
			$relay->ensureTransportKeypair();
		} catch (\Throwable $e) {
			error_log('JobResultProcessor::register_relay_row failed: ' . $e->getMessage());
		}
	}

	/**
	 * Pull the value of an `echo KEY=value` marker line out of streamed job output
	 * (the last occurrence wins). Returns '' when absent.
	 */
	private static function extract_marker(string $output, string $key): string {
		if (preg_match_all('/^' . preg_quote($key, '/') . '=(.*)$/m', $output, $m) && !empty($m[1])) {
			return trim((string)end($m[1]));
		}
		return '';
	}

	/**
	 * Send the post-provisioning welcome email to the customer via getjoinery's
	 * QueuedEmail API. Reads credentials from Server Manager plugin settings.
	 * Silently returns on any failure — email delivery is best-effort.
	 * Public: ProvisionCustomerCloud's failed-provision recovery path also
	 * sends it (for completed retry jobs that carry no order-item linkage).
	 */
	public static function send_provisioning_welcome_email($job, $node) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));

		$settings   = Globalvars::get_instance();
		$api_url    = $settings->get_setting('server_manager_getjoinery_api_url');
		$pub_key    = $settings->get_setting('server_manager_getjoinery_api_public_key');
		$sec_key    = $settings->get_setting('server_manager_getjoinery_api_secret_key');
		$from_email = $settings->get_setting('server_manager_provisioning_welcome_from_email') ?: 'support@getjoinery.com';
		$from_name  = $settings->get_setting('server_manager_provisioning_welcome_from_name')  ?: 'Get Joinery Support';

		if (!$api_url || !$pub_key || !$sec_key) return;

		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? json_decode($params, true) : $params;
		$params = is_array($params) ? $params : [];

		$domain      = $params['domain'] ?? '';
		$admin_email = $params['admin_email'] ?? '';
		$user_name   = $params['user_name'] ?? 'Customer';

		if (!$admin_email || !$domain) return;

		// Resolve host IP for the DNS A-record instruction: shared-host nodes
		// live on a ManagedHost machine; customer-cloud nodes have no host row
		// — the node's own address is the DNS target.
		$host_ip = '';
		$host_id = $node->get('mgn_mgh_host_id');
		if ($host_id) {
			try {
				$host    = new ManagedHost($host_id, true);
				$host_ip = (string)$host->get('mgh_host');
			} catch (Exception $e) {}
		}
		if ($host_ip === '') {
			$host_ip = (string)$node->get('mgn_host');
		}

		$client = new GetJoineryApiClient($api_url, $pub_key, $sec_key);
		$client->post('QueuedEmail', [
			'equ_from'      => $from_email,
			'equ_from_name' => $from_name,
			'equ_to'        => $admin_email,
			'equ_to_name'   => $user_name,
			'equ_subject'   => 'Your site is ready: ' . $domain,
			'equ_body'      => self::build_welcome_email_body($domain, $host_ip, $user_name),
			'equ_status'    => 2, // READY_TO_SEND
		]);
	}

	private static function build_welcome_email_body($domain, $host_ip, $user_name) {
		$name      = htmlspecialchars($user_name);
		$dom       = htmlspecialchars($domain);
		$ip        = htmlspecialchars($host_ip);
		$login_url = htmlspecialchars('https://' . $domain . '/admin');

		return <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333">
<h2 style="color:#1a1a1a">Your site is ready!</h2>
<p>Hi {$name},</p>
<p>Your Joinery site for <strong>{$dom}</strong> has been installed successfully.</p>

<h3>Next step: point your DNS</h3>
<p>Add an <strong>A record</strong> for <code>{$dom}</code> pointing to:</p>
<p style="font-size:1.5em;text-align:center;font-weight:bold;letter-spacing:.05em;background:#f4f4f4;padding:12px;border-radius:4px">{$ip}</p>
<p>DNS changes typically propagate in a few minutes to a few hours. Once your domain resolves to that IP, HTTPS will be provisioned automatically — no action needed on your part.</p>

<h3>Log in</h3>
<p>After DNS resolves, your admin panel is at:<br>
<a href="{$login_url}">{$login_url}</a></p>

<p style="color:#666;font-size:.9em">Questions? Reply to this email or contact support@getjoinery.com.</p>
<p>— The Get Joinery Team</p>
</body></html>
HTML;
	}

	/**
	 * On provision_ssl success, flip mgn_ssl_state to active.
	 * Failure tracking and the 16h give-up logic live in ProvisionPendingSsl.
	 */
	private static function process_provision_ssl($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$rdns_attempt = null;
		if ($job->get('mjb_status') === 'completed') {
			$was_active = ($node->get('mgn_ssl_state') === 'active');
			$node->set('mgn_ssl_state', 'active');
			$node->save();

			// The cert just issued, so the domain provably resolves to this
			// box — the provider's precondition for accepting it as reverse
			// DNS. Set the PTR through the birth provision's grant now, so a
			// standalone site never needs the control-plane panel. Best-effort
			// and first-issuance-only: a stale grant or manual node leaves the
			// PTR to the mailbox Setup tab checklist, and a later custom PTR
			// is never overwritten by a cert renewal.
			if (!$was_active) {
				$params = json_decode($job->get('mjb_parameters') ?: '', true);
				$rdns_domain = is_array($params) && !empty($params['domain'])
					? $params['domain']
					: (parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '');
				if ($rdns_domain && !filter_var($rdns_domain, FILTER_VALIDATE_IP)) {
					require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
					$rdns_attempt = NodeReverseDns::setQuietly($node, $rdns_domain);
				}
			}
		}

		$result = ['ssl_state' => $node->get('mgn_ssl_state')];
		if ($rdns_attempt !== null) {
			$result['rdns_attempt'] = $rdns_attempt;
		}
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Parse discover_nodes output into structured instance data.
	 */
	private static function process_discover_nodes($job) {
		$output = $job->get('mjb_output') ?: '';
		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? json_decode($params, true) : $params;

		$result = [
			'host' => $params['host'] ?? '',
			'ssh_user' => $params['ssh_user'] ?? 'root',
			'ssh_key_path' => $params['ssh_key_path'] ?? '',
			'ssh_port' => intval($params['ssh_port'] ?? 22),
			'hostname' => '',
			'has_docker' => true,
			'instances' => [],
		];

		// Parse hostname from connection test
		if (preg_match('/CONNECT_OK\s*\n\s*(.+)/m', $output, $m)) {
			$result['hostname'] = trim($m[1]);
		}

		// Check for NO_DOCKER
		if (strpos($output, 'NO_DOCKER') !== false) {
			$result['has_docker'] = false;
		}

		// Parse JOINERY_INSTANCE lines
		// Format: JOINERY_INSTANCE|type|name|web_root|domain|db_name|version
		//         m[1]=type, m[2]=name, m[3]=web_root, m[4]=domain, m[5]=db_name, m[6]=version
		if (preg_match_all('/JOINERY_INSTANCE\|([^|]*)\|([^|]*)\|([^|]*)\|([^|]*)\|([^|]*)\|(.*)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$name = trim($m[2]);
				$domain = trim($m[4]);
				$instance = [
					'type' => trim($m[1]),
					'container_name' => (trim($m[1]) === 'docker') ? $name : '',
					'name' => ucwords(str_replace(['-', '_'], ' ', $name)),
					'slug' => strtolower($name),
					'web_root' => trim($m[3]),
					'site_url' => $domain ? 'https://' . $domain : '',
					'db_name' => trim($m[5]),
					'version' => trim($m[6]),
				];
				$result['instances'][] = $instance;
			}
		}

		// Check which slugs already exist as nodes
		$existing_slugs = [];
		$existing_nodes = new MultiManagedNode(['deleted' => false]);
		$existing_nodes->load();
		foreach ($existing_nodes as $en) {
			$existing_slugs[] = $en->get('mgn_slug');
		}
		foreach ($result['instances'] as &$inst) {
			$inst['already_added'] = in_array($inst['slug'], $existing_slugs);
		}
		unset($inst);

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Parse list_backups output into a local-file list. Cloud listings are
	 * fetched web-server-side at display time via TargetLister, merged by
	 * BackupListHelper.
	 *
	 * Handles both transports:
	 *   - API path: JSON envelope with data.files[] (already structured).
	 *   - SSH path: LOCAL|size_bytes|mtime_epoch|filepath lines.
	 */
	private static function process_list_backups($job) {
		$output = $job->get('mjb_output') ?: '';
		$files = [];

		$api_data = self::extract_api_envelope_data($output);
		if (is_array($api_data) && isset($api_data['files']) && is_array($api_data['files'])) {
			$files = $api_data['files'];
		} elseif (preg_match_all('/^LOCAL\|(\d+)\|(\d+)\|(.+)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$path = trim($m[3]);
				$filename = basename($path);
				$size_bytes = intval($m[1]);
				$mtime = intval($m[2]);
				$files[] = [
					'filename' => $filename,
					'size' => self::format_size($size_bytes),
					'size_bytes' => $size_bytes,
					'date' => gmdate('Y-m-d', $mtime),
					'mtime' => $mtime,
					'local_path' => $path,
					'cloud_path' => null,
					'location' => 'local',
				];
			}
		}

		usort($files, function($a, $b) { return ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0); });

		$job->set('mjb_result', json_encode(['files' => $files]));
		$job->save();
	}

	/**
	 * Process delete_backup result.
	 */
	private static function process_delete_backup($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [
			'local_deleted' => strpos($output, 'LOCAL_DELETE_OK') !== false,
			'cloud_deleted' => strpos($output, 'CLOUD_DELETE_OK') !== false,
		];

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Format bytes into human-readable size.
	 */
	private static function format_size($bytes) {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
		if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
		if ($bytes >= 1024) return round($bytes / 1024, 1) . 'K';
		return $bytes . 'B';
	}
}
?>
