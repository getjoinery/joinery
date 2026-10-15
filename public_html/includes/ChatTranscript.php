<?php
/**
 * ChatTranscript — how one turn of a conversation with a model is drawn,
 * wherever one is shown: Joinery AI's chat (through its ChatRender) and the
 * Help panel's support threads. One renderer, so a turn reads the same in
 * both and there is one place a turn is drawn (support_chatbot.md, The
 * transcript).
 *
 * The markup carries the joai-chat-* classes, styled by
 * assets/css/chat_transcript.css; assets/js/chat_transcript.js draws the same
 * markup in the browser for a turn still being written. A caller adds what is
 * its own (Joinery AI's attachments, usage parts and Copy/Delete actions; the
 * Help panel's thumbs) through the options, never by editing the markup here.
 *
 * Every value is escaped here; markdown is rendered by MarkdownRenderer, which
 * escapes raw HTML. A page that shows a transcript emits assets() once; no
 * other page carries the sheet.
 *
 * @version 1.1.0
 */
class ChatTranscript {

	/**
	 * The transcript's stylesheet and, with $script, its browser module, as
	 * tags for a page that shows a transcript; each once a page, so the Help
	 * button and the page it sits on can both ask for them. No rule in a plugin's sheet
	 * depends on loading before or after this one.
	 */
	public static function assets(bool $script = true): string {
		$out = '';
		if (!self::$sheet_emitted) {
			self::$sheet_emitted = true;
			$out .= '<link rel="stylesheet" href="/assets/css/chat_transcript.css?v=' . self::mtime('assets/css/chat_transcript.css') . '">' . "\n";
		}
		if ($script && !self::$script_emitted) {
			self::$script_emitted = true;
			$out .= '<script src="/assets/js/chat_transcript.js?v=' . self::mtime('assets/js/chat_transcript.js') . '"></script>' . "\n";
		}
		return $out;
	}

	/** @var bool the sheet / the module is on this page already (one page per request) */
	private static $sheet_emitted = false;
	private static $script_emitted = false;

	private static function mtime(string $path): string {
		$file = PathHelper::getIncludePath($path);
		return is_file($file) ? (string)filemtime($file) : '1';
	}

	/**
	 * A bubble of the person's own words.
	 *
	 * @param array $opts message_id (int), attachments_html (string, already
	 *   safe), meta_parts (string[] already safe), actions_html (string)
	 */
	public static function userBubble(string $text, string $time, array $opts = array()): string {
		$raw = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		$id = (int)($opts['message_id'] ?? 0);
		$id_attr = $id ? ' data-message-id="' . $id . '"' : '';
		$body = $raw !== '' ? '<div class="joai-chat-body">' . nl2br($raw) . '</div>' : '';
		return '<div class="joai-chat-msg joai-chat-mine"' . $id_attr . ' data-raw="' . $raw . '">'
			. $body
			. (string)($opts['attachments_html'] ?? '')
			. self::footHtml('', $time, (array)($opts['meta_parts'] ?? array()), (string)($opts['actions_html'] ?? ''))
			. '</div>';
	}

	/**
	 * A reply: the model's, or a person's on a support desk.
	 *
	 * @param array $opts message_id, trace_html (safe), meta_parts (safe),
	 *   cancelled (bool), by_person (bool), actions_html (safe), after_html (safe:
	 *   drawn under the footer, e.g. the Help panel's thumbs)
	 */
	public static function assistantBubble(string $markdown, string $time, array $opts = array()): string {
		$id = (int)($opts['message_id'] ?? 0);
		$cancelled = !empty($opts['cancelled']) ? '<div class="joai-chat-cancelled-marker">Cancelled</div>' : '';
		$mark = !empty($opts['by_person']) ? '<div class="joai-chat-person-mark">From a person</div>' : '';
		return '<div class="joai-chat-msg joai-chat-assistant"' . ($id ? ' data-message-id="' . $id . '"' : '')
			. ' data-raw="' . htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8') . '">'
			. $mark
			. '<div class="joai-chat-body">' . self::renderMarkdown($markdown) . '</div>'
			. $cancelled
			. self::footHtml((string)($opts['trace_html'] ?? ''), $time, (array)($opts['meta_parts'] ?? array()), (string)($opts['actions_html'] ?? ''))
			. (string)($opts['after_html'] ?? '')
			. '</div>';
	}

	/** A turn that failed: the reason, never a blank bubble. */
	public static function failedBubble(string $error, int $message_id = 0): string {
		return '<div class="joai-chat-msg joai-chat-assistant joai-chat-failed"' . ($message_id ? ' data-message-id="' . $message_id . '"' : '') . '>'
			. '<div class="joai-chat-body">' . htmlspecialchars($error !== '' ? $error : 'The turn could not be completed.', ENT_QUOTES, 'UTF-8') . '</div>'
			. '</div>';
	}

	/** The one footer row under a bubble: an optional trace, then time · meta, then the actions. */
	public static function footHtml(string $trace, string $time, array $meta_extra, string $actions_html = ''): string {
		$meta = array_merge(
			array('<span class="joai-chat-time">' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</span>'),
			$meta_extra
		);
		return '<div class="joai-chat-foot">'
			. $trace
			. '<span class="joai-chat-meta-line">' . implode(' · ', $meta) . '</span>'
			. $actions_html
			. '</div>';
	}

	/**
	 * The collapsible tool trace: one line per call, its name and duration,
	 * red when it failed. $labels maps a tool name to the words shown for it
	 * (the Help panel names reads in the person's terms). '' with no calls.
	 */
	public static function traceHtml($tool_calls, array $labels = array()): string {
		if (is_string($tool_calls)) {
			$tool_calls = json_decode($tool_calls, true);
		}
		if (!is_array($tool_calls) || empty($tool_calls)) {
			return '';
		}
		$items = '';
		foreach ($tool_calls as $tc) {
			$raw_name = (string)($tc['name'] ?? '?');
			$name = htmlspecialchars($labels[$raw_name] ?? $raw_name, ENT_QUOTES, 'UTF-8');
			$dur = isset($tc['duration_ms']) && $tc['duration_ms'] !== null ? ' · ' . (int)$tc['duration_ms'] . 'ms' : '';
			$cls = !empty($tc['is_error']) ? ' joai-chat-tool-error' : '';
			$items .= '<li class="joai-chat-tool' . $cls . '">' . $name . $dur . '</li>';
		}
		$count = count($tool_calls);
		return '<details class="joai-chat-trace"><summary>' . $count . ' tool call'
			. ($count === 1 ? '' : 's') . '</summary><ul>' . $items . '</ul></details>';
	}

	/** Markdown → HTML with chat-style soft breaks; escaped text if the renderer is missing. */
	public static function renderMarkdown(string $md): string {
		if ($md === '') {
			return '';
		}
		try {
			return MarkdownRenderer::render($md, true);
		} catch (\Throwable $e) {
			return nl2br(htmlspecialchars($md, ENT_QUOTES, 'UTF-8'));
		}
	}
}
