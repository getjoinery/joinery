<?php
/**
 * Setup wizard step: Sending email (specs/setup_wizard.md § Step 3).
 * The same declared settings the email settings page renders — one renderer,
 * no parallel form — plus a test send that records its last success.
 *
 * The step wears two faces. With no working provider, the settings form is
 * the work and leads. With a provider already sending, the only thing owed
 * is the delivery proof, so that leads and the form folds away behind a
 * "change" disclosure — nobody re-reads credential fields that are done.
 * Included by views/setup.php with $page, $viewer, $settings, $site_name in scope.
 *
 * @version 1.1
 */
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

$setup_send_blocker = EmailSender::transactionalSendBlocker();
$setup_send_last = (string)$settings->get_setting('email_test_send_last_success');
$setup_send_service = EmailSender::activeServiceKey();
$setup_send_ready = ($setup_send_blocker === null && EmailSender::detectServiceType() !== 'none');
$setup_send_service_label = EmailSender::getAvailableServices()[$setup_send_service] ?? $setup_send_service;

// ---- The proof: send a test, confirm it arrived ----
if ($setup_send_ready) {
?>
	<div class="jy-fieldset">
		<h4>Prove it works</h4>
<?php if (!empty($_GET['sent'])) { ?>
		<div class="jy-alert jy-alert-info">Test sent — check your inbox at <?php echo htmlspecialchars((string)$viewer->get('usr_email')); ?>. If it arrives, press "It arrived".</div>
<?php } ?>
		<div class="setup-choice">
			<form method="POST" action="/setup">
				<input type="hidden" name="action" value="mail_send_test">
				<input type="hidden" name="step" value="mail_send">
				<button type="submit" class="btn <?php echo empty($_GET['sent']) ? 'btn-primary' : 'btn-secondary'; ?>">Send me a test at <?php echo htmlspecialchars((string)$viewer->get('usr_email')); ?></button>
			</form>
<?php if (!empty($_GET['sent'])) { ?>
			<form method="POST" action="/setup">
				<input type="hidden" name="action" value="mail_send_confirm">
				<input type="hidden" name="step" value="mail_send">
				<button type="submit" class="btn btn-primary">It arrived</button>
			</form>
<?php } ?>
		</div>
<?php if ($setup_send_last !== '') { ?>
		<p class="jy-muted jy-mt-2">Last successful test: <?php echo htmlspecialchars(LibraryFunctions::convert_time($setup_send_last, 'UTC', SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A T')); ?></p>
<?php } ?>
	</div>
<?php
} elseif ($setup_send_blocker !== null && $setup_send_service !== '') {
?>
	<div class="jy-alert jy-alert-error"><?php echo htmlspecialchars($setup_send_blocker); ?></div>
<?php
}

// ---- The settings: the work when unconfigured, folded away when done ----
if ($setup_send_ready) {
	echo '<details class="jy-mt-3"><summary>Change email settings — currently '
		. htmlspecialchars($setup_send_service_label)
		. ', sending as ' . htmlspecialchars((string)$settings->get_setting('defaultemail'))
		. '</summary><div class="jy-mt-2">';
}

$formwriter = $page->getFormWriter('setup-mail-send', array('action' => '/setup', 'method' => 'POST'));
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'mail_send_save'));
$formwriter->hiddeninput('step', '', array('value' => 'mail_send'));

SettingsFieldRenderer::renderGroup($formwriter, 'email_delivery', array(
	'source' => 'core',
	'only' => array('email_service'),
));

foreach (EmailSender::getDiscoveredProviders() as $setup_provider_key => $setup_provider_class) {
	$setup_group = 'email_provider_' . $setup_provider_key;
	if (!SettingsFieldRenderer::namesFor($setup_group, 'core')) {
		continue;
	}
	echo '<div class="setup-provider-fields d-none" data-email-provider="' . htmlspecialchars($setup_provider_key) . '">';
	SettingsFieldRenderer::renderGroup($formwriter, $setup_group, array('source' => 'core'));
	echo '</div>';
}

SettingsFieldRenderer::renderGroup($formwriter, 'email_identity', array(
	'source' => 'core',
	'only' => array('defaultemail', 'defaultemailname'),
));
?>
<div class="jy-mt-2">
	<?php echo $formwriter->submitbutton('btn_mail_send_save', 'Save', array('class' => 'btn btn-primary')); ?>
</div>
<?php
$formwriter->end_form();

if ($setup_send_ready) {
	echo '</div></details>';
}
?>

<script>
// Only the chosen provider's credential fields are on screen.
(function () {
	var select = document.getElementById('email_service');
	if (!select) { return; }
	function toggle() {
		document.querySelectorAll('.setup-provider-fields').forEach(function (div) {
			div.classList.toggle('d-none', div.getAttribute('data-email-provider') !== select.value);
		});
	}
	select.addEventListener('change', toggle);
	toggle();
})();
</script>
