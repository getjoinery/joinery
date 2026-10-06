<?php
/**
 * API action: mailbox/device_ai_test_prompt — what the Test button in Email settings
 * sends to the person's own model (specs/fortress_mail_device_ai.md § R7).
 *
 * POST /api/v1/action/mailbox/device_ai_test_prompt (browser session or app session key). Params:
 * mailbox (address, optional). Returns {system, user, max_tokens, source}:
 * the security scan's system prompt as a server run renders it
 * (PipelineRunner::systemText) and a sample digest wrapped as untrusted input
 * under the same nonce, so Test measures the real size of a judgement — the
 * scan's prompt is the longest a device runs. The prompt is the caller's own
 * scan recipe bound to that mailbox when there is one, the scan's default
 * otherwise; `source` says which.
 *
 * The sample is made up here, a shop's order notice with a full-length body
 * and a list of tracked links. No real mail is ever in it.
 *
 * @version 1.2 - reachable with an app session key too (requires_person_credential,
 * specs/fortress_mobile_apps.md § R8)
 * @version 1.1 - reasoning_effort 'none': Test checks reachability, the key, the model and the
 *   context, and its 1024-token budget cannot hold a model's reasoning as well
 * @version 1.0
 */

function device_ai_test_prompt_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::error('Sign in required.');
	}
	if (!PluginHelper::isPluginActive('joinery_ai')) {
		return LogicResult::error('AI is not turned on for this site.');
	}
	// Pipeline jobs live outside the plugin's includes/ and data/, so they do
	// not resolve by name.
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/pipeline_jobs/EmailSecurityScanJob.php'));

	$job = new EmailSecurityScanJob();
	$recipe = device_ai_test_prompt_own_recipe($user_id, $job->id(), strtolower(trim((string)($input['mailbox'] ?? ''))));

	$nonce = bin2hex(random_bytes(4));
	$parts = PipelineRunner::systemText($recipe, $job, RecipeRunContext::resolveTimezone($user_id), $nonce);
	$system = $parts['untrusted'] !== '' ? $parts['text'] . "\n\n" . $parts['untrusted'] : $parts['text'];

	return LogicResult::render(array(
		'system'     => $system,
		'user'       => UntrustedEnvelope::wrapBlock(device_ai_test_prompt_sample_digest(), $nonce),
		'max_tokens' => 1024,
		// Test asks whether the model can be reached and answers at all; a model
		// that reasons would spend this budget thinking and answer with nothing.
		'reasoning_effort' => 'none',
		'source'     => $recipe ? 'your security scan' : 'the security scan\'s standard instructions',
	));
}

/** The caller's own scan recipe that lists $address, or null. */
function device_ai_test_prompt_own_recipe(int $user_id, string $job_id, string $address): ?Recipe {
	if ($address === '') {
		return null;
	}
	$recipes = new MultiRecipe(array('rcp_owner_user_id' => $user_id, 'rcp_pipeline_job' => $job_id, 'deleted' => false));
	foreach ($recipes as $recipe) {
		if (in_array($address, MailboxAliasConfig::listedAddresses(Recipe::decodeSourceConfig($recipe)), true)) {
			return $recipe;
		}
	}
	return null;
}

/**
 * A made-up order notice in EmailSecurityDigest's layout: headers,
 * authentication, twenty tracked links and a body at the digest's 4096-char
 * cap. Deterministic, so every Test sends the same size.
 */
function device_ai_test_prompt_sample_digest(): string {
	$sentences = array(
		'Thank you for your order, which is being prepared for shipping.',
		'You can review the items, the delivery address and the payment method at any time from your account.',
		'Most orders leave our warehouse within two business days and arrive three to five days later.',
		'If anything looks wrong, reply to this message and our team will help you.',
		'Returns are accepted within thirty days of delivery for items in their original condition.',
		'We will send another message with a tracking number as soon as the parcel is on its way.',
	);
	$body = '';
	for ($i = 0; strlen($body) < 4096; $i++) {
		$body .= $sentences[$i % count($sentences)] . ' ';
	}
	$body = substr($body, 0, 4096);
	$urls = '';
	for ($i = 1; $i <= 20; $i++) {
		$urls .= "- https://click.example-shop.test/t/{$i}/" . substr(hash('sha256', 'sample' . $i), 0, 24)
			. "?u=https%3A%2F%2Fshop.example-shop.test%2Fitem%2F{$i}  [text: View item {$i}]\n";
	}
	return "=== EMAIL DIGEST ===\n"
		. "From: Example Shop <orders@example-shop.test>\n"
		. "Reply-To: (none)\n"
		. "Return-Path: bounce@mail.example-shop.test\n"
		. "To: you@example.test\n"
		. "Date: Thu, 24 Sep 2026 10:00:00 -0400\n"
		. "Subject: Your order is being prepared\n\n"
		. "AUTHENTICATION: spf=pass dkim=pass dmarc=pass\n\n"
		. "URLS FOUND (20):\n" . $urls . "\n"
		. "BODY:\n" . $body . "\n";
}

function device_ai_test_prompt_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		'description' => 'The system prompt and a made-up sample digest the AI panel\'s Test button sends to the caller\'s own model',
		'input' => [
			'mailbox' => ['type' => 'string', 'required' => false, 'label' => 'Mailbox address whose scan recipe to use'],
		],
	);
}
?>
