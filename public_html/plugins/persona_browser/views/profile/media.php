<?php
/**
 * Persona Browser — media streamer
 * URL: /profile/persona_browser/media?f=<file>
 *
 * Streams a locally-cached feed image to logged-in members. The bytes live in
 * the plugin's media_cache (downloaded by FetchFeedTask); served through PHP
 * (not statically) so access stays behind the session.
 */
$session = SessionControl::get_instance();
if (!$session->is_logged_in()) { http_response_code(403); exit; }

$f = basename((string)($_GET['f'] ?? ''));
if ($f === '' || strpos($f, '..') !== false) { http_response_code(400); exit; }

$path = PathHelper::getIncludePath('plugins/persona_browser/media_cache/' . $f);
if (!is_file($path)) { http_response_code(404); exit; }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
