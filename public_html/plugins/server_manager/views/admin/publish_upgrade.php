<?php
/**
 * Server Manager — Upgrades
 * URL: /admin/server_manager/publish_upgrade
 *
 * Lists published upgrade archives (with delete), and provides the
 * Publish New Upgrade form with optional version override.
 *
 * A publish is a job of this management node's OWN agent: the plane pairs to
 * itself, and the form dispatches the publish_upgrade primitive to that node.
 * There is no plane-local queue and no other transport — the signing key is
 * root-only, and the root agent is its one reader.
 *
 * @version 1.9 - a site that is another management node's node says so, naming that node from its
 *                own agent's credential: its releases are a node action there
 *                (specs/publish_as_node_action.md)
 * @version 1.8.1 - the no-self-node advice leads with the Management Node page, which files the join
 *                  with no shell; the CLI form is the aside
 * @version 1.8 - publishing dispatches to the plane's own node (ManagedNode::self_node()) as the
 *                publish_upgrade primitive; the page says what to do when there is no such node
 *                or its agent is too old (specs/agent_local_queue_retirement.md, G1)
 * @version 1.7
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/UpgradeRetention.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$static_dir = dirname(PathHelper::getIncludePath('')) . '/static_files';
// getIncludePath('') returns public_html/; its dirname is the site root
$archive_dir = rtrim($static_dir, '/');

// ── Delete upgrade ──
if ($_POST && ($_POST['action'] ?? '') === 'delete_upgrade') {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/publish_upgrade'); exit; }
	$delete_id = intval($_POST['upgrade_id'] ?? 0);
	if ($delete_id) {
		try {
			$u = new Upgrade($delete_id, TRUE);
			if ($u->key) {
				$archive_filename = $u->get('upg_name');
				$archive_path = $archive_dir . '/' . $archive_filename;
				if (file_exists($archive_path)) {
					@unlink($archive_path);
				}
				$version_string = $u->get('upg_major_version') . '.' . $u->get('upg_minor_version') . '.' . $u->get('upg_patch_version');
				$u->permanent_delete();
				$session->save_message(new DisplayMessage(
					"Upgrade $version_string deleted.", 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			}
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				'Delete failed: ' . $e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
	}
	header('Location: /admin/server_manager/publish_upgrade');
	exit;
}

// ── Toggle Keep (exempt a release from retention pruning) ──
if ($_POST && ($_POST['action'] ?? '') === 'toggle_keep') {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/publish_upgrade'); exit; }
	$keep_id = intval($_POST['upgrade_id'] ?? 0);
	if ($keep_id) {
		try {
			$u = new Upgrade($keep_id, TRUE);
			if ($u->key) {
				$now_kept = !$u->get('upg_keep');
				$u->set('upg_keep', $now_kept);
				$u->save();
				$version_string = UpgradeRetention::versionString($u);
				$session->save_message(new DisplayMessage(
					$now_kept
						? "Upgrade $version_string will be kept — retention will not remove its archive."
						: "Upgrade $version_string is no longer pinned; retention may remove its archive.",
					'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			}
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				'Could not update: ' . $e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
	}
	header('Location: /admin/server_manager/publish_upgrade');
	exit;
}

// ── Reclaim old archives now ──
if ($_POST && ($_POST['action'] ?? '') === 'prune_archives') {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/publish_upgrade'); exit; }
	try {
		$report = UpgradeRetention::prune();
		if ($report['keep_count'] === 0) {
			$msg = 'Retention is set to keep all archives; nothing removed.';
		} elseif (empty($report['removed'])) {
			$msg = 'Nothing to reclaim — every archive on disk is protected.';
		} else {
			$msg = 'Reclaimed ' . UpgradeRetention::formatBytes($report['bytes']) . ' from '
				. count($report['removed']) . ' archive(s). Release history kept.';
		}
		if (!empty($report['failed'])) {
			$msg .= ' Could not remove: ' . implode(', ', $report['failed']) . '.';
		}
		$session->save_message(new DisplayMessage(
			$msg, 'Retention', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage(
			'Retention aborted: ' . $e->getMessage(), 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /admin/server_manager/publish_upgrade');
	exit;
}

// ── Publish upgrade ──
if ($_POST && ($_POST['action'] ?? '') === 'publish_upgrade') {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/publish_upgrade'); exit; }
	$release_notes = trim($_POST['release_notes'] ?? '');
	$params = ['release_notes' => $release_notes];
	if (isset($_POST['version_major'], $_POST['version_minor'], $_POST['version_patch'])
		&& $_POST['version_major'] !== '' && $_POST['version_minor'] !== '' && $_POST['version_patch'] !== '') {
		$params['major'] = intval($_POST['version_major']);
		$params['minor'] = intval($_POST['version_minor']);
		$params['patch'] = intval($_POST['version_patch']);
	}
	if ($release_notes) {
		$self = ManagedNode::self_node();
		try {
			if (!$self) {
				throw new Exception(publish_self_pairing_advice());
			}
			$built = JobCommandBuilder::build_publish_upgrade($self, $params);
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				$e->getMessage(), 'Cannot publish', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: /admin/server_manager/publish_upgrade');
			exit;
		}
		$job = ManagementJob::createFromBuild($self->key, 'publish_upgrade', $built, $params, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}
}

/**
 * What an operator does when this management node has no record of itself.
 * Publishing runs on this machine's own agent, so the plane has to be a node
 * of itself first — unless it is already a node of ANOTHER management node,
 * in which case that node publishes it, from its node detail page, and this
 * page says which one. One agent holds one credential; a site cannot be its
 * own node and someone else's.
 */
function publish_self_pairing_advice() {
	$own_url = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
	$manager = ManagedNode::managed_by();
	if ($manager !== null) {
		return 'This site is managed by ' . htmlspecialchars($manager) . ', and a release is built by a '
			. 'site\'s own agent at the request of the management node that manages it. Publish this site '
			. 'from that management node: open this site\'s node detail page there, Updates tab, '
			. 'Publish Release on This Node.';
	}
	return 'This management node has no record of itself, and a publish runs as a job of this '
		. 'machine\'s own agent. Connect this site to itself: on the Management Node page '
		. '(/admin/admin_management_node) enter ' . $own_url . ' and connect, then approve the request '
		. 'at the top of the Server Manager dashboard. No shell is needed. (The same ask from a shell '
		. 'is: sudo /usr/local/bin/joinery-agent join --management-node=' . $own_url . '.)';
}

// ── Load upgrade history ──
// classify() walks every release (needed to know which fall outside the
// newest-N window) and reports why each archive is protected, if it is.
$retention_error = '';
$rows = [];
try {
	$rows = UpgradeRetention::classify();
} catch (Exception $e) {
	$retention_error = $e->getMessage();
}
$keep_count = UpgradeRetention::getKeepCount();

$on_disk = 0;
$on_disk_bytes = 0;
$reclaimable = 0;
$reclaimable_bytes = 0;
foreach ($rows as $r) {
	if (!$r['archive_exists']) continue;
	$on_disk++;
	$on_disk_bytes += $r['bytes'];
	if ($r['protected_by'] === null) {
		$reclaimable++;
		$reclaimable_bytes += $r['bytes'];
	}
}
// Default view: the newest 10 releases whose archive is still on disk.
// "Show all" (?show=all) reveals the full history, including releases whose
// archive has already been reclaimed by retention.
$show_all      = (($_GET['show'] ?? '') === 'all');
$default_limit = 10;
$is_reclaimed  = static function ($r) {
	return !$r['archive_exists'] && $r['protected_by'] === null;
};
if ($show_all) {
	$display_rows = $rows;
} else {
	$live_rows    = array_values(array_filter($rows, static function ($r) use ($is_reclaimed) {
		return !$is_reclaimed($r);
	}));
	$display_rows = array_slice($live_rows, 0, $default_limit);
}

// Still needed for next-version auto-detection below.
$upgrades = new MultiUpgrade([], ['upgrade_id' => 'DESC'], 50);
$upgrades->load();

// Auto-detect next version
$current = LibraryFunctions::get_joinery_version();
if ($current !== '' && preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $m)) {
	$next_major = (int)$m[1];
	$next_minor = (int)$m[2];
	$next_patch = (int)$m[3] + 1;
} elseif ($upgrades->count() > 0) {
	$last = $upgrades->get(0);
	$next_major = (int)$last->get('upg_major_version');
	$next_minor = (int)$last->get('upg_minor_version');
	$next_patch = (int)$last->get('upg_patch_version') + 1;
} else {
	$next_major = 0;
	$next_minor = 8;
	$next_patch = 1;
}

// Can this dashboard publish at all? Publishing is a job of this management
// node's own agent, so the plane needs a record of itself whose agent carries
// the primitive. Decided here so the page says what to do instead of offering
// a form whose submit is refused.
$self_node = ManagedNode::self_node();
$cannot_publish = '';
if (!$self_node) {
	$cannot_publish = publish_self_pairing_advice();
} elseif (!JobCommandBuilder::has_primitive($self_node, 'publish_upgrade')) {
	$cannot_publish = 'This management node is paired to itself as '
		. htmlspecialchars($self_node->get('mgn_name')) . ', but its agent ('
		. htmlspecialchars((string)$self_node->get('mgn_agent_version') ?: 'not reporting')
		. ') does not carry the publish primitive yet. Publish once from a shell — sudo /usr/bin/php '
		. htmlspecialchars(PathHelper::getRootDir()) . '/plugins/server_manager/includes/publish_upgrade.php '
		. '\'release notes\' — and the agent updates itself to the release that carries it, about a '
		. 'minute after the publish finishes.';
}

// On a site running exactly what upstream delivered, the number is not the
// operator's to pick: it republishes the version it is running. Offering the
// next patch here is what made a bumped number one unremarkable click — the
// CLI guard behind this form refuses it, but the form should not invite it.
$may_mint = DeploymentHelper::mayMintReleaseVersion();
if (!$may_mint && $current !== '' && preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $m)) {
	$next_major = (int)$m[1];
	$next_minor = (int)$m[2];
	$next_patch = (int)$m[3];
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Upgrades',
	'readable_title' => 'Upgrades',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Upgrades' => '',
	],
	'session' => $session,
]);

// Flash messages
$display_messages = $session->get_messages('/admin/server_manager');
foreach ($display_messages as $msg) {
	$alert_class = 'alert-info';
	if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) $alert_class = 'alert-danger';
	elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) $alert_class = 'alert-success';
	echo '<div class="alert ' . $alert_class . '" role="alert">';
	if ($msg->message_title) echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
	echo htmlspecialchars($msg->message);
	echo '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
}
// Rendered above, so these are spent; the footer drops them.
$session->mark_shown($display_messages);
$session->clear_clearable_messages();

// ── Upgrade History ──
$pageoptions = ['title' => 'Upgrade History'];
$page->begin_box($pageoptions);
?>
<?php if ($retention_error): ?>
	<div class="alert alert-danger" role="alert">
		<strong>Retention unavailable:</strong> <?php echo htmlspecialchars($retention_error); ?>
		Archives are left untouched until this is resolved.
	</div>
<?php endif; ?>
<?php if (empty($rows)): ?>
	<p class="text-muted mb-0">No upgrades published yet.</p>
<?php else: ?>
	<p class="text-muted">
		<?php if ($keep_count === 0): ?>
			Keeping every archive.
		<?php else: ?>
			Keeping the newest <?php echo (int)$keep_count; ?>.
		<?php endif; ?>
		<?php echo (int)$on_disk; ?> archive<?php echo $on_disk === 1 ? '' : 's'; ?> on disk
		(<?php echo htmlspecialchars(UpgradeRetention::formatBytes($on_disk_bytes)); ?>).
		<?php if ($reclaimable > 0): ?>
			<?php echo (int)$reclaimable; ?> reclaimable
			(<?php echo htmlspecialchars(UpgradeRetention::formatBytes($reclaimable_bytes)); ?>).
		<?php endif; ?>
	</p>
	<?php if ($reclaimable > 0 && !$retention_error): ?>
		<form method="post" class="svm-inline-form mb-2">
			<?php echo SmAdminCsrf::field(); ?>
			<input type="hidden" name="action" value="prune_archives">
			<button type="button" class="btn btn-sm btn-outline-secondary" onclick="var f=this.parentElement; JoineryModal.confirm('Remove <?php echo (int)$reclaimable; ?> old archive file(s) and reclaim <?php echo htmlspecialchars(UpgradeRetention::formatBytes($reclaimable_bytes)); ?>? Release history is kept, and versions in use or marked Keep are never removed.', function(){ f.submit(); })">Reclaim old archives</button>
		</form>
	<?php endif; ?>
	<table class="table table-sm mb-0">
		<thead>
			<tr>
				<th>Version</th>
				<th>Published</th>
				<th>Archive</th>
				<th>Release Notes</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($display_rows as $r): ?>
				<?php
				$u        = $r['upgrade'];
				$version  = $r['version'];
				$is_kept  = (bool)$u->get('upg_keep');
				?>
				<tr>
					<td>
						<strong><?php echo htmlspecialchars($version); ?></strong>
						<?php if ($r['in_use_by']): ?>
							<br><small class="text-muted">in use by <?php echo htmlspecialchars($r['in_use_by']); ?></small>
						<?php endif; ?>
					</td>
					<td><small><?php echo $u->get_local('upg_create_time', 'M j, Y g:i A'); ?></small></td>
					<td>
						<?php if ($r['archive_exists']): ?>
							<small class="text-muted"><?php echo htmlspecialchars($r['filename']); ?> (<?php echo htmlspecialchars(UpgradeRetention::formatBytes($r['bytes'])); ?>)</small>
							<?php if ($r['protected_by'] === null): ?>
								<br><small class="text-muted">reclaimable</small>
							<?php endif; ?>
						<?php elseif ($r['protected_by'] === null): ?>
							<span class="badge bg-secondary">reclaimed</span>
						<?php else: ?>
							<span class="badge bg-danger">missing</span>
						<?php endif; ?>
					</td>
					<td><small><?php echo nl2br(htmlspecialchars($u->get('upg_release_notes') ?? '')); ?></small></td>
					<td class="text-end">
						<form method="post" class="svm-inline-form">
							<?php echo SmAdminCsrf::field(); ?>
							<input type="hidden" name="action" value="toggle_keep">
							<input type="hidden" name="upgrade_id" value="<?php echo $u->key; ?>">
							<button type="submit" class="btn btn-sm <?php echo $is_kept ? 'btn-secondary' : 'btn-outline-secondary'; ?>" title="<?php echo $is_kept ? 'Retention will never remove this archive' : 'Pin this archive so retention never removes it'; ?>"><?php echo $is_kept ? 'Kept' : 'Keep'; ?></button>
						</form>
						<form method="post" class="svm-inline-form">
							<?php echo SmAdminCsrf::field(); ?>
							<input type="hidden" name="action" value="delete_upgrade">
							<input type="hidden" name="upgrade_id" value="<?php echo $u->key; ?>">
							<button type="button" class="btn btn-sm btn-outline-danger" onclick="var f=this.parentElement; JoineryModal.confirm('Delete upgrade <?php echo htmlspecialchars($version); ?>? This removes both the archive file and the database record.', function(){ f.submit(); })">Delete</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if (!$show_all && count($rows) > count($display_rows)): ?>
		<p class="text-muted mt-2 mb-0">
			<small>Showing <?php echo count($display_rows); ?> of <?php echo count($rows); ?> releases.</small>
			<a href="/admin/server_manager/publish_upgrade?show=all">Show all</a>
		</p>
	<?php elseif ($show_all && count($rows) > $default_limit): ?>
		<p class="text-muted mt-2 mb-0">
			<small>Showing all <?php echo count($rows); ?> releases.</small>
			<a href="/admin/server_manager/publish_upgrade">Show fewer</a>
		</p>
	<?php endif; ?>
<?php endif; ?>
<?php
$page->end_box();

// ── Publish Form ──
$pageoptions = ['title' => 'Publish New Upgrade'];
$page->begin_box($pageoptions);
?>
<?php if ($cannot_publish): ?>
<div class="jy-callout jy-callout-warning"><?php echo $cannot_publish; ?></div>
<?php elseif ($may_mint): ?>
<p class="text-muted">Build upgrade archives from the current management node source code, as a job of this node's own agent (<?php echo htmlspecialchars($self_node->get('mgn_name')); ?>). The version numbers default to the auto-detected next patch; override if you need a specific version.</p>
<?php else: ?>
<div class="jy-callout jy-callout-info">This deployment is running exactly the version upstream
delivered (<?php echo htmlspecialchars($current); ?>), so it republishes that version rather than
minting a new number. Upgrade it first to serve newer code.</div>
<?php endif; ?>
<?php
if (!$cannot_publish) {
$formwriter = $page->getFormWriter('publish_form');
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', ['value' => 'publish_upgrade']);
$formwriter->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
$formwriter->numberinput('version_major', 'Major', [
	'required' => true,
	'value'    => $next_major,
	'min'      => 0,
	'readonly' => !$may_mint,
]);
$formwriter->numberinput('version_minor', 'Minor', [
	'required' => true,
	'value'    => $next_minor,
	'min'      => 0,
	'readonly' => !$may_mint,
]);
$formwriter->numberinput('version_patch', 'Patch', [
	'required' => true,
	'value'    => $next_patch,
	'min'      => 0,
	'readonly' => !$may_mint,
]);
$formwriter->textarea('release_notes', 'Release notes', [
	'required'    => true,
	'rows'        => 4,
	'placeholder' => 'Describe what changed in this release...',
]);
$formwriter->submitbutton('btn_submit', $may_mint ? 'Publish Upgrade' : 'Republish ' . htmlspecialchars($current));
$formwriter->end_form();
}
?>
<a href="/admin/server_manager" class="btn btn-link">Cancel</a>
<?php
$page->end_box();
$page->admin_footer();
?>
