<?php
/**
 * Image List AJAX Endpoint
 *
 * Returns paginated list of uploaded images for the image selector field.
 * Requires admin permission (level 5+).
 *
 * @version 1.0.0
 * @see /specs/imageselector_formwriter_field.md
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('data/files_class.php'));

$session = SessionControl::get_instance();

// Require admin permission
if (!$session->is_logged_in() || $session->get_permission() < 5) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// Get parameters
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
$limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;

// Build filter options for MultiFile
$options = [
    'picture' => true,
    'deleted' => false
];

if ($search) {
    $options['filename_like'] = $search;
}

// Query images
$files = new MultiFile($options, ['fil_file_id' => 'DESC'], $limit, $offset);
$total = $files->count_all();
$files->load();

// Build response
$images = [];
foreach ($files as $file) {
    $images[] = [
        'id' => $file->key,
        'url' => $file->get_url('standard'),
        'thumbnail' => $file->get_url('thumbnail'),
        'title' => $file->get('fil_title') ?: $file->get('fil_name'),
        'filename' => $file->get('fil_name')
    ];
}

echo json_encode([
    'images' => $images,
    'total' => $total,
    'hasMore' => ($offset + $limit) < $total
]);
