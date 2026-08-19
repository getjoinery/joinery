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
 * @version 1.3 - manager_coverage() and the 'finished_since' collection filter: the run
 *                proving a control plane currently backs this site up, so the setup step
 *                and dashboards can read fleet custody from local history instead of
 *                calling the site un-backed-up
 * @version 1.2 - bkh_profile and bkh_recovery_fpr: a site can be backed up by more than one
 *                party, and every query that decides what to extend or delete has to be
 *                asking about one party's runs
 * @version 1.1 - 'chained' collection filter, so retention can address chain runs and
 *                standalone backups as separate families
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));

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

		// Whose backup this was: 'site' (this site's own) or 'manager' (a control
		// plane's copy of it). Load-bearing rather than descriptive — chain
		// extension, chain retention and cloud retention all ask history what
		// exists, and a query that spanned both profiles would extend one party's
		// chain with another party's run, or count somebody else's copies towards
		// this party's retention.
		'bkh_profile'       => array('type'=>'varchar(20)', 'default'=>'site',
		                             'allowed_values'=>array('site', 'manager')),

		// Fingerprint of the recovery key this run sealed to. Recorded per run
		// rather than inferred from settings, because settings say what would
		// happen today and a restore needs to know what happened then — which
		// private key opens THIS backup.
		'bkh_recovery_fpr'  => array('type'=>'varchar(64)'),

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
		// 'bkt' is claimed by both BackupTarget and BookingType, and the column
		// name (bkt_target, not bkt_backup_target) defeats the entity match -
		// name the source explicitly.
		'bkh_bkt_target_id' => array('action' => 'null', 'source_class' => 'BackupTarget'),
	);

	function prepare() {
		if (!in_array($this->get('bkh_type'), array('project', 'database'), true)) {
			throw new BackupHistoryException('A backup history row needs a type of project or database.');
		}
		// A row with no profile is a site-profile row. Defaulting here as well as
		// in the column keeps rows written by an older code path readable by the
		// queries that now filter on it.
		if (trim((string)$this->get('bkh_profile')) === '') {
			$this->set('bkh_profile', BackupProfile::SITE);
		}
	}

	/** Whose backup this was. Rows predating profiles are the site's own. */
	public function profile() {
		$p = trim((string)$this->get('bkh_profile'));
		return ($p === '') ? BackupProfile::SITE : $p;
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
	 * Days after which a control plane's newest proven run stops counting as
	 * live coverage — long enough to ride out a weekend outage on the control
	 * plane, short enough that abandoned coverage does not read as protection.
	 */
	const MANAGER_COVERAGE_DAYS = 7;

	/**
	 * The run proving another party currently backs this site up: the newest
	 * manager-profile row that reached its bucket within MANAGER_COVERAGE_DAYS.
	 * Null means no live coverage — whatever a control plane once did, this
	 * site cannot currently point to a recent archive it did not make itself.
	 *
	 * @return BackupHistory|null
	 */
	public static function manager_coverage() {
		$since = LibraryFunctions::time_shift(
			gmdate('Y-m-d H:i:s'), '-' . self::MANAGER_COVERAGE_DAYS . ' days', 'Y-m-d H:i:s');
		$rows = new MultiBackupHistory(
			array(
				'deleted' => false,
				'profile' => BackupProfile::MANAGER,
				'outcome' => 'success',
				'offsite' => true,
				'finished_since' => $since,
			),
			array('bkh_finish_time' => 'DESC'), 1, 0);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
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

		// Whose runs. Every caller that decides what to extend or what to delete
		// must pass this — the alternative is one party's retention counting the
		// other's copies, or a chain extended with a run sealed to a different
		// key. Rows written before the column existed are the site's own, so the
		// site filter has to accept a null as well as the literal.
		if (isset($this->options['profile'])) {
			$profile = BackupProfile::normalize($this->options['profile']);
			if ($profile === BackupProfile::SITE) {
				$filters['(bkh_profile'] = "= 'site' OR bkh_profile IS NULL)";
			} else {
				$filters['bkh_profile'] = [$profile, PDO::PARAM_STR];
			}
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

		// Runs finished on or after a UTC moment — how "is coverage live?" is
		// asked. Validated because it lands in the SQL as a literal condition.
		if (isset($this->options['finished_since'])) {
			$since = (string)$this->options['finished_since'];
			if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
				throw new BackupHistoryException('finished_since must be a UTC timestamp (Y-m-d H:i:s).');
			}
			$filters['bkh_finish_time'] = ">= '" . $since . "'";
		}


		return $this->_get_resultsv2('bkh_backup_history', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
