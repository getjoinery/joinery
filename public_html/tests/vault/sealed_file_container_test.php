<?php
/** @joinery-test
 * name: sealed_file_container
 * tier: safe
 * env: any
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SealedFileContainer.php'));

$work = sys_get_temp_dir() . '/sfc_test_' . bin2hex(random_bytes(4));
@mkdir($work, 0777, true);
harness_defer(function () use ($work) {
	foreach (glob($work . '/*') as $f) { @unlink($f); }
	@rmdir($work);
});

/** Deterministic filler, so a recovered range can be asserted without holding a copy. */
function sfc_pattern($size, $seed = 31) {
	$out = '';
	for ($i = 0; $i < $size; $i++) { $out .= chr(($i * $seed + 7) & 0xff); }
	return $out;
}

function sfc_write($work, $name, $bytes) {
	$p = $work . '/' . $name;
	file_put_contents($p, $bytes);
	return $p;
}

$fk = random_bytes(32);
$CHUNK = SealedFileContainer::CHUNK_BYTES;

// ---------------------------------------------------------------------------
section('round trip');

// Sizes that exercise every boundary the chunk arithmetic can get wrong: empty,
// tiny, one byte under / exactly / one byte over a chunk, and a multi-chunk file
// with a short tail.
$sizes = array(
	'empty'          => 0,
	'tiny'           => 1,
	'small'          => 5000,
	'chunk_minus_1'  => $CHUNK - 1,
	'exact_chunk'    => $CHUNK,
	'chunk_plus_1'   => $CHUNK + 1,
	'two_and_a_bit'  => (2 * $CHUNK) + 12345,
);

$sealed_paths = array();
foreach ($sizes as $label => $size) {
	$plain = sfc_pattern($size);
	$src   = sfc_write($work, 'plain_' . $label, $plain);
	$dest  = $work . '/sealed_' . $label;

	$info = SealedFileContainer::sealStream($src, $dest, $fk);
	$sealed_paths[$label] = $dest;

	check($info['plain_size'] === $size, "$label: sealStream reports the plaintext size ($size)");
	check(is_file($dest) && filesize($dest) === $info['cipher_size'], "$label: reported cipher size matches the file");
	check(file_get_contents($dest) !== $plain, "$label: the container is not the plaintext");
	check(SealedFileContainer::looksSealed($dest), "$label: looksSealed() recognizes it");
	check(SealedFileContainer::plainSize($dest) === $size, "$label: plainSize() derives $size from the framing alone");
	check(SealedFileContainer::cipherSizeFor($size) === $info['cipher_size'], "$label: cipherSizeFor() predicts the on-disk size");

	$back = SealedFileContainer::openString($dest, $fk);
	check($back === $plain, "$label: open returns the exact plaintext");
	unset($plain, $back);
}

section('header');

$h = SealedFileContainer::readHeader($sealed_paths['small']);
check($h['version'] === SealedFileContainer::VERSION, 'header carries the format version');
check($h['chunk_bytes'] === $CHUNK, 'header carries the plaintext chunk size');
check(strlen($h['content_id']) === 32, 'a minted content id is 32 hex characters');

$fixed = SealedFileContainer::sealStream(
	sfc_write($work, 'plain_cid', 'hello'), $work . '/sealed_cid', $fk, 'my-content-id');
check(SealedFileContainer::readHeader($work . '/sealed_cid')['content_id'] === 'my-content-id',
	'a caller-supplied content id round-trips through the header');
check($fixed['content_id'] === 'my-content-id', 'sealStream echoes the content id it used');

$not_a_container = sfc_write($work, 'plain_junk', str_repeat('x', 200));
check(!SealedFileContainer::looksSealed($not_a_container), 'looksSealed() is false for ordinary bytes');
$threw = false;
try { SealedFileContainer::openString($not_a_container, $fk); } catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'opening something that is not a container throws');

// ---------------------------------------------------------------------------
section('ranges');

$size  = (2 * $CHUNK) + 12345;
$path  = $sealed_paths['two_and_a_bit'];
$plain = sfc_pattern($size);

$ranges = array(
	'from the start'            => array(0, 100),
	'inside the first chunk'    => array(1000, 500),
	'up to the first seam'      => array($CHUNK - 50, 50),
	'across the first seam'     => array($CHUNK - 50, 100),
	'across the second seam'    => array((2 * $CHUNK) - 10, 60),
	'a whole middle chunk'      => array($CHUNK, $CHUNK),
	'spanning all three chunks' => array($CHUNK - 5, $CHUNK + 20),
	'the tail'                  => array($size - 200, 200),
	'past the end, clamped'     => array($size - 10, 999),
);
foreach ($ranges as $label => $r) {
	list($off, $len) = $r;
	$got = SealedFileContainer::openString($path, $fk, $off, $len);
	$want = substr($plain, $off, $len);
	check($got === $want, "range $label (offset $off, length $len) decrypts correctly");
}

check(SealedFileContainer::openString($path, $fk, $size, 100) === '', 'a range starting at EOF returns nothing');
check(strlen(SealedFileContainer::openString($path, $fk, 0, null)) === $size, 'a null length reads to the end');

// A range inside one chunk must read only that chunk — the reason a Range
// request for the tail of a large file is cheap. Counted by sink invocations.
$pieces = 0;
SealedFileContainer::openRange($path, $fk, function ($b) use (&$pieces) { $pieces++; }, (2 * $CHUNK) + 10, 100);
check($pieces === 1, 'a range inside one chunk emits from exactly one chunk');

$pieces = 0;
SealedFileContainer::openRange($path, $fk, function ($b) use (&$pieces) { $pieces++; }, $CHUNK - 5, 10);
check($pieces === 2, 'a range across a seam reads exactly the two chunks it spans');

unset($plain);

// ---------------------------------------------------------------------------
section('authentication');

$wrong = random_bytes(32);
$threw = false;
try { SealedFileContainer::openString($sealed_paths['small'], $wrong); } catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'the wrong key fails authentication instead of returning garbage');

// Flip one byte of ciphertext in the second chunk. A file that still opens after
// this would mean the tag is not covering the bytes.
$tampered = $work . '/tampered';
copy($sealed_paths['two_and_a_bit'], $tampered);
$fh = fopen($tampered, 'r+b');
$hdr_len = SealedFileContainer::headerLength(32);
$at = $hdr_len + ($CHUNK + SealedFileContainer::CHUNK_OVERHEAD) + 100; // inside chunk 1
fseek($fh, $at);
$byte = fread($fh, 1);
fseek($fh, $at);
fwrite($fh, chr(ord($byte) ^ 0xff));
fclose($fh);

check(SealedFileContainer::openString($tampered, $fk, 0, 100) === sfc_pattern(0 + 100),
	'chunk 0 still opens — tampering is detected per chunk, not per file');
$threw = false;
try { SealedFileContainer::openString($tampered, $fk, $CHUNK, 100); } catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'the tampered chunk fails its tag');

// Swap two whole chunks: each is individually valid, but the AAD binds the index.
$swapped = $work . '/swapped';
$raw = file_get_contents($sealed_paths['two_and_a_bit']);
$block = $CHUNK + SealedFileContainer::CHUNK_OVERHEAD;
$head  = substr($raw, 0, $hdr_len);
$c0    = substr($raw, $hdr_len, $block);
$c1    = substr($raw, $hdr_len + $block, $block);
$rest  = substr($raw, $hdr_len + (2 * $block));
file_put_contents($swapped, $head . $c1 . $c0 . $rest);
unset($raw, $c0, $c1, $rest);
$threw = false;
try { SealedFileContainer::openString($swapped, $fk, 0, 100); } catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'reordering two valid chunks fails — the chunk index is bound into the AAD');

// Move a chunk into a different file. Same key, same position, different content
// id: the transplant must fail.
$other = $work . '/other_file';
SealedFileContainer::sealStream(sfc_write($work, 'plain_other', sfc_pattern(5000, 17)), $other, $fk);
$graft = $work . '/graft';
$a = file_get_contents($sealed_paths['small']);
$b = file_get_contents($other);
file_put_contents($graft, substr($a, 0, $hdr_len) . substr($b, $hdr_len));
$threw = false;
try { SealedFileContainer::openString($graft, $fk); } catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'a chunk transplanted into another container fails — the content id is bound into the AAD');
unset($a, $b);

// A truncated container must raise rather than quietly serving a short file.
$truncated = $work . '/truncated';
file_put_contents($truncated, substr(file_get_contents($sealed_paths['small']), 0, 900));
$threw = false;
try { SealedFileContainer::openString($truncated, $fk); } catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'a truncated container raises instead of returning a short read');

section('key handling');

$threw = false;
try { SealedFileContainer::sealStream($not_a_container, $work . '/nope', 'too-short'); }
catch (SealedFileContainerException $e) { $threw = true; }
check($threw, 'a key that is not 32 raw bytes is refused');

// ---------------------------------------------------------------------------
section('browser format compatibility');

// The container is the browser's Fortress chunk scheme with a header in front.
// That claim is only worth anything if something reads what a browser actually
// wrote — so the fixture is produced by running assets/js/drive-crypto.js
// itself (tests/tools/make_drive_container_fixture.mjs), not by restating the
// format here.
$fixture_dir  = __DIR__ . '/../fixtures/drive';
$fixture_bin  = $fixture_dir . '/drive_fortress_container.bin';
$fixture_meta = $fixture_dir . '/drive_fortress_container.json';

if (!is_file($fixture_bin) || !is_file($fixture_meta)) {
	harness_skip('browser fixture missing',
		'regenerate with: node tests/tools/make_drive_container_fixture.mjs tests/fixtures/drive 3000');
} else {
	$meta = json_decode(file_get_contents($fixture_meta), true);
	$bfk  = base64_decode($meta['file_key_b64']);
	$want = sfc_pattern((int)$meta['plain_size']);

	check($meta['chunk_bytes'] === $CHUNK,
		'the browser and PHP agree on the plaintext chunk size');
	check(hash('sha256', $want) === $meta['plain_sha256'],
		'the fixture plaintext is the pattern this test reproduces');

	$got = '';
	SealedFileContainer::openBrowserBody($fixture_bin, $bfk, $meta['content_id'], $meta['chunk_bytes'],
		function ($b) use (&$got) { $got .= $b; });
	check($got === $want, 'PHP opens a container the browser produced');

	$got = '';
	SealedFileContainer::openBrowserBody($fixture_bin, $bfk, $meta['content_id'], $meta['chunk_bytes'],
		function ($b) use (&$got) { $got .= $b; }, 1000, 250);
	check($got === substr($want, 1000, 250), 'a range read works against browser-produced bytes');

	$threw = false;
	try {
		SealedFileContainer::openBrowserBody($fixture_bin, $bfk, 'a-different-content-id', $meta['chunk_bytes'],
			function ($b) {});
	} catch (SealedFileContainerException $e) { $threw = true; }
	check($threw, 'the browser body is bound to its content id too');

	// Multi-chunk browser output is 8 MB, too heavy to freeze in git — generate
	// one when node is here, and say plainly when it is not.
	$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
	if ($node === '') {
		harness_skip('multi-chunk browser cross-check',
			'node is not installed; the single-chunk fixture still pins the framing');
	} else {
		$gen_dir = $work . '/gen';
		@mkdir($gen_dir, 0777, true);
		$tool = PathHelper::getIncludePath('tests/tools/make_drive_container_fixture.mjs');
		$size = ($CHUNK * 2) + 4321;
		@shell_exec(escapeshellcmd($node) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($gen_dir)
			. ' ' . (int)$size . ' multichunk 2>&1');
		if (!is_file($gen_dir . '/multichunk.bin')) {
			harness_skip('multi-chunk browser cross-check', 'the fixture generator produced nothing');
		} else {
			$m = json_decode(file_get_contents($gen_dir . '/multichunk.json'), true);
			$got = '';
			SealedFileContainer::openBrowserBody($gen_dir . '/multichunk.bin', base64_decode($m['file_key_b64']),
				$m['content_id'], $m['chunk_bytes'], function ($b) use (&$got) { $got .= $b; });
			check(hash('sha256', $got) === $m['plain_sha256'],
				'PHP opens a MULTI-chunk browser container (chunk indices agree across implementations)');

			$got = '';
			SealedFileContainer::openBrowserBody($gen_dir . '/multichunk.bin', base64_decode($m['file_key_b64']),
				$m['content_id'], $m['chunk_bytes'], function ($b) use (&$got) { $got .= $b; }, $CHUNK - 5, 10);
			check($got === substr(sfc_pattern($size), $CHUNK - 5, 10),
				'a seam-crossing range works against multi-chunk browser bytes');
		}
	}
}

harness_finish();
