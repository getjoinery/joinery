<?php
/**
 * Setup wizard step: Welcome (specs/setup_wizard.md § Step 0).
 * Included by views/setup.php with $page, $viewer, $permission, $settings in scope.
 *
 * @version 1.3
 * @changelog 1.3 - The site name is the declared field (SettingsFieldRenderer),
 *   not a hand-drawn textinput: FormWriter refuses a page's own copy of a
 *   declared setting, which on a debug box stopped the Welcome step outright.
 * @changelog 1.2 - Shows what the installer did with the services it was handed
 *   (InstallReport): the StackScript path never shows its closing summary,
 *   so this is the first place the owner can see it.
 * @changelog 1.1 - an empty site name is prefilled with the site's own domain
 */
require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));

$setup_tz_current = (string)$viewer->get('usr_timezone');
$setup_install_outcomes = ($permission >= 10) ? InstallReport::outcomes() : array();
if ($setup_install_outcomes) {
?>
<div class="jy-fieldset">
	<h4>What the install already did</h4>
	<p class="jy-muted">You handed the deploy form some keys. This is what came of them — the steps ahead pick up from here.</p>
	<ul class="setup-checklist">
<?php foreach ($setup_install_outcomes as $setup_install_row) { ?>
		<li>
			<span class="setup-dot <?php echo InstallReport::dot($setup_install_row['outcome']); ?>"></span>
			<span><strong><?php echo htmlspecialchars($setup_install_row['label']); ?></strong></span>
			<span class="jy-muted"><?php echo htmlspecialchars($setup_install_row['text']); ?></span>
		</li>
<?php } ?>
	</ul>
</div>
<?php
}
$formwriter = $page->getFormWriter('setup-welcome', array('action' => '/setup', 'method' => 'POST'));
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'welcome_save'));
$formwriter->hiddeninput('step', '', array('value' => 'welcome'));

echo $formwriter->textinput('usr_first_name', 'First name', array(
	'required' => true,
	'value' => (string)$viewer->get('usr_first_name'),
));
echo $formwriter->textinput('usr_last_name', 'Last name', array(
	'value' => (string)$viewer->get('usr_last_name'),
));
echo $formwriter->dropinput('usr_timezone', 'Your timezone', array(
	'options' => Address::get_timezone_drop_array(),
	'value' => $setup_tz_current,
));
if ($permission >= 10) {
	// A blank site name is the field people stall on. The domain the site
	// already answers to is a name they recognize, and it saves on submit.
	$setup_site_name = trim((string)$settings->get_setting('site_name'));
	if ($setup_site_name === '') {
		$setup_web = trim((string)$settings->get_setting('webDir'), " /");
		$setup_host = parse_url('https://' . $setup_web, PHP_URL_HOST);
		if (is_string($setup_host) && $setup_host !== '' && $setup_host !== 'localhost' && !filter_var($setup_host, FILTER_VALIDATE_IP)) {
			$setup_site_name = preg_replace('/^www\./i', '', strtolower($setup_host));
		}
	}
	// The declared field, not a hand-drawn copy (FormWriter refuses one).
	SettingsFieldRenderer::renderGroup($formwriter, 'site_identity', array(
		'source' => 'core',
		'only' => array('site_name'),
		'values' => array('site_name' => $setup_site_name),
		'field_options' => array('site_name' => array(
			'helptext_append' => 'Your organization, club, or business name — you can change it any time.',
		)),
	));
}
?>
<div class="jy-mt-2">
	<?php echo $formwriter->submitbutton('btn_welcome', 'Save and continue', array('class' => 'btn btn-primary')); ?>
</div>
<?php
$formwriter->end_form();
?>
<script>
// Prefill the timezone from the browser when the account still carries the
// factory default — a wrong timezone silently skews every reminder and
// summary send hour.
(function () {
	var select = document.getElementById('usr_timezone');
	if (!select) { return; }
	var current = <?php echo json_encode($setup_tz_current); ?>;
	var untouched = (current === '' || current === 'America/New_York');
	if (!untouched) { return; }
	try {
		var detected = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		if (!detected) { return; }
		for (var i = 0; i < select.options.length; i++) {
			if (select.options[i].value === detected) {
				select.value = detected;
				break;
			}
		}
	} catch (e) { /* keep the current value */ }
})();
</script>
