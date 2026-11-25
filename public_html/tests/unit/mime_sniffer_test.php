<?php
/** @joinery-test
 * name: mime_sniffer
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 */

/**
 * A file's type is never asked of libmagic in this process
 * (specs/mime_sniff_outside_the_worker.md). Pinned:
 *
 *   - the commonest types are answered from their leading bytes with no
 *     question asked at all, and that answer is the one libmagic gives
 *   - everything else is asked of the file program, fenced where the parser
 *     jail is installed, and comes back with the types callers rely on
 *   - without the file program the extraction subprocess answers instead
 *   - a question that fails, or an answer that is not a type, is unknown bytes
 *   - a PDF marker past the start is libmagic's to judge, with the looser
 *     sniff only behind its "unknown"
 *   - no file outside the fenced side names libmagic (parser_surfaces_test
 *     holds the rule; this checks the two functions)
 *
 * @version 1.1 - nobody-could-be-asked is told from unknown (two tries); a blob kept as unknown is asked again
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = harness_scratch_dir('mime_sniffer');
harness_defer(function () { MimeSniffer::$file_override = null; });

$im = imagecreatetruecolor(40, 30);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
$images = array();
ob_start(); imagepng($im); $images['image/png'] = (string)ob_get_clean();
ob_start(); imagejpeg($im); $images['image/jpeg'] = (string)ob_get_clean();
ob_start(); imagegif($im); $images['image/gif'] = (string)ob_get_clean();
if (function_exists('imagewebp')) { ob_start(); imagewebp($im); $images['image/webp'] = (string)ob_get_clean(); }
if (function_exists('imageavif')) { ob_start(); @imageavif($im); $avif = (string)ob_get_clean(); if ($avif !== '') $images['image/avif'] = $avif; }
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

// ---------------------------------------------------------------------------
section('the commonest types are answered from their leading bytes, with no question asked');

MimeSniffer::$asked = array('tool' => 0, 'php' => 0);
foreach ($images + array('application/pdf' => $pdf) as $type => $bytes) {
	check(File::detect_mime_bytes($bytes) === $type, $type . ' is told from its first bytes', (string)File::detect_mime_bytes($bytes));
	$path = $dir . '/' . md5($type);
	file_put_contents($path, $bytes);
	check(File::detect_mime_file($path) === $type, $type . ' from a file too');
}
check(MimeSniffer::$asked === array('tool' => 0, 'php' => 0), 'and nothing left this process to find out', json_encode(MimeSniffer::$asked));

// The shortcut may only say what libmagic would have said.
foreach ($images + array('application/pdf' => $pdf) as $type => $bytes) {
	check(MimeSniffer::sniff($bytes) === $type, 'libmagic agrees: ' . $type, MimeSniffer::sniff($bytes));
}

// ---------------------------------------------------------------------------
section('everything else is asked outside this process');

$others = array(
	'text/x-php'               => "<?php echo 1; ?>\n",
	'text/plain'               => "just some words\non two lines\n",
	'text/html'                => "<!DOCTYPE html><html><head><title>x</title></head><body><p>hi</p></body></html>",
	'application/json'         => '{"a": 1, "b": [true, null]}',
	'image/svg+xml'            => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
	'application/octet-stream' => "\x00\x01\x02\x03\xFE\xFD\xFC\x9A\x00\x00\x07\x7F" . str_repeat("\x13\x37\x00\x9C", 40),
);
MimeSniffer::$asked = array('tool' => 0, 'php' => 0);
foreach ($others as $type => $bytes) {
	$got = (string)File::detect_mime_bytes($bytes);
	check($got === $type, 'asked and answered: ' . $type, $got);
}
check(array_sum(MimeSniffer::$asked) === count($others), 'one question each', json_encode(MimeSniffer::$asked));
$fenced = DocumentText::jailAvailable() && DocumentText::jailFenced();
if (is_executable('/usr/bin/file')) {
	check(MimeSniffer::$asked['tool'] === count($others) && MimeSniffer::$asked['php'] === 0, 'of the file program');
	if ($fenced) {
		$magic = realpath('/usr/lib/file/magic.mgc') ?: realpath('/usr/share/misc/magic.mgc');
		$r = DocumentText::runTool(array('/usr/bin/file', '--mime-type', '-b', '-m', (string)$magic, '-'), "<?php 1;", 10, 268435456, true, 4096, array((string)$magic));
		check($r['fenced'] === true && $r['exit'] === 0 && trim($r['stdout']) === 'text/x-php', 'which runs inside the fence with its magic database and nothing else', json_encode(array($r['exit'], trim($r['stdout']), trim($r['stderr']))));
		$r = DocumentText::runTool(array('/usr/bin/file', '--mime-type', '-b', PathHelper::getSiteRoot() . '/config/Globalvars_site.php'), '', 10, 268435456, true, 4096);
		check(strpos($r['stdout'] . $r['stderr'], 'text/x-php') === false, 'and cannot be pointed at the site\'s config file', trim($r['stdout'] . ' ' . $r['stderr']));
	} else {
		harness_skip('the file program inside the fence', 'the parser jail is not installed here');
	}
} else {
	harness_skip('the file program route', 'file is not installed on this machine (package file)');
}
$path = $dir . '/words.txt';
file_put_contents($path, $others['text/plain']);
check(File::detect_mime_file($path) === 'text/plain', 'a file on disk is asked the same way');
check(File::detect_mime_file($dir . '/not_there') === null, 'a path that cannot be read is null: a broken server, not a property of the file');

// ---------------------------------------------------------------------------
section('without the file program the extraction subprocess answers');

MimeSniffer::$file_override = '';
MimeSniffer::$asked = array('tool' => 0, 'php' => 0);
$got = (string)File::detect_mime_bytes($others['text/x-php']);
check($got === 'text/x-php', 'the same answer, from PHP in the subprocess', $got);
check(MimeSniffer::$asked['php'] >= 1 && MimeSniffer::$asked['tool'] === 0, 'by the other route', json_encode(MimeSniffer::$asked));
MimeSniffer::$file_override = null;

// ---------------------------------------------------------------------------
section('a question that could not be asked is told apart from an answer of unknown');

foreach (array(
	'exits non-zero'       => "#!/bin/sh\necho 'text/html'\nexit 3\n",
	'answers nonsense'     => "#!/bin/sh\ncat >/dev/null\necho 'ERROR: cannot open (No such file)'\n",
	'answers nothing'      => "#!/bin/sh\ncat >/dev/null\n",
	'answers far too much' => "#!/bin/sh\ncat >/dev/null\nhead -c 200000 /dev/zero | tr '\\\\0' 'a'\n",
	'is killed'            => "#!/bin/sh\nkill -9 \$\$\n",
) as $label => $script) {
	$fake = $dir . '/file_' . md5($label) . '.sh';
	file_put_contents($fake, $script);
	chmod($fake, 0755);
	MimeSniffer::$file_override = $fake;
	MimeSniffer::$asked = array('tool' => 0, 'php' => 0);
	$got = (string)File::detect_mime_bytes($others['text/html']);
	$nobody = in_array($label, array('exits non-zero', 'answers far too much', 'is killed'), true);
	check($got === 'application/octet-stream', 'a file program that ' . $label . ' leaves the bytes unknown', $got);
	check(File::$mime_was_unasked === $nobody, $nobody ? 'and says nobody could be asked, after asking twice' : 'and that is an answer, asked once',
		json_encode(array(File::$mime_was_unasked, MimeSniffer::$asked)));
	check(MimeSniffer::$asked['tool'] === ($nobody ? 2 : 1), 'asked ' . ($nobody ? 'twice' : 'once'), json_encode(MimeSniffer::$asked));
}
MimeSniffer::$file_override = null;
File::detect_mime_bytes($images['image/png']);
check(File::$mime_was_unasked === false, 'a type told from its leading bytes clears the mark');
$fake = $dir . '/file_125.sh';
file_put_contents($fake, "#!/bin/sh\nexit 125\n");
chmod($fake, 0755);
MimeSniffer::$file_override = $fake;
check(MimeSniffer::sniff('x') === null, 'a stand-in that exits 125 is not the launcher refusing: no second, unfenced run is made of it');
$fake = $dir . '/file_params.sh';
file_put_contents($fake, "#!/bin/sh\ncat >/dev/null\necho 'Text/HTML; charset=utf-8'\n");
chmod($fake, 0755);
MimeSniffer::$file_override = $fake;
check(File::detect_mime_bytes($others['text/plain']) === 'text/html', 'an answer is lowercased and loses its parameters');
MimeSniffer::$file_override = null;

// ---------------------------------------------------------------------------
section('a PDF marker past the start is libmagic\'s to judge');

$prose = "A note about the format.\n\nEvery such file starts with %PDF-1.x, and the rest follows.\n";
check(File::leading_signature($prose) === null, 'the leading-bytes shortcut leaves a text that mentions the marker alone');
$got = (string)File::detect_mime_bytes($prose);
check($got === MimeSniffer::sniff($prose), 'and the answer is libmagic\'s own, whatever it makes of it', $got . ' vs ' . MimeSniffer::sniff($prose));
$late = str_repeat("\x00", 2000) . $pdf;
check(File::leading_signature($late) === null && File::detect_mime_bytes($late) === 'application/pdf',
	'bytes libmagic cannot name, holding a PDF header further in, are still found by the looser sniff', (string)File::detect_mime_bytes($late));

// ---------------------------------------------------------------------------
section('a blob kept as unknown is asked again when its bytes next arrive');

$blob_src = (string)file_get_contents(PathHelper::getIncludePath('data/file_blobs_class.php'));
check(preg_match('/self::retain\(\$existing->key\)\) \{\s*self::_ask_type_again\(\$existing, \$src_path\);\s*@unlink\(\$src_path\);/', $blob_src) === 1,
	'the dedup path asks again before the arriving bytes are dropped');
check(strpos($blob_src, "!== 'application/octet-stream') {\n\t\t\treturn;") !== false && strpos($blob_src, 'File::$mime_was_unasked') !== false,
	'only for a blob stored as unknown, and only an answer somebody gave is kept');

// ---------------------------------------------------------------------------
section('libmagic is not named on this side');

$src = (string)file_get_contents(PathHelper::getIncludePath('data/files_class.php'));
check(!preg_match('/^[^*\n]*\b(finfo_open|finfo_file|finfo_buffer|mime_content_type)\s*\(/m', $src), 'File calls no libmagic function itself');
$sniffer = (string)file_get_contents(PathHelper::getIncludePath('includes/MimeSniffer.php'));
$at = strpos($sniffer, 'THE FENCED SIDE');
check($at !== false && !preg_match('/\b(finfo_open|finfo_buffer|finfo_file)\s*\(/', substr($sniffer, 0, $at)), 'and MimeSniffer only on its fenced side');
$composer = json_decode((string)file_get_contents(PathHelper::getRootDir() . '/composer.json'), true);
check(in_array('file', $composer['extra']['joinery-system-packages'] ?? array(), true), 'the file program is a declared system package, installed at every root moment');

harness_finish();
