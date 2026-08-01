<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * RecoveryReadinessItems — server_manager's contributions to the core Recovery
 * Readiness page (declared under `recoveryReadiness` in plugin.json).
 *
 * Two item families:
 *   - The backup recovery private key: the one secret that opens every escrowed
 *     node backup key. Verified by the same in-browser possession ceremony the
 *     setup walkthrough uses — now standing, so "did I really save it?" has an
 *     answer on demand, not only at setup time.
 *   - One attestation item per enabled backup target: after total server loss,
 *     the provider console login is the only non-circular way back to the
 *     backups, and the platform cannot check it for you.
 *
 * @version 1.0.0
 */
class RecoveryReadinessItems {

	/** Provider for RecoveryReadiness::items() — returns a list of item arrays. */
	public static function items() {
		require_once(__DIR__ . '/BackupKeyCustody.php');
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));

		$items = array();
		$items[] = self::recoveryKeyItem();
		foreach (self::targetItems() as $item) {
			$items[] = $item;
		}
		return $items;
	}

	private static function recoveryKeyItem() {
		$state = BackupKeyCustody::setup_state();

		$item = array(
			'key'      => 'backup_recovery_key',
			'title'    => 'Backup recovery private key',
			'protects' => 'Every encrypted database backup from every managed node. If the nodes are lost and this key is too, those backups can never be opened.',
			'verify'   => 'ceremony',
		);

		if (!$state['is_ready']) {
			$item['state'] = 'not_configured';
			$item['state_text'] = ($state['state'] === 'unconfigured')
				? 'No backup recovery key is set up — encrypted backups have no recovery story until one is. Set it up on the Backup Targets page.'
				: 'A recovery key is configured but not usable (' . ($state['error'] !== '' ? $state['error'] : 'not yet verified') . '). Finish the setup on the Backup Targets page.';
			return $item;
		}

		$sealed = self::sealedNodeSummary();

		$item['label'] = '{site} — backup recovery key (' . $state['fingerprint'] . ')';
		$item['facts'] = array(
			'Key fingerprint'      => $state['fingerprint'] . '…',
			'Node keys sealed to it' => $sealed['count'] > 0 ? $sealed['count'] . ' (' . $sealed['names'] . ')' : 'none yet',
		);
		$item['verify_call'] = 'RecoveryReadinessItems::verify_recovery_key';
		$item['ceremony'] = array(
			'challenge'   => BackupKeyCustody::browser_challenge(),
			'public_key'  => base64_encode(BackupKeyCustody::parse_public_key()),
			'cli_command' => "echo '" . BackupKeyCustody::possession_challenge() . "' | php "
				. PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php'
				. ' unseal --private /path/to/recovery.key',
		);
		return $item;
	}

	/** Distinct live nodes whose backup keys are escrowed, for the facts line. */
	private static function sealedNodeSummary() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT DISTINCT m.mgn_slug
			   FROM bke_backup_key_escrow b
			   JOIN mgn_managed_nodes m ON m.mgn_id = b.bke_mgn_node_id
			  WHERE b.bke_kind = 'backup' AND m.mgn_delete_time IS NULL
			  ORDER BY m.mgn_slug");
		$q->execute();
		$slugs = $q->fetchAll(PDO::FETCH_COLUMN);
		return array('count' => count($slugs), 'names' => implode(', ', $slugs));
	}

	/** Where each provider's console sign-in lives (for the guided attestation). */
	private static $console_urls = array(
		'b2'     => 'https://secure.backblaze.com/user_signin.htm',
		's3'     => 'https://console.aws.amazon.com/',
		'linode' => 'https://login.linode.com/login',
	);

	private static function targetItems() {
		$items = array();
		$targets = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
		$targets->load();
		foreach ($targets as $target) {
			$provider_key = strtolower((string)$target->get('bkt_provider'));
			$provider = strtoupper($provider_key);
			$bucket = (string)$target->get('bkt_bucket');
			$items[] = array(
				'key'      => 'bucket_console_' . (int)$target->key,
				'title'    => 'Backup bucket console access — ' . $target->get('bkt_name'),
				'protects' => 'Reaching the backups at all after total server loss. Bucket credentials are sealed inside this database, so the provider console login is the only way back in.',
				'label'    => $provider . ' console login — bucket ' . $bucket,
				'facts'    => array(
					'Provider'    => $provider,
					'Bucket'      => $bucket,
					'Path prefix' => (string)$target->get('bkt_path_prefix'),
				),
				'verify'       => 'attested',
				'instructions' => 'Open the ' . $provider . ' console in another tab and confirm the login saved in your '
					. 'password manager still gets you in. The platform holds no console credentials, so it cannot '
					. 'check this for you — recording it here just timestamps that you did.',
				'action_url'   => isset(self::$console_urls[$provider_key]) ? self::$console_urls[$provider_key] : '',
				'action_url_label' => 'Open the ' . $provider . ' console',
				'attest_label' => 'Record it — I just signed in',
			);
		}
		return $items;
	}

	/**
	 * Ceremony verifier: the submitted proof must be the challenge content only
	 * the private-key holder could recover. Delegates to the same check the
	 * setup walkthrough records, so re-verifying also refreshes the proof
	 * marker (idempotent — it rewrites the same fingerprint).
	 */
	public static function verify_recovery_key(array $input) {
		require_once(__DIR__ . '/BackupKeyCustody.php');
		try {
			BackupKeyCustody::record_possession_proof((string)($input['escrow_proof'] ?? ''));
			return array('ok' => true, 'message' => 'Your saved key opened the challenge — it is the right key.');
		} catch (BackupKeyCustodyException $e) {
			return array('ok' => false, 'message' => $e->getMessage());
		}
	}
}
