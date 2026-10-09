<?php
/**
 * Server Manager - Backup Targets
 * URL: /admin/server_manager/targets
 *
 * CRUD page for managing backup storage targets, at any provider in StorageProvider's catalogue.
 *
 * @version 2.17 - the form is the Backups page's: one key per target, since nodes write through the backup
 *                 broker and are handed none (specs/storage_targets.md WP5)
 * @version 2.16 - Delete all is refused for the folder an owner's new backups go to
 * @version 2.15 - storage spaces (specs/storage_targets.md WP4): who backs up to this target and whose older
 *                 backups it keeps, Move everyone off to another target, Stored Backups classified by space,
 *                 and an unclaimed folder adopted as a node's or a customer's draining space
 * @version 2.14 - Where new backups go is drawn by SettingsFieldRenderer from its declaration (a page may not
 *                 draw a declared setting's field itself)
 * @version 2.13 - Where new backups go (server_manager_backup_target_id), chosen here among the targets switched
 *                 on; a delete is refused while the target is used (BackupTarget::delete_refusal())
 * @version 2.12 - the form and its save are BackupTargetForm, the one target form the core Backups page and
 *                 the setup wizard also draw; this page adds the node key and per-run key fields
 * @version 2.11 - a provider change, or a provider with no node key, drops the stored node key
 * @version 2.10 - a Linode endpoint is checked (BackupTarget::credential_problem) before the target is tested or saved
 * @version 2.9 - the Stored Backups sizes use BackupRunner::human() (decimal units, as the provider bills)
 * @version 2.8 - stored secrets are locked fields with Reset (FormWriter 'stored' +
 *                process_secretinput()); Reset and save blank removes the node credential, and
 *                the Remove node credential box is gone
 * @version 2.7 - an enabled target is proven before it is saved (TargetTester 4.0: own bucket, private,
 *                prune, the node key write-only, Backblaze capabilities); a failing one is not saved;
 *                the key fields name the permissions each key needs
 * @version 2.6 - node credential (write-only): a target can hold a second key handed to nodes
 *                during a backup run, so the delete-capable key never leaves the management node.
 *                B2 and S3 only; Linode cannot express write-without-delete and says so.
 * @version 2.5 - recovery key setup is drawn once, on the core Backups page; this page keeps the
 *                standing state line and links there. The three POST handlers that served the
 *                walkthrough here are gone with the form that posted to them.
 * @version 2.4 - guided backup key recovery walkthrough (detects the outstanding step and walks
 *                it) replaces the bare verify card; public-key save/clear and bulk node escrow
 *                actions added
 * @version 2.3 - Stored Backups panel: list + delete offsite objects from the management node
 *                (node-independent), grouped by site with live/decommissioned/orphaned tags
 * @version 2.2 - possession check for the recovery key; CSRF on the save handler; undecryptable stored credentials surfaced instead of silently merged
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
require_once(PathHelper::getIncludePath('includes/TargetTester.php'));
require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackups.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
// Read by the recovery-key panel below on every render, not only by the POST
// handlers that also require it — a plain GET has to have it too.
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load or create target
$target = null;
$is_edit = false;
if (isset($_GET['bkt_backup_target_id']) && $_GET['bkt_backup_target_id']) {
	$target = new BackupTarget(intval($_GET['bkt_backup_target_id']), TRUE);
	$is_edit = true;
} elseif (isset($_GET['action']) && $_GET['action'] === 'add') {
	$target = new BackupTarget(NULL);
}

// Test and delete are POST actions (a GET link is CSRF-triggerable), CSRF-validated.
$post_action = ($_POST['action'] ?? '');

if ($post_action === 'test_target' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$result = TargetTester::test($target);
	$page_regex = '/\/admin\/server_manager/';
	$session->save_message(new DisplayMessage(
		'Test "' . $target->get('bkt_name') . '": ' . $result['message'],
		$result['success'] ? 'Success' : 'Error',
		$page_regex,
		$result['success'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/targets');
	exit;
}

if ($post_action === 'delete_target' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	$refusal = $target->delete_refusal();
	if ($refusal !== '') {
		$session->save_message(new DisplayMessage(
			'Not deleted. ' . $refusal, 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/targets?bkt_backup_target_id=' . $target->key);
		exit;
	}
	$target->soft_delete();
	$session->save_message(new DisplayMessage(
		'Target deleted.', 'Success', $page_regex,
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/targets');
	exit;
}

// Where new backups go: the target every new node and every new customer of
// backup storage is given. A choice, never inferred (specs/storage_targets.md R6).
if ($post_action === 'save_default_target') {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	$chosen = new BackupTarget((int)($_POST['server_manager_backup_target_id'] ?? 0), TRUE);
	if (!$chosen->key || $chosen->get('bkt_delete_time') || !$chosen->get('bkt_enabled')) {
		$session->save_message(new DisplayMessage(
			'Not saved. New backups can only go to a target that is switched on.', 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	} else {
		Setting::put('server_manager_backup_target_id', (string)(int)$chosen->key);
		$session->save_message(new DisplayMessage(
			'New nodes and new customers of backup storage now back up to "' . $chosen->get('bkt_name')
				. '". Nodes already backing up stay where they are until they are moved.',
			'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /admin/server_manager/targets');
	exit;
}

// Move everyone off: every owner whose new backups go here is moved to another
// target. Their backups already here stay, readable and restorable, and age out
// once the new target holds a verified backup (R3). Nothing is copied.
if ($post_action === 'move_everyone_off' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	try {
		$to = new BackupTarget((int)($_POST['to_target_id'] ?? 0), TRUE);
		$moved = StorageSpace::move_everyone_off($target, $to);
		$msg = count($moved['moved']) . ' moved to "' . $to->get('bkt_name') . '".';
		foreach ($moved['refused'] as $name => $why) {
			$msg .= ' ' . $name . ' was not moved: ' . $why;
		}
		$session->save_message(new DisplayMessage($msg, $moved['refused'] ? 'Error' : 'Success', $page_regex,
			$moved['refused'] ? DisplayMessage::MESSAGE_ERROR : DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage($e->getMessage(), 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	}
	header('Location: /admin/server_manager/targets?bkt_backup_target_id=' . $target->key);
	exit;
}

// Adopt an unclaimed folder: a folder on this target no storage space claims
// (left by an earlier switch, or by a deleted node) becomes the chosen owner's
// draining space, so its backups are listable, restorable and pruned again.
if ($post_action === 'adopt_folder' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	try {
		$owner = explode(':', (string)($_POST['owner'] ?? ''), 2);
		$kind = $owner[0] === 'tenant' ? StorageSpace::OWNER_TENANT : StorageSpace::OWNER_NODE;
		$space = StorageSpace::adopt($target, trim((string)($_POST['folder'] ?? '')), $kind, (int)($owner[1] ?? 0));
		$session->save_message(new DisplayMessage(
			'The folder ' . $space->base() . ' is now ' . $space->owner_name() . '\'s, kept while its backups age out.',
			'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage('Not adopted. ' . $e->getMessage(), 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
	}
	header('Location: /admin/server_manager/targets?bkt_backup_target_id=' . $target->key);
	exit;
}

// Recovery key setup lives in one place — the core Backups page, which draws
// RecoveryKeySetupPanel. This page shows the standing state and links there.

// Delete every offsite backup object for one site (whole slug prefix). Run from the
// management node against the bucket — no live node needed, so a decommissioned site's
// backups are still reachable. Type-to-confirm on the client, slug-validated on the server.
if ($post_action === 'delete_backup_prefix' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	$slug = trim($_POST['slug'] ?? '');
	try {
		$live = StorageSpace::for_base((int)$target->key, TargetBackups::base_prefix($target) . $slug . '/');
		if ($live && $live->is_active()) {
			throw new Exception('New backups of ' . $live->owner_name() . ' go to this folder. Move '
				. $live->owner_name() . ' to another target first; its backups here then age out, or can be deleted.');
		}
		$n = TargetBackups::delete_prefix($target, $slug);
		// A space kept only for its old backups has nothing left to keep.
		$emptied = StorageSpace::for_base((int)$target->key, TargetBackups::base_prefix($target) . $slug . '/');
		if ($emptied && $emptied->is_draining()) {
			$emptied->retire();
		}
		$session->save_message(new DisplayMessage(
			'Deleted ' . $n . ' backup object' . ($n === 1 ? '' : 's') . ' for "' . $slug . '".',
			'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage(
			$e->getMessage(), 'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /admin/server_manager/targets?bkt_backup_target_id=' . $target->key);
	exit;
}

// Delete a single offsite backup object. The key is validated to sit under this
// target's prefix before the delete is issued.
if ($post_action === 'delete_backup_object' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	try {
		TargetBackups::delete_object($target, $_POST['key'] ?? '');
		$session->save_message(new DisplayMessage(
			'Backup object deleted.', 'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage(
			$e->getMessage(), 'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /admin/server_manager/targets?bkt_backup_target_id=' . $target->key);
	exit;
}

// Handle form save: the one target form and save path (BackupTargetForm).
$error = null;
if ($_POST && isset($_POST['bkt_name'])) {
	// Same CSRF gate as every other mutation on this page: this handler writes
	// storage credentials, the highest-value forgery target here.
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	if (!$target) {
		$target = new BackupTarget(NULL);
	}
	$saved = BackupTargetForm::save($target, $_POST);
	if ($saved['ok']) {
		$session->save_message(new DisplayMessage(
			$saved['message'], 'Success', '/\/admin\/server_manager/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/targets?bkt_backup_target_id=' . $target->key);
		exit;
	}
	$error = $saved['message'];
	$is_edit = $target->key ? true : false;
}

// Load all targets for listing
$all_targets = new MultiBackupTarget(['deleted' => false], ['bkt_name' => 'ASC']);
$all_targets->load();

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Backup Targets',
	'readable_title' => 'Backup Targets',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Targets' => '',
	],
	'session' => $session,
]);

// Display messages
$display_messages = $session->get_messages('/admin/server_manager');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = $msg->display_type == DisplayMessage::MESSAGE_ERROR ? 'alert-danger' : 'alert-success';
		echo '<div class="alert ' . $alert_class . '">';
		echo htmlspecialchars($msg->message);
		echo ' ' . $msg->report_link_html();
		echo '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
	}
	// Rendered above, so these are spent; the footer drops them.
	$session->mark_shown($display_messages);
	$session->clear_clearable_messages();
}

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// ── Backup key recovery ──
// The guided walkthrough: it detects how far setup has got and renders the one
// outstanding step. Once every targeted node is escrowed it collapses to a
// standing summary of what recovery would look like.
// Recovery key setup is core, not fleet: a standalone site needs it just as
// much. This page links there rather than carrying a second copy of the panel.
$rk_state = BackupRecoveryKey::setup_state();
echo '<div class="alert ' . ($rk_state['is_ready'] ? 'alert-success' : 'alert-warning') . ' border" id="backup-key-setup">';
echo '<strong>Backup key recovery.</strong> '
   . htmlspecialchars(BackupRecoveryKey::outstanding_summary($rk_state));
if ($rk_state['is_ready']) {
	echo ' Key ' . htmlspecialchars($rk_state['fingerprint']) . '&hellip;';
}
echo ' <a href="' . BackupRecoveryKey::SETUP_URL . '" class="alert-link">Open backup settings</a>.';
echo '</div>';

// ── Which nodes can be backed up at all ──
// Every backup of a node seals to the recovery key that NODE holds and has
// proven, read there — the copies taken from here as much as the copies it takes
// for itself. Nothing supplies a key from this management node, because a key sent
// from here would let this management node decide who can open a node's database
// and mail, with nothing anywhere looking wrong. So a node with no verified key
// is not a node with a preference; it is a node nobody is backing up, and this
// table is the fleet's coverage list.
$rk_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$rk_nodes->load();

$rk_rows = [];
foreach ($rk_nodes as $rk_node) {
	$rk_state = RecoveryKeyFleet::node_state($rk_node);
	if ($rk_state['state'] === 'n/a') continue;   // not applicable, not a gap
	$rk_rows[] = ['node' => $rk_node, 'rk' => $rk_state];
}

if ($rk_rows) {
	$page->begin_box(['title' => 'Which nodes can be backed up']);
	echo '<p class="text-muted">A backup is encrypted to the recovery key the node itself holds and '
	   . 'has verified, and that key is set up by whoever administers the node, on the node\'s own '
	   . 'Backups page. This management node deliberately cannot supply one &mdash; a key sent from here '
	   . 'would be a key this machine could open every node\'s backups with. A node without a verified '
	   . 'key takes no backups, including the ones scheduled from here.</p>';
	echo '<table class="table table-sm"><thead><tr>'
	   . '<th>Node</th><th>Recovery key on the node</th>'
	   . '</tr></thead><tbody>';
	$rk_badges = ['proven' => 'success', 'missing' => 'warning', 'unproven' => 'warning', 'unknown' => 'secondary'];
	$rk_labels = ['proven' => 'can be backed up', 'missing' => 'no key — not backed up',
		'unproven' => 'not verified — not backed up', 'unknown' => 'not checked yet'];
	foreach ($rk_rows as $row) {
		$n  = $row['node'];
		$rk = $row['rk'];
		echo '<tr>';
		echo '<td><a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$n->key . '&tab=backups">'
		   . htmlspecialchars($n->get('mgn_name')) . '</a></td>';
		echo '<td><span class="badge bg-' . ($rk_badges[$rk['state']] ?? 'secondary') . '">'
		   . htmlspecialchars($rk_labels[$rk['state']] ?? $rk['state']) . '</span> ';
		echo '<span class="small text-muted">' . htmlspecialchars($rk['summary']);
		if ($rk['fingerprint'] !== '') {
			echo ' (' . htmlspecialchars(RecoveryKeyFleet::short($rk['fingerprint'])) . '&hellip;)';
		}
		echo '</span></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
}

// ── Target List ──
$pageoptions = ['title' => 'Backup Targets', 'altlinks' => ['Add Target' => '/admin/server_manager/targets?action=add']];
$page->begin_box($pageoptions);

echo '<table class="table table-striped table-sm">';
echo '<thead><tr><th>Name</th><th>Provider</th><th>Bucket</th><th>Path Prefix</th><th>Status</th><th>Actions</th></tr></thead>';
echo '<tbody>';

$target_count = 0;
foreach ($all_targets as $t) {
	$target_count++;
	$prov_label = StorageProvider::label($t->get('bkt_provider'));
	$enabled = $t->get('bkt_enabled');
	echo '<tr>';
	echo '<td><a href="/admin/server_manager/target_info?bkt_backup_target_id=' . $t->key . '">' . htmlspecialchars($t->get('bkt_name')) . '</a></td>';
	echo '<td>' . htmlspecialchars($prov_label) . '</td>';
	echo '<td>' . htmlspecialchars($t->get('bkt_bucket') ?: '-') . '</td>';
	echo '<td>' . htmlspecialchars($t->get('bkt_path_prefix') ?: '-') . '</td>';
	echo '<td><span class="badge bg-' . ($enabled ? 'success' : 'secondary') . '">' . ($enabled ? 'Enabled' : 'Disabled') . '</span></td>';
	echo '<td><a href="/admin/server_manager/targets?bkt_backup_target_id=' . $t->key . '" class="btn btn-sm btn-outline-primary">Edit</a> ';
	// Test is a POST action (it hits the provider; a GET link is CSRF-triggerable).
	echo '<form method="post" action="/admin/server_manager/targets?bkt_backup_target_id=' . $t->key . '" style="display:inline;">';
	echo '<input type="hidden" name="action" value="test_target">';
	echo SmAdminCsrf::field();
	echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Test</button>';
	echo '</form></td>';
	echo '</tr>';
}

if ($target_count === 0) {
	echo '<tr><td colspan="6" class="text-muted text-center">No backup targets configured. Backups are stored locally on each node.</td></tr>';
}

echo '</tbody></table>';

// ── Where new backups go ──
$default_id = (int)Globalvars::get_instance()->get_setting('server_manager_backup_target_id', false, true);
$default_options = [];
foreach ($all_targets as $t) {
	if ($t->get('bkt_enabled') || (int)$t->key === $default_id) {
		$default_options[(string)(int)$t->key] = $t->get('bkt_name') . ($t->get('bkt_enabled') ? '' : ' (switched off)');
	}
}
if ($default_options) {
	if (!isset($default_options[(string)$default_id])) {
		echo '<div class="alert alert-warning">Choose where new backups go. Until you do, a new node backs up on its own disk only, '
			. 'and backup storage for customers has nowhere to put anything.</div>';
	}
	$fdef = $page->getFormWriter('default_target_form');
	$fdef->begin_form();
	echo SmAdminCsrf::field();
	$fdef->hiddeninput('action', '', ['value' => 'save_default_target']);
	// A declared setting is drawn by its declaration; this page only narrows
	// the choices to the targets switched on (and the current one).
	$skip = [];
	foreach ($all_targets as $t) {
		if (!isset($default_options[(string)(int)$t->key])) { $skip[] = (string)(int)$t->key; }
	}
	if (isset($default_options[(string)$default_id])) { $skip[] = '0'; }
	SettingsFieldRenderer::renderGroup($fdef, 'services', [
		'source'        => 'server_manager',
		'only'          => ['server_manager_backup_target_id'],
		'values'        => ['server_manager_backup_target_id' => (string)$default_id],
		'field_options' => ['server_manager_backup_target_id' => ['skip_options' => $skip]],
	]);
	$fdef->submitbutton('btn_default_target', 'Save', ['class' => 'btn btn-sm btn-outline-primary']);
	$fdef->end_form();
}
$page->end_box();

// ── Add/Edit Form ──
if ($target !== null) {
	$form_title = $is_edit ? 'Edit Target: ' . htmlspecialchars($target->get('bkt_name')) : 'Add Target';
	$page->begin_box(['title' => $form_title]);

	$formwriter = $page->getFormWriter('target_form');
	$formwriter->begin_form();
	echo SmAdminCsrf::field();
	BackupTargetForm::render($formwriter, $target);
	$formwriter->submitbutton('btn_submit', $is_edit ? 'Save Changes' : 'Add Target');
	$formwriter->end_form();

	echo '<a href="/admin/server_manager/targets" class="btn btn-outline-secondary ms-2">Cancel</a>';
	if ($is_edit) {
		echo '<form method="post" action="/admin/server_manager/targets?bkt_backup_target_id=' . $target->key . '" id="delete_target_form" style="display:inline;">';
		echo '<input type="hidden" name="action" value="delete_target">';
		echo SmAdminCsrf::field();
		echo '<button type="button" class="btn btn-outline-danger ms-2" onclick="JoineryModal.confirm(\'Delete this target?\', function(){ document.getElementById(\'delete_target_form\').submit(); })">Delete</button>';
		echo '</form>';
	}

	$page->end_box();

	// ── Who backs up here ──
	if ($is_edit) {
		$spaces_here = StorageSpace::on_target((int)$target->key);
		$page->begin_box(['title' => 'Who backs up here']);
		if (!$spaces_here) {
			echo '<p class="text-muted mb-0">Nothing backs up to this target, and it keeps nobody\'s older backups.</p>';
		} else {
			echo '<table class="table table-sm"><thead><tr><th>Owner</th><th>Folder</th><th>Since</th></tr></thead><tbody>';
			$any_active = false;
			foreach ($spaces_here as $sp) {
				$any_active = $any_active || $sp->is_active();
				echo '<tr><td>' . htmlspecialchars($sp->owner_name()) . ' <span class="badge bg-'
					. ($sp->is_active() ? 'success">new backups go here' : 'secondary">older backups, aging out') . '</span></td>';
				echo '<td><code>' . htmlspecialchars($sp->base()) . '</code></td>';
				echo '<td class="small text-muted">' . htmlspecialchars(substr((string)($sp->is_active()
					? $sp->get('sps_opened_time') : $sp->get('sps_draining_time')), 0, 10)) . '</td></tr>';
			}
			echo '</tbody></table>';
			$off_options = [];
			foreach ($all_targets as $t) {
				if ($t->get('bkt_enabled') && (int)$t->key !== (int)$target->key) {
					$off_options[(string)(int)$t->key] = $t->get('bkt_name');
				}
			}
			if ($any_active && $off_options) {
				$fmove = $page->getFormWriter('move_everyone_off_form');
				$fmove->begin_form();
				echo SmAdminCsrf::field();
				$fmove->hiddeninput('action', '', ['value' => 'move_everyone_off']);
				$fmove->dropinput('to_target_id', 'Move everyone off this target to', [
					'options'  => $off_options,
					'helptext' => 'Each owner\'s next backup starts a full backup there. The backups already here stay, '
						. 'readable and restorable, and age out once the new target holds a verified backup.',
				]);
				$fmove->submitbutton('btn_move_everyone_off', 'Move everyone', ['class' => 'btn btn-sm btn-outline-primary']);
				$fmove->end_form();
			}
		}
		$page->end_box();
	}

	// ── Stored Backups (management-node view of the bucket) ──
	if ($is_edit) {
		$fmt_bytes = function ($b) { return BackupRunner::human((int)$b); };
		$badge_for = ['live' => 'success', 'draining' => 'secondary', 'unclaimed' => 'warning', 'orphaned' => 'warning'];
		$status_words = ['live' => 'new backups go here', 'draining' => 'older backups, aging out',
			'unclaimed' => 'unclaimed', 'orphaned' => 'unclaimed'];
		// Who an unclaimed folder can be given to: every node, and every
		// customer of backup storage.
		$adopt_owners = [];
		foreach (new MultiManagedNode(['deleted' => false], ['mgn_name' => 'ASC']) as $n) {
			$adopt_owners['node:' . (int)$n->key] = $n->get('mgn_name');
		}
		foreach (new MultiServiceTenant(['service' => ServiceTenant::SERVICE_SHELF, 'deleted' => false],
				['svt_service_tenant_id' => 'ASC']) as $row) {
			if ($row->is_node_linked()) { continue; }
			$adopt_owners['tenant:' . (int)$row->key] = 'customer ' . $row->get('svt_slug')
				. ($row->get('svt_host') ? ' (' . $row->get('svt_host') . ')' : '');
		}

		$page->begin_box(['title' => 'Stored Backups']);
		try {
			$listing = FleetBackups::list_grouped($target);
			if ($listing['total_objects'] === 0) {
				echo '<p class="text-muted">No backup objects found under '
					. htmlspecialchars(TargetBackups::base_prefix($target)) . '</p>';
			} else {
				echo '<p class="text-muted">' . $listing['total_objects'] . ' object'
					. ($listing['total_objects'] === 1 ? '' : 's') . ', ' . $fmt_bytes($listing['total_bytes'])
					. ' total, grouped by site. A decommissioned site keeps its backups here until you delete them.</p>';

				foreach ($listing['groups'] as $slug => $g) {
					$badge = $badge_for[$g['status']] ?? 'secondary';
					echo '<div class="card mb-2"><div class="card-body">';
					echo '<div class="d-flex justify-content-between align-items-start">';

					echo '<div><strong>' . htmlspecialchars($slug) . '</strong> ';
					echo '<span class="badge bg-' . $badge . '">' . htmlspecialchars($status_words[$g['status']] ?? $g['status']) . '</span>';
					if (($g['owner'] ?? '') !== '') {
						echo ' <span class="small text-muted">' . htmlspecialchars($g['owner']) . '</span>';
					}
					if (in_array($g['status'], ['live', 'draining'], true) && $g['node_id']) {
						echo ' <a class="small ms-1" href="/admin/server_manager/node_detail?mgn_managed_node_id='
							. (int)$g['node_id'] . '&tab=backups">manage on node</a>';
					}
					if (in_array($g['status'], ['unclaimed', 'orphaned'], true) && $adopt_owners) {
						$fad = $page->getFormWriter('adopt_' . md5($slug));
						$fad->begin_form();
						echo SmAdminCsrf::field();
						$fad->hiddeninput('action', '', ['value' => 'adopt_folder']);
						$fad->hiddeninput('folder', '', ['value' => $slug]);
						$fad->dropinput('owner', 'Adopt as older backups of', [
							'options' => $adopt_owners,
							'value'   => $g['node_id'] ? 'node:' . (int)$g['node_id'] : '',
						]);
						$fad->submitbutton('btn_adopt_' . md5($slug), 'Adopt', ['class' => 'btn btn-sm btn-outline-primary']);
						$fad->end_form();
					}
					echo '<div class="text-muted small">' . $g['count'] . ' object'
						. ($g['count'] === 1 ? '' : 's') . ', ' . $fmt_bytes($g['bytes']) . '</div>';

					// Per-object detail with individual delete.
					echo '<details class="mt-1"><summary class="small">Show files</summary>';
					echo '<table class="table table-sm mt-1 mb-0"><tbody>';
					foreach ($g['objects'] as $obj) {
						$fname = basename($obj['key']);
						$oid = 'delobj_' . md5($obj['key']);
						echo '<tr>';
						echo '<td class="small">' . htmlspecialchars($fname) . '</td>';
						echo '<td class="small text-muted">' . $fmt_bytes((int)$obj['size']) . '</td>';
						echo '<td class="small text-muted">' . htmlspecialchars($obj['last_modified']) . '</td>';
						echo '<td class="text-end">';
						echo '<form method="post" action="/admin/server_manager/targets?bkt_backup_target_id=' . $target->key . '" id="' . $oid . '" style="margin:0;">';
						echo '<input type="hidden" name="action" value="delete_backup_object">';
						echo '<input type="hidden" name="key" value="' . htmlspecialchars($obj['key']) . '">';
						echo SmAdminCsrf::field();
						$obj_msg = 'Delete backup file ' . $fname . '? This cannot be undone.';
						echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="JoineryModal.confirm('
							. json_encode($obj_msg) . ', function(){ document.getElementById(' . json_encode($oid) . ').submit(); })">Delete</button>';
						echo '</form>';
						echo '</td></tr>';
					}
					echo '</tbody></table></details>';
					echo '</div>'; // left column

					// Delete-all-for-this-site (whole prefix), type-to-confirm the slug.
					// Not where an owner's new backups go: its next run would extend
					// a chain whose full is gone. Move the owner first.
					if ($g['status'] !== 'live') {
						$pid = 'delpfx_' . md5($slug);
						echo '<form method="post" action="/admin/server_manager/targets?bkt_backup_target_id=' . $target->key . '" id="' . $pid . '" style="margin:0;">';
						echo '<input type="hidden" name="action" value="delete_backup_prefix">';
						echo '<input type="hidden" name="slug" value="' . htmlspecialchars($slug) . '">';
						echo SmAdminCsrf::field();
						$pfx_msg = 'Delete all ' . $g['count'] . ' backup object' . ($g['count'] === 1 ? '' : 's')
							. ' for this site? This cannot be undone.';
						echo '<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="JoineryModal.confirmTyped('
							. json_encode($pfx_msg) . ', ' . json_encode($slug) . ', function(){ document.getElementById(' . json_encode($pid) . ').submit(); })">Delete all</button>';
						echo '</form>';
					}

					echo '</div>'; // d-flex
					echo '</div></div>'; // card-body, card
				}
			}
		} catch (Exception $e) {
			echo '<div class="alert alert-warning">Could not list backups: ' . htmlspecialchars($e->getMessage()) . '</div>';
		}
		$page->end_box();
	}
}

$page->admin_footer();
?>
