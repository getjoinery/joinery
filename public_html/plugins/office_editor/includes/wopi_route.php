<?php
/**
 * Route handler for /office_editor/wopi/files/{id}[/contents]
 * (OfficeEditorWopi).
 *
 * @version 0.1.0
 */
// The editor reads bytes and JSON straight from here: none of the page's
// buffering applies.
while (ob_get_level() > 0) {
	ob_end_clean();
}
$office_wopi_id = isset($params['id']) ? (string)$params['id'] : '';
if (!ctype_digit($office_wopi_id)) {
	http_response_code(404);
	header('Content-Type: application/json');
	echo '{"error":"no such file"}';
	return;
}
$office_wopi_path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
OfficeEditorWopi::handle((int)$office_wopi_id, substr($office_wopi_path, -9) === '/contents');
