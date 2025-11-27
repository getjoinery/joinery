<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only

$specs_dir = PathHelper::getIncludePath('specs');
$implemented_dir = $specs_dir . '/implemented';

// Get active specs
$active_specs = array();
if (is_dir($specs_dir)) {
    $files = scandir($specs_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && $file !== 'implemented' && pathinfo($file, PATHINFO_EXTENSION) === 'md') {
            $filepath = $specs_dir . '/' . $file;
            $active_specs[] = array(
                'name' => $file,
                'title' => str_replace(array('_', '-', '.md'), array(' ', ' ', ''), $file),
                'modified' => filemtime($filepath),
                'size' => filesize($filepath),
            );
        }
    }
}

// Get implemented specs
$implemented_specs = array();
if (is_dir($implemented_dir)) {
    $files = scandir($implemented_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'md') {
            $filepath = $implemented_dir . '/' . $file;
            $implemented_specs[] = array(
                'name' => $file,
                'title' => str_replace(array('_', '-', '.md'), array(' ', ' ', ''), $file),
                'modified' => filemtime($filepath),
                'size' => filesize($filepath),
            );
        }
    }
}

// Sort by modified time descending
usort($active_specs, function($a, $b) {
    return $b['modified'] - $a['modified'];
});
usort($implemented_specs, function($a, $b) {
    return $b['modified'] - $a['modified'];
});

$page = new AdminPage();
$page->admin_header(
    array(
        'menu-id' => 'settings',
        'breadcrumbs' => array(
            'Settings' => '/admin/admin_settings',
            'Specifications' => '',
        ),
        'session' => $session,
    )
);

$headers = array("Specification", "Last Modified", "Size");

// Active Specifications Card
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Active Specifications (<?php echo count($active_specs); ?>)</h5>
    </div>
    <div class="card-body p-0">
<?php
$table_options = array();
$page->tableheader($headers, $table_options);

foreach ($active_specs as $spec) {
    $rowvalues = array();
    $rowvalues[] = "<a href='/admin/admin_spec_view?file=" . urlencode($spec['name']) . "'>" . htmlspecialchars(ucwords($spec['title'])) . "</a>";
    $rowvalues[] = date('Y-m-d H:i', $spec['modified']);
    $rowvalues[] = number_format($spec['size'] / 1024, 1) . ' KB';
    $page->disprow($rowvalues);
}

$page->endtable();
?>
    </div>
</div>

<?php
// Implemented Specifications Card
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Implemented Specifications (<?php echo count($implemented_specs); ?>)</h5>
    </div>
    <div class="card-body p-0">
<?php
$page->tableheader($headers, $table_options);

foreach ($implemented_specs as $spec) {
    $rowvalues = array();
    $rowvalues[] = "<a href='/admin/admin_spec_view?file=" . urlencode('implemented/' . $spec['name']) . "'>" . htmlspecialchars(ucwords($spec['title'])) . "</a>";
    $rowvalues[] = date('Y-m-d H:i', $spec['modified']);
    $rowvalues[] = number_format($spec['size'] / 1024, 1) . ' KB';
    $page->disprow($rowvalues);
}

$page->endtable();
?>
    </div>
</div>
<?php

$page->admin_footer();
?>
