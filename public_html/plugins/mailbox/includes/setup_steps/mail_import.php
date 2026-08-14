<?php
/**
 * Setup wizard step: Bring your old mail (specs/setup_wizard.md § Step 5).
 * The shared import panel's third mount, alongside /profile/mailbox/import
 * and the admin mount — same page logic, same panel, same API actions.
 * Included by views/setup.php with $page, $viewer, $permission, $next_key in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_import_page_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mail_import_panel.php'));

$setup_import_result = mailbox_import_page_logic(array('_return' => '/setup?step=mail_import'));
$setup_import_vars = $setup_import_result->data;
?>

<?php if (empty($setup_import_vars['import_enabled'])) { ?>
	<p class="jy-muted">Mail archive import is switched off on this site.</p>
<?php } else { ?>
	<?php mailbox_render_import_panel($page, $setup_import_vars); ?>

<?php if ($permission >= 10) { ?>
	<p class="jy-muted jy-mt-2">Prefer to pull from a live account instead? <a href="/plugins/mailbox/admin/admin_mailbox_imap">Connect an IMAP account</a>.</p>
<?php } ?>

	<form method="POST" action="/setup" class="jy-mt-3">
		<input type="hidden" name="action" value="decline_step">
		<input type="hidden" name="step_key" value="mail_import">
		<button type="submit" class="btn btn-secondary">I don't have old mail to bring — not now</button>
	</form>
<?php } ?>
