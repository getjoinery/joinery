<?php
/**
 * Setup wizard step: Backups (specs/setup_wizard.md § Step 8).
 * A signup-and-costs list of the three providers (shown until a target
 * exists), then two sections: the target form (save_target / test_target)
 * and the RecoveryKeySetupPanel embedded whole. Nightly activation has no section —
 * it happens by itself when the target and the proven key both exist
 * (BackupNightly::maybe_activate), which the step's intro copy promises. All
 * POSTs go to /setup and are forwarded to admin_backups_logic. Included by
 * views/setup.php with $page, $settings in scope.
 *
 * @version 2.2
 */
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
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
?>

<?php if ($setup_bk_target === null) { ?>
	<p class="mb-1">You need a bucket at one of these services — all three work the same here:</p>
	<ul class="small">
		<li><a href="https://www.backblaze.com/sign-up/cloud-storage" target="_blank" rel="noopener">Backblaze B2</a>
			(recommended) — the first 10&nbsp;GB are free, then about $0.006 per GB per month.</li>
		<li><a href="https://portal.aws.amazon.com/billing/signup" target="_blank" rel="noopener">Amazon S3</a>
			— about $0.023 per GB per month, with 5&nbsp;GB free for the first year.</li>
		<li><a href="https://login.linode.com/signup" target="_blank" rel="noopener">Linode Object Storage</a>
			— a flat $5 per month for the first 250&nbsp;GB, then $0.02 per GB.</li>
	</ul>
<?php } ?>

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
		// B2 needs neither: its endpoint is detected at save time.
		'visibility_rules' => array(
			'b2'      => array('show' => array(), 'hide' => array('region', 'endpoint')),
			'default' => array('show' => array('region', 'endpoint'), 'hide' => array()),
		),
	));
	echo $setup_bk_form->textinput('bkt_bucket', 'Bucket name', array('required' => true));
	echo $setup_bk_form->textinput('access_key', 'Access key ID', array('required' => true, 'autocomplete' => 'off'));
	echo $setup_bk_form->passwordinput('secret_key', 'Secret key', array('required' => true, 'autocomplete' => 'new-password'));
	echo $setup_bk_form->textinput('region', 'Region', array('helptext' => 'e.g. us-east-1'));
	echo $setup_bk_form->textinput('endpoint', 'Endpoint hostname', array('helptext' => 'e.g. s3.us-east-1.amazonaws.com or us-east-1.linodeobjects.com'));
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

