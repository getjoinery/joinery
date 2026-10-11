<?php
/**
 * DataRootEscrowSync — what a node's host report says of its encrypted data
 * root, kept on the escrow table, and the enrolment it calls for
 * (specs/hosted_data_promise_disk_encryption.md D3, WP2).
 *
 * The host report comes over the agent channel, signed by the node's own key,
 * so it is the node saying which unlock identity is its. That is recorded
 * (status "known" until it enrols), with the break-glass box, so the holder's
 * page can show the machine and unlock it before it has a slot (D2b).
 *
 * ENROLMENT AFTER THE JOIN (D3): a node reporting an encrypted data root that
 * is open and not enrolled here gets a data_root_enrol job, when this plane is
 * its holder: the machine names none yet, or names this plane. Never this
 * plane's own machine: a management node's key is held by the other one (O4,
 * C-3), never by itself, whose data root is locked when it would be asked.
 *
 * ROTATION (D8). A hosted machine's break-glass copy is sealed to this
 * management node's own recovery key. When that key is rotated, every hosted
 * machine whose report names another is sent the new one
 * (data_root_recipient), and its tick seals the copy again; the Backups page
 * counts them (sealing_counts()).
 *
 * @version 1.1 - hosted machines sealed to an older operator key are sent the current one (D8)
 * @version 1.0
 */

class DataRootEscrowSync {

	/** No second enrolment job for a node inside this many seconds of one. */
	const ENROL_RETRY_SECONDS = 3600;

	public static function from_host_report(ManagedNode $node, array $enc): void {
		$identity = (string)($enc['identity'] ?? '');
		if ($identity === '') {
			return;
		}
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row) {
			$row = new DataRootEscrow(NULL);
			$row->set('dre_identity_key', $identity);
			$row->set('dre_status', 'known');
			$row->set('dre_hostname', substr((string)$node->get('mgn_name'), 0, 255));
		}
		if ((string)$row->get('dre_status') === 'retired') {
			return;
		}
		if (!$row->get('dre_mgn_managed_node_id')) {
			$row->set('dre_mgn_managed_node_id', $node->key);
		}
		$row->note_box(json_encode(array('fingerprint' => $enc['box_fingerprint'] ?? '', 'sealed' => $enc['box_sealed'] ?? '')));
		$row->save();

		if (!empty($enc['open'])) {
			self::push_operator_recipient($node, $enc);
		}
		if (!empty($enc['open']) && self::should_enrol($node, $row, $enc)) {
			try {
				$built = JobCommandBuilder::build_data_root_enrol($node);
				ManagementJob::createFromBuild($node->key, 'data_root_enrol', $built, null, null);
			} catch (Exception $e) {
				// An agent too old for the word: the node page says so.
			}
		}
	}

	/** Whether this plane should enrol the node's data root now. */
	public static function should_enrol(ManagedNode $node, DataRootEscrow $row, array $enc): bool {
		if (in_array((string)$row->get('dre_status'), array('enrolled', 'pending'), true)) {
			return false;
		}
		if ($node->is_self()) {
			return false;
		}
		$own = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
		$holder = (string)($enc['holder'] ?? '');
		if ($holder !== '' && strcasecmp($holder, $own) !== 0) {
			return false;
		}
		return !self::enrol_job_recent($node);
	}

	private static function enrol_job_recent(ManagedNode $node): bool {
		return self::job_recent($node, 'data_root_enrol');
	}

	private static function job_recent(ManagedNode $node, string $type): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT 1 FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = ?
			AND mjb_create_time > now() - (? * interval '1 second') LIMIT 1");
		$q->execute(array($node->key, $type, self::ENROL_RETRY_SECONDS));
		return (bool)$q->fetchColumn();
	}

	/**
	 * Hosted by us: its machine is in one of our accounts (CloudAccounts,
	 * main or test), whether we created it or it predates provisioning. Its
	 * break-glass copy is sealed to this management node's recovery key (D2);
	 * a machine in its owner's account, or self-hosted, is sealed to its
	 * owner's.
	 */
	public static function sealed_to_operator(ManagedNode $node): bool {
		return CloudAccounts::is_ours($node);
	}

	/** This management node's own proven recovery key's fingerprint, or ''. */
	public static function operator_fingerprint(): string {
		try {
			return hash('sha256', BackupRecoveryKey::public_key());
		} catch (Exception $e) {
			return '';
		}
	}

	/** A hosted machine sealed to another key than this node's current one is sent the current one (D8). */
	public static function push_operator_recipient(ManagedNode $node, array $enc): void {
		if (!self::sealed_to_operator($node)) {
			return;
		}
		$fpr = self::operator_fingerprint();
		if ($fpr === '' || ($enc['recipient_fingerprint'] ?? '') === $fpr || self::job_recent($node, 'data_root_recipient')) {
			return;
		}
		try {
			$built = JobCommandBuilder::build_data_root_recipient($node, base64_encode(BackupRecoveryKey::public_key()));
			ManagementJob::createFromBuild($node->key, 'data_root_recipient', $built, null, null);
		} catch (Exception $e) {
			// An agent too old for the word: the node page says so.
		}
	}

	/**
	 * For the Backups page (D8): the hosted machines with an encrypted data
	 * root, and which are sealed to the current key. ['sealed' => [...names],
	 * 'behind' => [...names]].
	 */
	public static function sealing_counts(): array {
		$out = array('sealed' => array(), 'behind' => array());
		$fpr = self::operator_fingerprint();
		foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $node) {
			$hr = json_decode((string)$node->get('mgn_last_host_report'), true);
			$enc = is_array($hr) ? ($hr['data_root_encryption'] ?? null) : null;
			if (!is_array($enc) || !self::sealed_to_operator($node)) {
				continue;
			}
			$key = ($fpr !== '' && ($enc['recipient_fingerprint'] ?? '') === $fpr) ? 'sealed' : 'behind';
			$out[$key][] = (string)$node->get('mgn_name');
		}
		return $out;
	}
}
