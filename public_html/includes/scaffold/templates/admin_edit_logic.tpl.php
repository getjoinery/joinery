<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

function admin_<?= $entity_snake ?>_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('<?= $base ?>data/<?= $entity_snake ?>_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(<?= $admin_permission ?>);

	if (isset($input['edit_primary_key_value'])) {
		$<?= $entity_snake ?> = new <?= $entity ?>($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['<?= $pkey ?>'])) {
		$<?= $entity_snake ?> = new <?= $entity ?>($input['<?= $pkey ?>'], TRUE);
	} else {
		$<?= $entity_snake ?> = new <?= $entity ?>(NULL);
	}

	if (LibraryFunctions::isFormSubmission()) {
		$editable_fields = <?= ScaffoldGenerator::phpList($editable) ?>;
		foreach ($editable_fields as $field) {
			if (isset($input[$field])) {
				$<?= $entity_snake ?>->set($field, $input[$field]);
			}
		}

		// TODO: business rules, plus any fields with no descriptor type (uploads, etc.)

		$<?= $entity_snake ?>->prepare();
		$<?= $entity_snake ?>->save();

		return LogicResult::redirect('/admin/admin_<?= $plural ?>');
	}

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['<?= $entity_snake ?>'] = $<?= $entity_snake ?>;

	return LogicResult::render($page_vars);
}

function admin_<?= $entity_snake ?>_edit_logic_descriptor(): array {
	return array(
		'description'      => 'Create or update a <?= $title ?> (admin).',
		'requires_session' => true,
		'mutates'          => true,
		// Admin edit actions are not exposed to the AI agent by default.
		// Uncomment to make this callable in chat (confirmed before it runs):
		// 'ai_agent'         => 'confirm',
		'input'            => array(
			'edit_primary_key_value' => array('type' => 'int', 'required' => false, 'label' => '<?= $title ?> ID (omit to create)'),
<?php foreach ($descriptor_inputs as $di): ?>
			'<?= $di['col'] ?>' => array('type' => '<?= $di['type'] ?>', 'required' => <?= $di['required'] ? 'true' : 'false' ?>, 'label' => <?= ScaffoldGenerator::phpScalar($di['label']) ?><?php
				if ($di['type'] === 'select') { echo ", 'options' => " . ScaffoldGenerator::phpMap($di['options']); }
				if (isset($di['placeholder'])) { echo ", 'placeholder' => " . ScaffoldGenerator::phpScalar($di['placeholder']); }
				if (isset($di['help'])) { echo ", 'help' => " . ScaffoldGenerator::phpScalar($di['help']); }
			?>),
<?php endforeach; ?>
		),
	);
}
<?= $C ?>
