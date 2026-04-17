<?php
/**
 * Admin Help - Documentation Viewer
 * Version: 2.0
 *
 * Renders markdown documentation files from docs/ directory
 * with a sidebar navigation and content area.
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_help_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_help_logic($_GET, $_POST));

$session = $page_vars['session'];
$doc_tree = $page_vars['doc_tree'];
$selected_doc = $page_vars['selected_doc'];
$rendered_html = $page_vars['rendered_html'];
$page_title = $page_vars['page_title'];
$error = $page_vars['error'];

// Build breadcrumbs
$breadcrumbs = array('Help' => '/admin/admin_help');
if (!empty($selected_doc)) {
	$breadcrumbs[$page_title] = '';
}

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => null,
		'page_title' => 'Help' . (!empty($selected_doc) ? ' - ' . $page_title : ''),
		'readable_title' => 'Documentation',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
		'no_page_card' => true,
	)
);

?>

<style>
	<?php echo MarkdownRenderer::get_css(); ?>

	.docs-sidebar {
		position: sticky;
		top: 80px;
		max-height: calc(100vh - 100px);
		overflow-y: auto;
	}
	.docs-sidebar .nav-link {
		padding: 0.35rem 0.75rem;
		font-size: 0.875rem;
		color: #555;
		border-radius: 4px;
	}
	.docs-sidebar .nav-link:hover {
		background-color: #f0f0f0;
		color: #333;
	}
	.docs-sidebar .nav-link.active {
		background-color: #e8f0fe;
		color: #1a73e8;
		font-weight: 500;
	}
	.docs-sidebar .sidebar-group-header {
		font-size: 0.75rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: #888;
		padding: 0.5rem 0.75rem 0.25rem;
		margin-top: 0.5rem;
	}
	.docs-sidebar .sidebar-group-header:first-child {
		margin-top: 0;
	}
	@media (max-width: 991px) {
		.docs-sidebar {
			position: static;
			max-height: none;
			margin-bottom: 1.5rem;
		}
	}
</style>

<div class="row">
	<!-- Sidebar -->
	<div class="col-lg-3">
		<div class="card docs-sidebar mb-3">
			<div class="card-body p-2">
				<nav class="nav flex-column">
					<!-- Home link -->
					<a class="nav-link<?php echo empty($selected_doc) ? ' active' : ''; ?>" href="/admin/admin_help">
						<i class="fas fa-home me-1"></i> Overview
					</a>

					<?php
					// Top-level docs
					if (!empty($doc_tree['_top'])):
					?>
					<div class="sidebar-group-header">Documentation</div>
					<?php foreach ($doc_tree['_top'] as $doc): ?>
					<a class="nav-link<?php echo ($selected_doc === $doc['key']) ? ' active' : ''; ?>"
					   href="/admin/admin_help?doc=<?php echo htmlspecialchars($doc['key']); ?>">
						<?php echo htmlspecialchars($doc['title']); ?>
					</a>
					<?php endforeach; ?>
					<?php endif; ?>

					<?php
					// Subfolder groups
					foreach ($doc_tree as $group => $docs):
						if ($group === '_top') continue;
						$group_title = ucwords(str_replace(array('_', '-'), array(' ', ' '), $group));
					?>
					<div class="sidebar-group-header"><?php echo htmlspecialchars($group_title); ?></div>
					<?php foreach ($docs as $doc): ?>
					<a class="nav-link<?php echo ($selected_doc === $doc['key']) ? ' active' : ''; ?>"
					   href="/admin/admin_help?doc=<?php echo htmlspecialchars($doc['key']); ?>">
						<?php echo htmlspecialchars($doc['title']); ?>
					</a>
					<?php endforeach; ?>
					<?php endforeach; ?>
				</nav>
			</div>
		</div>
	</div>

	<!-- Content -->
	<div class="col-lg-9">
		<div class="card">
			<div class="card-body markdown-content">
				<?php if (!empty($error)): ?>
					<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
				<?php else: ?>
					<?php echo $rendered_html; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<?php
$page->admin_footer();
?>
