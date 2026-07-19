<?php
/** @joinery-test
 * name: upload_safety
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The uploaded-file attack surface, end to end.
 *
 * An upload is the one place where a stranger chooses both the bytes on our
 * disk and the name they land under. Four independent things have to hold, and
 * this test pins each one:
 *
 *   1. The name cannot escape the upload directory, cannot become a hidden or
 *      control-character name, and cannot smuggle a second extension past the
 *      allowlist (UploadHandler::trim_file_name / get_file_name).
 *   2. The extension allowlist is the setting, applied to the *sanitized* name
 *      — not the name the client sent (UploadHandler::validate).
 *   3. The stored type is read from the bytes, never from the client's claimed
 *      Content-Type or extension (File::detect_mime_*).
 *   4. Only genuine raster images render inline; everything else — SVG, HTML,
 *      PDF, unrecognized binary — is forced to download
 *      (File::is_inline_safe_type, and the pre-boot backstop in
 *      RouteHelper::serveStaticFile that serves public uploads without the
 *      File model).
 *
 * Plus the invariant the whole thing quietly rests on: neither directory that
 * receives uploaded bytes is inside the document root, so a name ending in
 * .php is inert wherever it lands.
 *
 * The blob/refcount layer underneath is covered by blob_layer_test.php; this
 * test does not re-cover it.
 *
 * Run:  php tests/functional/files/upload_safety_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/UploadHandler.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/RouteHelper.php'));

/**
 * UploadHandler with initialize() suppressed, exposing the protected name
 * pipeline. Constructing the real class (rather than reimplementing the regex)
 * is the point: these are the methods a POST actually runs.
 */
class UploadSafetyProbe extends UploadHandler
{
    public function __construct(array $options = array()) {
        parent::__construct($options, false);
    }
    public function trim_name($name) {
        return $this->trim_file_name(null, $name, 0, '', 0, null, null);
    }
    public function accepts($name) {
        return (bool) preg_match($this->options['accept_file_types'], $name);
    }
    public function base($path) {
        return $this->basename($path);
    }
}

$settings = Globalvars::get_instance();

// The options the admin upload page builds — in particular the allowlist is
// assembled from the allowed_upload_extensions setting, so the test exercises
// the real regex-construction pipeline. The value is pinned in memory: the
// accept/refuse checks below assume png in and php out, and must not flip
// with operator configuration.
harness_set_setting_mem('allowed_upload_extensions', 'gif,jpeg,jpg,png,avif,webp,pdf,xls,doc,xlsx,docx,mp3,mp4,m4a');
$allowed = $settings->get_setting('allowed_upload_extensions');
$probe = new UploadSafetyProbe(array(
    'accept_file_types' => '/\.(' . str_replace(',', '|', $allowed) . ')$/i',
));

$upload_dir = $settings->get_setting('upload_dir');
$fast_dir   = dirname($upload_dir) . '/static_files/uploads';

// A real 1x1 PNG — the only way to prove "detected from the bytes" is to hand
// the detector bytes that disagree with the name.
$PNG_BYTES = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);

$owner = make_user('upload_safety', 5);

/** Ingest bytes under a display name and register the row for teardown. */
function ingest($bytes, $display_name, $claimed_type, $owner_id, array $restrictions = array()) {
    $file = File::createFromBytes($bytes, $display_name, $claimed_type, $owner_id, $restrictions);
    harness_register_row('fil_files', 'fil_file_id', $file->key);
    return $file;
}

// ---------------------------------------------------------------------------
section('Name sanitization: the landing name cannot escape or hide');

// Every one of these must come back with no path separator at all. A name that
// still contains one would let the upload land outside upload_dir.
$traversal = array(
    '../../evil.png',
    '....//....//evil.png',
    '/etc/cron.d/evil.png',
    'a/b/c/evil.png',
    'x.png/../../y.png',
);
foreach ($traversal as $hostile) {
    $clean = $probe->trim_name($hostile);
    check(strpos($clean, '/') === false, 'no separator survives: ' . $hostile, 'got: ' . $clean);
    check($clean !== '' && $clean[0] !== '.', 'result is not a dotfile: ' . $hostile, 'got: ' . $clean);
}
check($probe->trim_name('../../evil.png') === 'evil.png', 'traversal reduces to the bare name');

// A leading dot would create a hidden file; ".htaccess" would be a directive
// file if it ever landed somewhere Apache reads.
check($probe->trim_name('.htaccess') === 'htaccess', 'leading dot stripped from .htaccess');
check($probe->trim_name('...hidden.png') === 'hidden.png', 'repeated leading dots stripped');

// Empty / all-punctuation names get a generated name rather than an empty path.
foreach (array('', '.', '..', '   ') as $empty) {
    $clean = $probe->trim_name($empty);
    check($clean !== '', 'empty-ish name gets a generated name: ' . var_export($empty, true), 'got: ' . $clean);
    check(strpos($clean, '/') === false, 'generated name has no separator: ' . var_export($empty, true));
}

// Interior whitespace is normalized, so a name cannot carry a newline into a
// header or a log line.
check($probe->trim_name('a b c.png') === 'a_b_c.png', 'spaces become underscores');
check(strpos($probe->trim_name("evil\n.png"), "\n") === false, 'newline does not survive');
check(strpos($probe->trim_name("evil\r\n.png"), "\r") === false, 'carriage return does not survive');

// ---------------------------------------------------------------------------
section('Name sanitization: control characters cannot reach the filesystem');

// A NUL in the middle of a name is the dangerous case, because it is invisible
// to every check in the pipeline: is_file() reports false for a NUL path, so
// the name looks unused, and then move_uploaded_file() rejects it with a
// ValueError. Without stripping, an upload named "evil\0.png" is an unhandled
// fatal triggered by a value the client fully controls.
foreach (array("evil\x00.png", "ev\x00il.png", "evil.p\x00ng") as $nul_name) {
    $clean = $probe->trim_name($nul_name);
    check(strpos($clean, "\x00") === false, 'NUL stripped from ' . addcslashes($nul_name, "\0"), 'got: ' . addcslashes($clean, "\0"));
}
foreach (array("evil\x01.png", "evil\x1f.png", "evil\x7f.png") as $ctrl_name) {
    $clean = $probe->trim_name($ctrl_name);
    check(preg_match('/[\x00-\x1f\x7f]/', $clean) === 0, 'control char stripped from ' . addcslashes($ctrl_name, "\0..\37\177"), 'got: ' . addcslashes($clean, "\0..\37\177"));
}

// The reason this matters, stated as the property that must hold: whatever the
// pipeline returns must be a name the filesystem will actually accept.
$landing = $probe->trim_name("evil\x00.png");
$probe_path = sys_get_temp_dir() . '/upload_safety_' . bin2hex(random_bytes(4)) . '_' . $landing;
$wrote = false;
try {
    $wrote = (file_put_contents($probe_path, 'x') !== false);
} catch (Throwable $e) {
    $wrote = false;
}
check($wrote, 'the sanitized name is a writable path (no ValueError)');
@unlink($probe_path);

// ---------------------------------------------------------------------------
section('Extension allowlist applies to the sanitized name');

// The gate runs against the cleaned name, so a double extension must already
// have been collapsed before it is tested.
check($probe->trim_name('evil.php.png') === 'evil-php.png', 'double extension collapsed to one');
check($probe->trim_name('shell.phtml.png') === 'shell-phtml.png', 'phtml double extension collapsed');
check($probe->accepts($probe->trim_name('evil.php.png')), 'collapsed double extension is accepted as a png');
check(!$probe->accepts($probe->trim_name('evil.php')), 'a bare .php name is refused');
check(!$probe->accepts($probe->trim_name('.htaccess')), 'htaccess is refused');
check(!$probe->accepts($probe->trim_name('no_extension')), 'an extensionless name is refused');
check(!$probe->accepts($probe->trim_name('evil.png.php')), 'png-then-php is refused (php is the real extension)');

// The allowlist is case-insensitive, so an uppercase extension is accepted
// rather than slipping past as unrecognized.
check($probe->accepts('UPPER.PNG'), 'uppercase extension accepted');
check(!$probe->accepts('UPPER.PHP'), 'uppercase php still refused');

// Every extension the setting names must actually pass its own gate — a stray
// space in the setting would silently disable one, and nothing else would say so.
foreach (array_map('trim', explode(',', $allowed)) as $ext) {
    check($probe->accepts('sample.' . $ext), 'configured extension is accepted: ' . $ext);
}

// ---------------------------------------------------------------------------
section('Stored type is read from the bytes, not from the client');

// Each case hands the ingestion path a name and a Content-Type that both lie.
// The stored fil_type must describe the bytes.
$lies = array(
    'php source as .png'  => array($PNG_BYTES === '' ? 'x' : "<?php system(\$_GET['c']); ?>", 'photo.png', 'image/png', 'text/x-php'),
    'html as .gif'        => array("<html><script>alert(1)</script></html>", 'photo.gif', 'image/gif', 'text/html'),
    'svg as .png'         => array('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'photo.png', 'image/png', 'image/svg+xml'),
);
foreach ($lies as $label => $case) {
    list($bytes, $name, $claimed, $expected) = $case;
    $file = ingest($bytes, $name, $claimed, $owner->key);
    check($file->get('fil_type') === $expected, "$label: stored type is $expected", 'got: ' . $file->get('fil_type'));
    check($file->get('fil_type') !== $claimed, "$label: the claimed type was not believed");
    check(!$file->is_image(), "$label: not treated as an image");
}

// The converse: honest bytes with a dishonest claim are still read correctly,
// so detection is not merely "distrust the client" but actual detection.
$real_png = ingest($PNG_BYTES, 'notes.txt', 'text/plain', $owner->key);
check($real_png->get('fil_type') === 'image/png', 'real PNG detected despite a .txt name and text/plain claim', 'got: ' . $real_png->get('fil_type'));
check($real_png->is_image(), 'real PNG is treated as an image');

// Unrecognized bytes fail closed to the sentinel rather than to something
// renderable.
$junk = ingest(random_bytes(64), 'thing.png', 'image/png', $owner->key);
check(in_array($junk->get('fil_type'), array('application/octet-stream', 'application/x-empty'), true),
    'unrecognized bytes store a fail-closed type', 'got: ' . $junk->get('fil_type'));
check(!$junk->is_image(), 'unrecognized bytes are not an image');

// ---------------------------------------------------------------------------
section('Inline rendering is an allowlist of raster images');

$inline_yes = array('image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif');
foreach ($inline_yes as $mime) {
    check(File::is_inline_safe_type($mime), "inline allowed: $mime");
}
// The types that must never render inline from our origin. SVG and HTML carry
// script; the rest are simply not images.
$inline_no = array(
    'image/svg+xml', 'text/html', 'text/xml', 'application/xml',
    'application/xhtml+xml', 'application/pdf', 'text/x-php',
    'application/octet-stream', 'text/plain', '', 'image/pngx', 'image/',
);
foreach ($inline_no as $mime) {
    check(!File::is_inline_safe_type($mime), 'inline refused: ' . var_export($mime, true));
}
check(!File::is_inline_safe_type(null), 'inline refused: null');

// Case and a charset parameter must not defeat the match, or a legitimate image
// would be forced to download.
check(File::is_inline_safe_type('IMAGE/PNG'), 'allowlist is case-insensitive');
check(File::is_inline_safe_type('image/png; charset=binary'), 'a charset parameter is tolerated');
check(File::is_inline_safe_type('  image/png  '), 'surrounding whitespace is tolerated');
// Substring tricks must not match, since the check is membership, not strpos.
check(!File::is_inline_safe_type('text/html; x=image/png'), 'a parameter cannot smuggle an allowed type');

// ---------------------------------------------------------------------------
section('The pre-boot backstop refuses to serve executables');

// Public uploads are served before the File model is loaded, so
// serveStaticFile carries its own floor. It must never serve a .php file,
// whatever else is true about the request.
$php_path = $upload_dir . '/upload_safety_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($php_path, "<?php echo 'executed'; ?>");
ob_start();
$served = RouteHelper::serveStaticFile($php_path);
$body = ob_get_clean();
check($served === false, 'serveStaticFile refuses a .php file');
check(strpos($body, 'executed') === false, 'the php file was neither executed nor echoed');
@unlink($php_path);

// A directory and a missing path are refused rather than fataling.
check(RouteHelper::serveStaticFile($upload_dir) === false, 'serveStaticFile refuses a directory');
check(RouteHelper::serveStaticFile($upload_dir . '/does_not_exist_' . bin2hex(random_bytes(4)) . '.png') === false,
    'serveStaticFile refuses a missing path');

// The dangerous-inline set this path forces to download. getMimeType is
// extension-driven here (the File model is deliberately absent), so the check
// is that the extension mapping lands on a type the backstop then refuses to
// render inline.
foreach (array('svg' => 'image/svg+xml', 'html' => 'text/html', 'htm' => 'text/html', 'xml' => 'text/xml') as $ext => $expected_mime) {
    $mime = RouteHelper::getMimeType('sample.' . $ext);
    check($mime === $expected_mime, "getMimeType maps .$ext to $expected_mime", 'got: ' . $mime);
    check(!File::is_inline_safe_type($mime), ".$ext is not inline-safe under the model's allowlist either");
}

// ---------------------------------------------------------------------------
section('Uploaded bytes never land inside the document root');

// This is the invariant that makes a .php name harmless: an extension the
// allowlist rejects can still reach disk through a non-upload ingestion path
// (an inbound email attachment, say), so the directories themselves must be
// unservable by Apache. If either ever moved under public_html, a name the
// minter preserves verbatim would become executable.
$docroot = realpath(PathHelper::getBasePath());
check($docroot !== false, 'document root resolves', 'PathHelper::getBasePath()');
foreach (array('upload_dir' => $upload_dir, 'fast-serve dir' => $fast_dir) as $label => $dir) {
    $real = realpath($dir);
    if ($real === false) {
        harness_skip("$label is outside the document root", "$dir does not exist on this host");
        continue;
    }
    check(strpos($real . '/', $docroot . '/') !== 0, "$label is outside the document root", "$real vs $docroot");
}

// The minter preserves whatever extension it is handed, which is exactly why
// the directory placement above has to hold. Pin it so the assumption is
// visible rather than implied.
$mint = new ReflectionMethod('File', '_mint_unique_name');
$mint->setAccessible(true);
$minted = $mint->invoke(null, '../../evil.php');
check(substr($minted, -4) === '.php', 'the minter preserves a .php extension verbatim', 'got: ' . $minted);
check(strpos($minted, '/') === false, 'the minter strips path separators', 'got: ' . $minted);
check(preg_match('/^[A-Za-z0-9._\-]+$/', $minted) === 1, 'the minted name is limited to safe characters', 'got: ' . $minted);
check(strpos($mint->invoke(null, "evil\x00.png"), "\x00") === false, 'the minter strips NUL');

// ---------------------------------------------------------------------------
section('Landing names do not collide with live files or stored blobs');

// Two ingests of the same display name must not produce the same fil_name, or
// the second would take over the first file's URL identity.
$a = ingest($PNG_BYTES, 'shared_name.png', 'image/png', $owner->key);
$b = ingest($PNG_BYTES . "\x00extra", 'shared_name.png', 'image/png', $owner->key);
check($a->get('fil_name') !== $b->get('fil_name'), 'two files sharing a display name get distinct stored names',
    $a->get('fil_name') . ' vs ' . $b->get('fil_name'));
check(File::get_by_name($a->get('fil_name'))->key == $a->key, 'the first file still resolves by its own name');
check(File::get_by_name($b->get('fil_name'))->key == $b->key, 'the second file resolves by its own name');

// ---------------------------------------------------------------------------
section('A private file is not viewable by a stranger');

$stranger = make_user('upload_safety_other', 0);
$private = ingest($PNG_BYTES, 'private.png', 'image/png', $owner->key, array('fil_private' => true));

check(!$private->is_public(), 'a private file is not public');
check($private->is_owned_by($owner->key), 'the owner owns it');
check(!$private->is_owned_by($stranger->key), 'a stranger does not own it');
check(!$private->is_owned_by(0), 'a logged-out user id does not own it');

$public = ingest($PNG_BYTES . 'pub', 'public.png', 'image/png', $owner->key);
check($public->is_public(), 'an unrestricted file is public');

harness_finish();
