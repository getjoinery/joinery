<?php
/**
 * MarkdownRenderer - Shared markdown-to-HTML renderer
 *
 * Extracted from adm/admin_spec_view.php for reuse across
 * the spec viewer and help documentation viewer.
 *
 * Version: 1.12
 * @changelog 1.12 - Placeholders are expanded only where present (a heading's
 *   slug, a mention's name): the full-map expansion per heading was quadratic.
 *   A link or image whose target holds a placeholder (code, a mention) stays
 *   as its bracket text instead of losing the target. A mention's name shows
 *   any code inside it rendered, never a raw placeholder.
 * @changelog 1.11 - Linear quote grouping (a quote-heavy text could exhaust the
 *   regex engine and come back empty) and one unwrap pass for quote blocks (the
 *   per-quote pass was quadratic). The placeholder sentinel byte is stripped from
 *   input and refused in link targets, so typed text cannot forge a placeholder.
 *   A link whose text holds a mention chip is shown as its text, not a link. A
 *   pattern that fails leaves its pass undone (logged) instead of emptying the page.
 * @changelog 1.10 - Block quotes: a run of lines starting with ">" renders as a
 *   <blockquote> whose contents are markdown of their own (lists, emphasis, code),
 *   nested up to MAX_QUOTE_DEPTH. Before this, "> " stayed literal text, so the
 *   editor's Quote button produced nothing a reader saw as a quote.
 * @changelog 1.9 - Mention tokens (@[Name](user:42), written by the editor's
 *   picker) render as a mention chip in every profile. Restricted links also
 *   carry rel="ugc", which tells search engines a member wrote the link.
 * @changelog 1.8 - Link and image targets are checked: only http, https, mailto and
 *   relative targets become links, and an image is only ever http, https or relative.
 *   Anything else (javascript:, data:, vbscript:, a scheme hidden behind tabs or
 *   entities) is shown as plain text. New $restricted profile for text other people
 *   wrote: no images, and headings carry no id (an id from someone else's text could
 *   shadow an element of the page).
 */

class MarkdownRenderer {

    /**
     * Changes whenever the same markdown would render differently. A caller that
     * stores rendered HTML stores this beside it, and renders again when it differs.
     */
    const VERSION = '1.12';

    /**
     * The renderer's definition of code, shared with MentionParser so both agree
     * on what is code and what is prose. Applied in this order.
     */
    const FENCE_PATTERN = '/^( {0,3})```(\w*)[ \t]*\n(.*?)\n {0,3}```[ \t]*$/ms';
    const DOUBLE_TICK_PATTERN = '/``([^`]+)``/';
    const SINGLE_TICK_PATTERN = '/`([^`\n]+)`/';

    /** The byte that brackets every placeholder; never allowed in from input. */
    const SENTINEL = "\x02";

    /** Quotes inside quotes are parsed this deep; deeper markers stay text. */
    const MAX_QUOTE_DEPTH = 4;

    /**
     * Render markdown text to HTML.
     * Input is HTML-escaped first for XSS protection.
     *
     * $soft_breaks — when true, a single newline inside a paragraph renders as
     * a line break (<br>) instead of collapsing to a space. Chat-style output
     * (LLM replies) wants this; authored docs are hard-wrapped and don't.
     *
     * $restricted — the profile for text other people wrote (comments, card
     * descriptions, forum posts, anything that can arrive by email): no images,
     * no heading ids, headings pushed down two levels, links open in a new tab
     * without the opener and are marked nofollow and ugc. Authored docs use the
     * default profile.
     *
     * A mention token — @[Name](user:42), see MentionParser — becomes a chip
     * showing the name, in either profile. Inside code it stays literal text.
     */
    public static function render($text, $soft_breaks = false, $restricted = false) {
        $text = str_replace(self::SENTINEL, '', (string)$text);
        return self::render_escaped(htmlspecialchars($text), $soft_breaks, $restricted, 0);
    }

    /**
     * The rendering itself, over text that is already HTML-escaped. A block
     * quote's contents come back through here one level deeper.
     */
    private static function render_escaped($text, $soft_breaks, $restricted, $depth) {

        // Extract fenced and inline code to placeholders BEFORE any other pass so
        // their contents are invisible to header/italic/etc. regexes. Without this,
        // a `# comment` line inside a ```bash block becomes an <h1>, and a single
        // `*` inside an inline code span pairs with another `*` later in the
        // document and italicizes everything between.
        $placeholders = array();
        $counter = 0;

        // Fenced code blocks (``` ... ```) — fences may be indented 0–3 spaces (e.g. inside list items).
        // Leading whitespace is preserved in the replacement so the placeholder stays on an indented
        // continuation line, which lets the list regex below absorb it into the parent list item.
        $text = self::sub(self::FENCE_PATTERN, function($matches) use (&$placeholders, &$counter) {
            $lang = $matches[2] ? ' class="language-' . $matches[2] . '"' : '';
            $key = "\x02MDPH" . $counter++ . "\x02";
            $placeholders[$key] = '<pre><code' . $lang . '>' . $matches[3] . '</code></pre>';
            return $matches[1] . $key;
        }, $text);

        // Block quotes: a run of lines starting with ">" (escaped to &gt; by
        // now). Fenced code is already a placeholder, so a "> " line inside a
        // code block is never taken for a quote; a fence inside the quote is
        // found by the inner pass. The quote stands as its own block.
        $block_keys = false;
        if ($depth < self::MAX_QUOTE_DEPTH && strpos($text, '&gt;') !== false) {
            $text = self::extract_quotes($text, $placeholders, $counter, $soft_breaks, $restricted, $depth);
            $block_keys = true;
        }

        // Double-backtick inline code (``...``) — used when content contains a backtick
        $text = self::sub(self::DOUBLE_TICK_PATTERN, function($matches) use (&$placeholders, &$counter) {
            $key = "\x02MDPH" . $counter++ . "\x02";
            $placeholders[$key] = '<code>' . trim($matches[1]) . '</code>';
            return $key;
        }, $text);

        // Single-backtick inline code (`code`) — restricted to a single line so an
        // unclosed backtick can't swallow the rest of the document
        $text = self::sub(self::SINGLE_TICK_PATTERN, function($matches) use (&$placeholders, &$counter) {
            $key = "\x02MDPH" . $counter++ . "\x02";
            $placeholders[$key] = '<code>' . $matches[1] . '</code>';
            return $key;
        }, $text);

        // Mention tokens, before any rule that reads brackets or emphasis: the
        // name is the author's text (already escaped), so it is held as a
        // placeholder and nothing inside it is parsed.
        $text = self::sub(MentionParser::TOKEN_PATTERN, function($matches) use (&$placeholders, &$counter) {
            $key = "\x02MDMN" . $counter++ . "\x02";
            $placeholders[$key] = '<span class="jy-mention" data-user-id="' . (int)$matches[2] . '">@' . self::expand_present($matches[1], $placeholders) . '</span>';
            return $key;
        }, $text);

        // Headers (# to ######), each carrying the slug its own document's
        // table of contents links to. Repeated titles get -1, -2 suffixes, the
        // same way the anchors are written elsewhere.
        $slug_counts = array();
        $text = self::sub('/^(#{1,6}) (.+)$/m', function($matches) use (&$slug_counts, $placeholders, $restricted) {
            $level = strlen($matches[1]);
            $title = trim($matches[2]);
            if ($restricted) {
                // Someone else's heading: no id, and never bigger than the page's own.
                $level = min(6, $level + 2);
                return '<h' . $level . '>' . $title . '</h' . $level . '>';
            }
            $slug = self::heading_slug($title, $placeholders);
            if ($slug !== '') {
                $n = isset($slug_counts[$slug]) ? $slug_counts[$slug] : 0;
                $slug_counts[$slug] = $n + 1;
                if ($n > 0) $slug = $slug . '-' . $n;
                return '<h' . $level . ' id="' . $slug . '">' . $title . '</h' . $level . '>';
            }
            return '<h' . $level . '>' . $title . '</h' . $level . '>';
        }, $text);

        // Bold (**text** or __text__)
        $text = self::sub('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = self::sub('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Italic (*text* or _text_)
        $text = self::sub('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = self::sub('/(?<![a-zA-Z])_([^_]+)_(?![a-zA-Z])/', '<em>$1</em>', $text);

        // Horizontal rules
        $text = self::sub('/^---+$/m', '<hr>', $text);

        // Images ![alt](url) — must run before the link rule, which would
        // otherwise consume the bracket pair and leave a bare '!' beside a link.
        // Text was escaped up front, so alt and src need no further escaping.
        // Wrapped in a link to itself: shown small in the column, full size on click.
        // The target is checked first: a scheme that can run script or fetch
        // something nobody asked for is shown as the alt text instead.
        $text = self::sub('/!\[([^\]]*)\]\(([^)\s]+)\)/', function($matches) use ($restricted) {
            // A placeholder (code, a mention) in the target: not an image —
            // keep the bracket text as written, which the restore fills in.
            if (strpos($matches[2], self::SENTINEL) !== false) {
                return $matches[0];
            }
            if ($restricted || !self::is_safe_url($matches[2], false)) {
                return $matches[1];
            }
            return '<a href="' . $matches[2] . '" target="_blank" rel="noopener"><img src="' . $matches[2] . '" alt="' . $matches[1] . '"></a>';
        }, $text);

        // Links [text](url). Only http, https, mailto and relative targets
        // become links; any other scheme leaves the text without the link.
        $text = self::sub('/\[([^\]]+)\]\(([^)]+)\)/', function($matches) use ($restricted) {
            // A placeholder in the target: not a link; keep the bracket text.
            if (strpos($matches[2], self::SENTINEL) !== false) {
                return $matches[0];
            }
            // A mention chip is a person, never the face of a link.
            if (!self::is_safe_url($matches[2], true) || strpos($matches[1], "\x02MDMN") !== false) {
                return $matches[1];
            }
            $extra = $restricted ? ' target="_blank" rel="noopener noreferrer nofollow ugc"' : '';
            return '<a href="' . $matches[2] . '"' . $extra . '>' . $matches[1] . '</a>';
        }, $text);

        // Unordered lists (- item) — each item may span indented continuation lines
        $text = self::sub('/(?:^- .+$(?:\n[ \t]+[^\n]*)*\n?)+/m', function($matches) {
            $raw = trim($matches[0]);
            $items = preg_split('/^(?=- )/m', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $html = '';
            foreach ($items as $item) {
                $content = trim(preg_replace('/^- /', '', $item));
                if ($content !== '') $html .= '<li>' . $content . '</li>';
            }
            return '<ul>' . $html . '</ul>';
        }, $text);

        // Ordered lists (1. item) — each item may span indented continuation lines
        $text = self::sub('/(?:^\d+\. .+$(?:\n[ \t]+[^\n]*)*\n?)+/m', function($matches) {
            $raw = trim($matches[0]);
            $items = preg_split('/^(?=\d+\. )/m', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $html = '';
            foreach ($items as $item) {
                $content = trim(preg_replace('/^\d+\. /', '', $item));
                if ($content !== '') $html .= '<li>' . $content . '</li>';
            }
            return '<ol>' . $html . '</ol>';
        }, $text);

        // Tables — separator row accepts alignment colons (:---, :---:, ---:)
        $text = self::sub('/^\|(.+)\|\n\|[-:| ]+\|\n((?:\|.+\|\n?)+)/m', function($matches) {
            // Header row
            $headers = array_map('trim', explode('|', trim($matches[1], '|')));
            $header_html = '<tr>' . implode('', array_map(function($h) { return '<th>' . $h . '</th>'; }, $headers)) . '</tr>';

            // Body rows
            $rows = explode("\n", trim($matches[2]));
            $body_html = '';
            foreach ($rows as $row) {
                if (trim($row)) {
                    $cells = array_map('trim', explode('|', trim($row, '|')));
                    $body_html .= '<tr>' . implode('', array_map(function($c) { return '<td>' . $c . '</td>'; }, $cells)) . '</tr>';
                }
            }

            return '<table class="table table-bordered table-sm"><thead>' . $header_html . '</thead><tbody>' . $body_html . '</tbody></table>';
        }, $text);

        // Paragraphs (double newlines)
        $text = self::sub('/\n\n+/', '</p><p>', $text);
        $text = '<p>' . $text . '</p>';

        // Soft breaks: remaining newlines are single (the paragraph pass ate
        // runs of 2+). Convert them to <br>, except where the newline touches
        // a block element's boundary — a <br> there would add stray gaps.
        // Code blocks are placeholders at this point, so their contents are
        // safe; a <br> landing against a restored <pre> is stripped below.
        if ($soft_breaks) {
            $text = self::sub(
                '~(?<!</h1>|</h2>|</h3>|</h4>|</h5>|</h6>|</ul>|</ol>|</table>|<hr>)\n(?!<(?:h[1-6]|ul|ol|table|hr))~',
                '<br>',
                $text
            );
        }

        // Clean up empty paragraphs and fix nesting
        $text = self::sub('/<p>\s*<(h[1-6]|ul|ol|pre|hr|table)/s', '<$1', $text);
        $text = self::sub('/<\/(h[1-6]|ul|ol|pre|table)>\s*<\/p>/s', '</$1>', $text);
        $text = self::sub('/<p>\s*<\/p>/', '', $text);
        $text = self::sub('/<p><hr><\/p>/', '<hr>', $text);
        $text = self::sub('/<p><hr>/', '<hr><p>', $text);

        // A quote is a block, not a paragraph's content: one pass for all of them.
        if ($block_keys) {
            $text = self::sub('#<p>(\x02MDBQ\d+\x02)</p>|<br>(\x02MDBQ\d+\x02)|(\x02MDBQ\d+\x02)<br>#', function($m) {
                return $m[1] . ($m[2] ?? '') . ($m[3] ?? '');
            }, $text);
        }

        if (!empty($placeholders)) {
            $text = strtr($text, $placeholders);
        }

        // A soft break that landed against a fenced code block (a placeholder
        // until the line above) is a stray gap — drop it.
        if ($soft_breaks) {
            $text = str_replace(array('<br><pre>', '</pre><br>'), array('<pre>', '</pre>'), $text);
        }

        return $text;
    }

    /**
     * Group runs of quoted lines ("&gt;" after escaping) into block quotes, in
     * one walk over the lines. Each quote's text, its markers removed, is
     * rendered as markdown of its own one level deeper and held as a block
     * placeholder that stands as its own paragraph.
     */
    private static function extract_quotes($text, &$placeholders, &$counter, $soft_breaks, $restricted, $depth) {
        $out = array();
        $run = array();
        $flush = function () use (&$run, &$out, &$placeholders, &$counter, $soft_breaks, $restricted, $depth) {
            if (!$run) {
                return;
            }
            $key = "\x02MDBQ" . $counter++ . "\x02";
            $placeholders[$key] = '<blockquote>' . self::render_escaped(implode("\n", $run), $soft_breaks, $restricted, $depth + 1) . '</blockquote>';
            // A blank line on each side: the quote is always a paragraph of its own.
            array_push($out, '', '', $key, '', '');
            $run = array();
        };
        foreach (explode("\n", $text) as $line) {
            $trimmed = ltrim($line, ' ');
            if (strlen($line) - strlen($trimmed) <= 3 && strncmp($trimmed, '&gt;', 4) === 0) {
                $rest = substr($trimmed, 4);
                $run[] = ($rest !== '' && $rest[0] === ' ') ? substr($rest, 1) : $rest;
                continue;
            }
            $flush();
            $out[] = $line;
        }
        $flush();
        return implode("\n", $out);
    }

    /**
     * $text with the placeholders it contains expanded — only those, so the
     * cost follows the text, not the size of the whole placeholder map.
     */
    private static function expand_present($text, array $placeholders) {
        if (!$placeholders || strpos($text, self::SENTINEL) === false) {
            return $text;
        }
        if (!preg_match_all('/\x02MD[A-Z]{2}\d+\x02/', $text, $m)) {
            return $text;
        }
        $present = array();
        foreach ($m[0] as $key) {
            if (isset($placeholders[$key])) {
                $present[$key] = $placeholders[$key];
            }
        }
        return strtr($text, $present);
    }

    /**
     * preg_replace / preg_replace_callback that never empties the text: when a
     * pattern fails (the backtrack limit on hostile input), the pass is left
     * undone and logged, and rendering carries on with the text as it was.
     *
     * @param string|callable $replacement
     */
    private static function sub($pattern, $replacement, $text) {
        $result = is_callable($replacement) && !is_string($replacement)
            ? preg_replace_callback($pattern, $replacement, $text)
            : preg_replace($pattern, $replacement, $text);
        if ($result === null) {
            error_log('MarkdownRenderer: pattern failed (' . preg_last_error_msg() . '): ' . $pattern);
            return $text;
        }
        return $result;
    }

    /**
     * May this link or image target be emitted as-is?
     *
     * $url arrives already HTML-escaped (render() escapes the whole text up
     * front), so it is decoded before the scheme is read, and the whitespace and
     * control characters a browser ignores inside a scheme ("java\tscript:") are
     * stripped first. A target with no scheme is relative and fine. A target with
     * a scheme is fine only if the scheme is http or https — or mailto, for a
     * link. Everything else (javascript:, data:, vbscript:, file:, …) is refused.
     *
     * @param string $url
     * @param bool $allow_mailto true for a link, false for an image
     */
    public static function is_safe_url($url, $allow_mailto = true) {
        // A placeholder in a target would be filled with markup on the way out.
        if (strpos((string)$url, self::SENTINEL) !== false) {
            return false;
        }
        $decoded = html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/[\x00-\x20\x7f]+/u', '', $decoded);
        if ($decoded === null || $decoded === '') {
            return false;
        }
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $decoded, $m)) {
            // No scheme: relative ('/x', 'x', '#x', '?x'). A protocol-relative
            // '//host/x' is still an http(s) link, which is allowed.
            return true;
        }
        $scheme = strtolower($m[1]);
        if ($scheme === 'http' || $scheme === 'https') {
            return true;
        }
        return $allow_mailto && $scheme === 'mailto';
    }

    /**
     * Anchor slug for a heading, matching the anchors authors hand-write in a
     * table of contents: punctuation is dropped first, and only then do spaces
     * become hyphens — so 'File Structure & Naming' is
     * 'file-structure--naming', with the gap the ampersand left behind.
     * Inline code in a heading is a placeholder by this point, so its text is
     * restored before slugging.
     */
    private static function heading_slug($title, $placeholders = array()) {
        $slug = self::expand_present($title, $placeholders);
        $slug = html_entity_decode(strip_tags($slug), ENT_QUOTES, 'UTF-8');
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9 -]+/', '', $slug);
        return str_replace(' ', '-', trim($slug));
    }

    /**
     * Rewrite markdown-doc links in rendered HTML to point at the active
     * viewer (e.g. '/admin/admin_help' or '/documentation').
     *
     * Any .md href that DocsScanner::path_to_key() recognizes as pointing at
     * a real doc inside docs/ or plugins/*\/docs/ is rewritten. Everything
     * else (external URLs, paths outside the docs roots, missing files) is
     * left untouched.
     *
     * $current_doc_dir is the directory of the doc whose content is being
     * rendered — used to resolve relative links (bare filenames, ../ paths).
     */
    public static function rewrite_doc_links($html, $current_doc_dir, $base_url = '/admin/admin_help') {
        return preg_replace_callback('/href="([^"]*\.md(?:#[^"]*)?)"/', function($matches) use ($current_doc_dir, $base_url) {
            $href = $matches[1];

            $anchor = '';
            if (($hash_pos = strpos($href, '#')) !== false) {
                $anchor = substr($href, $hash_pos);
                $href = substr($href, 0, $hash_pos);
            }

            $key = DocsScanner::path_to_key($href, $current_doc_dir);
            if ($key === null) return $matches[0];

            return 'href="' . $base_url . '?doc=' . htmlspecialchars($key) . $anchor . '"';
        }, $html);
    }

    /**
     * Return CSS for styling rendered markdown content.
     * Use inside a <style> tag wrapping a .markdown-content container.
     */
    public static function get_css() {
        return '
            .markdown-content pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; }
            .markdown-content code { background: #e8e8e8; color: #333; padding: 2px 5px; border-radius: 3px; font-size: 0.9em; }
            .markdown-content pre code { background: none; color: #f8f8f2; padding: 0; }
            .markdown-content h1, .markdown-content h2, .markdown-content h3 { margin-top: 1.5em; margin-bottom: 0.5em; }
            .markdown-content h1 { border-bottom: 1px solid #ddd; padding-bottom: 0.3em; }
            .markdown-content h2 { border-bottom: 1px solid #eee; padding-bottom: 0.3em; }
            .markdown-content table { margin: 1em 0; }
            .markdown-content ul, .markdown-content ol { margin: 0.5em 0; padding-left: 2em; }
            /* Stated rather than inherited: the admin theme resets list markers
               globally, which left every numbered step in the docs unnumbered. */
            .markdown-content ul { list-style: disc; }
            .markdown-content ol { list-style: decimal; }
            .markdown-content li { list-style: inherit; }
            /* Screenshots sit at half the column width, framed so a
               white-background capture reads as a distinct thing. They are
               stored at twice that, so they stay sharp on high-DPI screens
               and open at full size when clicked. */
            .markdown-content img { display: block; max-width: min(100%, 380px); height: auto; margin: 1em 0; border: 1px solid #ddd; border-radius: 4px; }
            .markdown-content a > img { cursor: zoom-in; }
            .markdown-content .jy-mention { font-weight: 600; white-space: nowrap; }
            .markdown-content blockquote { margin: 0.75em 0; padding: 0.25em 1em; border-left: 3px solid #c8ccd4; color: #5a6170; }
        ';
    }
}
?>
