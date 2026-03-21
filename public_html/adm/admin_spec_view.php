<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only

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
        <div class="card-body markdown-content">
            <style><?php echo MarkdownRenderer::get_css(); ?></style>
            <?php echo MarkdownRenderer::render($content); ?>
        </div>
    </div>
<?php endif; ?>

<?php
$page->admin_footer();
?>
