<?php
/**
 * Setup wizard step: Welcome (specs/setup_wizard.md § Step 0).
 * Included by views/setup.php with $page, $viewer, $permission, $settings in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/address_class.php'));

$setup_tz_current = (string)$viewer->get('usr_timezone');
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
	echo $formwriter->textinput('site_name', 'Site name', array(
		'value' => (string)$settings->get_setting('site_name'),
		'helptext' => 'Shown in page titles, emails, and the header.',
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
