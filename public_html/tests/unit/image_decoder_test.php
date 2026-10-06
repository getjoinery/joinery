<?php
/** @joinery-test
 * name: image_decoder
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 */

/**
 * ImageDecoder (specs/image_decode_memory.md WP1): the one place an image
 * becomes pixels decodes it once, upright, no larger than the sizes the site
 * cuts from it, and refuses from the header when the decode would cost more
 * than the ceiling. Pinned with images the test paints itself:
 *
 *   - the header read sees dimensions, EXIF orientation and progressive encoding
 *   - a large JPEG decodes already shrunk through djpeg, and the process grows by
 *     a fraction of a full decode (measured from /proc; skipped where djpeg is absent)
 *   - the shrink keeps both dimensions: a portrait source still covers a landscape hero
 *   - a source smaller than the largest size is neither shrunk nor enlarged
 *   - every EXIF orientation 2-8 comes back upright, with the swapped dimensions
 *     (checked against ImageMagick's autoOrient when this run was written)
 *   - a progressive JPEG is refused at a ceiling it does not fit and opens at one it does
 *   - PNG opens in full; a large PNG is refused by the ceiling
 *   - not an image, and a truncated JPEG, are refused with a reason, not a GD warning
 *   - ImageSizeRegistry::max_dimensions() is the pair the decoder is given
 *   - the ceiling follows the memory budget (the plan's split, pinned to _memory_plan.sh),
 *     under the setting as a cap; a 256 MB site refuses 24 MP progressive, takes baseline
 *   - a child killed by a signal is out of memory and retryable; stopped by the limit is
 *     recorded; rejecting the file is damage
 *
 * @version 1.1 - budget-derived ceiling, signal-killed child
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = harness_scratch_dir('image_decoder');
harness_defer(function () {
	ImageDecoder::set_ceiling_for_tests(null);
	ImageDecoder::set_budget_for_tests(null);
	ImageDecoder::set_djpeg_for_tests(null);
});

// ---------------------------------------------------------------- helpers

/** A JPEG with corner marks: red top-left, green top-right, blue bottom-left. */
function painted_jpeg(string $path, int $w, int $h, bool $progressive = false): void {
	$im = imagecreatetruecolor($w, $h);
	imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
	$bw = max(2, (int)($w / 4)); $bh = max(2, (int)($h / 4));
	imagefilledrectangle($im, 0, 0, $bw - 1, $bh - 1, imagecolorallocate($im, 255, 0, 0));
	imagefilledrectangle($im, $w - $bw, 0, $w - 1, $bh - 1, imagecolorallocate($im, 0, 255, 0));
	imagefilledrectangle($im, 0, $h - $bh, $bw - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
	imageinterlace($im, $progressive);
	imagejpeg($im, $path, 90);
	unset($im);
}

/** The same JPEG bytes with an EXIF APP1 segment carrying only Orientation. */
function with_exif_orientation(string $jpeg, int $o): string {
	$tiff = "II*\0" . pack('V', 8) . pack('v', 1)
		. pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $o) . "\0\0"
		. pack('V', 0);
	$app1 = "Exif\0\0" . $tiff;
	return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
}

/** Which of red/green/blue/white the pixel at a fraction of the image is. */
function colour_at($img, float $fx, float $fy): string {
	$x = (int)(imagesx($img) * $fx); $y = (int)(imagesy($img) * $fy);
	$c = imagecolorat($img, $x, $y);
	$r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
	if ($r > 180 && $g < 90 && $b < 90) return 'red';
	if ($g > 180 && $r < 90 && $b < 90) return 'green';
	if ($b > 180 && $r < 90 && $g < 90) return 'blue';
	if ($r > 180 && $g > 180 && $b > 180) return 'white';
	return "rgb($r,$g,$b)";
}

function hwm_mb(): float {
	preg_match('/VmHWM:\s+(\d+)/', (string)file_get_contents('/proc/self/status'), $m);
	return ((int)($m[1] ?? 0)) / 1024;
}

$big = $dir . '/big.jpg';
$big_prog = $dir . '/big_progressive.jpg';
if (!is_file($big)) painted_jpeg($big, 6000, 4000);
if (!is_file($big_prog)) painted_jpeg($big_prog, 6000, 4000, true);
// Until libjpeg-turbo-progs is installed on a box, JOINERY_DJPEG=/path/to/djpeg
// runs the shrink path against a copy of the program unpacked anywhere.
$djpeg_override = getenv('JOINERY_DJPEG') ?: null;
if ($djpeg_override !== null) ImageDecoder::set_djpeg_for_tests($djpeg_override);
$djpeg = ImageDecoder::djpeg_path();

// ---------------------------------------------------------------- header

section('the header read sees what a decode needs to know');
$h = ImageDecoder::read_header($big);
check($h['width'] === 6000 && $h['height'] === 4000 && $h['type'] === IMAGETYPE_JPEG, 'dimensions and type', json_encode($h));
check($h['orientation'] === 1 && $h['progressive'] === false, 'no orientation, baseline');
$h = ImageDecoder::read_header($big_prog);
check($h['progressive'] === true, 'a progressive JPEG is recognised from its SOF2 marker');
$o6 = $dir . '/o6.jpg';
file_put_contents($o6, with_exif_orientation(file_get_contents($big), 6));
$h = ImageDecoder::read_header($o6);
check($h['orientation'] === 6 && $h['progressive'] === false, 'EXIF orientation read; a C2 byte inside the EXIF segment is not taken for SOF2');

// ---------------------------------------------------------------- shrink

section('a large JPEG decodes already shrunk, at a fraction of the memory');
ImageDecoder::set_ceiling_for_tests(160);
if ($djpeg === null) {
	harness_skip('decode through djpeg', 'djpeg is not installed (libjpeg-turbo-progs); the full-decode path is what runs here');
	$d = ImageDecoder::open($big, 1920, 1080);
	check($d->method === 'gd' && $d->width === 6000, 'without djpeg the full image is decoded', $d->method . ' ' . $d->width);
	unset($d);
} else {
	$before = hwm_mb();
	$d = ImageDecoder::open($big, 1920, 1080);
	$grew = hwm_mb() - $before;
	check($d->method === 'djpeg', 'decoded through djpeg', $d->method);
	check($d->width === 2250 && $d->height === 1500, '6000x4000 at 3/8: the smallest M/8 that keeps 1920x1080', $d->width . 'x' . $d->height);
	check($grew < 40, 'the process grew by less than 40 MB (a full decode is 92)', round($grew) . ' MB');
	check(colour_at($d->image, 0.05, 0.05) === 'red' && colour_at($d->image, 0.95, 0.05) === 'green'
		&& colour_at($d->image, 0.05, 0.95) === 'blue', 'the pixels are the photo\'s');
	unset($d);
	if (!is_file($dir . '/big_portrait.jpg')) painted_jpeg($dir . '/big_portrait.jpg', 4000, 6000);
	$d = ImageDecoder::open($dir . '/big_portrait.jpg', 1920, 1080);
	check($d->width === 2000 && $d->height === 3000, 'a 4000x6000 portrait gets 4/8, so its width still covers a 1920-wide crop', $d->width . 'x' . $d->height);
	unset($d);
	check(count(glob($dir . '/*.bmp')) === 0 && count(glob(PathHelper::getSiteRoot() . '/cache/image_decode_*')) === 0, 'no temp BMP is left behind');
}
$small = $dir . '/small.jpg';
painted_jpeg($small, 400, 300);
$d = ImageDecoder::open($small, 1920, 1080);
check($d->width === 400 && $d->height === 300 && $d->method === 'gd', 'a source smaller than the largest size is neither shrunk nor enlarged', $d->width . 'x' . $d->height . ' ' . $d->method);
unset($d);

// ---------------------------------------------------------------- orientation

section('every EXIF orientation comes back upright');
$base = $dir . '/o_base.jpg';
painted_jpeg($base, 40, 24);
$bytes = file_get_contents($base);
// Where the red (top-left), green (top-right) and blue (bottom-left) marks of
// the stored pixels land once the image is shown upright, per the EXIF table.
$expect = array(
	1 => array('red' => array(0.05, 0.05), 'green' => array(0.95, 0.05), 'blue' => array(0.05, 0.95), 'size' => '40x24'),
	2 => array('red' => array(0.95, 0.05), 'green' => array(0.05, 0.05), 'blue' => array(0.95, 0.95), 'size' => '40x24'),
	3 => array('red' => array(0.95, 0.95), 'green' => array(0.05, 0.95), 'blue' => array(0.95, 0.05), 'size' => '40x24'),
	4 => array('red' => array(0.05, 0.95), 'green' => array(0.95, 0.95), 'blue' => array(0.05, 0.05), 'size' => '40x24'),
	5 => array('red' => array(0.05, 0.05), 'green' => array(0.05, 0.95), 'blue' => array(0.95, 0.05), 'size' => '24x40'),
	6 => array('red' => array(0.95, 0.05), 'green' => array(0.95, 0.95), 'blue' => array(0.05, 0.05), 'size' => '24x40'),
	7 => array('red' => array(0.95, 0.95), 'green' => array(0.95, 0.05), 'blue' => array(0.05, 0.95), 'size' => '24x40'),
	8 => array('red' => array(0.05, 0.95), 'green' => array(0.05, 0.05), 'blue' => array(0.95, 0.95), 'size' => '24x40'),
);
foreach ($expect as $o => $e) {
	$p = $dir . '/orient' . $o . '.jpg';
	file_put_contents($p, with_exif_orientation($bytes, $o));
	$d = ImageDecoder::open($p, 40, 24);   // the full size: orientation alone
	$got = array();
	foreach (array('red', 'green', 'blue') as $name) {
		$got[$name] = colour_at($d->image, $e[$name][0], $e[$name][1]);
	}
	check($d->width . 'x' . $d->height === $e['size'] && $got === array('red' => 'red', 'green' => 'green', 'blue' => 'blue'),
		'orientation ' . $o . ' upright, ' . $e['size'], $d->width . 'x' . $d->height . ' ' . json_encode($got));
	unset($d);
}
if ($djpeg !== null) {
	$d = ImageDecoder::open($dir . '/orient6.jpg', 10, 10);   // shrunk to 4/8, then turned
	check($d->width === 12 && $d->height === 20 && $d->method === 'djpeg'
		&& colour_at($d->image, 0.95, 0.05) === 'red' && colour_at($d->image, 0.95, 0.95) === 'green' && colour_at($d->image, 0.05, 0.05) === 'blue',
		'shrink and orientation compose: orientation 6 at 4/8 is 12x20 and upright', $d->width . 'x' . $d->height . ' ' . $d->method);
	unset($d);
}

// ---------------------------------------------------------------- ceiling

section('the ceiling refuses from the header, before any pixels');
ImageDecoder::set_djpeg_for_tests('');   // the full-decode estimate, whatever the machine has
ImageDecoder::set_ceiling_for_tests(60);
$before = hwm_mb();
try {
	ImageDecoder::open($big, 1920, 1080);
	check(false, 'a 92 MB decode is refused at a 60 MB ceiling');
} catch (ImageDecodeRefused $e) {
	check($e->estimate_mb === 92 && $e->ceiling_mb === 60, 'a 92 MB decode is refused at a 60 MB ceiling', $e->getMessage());
	check(strpos($e->getMessage(), 'saved as uploaded') !== false, 'the reason reads for the person uploading', $e->getMessage());
}
check(hwm_mb() - $before < 2, 'nothing was allocated for the refusal', round(hwm_mb() - $before) . ' MB');
ImageDecoder::set_ceiling_for_tests(100);
try {
	ImageDecoder::open($big_prog, 1920, 1080);
	check(false, 'a progressive 24 MP JPEG (229 MB in full) is refused at 100');
} catch (ImageDecodeRefused $e) {
	check($e->estimate_mb === 229 && strpos($e->getMessage(), 'progressive') !== false, 'a progressive 24 MP JPEG (229 MB in full) is refused at 100', $e->getMessage());
}
ImageDecoder::set_djpeg_for_tests($djpeg_override);
if ($djpeg !== null) {
	ImageDecoder::set_ceiling_for_tests(160);
	$d = ImageDecoder::open($big_prog, 1920, 1080);
	check($d->method === 'djpeg' && $d->width === 2250, 'and opens at 160 through djpeg, which keeps the coefficient buffer in the child', $d->method . ' ' . $d->width);
	unset($d);
	ImageDecoder::set_ceiling_for_tests(100);
	try {
		ImageDecoder::open($big_prog, 1920, 1080);
		check(false, 'through djpeg it is still refused at 100: the coefficient buffer counts, shrunk or not');
	} catch (ImageDecodeRefused $e) {
		check($e->estimate_mb > 100, 'through djpeg it is still refused at 100: the coefficient buffer counts, shrunk or not', $e->getMessage());
	}
}
ImageDecoder::set_ceiling_for_tests(160);

section('the ceiling follows the memory budget, under the setting as a cap');
ImageDecoder::set_ceiling_for_tests(null);
$plan = (string)file_get_contents(PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/_memory_plan.sh');
preg_match('/^MEMORY_PLAN_BASE_MB=(\d+)/m', $plan, $pb);
check((int)($pb[1] ?? 0) === ImageDecoder::PLAN_BASE_MB, 'the base the plan reserves is the one the decoder reserves', ($pb[1] ?? '?') . ' vs ' . ImageDecoder::PLAN_BASE_MB);
check(preg_match('/mb=\$\(\( \$1 \/ 5 \)\)/', $plan) === 1 && preg_match('/-lt 64 \]/', $plan) === 1 && preg_match('/-gt 2048 \]/', $plan) === 1,
	'shared_buffers is a fifth of the budget, clamped 64..2048, in the plan as in the decoder');
$cases = array(256 => 64, 384 => 180, 512 => 282, 192 => 24, 128 => 24, 8192 => 6426, 16384 => 14208);
$got = array();
foreach ($cases as $budget => $room) {
	ImageDecoder::set_budget_for_tests($budget);
	$got[$budget] = ImageDecoder::decode_room_mb();
}
check($got === $cases, 'room = budget - shared_buffers - 128, floor 24 (256 MB: 64, 512 MB: 282)', json_encode($got));
ImageDecoder::set_budget_for_tests(256);
check(ImageDecoder::ceiling_mb() === min(64, ImageDecoder::setting_mb()), 'on a 256 MB site the ceiling is the room, 64 MB, not the setting', (string)ImageDecoder::ceiling_mb());
ImageDecoder::set_budget_for_tests(8192);
check(ImageDecoder::ceiling_mb() === ImageDecoder::setting_mb(), 'on a big box the setting caps it', (string)ImageDecoder::ceiling_mb());
ImageDecoder::set_budget_for_tests(false);
check(ImageDecoder::ceiling_mb() === ImageDecoder::setting_mb(), 'with no readable budget the setting alone applies');
ImageDecoder::set_budget_for_tests(256);
ImageDecoder::set_djpeg_for_tests($djpeg_override);
try {
	ImageDecoder::open($big_prog, 1920, 1080);
	check(false, 'a 256 MB site refuses a 24 MP progressive JPEG, naming its memory as the reason');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'this site\'s memory allows 64 MB') !== false && !$e->transient,
		'a 256 MB site refuses a 24 MP progressive JPEG, naming its memory as the reason', $e->getMessage());
}
if ($djpeg !== null) {
	$d = ImageDecoder::open($big, 1920, 1080);
	check($d->method === 'djpeg' && $d->width === 2250, 'and still takes a 24 MP baseline JPEG, shrunk (about 13 MB)');
	unset($d);
}
ImageDecoder::set_budget_for_tests(null);
ImageDecoder::set_ceiling_for_tests(160);

section('a decoder child killed by a signal is out of memory, not damage, and retryable');
$fake = $dir . '/djpeg_killed.sh';
file_put_contents($fake, "#!/bin/sh\nkill -9 \$\$\n");
chmod($fake, 0755);
ImageDecoder::set_djpeg_for_tests($fake);
try {
	ImageDecoder::open($big, 1920, 1080);
	check(false, 'a SIGKILLed child is reported as the site out of memory');
} catch (ImageDecodeRefused $e) {
	check($e->transient === true && strpos($e->getMessage(), 'out of memory') !== false && strpos($e->getMessage(), 'damaged') === false,
		'a SIGKILLed child is reported as the site out of memory, transient', $e->getMessage());
}
$fake2 = $dir . '/djpeg_nomem.sh';
file_put_contents($fake2, "#!/bin/sh\necho 'Insufficient memory (case 4)' >&2\nexit 1\n");
chmod($fake2, 0755);
ImageDecoder::set_djpeg_for_tests($fake2);
try {
	ImageDecoder::open($big, 1920, 1080);
	check(false, 'a child stopped by the address-space limit is over the ceiling, recorded');
} catch (ImageDecodeRefused $e) {
	check($e->transient === false && strpos($e->getMessage(), 'more than this site can spare') !== false,
		'a child stopped by the address-space limit is over the ceiling, recorded', $e->getMessage());
}
$fake3 = $dir . '/djpeg_broken.sh';
file_put_contents($fake3, "#!/bin/sh\necho 'Not a JPEG file' >&2\nexit 2\n");
chmod($fake3, 0755);
ImageDecoder::set_djpeg_for_tests($fake3);
try {
	ImageDecoder::open($big, 1920, 1080);
	check(false, 'a child that rejects the file is damage');
} catch (ImageDecodeRefused $e) {
	check($e->transient === false && strpos($e->getMessage(), 'damaged') !== false, 'a child that rejects the file is damage', $e->getMessage());
}
ImageDecoder::set_djpeg_for_tests($djpeg_override);

section('other formats open in full under the same ceiling');
$png = $dir . '/small.png';
$im = imagecreatetruecolor(300, 200);
imagefilledrectangle($im, 0, 0, 299, 199, imagecolorallocate($im, 0, 0, 255));
imagepng($im, $png);
unset($im);
$d = ImageDecoder::open($png, 1920, 1080);
check($d->type === IMAGETYPE_PNG && $d->width === 300 && $d->method === 'gd', 'a PNG opens in full', $d->width);
unset($d);
$big_png = $dir . '/big.png';
if (!is_file($big_png)) {
	$im = imagecreatetruecolor(5000, 4000);
	imagepng($im, $big_png, 1);
	unset($im);
}
ImageDecoder::set_ceiling_for_tests(60);
try {
	ImageDecoder::open($big_png, 1920, 1080);
	check(false, 'a 20 MP PNG (80 MB) is refused at a 60 MB ceiling');
} catch (ImageDecodeRefused $e) {
	check($e->estimate_mb === 77, 'a 20 MP PNG (77 MB) is refused at a 60 MB ceiling', $e->getMessage());
}
ImageDecoder::set_ceiling_for_tests(160);

section('what is not a decodable image is refused with a reason');
file_put_contents($dir . '/not_an_image.jpg', 'hello, this is text');
try {
	ImageDecoder::open($dir . '/not_an_image.jpg', 100, 100);
	check(false, 'text in a .jpg is refused');
} catch (ImageDecodeRefused $e) {
	check(strpos($e->getMessage(), 'not an image') !== false, 'text in a .jpg is refused', $e->getMessage());
}
try {
	ImageDecoder::open($dir . '/does_not_exist.jpg', 100, 100);
	check(false, 'a missing file is refused');
} catch (ImageDecodeRefused $e) {
	check(true, 'a missing file is refused', $e->getMessage());
}
$trunc = $dir . '/truncated.jpg';
file_put_contents($trunc, substr(file_get_contents($small), 0, 2000));
$ok = false; $why = '';
try {
	$d = ImageDecoder::open($trunc, 1920, 1080);
	$ok = ($d->width === 400);   // libjpeg fills the missing scanlines; GD still returns an image
	$why = 'decoded with the missing rows filled';
	unset($d);
} catch (ImageDecodeRefused $e) {
	$ok = true;
	$why = $e->getMessage();
}
check($ok, 'a truncated JPEG either decodes or is refused — never a PHP warning', $why);

section('ImageSizeRegistry::max_dimensions() is the pair the decoder is given');
$max = ImageSizeRegistry::max_dimensions();
$sizes = ImageSizeRegistry::get_sizes();
$w = 0; $h = 0;
foreach ($sizes as $c) { $w = max($w, $c['width']); $h = max($h, $c['height']); }
check($max['width'] === max(1, $w) && $max['height'] === max(1, $h), 'the largest width and the largest height across the registered sizes', json_encode($max));

harness_finish();
