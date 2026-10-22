<?php
/** @joinery-test
 * name: zip_writer_js
 * tier: safe
 * parallel: true
 * env: any
 * needs: [node]
 */
/**
 * PURPOSE: The browser's zip writer (assets/js/zip-writer.js), which zips a
 * Fortress Drive folder in the tab, makes archives PHP's reader takes byte for
 * byte, and names files exactly as the server's ZipStreamWriter does.
 *
 *   - deflated and stored files, an empty file, an empty folder, UTF-8 names,
 *     in the classic and the forced ZIP64 form
 *   - JoineryZip.Parts: each part a whole zip, rolling over at the part size,
 *     and the parts together hold every file
 *   - cleanName, compressible and uniqueName agree with the PHP side
 *
 * Runs the real file under node (which has CompressionStream).
 *
 * Run:  php tests/unit/zip_writer_js_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$js = PathHelper::getIncludePath('assets/js/zip-writer.js');
$dir = sys_get_temp_dir() . '/zwjs_' . bin2hex(random_bytes(4));
mkdir($dir);
harness_defer(function () use ($dir) {
	foreach (glob($dir . '/*') as $f) { @unlink($f); }
	@rmdir($dir);
});

$names = array('a/b\\c', "tab\there", '..', '.', '  ', 'Résumé: draft?.txt', 'IMG.JPG', 'a.mp4', 'x.docx', 'notes.txt', 'noext');
$driver = <<<'JS'
const fs = require('fs');
const Z = require(process.argv[2]);
const dir = process.argv[3];
const names = JSON.parse(process.argv[4]);
(async () => {
  const d = new Date(Date.UTC(2025, 9, 9, 8, 53, 20));
  const files = {
    'Top/hello.txt': Buffer.from('Hello zip\n'.repeat(2000)),
    'Top/Zoë Ångström ✓.md': Buffer.from('accents ✓\n'),
    'Top/empty.txt': Buffer.alloc(0),
    'Top/Sub/photo.jpg': require('crypto').randomBytes(200000),
  };
  for (const n in files) fs.writeFileSync(dir + '/src-' + Buffer.from(n).toString('hex'), files[n]);
  for (const [tag, opts] of [['classic', {}], ['zip64', { forceZip64: true }]]) {
    const fd = fs.openSync(dir + '/' + tag + '.zip', 'w');
    const w = new Z.Writer({ write: (u8) => { fs.writeSync(fd, u8); } }, opts);
    await w.addDirectory('Top/', d);
    await w.addDirectory('Top/Empty', d);
    for (const n in files) await w.addFile(n, new Uint8Array(files[n]), { date: d });
    const size = await w.finish();
    fs.closeSync(fd);
    if (size !== fs.statSync(dir + '/' + tag + '.zip').size) throw new Error('size mismatch ' + tag);
  }
  // Five 60 KB incompressible files into 130 KB parts: two per part, then one.
  const p = new Z.Parts({ partBytes: 130000, openPart: (n) => {
    const fd = fs.openSync(dir + '/part-' + n + '.zip', 'w');
    return { write: (u8) => { fs.writeSync(fd, u8); }, close: () => fs.closeSync(fd) };
  } });
  await p.addDirectory('P/', d);
  for (let i = 1; i <= 5; i++) {
    const b = require('crypto').randomBytes(60000);
    fs.writeFileSync(dir + '/pf' + i, b);
    await p.addFile('P/f' + i + '.bin', new Uint8Array(b), { date: d });
  }
  const parts = await p.finish();
  const taken = {};
  const unique = ['Report.txt', 'report.txt', 'REPORT.TXT', 'Notes', 'notes'].map((n) => Z.uniqueName(n, taken));
  console.log(JSON.stringify({
    parts,
    clean: names.map(Z.cleanName),
    compress: names.map((n) => Z.compressible(n)),
    mime: [Z.compressible('blob', 'image/png'), Z.compressible('drawing', 'image/svg+xml')],
    unique,
    crc: Z.crc32(new TextEncoder().encode('The quick brown fox jumps over the lazy dog')),
  }));
})().catch((e) => { console.error(e && e.stack || e); process.exit(1); });
JS;
$script = $dir . '/driver.js';
file_put_contents($script, $driver);
$out = shell_exec('node ' . escapeshellarg($script) . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($dir) . ' '
	. escapeshellarg(json_encode($names)) . ' 2>&1');
$res = json_decode(trim((string)$out), true);
check(is_array($res), 'the writer runs under node' . (is_array($res) ? '' : ': ' . trim((string)$out)));
if (!is_array($res)) {
	harness_finish();
}

$sources = array();
foreach (glob($dir . '/src-*') as $f) {
	$sources[hex2bin(substr(basename($f), 4))] = file_get_contents($f);
}

// ---------------------------------------------------------------------------
foreach (array('classic', 'zip64') as $tag) {
	section('the ' . $tag . ' form');
	$zip = new ZipArchive();
	$ok = $zip->open($dir . '/' . $tag . '.zip', ZipArchive::CHECKCONS) === true;
	check($ok, $tag . ': PHP\'s reader opens it and finds it consistent');
	if (!$ok) { continue; }
	check($zip->numFiles === 6, $tag . ': 6 entries (2 folders, 4 files)');
	check($zip->locateName('Top/Empty/') !== false, $tag . ': the empty folder is a directory entry');
	$bad = array();
	foreach ($sources as $name => $bytes) {
		$s = $zip->statName($name);
		if (!$s || $zip->getFromName($name) !== $bytes || $s['crc'] !== hexdec(hash('crc32b', $bytes)) || $s['size'] !== strlen($bytes)) {
			$bad[] = $name;
		}
	}
	check(!$bad, $tag . ': every file byte for byte, with its checksum and size' . ($bad ? ' (' . implode(', ', $bad) . ')' : ''));
	check($zip->statName('Top/hello.txt')['comp_method'] === ZipArchive::CM_DEFLATE, $tag . ': text is deflated');
	check($zip->statName('Top/Sub/photo.jpg')['comp_method'] === ZipArchive::CM_STORE, $tag . ': a photo is stored');
	check($zip->statName('Top/hello.txt')['mtime'] === 1760000000, $tag . ': the time is the UTC instant');
	$zip->close();
}

// ---------------------------------------------------------------------------
section('parts');
check($res['parts'] === 3, 'five 60 KB files in 130 KB parts make 3 parts (got ' . $res['parts'] . ')');
$seen = array();
for ($n = 1; $n <= (int)$res['parts']; $n++) {
	$zip = new ZipArchive();
	$ok = $zip->open($dir . '/part-' . $n . '.zip', ZipArchive::CHECKCONS) === true;
	check($ok, 'part ' . $n . ' is a whole zip');
	if (!$ok) { continue; }
	check(filesize($dir . '/part-' . $n . '.zip') <= 130000 + 1000, 'part ' . $n . ' stays near the part size');
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);
		if (preg_match('#^P/f(\d)\.bin$#', $name, $m)) {
			$seen[(int)$m[1]] = ($zip->getFromName($name) === file_get_contents($dir . '/pf' . $m[1]));
		}
	}
	$zip->close();
}
ksort($seen);
check(array_keys($seen) === array(1, 2, 3, 4, 5) && !in_array(false, $seen, true), 'together the parts hold every file, intact');

// ---------------------------------------------------------------------------
section('the same names as the server');
$php_clean = array_map(array('ZipStreamWriter', 'cleanName'), $names);
check($res['clean'] === $php_clean, 'cleanName agrees with ZipStreamWriter::cleanName');
$php_compress = array_map(function ($n) { return ZipStreamWriter::compressible($n); }, $names);
check($res['compress'] === $php_compress, 'compressible agrees on names');
check($res['mime'] === array(false, true), 'and on types');
check($res['unique'] === array('Report.txt', 'report (2).txt', 'REPORT (3).TXT', 'Notes', 'notes (2)'),
	'siblings that differ only in case are numbered, before the extension');
check($res['crc'] === 0x414FA339, 'crc32 matches the standard check value');

harness_finish();
