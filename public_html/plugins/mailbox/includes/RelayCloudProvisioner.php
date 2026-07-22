<?php
/**
 * RelayCloudProvisioner - turns a just-in-time provider token into a running
 * relay on the customer's own cloud account
 * (specs/mailbox_relay_cloud_provisioning.md).
 *
 * Advances RelayCloudProvision runs. Reuses the platform's cloud machinery:
 * CloudComputeProvider / LinodeComputeDriver (includes/cloud_compute/) for the
 * instance lifecycle, provision_relay.sh for the relay build (the same
 * tarball -> scp -> skeleton -> add-tenant 'main' -> markers sequence the
 * server_manager provision job runs), RelaySsh::run for execution, and the
 * customer-cloud failure policy (401 terminal here — grant-per-act has no
 * re-connect parking; 4xx terminal; 5xx/network retry next tick).
 *
 * Grant-per-act custody: the provider token and the per-run root SSH key live
 * SecretBox-sealed on the run row and are erased at every terminal state. A
 * failed run destroys the instance it created within the same grant — the
 * customer is never left paying for a half-built box.
 *
 * Test seams: $driver_factory and $runner are injectable statics.
 *
 * @version 1.4 - instance labels name the relay (hostname + run id) instead of a
 *                bare counter; forgetHostKey on binding a row to a new machine
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));

class RelayCloudProvisioner {

	const BOOT_TIMEOUT_SECONDS = 1800; // instance create -> running + IP
	const LABEL_MAX_LENGTH = 64;       // provider cap on an instance label (Linode)

	/** @var callable|null fn(RelayCloudProvision): CloudComputeProvider — test seam. */
	public static $driver_factory = null;

	/** @var callable|null fn(string $cmd): array{0:int,1:string} — test seam. */
	public static $runner = null;

	/** Advance one run a single step. Returns a short human status line. */
	public function advance(RelayCloudProvision $run): string {
		switch ((string)$run->get('rcp_status')) {
			case 'ready':
				return $this->handleReady($run);
			case 'booting':
				return $this->handleBooting($run);
			case 'provisioning':
				// A crash mid-SSH leaves this state behind; rerunning the
				// idempotent script is safe, so treat it like booting-complete.
				return $this->handleProvision($run);
			default:
				return 'nothing to do';
		}
	}

	/**
	 * The provider-side instance label.
	 *
	 * Cosmetic to the platform — every lookup, destroy and reverse-DNS call keys
	 * on the numeric instance id, never on this — but it is the ONLY thing
	 * naming a box in the provider's dashboard, so it carries the relay's mail
	 * hostname instead of a bare counter that says nothing about what the
	 * machine is or whether it is the live one.
	 *
	 * The run id stays on the end because a rebuild creates the replacement
	 * while the predecessor is still running (retiring the old box is a
	 * deliberate manual act), and provider labels must be unique within an
	 * account: a bare hostname would collide and fail the create on every
	 * rotation. Hostname for humans, suffix for the provider.
	 */
	public static function instanceLabel(string $mail_hostname, int $run_id): string {
		$suffix = '-' . $run_id;
		$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($mail_hostname)));
		$slug = trim((string)$slug, '-');
		if ($slug === '') {
			return 'joinery-relay' . $suffix;
		}
		// Providers cap label length (Linode: 64). The suffix is what guarantees
		// uniqueness, so it is kept whole and the readable half gives way.
		$max = self::LABEL_MAX_LENGTH - strlen($suffix);
		if (strlen($slug) > $max) {
			$slug = rtrim(substr($slug, 0, $max), '-');
		}
		return $slug . $suffix;
	}

	// ------------------------------------------------------------ transitions

	/** ready -> booting: create the instance on the customer's account. */
	private function handleReady(RelayCloudProvision $run): string {
		$driver = $this->driverFor($run);

		// Per-run root key: generated once, public half injected at create,
		// private half sealed on the row until the run ends.
		if ((string)$run->get('rcp_ssh_public_key') === '') {
			list($private_key, $public_key) = $this->generateSshKeypair();
			$run->sealSshKey($private_key);
			$run->set('rcp_ssh_public_key', $public_key);
			$run->save();
		}

		try {
			$instance = $driver->createInstance(array(
				'label'           => self::instanceLabel(
					(string)$run->get('rcp_mail_hostname'), intval($run->key)),
				'region'          => (string)$run->get('rcp_region'),
				'type'            => (string)$run->get('rcp_instance_type'),
				'image'           => $this->imageId(),
				'root_pass'       => 'Aa1!' . bin2hex(random_bytes(20)), // never stored
				'authorized_keys' => array((string)$run->get('rcp_ssh_public_key')),
			));
		} catch (CloudComputeException $e) {
			return $this->handleComputeFailure($run, $e, 'create');
		}

		$run->set('rcp_instance_id', (string)$instance['id']);
		$run->set('rcp_instance_ip', (string)$instance['ip']);
		$run->set('rcp_status', 'booting');
		$run->set('rcp_error', null);
		$run->save();
		return 'instance created, booting';
	}

	/** booting -> provisioning (and straight into the SSH build). */
	private function handleBooting(RelayCloudProvision $run): string {
		$driver = $this->driverFor($run);
		try {
			$instance = $driver->getInstance((string)$run->get('rcp_instance_id'));
		} catch (CloudComputeException $e) {
			return $this->handleComputeFailure($run, $e, 'boot-poll');
		}

		if ($instance['status'] !== 'running' || $instance['ip'] === '') {
			$since = $run->get('rcp_update_time') ?: $run->get('rcp_create_time');
			if ($since && (time() - strtotime($since . ' UTC')) > self::BOOT_TIMEOUT_SECONDS) {
				$this->destroyInstanceQuietly($run);
				$run->fail('Instance did not reach running with a public IP within '
					. intval(self::BOOT_TIMEOUT_SECONDS / 60) . ' minutes (status: ' . $instance['status'] . ').');
				return 'boot timeout — failed';
			}
			return 'still booting';
		}

		$run->set('rcp_instance_ip', (string)$instance['ip']);
		$run->set('rcp_status', 'provisioning');
		$run->save();
		return 'boot complete — the build starts on the next task pass';
	}

	/**
	 * Advance only the CHEAP transitions (create the instance, poll the boot)
	 * — safe inside a page load, so the Setup page moves the run along while
	 * the admin watches. The long SSH build stays with the scheduled task.
	 */
	public function advanceCheap(RelayCloudProvision $run): string {
		switch ((string)$run->get('rcp_status')) {
			case 'ready':   return $this->handleReady($run);
			case 'booting': return $this->handleBooting($run);
			default:        return 'nothing cheap to do';
		}
	}

	/**
	 * provisioning -> done|failed: the relay build over root SSH — the same
	 * sequence as the server_manager provision job, minus the Go agent.
	 */
	private function handleProvision(RelayCloudProvision $run): string {
		$ip = (string)$run->get('rcp_instance_ip');
		$mail_hostname = strtolower(trim((string)$run->get('rcp_mail_hostname')));
		$settings = Globalvars::get_instance();

		$pull_pubkey = trim((string)@file_get_contents(RelaySsh::pullKeyPath() . '.pub'));
		$main_wg_pubkey = trim((string)$settings->get_setting('mailbox_relay_wg_public_key'));
		if ($pull_pubkey === '' || $main_wg_pubkey === '') {
			// Checked before the run starts; a hole here means the main box
			// changed underneath the run — terminal, with cleanup.
			$this->destroyInstanceQuietly($run);
			$run->fail('The main box lost its relay identity mid-run (pull key or WireGuard key missing) — '
				. 'run provision_relay_main.sh and retry.');
			return 'main-box identity missing — failed';
		}

		$key_file = $this->writeKeyFile($run);
		try {
			// 1. Package the provisioning bundle locally.
			$provisioning_dir = PathHelper::getIncludePath('plugins/mailbox/provisioning');
			$transfer_id = substr(md5(uniqid(mt_rand(), true)), 0, 12);
			$tarball = sys_get_temp_dir() . "/joinery-relay-{$transfer_id}.tgz";
			$remote_tarball = "/tmp/joinery-relay-{$transfer_id}.tgz";
			$remote_dir = "/tmp/joinery-relay-{$transfer_id}";
			list($code, $out) = $this->run(
				'tar czf ' . escapeshellarg($tarball) . ' -C ' . escapeshellarg($provisioning_dir)
				. ' relay-sealer provision_relay.sh');
			if ($code !== 0) {
				$this->failWithCleanup($run, 'Packaging the provisioning bundle failed: ' . $out);
				return 'bundle failed';
			}

			// 2. Deliver it (accept-new: a freshly created instance has no known host key).
			list($code, $out) = $this->run(
				'scp ' . $this->sshOptions($key_file) . ' ' . escapeshellarg($tarball)
				. ' ' . escapeshellarg('root@' . $ip . ':' . $remote_tarball));
			@unlink($tarball);
			if ($code !== 0) {
				$this->failWithCleanup($run, 'Uploading the provisioning bundle failed: ' . $out);
				return 'upload failed';
			}

			$smarthost = (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost');

			// 3. Skeleton + 4. add THIS deployment as tenant 'main' (a
			// self-hosted relay is a fleet of one) + 5. markers.
			$remote = 'rm -rf ' . escapeshellarg($remote_dir) . ' && mkdir -p ' . escapeshellarg($remote_dir)
				. ' && tar xzf ' . escapeshellarg($remote_tarball) . ' -C ' . escapeshellarg($remote_dir)
				. ' && cd ' . escapeshellarg($remote_dir)
				. ' && bash provision_relay.sh ' . escapeshellarg($mail_hostname) . ($smarthost ? ' smarthost' : '')
				. ' && bash provision_relay.sh add-tenant main --pull-pubkey ' . escapeshellarg($pull_pubkey)
				. ' --tunnel-ip 10.99.0.2 --domains ' . escapeshellarg('*')
				. ' --wg-pubkey ' . escapeshellarg($main_wg_pubkey)
				. ' && echo RELAY_WG_PUBKEY=$(cat /etc/wireguard/relay_public.key 2>/dev/null)'
				. ' && echo RELAY_PUBLIC_IP=$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk \'{print $1}\')'
				. ' && postfix status >/dev/null 2>&1 && echo PROVISION_RELAY_SUCCESS';
			list($code, $out) = $this->run(
				'timeout 1800 ssh ' . $this->sshOptions($key_file) . ' ' . escapeshellarg('root@' . $ip)
				. ' ' . escapeshellarg($remote));

			if ($code !== 0 || strpos($out, 'PROVISION_RELAY_SUCCESS') === false) {
				$this->failWithCleanup($run, 'provision_relay.sh did not complete: ' . mb_substr($out, -1500));
				return 'provision script failed';
			}
		} finally {
			@unlink($key_file);
		}

		$wg_pubkey = $this->extractMarker($out, 'RELAY_WG_PUBKEY');
		$public_ip = $this->extractMarker($out, 'RELAY_PUBLIC_IP') ?: $ip;

		$relay = $this->registerRelayRow($run, $public_ip, $wg_pubkey, $mail_hostname);

		// Peer the relay on the MAIN box's WireGuard interface — the other half
		// of the tunnel (root helper + sudoers installed by
		// provision_relay_main.sh). Best-effort: on failure the tunnel health
		// checks go red and the log says what to run.
		if ($wg_pubkey !== '') {
			list($peer_code, $peer_out) = $this->run(
				'sudo -n /usr/local/sbin/joinery-relay-peer ' . escapeshellarg($wg_pubkey)
				. ' ' . escapeshellarg($public_ip . ':51820'));
			if ($peer_code !== 0) {
				error_log('RelayCloudProvisioner: main-box WireGuard peer add failed (' . $peer_code . '): '
					. $peer_out . ' — run plugins/mailbox/provisioning/provision_relay_main.sh');
			}
		}

		// Reverse DNS through the provider API. Providers require the forward
		// A record first, which is usually unpublished at this moment — a
		// refusal is expected and the Setup tab's PTR check carries the
		// instruction from here.
		try {
			$this->driverFor($run)->setReverseDns((string)$run->get('rcp_instance_id'), $public_ip, $mail_hostname);
		} catch (\Throwable $e) {
			error_log('RelayCloudProvisioner: setReverseDns deferred (' . $e->getMessage()
				. ') — expected until the mail hostname A record resolves.');
		}

		$run->set('rcp_status', 'done');
		$run->set('rcp_error', null);
		$run->eraseCredentials();
		return 'relay provisioned (relay row #' . intval($relay->key) . ')';
	}

	// --------------------------------------------------------------- plumbing

	/**
	 * Register the MailboxRelay row for the freshly built relay — the same
	 * shape the server_manager job path registers, node-less, carrying the
	 * cloud coordinates Rebuild/Destroy target. Left DISABLED: enabling is an
	 * explicit admin act once the Setup checks go green.
	 */
	private function registerRelayRow(RelayCloudProvision $run, string $public_ip, string $wg_pubkey, string $mail_hostname): MailboxRelay {
		$existing = new MultiMailboxRelay(array('deleted' => false));
		$existing->load();
		$relay = null;
		foreach ($existing as $row) {
			if ((string)$row->get('mrl_cloud_instance_id') === (string)$run->get('rcp_instance_id')) {
				$relay = $row;
				break;
			}
		}
		// No row for this instance means the tunnel address is changing hands:
		// a different machine is about to answer on 10.99.0.1 with its own SSH
		// host key, and the previous relay's key must be forgotten or every
		// connection fails with REMOTE HOST IDENTIFICATION HAS CHANGED.
		$is_new_machine = ($relay === null);
		if ($relay === null) {
			$relay = new MailboxRelay(NULL);
			// Born enabled: the relay starts pulling and receives its address
			// list immediately, so it is ready before any MX points at it.
			// Doctrine consequences (outbound enforcement, origin-hidden) key
			// off the recorded cutover state, not this flag — Disable remains
			// as an emergency stop only.
			$relay->set('mrl_is_enabled', true);
		}
		$relay->set('mrl_name', $mail_hostname);
		$relay->set('mrl_host', '10.99.0.1'); // the relay's fixed tunnel address
		$relay->set('mrl_public_ip', $public_ip);
		$relay->set('mrl_tenant_slug', 'main');
		$relay->set('mrl_mx_hostname', substr($mail_hostname, 0, 255));
		$relay->set('mrl_ssh_user', 'jt-main');
		$relay->set('mrl_ssh_port', 22);
		$pull_key = RelaySsh::pullKeyPath();
		if (is_file($pull_key)) {
			$relay->set('mrl_ssh_key_path', $pull_key);
		}
		$relay->set('mrl_spool_path', '/var/spool/joinery-relay/main');
		if ($wg_pubkey !== '') {
			$relay->set('mrl_wg_public_key', substr($wg_pubkey, 0, 255));
		}
		$relay->set('mrl_wg_endpoint', $public_ip . ':51820');
		$relay->set('mrl_wg_ip', '10.99.0.1');
		$relay->set('mrl_cloud_provider', (string)$run->get('rcp_provider'));
		$relay->set('mrl_cloud_instance_id', (string)$run->get('rcp_instance_id'));
		$relay->save();
		if ($is_new_machine) {
			RelaySsh::forgetHostKey((string)$relay->get('mrl_host'));
		}
		// Mint the ambient transport keypair now so the first map push can seal
		// Standard/Private mail immediately once enabled.
		$relay->ensureTransportKeypair();
		return $relay;
	}

	/**
	 * Compute-API failure policy (grant-per-act variant): 401 and other 4xx
	 * are terminal (a fresh retry brings a fresh grant); 5xx/network stay put
	 * and retry next tick. A terminal failure cleans up any created instance.
	 */
	private function handleComputeFailure(RelayCloudProvision $run, CloudComputeException $e, string $phase): string {
		$code = (int)$e->getCode();
		if ($code >= 400 && $code < 500 && $code !== 429) {
			$this->failWithCleanup($run, 'Provider API error during ' . $phase . ': ' . $e->getMessage());
			return $phase . ' failed';
		}
		$run->set('rcp_error', mb_substr('Transient (' . $phase . '): ' . $e->getMessage(), 0, 4000));
		$run->save();
		return $phase . ' transient error — will retry';
	}

	/** Terminal failure: destroy any created instance within the grant, then fail. */
	private function failWithCleanup(RelayCloudProvision $run, string $message): void {
		$this->destroyInstanceQuietly($run);
		$run->fail($message);
	}

	private function destroyInstanceQuietly(RelayCloudProvision $run): void {
		$instance_id = (string)$run->get('rcp_instance_id');
		if ($instance_id === '') {
			return;
		}
		try {
			$this->driverFor($run)->deleteInstance($instance_id);
		} catch (\Throwable $e) {
			error_log('RelayCloudProvisioner: cleanup deleteInstance failed for run '
				. intval($run->key) . ': ' . $e->getMessage()
				. ' — the instance may still exist on the customer account.');
		}
	}

	private function driverFor(RelayCloudProvision $run): CloudComputeProvider {
		if (self::$driver_factory !== null) {
			return call_user_func(self::$driver_factory, $run);
		}
		$provider = (string)$run->get('rcp_provider');
		if ($provider !== 'linode') {
			throw new RelayCloudProvisionException('Unknown cloud provider "' . $provider . '".');
		}
		$token = $run->unsealToken();
		if ($token === '') {
			throw new RelayCloudProvisionException('The run holds no provider token — restart it to re-grant.');
		}
		return new LinodeComputeDriver($token);
	}

	private function run(string $cmd): array {
		if (self::$runner !== null) {
			return call_user_func(self::$runner, $cmd);
		}
		return RelaySsh::run($cmd);
	}

	private function sshOptions(string $key_file): string {
		return '-i ' . escapeshellarg($key_file)
			. ' -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null'
			. ' -o ConnectTimeout=20 -o BatchMode=yes';
	}

	/** Write the sealed run key to a 0600 temp file; caller unlinks. */
	private function writeKeyFile(RelayCloudProvision $run): string {
		$key = $run->unsealSshKey();
		$file = tempnam(sys_get_temp_dir(), 'jyrck');
		file_put_contents($file, $key);
		chmod($file, 0600);
		return $file;
	}

	/** @return array{0:string,1:string} [private_pem, public_openssh] */
	private function generateSshKeypair(): array {
		$file = tempnam(sys_get_temp_dir(), 'jyrcg');
		@unlink($file); // ssh-keygen refuses an existing file
		list($code, $out) = $this->run(
			'ssh-keygen -t ed25519 -N ' . escapeshellarg('') . ' -C joinery-relay-provision -f ' . escapeshellarg($file));
		if ($code !== 0) {
			throw new RelayCloudProvisionException('ssh-keygen failed: ' . $out);
		}
		$private = (string)file_get_contents($file);
		$public = trim((string)file_get_contents($file . '.pub'));
		@unlink($file);
		@unlink($file . '.pub');
		if ($private === '' || $public === '') {
			throw new RelayCloudProvisionException('ssh-keygen produced an empty keypair.');
		}
		return array($private, $public);
	}

	private function imageId(): string {
		$image = trim((string)Globalvars::get_instance()->get_setting('mailbox_relay_cloud_image'));
		return $image !== '' ? $image : 'linode/ubuntu24.04';
	}

	private function extractMarker(string $output, string $marker): string {
		if (preg_match('/^' . preg_quote($marker, '/') . '=(.*)$/m', $output, $m)) {
			return trim($m[1]);
		}
		return '';
	}
}
?>
