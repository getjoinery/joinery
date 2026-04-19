<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/pages_class.php'));
	require_once(PathHelper::getIncludePath('/data/page_contents_class.php'));
	require_once(PathHelper::getIncludePath('/data/components_class.php'));
	require_once(PathHelper::getIncludePath('/includes/PhotoHelper.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	// Require page ID - redirect to list if not provided
	if (empty($_GET['pag_page_id'])) {
		header("Location: /admin/admin_pages");
		exit();
	}

	$page = new Page($_GET['pag_page_id'], TRUE);

	// Handle layout save from the drag-reorder picker
	if (isset($_POST['action']) && $_POST['action'] === 'save_layout') {
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$ids = isset($_POST['layout']) && is_array($_POST['layout'])
			? array_values(array_map('intval', $_POST['layout']))
			: [];
		$page->set('pag_component_layout', $ids);
		$page->save();
		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}

	if($_REQUEST['action'] == 'delete'){
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page->soft_delete();

		header("Location: /admin/admin_pages");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page->undelete();

		header("Location: /admin/admin_pages");
		exit();
	}
	else if($_REQUEST['action'] == 'remove_component'){
		// Remove a component from this page's layout (keeps the component itself)
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$remove_id = intval($_POST['pac_page_content_id'] ?? 0);
		$layout = $page->get_component_layout();
		$layout = array_values(array_filter($layout, function($id) use ($remove_id) {
			return (int)$id !== $remove_id;
		}));
		$page->set('pag_component_layout', $layout);
		$page->save();
		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}
	else if($_REQUEST['action'] == 'add_component'){
		// Append an existing component to this page's layout
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$add_id = intval($_POST['pac_page_content_id'] ?? 0);
		if ($add_id) {
			$layout = $page->get_component_layout();
			if (!in_array($add_id, array_map('intval', $layout), true)) {
				$layout[] = $add_id;
				$page->set('pag_component_layout', $layout);
				$page->save();
			}
		}
		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}
	else if($_REQUEST['action'] == 'set_primary_photo'){
		$page->set_primary_photo((int)$_POST['photo_id']);

		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}
	else if($_REQUEST['action'] == 'clear_primary_photo'){
		$page->clear_primary_photo();

		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}

	// Build dropdown actions
	$options['altlinks'] = array('Edit Page' => '/admin/admin_page_edit?pag_page_id='.$page->key);
	if(!$page->get('pag_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_page?action=delete&pag_page_id='.$page->key;
	}
	else if($_SESSION['permission'] >= 8){
		$options['altlinks']['Undelete'] = '/admin/admin_page?action=undelete&pag_page_id='.$page->key;
	}
	$options['altlinks'] += array('Permanent Delete' => '/admin/admin_page_permanent_delete?pag_page_id='.$page->key);

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Load the layout and its components for the picker
	$layout = $page->get_component_layout();
	$layout_components = [];
	if (!empty($layout)) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$placeholders = implode(',', array_fill(0, count($layout), '?'));
		$sql = 'SELECT * FROM pac_page_contents
				WHERE pac_page_content_id IN (' . $placeholders . ')
				  AND pac_delete_time IS NULL';
		$q = $dblink->prepare($sql);
		$q->execute(array_map('intval', $layout));
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);
		$fields = array_keys(PageContent::$field_specifications);
		$by_id = [];
		foreach ($rows as $row) {
			$component = new PageContent($row['pac_page_content_id']);
			$component->load_from_data($row, $fields);
			$by_id[(int)$row['pac_page_content_id']] = $component;
		}
		foreach ($layout as $pac_id) {
			$pac_id = (int)$pac_id;
			if (isset($by_id[$pac_id])) {
				$layout_components[] = $by_id[$pac_id];
			}
		}
	}

	// Components available to add (not already in this page's layout)
	$available_components_ms = new MultiPageContent(
		['components_only' => true, 'deleted' => false],
		['pac_title' => 'ASC']
	);
	$available_components_ms->load();
	$layout_ids = array_map('intval', $layout);
	$available_components = [];
	foreach ($available_components_ms as $comp) {
		if (!in_array((int)$comp->key, $layout_ids, true)) {
			$available_components[(int)$comp->key] = $comp->get('pac_title') ?: ('(' . $comp->get('pac_location_name') . ')') ?: ('#' . $comp->key);
		}
	}

	$paget = new AdminPage();
	$paget->admin_header(
	array(
		'menu-id'=> 'pages',
		'page_title' => 'Page',
		'readable_title' => $page->get('pag_title'),
		'breadcrumbs' => array(
			'Pages'=>'/admin/admin_pages',
			$page->get('pag_title')=>'',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Page Information Card -->
	<div class="card mb-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Page Information</h5>
		</div>
		<div class="card-body">
			<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
				<tbody>
					<tr>
						<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Title</td>
						<td class="p-1 text-600"><?php echo htmlspecialchars($page->get('pag_title')); ?></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Link</td>
						<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($page->get_url()); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($page->get_url())); ?></a></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Created</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($page->get('pag_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Status</td>
						<td class="p-1">
							<?php if($page->get('pag_delete_time')): ?>
								<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($page->get('pag_delete_time'), 'UTC', $session->get_timezone()); ?></span>
							<?php elseif($page->get('pag_published_time')): ?>
								<span class="badge badge-subtle-success">Published</span>
							<?php else: ?>
								<span class="badge badge-subtle-secondary">Unpublished</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if($page->get('pag_published_time')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Published</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($page->get('pag_published_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<?php endif; ?>
					<?php if($page->get('pag_last_edit_time')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Last Edited</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($page->get('pag_last_edit_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Page Layout: drag-reorder picker over pag_component_layout -->
	<div class="card mb-3">
		<div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
			<h5 class="mb-0">Page Layout</h5>
			<a href="/admin/admin_component_edit?pag_page_id=<?php echo (int)$page->key; ?>" class="btn btn-sm btn-outline-primary">New Component</a>
		</div>
		<div class="card-body">
			<?php
			$has_legacy_body_content = !empty(trim($page->get('pag_body') ?? ''));
			if ($has_legacy_body_content && empty($layout_components)) {
				echo '<div class="alert alert-warning">';
				echo 'This page renders its <code>pag_body</code> HTML directly. Adding any component to the layout will switch rendering to the component list — edit the page body out or leave it as fallback.';
				echo '</div>';
			}
			?>
			<form method="post" id="layoutForm">
				<input type="hidden" name="action" value="save_layout">
				<ul id="layoutList" class="list-group mb-3">
					<?php if (empty($layout_components)): ?>
						<li class="list-group-item text-muted text-center py-3" id="layoutEmpty">
							No components in the layout. Add one below.
						</li>
					<?php else: foreach ($layout_components as $component): ?>
						<li class="list-group-item d-flex justify-content-between align-items-center" data-id="<?php echo (int)$component->key; ?>">
							<span>
								<span class="drag-handle me-2" style="cursor:move;">&#x2630;</span>
								<?php
								$comp_type = $component->get_component_type();
								$type_label = $comp_type ? $comp_type->get('com_title') : 'Unknown';
								$title = $component->get('pac_title') ?: '(untitled)';
								?>
								<a href="/admin/admin_component_edit?pac_page_content_id=<?php echo (int)$component->key; ?>&pag_page_id=<?php echo (int)$page->key; ?>"><?php echo htmlspecialchars($title); ?></a>
								<small class="text-muted ms-2">&middot; <?php echo htmlspecialchars($type_label); ?></small>
							</span>
							<span>
								<input type="hidden" name="layout[]" value="<?php echo (int)$component->key; ?>">
								<?php
								echo AdminPage::action_button('Remove', '', [
									'hidden' => ['action' => 'remove_component', 'pac_page_content_id' => $component->key, 'pag_page_id' => $page->key],
									'confirm' => 'Remove this component from the page layout? (The component itself is not deleted.)',
									'class'  => 'btn btn-sm btn-outline-danger',
								]);
								?>
							</span>
						</li>
					<?php endforeach; endif; ?>
				</ul>
				<?php if (!empty($layout_components)): ?>
					<button type="submit" class="btn btn-primary btn-sm">Save Order</button>
				<?php endif; ?>
			</form>

			<hr>

			<form method="post" class="mt-3">
				<input type="hidden" name="action" value="add_component">
				<div class="row g-2">
					<div class="col-md-8">
						<select name="pac_page_content_id" class="form-select form-select-sm" required>
							<option value="">-- Select a component to add --</option>
							<?php foreach ($available_components as $id => $label): ?>
								<option value="<?php echo (int)$id; ?>"><?php echo htmlspecialchars($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-4">
						<button type="submit" class="btn btn-sm btn-outline-primary w-100">Add to Layout</button>
					</div>
				</div>
			</form>
		</div>
	</div>

	<?php
	// Page Photos Card
	$page_photos = $page->get_photos();
	$photo_editable = !$page->get('pag_delete_time') && $_SESSION['permission'] > 4;
	PhotoHelper::render_photo_card('grid', 'page', $page->key, $page_photos, [
		'set_primary_url' => '/admin/admin_page?pag_page_id=' . $page->key,
		'card_title' => 'Page Photos',
		'editable' => $photo_editable,
		'primary_file_id' => $page->get('pag_fil_file_id'),
	]);
	?>

	<?php if($photo_editable): ?>
	<?php PhotoHelper::render_photo_scripts('grid', 'page', $page->key, [
		'set_primary_url' => '/admin/admin_page?pag_page_id=' . $page->key,
		'confirm_delete_msg' => 'Remove this photo from this page?',
	]); ?>
	<?php endif; ?>

	<?php
	// A/B Testing Panel — only renders if Page has opted into the framework
	if (!empty(Page::$ab_testable)) {
		require_once(PathHelper::getIncludePath('data/abt_tests_class.php'));
		AbTestVersionsPanel::render('Page', $page->key);
	}
	?>

	<!-- Page Preview Card (Full Width) -->
	<div class="card mt-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Page Preview</h5>
		</div>
		<div class="card-body p-0">
			<iframe src="<?php echo htmlspecialchars($page->get_url()); ?>" width="100%" height="650" style="border:none;"></iframe>
		</div>
	</div>

	<script>
	(function(){
		var list = document.getElementById('layoutList');
		if (!list) return;
		var dragSrc = null;
		Array.from(list.querySelectorAll('li[data-id]')).forEach(function(li){
			li.setAttribute('draggable', 'true');
			li.addEventListener('dragstart', function(e){
				dragSrc = li;
				li.classList.add('opacity-50');
				e.dataTransfer.effectAllowed = 'move';
			});
			li.addEventListener('dragover', function(e){
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				var rect = li.getBoundingClientRect();
				var before = (e.clientY - rect.top) < rect.height / 2;
				if (dragSrc && dragSrc !== li) {
					if (before) list.insertBefore(dragSrc, li);
					else list.insertBefore(dragSrc, li.nextSibling);
				}
			});
			li.addEventListener('dragend', function(){
				li.classList.remove('opacity-50');
				dragSrc = null;
			});
		});
	})();
	</script>

	<?php

	$paget->admin_footer();
?>
