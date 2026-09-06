<?php
/**
 * API action: markdown_preview — render markdown to HTML for a live preview.
 *
 * POST /api/v1/action/markdown_preview (browser session). Params:
 *   markdown     string  the source to render (required, may be empty)
 *   soft_breaks  bool    treat single newlines as <br> (default false)
 *
 * Exists so the markdown editor's preview comes from the SAME renderer that
 * will produce the page — a JavaScript renderer would be a second grammar to
 * keep in step with MarkdownRenderer, and the preview would quietly stop
 * matching what readers see.
 *
 * MarkdownRenderer escapes the whole document before it parses, so no markup
 * a caller sends survives into the response as live HTML.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

/** Longest source this will render. Comfortably past the largest platform doc. */
define('MARKDOWN_PREVIEW_MAX_BYTES', 512 * 1024);

function markdown_preview_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$markdown = (string)($input['markdown'] ?? '');

	if (strlen($markdown) > MARKDOWN_PREVIEW_MAX_BYTES) {
		return LogicResult::error('That document is too large to preview.');
	}

	$soft_breaks = !empty($input['soft_breaks']);

	return LogicResult::render(array(
		'html' => MarkdownRenderer::render($markdown, $soft_breaks),
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
		],
	];
}
?>
