<?php
/**
 * Setup wizard step: AI assistant (specs/setup_wizard.md § Step 7).
 * Three-way choice, own-machine first. Writes the plugin's declared settings
 * through the wizard's generic step_settings_save handler; the Test button
 * calls joinery_ai/test_connection against what was saved.
 * Included by views/setup.php with $page, $settings, $next_key in scope.
 *
 * @version 1.0
 */

$setup_ai_provider = (string)$settings->get_setting('joinery_ai_llm_provider') ?: 'anthropic';
$setup_ai_local_url = (string)$settings->get_setting('joinery_ai_local_base_url');
$setup_ai_local_model = (string)$settings->get_setting('joinery_ai_local_model');
$setup_ai_has_anthropic = (string)$settings->get_setting('joinery_ai_anthropic_api_key') !== '';
$setup_ai_has_fireworks = (string)$settings->get_setting('joinery_ai_fireworks_api_key') !== '';
$setup_ai_configured = $setup_ai_has_anthropic || $setup_ai_has_fireworks || $setup_ai_local_model !== '';

$setup_ai_choice = 'local';
if ($setup_ai_provider === 'anthropic' || $setup_ai_provider === 'fireworks') {
	$setup_ai_choice = 'cloud';
}
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
$setup_ai_form->hiddeninput('joinery_ai_llm_provider', '', array('value' => $setup_ai_provider, 'id' => 'setup-ai-provider-setting'));
?>
	<fieldset class="jy-fieldset">
		<label class="jy-check"><input type="radio" name="ai_choice" value="local" <?php echo $setup_ai_choice === 'local' ? 'checked' : ''; ?>> <strong>My own machine</strong> — an OpenAI-compatible server you run (Ollama, LM Studio, …)</label>
		<div id="setup-ai-local" class="d-none" style="margin-left:24px">
<?php
echo $setup_ai_form->textinput('joinery_ai_local_base_url', 'Server URL', array(
	'value' => $setup_ai_local_url !== '' ? $setup_ai_local_url : 'http://localhost:11434/v1',
));
echo $setup_ai_form->textinput('joinery_ai_local_model', 'Model id', array(
	'value' => $setup_ai_local_model,
	'helptext' => 'As your server names it, e.g. llama3.1:8b. Comma-separate to offer several; the first is the default.',
));
?>
		</div>

		<label class="jy-check"><input type="radio" name="ai_choice" value="cloud" <?php echo $setup_ai_choice === 'cloud' ? 'checked' : ''; ?>> <strong>Cloud provider</strong> — bring an API key</label>
		<div id="setup-ai-cloud" class="d-none" style="margin-left:24px">
			<label for="setup-ai-cloud-which">Provider</label>
			<select id="setup-ai-cloud-which" class="jy-w-full">
				<option value="anthropic" <?php echo $setup_ai_provider === 'anthropic' ? 'selected' : ''; ?>>Anthropic</option>
				<option value="fireworks" <?php echo $setup_ai_provider === 'fireworks' ? 'selected' : ''; ?>>Fireworks (no-train, private)</option>
			</select>
			<div data-ai-cloud="anthropic">
<?php echo $setup_ai_form->passwordinput('joinery_ai_anthropic_api_key', 'Anthropic API key', array(
	'autocomplete' => 'off',
	'helptext' => $setup_ai_has_anthropic ? 'A key is saved — leave blank to keep it.' : '',
)); ?>
			</div>
			<div data-ai-cloud="fireworks">
<?php echo $setup_ai_form->passwordinput('joinery_ai_fireworks_api_key', 'Fireworks API key', array(
	'autocomplete' => 'off',
	'helptext' => $setup_ai_has_fireworks ? 'A key is saved — leave blank to keep it.' : '',
)); ?>
			</div>
		</div>
	</fieldset>
	<div class="jy-mt-2">
		<?php echo $setup_ai_form->submitbutton('btn_ai_save', 'Save', array('class' => 'btn btn-primary')); ?>
	</div>
<?php
$setup_ai_form->end_form();
?>

	<div class="jy-mt-2">
<?php if ($setup_ai_configured) { ?>
		<button type="button" class="btn btn-secondary" id="setup-ai-test">Test it</button>
		<span class="jy-muted" id="setup-ai-test-result"></span>
<?php } ?>
	</div>

	<form method="POST" action="/setup" class="jy-mt-3">
		<input type="hidden" name="action" value="decline_step">
		<input type="hidden" name="step_key" value="ai_provider">
		<button type="submit" class="btn btn-secondary">Not now — everything works without it</button>
	</form>

	<script>
	(function () {
		var providerSetting = document.getElementById('setup-ai-provider-setting');
		var cloudWhich = document.getElementById('setup-ai-cloud-which');
		function sync() {
			var choice = document.querySelector('input[name="ai_choice"]:checked');
			var isLocal = choice && choice.value === 'local';
			document.getElementById('setup-ai-local').classList.toggle('d-none', !isLocal);
			document.getElementById('setup-ai-cloud').classList.toggle('d-none', isLocal);
			providerSetting.value = isLocal ? 'local' : cloudWhich.value;
			document.querySelectorAll('[data-ai-cloud]').forEach(function (div) {
				div.classList.toggle('d-none', isLocal || div.getAttribute('data-ai-cloud') !== cloudWhich.value);
			});
		}
		document.querySelectorAll('input[name="ai_choice"]').forEach(function (r) { r.addEventListener('change', sync); });
		cloudWhich.addEventListener('change', sync);
		sync();

		var test = document.getElementById('setup-ai-test');
		if (test) {
			test.addEventListener('click', function () {
				var out = document.getElementById('setup-ai-test-result');
				out.textContent = 'Testing…';
				test.disabled = true;
				joineryApi.post('joinery_ai/test_connection', {}).then(function (data) {
					out.textContent = 'It answers — ' + data.model + ' replied in ' + data.ms + ' ms.';
					test.disabled = false;
				}).catch(function (e) {
					out.textContent = e.message || 'The test failed.';
					test.disabled = false;
				});
			});
		}
	})();
	</script>
