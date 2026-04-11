<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getIncludePath('plugins/server_manager/logic/admin_marketplace_logic.php'));

$page_vars = process_logic(admin_marketplace_logic($_GET, $_POST));

$session = SessionControl::get_instance();

$message = $page_vars['message'] ?? '';
$error = $page_vars['error'] ?? '';
$themes = $page_vars['themes'] ?? array();
$plugins = $page_vars['plugins'] ?? array();
$upgrade_source = $page_vars['upgrade_source'] ?? '';
$catalog_error = $page_vars['catalog_error'] ?? false;

// Generate CSRF token for install forms
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_tokens']['marketplace_install'] = [
	'token' => $csrf_token,
	'expires' => time() + 3600,
];

$page = new AdminPage();

$altlinks = array();
$altlinks['Refresh'] = '/admin/server_manager/marketplace';

$page->admin_header(array(
	'menu-id' => 'server-manager',
	'page_title' => 'Marketplace',
	'readable_title' => 'Marketplace',
	'breadcrumbs' => array(
		'Server Manager' => '/admin/server_manager',
		'Marketplace' => '',
	),
	'session' => $session,
));

$page->begin_box(array('altlinks' => $altlinks));
?>

<div class="container-fluid">
	<div class="row">
		<div class="col-12">
			<?php if ($message): ?>
				<div class="alert alert-success"><?= $message ?></div>
			<?php endif; ?>

			<?php if ($error): ?>
				<div class="alert alert-danger"><?= $error ?></div>
			<?php endif; ?>

			<?php if ($catalog_error && empty($error)): ?>
				<div class="alert alert-warning">
					<i class="fas fa-exclamation-triangle"></i>
					Could not fetch catalog from the upgrade server. The server may be unreachable or not configured as an upgrade server.
				</div>
			<?php endif; ?>

			<?php if (!empty($themes)): ?>
			<h3 class="mb-3">Themes (<?= count($themes) ?>)</h3>
			<div class="row mb-4">
				<?php foreach ($themes as $item): ?>
				<div class="col-lg-4 col-md-6 mb-3">
					<div class="card h-100">
						<div class="card-body d-flex flex-column">
							<h5 class="card-title">
								<?= htmlspecialchars($item['display_name'] ?? $item['name']) ?>
								<small class="text-muted">v<?= htmlspecialchars($item['version'] ?? '1.0.0') ?></small>
							</h5>
							<?php if (!empty($item['author'])): ?>
								<p class="card-text text-muted small mb-1">by <?= htmlspecialchars($item['author']) ?></p>
							<?php endif; ?>
							<?php if (!empty($item['description'])): ?>
								<p class="card-text small"><?= htmlspecialchars($item['description']) ?></p>
							<?php endif; ?>
							<?php if (!empty($item['is_system'])): ?>
								<span class="badge bg-primary mb-2"><i class="fas fa-lock me-1"></i>System</span>
							<?php endif; ?>
							<div class="mt-auto">
								<?php if ($item['install_status'] === 'installed'): ?>
									<span class="badge bg-success"><i class="fas fa-check me-1"></i>Installed</span>
								<?php else: ?>
									<form method="post" class="d-inline" onsubmit="return confirm('Install <?= htmlspecialchars($item['display_name'] ?? $item['name'], ENT_QUOTES) ?>?');">
										<input type="hidden" name="action" value="install">
										<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
										<input type="hidden" name="name" value="<?= htmlspecialchars($item['directory_name'] ?? $item['name']) ?>">
										<input type="hidden" name="type" value="theme">
										<button type="submit" class="btn btn-primary btn-sm">
											<i class="fas fa-download me-1"></i>Install
										</button>
									</form>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php elseif (empty($error) && !$catalog_error): ?>
				<h3 class="mb-3">Themes</h3>
				<p class="text-muted">No themes available from the upgrade server.</p>
			<?php endif; ?>

			<?php if (!empty($plugins)): ?>
			<h3 class="mb-3">Plugins (<?= count($plugins) ?>)</h3>
			<div class="row mb-4">
				<?php foreach ($plugins as $item): ?>
				<div class="col-lg-4 col-md-6 mb-3">
					<div class="card h-100">
						<div class="card-body d-flex flex-column">
							<h5 class="card-title">
								<?= htmlspecialchars($item['display_name'] ?? $item['name']) ?>
								<small class="text-muted">v<?= htmlspecialchars($item['version'] ?? '1.0.0') ?></small>
							</h5>
							<?php if (!empty($item['author'])): ?>
								<p class="card-text text-muted small mb-1">by <?= htmlspecialchars($item['author']) ?></p>
							<?php endif; ?>
							<?php if (!empty($item['description'])): ?>
								<p class="card-text small"><?= htmlspecialchars($item['description']) ?></p>
							<?php endif; ?>
							<div class="mt-auto">
								<?php if ($item['install_status'] === 'installed'): ?>
									<span class="badge bg-success"><i class="fas fa-check me-1"></i>Installed</span>
								<?php else: ?>
									<form method="post" class="d-inline" onsubmit="return confirm('Install <?= htmlspecialchars($item['display_name'] ?? $item['name'], ENT_QUOTES) ?>?');">
										<input type="hidden" name="action" value="install">
										<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
										<input type="hidden" name="name" value="<?= htmlspecialchars($item['directory_name'] ?? $item['name']) ?>">
										<input type="hidden" name="type" value="plugin">
										<button type="submit" class="btn btn-primary btn-sm">
											<i class="fas fa-download me-1"></i>Install
										</button>
									</form>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php elseif (empty($error) && !$catalog_error): ?>
				<h3 class="mb-3">Plugins</h3>
				<p class="text-muted">No plugins available from the upgrade server.</p>
			<?php endif; ?>

			<?php if ($upgrade_source): ?>
			<div class="mt-3">
				<p class="text-muted small">
					Source: <?= htmlspecialchars($upgrade_source) ?>
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
