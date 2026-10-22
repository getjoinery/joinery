<?php
/** @joinery-test
 * name: zip_stream_writer
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * PURPOSE: ZipStreamWriter's archives open in every reader at hand, byte for
 * byte, in both the classic and the ZIP64 form (docs/drive.md § Downloading a
 * folder).
 *
 *   - deflated and stored files, an empty file, an empty folder, UTF-8 names,
 *     a file written in many pieces; contents and checksums through PHP's
 *     reader, and through unzip and Python's zipfile where installed
 *   - the ZIP64 form (forced: 4 GiB is not written here), which the readers
 *     must take the same way
 *   - names cleaned to one path segment; already-compressed formats stored
 *   - times before 1980 clamp to the format's first day
 *   - writes out of order are refused rather than producing a broken archive
 *
 * Run:  php tests/unit/zip_stream_writer_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$has_unzip  = trim((string)shell_exec('command -v unzip')) !== '';
$has_python = trim((string)shell_exec('command -v python3')) !== '';

function zsw_build(array $options, &$files) {
	$path = tempnam(sys_get_temp_dir(), 'zsw_');
	harness_defer(function () use ($path) { @unlink($path); });
	$fh = fopen($path, 'wb');
	$z = new ZipStreamWriter(function ($b) use ($fh) { fwrite($fh, $b); }, $options);
	$t = 1760000000;
	$files = array(
		'Top/hello.txt'           => str_repeat("Hello zip\n", 2000),
		'Top/Zoë Ångström ✓.md'   => "accents ✓\n",
		'Top/empty.txt'           => '',
		'Top/Sub/photo.jpg'       => random_bytes(200000),
		'Top/Sub/pieces.txt'      => str_repeat('0123456789abcdef', 70000),
	);
	$z->addDirectory('Top/', $t);
	$z->addDirectory('Top/Empty', $t);
	$z->addFile('Top/hello.txt', $files['Top/hello.txt'], $t);
	$z->addFile('Top/Zoë Ångström ✓.md', $files['Top/Zoë Ångström ✓.md'], $t);
	$z->addFile('Top/empty.txt', '', $t);
	$z->beginFile('Top/Sub/photo.jpg', $t, ZipStreamWriter::compressible('photo.jpg'), 200000);
	foreach (str_split($files['Top/Sub/photo.jpg'], 65536) as $piece) { $z->write($piece); }
	$z->endFile();
	$z->beginFile('Top/Sub/pieces.txt', $t, true, strlen($files['Top/Sub/pieces.txt']));
	foreach (str_split($files['Top/Sub/pieces.txt'], 4096) as $piece) { $z->write($piece); }
	$z->endFile();
	$size = $z->finish();
	fclose($fh);
	check($size === filesize($path), 'finish() reports the bytes written');
	return $path;
}

function zsw_read($label, $path, array $files, $has_unzip, $has_python, $zip64) {
	$zip = new ZipArchive();
	$ok = $zip->open($path, ZipArchive::CHECKCONS) === true;
	check($ok, $label . ': PHP\'s reader opens it and finds it consistent');
	if (!$ok) { return; }
	check($zip->numFiles === 7, $label . ': 7 entries (2 folders, 5 files)');
	check($zip->locateName('Top/Empty/') !== false, $label . ': the empty folder is a directory entry');
	$bad = array();
	foreach ($files as $name => $bytes) {
		$s = $zip->statName($name);
		if (!$s || $zip->getFromName($name) !== $bytes || $s['crc'] !== hexdec(hash('crc32b', $bytes)) || $s['size'] !== strlen($bytes)) {
			$bad[] = $name;
		}
	}
	check(!$bad, $label . ': every file byte for byte, with its checksum and size' . ($bad ? ' (' . implode(', ', $bad) . ')' : ''));
	check($zip->statName('Top/hello.txt')['comp_method'] === ZipArchive::CM_DEFLATE, $label . ': text is deflated');
	check($zip->statName('Top/Sub/photo.jpg')['comp_method'] === ZipArchive::CM_STORE, $label . ': a photo is stored');
	check($zip->statName('Top/empty.txt')['comp_method'] === ZipArchive::CM_STORE, $label . ': an empty file is stored');
	$zip->close();

	if ($has_unzip) {
		$out = (string)shell_exec('unzip -tq ' . escapeshellarg($path) . ' 2>&1');
		check(strpos($out, 'No errors detected') !== false, $label . ': unzip -t finds no errors');
		$v = (string)shell_exec('zipinfo -v ' . escapeshellarg($path) . ' 2>&1');
		check(strpos($v, 'minimum software version required to extract:   ' . ($zip64 ? '4.5' : '2.0')) !== false,
			$label . ': entries ask for version ' . ($zip64 ? '4.5 (ZIP64)' : '2.0'));
	}
	if ($has_python) {
		$py = "import zipfile,sys,hashlib\nz=zipfile.ZipFile(sys.argv[1])\nprint('bad' if z.testzip() else 'ok')\n"
			. "print(hashlib.sha256(z.read('Top/Sub/pieces.txt')).hexdigest())\nprint(z.getinfo('Top/hello.txt').date_time)";
		$out = (string)shell_exec('python3 -I -c ' . escapeshellarg($py) . ' ' . escapeshellarg($path) . ' 2>&1');
		$lines = explode("\n", trim($out));
		check(($lines[0] ?? '') === 'ok', $label . ': Python\'s zipfile checks every CRC');
		check(($lines[1] ?? '') === hash('sha256', $files['Top/Sub/pieces.txt']), $label . ': Python reads the piecewise file back');
		check(($lines[2] ?? '') === '(2025, 10, 9, 8, 53, 20)', $label . ': the DOS time is the UTC time');
	}
}

// ---------------------------------------------------------------------------
section('the classic form');
$p = zsw_build(array(), $files);
zsw_read('classic', $p, $files, $has_unzip, $has_python, false);
check(strpos(file_get_contents($p), pack('V', ZipStreamWriter::SIG_END64)) === false, 'classic: no ZIP64 end record');

// ---------------------------------------------------------------------------
section('the ZIP64 form');
$p64 = zsw_build(array('force_zip64' => true), $files64);
zsw_read('zip64', $p64, $files64, $has_unzip, $has_python, true);
check(strpos(file_get_contents($p64), pack('V', ZipStreamWriter::SIG_END64)) !== false, 'zip64: the ZIP64 end record is written');

// ---------------------------------------------------------------------------
section('names and formats');
check(ZipStreamWriter::cleanName('a/b\\c') === 'a_b_c', 'separators become underscores');
check(ZipStreamWriter::cleanName("tab\there") === 'tab_here', 'control characters become underscores');
check(ZipStreamWriter::cleanName('..') === '_' && ZipStreamWriter::cleanName('.') === '_' && ZipStreamWriter::cleanName('  ') === '_',
	'., .. and blank never name a segment');
check(ZipStreamWriter::cleanName('Résumé: draft?.txt') === 'Résumé: draft?.txt', 'everything else is kept');
check(!ZipStreamWriter::compressible('IMG.JPG') && !ZipStreamWriter::compressible('a.mp4') && !ZipStreamWriter::compressible('x.docx'),
	'photos, video and Office files are stored');
check(ZipStreamWriter::compressible('notes.txt') && ZipStreamWriter::compressible('data.csv') && ZipStreamWriter::compressible('noext'),
	'text and unknown files are deflated');
check(!ZipStreamWriter::compressible('blob', 'image/png') && ZipStreamWriter::compressible('drawing', 'image/svg+xml'),
	'the type decides when the name has no extension');

// ---------------------------------------------------------------------------
section('times and order');
$buf = '';
$z = new ZipStreamWriter(function ($b) use (&$buf) { $buf .= $b; });
$z->addFile('old.txt', 'old', 0);
$z->finish();
$tmp = tempnam(sys_get_temp_dir(), 'zsw_t_');
harness_defer(function () use ($tmp) { @unlink($tmp); });
file_put_contents($tmp, $buf);
$zip = new ZipArchive();
check($zip->open($tmp) === true && $zip->statName('old.txt')['mtime'] > 0, 'a 1970 time clamps to 1980 and still opens');
$zip->close();

$z = new ZipStreamWriter(function ($b) {});
$threw = false;
try { $z->write('x'); } catch (LogicException $e) { $threw = true; }
check($threw, 'write() with no file begun is refused');
$z->beginFile('a.txt', null, true, 1);
$threw = false;
try { $z->addDirectory('d/'); } catch (LogicException $e) { $threw = true; }
check($threw, 'a new entry while a file is open is refused');
$z->write('a');
$z->endFile();
$n = $z->finish();
check($z->finish() === $n, 'finish() twice writes nothing more');
$threw = false;
try { $z->addFile('late.txt', 'x'); } catch (LogicException $e) { $threw = true; }
check($threw, 'nothing is added after finish()');

harness_finish();
