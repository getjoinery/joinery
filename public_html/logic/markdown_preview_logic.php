<?php
/**
 * API action: markdown_preview — render markdown to HTML for a live preview.
 *
 * POST /api/v1/action/markdown_preview (browser session). Params:
 *   markdown     string  the source to render (required, may be empty)
 *   soft_breaks  bool    treat single newlines as <br> (default false)
 *   restricted   bool    render through the restricted profile, the one text
 *                        members write for each other is shown with (default false)
 *
 * Exists so the markdown editor's preview comes from the SAME renderer that
 * will produce the page — a JavaScript renderer would be a second grammar to
 * keep in step with MarkdownRenderer, and the preview would quietly stop
 * matching what readers see.
 *
 * MarkdownRenderer escapes the whole document before it parses, so no markup
 * a caller sends survives into the response as live HTML.
 *
 * Rate-limited per member (MARKDOWN_PREVIEW_RATE per MARKDOWN_PREVIEW_WINDOW
 * seconds): the editor asks at most once per pause in typing, so the limit only
 * meets a script.
 *
 * Members preview up to MARKDOWN_PREVIEW_MEMBER_MAX_BYTES (a forum post is at
 * most 64 KB); admins, who edit the platform docs, up to MARKDOWN_PREVIEW_MAX_BYTES.
 *
 * @version 1.3.0 - members capped at 128 KB; the throttle answers with Retry-After
 * @version 1.2.0 - per-member rate limit
 * @version 1.1.0 - restricted: preview through the restricted profile
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

/** Longest source this will render. Comfortably past the largest platform doc. */
define('MARKDOWN_PREVIEW_MAX_BYTES', 512 * 1024);
define('MARKDOWN_PREVIEW_MEMBER_MAX_BYTES', 128 * 1024);
define('MARKDOWN_PREVIEW_RATE', 120);
define('MARKDOWN_PREVIEW_WINDOW', 60);

function markdown_preview_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$user_id = (int)$session->get_user_id();
	$state = RequestLogger::rate_limit_state('markdown_preview', MARKDOWN_PREVIEW_RATE, MARKDOWN_PREVIEW_WINDOW, null, $user_id);
	if (!$state['allowed']) {
		if (function_exists('api_rate_limited')) {
			api_rate_limited($state, 'You', MARKDOWN_PREVIEW_RATE, MARKDOWN_PREVIEW_WINDOW, 'previews');
		}
		return LogicResult::error('Previewing too often. Try again in ' . $state['retry_after'] . ' seconds.');
	}
	RequestLogger::log('markdown_preview', 'preview', true, array('user_id' => $user_id));

	$markdown = (string)($input['markdown'] ?? '');

	$max = Access::permits(Access::staffLevel(), $session) ? MARKDOWN_PREVIEW_MAX_BYTES : MARKDOWN_PREVIEW_MEMBER_MAX_BYTES;
	if (strlen($markdown) > $max) {
		return LogicResult::error('That document is too large to preview.');
	}

	$soft_breaks = !empty($input['soft_breaks']);
	$restricted = !empty($input['restricted']);

	return LogicResult::render(array(
		'html' => MarkdownRenderer::render($markdown, $soft_breaks, $restricted),
	));
}

function markdown_preview_logic_descriptor(): array {
	return [
		'description'      => 'Render markdown to HTML for an editor preview.',
		'mutates'          => false,
		'requires_session' => true,
		'input'            => [
			'markdown'    => ['type' => 'string', 'required' => false, 'label' => 'Markdown source'],
			'soft_breaks' => ['type' => 'bool',   'required' => false, 'label' => 'Treat single newlines as line breaks'],
			'restricted'  => ['type' => 'bool',   'required' => false, 'label' => 'Preview as text members write for each other'],
		],
		'auth'             => ['capability' => 'write', 'min_user_permission' => 0],
	];
}
?>
