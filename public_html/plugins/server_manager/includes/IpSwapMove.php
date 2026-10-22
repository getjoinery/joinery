<?php
/**
 * IpSwapMove - move a site to its copy by swapping the two servers' public
 * IPv4 addresses at the provider (specs/site_copy.md WP12, step 9).
 *
 * The address moves, and with it its reverse DNS and every fact bound to it:
 * the A records, SPF, the relay's and the registrar's allow-lists. Nothing
 * waits for a resolver cache. The limits are the provider's: both servers on
 * one account, in one region. IPv6 addresses never move.
 *
 *   plan()  read-only: both servers' provisions, the account, the region, one
 *           public IPv4 each, Network Helper on both (it writes the new address
 *           into the machine's network config at boot), every provider firewall
 *           of the source also on the copy, and nothing in the site's own AAAA
 *           records naming the source's IPv6. Records each address's reverse
 *           DNS, so the swap can be confirmed against it.
 *   step()  one local step of a switch-over, by name, against the recorded
 *           plan. Each is safe to call again: it looks at the provider first and
 *           does only what is not done yet, and says 'waiting' while a machine
 *           is still changing state.
 *
 *     power_off   shut a machine down; done once it is offline
 *     power_on    boot a machine that is off; done once it runs
 *     power_cycle reboot a machine (boot it when off) so Network Helper writes
 *                 the address it holds now; done once it runs again
 *     ip_swap     arg over: the source's address to the copy's machine and the
 *                 copy's to the source's; arg back: each to its own again. Then
 *                 each address's reverse DNS is checked (and put back if the
 *                 provider dropped it), and the records on this management node
 *                 follow: each provision's address and each node row's host.
 *
 * Machines are named by their instance ids in the plan, never by node rows:
 * the rows swap at step 10, the machines do not.
 *
 * The provider credential is the one the provision's account holds on this
 * management node (the operator's token, or the connected account's grant):
 * the same one that created the servers and sets their reverse DNS.
 *
 * @version 1.9 - the plan refuses a server on Linode Interfaces, whose address Linode will not swap
 *                (fleet_security_patching B8)
 * @version 1.8 - the plan says whether the copy is powered off for the swap too (both_off, CloudSwapWhileStopped)
 * @version 1.7 - any provider whose driver swaps addresses (CloudAddressSwap), both servers at the same one; the plan
 *                names the provider; powering a machine on or off needs only its driver, so a dead source at any
 *                provider is powered off (specs/site_copy_anything_to_anything.md D2)
 * @version 1.6 - swapping back records the AAAA record still at the copy as aaaa_left (its domain and both IPv6
 *                addresses), and aaaa_note() asks DNS each time it is shown, so one the owner has pointed back is
 *                no longer named (B66)
 * @version 1.5 - a copy enrolled here opens by itself from the moment this management node holds its key, as its
 *                node page says, not from its next host report (B62)
 * @version 1.4 - swapping back notes a site AAAA record still naming the copy's IPv6, which no swap moves back (B61);
 *                refused while the copy's encrypted data root has no escrow holder: the swap restarts it, and it
 *                would wait for its recovery key (B58)
 * @version 1.3 - an escrow row held here follows its machine's address (encrypted data root D3)
 * @version 1.2 - each of our accounts is its own account: two servers swap only within one (cloud_accounts_list)
 * @version 1.1 - a container site's shared server is never swapped or powered off
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_compute/CloudComputeProvider.php'));

class IpSwapMoveException extends Exception {}

class IpSwapMove {

	/**
	 * Builds the provider driver for a provision: NodeReverseDns::
	 * driverForProvision when null. A variable only so a test can stand in for
	 * the provider; nothing in production sets it.
	 *
	 * @var callable|null fn(CustomerCloudProvision): CloudAddressSwap
	 */
	public static $driver_for = null;

	/**
	 * Lists a name's AAAA addresses: dns_get_record when null. A variable for
	 * the reason $driver_for is one.
	 *
	 * @var callable|null fn(string): string[]
	 */
	public static $aaaa_lookup = null;

	/** The OAuth scope a connected account's grant needs for the swap. */
	const SCOPE = 'ips:read_write';

	/** And the one that reads which firewalls each server is behind. */
	const FIREWALL_SCOPE = 'firewall:read_only';

	/** How long a machine may take to reach the state a step waits for, from the step's first call. */
	const POWER_SECONDS = 600;

	/**
	 * After a reboot is asked, a machine that still reads 'running' this long
	 * after has rebooted (the provider reports the reboot as a status for only
	 * a few seconds on a fast machine).
	 */
	const REBOOT_SETTLE_SECONDS = 90;

	/**
	 * The provision that created a node's machine, with an instance, or null.
	 * Null for a container site: its machine is shared with other sites, so
	 * it is never this site's to power off or to take an address from.
	 */
	public static function provision_of(ManagedNode $node): ?CustomerCloudProvision {
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			return null;
		}
		$rows = new MultiCustomerCloudProvision(array('node_id' => (int)$node->key, 'deleted' => false),
			array('cvp_customer_cloud_provision_id' => 'DESC'));
		foreach ($rows as $row) {
			if (trim((string)$row->get('cvp_instance_id')) !== '') {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Could these two be swapped at all, from this management node's own
	 * records? The cheap half of plan(), for the page: no provider is asked.
	 * Empty when they could.
	 */
	public static function record_refusals(ManagedNode $source, ManagedNode $copy): array {
		$why = array();
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			return array('The site runs in a container on a server it shares with other sites; that server\'s address '
				. 'is every one of theirs, so it is never swapped.');
		}
		$s = self::provision_of($source);
		$c = self::provision_of($copy);
		if (!$s || !$c) {
			$why[] = 'An address swap needs both servers to have been created by this management node, at a provider that swaps addresses; '
				. (!$s ? 'the site\'s server was not.' : 'the copy\'s server was not.');
			return $why;
		}
		if ((string)$s->get('cvp_provider') !== (string)$c->get('cvp_provider')) {
			$why[] = 'The site\'s server is at ' . ComputeDriverRegistry::label((string)$s->get('cvp_provider')) . ' and the copy\'s at '
				. ComputeDriverRegistry::label((string)$c->get('cvp_provider')) . '; an address moves only within one provider.';
		}
		foreach (array('site\'s' => $s, 'copy\'s' => $c) as $whose => $p) {
			$provider = (string)$p->get('cvp_provider');
			if (!ComputeDriverRegistry::supports($provider, 'CloudAddressSwap')) {
				$why[] = "The {$whose} server is at " . ComputeDriverRegistry::label($provider) . ', where this management node '
					. 'does not swap addresses.';
			}
			if ($p->is_transferred()) {
				$why[] = "The {$whose} server was handed to its customer's own account.";
			}
		}
		if (!self::opens_by_itself($copy)) {
			$why[] = 'The copy\'s server keeps its data encrypted and no management node holds its unlock key yet, so the restart '
				. 'an address swap makes would leave it waiting for its recovery key. It is enrolled within minutes of reporting '
				. 'once its agent is ' . DataRootEscrowSync::DORMANT_COPY_MIN_AGENT_VERSION . ' or newer; its node page then names '
				. 'the holder. Try again then.';
		}
		if (self::account_of($s) !== self::account_of($c)) {
			$why[] = 'The two servers are on different cloud accounts; an address moves only between servers of one account.';
		}
		if (strtolower(trim((string)$s->get('cvp_region'))) !== strtolower(trim((string)$c->get('cvp_region')))) {
			$why[] = 'The site\'s server is in ' . $s->get('cvp_region') . ' and the copy\'s in ' . $c->get('cvp_region')
				. '; an address moves only within one region.';
		}
		if (!$s->is_operator_hosted() && (int)$s->get('cvp_cca_customer_cloud_account_id')) {
			$cca = new CustomerCloudAccount((int)$s->get('cvp_cca_customer_cloud_account_id'), TRUE);
			$scopes = (string)$cca->get('cca_scopes');
			if ($cca->key && (!self::scope_granted($scopes, self::SCOPE) || !self::scope_granted($scopes, self::FIREWALL_SCOPE))) {
				$why[] = 'The connected cloud account was granted without permission to move IP addresses and read firewalls ('
					. self::SCOPE . ', ' . self::FIREWALL_SCOPE . '). Re-connect it from its owner\'s profile, then try again.';
			}
		}
		return $why;
	}

	/**
	 * Whether a machine boots without anyone typing its recovery key: its data
	 * root is not encrypted, or an escrow holder opens it: its last report
	 * names one, or this management node holds the key of the machine that
	 * report describes (enrolled since it, B62). A machine that has not
	 * reported says nothing either way.
	 */
	public static function opens_by_itself(ManagedNode $node): bool {
		$hr = json_decode((string)$node->get('mgn_last_host_report'), true);
		$enc = is_array($hr) ? ($hr['data_root_encryption'] ?? null) : null;
		if (!is_array($enc) || trim((string)($enc['holder'] ?? '')) !== '') {
			return true;
		}
		$identity = (string)($enc['identity'] ?? '');
		$row = DataRootEscrow::for_node((int)$node->key);
		return $identity !== '' && $row && (string)$row->get('dre_status') === 'enrolled'
			&& hash_equals((string)$row->get('dre_identity_key'), $identity);
	}

	/** Which account a provision's instance lives on: 'oca:<id>' for one of ours, 'cca:<id>' for a connected one. */
	public static function account_of(CustomerCloudProvision $p): string {
		return $p->is_operator_hosted() ? 'oca:' . (int)$p->get('cvp_oca_operator_cloud_account_id')
			: 'cca:' . (int)$p->get('cvp_cca_customer_cloud_account_id');
	}

	/** Does a granted scope string cover $want? A read_write grant covers read_only. */
	public static function scope_granted(string $scopes, string $want): bool {
		$area = explode(':', $want)[0];
		foreach (preg_split('/[\s,]+/', trim($scopes)) as $scope) {
			if ($scope === '*' || $scope === $want || $scope === $area . ':read_write') {
				return true;
			}
		}
		return false;
	}

	/**
	 * The swap, checked with the provider. Read-only.
	 *
	 * @return array the plan the steps act on
	 * @throws IpSwapMoveException naming everything that stops it
	 */
	public static function plan(ManagedNode $source, ManagedNode $copy, string $domain): array {
		$why = self::record_refusals($source, $copy);
		if ($why) {
			throw new IpSwapMoveException(implode(' ', $why));
		}
		$sp = self::provision_of($source);
		$cp = self::provision_of($copy);
		try {
			$driver = self::driver($sp);
			$s = $driver->addressReport((string)$sp->get('cvp_instance_id'));
			$c = $driver->addressReport((string)$cp->get('cvp_instance_id'));
		} catch (Exception $e) {
			throw new IpSwapMoveException('The servers could not be read at the provider: ' . $e->getMessage());
		}
		$why = array();
		foreach (array('site\'s' => $s, 'copy\'s' => $c) as $whose => $r) {
			if (count($r['ipv4_public']) !== 1) {
				$why[] = "The {$whose} server has " . count($r['ipv4_public']) . ' public IPv4 addresses ('
					. implode(', ', $r['ipv4_public']) . '); a swap moves one, so each server must have exactly one.';
			}
			// Linode refuses to swap an address a Linode-generation interface
			// holds, and a machine is upgraded to that generation one way only
			// (2026-10-21, xandyliberato.com: the swap failed mid-move; B8).
			if (($r['interface_generation'] ?? '') === 'linode') {
				$why[] = "The {$whose} server uses Linode Interfaces, and Linode will not swap an address an interface holds. "
					. 'Make it again with configuration-profile interfaces (servers this management node makes have them), '
					. 'or switch by DNS or by the proxy instead.';
			}
			if ($r['network_helper'] === null) {
				$why[] = "Whether Network Helper is on for the {$whose} server could not be read.";
			} elseif (!$r['network_helper']) {
				$why[] = "Network Helper is off for the {$whose} server, so it would not take up its new address at boot. "
					. 'Turn it on in Cloud Manager (the Linode, Configurations or Settings), then try again.';
			}
		}
		if (strtolower((string)$s['region']) !== strtolower((string)$c['region'])) {
			$why[] = 'The provider reports the servers in ' . $s['region'] . ' and ' . $c['region'] . '.';
		}
		if ($why) {
			throw new IpSwapMoveException(implode(' ', $why));
		}
		$s_ip = $s['ipv4_public'][0];
		$c_ip = $c['ipv4_public'][0];

		try {
			$s_addr = $driver->ipAddress($s_ip);
			$c_addr = $driver->ipAddress($c_ip);
			$s_fw = $driver->instanceFirewalls((string)$sp->get('cvp_instance_id'));
			$c_fw = $driver->instanceFirewalls((string)$cp->get('cvp_instance_id'));
		} catch (Exception $e) {
			throw new IpSwapMoveException('The addresses or firewalls could not be read at the provider: ' . $e->getMessage()
				. ' The token needs ' . self::SCOPE . ' and firewall:read_only.');
		}
		$missing = array_diff_key($s_fw, $c_fw);
		if ($missing) {
			$why[] = 'The site\'s server is behind provider firewall(s) the copy\'s is not: ' . implode(', ', $missing)
				. '. Attach them to the copy\'s server too, so it admits no more than the site\'s does.';
		}
		$s_v6 = (string)$s['ipv6'];
		if ($s_v6 !== '') {
			foreach (self::aaaa($domain) as $a) {
				if (@inet_pton($a) === @inet_pton($s_v6)) {
					$why[] = "{$domain} has an AAAA record for the site's server's IPv6 address {$s_v6}, and IPv6 addresses do "
						. 'not move. Remove it, or point it at the copy\'s IPv6 (' . ($c['ipv6'] ?: 'none') . '), before switching; '
						. 'if the site goes back, point it back.';
				}
			}
		}
		if ($why) {
			throw new IpSwapMoveException(implode(' ', $why));
		}
		return array(
			'provider' => (string)$sp->get('cvp_provider'),
			// Hetzner moves an address only between stopped servers: the copy
			// is powered off before the swap too, and on again after.
			'both_off' => $driver instanceof CloudSwapWhileStopped,
			'region'   => (string)$s['region'],
			'domain'   => $domain,
			'source'   => array('provision_id' => (int)$sp->key, 'instance_id' => (string)$sp->get('cvp_instance_id'),
				'ip' => $s_ip, 'ipv6' => $s_v6, 'rdns' => (string)$s_addr['rdns']),
			'copy'     => array('provision_id' => (int)$cp->key, 'instance_id' => (string)$cp->get('cvp_instance_id'),
				'ip' => $c_ip, 'ipv6' => (string)$c['ipv6'], 'rdns' => (string)$c_addr['rdns']),
		);
	}

	/** What stays behind at the site's server's IPv6 address, for the page. */
	public static function ipv6_note(array $plan): string {
		$v6 = (string)($plan['source']['ipv6'] ?? '');
		return $v6 === '' ? '' : "The site's server's IPv6 address {$v6} does not move: anything else naming it (an SPF ip6: "
			. 'entry, IPv6 reverse DNS, an allow-list) keeps naming the old server.';
	}

	/**
	 * One local step. Returns 'done' or 'waiting'; throws when it cannot be
	 * done. $state is the step's own memory between calls.
	 */
	public static function step(string $op, string $arg, array $plan, array &$state): string {
		// Powering a machine needs only its driver; a reboot and the swap
		// need the swap's capability.
		$p = self::plan_provision($plan, $op === 'ip_swap' ? 'source' : $arg);
		$driver = in_array($op, array('power_off', 'power_on'), true) ? self::power_driver($p) : self::driver($p);
		switch ($op) {
			case 'power_off':
			case 'power_on':
			case 'power_cycle':
				return self::power($driver, $op, (string)$plan[$arg]['instance_id'], $state);
			case 'ip_swap':
				return self::swap($driver, $arg === 'back', $plan, $state);
		}
		throw new IpSwapMoveException("unknown step {$op}");
	}

	private static function power($driver, string $op, string $instance_id, array &$state): string {
		$status = (string)$driver->getInstance($instance_id)['status'];
		$now = time();
		$state['first'] = $state['first'] ?? $now;
		if ($now - (int)$state['first'] > self::POWER_SECONDS) {
			throw new IpSwapMoveException("instance {$instance_id} is still {$status} after " . self::POWER_SECONDS . ' seconds');
		}
		if ($op === 'power_off') {
			if ($status === 'offline') {
				return 'done';
			}
			if (empty($state['asked']) && $status === 'running') {
				$driver->shutdownInstance($instance_id);
				$state['asked'] = $now;
			}
			return 'waiting';
		}
		if ($op === 'power_on') {
			if ($status === 'running') {
				return 'done';
			}
			if (empty($state['asked']) && $status === 'offline') {
				$driver->bootInstance($instance_id);
				$state['asked'] = $now;
			}
			return 'waiting';
		}
		// power_cycle: done only once the machine has started again since asked.
		if (empty($state['asked'])) {
			if ($status === 'offline') {
				$driver->bootInstance($instance_id);
			} elseif ($status === 'running') {
				$driver->rebootInstance($instance_id);
			} else {
				return 'waiting'; // mid-transition: ask once it settles
			}
			$state['asked'] = $now;
			return 'waiting';
		}
		if ($status !== 'running') {
			$state['seen_down'] = true;
			return 'waiting';
		}
		return (!empty($state['seen_down']) || $now - (int)$state['asked'] >= self::REBOOT_SETTLE_SECONDS) ? 'done' : 'waiting';
	}

	private static function swap($driver, bool $back, array $plan, array &$state): string {
		$s_ip = (string)$plan['source']['ip'];
		$c_ip = (string)$plan['copy']['ip'];
		$s_inst = (string)$plan['source']['instance_id'];
		$c_inst = (string)$plan['copy']['instance_id'];
		$want = $back ? array($s_ip => $s_inst, $c_ip => $c_inst) : array($s_ip => $c_inst, $c_ip => $s_inst);

		if (!self::assigned_as($driver, $want)) {
			if (!empty($state['asked'])) {
				throw new IpSwapMoveException('the provider accepted the swap, and the addresses do not read as swapped');
			}
			$driver->assignIpv4((string)$plan['region'], $want);
			$state['asked'] = time();
			if (!self::assigned_as($driver, $want)) {
				throw new IpSwapMoveException('the provider accepted the swap, and the addresses do not read as swapped');
			}
		}
		// Reverse DNS belongs to the address and moves with it. Checked, and
		// put back where it was dropped.
		$state['rdns'] = array();
		foreach ($want as $ip => $inst) {
			$had = (string)($ip === $s_ip ? $plan['source']['rdns'] : $plan['copy']['rdns']);
			$now = (string)$driver->ipAddress($ip)['rdns'];
			if ($had !== '' && rtrim(strtolower($now), '.') !== rtrim(strtolower($had), '.')) {
				$driver->setReverseDns($inst, $ip, rtrim($had, '.'));
				$state['rdns'][] = "{$ip}'s reverse DNS read '{$now}' after the move and was set back to '{$had}'.";
			}
		}
		self::follow_records($plan, $want);
		// IPv6 addresses never move: a record the owner pointed at the copy's
		// before switching still sends IPv6 visitors there (B61). Recorded as
		// what it is, so it is named only while DNS still says so (B66).
		$state['aaaa_left'] = null;
		if ($back) {
			$left = array('domain' => (string)($plan['domain'] ?? ''), 'copy_ipv6' => (string)($plan['copy']['ipv6'] ?? ''),
				'source_ipv6' => (string)($plan['source']['ipv6'] ?? ''));
			if (self::aaaa_note($left) !== '') {
				$state['aaaa_left'] = $left;
			}
		}
		return 'done';
	}

	/**
	 * What to tell the owner about a site AAAA record left at the copy's IPv6
	 * ($left: domain, copy_ipv6, source_ipv6, as a swap back records it), or ''
	 * when DNS no longer names it. Asked each time: the owner fixes the record
	 * at their DNS provider, which tells us nothing.
	 */
	public static function aaaa_note($left): string {
		$domain = is_array($left) ? (string)($left['domain'] ?? '') : '';
		$c_v6 = is_array($left) ? (string)($left['copy_ipv6'] ?? '') : '';
		if ($domain === '' || $c_v6 === '' || @inet_pton($c_v6) === false) {
			return '';
		}
		foreach (self::aaaa($domain) as $a) {
			if (@inet_pton($a) === @inet_pton($c_v6)) {
				$s_v6 = (string)($left['source_ipv6'] ?? '');
				return "{$domain} has an AAAA record for the copy's IPv6 address {$c_v6}, which did not move back: "
					. 'IPv6 visitors still reach the copy. Point it at the site\'s server' . ($s_v6 !== '' ? " ({$s_v6})" : '')
					. ', or remove it.';
			}
		}
		return '';
	}

	/** Do the provider's records put each address on the instance wanted? */
	private static function assigned_as(CloudAddressSwap $driver, array $want): bool {
		foreach ($want as $ip => $inst) {
			if ((string)$driver->ipAddress((string)$ip)['instance_id'] !== (string)$inst) {
				return false;
			}
		}
		return true;
	}

	/**
	 * This management node's records follow the machines: each provision's
	 * address is the one its instance holds now, and each node row whose host
	 * was one of the two addresses names the address its machine holds now.
	 * Which row describes which machine is read from the provision links,
	 * which follow the machines through the row swap.
	 */
	private static function follow_records(array $plan, array $now): void {
		$ip_of = array_flip($now); // instance id => address it holds now
		$old = array((string)$plan['source']['ip'], (string)$plan['copy']['ip']);
		foreach (array('source', 'copy') as $side) {
			$p = self::plan_provision($plan, $side);
			$inst = (string)$plan[$side]['instance_id'];
			if (isset($ip_of[$inst]) && (string)$p->get('cvp_instance_ip') !== (string)$ip_of[$inst]) {
				$p->set('cvp_instance_ip', (string)$ip_of[$inst]);
				$p->save();
			}
			$node_id = (int)$p->get('cvp_mgn_managed_node_id');
			if (!$node_id || !isset($ip_of[$inst])) {
				continue;
			}
			// An encrypted data root's escrow holder here answers its boot
			// only from addresses it knows (D3): the one this machine gave up
			// becomes the one it holds now.
			$gave_up = (string)$ip_of[$inst] === $old[0] ? $old[1] : $old[0];
			DataRootEscrow::address_moved($node_id, $gave_up, (string)$ip_of[$inst]);
			$node = new ManagedNode($node_id, TRUE);
			$host = trim((string)$node->get('mgn_host'));
			$packed = @inet_pton($host);
			foreach ($old as $a) {
				if ($packed !== false && $packed === @inet_pton($a) && $host !== (string)$ip_of[$inst]) {
					$node->set('mgn_host', (string)$ip_of[$inst]);
					$node->save();
					break;
				}
			}
		}
	}

	private static function plan_provision(array $plan, string $side): CustomerCloudProvision {
		$p = new CustomerCloudProvision((int)($plan[$side]['provision_id'] ?? 0), TRUE);
		if (!$p->key) {
			throw new IpSwapMoveException("the {$side} server's provision record is gone");
		}
		return $p;
	}

	/** @return CloudAddressSwap&CloudComputeProvider */
	private static function driver(CustomerCloudProvision $p) {
		$driver = self::$driver_for ? (self::$driver_for)($p) : NodeReverseDns::driverForProvision($p);
		if (!($driver instanceof CloudAddressSwap) || !($driver instanceof CloudComputeProvider)) {
			throw new IpSwapMoveException('This provider cannot swap addresses.');
		}
		return $driver;
	}

	/** The driver that powers a provision's machine on and off. */
	private static function power_driver(CustomerCloudProvision $p): CloudComputeProvider {
		$driver = self::$driver_for ? (self::$driver_for)($p) : NodeReverseDns::driverForProvision($p);
		if (!($driver instanceof CloudComputeProvider)) {
			throw new IpSwapMoveException('This provider\'s machines cannot be powered from here.');
		}
		return $driver;
	}

	private static function aaaa(string $domain): array {
		if (self::$aaaa_lookup) {
			return (array)(self::$aaaa_lookup)($domain);
		}
		$out = array();
		foreach ((array)@dns_get_record($domain, DNS_AAAA) as $r) {
			if (!empty($r['ipv6'])) {
				$out[] = (string)$r['ipv6'];
			}
		}
		return $out;
	}
}
