<?php
/**
 * HetznerComputeDriver - Hetzner Cloud API v1 implementation of
 * CloudComputeProvider (specs/site_copy_anything_to_anything.md D3).
 *
 * One API token reaches one Hetzner Cloud project, with every right the token
 * was made with (read, or read and write); tokens carry no scopes and no
 * expiry, so the driver implements neither CloudTokenScopes nor a key expiry
 * of its own, and the API has no name for the project: the account is held
 * under the name typed when it was added.
 *
 * Differences from Linode the platform meets:
 *
 *   - A server is created with no SSH key and its root password set by the
 *     first-boot user data (cloud-init), so the password the provisioning
 *     sealed before the create is the one that logs in.
 *   - Hetzner has no disk encryption of its own: asked 'disabled', there is
 *     nothing to turn off; asked 'enabled', the create is refused.
 *   - Status 'off' is reported as 'offline', the platform's word.
 *   - An address swap moves the two servers' primary IPv4 addresses, both
 *     servers off (CloudSwapWhileStopped), each move waited on. IPv6 is not moved: Hetzner writes a server's IPv6 into its
 *     own network configuration at first boot, so a moved IPv6 would not be
 *     taken up.
 *   - Its activity log is the server's actions, by Hetzner's own command names.
 *
 * @version 1.5 - a server's region is its location, read at the top of the server: the datacenter field it was read
 *               from answers null, so every server read as in no region and an IP swap refused it (B43)
 * @version 1.4 - listInstances carries each server's image name, for the list of every machine we pay for
 *               (fleet_security_patching WP8)
 * @version 1.3 - root's password is set by runcmd from its hash: Hetzner's vendor data set root's password after
 *               this user data's, so no install could log in (B32); the plaintext is no longer in the user data
 * @version 1.2 - plans are those a location sells now, by its own entry in each plan's list of locations, not its
 *               price list, which keeps naming a location a plan was withdrawn from or is sold out in (B31); the
 *               fallback plan is cpx22
 * @version 1.1 - the swap waits on each Hetzner action and refuses unless both servers are off
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/cloud_compute/CloudComputeProvider.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class HetznerComputeDriver implements CloudComputeProvider, CloudAddressSwap, CloudSwapWhileStopped, CloudMachineTransfer,
		CloudRegionFacts, CloudProviderEvents, CloudInstanceTypes, CloudKeyExpiry {

	const API_BASE = 'https://api.hetzner.cloud/v1/';

	/** The longest an action (an address unassigned or assigned) is waited on, in seconds. */
	const ACTION_SECONDS = 120;

	/**
	 * Waits between asking after an action: fn(int $seconds). Null sleeps. A
	 * variable only so a test does not wait; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $sleeper = null;

	/** Where and from what a form starts. */
	const DEFAULT_REGION = 'fsn1';
	const DEFAULT_IMAGE = 'ubuntu-26.04';
	/** The plan a form starts at when the list cannot be read: a current one the default location sells. */
	const FALLBACK_TYPE = 'cpx22';

	/** @var Client */
	private $http;
	/** @var string */
	private $access_token;

	public function __construct(string $access_token, ?Client $http = null) {
		$this->access_token = $access_token;
		$this->http = $http ?: new Client([
			'base_uri'        => self::API_BASE,
			'timeout'         => 30,
			'connect_timeout' => 10,
		]);
	}

	// ── CloudComputeProvider ─────────────────────────────────────────────────

	public function createInstance(array $opts): array {
		foreach (array('label', 'region', 'type', 'image') as $required) {
			if (empty($opts[$required])) {
				throw new CloudComputeException('createInstance missing required option: ' . $required);
			}
		}
		if ((string)($opts['disk_encryption'] ?? '') === 'enabled') {
			throw new CloudComputeException('Hetzner has no disk encryption of its own to turn on.', 400);
		}
		$body = array(
			'name'               => self::serverName((string)$opts['label']),
			'server_type'        => (string)$opts['type'],
			'image'              => (string)$opts['image'],
			'location'           => (string)$opts['region'],
			'start_after_create' => true,
			'public_net'         => array('enable_ipv4' => true, 'enable_ipv6' => true),
		);
		if (!empty($opts['authorized_keys'])) {
			throw new CloudComputeException('Hetzner takes SSH keys only as keys stored in the project; none are installed here.', 400);
		}
		$user_data = (string)($opts['user_data'] ?? '');
		if ($user_data === '' && (string)($opts['root_pass'] ?? '') !== '') {
			$user_data = self::passwordUserData((string)$opts['root_pass']);
		}
		if ($user_data !== '') {
			$body['user_data'] = $user_data;
		}
		$created = $this->request('POST', 'servers', $body);
		return $this->normalize((array)($created['server'] ?? array()));
	}

	public function getInstance(string $instance_id): array {
		return $this->normalize((array)($this->request('GET', 'servers/' . rawurlencode($instance_id))['server'] ?? array()));
	}

	public function rebuildInstance(string $instance_id, array $opts): array {
		if (empty($opts['image'])) {
			throw new CloudComputeException('rebuildInstance missing required option: image');
		}
		// A rebuild keeps the server and its primary addresses; the new root
		// password Hetzner returns is not the platform's, and user data is not
		// run again, so a rebuild here is for an image alone.
		$this->request('POST', 'servers/' . rawurlencode($instance_id) . '/actions/rebuild', array('image' => (string)$opts['image']));
		return $this->getInstance($instance_id);
	}

	public function deleteInstance(string $instance_id): void {
		$this->request('DELETE', 'servers/' . rawurlencode($instance_id));
	}

	public function shutdownInstance(string $instance_id): void {
		$this->request('POST', 'servers/' . rawurlencode($instance_id) . '/actions/shutdown');
	}

	public function bootInstance(string $instance_id): void {
		$this->request('POST', 'servers/' . rawurlencode($instance_id) . '/actions/poweron');
	}

	/**
	 * Hetzner counts each server's outgoing traffic against its own included
	 * traffic; the pool here is their sum, as the interface wants it.
	 */
	public function getTransfer(): array {
		$used = 0.0;
		$quota = 0.0;
		$billable = 0.0;
		foreach ($this->servers() as $s) {
			$out = (float)($s['outgoing_traffic'] ?? 0) / 1e9;
			$in = (float)($s['included_traffic'] ?? 0) / 1e9;
			$used += $out;
			$quota += $in;
			$billable += max(0.0, $out - $in);
		}
		return array('used_gb' => $used, 'quota_gb' => $quota, 'billable_gb' => $billable);
	}

	public function setReverseDns(string $instance_id, string $ip, string $hostname): array {
		$this->request('POST', 'servers/' . rawurlencode($instance_id) . '/actions/change_dns_ptr',
			array('ip' => $ip, 'dns_ptr' => $hostname));
		return array('ip' => $ip, 'rdns' => $hostname);
	}

	// ── CloudInstanceTypes ───────────────────────────────────────────────────

	/**
	 * The x86 plans still sold, smallest first; with a location, only those
	 * it sells new servers in.
	 */
	public function instanceTypes(string $region = ''): array {
		$out = array();
		foreach ($this->soldTypes($region) as $t) {
			$out[] = array('id' => (string)$t['name'], 'memory_mb' => (int)round((float)($t['memory'] ?? 0) * 1024),
				'disk_mb' => (int)((float)($t['disk'] ?? 0) * 1024));
		}
		usort($out, function ($a, $b) { return $a['memory_mb'] <=> $b['memory_mb'] ?: $a['disk_mb'] <=> $b['disk_mb']; });
		return $out;
	}

	public function defaultRegion(): string {
		return self::DEFAULT_REGION;
	}

	/** The cheapest x86 plan sold at $region (the default location when ''). */
	public function defaultInstanceType(string $region = ''): string {
		$region = $region !== '' ? $region : self::DEFAULT_REGION;
		try {
			$best = null;
			foreach ($this->soldTypes($region) as $t) {
				foreach ((array)($t['prices'] ?? array()) as $p) {
					if ((string)($p['location'] ?? '') === $region) {
						$monthly = (float)($p['price_monthly']['gross'] ?? 0);
						if ($best === null || $monthly < $best[1]) {
							$best = array((string)$t['name'], $monthly);
						}
					}
				}
			}
			return $best ? $best[0] : self::FALLBACK_TYPE;
		} catch (CloudComputeException $e) {
			return self::FALLBACK_TYPE;
		}
	}

	/**
	 * The x86 plans not being withdrawn, as the API lists them; with a
	 * location, only those whose entry for it in the plan's own list of
	 * locations says it is available there now and not being withdrawn.
	 * Hetzner says unavailable for a plan withdrawn from a location (cpx11 in
	 * fsn1, from 2026) and for one sold out there. A plan's price list is not
	 * the answer: it keeps naming a location the plan cannot be had in.
	 */
	private function soldTypes(string $region): array {
		$out = array();
		foreach ((array)($this->request('GET', 'server_types?per_page=50')['server_types'] ?? array()) as $t) {
			if (!empty($t['deprecated']) || !empty($t['deprecation']) || (string)($t['architecture'] ?? 'x86') !== 'x86') {
				continue;
			}
			if ($region !== '') {
				$sold = false;
				foreach ((array)($t['locations'] ?? array()) as $l) {
					if ((string)($l['name'] ?? '') === $region) {
						$sold = !empty($l['available']) && empty($l['deprecation']);
					}
				}
				if (!$sold) {
					continue;
				}
			}
			$out[] = $t;
		}
		return $out;
	}

	public function defaultImage(): string {
		return self::DEFAULT_IMAGE;
	}

	// ── CloudRegionFacts ─────────────────────────────────────────────────────

	public function regions(): array {
		$out = array();
		foreach ((array)($this->request('GET', 'locations')['locations'] ?? array()) as $l) {
			$facts = self::locationFacts((array)$l);
			if ($facts['id'] !== '') {
				$out[$facts['id']] = $facts;
			}
		}
		return $out;
	}

	public function regionInfo(string $region): array {
		if (trim($region) === '') {
			throw new CloudComputeException('regionInfo needs a region');
		}
		$all = $this->regions();
		if (!isset($all[$region])) {
			throw new CloudComputeException("Hetzner has no location '{$region}'.", 404);
		}
		return $all[$region];
	}

	private static function locationFacts(array $l): array {
		$id = (string)($l['name'] ?? '');
		return array('id' => $id, 'label' => (string)($l['description'] ?? $id), 'site_type' => 'core', 'disk_encryption' => false);
	}

	// ── CloudAddressSwap ─────────────────────────────────────────────────────

	public function addressReport(string $instance_id): array {
		$server = (array)($this->request('GET', 'servers/' . rawurlencode($instance_id))['server'] ?? array());
		$out = $this->normalize($server);
		$v4 = (string)($server['public_net']['ipv4']['ip'] ?? '');
		$out['ipv4_public'] = $v4 !== '' ? array($v4) : array();
		// Hetzner configures a server's IPv4 by DHCP at every boot: it takes
		// up an address moved to it, as Network Helper does at Linode.
		$out['network_helper'] = true;
		return $out;
	}

	public function ipAddress(string $address): array {
		$ip = $this->primaryIp($address);
		$rdns = '';
		foreach ((array)($ip['dns_ptr'] ?? array()) as $ptr) {
			if (@inet_pton((string)($ptr['ip'] ?? '')) === @inet_pton($address)) {
				$rdns = (string)($ptr['dns_ptr'] ?? '');
			}
		}
		return array('address' => $address, 'instance_id' => isset($ip['assignee_id']) && $ip['assignee_id'] !== null
			? (string)$ip['assignee_id'] : '', 'rdns' => $rdns);
	}

	/**
	 * Move primary IPv4 addresses between servers of this project. Hetzner
	 * moves a primary IP only to and from a stopped server, so every server
	 * named must be off (CloudSwapWhileStopped). Each address is unassigned,
	 * then assigned to its new server and kept when either server is later
	 * deleted; each is an action at Hetzner, waited on until it has succeeded,
	 * since an address under a running action is locked.
	 */
	public function assignIpv4(string $region, array $assignments): void {
		$ids = array();
		foreach ($assignments as $address => $instance_id) {
			$ip = $this->primaryIp((string)$address);
			$ids[(string)$address] = (int)$ip['id'];
			if ((string)($ip['assignee_id'] ?? '') === (string)$instance_id) {
				unset($ids[(string)$address]);
			}
		}
		$servers = array();
		foreach ($ids as $address => $id) {
			$servers[(string)$assignments[$address]] = true;
			$ip = $this->primaryIp($address);
			if (!empty($ip['assignee_id'])) {
				$servers[(string)$ip['assignee_id']] = true;
			}
		}
		foreach (array_keys($servers) as $server_id) {
			$status = (string)$this->getInstance((string)$server_id)['status'];
			if ($status !== 'offline') {
				throw new CloudComputeException("Hetzner moves an address only between stopped servers, and server {$server_id} is {$status}.");
			}
		}
		foreach ($ids as $address => $id) {
			$ip = $this->primaryIp($address);
			if (!empty($ip['assignee_id'])) {
				$this->waitFor($this->request('POST', 'primary_ips/' . $id . '/actions/unassign'));
			}
		}
		foreach ($ids as $address => $id) {
			$this->request('PUT', 'primary_ips/' . $id, array('auto_delete' => false));
			$this->waitFor($this->request('POST', 'primary_ips/' . $id . '/actions/assign',
				array('assignee_id' => (int)$assignments[$address], 'assignee_type' => 'server')));
		}
	}

	/**
	 * Wait for the primary IP action a request started to succeed, asked at
	 * the resource's own actions endpoint. Throws when it fails, or is still
	 * running after ACTION_SECONDS.
	 */
	private function waitFor(array $response): void {
		$action = (array)($response['action'] ?? array());
		$started = time();
		for (;;) {
			$status = (string)($action['status'] ?? '');
			if ($status === 'success' || !isset($action['id'])) {
				return;
			}
			if ($status === 'error') {
				throw new CloudComputeException('Hetzner action ' . (string)($action['command'] ?? $action['id']) . ' failed: '
					. (string)($action['error']['message'] ?? 'no reason given'));
			}
			if (time() - $started > self::ACTION_SECONDS) {
				throw new CloudComputeException('Hetzner action ' . (string)($action['command'] ?? $action['id']) . ' is still running after '
					. self::ACTION_SECONDS . ' seconds');
			}
			self::$sleeper ? (self::$sleeper)(2) : sleep(2);
			$action = (array)($this->request('GET', 'primary_ips/actions/' . (int)$action['id'])['action'] ?? array());
		}
	}

	public function instanceFirewalls(string $instance_id): array {
		$server = (array)($this->request('GET', 'servers/' . rawurlencode($instance_id))['server'] ?? array());
		$out = array();
		foreach ((array)($server['public_net']['firewalls'] ?? array()) as $fw) {
			if (isset($fw['id'])) {
				$fw_info = $this->request('GET', 'firewalls/' . (int)$fw['id']);
				$out[(string)$fw['id']] = (string)($fw_info['firewall']['name'] ?? $fw['id']);
			}
		}
		return $out;
	}

	public function rebootInstance(string $instance_id): void {
		$this->request('POST', 'servers/' . rawurlencode($instance_id) . '/actions/reboot');
	}

	// ── CloudProviderEvents ──────────────────────────────────────────────────

	public function instanceEvents(string $instance_id, string $since_utc): array {
		$out = array();
		$since = strtotime($since_utc . ' UTC') ?: 0;
		for ($page = 1; $page <= 10; $page++) {
			$r = $this->request('GET', 'servers/' . rawurlencode($instance_id) . '/actions?sort=started:asc&per_page=50&page=' . $page);
			foreach ((array)($r['actions'] ?? array()) as $a) {
				$when = strtotime((string)($a['started'] ?? '')) ?: 0;
				if ($when < $since) {
					continue;
				}
				$status = (string)($a['status'] ?? '');
				$out[] = array(
					'action'  => (string)($a['command'] ?? ''),
					'time'    => gmdate('Y-m-d H:i:s', $when),
					'status'  => $status === 'success' ? 'finished' : ($status === 'error' ? 'failed' : $status),
					'message' => (string)($a['error']['message'] ?? ''),
					'entity'  => 'server',
					'via'     => '',
				);
			}
			if (empty($r['meta']['pagination']['next_page'])) {
				break;
			}
		}
		return $out;
	}

	public function instanceForAddress(string $address): string {
		try {
			return $this->ipAddress($address)['instance_id'];
		} catch (CloudComputeException $e) {
			if ($e->getCode() === 404) {
				return '';
			}
			throw $e;
		}
	}

	// ── CloudMachineTransfer ─────────────────────────────────────────────────

	public function listInstances(): array {
		$out = array();
		foreach ($this->servers() as $s) {
			$n = $this->normalize($s);
			$v4 = (string)($s['public_net']['ipv4']['ip'] ?? '');
			$out[] = array('id' => $n['id'], 'status' => $n['status'], 'label' => $n['label'], 'region' => $n['region'],
				'ipv4_public' => $v4 !== '' ? array($v4) : array(), 'ipv6' => $n['ipv6'], 'disk_encryption' => $n['disk_encryption'],
				'created' => gmdate('Y-m-d H:i:s', strtotime((string)($s['created'] ?? '')) ?: 0),
				'image' => (string)($s['image']['name'] ?? ''),
				'tags' => array_values(array_map('strval', array_keys((array)($s['labels'] ?? array())))));
		}
		return $out;
	}

	public function getInstanceTransfer(string $instance_id): array {
		$s = (array)($this->request('GET', 'servers/' . rawurlencode($instance_id))['server'] ?? array());
		$used = (int)($s['outgoing_traffic'] ?? 0);
		$quota = (float)($s['included_traffic'] ?? 0) / 1e9;
		return array('used_bytes' => $used, 'quota_gb' => $quota, 'billable_gb' => max(0.0, $used / 1e9 - $quota));
	}

	// ── CloudKeyExpiry ───────────────────────────────────────────────────────

	/** A Hetzner Cloud token never expires: it lasts until it is deleted. */
	public function keyExpiry(): ?string {
		return null;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** A server name Hetzner accepts: letters, digits, dots and dashes, starting with a letter or digit. */
	private static function serverName(string $label): string {
		$name = strtolower(preg_replace('/[^A-Za-z0-9.-]+/', '-', $label));
		$name = trim($name, '-.');
		return $name !== '' ? substr($name, 0, 63) : 'joinery';
	}

	/**
	 * First-boot user data that sets root's password and lets root log in by
	 * it over SSH, as a Linode image does: the install logs in by the password
	 * the provisioning sealed before the create, and erases it at the join.
	 *
	 * Hetzner's own vendor data also sets root's password (a random one, in
	 * chpasswd's list form) and expires it, and cloud-init merges it with
	 * this: its password module applies the vendor's list after this data's
	 * users, so the vendor's would win. The password is therefore set by
	 * runcmd, which runs after every password module, and chpasswd's expire is
	 * turned off here, which this data's value decides. Only its SHA-512 crypt
	 * hash is in the user data: Hetzner's metadata service hands user data to
	 * any process on the server.
	 */
	private static function passwordUserData(string $password): string {
		$salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
		$hash = crypt($password, '$6$' . $salt . '$');
		$quoted = json_encode($hash, JSON_UNESCAPED_SLASHES);
		return "#cloud-config\nssh_pwauth: true\ndisable_root: false\nchpasswd:\n  expire: false\n"
			. "write_files:\n  - path: /etc/ssh/sshd_config.d/10-joinery-install.conf\n"
			. "    content: |\n      PermitRootLogin yes\nruncmd:\n  - [usermod, -p, " . $quoted . ", root]\n"
			. "  - [systemctl, restart, ssh]\n";
	}

	/** Every server in the project. */
	private function servers(): array {
		$out = array();
		for ($page = 1; $page <= 50; $page++) {
			$r = $this->request('GET', 'servers?per_page=50&page=' . $page);
			foreach ((array)($r['servers'] ?? array()) as $s) {
				$out[] = (array)$s;
			}
			if (empty($r['meta']['pagination']['next_page'])) {
				break;
			}
		}
		return $out;
	}

	/** The project's primary IP holding an address. @throws CloudComputeException 404 when none does */
	private function primaryIp(string $address): array {
		$r = $this->request('GET', 'primary_ips?ip=' . rawurlencode($address));
		foreach ((array)($r['primary_ips'] ?? array()) as $ip) {
			return (array)$ip;
		}
		throw new CloudComputeException("No primary IP in this project holds {$address}.", 404);
	}

	/** A Hetzner server object in the CloudComputeProvider shape. */
	private function normalize(array $s): array {
		$status = (string)($s['status'] ?? '');
		$v6 = (string)($s['public_net']['ipv6']['ip'] ?? '');
		if ($v6 !== '') {
			// Hetzner gives a server a /64 and the server answers on its ::1.
			$v6 = strtolower(rtrim(explode('/', $v6, 2)[0], ':')) . '::1';
		}
		return array(
			'id'     => isset($s['id']) ? (string)$s['id'] : '',
			'status' => $status === 'off' ? 'offline' : $status,
			'ip'     => (string)($s['public_net']['ipv4']['ip'] ?? ''),
			'ipv6'   => $v6,
			'label'  => (string)($s['name'] ?? ''),
			// The server's own location: Hetzner's datacenter field, which carried
			// it before, now answers null.
			'region' => (string)($s['location']['name'] ?? ''),
			// Hetzner has no disk encryption of its own.
			'disk_encryption' => 'disabled',
		);
	}

	/** Issue a request; decode its JSON. Throws CloudComputeException with Hetzner's own message on failure. */
	private function request(string $method, string $path, ?array $body = null): array {
		$options = array('headers' => array('Authorization' => 'Bearer ' . $this->access_token, 'Accept' => 'application/json'));
		if ($body !== null) {
			$options['json'] = $body;
		}
		try {
			$response = $this->http->request($method, $path, $options);
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			$decoded = $e->getResponse() ? json_decode((string)$e->getResponse()->getBody(), true) : null;
			$reason = (string)($decoded['error']['message'] ?? $e->getMessage());
			if ($status === 401) {
				throw new CloudComputeException('unauthorized: ' . $reason, 401, $e);
			}
			throw new CloudComputeException('Hetzner API ' . $method . ' ' . $path . ' failed (' . $status . '): ' . $reason, $status, $e);
		} catch (GuzzleException $e) {
			throw new CloudComputeException('Hetzner API ' . $method . ' ' . $path . ' failed (0): ' . $e->getMessage(), 0, $e);
		}
		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : array();
	}
}
