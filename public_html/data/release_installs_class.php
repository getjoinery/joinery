<?php
/**
 * ReleaseInstall — one run of the upgrade on this site, and how it ended.
 *
 * utils/upgrade.php writes a row at the end of every command-line run (the
 * Updates page's request, the agent's apply_update, a run at a shell): the
 * version it started from, the version it was installing, whether it was
 * installed, refused, rolled back or stopped, and why. An installed release
 * also records the public commit and log entry its statement names. The
 * Updates page (/admin/admin_updates) shows them as the site's install history.
 *
 * @version 1.0
 */
class ReleaseInstall extends SystemBase {
	public static $prefix = 'rin';
	public static $tablename = 'rin_release_installs';
	public static $pkey_column = 'rin_release_install_id';

	/** The release was deployed and passed its checks. */
	const INSTALLED = 'installed';
	/** The release did not verify, so nothing was deployed. */
	const REFUSED = 'refused';
	/** The release was deployed, failed a check, and the previous code was put back. */
	const ROLLED_BACK = 'rolled_back';
	/** The run ended before deploying anything, for a reason other than verification. */
	const STOPPED = 'stopped';

	public static $field_specifications = array(
		'rin_release_install_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'rin_from_version' => array('type'=>'varchar(32)', 'is_nullable'=>true),
		'rin_to_version' => array('type'=>'varchar(32)', 'is_nullable'=>true),
		'rin_outcome' => array('type'=>'varchar(16)', 'required'=>true),
		// Why a run that did not install ended: the upgrade's own words, plain text.
		'rin_detail' => array('type'=>'text', 'is_nullable'=>true),
		// From the installed release's statement; null on any other outcome,
		// and on a release from before logging.
		'rin_core_commit' => array('type'=>'varchar(40)', 'is_nullable'=>true),
		'rin_agent_commit' => array('type'=>'varchar(40)', 'is_nullable'=>true),
		'rin_log_origin' => array('type'=>'varchar(128)', 'is_nullable'=>true),
		'rin_log_index' => array('type'=>'int8', 'is_nullable'=>true),
		'rin_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);
}

class MultiReleaseInstall extends SystemMultiBase {
	protected static $model_class = 'ReleaseInstall';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['outcome'])) {
			$filters['rin_outcome'] = array($this->options['outcome'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('rin_release_installs', $filters, $this->order_by, $only_count, $debug);
	}
}
