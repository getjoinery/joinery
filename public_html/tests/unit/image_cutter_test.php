<?php
/** @joinery-test
 * name: image_cutter
 * tier: safe
 * env: any
 * needs: []
 * timeout: 180
 */

/**
 * ImageCutter (specs/image_decode_in_the_jail.md): every size the site cuts
 * from an uploaded image is made in the extraction subprocess, fenced where
 * the parser jail is installed. Pinned with images the test paints itself:
 *
 *   - one call answers every size asked for, in the source's own format, at
 *     the registered geometry, for JPEG, PNG, WebP and GIF
 *   - a JPEG much larger than needed goes through the shrink (two fenced runs
 *     around a fenced djpeg) and comes back the same sizes; skipped without djpeg
 *   - an EXIF orientation comes back upright
 *   - a GIF's transparency survives the thread-free palette step
 *   - over the ceiling, not an image, a truncated file and a file over the
 *     source limit are refusals in words, with the figures, and nothing decoded
 *   - a BMP upload is not decoded (the only BMP read is the shrink's own)
 *   - where the fence is installed, the decode ran as another user: this
 *     process's own readers were never called
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = harness_scratch_dir('image_cutter');
harness_defer(function () {
	ImageDecoder::set_ceiling_for_tests(null);
	ImageDecoder::set_djpeg_for_tests(null);
});

function cutter_paint(int $w, int $h) {
	$im = imagecreatetruecolor($w, $h);
	imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
	$bw = max(2, (int)($w / 4)); $bh = max(2, (int)($h / 4));
	imagefilledrectangle($im, 0, 0, $bw - 1, $bh - 1, imagecolorallocate($im, 255, 0, 0));
	imagefilledrectangle($im, $w - $bw, 0, $w - 1, $bh - 1, imagecolorallocate($im, 0, 255, 0));
	imagefilledrectangle($im, 0, $h - $bh, $bw - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
	return $im;
}

/** Which of red/green/blue/white the pixel at a fraction of the image is. */
function cutter_colour($img, float $fx, float $fy): string {
	$c = imagecolorat($img, (int)(imagesx($img) * $fx), (int)(imagesy($img) * $fy));
	if (!imageistruecolor($img)) {
		$p = imagecolorsforindex($img, $c);
		$r = $p['red']; $g = $p['green']; $b = $p['blue'];
	} else {
		$r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
	}
	if ($r > 180 && $g < 90 && $b < 90) return 'red';
	if ($g > 180 && $r < 90 && $b < 90) return 'green';
	if ($b > 180 && $r < 90 && $g < 90) return 'blue';
	if ($r > 180 && $g > 180 && $b > 180) return 'white';
	return "rgb($r,$g,$b)";
}

/** The same JPEG bytes with an EXIF APP1 segment carrying only Orientation. */
function cutter_with_orientation(string $jpeg, int $o): string {
	$tiff = "II*\0" . pack('V', 8) . pack('v', 1)
		. pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $o) . "\0\0"
		. pack('V', 0);
	$app1 = "Exif\0\0" . $tiff;
	return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
}

$sizes = array(
	'thumb' => array('width' => 80, 'height' => 80, 'crop' => true, 'quality' => 85),
	'fit'   => array('width' => 400, 'height' => 300, 'crop' => false, 'quality' => 85),
);
ImageDecoder::set_ceiling_for_tests(160);

// ---------------------------------------------------------------------------
section('one call answers every size, in the source\'s own format');

$im = cutter_paint(1200, 800);
$sources = array();
imagejpeg($im, $dir . '/a.jpg', 90);  $sources['a.jpg'] = IMAGETYPE_JPEG;
imagepng($im, $dir . '/a.png');       $sources['a.png'] = IMAGETYPE_PNG;
if (function_exists('imagewebp')) { imagewebp($im, $dir . '/a.webp', 90); $sources['a.webp'] = IMAGETYPE_WEBP; }
$pal = cutter_paint(1200, 800); imagetruecolortopalette($pal, false, 255); imagegif($pal, $dir . '/a.gif'); $sources['a.gif'] = IMAGETYPE_GIF;
unset($im, $pal);

foreach ($sources as $name => $type) {
	ImageDecoder::$decode_count = 0;
	try {
		$cut = ImageCutter::cut($dir . '/' . $name, $sizes, 400, 300);
	} catch (ImageDecodeRefused $e) {
		check(false, $name . ': cut', $e->getMessage());
		continue;
	}
	check($cut['type'] === $type && array_keys($cut['variants']) === array('thumb', 'fit'), $name . ': both sizes came back, typed as the source',
		$cut['type'] . ' ' . implode(',', array_keys($cut['variants'])));
	check(ImageDecoder::$decode_count === 1, $name . ': one decode for every size');
	$t = @getimagesizefromstring($cut['variants']['thumb']);
	$f = @getimagesizefromstring($cut['variants']['fit']);
	check($t && $t[0] === 80 && $t[1] === 80 && $t[2] === $type, $name . ': the cropped size is 80x80 in the source\'s format', json_encode($t));
	check($f && $f[0] === 400 && $f[1] === 267 && $f[2] === $type, $name . ': the fitted size keeps the aspect inside 400x300', json_encode($f));
	$fit = @imagecreatefromstring($cut['variants']['fit']);
	check($fit && cutter_colour($fit, 0.05, 0.05) === 'red' && cutter_colour($fit, 0.95, 0.05) === 'green' && cutter_colour($fit, 0.05, 0.95) === 'blue'
		&& cutter_colour($fit, 0.5, 0.5) === 'white', $name . ': the picture is the picture', $fit ? cutter_colour($fit, 0.05, 0.05) : 'unreadable');
}

// ---------------------------------------------------------------------------
section('a JPEG much larger than needed is decoded already shrunk');

$big = cutter_paint(4800, 3200);
imagejpeg($big, $dir . '/big.jpg', 85);
unset($big);
if (ImageDecoder::djpeg_path() === null) {
	harness_skip('decode through djpeg', 'djpeg is not installed (libjpeg-turbo-progs)');
} else {
	// 4800x3200 in full is 59 MB; at a 30 MB ceiling only the shrunk decode fits.
	ImageDecoder::set_ceiling_for_tests(30);
	try {
		$cut = ImageCutter::cut($dir . '/big.jpg', $sizes, 400, 300);
		$f = @getimagesizefromstring($cut['variants']['fit'] ?? '');
		check($f && $f[0] === 400 && $f[1] === 267, 'the sizes come back from the shrunk decode, under a ceiling a full decode does not fit', json_encode($f));
		$fit = @imagecreatefromstring($cut['variants']['fit']);
		check($fit && cutter_colour($fit, 0.05, 0.05) === 'red' && cutter_colour($fit, 0.95, 0.05) === 'green', 'and the picture is the picture');
	} catch (ImageDecodeRefused $e) {
		check(false, 'the sizes come back from the shrunk decode', $e->getMessage());
	}
	ImageDecoder::set_djpeg_for_tests('');
	try {
		ImageCutter::cut($dir . '/big.jpg', $sizes, 400, 300);
		check(false, 'without djpeg the same photo is refused at that ceiling');
	} catch (ImageDecodeRefused $e) {
		check($e->estimate_mb === 59 && $e->ceiling_mb === 30 && $e->transient === false, 'without djpeg the same photo is refused at that ceiling, with the figures', $e->getMessage());
	}
	ImageDecoder::set_djpeg_for_tests(null);
	ImageDecoder::set_ceiling_for_tests(160);
}

// ---------------------------------------------------------------------------
section('an EXIF orientation comes back upright');

$land = cutter_paint(600, 400);
ob_start(); imagejpeg($land, null, 90); $jpeg = (string)ob_get_clean(); unset($land);
file_put_contents($dir . '/o6.jpg', cutter_with_orientation($jpeg, 6));   // stored on its side: upright it is 400 wide, 600 tall
try {
	$cut = ImageCutter::cut($dir . '/o6.jpg', array('fit' => array('width' => 200, 'height' => 300, 'crop' => false, 'quality' => 90)), 200, 300);
	$f = @getimagesizefromstring($cut['variants']['fit'] ?? '');
	check($f && $f[0] === 200 && $f[1] === 300, 'orientation 6 comes back portrait', json_encode($f));
	$up = @imagecreatefromstring($cut['variants']['fit']);
	// Turned a quarter clockwise, the stored top-left (red) is the upright top-right.
	check($up && cutter_colour($up, 0.95, 0.03) === 'red', 'with the stored top-left corner at the upright top-right', $up ? cutter_colour($up, 0.95, 0.03) : 'unreadable');
} catch (ImageDecodeRefused $e) {
	check(false, 'orientation 6 comes back portrait', $e->getMessage());
}

// ---------------------------------------------------------------------------
section('a GIF keeps its transparency');

$g = imagecreatetruecolor(300, 200);
imagealphablending($g, false); imagesavealpha($g, true);
imagefill($g, 0, 0, imagecolorallocatealpha($g, 0, 0, 0, 127));
imagefilledellipse($g, 150, 100, 150, 100, imagecolorallocate($g, 255, 0, 0));
imagetruecolortopalette($g, false, 255);
imagecolortransparent($g, imagecolorclosestalpha($g, 0, 0, 0, 127));
imagegif($g, $dir . '/tr.gif'); unset($g);
try {
	$cut = ImageCutter::cut($dir . '/tr.gif', array('fit' => array('width' => 150, 'height' => 100, 'crop' => false, 'quality' => 85)), 150, 100);
	$out = @imagecreatefromstring($cut['variants']['fit'] ?? '');
	$tidx = $out ? imagecolortransparent($out) : -1;
	check($out && $tidx >= 0 && imagecolorat($out, 2, 2) === $tidx, 'the corner is the transparent entry', 'transparent=' . $tidx);
	check($out && cutter_colour($out, 0.5, 0.5) === 'red', 'and the middle is the picture');
} catch (ImageDecodeRefused $e) {
	check(false, 'the corner is the transparent entry', $e->getMessage());
}

// ---------------------------------------------------------------------------
section('refusals are words, with the figures, and nothing is decoded');

ImageDecoder::set_ceiling_for_tests(1);
ImageDecoder::$decode_count = 0;
try {
	ImageCutter::cut($dir . '/a.png', $sizes, 400, 300);
	check(false, 'over the ceiling is refused');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'too large to make sizes from') !== false && $e->estimate_mb === 4 && $e->ceiling_mb === 1 && !$e->transient,
		'over the ceiling is refused with the estimate and the limit', $e->getMessage());
}
check(ImageDecoder::$decode_count === 0, 'and nothing was decoded');
ImageDecoder::set_ceiling_for_tests(160);

file_put_contents($dir . '/junk.jpg', str_repeat('not an image ', 50));
try {
	ImageCutter::cut($dir . '/junk.jpg', $sizes, 400, 300);
	check(false, 'not an image is refused');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'not an image the site can read') !== false, 'not an image is refused, in words', $e->getMessage());
}
file_put_contents($dir . '/cut_short.jpg', substr((string)file_get_contents($dir . '/a.jpg'), 0, 900));
try {
	$cut = ImageCutter::cut($dir . '/cut_short.jpg', $sizes, 400, 300);
	check(true, 'a truncated JPEG is cut from what there is, or refused, never a crash');
} catch (ImageDecodeRefused $e) {
	check(true, 'a truncated JPEG is cut from what there is, or refused, never a crash', $e->getMessage());
}
$bm = cutter_paint(200, 100); imagebmp($bm, $dir . '/a.bmp'); unset($bm);
try {
	ImageCutter::cut($dir . '/a.bmp', $sizes, 400, 300);
	check(false, 'a BMP is not decoded');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'is not one the site decodes') !== false, 'a BMP is not decoded: only the shrink\'s own BMP is ever read', $e->getMessage());
}
try {
	ImageCutter::cut($dir . '/missing.jpg', $sizes, 400, 300);
	check(false, 'a missing file is refused');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'missing or unreadable') !== false, 'a missing file is refused', $e->getMessage());
}
$fh = fopen($dir . '/huge.png', 'wb'); ftruncate($fh, ImageCutter::MAX_SOURCE_BYTES + 1); fclose($fh);
try {
	ImageCutter::cut($dir . '/huge.png', $sizes, 400, 300);
	check(false, 'a file over the source limit is refused');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'the limit is 48 MB') !== false, 'a file over the source limit is refused before it is read', $e->getMessage());
}
@unlink($dir . '/huge.png');

// ---------------------------------------------------------------------------
section('the decode ran somewhere else');

$probe = DocumentText::parseWith('ImageCutter', (string)file_get_contents($dir . '/a.jpg'),
	array('sizes' => array(), 'max_w' => 10, 'max_h' => 10, 'ceiling_mb' => 160, 'shrink' => false));
check($probe['status'] === DocumentText::OK && is_array(json_decode($probe['text'], true)), 'the cutter answers through the extraction subprocess', (string)$probe['detail']);
if (DocumentText::jailAvailable() && DocumentText::jailFenced()) {
	$tool = DocumentText::runTool(array('/usr/bin/id', '-u'), '', 10, 268435456);
	check($tool['fenced'] === true && $tool['exit'] === 0 && trim($tool['stdout']) !== (string)posix_getuid(),
		'a fenced tool runs as another user', 'uid ' . trim($tool['stdout']) . ' vs ' . posix_getuid() . ' ' . trim($tool['stderr']));
} else {
	harness_skip('a fenced tool runs as another user', 'the parser jail is not installed here: the subprocess runs as this user');
}

harness_finish();
