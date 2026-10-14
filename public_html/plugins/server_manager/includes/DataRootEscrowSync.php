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
 * A PLAIN DATA ROOT (B55): a node whose report says its data root is mounted
 * and not encrypted holds none of this holder's key slots, whatever was enrolled
 * before its decryption. What this holder kept for it goes, as before it was
 * ever encrypted, and an encryption made again enrols afresh. A retired row
 * stays: it records a key this holder gave up.
 *
 * A DORMANT COPY (B58) is enrolled and sent its recipient as any machine is,
 * once its agent takes both on a quiet site (DORMANT_COPY_MIN_AGENT_VERSION):
 * an address swap restarts it, and it must open by escrow then. Its row follows
 * it to its source's node at the switch-over (SiteCopySwap). A machine the
 * copy lets go (a discarded copy, the old server of a kept switch-over) has
 * its key given up (let_go()): its owner deletes it at the provider, and a
 * holder still answering it would open its data for whoever has it next.
 *
 * @version 1.7 - let_go(): a machine a site copy lets go (discarded, or the old server of a kept switch-over) has
 *                the keys held for it retired; its row stayed enrolled on a deleted node, still answered (B64)
 * @version 1.6 - a dormant copy is enrolled and sent its recipient once its agent takes both on a quiet site (B58);
 *                only an enrolment job that has not succeeded holds the next back an hour, so the machine that
 *                takes over a node is enrolled at once (B60)
 * @version 1.5 - from_plain_report(): a node reporting a plain data root drops what this holder kept for it; its
 *                enrolled row stayed, hid its node page's Encrypt and stopped a new encryption from enrolling (B55)
 * @version 1.4 - a dormant copy is sent no enrolment and no recipient: it refuses both until it takes over its
 *                source's node, and each refusal was a failed job every hour
 * @version 1.3 - a rotated key is sent at once: the hour between sends holds back only the same key again (B35)
 * @version 1.2 - no enrolment job while this holder takes no new enrolments (DataRootEscrow::takes_new_enrolments)
 * @version 1.1 - hosted machines sealed to an older operator key are sent the current one (D8)
 * @version 1.0
 */

class DataRootEscrowSync {

	/** No second enrolment job for a node inside this many seconds of one that has not succeeded. */
	const ENROL_RETRY_SECONDS = 3600;

	/** The first agent that takes data_root_enrol and data_root_recipient on a dormant copy (B58). */
	const DORMANT_COPY_MIN_AGENT_VERSION = '1.75.5';

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

		// An older agent's dormant copy refuses both of these until it takes
		// over its source's node; they follow its first report after that.
		if (trim((string)$node->get('mgn_install_state')) === 'copy' && !self::copy_takes_escrow($node)) {
			return;
		}
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

	/** A node reporting its data root plain: the rows this holder kept for it go (B55). */
	public static function from_plain_report(ManagedNode $node, $disk_pool): void {
		// Only with a data root mounted: a report silent on one says nothing.
		if (!is_array($disk_pool)) {
			return;
		}
		foreach (new MultiDataRootEscrow(array('node' => (int)$node->key, 'live' => true)) as $row) {
			$row->permanent_delete();
		}
	}

	/**
	 * A machine a site copy lets go: what this holder keeps for it is retired,
	 * as Stop holding its key does, so it is answered no more (B64). Retired,
	 * not removed: it records a key this holder gave up.
	 */
	public static function let_go(ManagedNode $node): void {
		foreach (new MultiDataRootEscrow(array('node' => (int)$node->key, 'live' => true)) as $row) {
			$row->retire();
		}
	}

	/** Whether this plane should enrol the node's data root now. */
	public static function should_enrol(ManagedNode $node, DataRootEscrow $row, array $enc): bool {
		if (!DataRootEscrow::takes_new_enrolments()) {
			return false;
		}
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

	/** Whether a dormant copy's agent takes the escrow words on a quiet site (B58). */
	public static function copy_takes_escrow(ManagedNode $node): bool {
		$v = AgentVocabulary::version($node);
		return $v !== '' && version_compare($v, self::DORMANT_COPY_MIN_AGENT_VERSION, '>=');
	}

	/**
	 * An enrolment job within the retry window that has not succeeded. One
	 * that succeeded holds nothing back: a machine reported not enrolled after
	 * it is another machine on this node (a switch-over) or a header made
	 * again (an encryption after a decryption), and is enrolled at once (B60).
	 */
	private static function enrol_job_recent(ManagedNode $node): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT 1 FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'data_root_enrol'
			AND mjb_status <> 'completed' AND mjb_create_time > now() - (? * interval '1 second') LIMIT 1");
		$q->execute(array($node->key, self::ENROL_RETRY_SECONDS));
		return (bool)$q->fetchColumn();
	}

	/** A data_root_recipient job carrying this very key, made within the retry window. */
	private static function recipient_sent_recently(ManagedNode $node, string $key): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT 1 FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'data_root_recipient'
			AND mjb_commands->'params'->>'public_key' = ? AND mjb_create_time > now() - (? * interval '1 second') LIMIT 1");
		$q->execute(array($node->key, $key, self::ENROL_RETRY_SECONDS));
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
		if ($fpr === '' || ($enc['recipient_fingerprint'] ?? '') === $fpr) {
			return;
		}
		$key = base64_encode(BackupRecoveryKey::public_key());
		// The same key once an hour at most; a new key (a rotation) at once,
		// whatever was sent before it.
		if (self::recipient_sent_recently($node, $key)) {
			return;
		}
		try {
			$built = JobCommandBuilder::build_data_root_recipient($node, $key);
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
