<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

function <?= $entity_snake ?>_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('<?= $base ?>data/<?= $entity_snake ?>_class.php'));

	$session = SessionControl::get_instance();
<?php if ($public_permission !== null): ?>
	$session->check_permission(<?= $public_permission ?>);
<?php endif; ?>

	if (isset($input['edit_primary_key_value'])) {
		$<?= $entity_snake ?> = new <?= $entity ?>($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['<?= $pkey ?>'])) {
		$<?= $entity_snake ?> = new <?= $entity ?>($input['<?= $pkey ?>'], TRUE);
	} else {
		$<?= $entity_snake ?> = new <?= $entity ?>(NULL);
	}

	if (LibraryFunctions::isFormSubmission()) {
<?php if ($owner_field !== null): ?>
		if (!$<?= $entity_snake ?>->key) {
			$<?= $entity_snake ?>->set('<?= $owner_field ?>', $session->get_user_id());
		}
<?php endif; ?>

		$editable_fields = <?= ScaffoldGenerator::phpList($editable) ?>;
		foreach ($editable_fields as $field) {
			if (isset($input[$field])) {
				$<?= $entity_snake ?>->set($field, $input[$field]);
			}
		}

		// TODO: business rules, plus any fields with no descriptor type (uploads, etc.)

		$<?= $entity_snake ?>->prepare();
		$<?= $entity_snake ?>->save();

		return LogicResult::redirect('/<?= $entity_snake ?>_edit?<?= $pkey ?>=' . $<?= $entity_snake ?>->key);
	}

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['<?= $entity_snake ?>'] = $<?= $entity_snake ?>;

	return LogicResult::render($page_vars);
}

function <?= $entity_snake ?>_edit_logic_descriptor(): array {
	return array(
		'description'      => 'Create or update a <?= $title ?>.',
		'requires_session' => <?= ($public_permission !== null) ? 'true' : 'false' ?>,
		'mutates'          => true,
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
