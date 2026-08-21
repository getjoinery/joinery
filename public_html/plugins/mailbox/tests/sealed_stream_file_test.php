<?php
/** @joinery-test
 * name: sealed_stream_file
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The streaming sealed-file format (specs/mailbox_search_index_streaming_seal.md
 * § 3.1–3.2): SealedBox::sealStreamFile/openStreamFile must round-trip a
 * multi-megabyte file byte-identically in memory bounded by a chunk, and must
 * fail closed — wrong key, wrong AD, truncation, a flipped ciphertext byte, or
 * trailing data each throw and leave no destination file behind. The
 * VaultCrypto wrappers add the hot-turn contract: openFieldFile() arms
 * SealedEgressGuard exactly as openField() does.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$box = new SealedBox();
$crypto = new VaultCrypto();

$work = sys_get_temp_dir() . '/sealed_stream_test_' . bin2hex(random_bytes(4));
mkdir($work, 0777, true);
$cleanup = array();

$make_file = function (string $name, int $bytes) use ($work, &$cleanup) {
	$path = $work . '/' . $name;
	$fh = fopen($path, 'wb');
	// A repeating-but-random megabyte block: multi-chunk content without ever
	// holding the whole file as a string in the TEST either.
	$block = random_bytes(1048576);
	$written = 0;
	while ($written < $bytes) {
		$slice = min(strlen($block), $bytes - $written);
		fwrite($fh, substr($block, 0, $slice));
		$written += $slice;
	}
	fclose($fh);
	$cleanup[] = $path;
	return $path;
};

$key = random_bytes(32);
$ad = 'mail:ftsindex:12345';

// ---------------------------------------------------------------- roundtrip

section('a multi-chunk file round-trips byte-identically');

$plain = $make_file('plain.bin', 3670016); // 3.5 MiB — multi-chunk, not chunk-aligned
$sealed = $work . '/sealed.stream';
$opened = $work . '/opened.bin';
$cleanup[] = $sealed;
$cleanup[] = $opened;

$box->sealStreamFile($plain, $sealed, $key, $ad);
check(is_file($sealed), 'the sealed file exists');
check(SealedBox::isStreamFile($sealed), 'and carries the v1.stream. magic');
check(!SealedBox::isStreamFile($plain), 'the plaintext file does not');

$box->openStreamFile($sealed, $opened, $key, $ad);
check(hash_file('sha256', $opened) === hash_file('sha256', $plain),
	'opened bytes are identical to the original');

section('an empty file round-trips too');

$empty = $make_file('empty.bin', 0);
$empty_sealed = $work . '/empty.stream';
$empty_opened = $work . '/empty.out';
$cleanup[] = $empty_sealed;
$cleanup[] = $empty_opened;
$box->sealStreamFile($empty, $empty_sealed, $key, $ad);
$box->openStreamFile($empty_sealed, $empty_opened, $key, $ad);
check(is_file($empty_opened) && filesize($empty_opened) === 0, 'zero bytes in, zero bytes out');

// ---------------------------------------------------------------- fail closed

section('every corruption throws and leaves no destination file');

$expect_refused = function (string $label, string $src) use ($box, $key, $ad, $work) {
	$dst = $work . '/refused_' . bin2hex(random_bytes(3)) . '.out';
	$threw = false;
	try {
		$box->openStreamFile($src, $dst, $key, $ad);
	} catch (RuntimeException $e) {
		$threw = true;
	}
	check($threw, $label . ' throws');
	check(!is_file($dst), $label . ' leaves no destination file');
	check(count(glob($dst . '.opening.*')) === 0, $label . ' leaves no temp file');
};

$wrong_key_dst = $work . '/wrongkey.out';
$threw = false;
try {
	$box->openStreamFile($sealed, $wrong_key_dst, random_bytes(32), $ad);
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'a wrong key throws');
check(!is_file($wrong_key_dst), 'a wrong key leaves no destination file');

$wrong_ad_dst = $work . '/wrongad.out';
$threw = false;
try {
	$box->openStreamFile($sealed, $wrong_ad_dst, $key, 'mail:ftsindex:99999');
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'an AD mismatch throws (the splice defense holds per frame)');
check(!is_file($wrong_ad_dst), 'an AD mismatch leaves no destination file');

// Truncated tail — the FINAL frame is cut, so the loss is detectable.
$truncated = $work . '/truncated.stream';
copy($sealed, $truncated);
$cleanup[] = $truncated;
$fh = fopen($truncated, 'r+b');
ftruncate($fh, filesize($truncated) - 10);
fclose($fh);
$expect_refused('a truncated tail', $truncated);

// One flipped ciphertext byte in the middle of a frame.
$flipped = $work . '/flipped.stream';
copy($sealed, $flipped);
$cleanup[] = $flipped;
$fh = fopen($flipped, 'r+b');
fseek($fh, 100);
$byte = fread($fh, 1);
fseek($fh, 100);
fwrite($fh, chr(ord($byte) ^ 0xFF));
fclose($fh);
$expect_refused('a flipped ciphertext byte', $flipped);

// Data appended after the FINAL frame is not silently ignored.
$trailing = $work . '/trailing.stream';
copy($sealed, $trailing);
$cleanup[] = $trailing;
file_put_contents($trailing, random_bytes(16), FILE_APPEND);
$expect_refused('trailing data after the FINAL frame', $trailing);

// A plain file that is not stream-format at all (a legacy v1.aead. text blob).
$legacy = $work . '/legacy.blob';
file_put_contents($legacy, $box->aeadEncrypt('legacy whole-blob content', $key, $ad));
$cleanup[] = $legacy;
check(!SealedBox::isStreamFile($legacy), 'isStreamFile is false for a v1.aead. text blob');
$expect_refused('opening a non-stream file', $legacy);

// ---------------------------------------------------------------- bounded memory

section('memory stays proportional to a chunk, never the file');

$big = $make_file('big.bin', 12 * 1048576); // 12x the chunk size
$big_sealed = $work . '/big.stream';
$big_opened = $work . '/big.out';
$cleanup[] = $big_sealed;
$cleanup[] = $big_opened;

$before = memory_get_peak_usage(true);
$box->sealStreamFile($big, $big_sealed, $key, $ad);
$box->openStreamFile($big_sealed, $big_opened, $key, $ad);
$delta = memory_get_peak_usage(true) - $before;
check($delta < 6 * 1048576,
	'sealing and opening a 12 MiB file grows peak memory by less than half the file (a few chunks at most)',
	'peak delta ' . $delta . ' bytes');
check(hash_file('sha256', $big_opened) === hash_file('sha256', $big),
	'and the big file still round-trips byte-identically');

// ---------------------------------------------------------------- the hot-turn contract

section('openFieldFile arms the hot-turn rule; sealFieldFile does not');

SealedEgressGuard::reset();
$dek = $crypto->newItemDek();
$vc_sealed = $work . '/vc.stream';
$vc_opened = $work . '/vc.out';
$cleanup[] = $vc_sealed;
$cleanup[] = $vc_opened;
$crypto->sealFieldFile($plain, $vc_sealed, $dek, $ad);
check(!SealedEgressGuard::isHot(), 'sealing through VaultCrypto leaves the process cold');
$crypto->openFieldFile($vc_sealed, $vc_opened, $dek, $ad);
check(SealedEgressGuard::isHot(), 'opening stored sealed content through VaultCrypto makes the process hot');
check(in_array($ad, SealedEgressGuard::sources(), true), 'and records the AD as the opened source');
SealedEgressGuard::reset();

foreach ($cleanup as $path) {
	if (is_file($path)) {
		@unlink($path);
	}
}
@rmdir($work);

harness_finish();
