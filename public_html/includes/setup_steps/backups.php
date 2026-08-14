<?php
/**
 * Setup wizard step: Backups (specs/setup_wizard.md § Step 8).
 * The backups page's own pieces on one screen: target form (save_target /
 * test_target), the RecoveryKeySetupPanel embedded whole, nightly task
 * activation, and Run one now. All POSTs go to /setup and are forwarded to
 * admin_backups_logic. Included by views/setup.php with $page, $settings in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

$setup_bk_targets = new MultiBackupTarget(array('deleted' => false), array('bkt_name' => 'ASC'));
$setup_bk_targets->load();
$setup_bk_target = null;
foreach ($setup_bk_targets as $setup_bk_row) {
	if ($setup_bk_target === null) {
		$setup_bk_target = $setup_bk_row;
	}
}
$setup_bk_recovery = BackupRecoveryKey::setup_state();
$setup_bk_tasks = new MultiScheduledTask(array('task_class' => 'BackupRun', 'active' => true, 'deleted' => false));
$setup_bk_task_active = $setup_bk_tasks->count_all() > 0;
$setup_bk_ready = ($setup_bk_target !== null)
	&& (int)$settings->get_setting('backup_target_id') > 0
	&& $setup_bk_recovery['is_ready'];
?>

	<div class="jy-fieldset">
		<h4>1 &middot; Where backups go</h4>
<?php if ($setup_bk_target !== null) { ?>
		<p>
			<span class="badge badge-success">Set</span>
			<?php echo htmlspecialchars($setup_bk_target->get('bkt_name')); ?>
			(<?php echo htmlspecialchars(strtoupper((string)$setup_bk_target->get('bkt_provider'))); ?>,
			bucket <code><?php echo htmlspecialchars((string)$setup_bk_target->get('bkt_bucket')); ?></code>)
		</p>
		<form method="POST" action="/setup">
			<input type="hidden" name="action" value="test_target">
			<input type="hidden" name="step" value="backups">
			<input type="hidden" name="bkt_id" value="<?php echo (int)$setup_bk_target->key; ?>">
			<button type="submit" class="btn btn-secondary">Test the connection</button>
		</form>
<?php } else {
	$setup_bk_form = $page->getFormWriter('setup-backup-target', array('action' => '/setup', 'method' => 'POST'));
	$setup_bk_form->begin_form();
	$setup_bk_form->hiddeninput('action', '', array('value' => 'save_target'));
	$setup_bk_form->hiddeninput('step', '', array('value' => 'backups'));
	$setup_bk_form->hiddeninput('bkt_id', '', array('value' => ''));
	$setup_bk_form->hiddeninput('bkt_name', '', array('value' => 'Backups'));
	echo $setup_bk_form->dropinput('bkt_provider', 'Provider', array(
		'options' => array('b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'),
		'value' => 'b2',
	));
	echo $setup_bk_form->textinput('bkt_bucket', 'Bucket name', array('required' => true));
	echo $setup_bk_form->textinput('access_key', 'Access key ID', array('required' => true, 'autocomplete' => 'off'));
	echo $setup_bk_form->passwordinput('secret_key', 'Secret key', array('required' => true, 'autocomplete' => 'new-password'));
	echo $setup_bk_form->textinput('region', 'Region', array('helptext' => 'Leave blank for Backblaze B2.'));
	echo $setup_bk_form->textinput('endpoint', 'Endpoint hostname', array('helptext' => 'Leave blank for Backblaze B2 — it is detected when the target is saved.'));
	echo $setup_bk_form->submitbutton('btn_save_target', 'Save and test', array('class' => 'btn btn-primary'));
	$setup_bk_form->end_form();
} ?>
	</div>

	<div class="jy-fieldset jy-mt-3">
		<h4>2 &middot; The recovery key</h4>
<?php
require_once(PathHelper::getIncludePath('includes/RecoveryKeySetupPanel.php'));
RecoveryKeySetupPanel::render($page, array('state' => $setup_bk_recovery));
?>
	</div>

	<div class="jy-fieldset jy-mt-3">
		<h4>3 &middot; Run it nightly</h4>
<?php if ($setup_bk_task_active) { ?>
		<p><span class="badge badge-success">On</span> The Backup task runs nightly.</p>
<?php if ($setup_bk_ready) { ?>
		<form method="POST" action="/setup">
			<input type="hidden" name="action" value="run_backup">
			<input type="hidden" name="step" value="backups">
			<button type="submit" class="btn btn-secondary">Run one now</button>
		</form>
<?php } ?>
<?php } else { ?>
		<form method="POST" action="/setup">
			<input type="hidden" name="action" value="backup_task_activate">
			<input type="hidden" name="step" value="backups">
			<button type="submit" class="btn btn-primary">Back up nightly</button>
		</form>
<?php } ?>
	</div>
