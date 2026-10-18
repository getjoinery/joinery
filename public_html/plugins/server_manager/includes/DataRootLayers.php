<?php
/**
 * DataRootLayers - how many layers of encryption a machine's data sits under:
 * ours (its data root's backing, from its host report) and its provider's
 * (recorded on its provision when it was made, or from the provider's own
 * listing by the daily machine transfer watch), and the sum
 * (specs/site_copy_final_test.md E4). One is the rule; two only on a
 * management node, which never copies itself onto a new server
 * (DataRootEncryptionOffer::may_keep_provider_layer); none of ours with the
 * provider's off is no layer at all.
 *
 * A container's data is its host's: its layers are its host's.
 *
 * @version 1.0
 */

class DataRootLayers {

	/**
	 * The machine's layers:
	 *   ours      true, false, or null when its host report does not say
	 *   provider  'enabled', 'disabled' or 'unknown'
	 *   count     the sum, or null while either side is unknown
	 *   line      the node page's words
	 *
	 */
	public static function of(ManagedNode $node): array {
		$machine = self::machine_of($node);
		$report = json_decode((string)$machine->get('mgn_last_host_report'), true);
		$enc = is_array($report) ? ($report['data_root_encryption'] ?? null) : null;
		$ours = null;
		if ($enc === 'none') {
			$ours = false;
		} elseif (is_array($enc) && isset($enc['backing'])) {
			$ours = strpos((string)$enc['backing'], 'luks-') === 0;
		}
		$provider = DataRootEncryptionOffer::provider_layer($machine);
		$count = ($ours === null || $provider === 'unknown') ? null : (int)$ours + (int)($provider === 'enabled');
		$said = array('enabled' => 'on', 'disabled' => 'off', 'unknown' => 'unknown')[$provider];
		$line = ($count === null ? 'Encryption layers: not known' : 'Encryption layers: ' . $count)
			. ' (ours: ' . ($ours === null ? 'not reported' : ($ours ? 'encrypted' : 'none')) . '; provider\'s: ' . $said . ')'
			. ((int)$machine->key !== (int)$node->key ? ', its host\'s' : '');
		return array('ours' => $ours, 'provider' => $provider, 'count' => $count, 'line' => $line);
	}

	/** The machine a node's data is on: its host's agent node for a container. */
	public static function machine_of(ManagedNode $node): ManagedNode {
		if (trim((string)$node->get('mgn_container_name')) === '') {
			return $node;
		}
		try {
			return JobCommandBuilder::decommission_host_node_for($node);
		} catch (Exception $e) {
			return $node;
		}
	}

	/**
	 * Keep the provider's layer on each provision of one instance that has
	 * none recorded, from the provider's own listing (the daily machine
	 * transfer watch reads every instance): the provider's layer is fixed when
	 * a server is made, so once is enough.
	 */
	public static function record_provider_layer(string $provider, string $instance_id, $layer): int {
		if (!in_array($layer, array('enabled', 'disabled'), true) || $instance_id === '') {
			return 0;
		}
		$n = 0;
		foreach (new MultiCustomerCloudProvision(array('deleted' => false)) as $p) {
			if ((string)$p->get('cvp_instance_id') === $instance_id && (string)$p->get('cvp_provider') === $provider
					&& trim((string)$p->get('cvp_provider_disk_encryption')) === '') {
				$p->set('cvp_provider_disk_encryption', $layer);
				$p->save();
				$n++;
			}
		}
		if ($n) {
			DataRootEncryptionOffer::forget_layers();
		}
		return $n;
	}
}
