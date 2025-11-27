<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only

/**
 * Basic markdown to HTML renderer
 */
function render_markdown($text) {
    // Escape HTML first
    $text = htmlspecialchars($text);

    // Code blocks (``` ... ```)
    $text = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function($matches) {
        $lang = $matches[1] ? ' class="language-' . $matches[1] . '"' : '';
        return '<pre><code' . $lang . '>' . $matches[2] . '</code></pre>';
    }, $text);

    // Inline code (`code`)
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

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

    return $text;
}

$file = isset($_GET['file']) ? $_GET['file'] : '';
$specs_dir = PathHelper::getIncludePath('specs');

// Security: only allow .md files within specs directory
$safe_file = basename(str_replace('implemented/', '', $file));
$is_implemented = strpos($file, 'implemented/') === 0;
$filepath = $is_implemented ? $specs_dir . '/implemented/' . $safe_file : $specs_dir . '/' . $safe_file;

// Validate file exists and is markdown
$content = '';
$title = '';
$error = '';

if (empty($file)) {
    $error = 'No file specified.';
} elseif (pathinfo($safe_file, PATHINFO_EXTENSION) !== 'md') {
    $error = 'Invalid file type.';
} elseif (!file_exists($filepath)) {
    $error = 'File not found.';
} elseif (!is_readable($filepath)) {
    $error = 'File is not readable.';
} else {
    $content = file_get_contents($filepath);
    $title = ucwords(str_replace(array('_', '-', '.md'), array(' ', ' ', ''), $safe_file));
}

$page = new AdminPage();
$page->admin_header(
    array(
        'menu-id' => 'settings',
        'breadcrumbs' => array(
            'Settings' => '/admin/admin_settings',
            'Specifications' => '/admin/admin_specs',
            $title => '',
        ),
        'session' => $session,
        'no_page_card' => true,
    )
);

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><?php echo htmlspecialchars($title); ?></h5>
    <div>
        <?php if ($is_implemented): ?>
            <span class="badge bg-success">Implemented</span>
        <?php else: ?>
            <span class="badge bg-warning text-dark">Active</span>
        <?php endif; ?>
        <a href="/admin/admin_specs" class="btn btn-secondary btn-sm ms-2">Back to List</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php else: ?>
    <div class="card">
        <div class="card-body spec-content">
            <style>
                .spec-content pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; }
                .spec-content code { background: #e8e8e8; color: #333; padding: 2px 5px; border-radius: 3px; font-size: 0.9em; }
                .spec-content pre code { background: none; color: #f8f8f2; padding: 0; }
                .spec-content h1, .spec-content h2, .spec-content h3 { margin-top: 1.5em; margin-bottom: 0.5em; }
                .spec-content h1 { border-bottom: 1px solid #ddd; padding-bottom: 0.3em; }
                .spec-content h2 { border-bottom: 1px solid #eee; padding-bottom: 0.3em; }
                .spec-content table { margin: 1em 0; }
                .spec-content ul, .spec-content ol { margin: 0.5em 0; padding-left: 2em; }
            </style>
            <?php echo render_markdown($content); ?>
        </div>
    </div>
<?php endif; ?>

<?php
$page->admin_footer();
?>
