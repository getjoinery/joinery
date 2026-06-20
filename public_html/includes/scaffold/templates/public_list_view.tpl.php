<?php extract($ctx); $O = ScaffoldGenerator::open(); $C = ScaffoldGenerator::close();
?><?= $O ?>

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('<?= $plural ?>_logic.php', 'logic'));

$page_vars = process_logic(<?= $plural ?>_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$page->public_header(['title' => '<?= $title_plural ?>']);
<?= $C ?>


<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<h1><?= $title_plural ?></h1>
<?php if ($surface_on['public_edit']): ?>
		<p><a class="btn btn-primary" href="/<?= $entity_snake ?>_edit">New <?= $title ?></a></p>
<?php endif; ?>
		<table class="jy-table">
			<thead>
				<tr>
<?php foreach ($display_fields as $df): ?>
					<th><?= $df['label'] ?></th>
<?php endforeach; ?>
<?php if ($surface_on['public_edit']): ?>
					<th></th>
<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?= $O ?> foreach ($<?= $plural ?> as $<?= $entity_snake ?>): <?= $C ?>

				<tr>
<?php foreach ($display_fields as $df): ?>
					<td><?= $O ?> echo htmlspecialchars((string)$<?= $entity_snake ?>->get('<?= $df['col'] ?>')); <?= $C ?></td>
<?php endforeach; ?>
<?php if ($surface_on['public_edit']): ?>
					<td><a href="/<?= $entity_snake ?>_edit?<?= $pkey ?>=<?= $O ?> echo $<?= $entity_snake ?>->key; <?= $C ?>">Edit</a></td>
<?php endif; ?>
				</tr>
				<?= $O ?> endforeach; <?= $C ?>

			</tbody>
		</table>
	</div>
</section>
</div>
<?= $O ?>

$page->public_footer(['track' => true]);
<?= $C ?>
