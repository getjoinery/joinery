<?php
// PathHelper, Globalvars, SessionControl are pre-loaded by the front controller.

require_once(PathHelper::getIncludePath('adm/logic/admin_marketplace_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_marketplace_logic(array_merge($_GET, $_POST)));

$session = $page_vars['session'] ?? SessionControl::get_instance();

$error = $page_vars['error'] ?? '';
$themes = $page_vars['themes'] ?? array();
$plugins = $page_vars['plugins'] ?? array();
$upgrade_source = $page_vars['upgrade_source'] ?? '';
$catalog_error = $page_vars['catalog_error'] ?? false;

$page = new AdminPage();

// One token shared by every install button — each is a single-button action
// form posting back to this page with the marketplace_install form's token.
$formwriter = $page->getFormWriter('marketplace_install');
$csrf_token = $formwriter->getCSRFToken();

$altlinks = array();
$altlinks['Refresh'] = '/admin/admin_marketplace';

$page->admin_header(array(
	'menu-id' => 'system-marketplace',
	'page_title' => 'Marketplace',
	'readable_title' => 'Marketplace',
	'breadcrumbs' => array(
		'Marketplace' => '',
	),
	'session' => $session,
));

$page->begin_box(array('altlinks' => $altlinks));

/**
 * Maturity badge for a catalog item. Every item gets exactly one, so a card
 * always answers "how finished is this?" — an unmarked item is stable.
 * Labels only: an experimental extension installs like a stable one.
 */
function marketplace_maturity_badge(array $item): string {
	$badges = array(
		'experimental' => array('Experimental', 'badge-warning'),
		'beta'         => array('Beta', 'badge-info'),
		'deprecated'   => array('Deprecated', 'badge-secondary'),
		'stable'       => array('Stable', 'badge-subtle-secondary'),
	);
	$status = strtolower(trim((string)($item['status'] ?? 'stable')));
	if (!isset($badges[$status])) {
		$status = 'stable';
	}
	list($label, $class) = $badges[$status];
	return '<span class="badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

/**
 * One catalog card: name, version, author, description, and either an
 * Installed badge or an Install action button.
 */
function marketplace_render_card(array $item, string $type, string $csrf_token) {
	$display_name = $item['display_name'] ?? $item['name'];
	?>
	<div class="col-lg-4 col-md-6 mb-3">
		<div class="card h-100">
			<div class="card-body d-flex flex-column">
				<h5 class="card-title">
					<?= htmlspecialchars($display_name) ?>
					<small class="text-muted">v<?= htmlspecialchars($item['version'] ?? '1.0.0') ?></small>
				</h5>
				<?php if (!empty($item['author'])): ?>
					<p class="card-text text-muted small mb-1">by <?= htmlspecialchars($item['author']) ?></p>
				<?php endif; ?>
				<div class="marketplace-card-badges">
					<?= marketplace_maturity_badge($item) ?>
					<?php if (!empty($item['is_system'])): ?>
						<span class="badge badge-primary">System</span>
					<?php endif; ?>
					<?php if (!empty($item['unlisted'])): ?>
						<span class="badge badge-subtle-primary" title="Built for named sites — it is not listed in the public catalog">Unlisted</span>
					<?php endif; ?>
				</div>
				<?php if (!empty($item['description'])): ?>
					<p class="card-text small"><?= htmlspecialchars($item['description']) ?></p>
				<?php endif; ?>
				<div class="mt-auto">
					<?php if ($item['install_status'] === 'installed'): ?>
						<span class="badge badge-success">Installed</span>
					<?php else: ?>
						<?= AdminPage::action_button('Install', '/admin/admin_marketplace', array(
							'hidden' => array(
								'action' => 'install',
								'_csrf_token' => $csrf_token,
								'name' => $item['directory_name'] ?? $item['name'],
								'type' => $type,
							),
							'confirm' => 'Install ' . $display_name . '?',
							'class' => 'btn btn-primary btn-sm',
						)) ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
}
?>

<style>
/* The card body is a flex column, so a bare badge would stretch across the
   whole card. Badges sit in one wrapping row of their own instead. */
.marketplace-card-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 0.35rem;
	margin-bottom: 0.6rem;
}
</style>

<div class="container-fluid">
	<div class="row">
		<div class="col-12">
			<?php if ($error): ?>
				<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
			<?php endif; ?>

			<?php if ($catalog_error && empty($error)): ?>
				<div class="alert alert-warning">
					Could not fetch the catalog from <?= htmlspecialchars($upgrade_source) ?>. The source may be unreachable or not publishing extensions.
				</div>
			<?php endif; ?>

			<?php if (!empty($plugins)): ?>
			<h3 class="mb-3">Plugins (<?= count($plugins) ?>)</h3>
			<div class="row mb-4">
				<?php foreach ($plugins as $item) marketplace_render_card($item, 'plugin', $csrf_token); ?>
			</div>
			<?php elseif (empty($error) && !$catalog_error): ?>
				<h3 class="mb-3">Plugins</h3>
				<p class="text-muted">No plugins available from the source.</p>
			<?php endif; ?>

			<?php if (!empty($themes)): ?>
			<h3 class="mb-3">Themes (<?= count($themes) ?>)</h3>
			<div class="row mb-4">
				<?php foreach ($themes as $item) marketplace_render_card($item, 'theme', $csrf_token); ?>
			</div>
			<?php elseif (empty($error) && !$catalog_error): ?>
				<h3 class="mb-3">Themes</h3>
				<p class="text-muted">No themes available from the source.</p>
			<?php endif; ?>

			<?php if ($upgrade_source): ?>
			<div class="mt-3">
				<p class="text-muted small">
					Source: <?= htmlspecialchars($upgrade_source) ?> &middot;
					Have a custom plugin or theme as a ZIP? Upload it from
					<a href="/admin/admin_plugins?show_upload=1">Plugins</a> or
					<a href="/admin/admin_themes?show_upload=1">Themes</a>.
				</p>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
$page->end_box();
$page->admin_footer();
?>
