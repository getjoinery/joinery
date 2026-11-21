<?php
/**
 * PluginInstallLedgerEntry and MultiPluginInstallLedgerEntry — what each
 * install of a plugin made, so uninstall removes all of it.
 *
 * One row per thing one install made: a setting, a table, a menu slug, a
 * migration record and the rest (PluginInstallIdentity::KINDS), keyed by the
 * install's id (plg_plugins.plg_install_id), with the plugin's name beside it.
 * A row is written when its thing is made and struck only once its thing is
 * confirmed gone, so a row left after an uninstall is a thing that uninstall
 * could not remove, and the next install of that name finishes it first.
 *
 * Kept apart from plg_plugins on purpose: that row is taken back by a later
 * install of the same name, and this record must not be. Written and read by
 * PluginInstallIdentity only (spec plugin_install_identity, design 3).
 *
 * @version 1.0
 */

class PluginInstallLedgerEntry extends SystemBase {
	public static $prefix = 'pil';
	public static $tablename = 'pil_plugin_install_ledger';
	public static $access_class = 'config';
	public static $pkey_column = 'pil_plugin_install_ledger_id';

	public static $field_specifications = array(
		'pil_plugin_install_ledger_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'pil_install_id'  => array('type' => 'varchar(32)', 'is_nullable' => false, 'required' => true,
			'unique_with' => array('pil_kind', 'pil_name')),
		'pil_plugin'      => array('type' => 'varchar(128)', 'is_nullable' => false, 'required' => true, 'index' => true),
		// record()'s holder lookup is by kind and name.
		'pil_kind'        => array('type' => 'varchar(32)', 'is_nullable' => false, 'required' => true,
			'index_with' => array('pil_name')),
		'pil_name'        => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		'pil_create_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);
}

class MultiPluginInstallLedgerEntry extends SystemMultiBase {
	protected static $model_class = 'PluginInstallLedgerEntry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['install_id'])) {
			$filters['pil_install_id'] = array($this->options['install_id'], PDO::PARAM_STR);
		}
		if (isset($this->options['plugin'])) {
			$filters['pil_plugin'] = array($this->options['plugin'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('pil_plugin_install_ledger', $filters, $this->order_by, $only_count, $debug);
	}
}
