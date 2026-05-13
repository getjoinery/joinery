<?php
/**
 * MarkdownRenderer - Shared markdown-to-HTML renderer
 *
 * Extracted from adm/admin_spec_view.php for reuse across
 * the spec viewer and help documentation viewer.
 *
 * Version: 1.0
 */

class MarkdownRenderer {

    /**
     * Render markdown text to HTML.
     * Input is HTML-escaped first for XSS protection.
     */
    public static function render($text) {
        $text = htmlspecialchars($text);

        // Extract fenced and inline code to placeholders BEFORE any other pass so
        // their contents are invisible to header/italic/etc. regexes. Without this,
        // a `# comment` line inside a ```bash block becomes an <h1>, and a single
        // `*` inside an inline code span pairs with another `*` later in the
        // document and italicizes everything between.
        $placeholders = array();
        $counter = 0;

        // Fenced code blocks (``` ... ```)
        $text = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function($matches) use (&$placeholders, &$counter) {
            $lang = $matches[1] ? ' class="language-' . $matches[1] . '"' : '';
            $key = "\x02MDPH" . $counter++ . "\x02";
            $placeholders[$key] = '<pre><code' . $lang . '>' . $matches[2] . '</code></pre>';
            return $key;
        }, $text);

        // Double-backtick inline code (``...``) — used when content contains a backtick
        $text = preg_replace_callback('/``([^`]+)``/', function($matches) use (&$placeholders, &$counter) {
            $key = "\x02MDPH" . $counter++ . "\x02";
            $placeholders[$key] = '<code>' . trim($matches[1]) . '</code>';
            return $key;
        }, $text);

        // Single-backtick inline code (`code`) — restricted to a single line so an
        // unclosed backtick can't swallow the rest of the document
        $text = preg_replace_callback('/`([^`\n]+)`/', function($matches) use (&$placeholders, &$counter) {
            $key = "\x02MDPH" . $counter++ . "\x02";
            $placeholders[$key] = '<code>' . $matches[1] . '</code>';
            return $key;
        }, $text);

        // Headers (# to ######)
        $text = preg_replace('/^###### (.+)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $text);
        $text = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);

        // Bold (**text** or __text__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Italic (*text* or _text_)
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![a-zA-Z])_([^_]+)_(?![a-zA-Z])/', '<em>$1</em>', $text);

        // Horizontal rules
        $text = preg_replace('/^---+$/m', '<hr>', $text);

        // Links [text](url)
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);

        // Unordered lists (- item)
        $text = preg_replace_callback('/(?:^- .+$\n?)+/m', function($matches) {
            $items = preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($matches[0]));
            return '<ul>' . $items . '</ul>';
        }, $text);

        // Ordered lists (1. item)
        $text = preg_replace_callback('/(?:^\d+\. .+$\n?)+/m', function($matches) {
            $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($matches[0]));
            return '<ol>' . $items . '</ol>';
        }, $text);

        // Tables
        $text = preg_replace_callback('/^\|(.+)\|\n\|[-| ]+\|\n((?:\|.+\|\n?)+)/m', function($matches) {
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
        $text = preg_replace('/\n\n+/', '</p><p>', $text);
        $text = '<p>' . $text . '</p>';

        // Clean up empty paragraphs and fix nesting
        $text = preg_replace('/<p>\s*<(h[1-6]|ul|ol|pre|hr|table)/s', '<$1', $text);
        $text = preg_replace('/<\/(h[1-6]|ul|ol|pre|table)>\s*<\/p>/s', '</$1>', $text);
        $text = preg_replace('/<p>\s*<\/p>/', '', $text);
        $text = preg_replace('/<p><hr><\/p>/', '<hr>', $text);
        $text = preg_replace('/<p><hr>/', '<hr><p>', $text);

        if (!empty($placeholders)) {
            $text = strtr($text, $placeholders);
        }

        return $text;
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
        ';
    }
}
?>
