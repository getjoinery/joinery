<?php
/**
 * "Test it" button for the AI assistant — proves the endpoint this install
 * would actually reach for answers a one-token message, via the
 * joinery_ai/test_connection API action. Shared by the setup wizard's AI step
 * and the Plugin Settings page fragment (settings_actions.php).
 *
 * The action is owner-only and reads only saved settings, so the button
 * renders only at permission 10 and only once some endpoint is configured —
 * before that there is nothing to test.
 *
 * @version 1.1 - the button's script is assets/js/test-connection.js (no inline script)
 * @version 1.0
 */

$ai_test_settings = Globalvars::get_instance();
$ai_test_configured = (string)$ai_test_settings->get_setting('joinery_ai_anthropic_api_key') !== ''
	|| (string)$ai_test_settings->get_setting('joinery_ai_fireworks_api_key') !== ''
	|| (string)$ai_test_settings->get_setting('joinery_ai_local_model') !== '';

if ($ai_test_configured && Access::permits(10)) { ?>
	<button type="button" class="btn btn-secondary" id="joinery-ai-test">Test it</button>
	<span class="jy-muted" id="joinery-ai-test-result"></span>
	<?php echo PageScript::src('plugins/joinery_ai/assets/js/test-connection.js'); ?>
<?php } ?>
