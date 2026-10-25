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
 * @version 1.2 - push_provider_layer: the machine is told the provider's layer this management node knows, so
 *                joinery_data_root.sh refuses to encrypt it in place while it is on, and its site's own
 *                Backups page says why (specs/site_copy_final_test.md E2, B114)
 * @version 1.1 - record_provider_layer reads the one instance's provisions, not every provision
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
		foreach (new MultiCustomerCloudProvision(array('cvp_instance_id' => $instance_id, 'cvp_provider' => $provider, 'deleted' => false)) as $p) {
			if (trim((string)$p->get('cvp_provider_disk_encryption')) === '') {
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

	/** How long the same layer is not sent to a machine again: one host report's worth. */
	const PROVIDER_LAYER_RESEND_SECONDS = 3600;

	/** The first joinery_data_root.sh that records the provider's layer (provider-layer) and refuses by it. */
	const PROVIDER_LAYER_SCRIPT_MIN_VERSION = '1.32';

	/**
	 * Tell a machine the provider's layer under it, when what it last reported
	 * recording (its host report's data_root_provider_layer) is not what this
	 * management node knows (E2, B114). Nothing on the machine can ask the
	 * provider, and its site's own Backups page asks root to encrypt it in
	 * place: with the fact recorded, joinery_data_root.sh refuses while the
	 * provider's layer is on, as the node page does, and the site says why.
	 *
	 * Sent only where this side knows the layer (an unknown one is never
	 * sent), to a machine (a container's is its host's) with a data root,
	 * whose agent has the word and whose release's joinery_data_root.sh takes
	 * it, and the same value once an hour at most. A report from before
	 * host_report 1.41 (no such key) asks nothing; a machine with no data root
	 * has nothing to encrypt, and is told once it has one.
	 */
	public static function push_provider_layer(ManagedNode $node, array $report): bool {
		$reported = $report['data_root_provider_layer'] ?? null;
		$script = $report['data_root_script'] ?? null;
		$script_version = is_array($script) ? (string)($script['release_version'] ?? '') : '';
		if (!is_string($reported) || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $script_version)
				|| version_compare($script_version, self::PROVIDER_LAYER_SCRIPT_MIN_VERSION, '<')
				|| JobCommandBuilder::container_node_host($node) !== null) {
			return false;
		}
		$known = DataRootEncryptionOffer::provider_layer($node);
		if (!in_array($known, array('enabled', 'disabled'), true) || $reported === $known
				|| !JobCommandBuilder::has_primitive($node, 'data_root_provider_layer')) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT 1 FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'data_root_provider_layer'
			AND mjb_commands->'params'->>'layer' = ? AND mjb_create_time > now() - (? * interval '1 second') LIMIT 1");
		$q->execute(array($node->key, $known, self::PROVIDER_LAYER_RESEND_SECONDS));
		if ($q->fetchColumn()) {
			return false;
		}
		try {
			ManagementJob::createFromBuild($node->key, 'data_root_provider_layer',
				JobCommandBuilder::build_data_root_provider_layer($node, $known), null, null);
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}
