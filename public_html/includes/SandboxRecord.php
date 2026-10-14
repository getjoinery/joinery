<?php
/**
 * SandboxRecord and SandboxRecordCollection — the base of every model a
 * sandboxed plugin's manifest declares.
 *
 * SandboxModels declares one subclass of each per manifest model (the class
 * Sbx_notifier_SentLog for model SentLog of plugin notifier, and
 * MultiSbx_notifier_SentLog), filling the static properties every SystemBase model
 * carries from the manifest. From there the model is an ordinary one: its
 * table is built and migrated by update_database and plugin sync, it is a
 * record endpoint on /api/v1 (exposed read and write; per-record
 * authorization is SystemBase's default, which for a table with no owner
 * column is staff only), and the deletion system knows it.
 *
 * Every model gets {prefix}_id, {prefix}_create_time, {prefix}_update_time and
 * {prefix}_delete_time (soft delete) beside its declared fields, each stored
 * as {prefix}_{field}.
 *
 * @version 1.0
 */

abstract class SandboxRecord extends SystemBase {

	/**
	 * The field specifications a manifest model becomes.
	 *
	 * @param string $prefix e.g. 'notifier_sent_log'
	 * @param array  $fields the model's validated manifest fields
	 */
	public static function specificationsFor(string $prefix, array $fields): array {
		$specs = array(
			$prefix . '_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		);
		foreach ($fields as $field) {
			$type = $field['type'];
			$column = SandboxManifest::FIELD_TYPES[$type];
			if ($type === 'string') {
				$column = 'varchar(' . (int)($field['max_length'] ?? 255) . ')';
			}
			$spec = array('type' => $column, 'is_nullable' => true);
			if (!empty($field['required'])) {
				// Enforced on save. The column stays nullable, so a field made
				// required in a later version cannot fail on rows already stored.
				$spec['required'] = true;
			}
			if (!empty($field['index'])) {
				$spec['index'] = true;
			}
			$specs[$prefix . '_' . $field['name']] = $spec;
		}
		$specs[$prefix . '_create_time'] = array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()');
		$specs[$prefix . '_update_time'] = array('type' => 'timestamp(6)', 'is_nullable' => true);
		$specs[$prefix . '_delete_time'] = array('type' => 'timestamp(6)', 'is_nullable' => true);
		return $specs;
	}

	function save($debug = false) {
		if ($this->key) {
			$this->set(static::$prefix . '_update_time', gmdate('Y-m-d H:i:s'));
		}
		return parent::save($debug);
	}

	/** The plugin this model belongs to. */
	public static function sandboxPlugin(): string {
		return static::$sandbox_plugin;
	}

	/** The model's name in its manifest. */
	public static function sandboxModel(): string {
		return static::$sandbox_model;
	}
}

abstract class SandboxRecordCollection extends SystemMultiBase {

	/**
	 * Every option must name one of the model's columns, with or without its
	 * prefix, or be 'deleted'. An unknown key is refused, not dropped: a
	 * filter that is silently ignored returns every row as if filtered.
	 */
	protected function getMultiResults($only_count = false, $debug = false) {
		$model = static::$model_class;
		$specs = $model::$field_specifications;
		$prefix = $model::$prefix;
		$filters = array();
		foreach ((array)$this->options as $key => $value) {
			if ($key === 'deleted') {
				if ($value !== null) {
					$filters[$prefix . '_delete_time'] = $value ? 'IS NOT NULL' : 'IS NULL';
				}
				continue;
			}
			$column = isset($specs[$key]) ? $key : (isset($specs[$prefix . '_' . $key]) ? $prefix . '_' . $key : null);
			if ($column === null) {
				throw new UnknownMultiOptionException(get_class($this) . " has no field '" . $key . "' to filter on.");
			}
			if ($value === null) {
				$filters[$column] = 'IS NULL';
				continue;
			}
			if (!is_scalar($value)) {
				throw new UnknownMultiOptionException(get_class($this) . ": a filter on '" . $key . "' takes one value.");
			}
			$filters[$column] = self::filterValue(get_class($this), $key, strtolower($specs[$column]['type']), (string)$value);
		}
		return $this->_get_resultsv2($model::$tablename, $filters, $this->order_by, $only_count, $debug);
	}

	/**
	 * A filter value bound as its column's type, or refused when it is not
	 * one: a value turned into something else ("abc" into 0) would return
	 * rows as if filtered on what the caller did not ask for.
	 *
	 * @return array the value and its PDO type, for the filter list
	 * @throws UnknownMultiOptionException
	 */
	private static function filterValue(string $collection, string $key, string $type, string $value): array {
		$refuse = function ($what) use ($collection, $key, $value) {
			throw new UnknownMultiOptionException($collection . ": '" . mb_substr($value, 0, 40) . "' is not $what, which '" . $key . "' holds.");
		};
		if ($type === 'jsonb') {
			throw new UnknownMultiOptionException($collection . ": '" . $key . "' holds JSON, which cannot be filtered on.");
		}
		if (strpos($type, 'bool') !== false) {
			$bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			if ($bool === null) {
				$refuse('true or false');
			}
			return array($bool, PDO::PARAM_BOOL);
		}
		if (strpos($type, 'int') !== false) {
			if (!preg_match('~^-?\d{1,18}$~D', $value)) {
				$refuse('a whole number');
			}
			return array((int)$value, PDO::PARAM_INT);
		}
		if (strpos($type, 'numeric') === 0) {
			if (!is_numeric($value)) {
				$refuse('a number');
			}
			return array($value, PDO::PARAM_STR);
		}
		if (strpos($type, 'timestamp') === 0 || $type === 'date') {
			if (strtotime($value) === false) {
				$refuse('a date');
			}
			return array($value, PDO::PARAM_STR);
		}
		return array($value, PDO::PARAM_STR);
	}
}
