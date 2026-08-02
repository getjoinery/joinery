<?php
/**
 * BackupHistory — one row per backup run this site made.
 *
 * This is the primary answer to "what backups exist and did they work". Reading
 * it beats listing the bucket: listing needs working credentials and a reachable
 * provider, tells you nothing about runs that FAILED (the ones worth knowing
 * about), and cannot distinguish an archive that uploaded from one that was
 * written locally and never left. Bucket listing stays the reconciliation and
 * disaster-recovery path — the source of truth about the bucket — but it is not
 * the source of truth about this site's backups.
 *
 * A run is recorded even when it fails, and especially then: a site whose
 * backups have been failing for a month looks identical to a healthy one if only
 * successes are written down.
 *
 * @version 1.1 - 'chained' collection filter, so retention can address chain runs and
 *                standalone backups as separate families
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BackupHistoryException extends SystemBaseException {}

class BackupHistory extends SystemBase {
	public static $prefix = 'bkh';
	public static $tablename = 'bkh_backup_history';
	public static $pkey_column = 'bkh_id';

	public static $json_vars = array('bkh_artifacts');

	public static $field_specifications = array(
		'bkh_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),

		// project | database — what was backed up.
		'bkh_type'          => array('type'=>'varchar(20)', 'is_nullable'=>false, 'required'=>true,
		                             'allowed_values'=>array('project', 'database')),

		// running | success | failed. A row is inserted as 'running' before the
		// engine starts, so a run killed mid-flight (OOM, reboot, deploy) leaves
		// evidence instead of vanishing.
		'bkh_outcome'       => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'running',
		                             'allowed_values'=>array('running', 'success', 'failed')),

		'bkh_start_time'    => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'bkh_finish_time'   => array('type'=>'timestamp(6)'),

		// Which target it went to, and the slug it was filed under. Kept as
		// values rather than only a foreign key: a target can be deleted and
		// reconfigured, and the history still has to say where a backup went.
		'bkh_bkt_target_id' => array('type'=>'int8',
		                             'foreign_key'=>array('table'=>'bkt_backup_targets', 'column'=>'bkt_id',
		                                                  'on_delete'=>'SET NULL')),
		'bkh_target_name'   => array('type'=>'varchar(100)'),
		'bkh_slug'          => array('type'=>'varchar(255)'),

		// Every object this run produced: [{name, key, bytes, kind}] where kind
		// is archive | envelope. A run is more than one object once envelopes
		// exist, and will be more again once chains do.
		'bkh_artifacts'     => array('type'=>'jsonb'),
		'bkh_bytes'         => array('type'=>'int8', 'default'=>0),

		// Set once the objects are confirmed in the bucket. A successful run with
		// no upload time is a local-only backup, which is a real and different
		// state from "backed up offsite".
		'bkh_upload_time'   => array('type'=>'timestamp(6)'),

		// Chain this run belongs to (Phase 3). Null means a standalone full
		// backup, which is every run until chains land.
		'bkh_chain_id'      => array('type'=>'varchar(64)'),
		'bkh_chain_seq'     => array('type'=>'int4'),

		'bkh_encrypted'     => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'bkh_message'       => array('type'=>'text'),

		'bkh_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'bkh_update_time'   => array('type'=>'timestamp(6)'),
		'bkh_delete_time'   => array('type'=>'timestamp(6)'),
	);

	// What happens to these rows when a referenced parent is deleted — docs/deletion_system.md
	//
	// Deleting a target must NOT erase the record of backups sent to it: those
	// objects still exist in a bucket somebody has to account for, and the
	// target name is denormalised onto the row precisely so the history still
	// reads correctly once the target is gone. Hence 'null', not 'cascade'.
	protected static $foreign_key_actions = array(
		'bkh_bkt_target_id' => array('action' => 'null'),
	);

	public static $permanent_delete_actions = array();

	function prepare() {
		if (!in_array($this->get('bkh_type'), array('project', 'database'), true)) {
			throw new BackupHistoryException('A backup history row needs a type of project or database.');
		}
	}

	/** Artifacts as an array, whatever shape the column came back in. */
	public function artifacts() {
		$a = $this->get('bkh_artifacts');
		if (is_string($a)) {
			$a = json_decode($a, true);
		}
		return is_array($a) ? $a : array();
	}

	/** Record the objects this run produced and their total size. */
	public function set_artifacts(array $artifacts) {
		$bytes = 0;
		foreach ($artifacts as $a) {
			$bytes += (int)($a['bytes'] ?? 0);
		}
		$this->set('bkh_artifacts', $artifacts);
		$this->set('bkh_bytes', $bytes);
		return $this;
	}

	/** Did this run reach the bucket? */
	public function is_offsite() {
		return $this->get('bkh_outcome') === 'success' && !empty($this->get('bkh_upload_time'));
	}

	/**
	 * The object keys this run owns, for retention to delete. Envelopes are
	 * included deliberately: deleting an archive and leaving its envelope behind
	 * accumulates orphans that look like restore points in a bucket listing.
	 */
	public function object_keys() {
		$keys = array();
		foreach ($this->artifacts() as $a) {
			if (!empty($a['key'])) {
				$keys[] = (string)$a['key'];
			}
		}
		return $keys;
	}
}

class MultiBackupHistory extends SystemMultiBase {
	protected static $model_class = 'BackupHistory';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['type'])) {
			$filters['bkh_type'] = [$this->options['type'], PDO::PARAM_STR];
		}

		if (isset($this->options['outcome'])) {
			$filters['bkh_outcome'] = [$this->options['outcome'], PDO::PARAM_STR];
		}

		if (isset($this->options['target_id'])) {
			$filters['bkh_bkt_target_id'] = [$this->options['target_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['slug'])) {
			$filters['bkh_slug'] = [$this->options['slug'], PDO::PARAM_STR];
		}

		if (isset($this->options['chain_id'])) {
			$filters['bkh_chain_id'] = [$this->options['chain_id'], PDO::PARAM_STR];
		}

		// Chain runs vs standalone backups. Retention passes MUST separate the
		// two: chains are only ever deleted whole, so a pass that counted and
		// deleted individual rows would take a chain's full before its
		// incrementals.
		if (isset($this->options['chained'])) {
			$filters['bkh_chain_id'] = $this->options['chained'] ? "IS NOT NULL" : "IS NULL";
		}

		// Runs that actually reached the bucket — what retention counts, and what
		// "am I backed up?" means.
		if (isset($this->options['offsite'])) {
			$filters['bkh_upload_time'] = $this->options['offsite'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['deleted'])) {
			$filters['bkh_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('bkh_backup_history', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
