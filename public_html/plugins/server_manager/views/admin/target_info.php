<?php
/**
 * Server Manager - Backup Target Info
 * URL: /admin/server_manager/target_info?bkt_id=N
 *
 * Shows a target's metadata and a listing of its bucket contents grouped by node slug.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/TargetLister.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$bkt_id = intval($_GET['bkt_id'] ?? 0);
if (!$bkt_id) {
	header('Location: /admin/server_manager/targets');
	exit;
}
$target = new BackupTarget($bkt_id, TRUE);
if (!$target->key) {
	header('Location: /admin/server_manager/targets');
	exit;
}

$prefix = rtrim($target->get('bkt_path_prefix') ?: 'joinery-backups', '/') . '/';
$list = TargetLister::list_files($target, 500);

// Group files by node slug (first path segment after prefix)
$by_slug = [];
if (!empty($list['files'])) {
	foreach ($list['files'] as $f) {
		$rel = (strpos($f['key'], $prefix) === 0) ? substr($f['key'], strlen($prefix)) : $f['key'];
		$parts = explode('/', $rel, 2);
		if (count($parts) > 1) {
			$slug = $parts[0];
			$f['filename'] = $parts[1];
		} else {
			$slug = '(root)';
			$f['filename'] = $parts[0];
		}
		$by_slug[$slug][] = $f;
	}
	ksort($by_slug);
}

function format_bytes($bytes) {
	if ($bytes < 1024) return $bytes . ' B';
	if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
	if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
	return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

$provider_labels = ['b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'];
$provider = $target->get('bkt_provider');

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => $target->get('bkt_name'),
	'readable_title' => $target->get('bkt_name'),
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Targets' => '/admin/server_manager/targets',
		$target->get('bkt_name') => '',
	],
	'session' => $session,
]);

// ── Metadata ──
$page->begin_box([
	'title' => 'Target Details',
	'altlinks' => ['Edit' => '/admin/server_manager/targets?bkt_id=' . $target->key],
]);
echo '<table class="table table-sm mb-0" style="max-width:600px">';
echo '<tbody>';
echo '<tr><th style="width:40%">Provider</th><td>' . htmlspecialchars($provider_labels[$provider] ?? $provider) . '</td></tr>';
echo '<tr><th>Bucket</th><td><code>' . htmlspecialchars($target->get('bkt_bucket')) . '</code></td></tr>';
echo '<tr><th>Path Prefix</th><td><code>' . htmlspecialchars($target->get('bkt_path_prefix') ?: '-') . '</code></td></tr>';
echo '<tr><th>Status</th><td><span class="badge bg-' . ($target->get('bkt_enabled') ? 'success' : 'secondary') . '">' . ($target->get('bkt_enabled') ? 'Enabled' : 'Disabled') . '</span></td></tr>';
echo '</tbody></table>';
$page->end_box();

// ── Contents ──
$page->begin_box(['title' => 'Contents']);

if (!$list['success']) {
	echo '<div class="alert alert-danger">Failed to list files: ' . htmlspecialchars($list['error'] ?? 'unknown error') . '</div>';
} elseif (empty($list['files'])) {
	echo '<p class="text-muted mb-0">No files found under prefix <code>' . htmlspecialchars($prefix) . '</code>.</p>';
} else {
	$total = count($list['files']);
	$total_bytes = array_sum(array_column($list['files'], 'size'));
	if (!empty($list['truncated'])) {
		echo '<div class="alert alert-info">Showing first ' . $total . ' files (listing truncated — more exist in the bucket).</div>';
	} else {
		echo '<p class="text-muted small mb-3">' . $total . ' file' . ($total === 1 ? '' : 's') . ', ' . format_bytes($total_bytes) . ' total.</p>';
	}

	foreach ($by_slug as $slug => $files) {
		$slug_bytes = array_sum(array_column($files, 'size'));
		echo '<h6 class="mt-3"><code>' . htmlspecialchars($slug) . '</code> <span class="badge bg-secondary">' . count($files) . '</span> <span class="text-muted small">' . format_bytes($slug_bytes) . '</span></h6>';
		echo '<table class="table table-sm table-striped">';
		echo '<thead><tr><th>File</th><th>Size</th><th>Modified (UTC)</th></tr></thead><tbody>';
		foreach ($files as $f) {
			echo '<tr>';
			echo '<td><code>' . htmlspecialchars($f['filename']) . '</code></td>';
			echo '<td>' . htmlspecialchars(format_bytes($f['size'])) . '</td>';
			echo '<td>' . htmlspecialchars($f['modified'] ?: '-') . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}

$page->end_box();
$page->admin_footer();
?>
