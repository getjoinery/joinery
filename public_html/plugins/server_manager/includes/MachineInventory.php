<?php
/**
 * MachineInventory - every machine we pay for, on one list
 * (specs/fleet_security_patching.md WP8).
 *
 * On 2026-10-21 three old sites surfaced only because the owner remembered
 * them; one was not even live, and two ran a PHP nobody would ever patch. The
 * list has three sources:
 *
 *   - every machine this management node manages: a node that is not a site
 *     in a container, in whatever install state (a dormant copy and a retired
 *     source are machines we pay for), with its operating system from its own
 *     host report;
 *   - every server in each of our own cloud accounts this node holds a key
 *     for (OperatorCloudAccount; the test account is left out, since its
 *     servers are thrown away nightly, and a customer's Connect grant is the
 *     customer's), matched to a node by its addresses as the transfer watch
 *     matches them (MachineTransferWatch::nodes_on). A server no node runs on
 *     is listed as not managed, with what a note on it says it runs over the
 *     image it was made from;
 *   - the machines written down by hand (FleetHandMachine): those in an
 *     account no key here reads (Q6), notes on a listed server that is not
 *     managed on purpose, and notes on a node with no agent (the relay and
 *     the DNS servers are watched from here and never run one), whose own
 *     report can never say what it runs.
 *
 * The verdict, most serious first:
 *   red    its Ubuntu release is past standard support, or its retire-by date
 *          has passed
 *   amber  a server no node manages and nothing written down explains, a
 *          node with no agent and nothing written down saying what it runs,
 *          a hand-written row not confirmed for 90 days, a retired source, or
 *          a machine whose install failed
 *   grey   its agent has not reported what it runs yet
 *   green  none of these
 *
 * snapshot() reads the nodes, the providers and the hand rows; rows() is
 * pure, so the test drives it with arrays.
 *
 * @version 1.1 - a node with no agent can carry a note (fhm_instance_ref node:N) saying what it runs; with none
 *                it is amber, asking for one, instead of grey for ever
 * @version 1.0
 */
class MachineInventory {

	/**
	 * Ubuntu releases and the day their standard support ends. A release past
	 * it gets no security fixes without Ubuntu Pro (which keeps them coming
	 * for a fee, and is still a release to leave). Canonical's own pages give
	 * a coming end a month apart (the release-cycle page, the release notes,
	 * the release team's list); the table holds the first day of the earliest,
	 * so a row turns red early rather than late.
	 */
	const UBUNTU_SUPPORT_ENDS = array(
		'14.04' => '2019-04-30',
		'16.04' => '2021-04-30',
		'18.04' => '2023-05-31',
		'20.04' => '2025-05-31',
		'22.04' => '2027-04-01',
		'24.04' => '2029-05-01',
		'24.10' => '2025-07-10',
		'25.04' => '2026-01-15',
		'25.10' => '2026-07-09',
		'26.04' => '2031-04-01',
	);

	const ORDER = array('red' => 0, 'amber' => 1, 'grey' => 2, 'green' => 3);

	/** An Ubuntu release (24.04) from what names one: "Ubuntu 24.04", linode/ubuntu24.04, ubuntu-24.04. */
	public static function release_of(string $text): ?string {
		return preg_match('/ubuntu[ \-]?([0-9]{2}\.[0-9]{2})/i', $text, $m) ? $m[1] : null;
	}

	/** Whether a release is past standard support: true, false, or null when it is not one this knows. */
	public static function past_support(?string $release, int $now): ?bool {
		if ($release === null || !isset(self::UBUNTU_SUPPORT_ENDS[$release])) {
			return null;
		}
		return $now >= strtotime(self::UBUNTU_SUPPORT_ENDS[$release] . 'T00:00:00Z');
	}

	/**
	 * What rows() reads: the machines this node manages, our accounts' servers
	 * (or why an account could not be read), the hand rows, and the nodes'
	 * addresses for matching.
	 */
	public static function snapshot(): array {
		$nodes = array();
		foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $node) {
			// Every machine, in whatever install state: a dormant copy or a
			// retired source is a machine we pay for. A container is a site.
			if (trim((string)$node->get('mgn_container_name')) !== '') {
				continue;
			}
			if (CloudAccounts::of_node($node) === CloudAccounts::TEST) {
				continue;
			}
			$hr = $node->get('mgn_last_host_report');
			if (is_string($hr)) { $hr = json_decode($hr, true); }
			$hr = is_array($hr) ? JobResultProcessor::sanitise_host_report($hr) : null;
			$os = (is_array($hr) && is_array($hr['os'] ?? null) && $hr['os']['id'] === 'ubuntu' && $hr['os']['version'] !== 'unknown')
				? 'Ubuntu ' . $hr['os']['version'] : '';
			$nodes[(int)$node->key] = array(
				'id'        => (int)$node->key,
				'name'      => (string)$node->get('mgn_name'),
				'address'   => trim((string)$node->get('mgn_host')),
				'os'        => $os,
				'hosted_at' => CloudAccounts::hosted_at_label(CloudAccounts::hosted_at($node)),
				'state'     => trim((string)$node->get('mgn_install_state')),
				// No agent paired: nothing will ever report what it runs.
				'agent'     => trim((string)$node->get('mgn_agent_public_key')) !== '',
			);
		}
		$accounts = array();
		foreach (OperatorCloudAccount::held() as $oca) {
			if (!self::lists_account($oca)) {
				continue;
			}
			$entry = array('name' => $oca->label(), 'instances' => array(), 'error' => '');
			if (!ComputeDriverRegistry::supports($oca->provider(), 'CloudMachineTransfer')) {
				$entry['error'] = 'this provider cannot list its servers here';
			} elseif (($why = $oca->unusable_reason()) !== '') {
				$entry['error'] = $why;
			} else {
				try {
					$entry['instances'] = $oca->driver()->listInstances();
				} catch (Exception $e) {
					$entry['error'] = 'the provider did not list its servers: ' . $e->getMessage();
				}
			}
			$accounts[$oca->choice_key()] = $entry;
		}
		$hand = array();
		foreach (new MultiFleetHandMachine(array('deleted' => false), array('fhm_name' => 'ASC')) as $h) {
			$hand[] = array(
				'id'           => (int)$h->key,
				'name'         => (string)$h->get('fhm_name'),
				'address'      => (string)$h->get('fhm_address'),
				'where'        => (string)$h->get('fhm_where'),
				'instance_ref' => (string)$h->get('fhm_instance_ref'),
				'purpose'      => (string)$h->get('fhm_purpose'),
				'owner'        => (string)$h->get('fhm_owner'),
				'os'           => (string)$h->get('fhm_os'),
				'serves'       => (string)$h->get('fhm_serves'),
				'retire_by'    => (string)$h->get('fhm_retire_by'),
				'confirmed'    => (string)$h->get('fhm_confirmed_time'),
			);
		}
		$addresses = MachineTransferWatch::node_addresses(array('MachineTransferWatch', 'resolve'));
		return array('nodes' => $nodes, 'accounts' => $accounts, 'hand' => $hand,
			'addresses' => array_intersect_key($addresses, $nodes));
	}

	/**
	 * Whether an account's servers are ours to list: not the test account's
	 * (thrown away nightly), and not a customer's Connect grant (the
	 * customer's account, borrowed for one provision).
	 */
	public static function lists_account(OperatorCloudAccount $oca): bool {
		return !$oca->get('oca_is_test') && (string)$oca->get('oca_method') !== OperatorCloudAccount::METHOD_CONNECT;
	}

	/** The rows, most serious first, then by name. */
	public static function rows(array $snap, int $now): array {
		$hand_for = array();
		foreach ($snap['hand'] as $h) {
			if ($h['instance_ref'] !== '') { $hand_for[$h['instance_ref']] = $h; }
		}
		$node_instance = array();
		$rows = array();
		$listed = array();
		foreach ($snap['accounts'] as $key => $a) {
			foreach ($a['instances'] as $i) {
				$ref = $key . ':' . $i['id'];
				$listed[$ref] = true;
				$on = MachineTransferWatch::nodes_on($i, $snap['addresses']);
				if ($on) {
					foreach (array_keys($on) as $node_id) {
						$node_instance[$node_id] = array('where' => $a['name'], 'image' => (string)($i['image'] ?? ''), 'created' => (string)($i['created'] ?? ''), 'id' => (string)$i['id']);
					}
					continue;
				}
				$note = $hand_for[$ref] ?? null;
				$rows[] = self::verdict(array(
					'kind'      => 'unmanaged',
					'name'      => (string)($i['label'] ?? $i['id']),
					// Its IPv4, or its IPv6 where it has none.
					'address'   => (string)(((array)($i['ipv4_public'] ?? array()))[0] ?? preg_replace('#/\d+$#', '', (string)($i['ipv6'] ?? ''))),
					'where'     => $a['name'] . ', ' . (string)($i['region'] ?? ''),
					'instance'  => (string)$i['id'],
					'ref'       => $ref,
					// What a note says it runs now, over what it was made from.
					'os'        => ($note && trim($note['os']) !== '') ? $note['os']
						: (self::release_of((string)($i['image'] ?? '')) !== null ? 'Ubuntu ' . self::release_of((string)$i['image']) . ' (as made)' : (string)($i['image'] ?? '')),
					'created'   => (string)($i['created'] ?? ''),
					'status'    => (string)($i['status'] ?? ''),
					'state'     => '',
					'hand'      => $note,
					'node_id'   => null,
				), $now);
			}
		}
		foreach ($snap['nodes'] as $id => $n) {
			$inst = $node_instance[$id] ?? null;
			$ref = 'node:' . $id;
			// Its own report says what it runs; a note says it where no agent reports.
			$note = $hand_for[$ref] ?? null;
			$rows[] = self::verdict(array(
				'kind'      => 'managed',
				'name'      => $n['name'],
				'address'   => $n['address'],
				'where'     => $inst ? $inst['where'] : $n['hosted_at'],
				'instance'  => $inst ? $inst['id'] : '',
				'ref'       => $ref,
				'os'        => $n['os'] !== '' ? $n['os']
					: (($note && trim($note['os']) !== '') ? $note['os']
					: ($inst && self::release_of($inst['image']) !== null ? 'Ubuntu ' . self::release_of($inst['image']) . ' (as made)' : '')),
				'created'   => $inst ? $inst['created'] : '',
				'status'    => '',
				'state'     => (string)($n['state'] ?? ''),
				'hand'      => $note,
				'node_id'   => $id,
				'agent'     => !empty($n['agent']),
			), $now);
			$listed[$ref] = true;
		}
		foreach ($snap['hand'] as $h) {
			// A note on a listed server or a node is shown on that row.
			if ($h['instance_ref'] !== '' && isset($listed[$h['instance_ref']])) { continue; }
			$rows[] = self::verdict(array(
				'kind'      => 'hand',
				'name'      => $h['name'],
				'address'   => $h['address'],
				'where'     => $h['where'],
				'instance'  => '',
				'ref'       => $h['instance_ref'],
				'os'        => $h['os'],
				'created'   => '',
				'status'    => '',
				'state'     => '',
				'hand'      => $h,
				'node_id'   => null,
			), $now);
		}
		usort($rows, function ($a, $b) {
			return array(self::ORDER[$a['verdict']], strtolower($a['name'])) <=> array(self::ORDER[$b['verdict']], strtolower($b['name']));
		});
		return $rows;
	}

	/** A row's verdict and its reasons, most serious first. */
	private static function verdict(array $row, int $now): array {
		$red = array();
		$amber = array();
		$release = self::release_of($row['os']);
		$past = self::past_support($release, $now);
		if ($past === true) {
			$red[] = 'Ubuntu ' . $release . ' is past standard support (since ' . self::UBUNTU_SUPPORT_ENDS[$release] . ')';
		}
		$h = $row['hand'];
		if ($h !== null) {
			if ($h['retire_by'] !== '' && strtotime($h['retire_by'] . 'T00:00:00Z') <= $now) {
				$red[] = 'its retire-by date (' . $h['retire_by'] . ') has passed';
			}
			$confirmed = $h['confirmed'] !== '' ? strtotime($h['confirmed'] . ' UTC') : false;
			if ($confirmed === false || $now - $confirmed > FleetHandMachine::CONFIRM_DAYS * 86400) {
				$amber[] = $confirmed === false ? 'what is written about it has never been confirmed' : 'what is written about it was last confirmed over ' . FleetHandMachine::CONFIRM_DAYS . ' days ago';
			}
			if ($row['kind'] === 'hand' && trim($h['serves']) === '' && trim($h['retire_by']) === '') {
				$amber[] = 'nothing is written as served from it: retire it, or say what it serves';
			}
		} elseif ($row['kind'] === 'unmanaged') {
			$amber[] = 'no node manages it and nothing here says what it is: convert it, retire it, or write it down';
		}
		if ($row['kind'] === 'managed' && empty($row['agent']) && $release === null) {
			$amber[] = $h === null ? 'no agent reports from it, so nothing says what it runs: write it down'
				: 'no agent reports from it, and what is written does not name its release';
		}
		if ($row['state'] === 'retired') {
			$amber[] = 'a retired source, kept for the way back: delete it once that is no longer needed';
		} elseif ($row['state'] === 'install_failed') {
			$amber[] = 'its install failed: finish it, or delete the server';
		}
		$row['verdict'] = $red ? 'red' : ($amber ? 'amber' : ($release === null && $row['kind'] === 'managed' && $row['os'] === '' ? 'grey' : 'green'));
		$row['reasons'] = array_merge($red, $amber);
		if ($row['verdict'] === 'grey') {
			$row['reasons'][] = 'its agent has not reported what it runs yet';
		}
		return $row;
	}
}
?>
