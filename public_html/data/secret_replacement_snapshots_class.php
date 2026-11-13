<?php
/**
 * What was stored when a replace-after-a-break-in list opened: one row per
 * stored secret value, holding a short hash of its stored blob, never the
 * value (SecretReplacementList).
 *
 * A re-seal always changes the stored blob, so a value whose hash is still
 * stored has not been replaced. Kept out of the list's own setting because a
 * per-member kind (authenticator secrets) is one value per member, and
 * settings are read on every request. Rows belong to the list that opened
 * them (srs_list_started) and go when it closes.
 *
 * Written and read with SQL by SecretReplacementList only, in bulk.
 *
 * @version 1.0 (specs/security_weak_spots_2026_11.md WP6)
 */
class SecretReplacementSnapshot extends SystemBase {
	public static $prefix = 'srs';
	public static $tablename = 'srs_secret_replacement_snapshots';
	public static $access_class = 'keys';
	public static $pkey_column = 'srs_secret_replacement_snapshot_id';

	public static $field_specifications = array(
		'srs_secret_replacement_snapshot_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'srs_list_started' => array('type'=>'varchar(19)', 'required'=>true, 'is_nullable'=>false, 'index'=>true),
		'srs_locator'      => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'srs_hash'         => array('type'=>'varchar(16)', 'required'=>true, 'is_nullable'=>false),
	);
}

class MultiSecretReplacementSnapshot extends SystemMultiBase {
	protected static $model_class = 'SecretReplacementSnapshot';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['list_started'])) {
			$filters['srs_list_started'] = array($this->options['list_started'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('srs_secret_replacement_snapshots', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
