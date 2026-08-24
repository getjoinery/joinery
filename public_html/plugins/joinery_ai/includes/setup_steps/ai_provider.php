<?php
/**
 * Setup wizard step: AI assistant (specs/setup_wizard.md § Step 7).
 * Own-machine first. Writes the plugin's declared settings through the wizard's
 * generic step_settings_save handler; the Test button calls
 * joinery_ai/test_connection, which probes whatever endpoint this install would
 * actually reach for.
 * Included by views/setup.php with $page, $settings, $next_key in scope.
 *
 * @version 1.3
 * @changelog 1.3 - the choice radio and provider select are FormWriter fields
 *                  driving visibility_rules, and the declared settings render
 *                  through SettingsFieldRenderer; the hand-rolled markup and
 *                  toggle script are gone
 * @changelog 1.2 - Test button extracted to the shared test_connection_button.php partial (also mounted on Plugin Settings)
 */

$setup_ai_local_url = (string)$settings->get_setting('joinery_ai_local_base_url');
$setup_ai_local_model = (string)$settings->get_setting('joinery_ai_local_model');
$setup_ai_has_anthropic = (string)$settings->get_setting('joinery_ai_anthropic_api_key') !== '';
$setup_ai_has_fireworks = (string)$settings->get_setting('joinery_ai_fireworks_api_key') !== '';

// There is no "active provider" to remember: every configured endpoint is
// available at once, and what a piece of work runs on is decided from what it
// needs. So the radio is only about which fields to show, and it opens on
// whatever this install has actually set up - own-machine first.
$setup_ai_provider = $setup_ai_has_anthropic ? 'anthropic' : 'fireworks';
$setup_ai_choice = ($setup_ai_local_model === '' && ($setup_ai_has_anthropic || $setup_ai_has_fireworks))
	? 'cloud' : 'local';
?>

<?php if (!empty($_GET['saved'])) { ?>
	<div class="jy-alert jy-alert-info">Saved. Press "Test it" to prove the assistant answers.</div>
<?php } ?>

<?php
$setup_ai_form = $page->getFormWriter('setup-ai', array('action' => '/setup', 'method' => 'POST'));
$setup_ai_form->begin_form();
$setup_ai_form->hiddeninput('action', '', array('value' => 'step_settings_save'));
$setup_ai_form->hiddeninput('step', '', array('value' => 'ai_provider'));
$setup_ai_form->hiddeninput('step_key', '', array('value' => 'ai_provider'));
?>
	<fieldset class="jy-fieldset">
<?php
// The radio and the provider select are page furniture, not settings — they
// only decide which declared fields are visible.
echo $setup_ai_form->radioinput('ai_choice', '', array(
	'options' => array(
		'local' => 'My own machine',
		'cloud' => 'Cloud provider',
	),
	'descriptions' => array(
		'local' => 'An OpenAI-compatible server you run (Ollama, LM Studio, …)',
		'cloud' => 'Bring an API key',
	),
	'value' => $setup_ai_choice,
	'visibility_rules' => array(
		'local' => array('show' => array('setup-ai-local'), 'hide' => array('setup-ai-cloud')),
		'cloud' => array('show' => array('setup-ai-cloud'), 'hide' => array('setup-ai-local')),
	),
));
?>
		<div id="setup-ai-local" style="margin-left:24px;<?php echo $setup_ai_choice === 'local' ? '' : 'display:none'; ?>"><?php // jy-allow-style: initial visibility is server-computed ?>
<?php
SettingsFieldRenderer::renderGroup($setup_ai_form, 'provider', array(
	'source' => 'joinery_ai',
	'only' => array('joinery_ai_local_base_url', 'joinery_ai_local_model'),
	'values' => array(
		'joinery_ai_local_base_url' => $setup_ai_local_url !== '' ? $setup_ai_local_url : 'http://localhost:11434/v1',
	),
));
?>
		</div>

		<div id="setup-ai-cloud" style="margin-left:24px;<?php echo $setup_ai_choice === 'cloud' ? '' : 'display:none'; ?>"><?php // jy-allow-style: initial visibility is server-computed ?>
<?php
echo $setup_ai_form->dropinput('ai_cloud_provider', 'Provider', array(
	'options' => array(
		'anthropic' => 'Anthropic (not recommended)',
		'fireworks' => 'Fireworks (they guarantee your data stays private)',
	),
	'value' => $setup_ai_provider,
	// A stored key grows a clear__ checkbox beside it; toggle both together.
	'visibility_rules' => array(
		'anthropic' => array(
			'show' => array('joinery_ai_anthropic_api_key', 'clear__joinery_ai_anthropic_api_key'),
			'hide' => array('joinery_ai_fireworks_api_key', 'clear__joinery_ai_fireworks_api_key'),
		),
		'fireworks' => array(
			'show' => array('joinery_ai_fireworks_api_key', 'clear__joinery_ai_fireworks_api_key'),
			'hide' => array('joinery_ai_anthropic_api_key', 'clear__joinery_ai_anthropic_api_key'),
		),
	),
));
SettingsFieldRenderer::renderGroup($setup_ai_form, 'keys', array(
	'source' => 'joinery_ai',
	'only' => array('joinery_ai_anthropic_api_key', 'joinery_ai_fireworks_api_key'),
));
?>
		</div>
	</fieldset>
	<div class="jy-mt-2">
		<?php echo $setup_ai_form->submitbutton('btn_ai_save', 'Save', array('class' => 'btn btn-primary')); ?>
	</div>
<?php
$setup_ai_form->end_form();
?>

	<div class="jy-mt-2">
<?php require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/test_connection_button.php')); ?>
	</div>

	<form method="POST" action="/setup" class="jy-mt-3">
		<input type="hidden" name="action" value="decline_step">
		<input type="hidden" name="step_key" value="ai_provider">
		<button type="submit" class="btn btn-secondary">Not now — everything works without it</button>
	</form>
