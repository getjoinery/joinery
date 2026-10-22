<?php
/**
 * Route handler: /drive/zip/{folder_id} — a Drive folder as a zip download.
 *
 * A route rather than an API action because the answer is the file itself,
 * streamed as it is built (DriveZip, ZipStreamWriter); the page asks with a
 * HEAD first and follows with the download. The statuses are the contract the
 * page reads:
 *
 *   200  the zip (HEAD: its headers only)
 *   404  no such live folder, or not the caller's to read
 *   409  a Fortress folder: the browser builds that zip, the server cannot
 *   423  a Private folder with the owner's unlock window shut
 *
 * Read access is the folder's (owner, or a viewer/editor grant reaching it),
 * the same as listing it. A zip asks no second factor: every file in it can
 * already be downloaded one by one (spec P5). drive_active is checked by the
 * route.
 *
 * @version 1.1 - output compression off for the zip
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$session = SessionControl::get_instance();
$user_id = (int)$session->get_user_id();
$folder_id = (int)($params['folder_id'] ?? 0);

$refuse = function (int $status, string $message) {
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
		echo $message;
	}
};

if (!$user_id) {
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
		$refuse(401, 'Sign in to download this folder.');
		return;
	}
	header('Location: /login?return=' . urlencode('/drive'), true, 302);
	return;
}

$folder = DriveHelper::load_folder($folder_id);
if (!$folder || DriveHelper::folder_is_trashed($folder)
	|| !DriveHelper::can_read(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
	$refuse(404, 'That folder was not found.');
	return;
}
if (DriveHelper::folder_is_encrypted($folder)) {
	$refuse(409, 'This folder is encrypted in your browser, so the Drive page builds its zip there.');
	return;
}

try {
	$plan = DriveZip::plan($folder);
} catch (VaultLockedException $e) {
	$refuse(423, 'This folder is Private. Unlock your vault to download it.');
	return;
}

$name = DriveZip::filename($plan);
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $name);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
	return;
}

// A zip is compressed already; output compression would gzip it again and
// leave the browser a file that is not a zip.
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
	@apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) { ob_end_clean(); }
if (session_status() === PHP_SESSION_ACTIVE) {
	// A large folder takes a while; the member's other pages, and the vault
	// heartbeat that keeps their window open, must not wait on it. Every sealed
	// file's key was resolved inside the window by plan().
	session_write_close();
}
@set_time_limit(0);

$buffer = '';
$sink = function ($bytes) use (&$buffer) {
	$buffer .= $bytes;
	if (strlen($buffer) >= 262144) {
		echo $buffer;
		$buffer = '';
		flush();
	}
};
try {
	DriveZip::stream($plan, $sink);
	echo $buffer;
	flush();
} catch (Throwable $e) {
	// The headers are gone; a short body is how the browser learns it failed.
	error_log('DriveZip: sending folder ' . (int)$folder->key . ' stopped: ' . $e->getMessage());
}
exit;
