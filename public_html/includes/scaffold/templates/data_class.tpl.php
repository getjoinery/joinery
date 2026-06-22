<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>


require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class <?= $exception ?> extends SystemBaseException {}

class <?= $entity ?> extends SystemBase {
	public static $prefix = '<?= $prefix ?>';
	public static $tablename = '<?= $table ?>';
	public static $pkey_column = '<?= $pkey ?>';
<?php if ($api !== null): ?>

	// REST API exposure & authorization — docs/api.md
	public static $api_readable = <?= !empty($api['readable']) ? 'true' : 'false' ?>;
	public static $api_writable = <?= !empty($api['writable']) ? 'true' : 'false' ?>;
<?php if (!empty($api['public_read'])): ?>
	public static $api_public_read = true;
<?php endif; ?>
<?php if (!empty($api['unwritable_fields'])): ?>
	public static $api_unwritable_fields = <?= ScaffoldGenerator::phpList($api['unwritable_fields']) ?>;
<?php endif; ?>
<?php if (!empty($api['derived_fields'])): ?>
	public static $api_derived_fields = <?= ScaffoldGenerator::phpList($api['derived_fields']) ?>;
<?php endif; ?>
<?php endif; ?>
<?php if ($ai !== null): ?>

	// AI model surface (joinery_ai) — plugins/joinery_ai/docs/overview.md
	public static $ai_readable = <?= !empty($ai['readable']) ? 'true' : 'false' ?>;
	public static $ai_description = <?= ScaffoldGenerator::phpScalar($ai['description'] ?? '') ?>;
<?php if (!empty($ai['writable_fields'])): ?>
	public static $ai_writable_fields = <?= ScaffoldGenerator::phpList($ai['writable_fields']) ?>;
<?php endif; ?>
<?php if (!empty($ai['untrusted_fields'])): ?>
	public static $ai_untrusted_fields = <?= ScaffoldGenerator::phpList($ai['untrusted_fields']) ?>;
<?php endif; ?>
<?php if (!empty($ai['excluded_fields'])): ?>
	public static $ai_excluded_fields = <?= ScaffoldGenerator::phpList($ai['excluded_fields']) ?>;
<?php endif; ?>
<?php endif; ?>

	public static $field_specifications = array(
		'<?= $pkey ?>' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
<?php foreach ($fields as $f): ?>
		'<?= $f['col'] ?>' => array('type'=>'<?= $f['type'] ?>'<?php
			if ($f['required'] || !$f['is_nullable']) { echo ", 'is_nullable'=>false"; }
			if ($f['required']) { echo ", 'required'=>true"; }
			if ($f['unique']) { echo ", 'unique'=>true"; }
			if (!empty($f['unique_with'])) { echo ", 'unique_with'=>" . ScaffoldGenerator::phpList($f['unique_with']); }
			if ($f['has_default']) { echo ", 'default'=>" . $f['default_literal']; }
			if ($f['zero_on_create']) { echo ", 'zero_on_create'=>true"; }
		?>),
<?php endforeach; ?>
<?php if ($soft_delete): ?>
		'<?= $delete_col ?>' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
<?php endif; ?>
	);
<?php if (!empty($delete['foreign_key_actions'])): ?>

	// What happens to these rows when a referenced parent is deleted — docs/deletion_system.md
	protected static $foreign_key_actions = <?= ScaffoldGenerator::phpValue($delete['foreign_key_actions'], 1) ?>;
<?php endif; ?>

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md
	public static $permanent_delete_actions = <?= ScaffoldGenerator::phpValue($delete['permanent_delete_actions'] ?? [], 1) ?>;
<?php if ($owner_field !== null && $owner_field !== $prefix . '_usr_user_id'): ?>

	// Non-standard ownership: rows are owned via <?= $owner_field ?>, not the
	// default <?= $prefix ?>_usr_user_id. TODO: confirm this row-scope rule is correct.
	function authenticate_read($data) {
		if ($this->get('<?= $owner_field ?>') != $data['current_user_id']
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}

	function authenticate_write($data) {
		if ($this->get('<?= $owner_field ?>') != $data['current_user_id']
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}
<?php endif; ?>

	// Business-rule extension point. TODO: add cross-field validation, computed
	// export_as_array() keys, or relationship loading here. Override prepare()
	// for validation (docs/validation.md); note prepare() is not guaranteed to
	// run before save() — mandatory transforms belong in save().
	// function prepare() {
	//     $result = parent::prepare();
	//     // ... your checks; set $result['success'] = false and append messages on failure ...
	//     return $result;
	// }
}

class <?= $multi ?> extends SystemMultiBase {
	protected static $model_class = '<?= $entity ?>';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
<?php foreach ($filters as $flt): ?>
<?php if ($flt['kind'] === 'param'): ?>
		if (isset($this->options['<?= $flt['option'] ?>'])) {
			$filters['<?= $flt['column'] ?>'] = [$this->options['<?= $flt['option'] ?>'], <?= $flt['bind'] ?>];
		}
<?php elseif ($flt['kind'] === 'condition'): ?>
		if (isset($this->options['<?= $flt['option'] ?>'])) {
			$filters['<?= $flt['column'] ?>'] = "<?= $flt['condition'] ?>";
		}
<?php elseif ($flt['kind'] === 'match'): ?>
		if (isset($this->options['<?= $flt['option'] ?>'])) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$filters['<?= $flt['column'] ?>'] = '<?= strtoupper($flt['match']) ?> ' . $dblink->quote('%' . $this->options['<?= $flt['option'] ?>'] . '%');
		}
<?php endif; ?>
<?php endforeach; ?>
<?php if ($soft_delete): ?>
		if (isset($this->options['deleted'])) {
			$filters['<?= $delete_col ?>'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}
<?php endif; ?>

		return $this->_get_resultsv2('<?= $table ?>', $filters, $this->order_by, $only_count, $debug);
	}
}
<?= $C ?>
