<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('<?= $entity_snake ?>_edit_logic.php', 'logic'));

$page_vars = process_logic(<?= $entity_snake ?>_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$page->public_header(['title' => 'Edit <?= $title ?>']);
<?= $C ?>


<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div style="max-width: 720px; margin: 0 auto;">
			<h1>Edit <?= $title ?></h1>
			<div class="jy-panel">
				<?= $O ?>

				$formwriter = $page->getFormWriter('<?= $entity_snake ?>_edit', [
					'model' => $<?= $entity_snake ?>,
					'edit_primary_key_value' => $<?= $entity_snake ?>->key
				]);

				$formwriter->begin_form();
				$formwriter->fromDescriptor(<?= $entity_snake ?>_edit_logic_descriptor());
				// TODO: hand-add fields with no descriptor type (uploads, rich text, custom widgets) here.
				$formwriter->submitbutton('btn_submit', 'Save');
				$formwriter->end_form();
				<?= $C ?>

			</div>
		</div>
	</div>
</section>
</div>
<?= $O ?>

$page->public_footer(['track' => true]);
<?= $C ?>
