<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

/**
 * Assembles a chat thread into a portable text document, in two flavors:
 *
 *  - **Markdown** — role-labeled turns with the stored source markdown intact,
 *    for pasting where Markdown renders (docs, issues, another chat).
 *  - **Plain text** — the same, but each turn's body is rendered down to clean
 *    plain text for social media (which doesn't render Markdown): emphasis
 *    stripped to the words, headings to plain lines, code fences/backticks
 *    removed, list markers kept as simple bullets/numbers, and `[text](url)`
 *    flattened to `text (url)` so links survive as bare tappable URLs.
 *
 * Pure transformation over already-stored `aim_content`; never calls a provider.
 */
class ChatExport {

    /** Build both export flavors for a loaded conversation. Returns
     *  ['title' => string, 'markdown' => string, 'text' => string]. */
    public static function assemble(AiConversation $conversation, array $messages): array {
        $title = trim((string)$conversation->get('aic_title'));
        if ($title === '') $title = 'Untitled';

        $md_turns   = [];
        $text_turns = [];

        foreach ($messages as $m) {
            $is_assistant = $m->get('aim_role') === AiConversationMessage::ROLE_ASSISTANT;
            $label = $is_assistant ? 'Assistant' : 'You';
            $body  = (string)$m->get('aim_content');

            $md_turns[]   = '**' . $label . ":**\n\n" . $body;
            $text_turns[] = $label . ":\n" . self::toPlainText($body);
        }

        return [
            'title'    => $title,
            'markdown' => '# ' . $title . "\n\n" . implode("\n\n", $md_turns) . "\n",
            'text'     => $title . "\n\n" . implode("\n\n", $text_turns) . "\n",
        ];
    }

    /**
     * Render Markdown source down to readable plain text. Best-effort and
     * line-oriented — it removes Markdown syntax rather than interpreting it, so
     * the result is paste-ready prose, not a re-rendered document.
     */
    public static function toPlainText(string $md): string {
        // Normalize newlines so the line-by-line pass is predictable.
        $md = str_replace(["\r\n", "\r"], "\n", $md);

        // Drop fenced code blocks' fences but keep their contents as plain lines.
        $md = preg_replace('/^[ \t]*```[^\n]*\n(.*?)^[ \t]*```[ \t]*$/ms', "$1", $md);

        $out = [];
        foreach (explode("\n", $md) as $line) {
            // Headings: "## Title" -> "Title".
            $line = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+/', '', $line);
            // Blockquotes: "> quote" -> "quote".
            $line = preg_replace('/^[ \t]{0,3}>[ \t]?/', '', $line);
            // Bullet markers (-, *, +) -> a simple bullet, preserving indentation.
            $line = preg_replace('/^([ \t]*)[-*+][ \t]+/', "$1\xE2\x80\xA2 ", $line);
            // Horizontal rules -> blank line.
            if (preg_match('/^[ \t]{0,3}([-*_])([ \t]*\1){2,}[ \t]*$/', $line)) {
                $line = '';
            }
            $out[] = $line;
        }
        $text = implode("\n", $out);

        // Inline markup, applied across the whole string.
        // Images: ![alt](url) -> alt (url)  (run before links).
        $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/', '$1 ($2)', $text);
        // Links: [text](url) -> text (url).
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', '$1 ($2)', $text);
        // Bold/italic: **x**, __x__, *x*, _x_ -> x.
        $text = preg_replace('/(\*\*|__)(.+?)\1/s', '$2', $text);
        $text = preg_replace('/(\*|_)(.+?)\1/s', '$2', $text);
        // Inline code: `x` -> x.
        $text = preg_replace('/`([^`]+)`/', '$1', $text);

        // Collapse 3+ blank lines to a single blank line; trim edges.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
