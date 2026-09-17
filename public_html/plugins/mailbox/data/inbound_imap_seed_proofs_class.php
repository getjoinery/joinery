<?php
/**
 * InboundImapSeedProof - the evidence behind where a day-windowed feed started reading.
 *
 * A feed set to "last N days" does not read the whole mailbox: it bisects the UID
 * space for the oldest message still inside the window and starts just below it
 * (ImapIngestor::seekCursorForCutoff). That decision is made once per folder, it
 * decides what the user will and will not receive, and until this table existed it
 * left no trace at all — a seek that landed too high silently skipped real mail and
 * nothing anywhere would have said so.
 *
 * One row per folder seed, holding the inputs (cutoff, high UID), the answer (the
 * cursor), how it was reached (probe count, whether the bisection converged or ran
 * out of budget), and two boundary probes that make the answer checkable:
 *
 *   - below : the newest message at or under the cursor. Its date should be OLDER
 *     than the cutoff. If it is not, the seek started too high and skipped mail.
 *   - above : the oldest message over the cursor. Its date should be INSIDE the
 *     window. If it is not, the seek started low and over-imported, which is the
 *     documented fail-soft direction and costs nothing but time.
 *
 * The boundary check is exactly that — a check of the boundary the bisection chose.
 * It is not a proof about the whole region below the cursor, because INTERNALDATE is
 * not guaranteed to rise with UID: a message copied or imported into the account gets
 * a fresh high UID carrying whatever date it already had. Proving the region needs
 * every date under the cursor, which is what imap_window_audit.php does on demand.
 *
 * See specs/mail_import_loss_proof.md § B.
 *
 * @version 1.1
 * @changelog 1.1 - isp_method records whether the cursor came from a server-side
 *   UID SEARCH SINCE or the bisection (specs/imap_seed_scope_guard.md §3.4)
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundImapSeedProofException extends SystemBaseException {}

class InboundImapSeedProof extends SystemBase {
	public static $prefix = 'isp';
	public static $tablename = 'isp_inbound_imap_seed_proofs';
	public static $pkey_column = 'isp_inbound_imap_seed_proof_id';

	protected static $foreign_key_actions = array(
		'isp_iia_inbound_imap_account_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'isp_inbound_imap_seed_proof_id'  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'isp_iia_inbound_imap_account_id' => array('type'=>'int8', 'is_nullable'=>false),
		'isp_folder'                      => array('type'=>'varchar(255)', 'is_nullable'=>false),
		// What was asked for.
		'isp_scope'                       => array('type'=>'varchar(10)'),
		'isp_cutoff_time'                 => array('type'=>'timestamp(6)'),
		'isp_high_uid'                    => array('type'=>'int8'),
		// What was decided, and how hard it was to decide.
		'isp_cursor_uid'                  => array('type'=>'int8'),
		// How the cursor was found: 'search' (one server-side UID SEARCH SINCE
		// answered exactly) or 'bisect' (the band-probe bisection). NULL on proofs
		// recorded before the column existed — all of those bisected.
		'isp_method'                      => array('type'=>'varchar(10)'),
		'isp_probes'                      => array('type'=>'int4', 'default'=>'0'),
		// FALSE when the probe budget ran out before the bisection closed. The
		// cursor is still safe (it is the proven lower bound) but looser than asked.
		'isp_converged'                   => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		// The two boundary probes. NULL where the folder had nothing to probe —
		// an empty folder, or a cursor at either end of the UID space.
		'isp_below_uid'                   => array('type'=>'int8'),
		'isp_below_time'                  => array('type'=>'timestamp(6)'),
		'isp_above_uid'                   => array('type'=>'int8'),
		'isp_above_time'                  => array('type'=>'timestamp(6)'),
		'isp_create_time'                 => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $index_specifications = array(
		array('columns' => array('isp_iia_inbound_imap_account_id', 'isp_create_time')),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Did the seek skip mail the window claims?
	 *
	 * TRUE when the newest message at or under the cursor is older than the cutoff,
	 * which is the answer we want. NULL when there is nothing to judge: no cutoff,
	 * or no message below the cursor to probe (an empty folder, or a seed at zero).
	 * An unreadable INTERNALDATE is stored as NULL for the same reason it counts as
	 * in-window during the seek — unknown is never treated as proof.
	 */
	function boundaryHolds(): ?bool {
		$cutoff = (string)$this->get('isp_cutoff_time');
		$below  = (string)$this->get('isp_below_time');
		if ($cutoff === '' || $below === '') {
			return null;
		}
		return $below < $cutoff;
	}

	/** Whether the seek started later than it needed to — costs time, never mail. */
	function overImported(): ?bool {
		$cutoff = (string)$this->get('isp_cutoff_time');
		$above  = (string)$this->get('isp_above_time');
		if ($cutoff === '' || $above === '') {
			return null;
		}
		return $above < $cutoff;
	}

	/** One line for a log or a report. */
	function describe(): string {
		$holds = $this->boundaryHolds();
		$verdict = $holds === null ? 'unprovable' : ($holds ? 'boundary holds' : 'BOUNDARY BROKEN');
		return $this->get('isp_folder') . ': cursor ' . intval($this->get('isp_cursor_uid'))
			. ' of ' . intval($this->get('isp_high_uid'))
			. ', cutoff ' . (string)$this->get('isp_cutoff_time')
			. ', below ' . ((string)$this->get('isp_below_time') ?: '-')
			. ', above ' . ((string)$this->get('isp_above_time') ?: '-')
			. ', ' . ((string)$this->get('isp_method') === 'search'
				? 'server search' : intval($this->get('isp_probes')) . ' probes')
			. ($this->get('isp_converged') ? '' : ' (budget exhausted)')
			. ' — ' . $verdict;
	}

	/**
	 * Write one seed proof. Best effort by design: losing the evidence row must
	 * never cost the poll that produced it, so every failure here is logged and
	 * swallowed. The mail is what matters; the proof is how we check it.
	 */
	static function record(array $row): ?InboundImapSeedProof {
		try {
			$proof = new InboundImapSeedProof(NULL);
			foreach ($row as $field => $value) {
				$proof->set($field, $value);
			}
			$proof->save();
			return $proof;
		} catch (\Throwable $e) {
			error_log('InboundImapSeedProof: could not record the seed proof — ' . $e->getMessage());
			return null;
		}
	}
}

class MultiInboundImapSeedProof extends SystemMultiBase {
	protected static $model_class = 'InboundImapSeedProof';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['account_id'])) {
			$filters['isp_iia_inbound_imap_account_id'] = array($this->options['account_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['folder'])) {
			$filters['isp_folder'] = array($this->options['folder'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('isp_inbound_imap_seed_proofs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
