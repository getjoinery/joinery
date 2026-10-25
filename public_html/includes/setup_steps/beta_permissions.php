<?php
/**
 * Setup wizard step: Beta permissions (specs/implemented/beta_break_glass_root_access.md 4.2).
 * Included by views/setup.php with $page, $viewer, $permission, $settings in scope.
 *
 * Emergency root access is offered only where the install said the management
 * node keeps this machine's root password (beta_root_access_offered). Once
 * off it shows as off, with no checkbox: it cannot be turned back on. Log
 * access is the existing agent_log_access switch, also on the Management Node
 * page.
 *
 * @version 1.1 - the line saying nothing reads the logs yet shows only on a site not yet connected (reviewer2 B1)
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/BetaPermissions.php'));

$beta_offered = BetaPermissions::root_access_offered();
$beta_root_on = BetaPermissions::root_access_on();

$formwriter = $page->getFormWriter('setup-beta-permissions', array('action' => '/setup', 'method' => 'POST'));
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'beta_permissions_save'));
$formwriter->hiddeninput('step', '', array('value' => 'beta_permissions'));

if ($beta_offered) {
	if ($beta_root_on) {
		echo $formwriter->checkboxinput('keep_root_access', 'Emergency root access', array(
			'checked' => true,
			'helptext' => "If an update breaks your server badly enough that our normal tools can't reach it, we can log in "
				. 'as root to fix it. The password is locked so only our operator can read it, and every time it is read '
				. 'is recorded. Unchecking this has it removed and cannot be undone: we cannot turn it back on for you. '
				. 'It is removed for everyone when the beta ends.',
		));
	} else {
		?>
<div class="jy-fieldset">
	<h4>Emergency root access: off</h4>
	<p class="jy-muted">You turned this off. It cannot be turned back on.</p>
</div>
		<?php
	}
} elseif (!BetaPermissions::connected()) {
	?>
<p class="jy-muted">This permission matters once this site is connected to a management node, for example to get support. Until then nothing reads your logs.</p>
	<?php
}

echo $formwriter->checkboxinput('allow_log_access', 'Log access', array(
	'checked' => BetaPermissions::log_access_on(),
	'helptext' => "Lets us read your server's error and activity logs when diagnosing a problem. Passwords, keys, member "
		. 'addresses and form contents are never sent. You can change this any time on the Management Node page.',
));
?>
<div class="jy-mt-2">
	<?php echo $formwriter->submitbutton('btn_beta_permissions', 'Save and continue', array('class' => 'btn btn-primary')); ?>
</div>
<?php
$formwriter->end_form();
