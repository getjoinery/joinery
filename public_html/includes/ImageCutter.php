<?php
/**
 * ImageCutter - every size the site cuts from an uploaded image, made in the
 * parser jail.
 *
 * An uploaded photo is a stranger's bytes, and turning it into pixels hands
 * those bytes to C libraries (libjpeg, libpng, libwebp, libavif, giflib, the
 * EXIF reader). A bug in one of them, reached by a crafted picture, runs code
 * as whoever called it. So no web worker, cron task or mail pipe ever decodes
 * an image: it asks here, and the decode, the turning upright, the resampling
 * and the encoding of each size all happen in the fenced subprocess
 * (docs/document_text.md). What comes back is finished files' bytes, which the
 * caller writes to disk and never opens.
 *
 * The caller's side:
 *
 *   $max = ImageSizeRegistry::max_dimensions();
 *   try {
 *       $cut = ImageCutter::cut($path, $sizes, $max['width'], $max['height']);
 *       // $cut['variants'][$size_key] is that size's encoded bytes
 *   } catch (ImageDecodeRefused $e) {
 *       // $e->getMessage() says why, in words a user can read
 *   }
 *
 * One call is one decode, however many sizes are asked for. A JPEG much
 * larger than needed takes three short runs in place of one: the first reads
 * its header and answers how far to shrink it, djpeg decodes it already
 * shrunk (also fenced, ImageDecoder::shrink_jpeg()), and the second cuts the
 * sizes from that small picture. That keeps a phone photo within a small
 * site's memory, as it did when the decode ran in the worker.
 *
 * The ceiling (ImageDecoder::ceiling_mb()) is worked out here, where the
 * setting and the machine's memory can be read, and travels in the options:
 * the fenced side reads no setting.
 *
 * @version 1.1 - a shrunk copy that would not fit this process beside the source is not asked for (a 30000 x 1250
 *                JPEG answered an 86 MB BMP and the worker died for memory on every view); the scale the fenced
 *                side names is held to a shrink; the palette for a transparent GIF is chosen from the opaque
 *                picture and the see-through pixels are set after, so no highlight falls through; the fenced
 *                ceiling is 200 MB
 * @version 1.0
 */
class ImageCutter implements SandboxParserInterface {

	/** The largest source file a cut is attempted from. Its bytes travel down a pipe and are held once in memory here. */
	const MAX_SOURCE_BYTES = 50331648;   // 48 MB

	/**
	 * The most the fenced decode may estimate, whatever the site's ceiling:
	 * the run's address space (DocumentText::JAIL_ADDRESS_SPACE_BYTES, 640 MB)
	 * also holds PHP and its libraries, the source's bytes twice (the EXIF
	 * reader takes a copy) and, for a picture stored on its side, a second
	 * full image while it is turned.
	 */
	const FENCE_CEILING_MB = 200;

	/**
	 * The most of an image this process holds at once: the source file's
	 * bytes plus the shrunk copy djpeg hands back. A web worker's
	 * memory_limit is 128 MB and a fatal for memory cannot be caught, so a
	 * shrink that would not fit is not attempted (the image is decoded in
	 * full inside the fence, or refused there in words).
	 */
	const WORKER_BUDGET_BYTES = 67108864;   // 64 MB

	/**
	 * Cut $sizes from the image at $src_path.
	 *
	 * @param string $src_path a readable image file
	 * @param array  $sizes    size key => array(width, height, crop, quality), as ImageSizeRegistry gives them
	 * @param int    $max_w    the largest width any size needs
	 * @param int    $max_h    the largest height any size needs
	 * @return array{type:int,variants:array<string,string>} the source's IMAGETYPE_* and each size's encoded bytes;
	 *         a size the encoder could not write is absent
	 * @throws ImageDecodeRefused with words a user can read; ->transient when a later try may succeed
	 */
	public static function cut($src_path, array $sizes, $max_w, $max_h) {
		if (!is_file($src_path) || !is_readable($src_path)) {
			throw new ImageDecodeRefused('The image file is missing or unreadable.');
		}
		$size = (int)@filesize($src_path);
		if ($size > self::MAX_SOURCE_BYTES) {
			throw new ImageDecodeRefused(sprintf(
				'This image is too large to make sizes from (a %d MB file; the limit is %d MB). It was saved as uploaded.',
				(int)ceil($size / 1048576), (int)(self::MAX_SOURCE_BYTES / 1048576)));
		}
		$bytes = @file_get_contents($src_path);
		if ($bytes === false || $bytes === '') {
			throw new ImageDecodeRefused('The image file is missing or unreadable.');
		}

		$site_ceiling = ImageDecoder::ceiling_mb();
		$ceiling = min($site_ceiling, self::FENCE_CEILING_MB);
		$options = array(
			'sizes' => self::plainSizes($sizes),
			'max_w' => max(1, (int)$max_w),
			'max_h' => max(1, (int)$max_h),
			'ceiling_mb' => $ceiling,
			'ceiling_is_site' => ImageDecoder::ceiling_is_site(),
			'shrink' => ImageDecoder::djpeg_path() !== null,
		);

		$answer = self::ask($bytes, $options);
		if (isset($answer['shrink'])) {
			// The fenced side's word is not trusted for what this process then
			// does: the scale is a shrink (1 to 7 eighths) or it is refused, and
			// the shrunk copy must fit beside the source in this process.
			$m = (int)$answer['shrink'];
			$info = is_array($answer['info'] ?? null) ? $answer['info'] : array();
			$w = (int)($info['width'] ?? 0);
			$h = (int)($info['height'] ?? 0);
			if ($m < 1 || $m > 7 || $w < 1 || $h < 1 || $w > 65535 || $h > 65535) {
				throw new ImageDecodeRefused('The image could not be decoded; the file may be damaged.');
			}
			$room = self::WORKER_BUDGET_BYTES - strlen($bytes);
			$bmp_bytes = 54 + 3 * (int)ceil($w * $m / 8) * (int)ceil($h * $m / 8) + 4 * (int)ceil($h * $m / 8);
			if ($bmp_bytes > $room) {
				// Too large to carry through here shrunk: decode it in full
				// inside the fence, where the ceiling refuses it in words if
				// that does not fit either.
				$options['shrink'] = false;
				$answer = self::ask($bytes, $options);
			}
		}
		if (isset($answer['shrink'])) {
			$bmp = ImageDecoder::shrink_jpeg($bytes, $m, $ceiling, $room);
			unset($bytes);
			$options['shrunk'] = is_array($answer['info'] ?? null) ? $answer['info'] : array();
			$answer = self::ask($bmp, $options);
			unset($bmp);
			if (isset($answer['shrink'])) {
				throw new ImageDecodeRefused('The image could not be decoded; the file may be damaged.');
			}
		}

		$variants = array();
		foreach ((array)($answer['variants'] ?? array()) as $key => $b64) {
			$raw = is_string($b64) ? base64_decode($b64, true) : false;
			if ($raw !== false && $raw !== '' && isset($sizes[$key])) {
				$variants[$key] = $raw;
			}
		}
		ImageDecoder::$decode_count++;
		return array('type' => (int)($answer['type'] ?? 0), 'variants' => $variants);
	}

	/** One fenced run: the answer as an array, or the refusal it stands for. */
	private static function ask($bytes, array $options) {
		$r = DocumentText::parseWith('ImageCutter', $bytes, $options);
		if ($r['status'] !== DocumentText::OK) {
			$detail = (string)($r['detail'] ?? '');
			error_log('ImageCutter: the fenced decode failed (' . $r['status'] . ')' . ($detail !== '' ? ': ' . $detail : ''));
			if (stripos($detail, 'memory') !== false) {
				// Killed for memory, not a bad picture: nothing is recorded, and a later try may fit.
				$e = new ImageDecodeRefused('The site ran out of memory while making sizes for this photo; it will be tried again later.');
				$e->transient = true;
				throw $e;
			}
			if (stripos($detail, 'answer too large') !== false) {
				throw new ImageDecodeRefused('This image is too large to make sizes from (its sizes together are over '
					. (int)(DocumentText::MAX_ANSWER_BYTES / 1048576) . ' MB). It was saved as uploaded.');
			}
			if (stripos($detail, 'timed out') !== false) {
				throw new ImageDecodeRefused('The image took longer than ' . DocumentText::TIMEOUT_SECONDS . ' seconds to decode and was stopped.');
			}
			if (stripos($detail, 'jail') !== false || stripos($detail, 'spawn') !== false || stripos($detail, 'extractor') !== false) {
				$e = new ImageDecodeRefused('The image decoder could not be started.');
				$e->transient = true;
				throw $e;
			}
			throw new ImageDecodeRefused('The image could not be decoded; the file may be damaged.');
		}
		$answer = json_decode($r['text'], true);
		if (!is_array($answer)) {
			throw new ImageDecodeRefused('The image could not be decoded; the file may be damaged.');
		}
		if (isset($answer['refused'])) {
			$e = new ImageDecodeRefused((string)$answer['refused']);
			$e->estimate_mb = isset($answer['estimate_mb']) ? (int)$answer['estimate_mb'] : null;
			$e->ceiling_mb = isset($answer['ceiling_mb']) ? (int)$answer['ceiling_mb'] : null;
			$e->transient = !empty($answer['transient']);
			throw $e;
		}
		return $answer;
	}

	/** Only what the fenced side reads of each size, as plain JSON-safe values. */
	private static function plainSizes(array $sizes) {
		$out = array();
		foreach ($sizes as $key => $config) {
			$out[(string)$key] = array(
				'width' => (int)($config['width'] ?? 0),
				'height' => (int)($config['height'] ?? 0),
				'crop' => !empty($config['crop']),
				'quality' => isset($config['quality']) ? (int)$config['quality'] : 85,
			);
		}
		return $out;
	}

	// ==================================================================
	// THE FENCED SIDE: everything below runs only inside the extraction
	// subprocess. It opens a stranger's bytes.
	// ==================================================================

	/**
	 * Decode once, cut every size, answer JSON:
	 *   {"type":2,"variants":{"thumb":"<base64>",...}}   the sizes
	 *   {"shrink":3,"info":{...}}                          decode this JPEG at 3/8 first, then ask again
	 *   {"refused":"words","estimate_mb":..,"ceiling_mb":..,"transient":bool}
	 */
	public static function sandboxParse(string $bytes, array $options): string {
		$sizes = is_array($options['sizes'] ?? null) ? $options['sizes'] : array();
		$ceiling = max(1, (int)($options['ceiling_mb'] ?? ImageDecoder::DEFAULT_MAX_MB));
		try {
			if (is_array($options['shrunk'] ?? null)) {
				$decoded = ImageDecoder::open_shrunk($bytes, $options['shrunk'], $ceiling);
			} else {
				$decoded = ImageDecoder::open_bytes($bytes, (int)($options['max_w'] ?? 1), (int)($options['max_h'] ?? 1), array(
					'ceiling_mb' => $ceiling,
					'ceiling_is_site' => !empty($options['ceiling_is_site']),
					'shrink' => !empty($options['shrink']) ? 'ask' : 'none',
				));
			}
		} catch (ImageShrinkWanted $e) {
			return (string)json_encode(array('shrink' => (int)$e->scale_num, 'info' => $e->info));
		} catch (ImageDecodeRefused $e) {
			return (string)json_encode(array(
				'refused' => $e->getMessage(),
				'estimate_mb' => $e->estimate_mb,
				'ceiling_mb' => $e->ceiling_mb,
				'transient' => (bool)$e->transient,
			));
		}
		unset($bytes);

		$variants = array();
		foreach ($sizes as $key => $config) {
			if (!is_array($config)) continue;
			$encoded = self::renderSize($decoded, $config);
			if ($encoded !== null) {
				$variants[(string)$key] = base64_encode($encoded);
			}
		}
		return (string)json_encode(array('type' => (int)$decoded->type, 'variants' => $variants));
	}

	/**
	 * Resample one size from a decoded image and encode it in the source's own
	 * format. A crop takes the centred box of the target's aspect, a scale fits
	 * inside width x height, and neither ever enlarges.
	 *
	 * @return string|null the encoded bytes, or null when this GD cannot write the format
	 */
	public static function renderSize(ImageDecoded $decoded, array $config) {
		$width = (int)($config['width'] ?? 0);
		$height = (int)($config['height'] ?? 0);
		$crop = !empty($config['crop']);
		$quality = isset($config['quality']) ? (int)$config['quality'] : 85;

		$src = $decoded->image;
		$type = $decoded->type;
		$src_w = $decoded->width;
		$src_h = $decoded->height;

		$sx = 0; $sy = 0; $sw = $src_w; $sh = $src_h;
		if ($crop && $width > 0 && $height > 0) {
			if (($src_w / $width) < ($src_h / $height)) {
				$sw = $src_w;
				$sh = (int)floor($height * $src_w / $width);
				$sx = 0;
				$sy = (int)(($src_h - $sh) / 2);
			} else {
				$sw = (int)ceil($width * $src_h / $height);
				$sh = $src_h;
				$sx = (int)(($src_w - $sw) / 2);
				$sy = 0;
			}
		}

		$f = 1.0;
		if ($width > 0 && $height > 0) {
			$f = min($width / $sw, $height / $sh);
		} elseif ($width > 0) {
			$f = $width / $sw;
		} elseif ($height > 0) {
			$f = $height / $sh;
		}
		if ($f > 1.0) { $f = 1.0; }
		$dw = max(1, (int)round($sw * $f));
		$dh = max(1, (int)round($sh * $f));

		$dst = imagecreatetruecolor($dw, $dh);
		if ($type !== IMAGETYPE_JPEG) {
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
			imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
		}
		imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

		if ($type === IMAGETYPE_GIF) {
			$dst = self::toPalette($dst, imagecolortransparent($src) >= 0);
		}

		ob_start();
		$wrote = false;
		switch ($type) {
			case IMAGETYPE_JPEG: $wrote = imagejpeg($dst, null, $quality); break;
			case IMAGETYPE_PNG:  $wrote = imagepng($dst); break;
			case IMAGETYPE_GIF:  $wrote = imagegif($dst); break;
			case IMAGETYPE_WEBP: if (function_exists('imagewebp')) { $wrote = imagewebp($dst, null, $quality); } break;
			case IMAGETYPE_AVIF: if (function_exists('imageavif')) { $wrote = imageavif($dst, null, $quality); } break;
		}
		$encoded = (string)ob_get_clean();
		unset($dst);
		return ($wrote && $encoded !== '') ? $encoded : null;
	}

	/**
	 * A 256-colour copy of a truecolour image, for a GIF. GD's own reduction
	 * (imagetruecolortopalette) goes through a library that starts threads,
	 * which the fence refuses, so the palette is chosen here: the commonest
	 * colours of a small sample, in 5 bits a channel, each slot the average
	 * of what fell in it. GD then maps every pixel to its nearest entry.
	 *
	 * A GIF has one transparent entry. The picture is mapped first, laid over
	 * its own commonest colour, to at most 255 colours that are all real
	 * ones; the transparent entry is added after, and given only to the
	 * pixels the source says are see-through. No colour of the picture can
	 * land on it, however near.
	 */
	private static function toPalette($image, $transparent) {
		$w = imagesx($image);
		$h = imagesy($image);
		$sw = min(96, $w);
		$sh = min(96, $h);
		$sample = imagecreatetruecolor($sw, $sh);
		imagealphablending($sample, false);
		imagesavealpha($sample, true);
		imagecopyresampled($sample, $image, 0, 0, 0, 0, $sw, $sh, $w, $h);
		$buckets = array();
		for ($y = 0; $y < $sh; $y++) {
			for ($x = 0; $x < $sw; $x++) {
				$c = imagecolorat($sample, $x, $y);
				if ((($c >> 24) & 0x7F) >= 64) continue;   // mostly transparent: not a colour to keep
				$r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
				$k = (($r >> 3) << 10) | (($g >> 3) << 5) | ($b >> 3);
				if (!isset($buckets[$k])) {
					$buckets[$k] = array(0, 0, 0, 0);
				}
				$buckets[$k][0]++; $buckets[$k][1] += $r; $buckets[$k][2] += $g; $buckets[$k][3] += $b;
			}
		}
		unset($sample);
		uasort($buckets, function ($a, $b) { return $b[0] <=> $a[0]; });

		$colours = array();
		foreach (array_slice($buckets, 0, $transparent ? 255 : 256, true) as $bucket) {
			$colours[] = array((int)round($bucket[1] / $bucket[0]), (int)round($bucket[2] / $bucket[0]), (int)round($bucket[3] / $bucket[0]));
		}
		if (!$colours) {
			$colours[] = array(0, 0, 0);
		}

		// The picture with nothing see-through in it: over its commonest colour.
		$flat = imagecreatetruecolor($w, $h);
		imagefill($flat, 0, 0, imagecolorallocate($flat, $colours[0][0], $colours[0][1], $colours[0][2]));
		imagealphablending($flat, true);
		imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);

		$out = imagecreate($w, $h);
		foreach ($colours as $c) {
			imagecolorallocate($out, $c[0], $c[1], $c[2]);
		}
		// The transparent entry takes its slot before the copy, and is named
		// transparent before it too: GD maps no pixel to the entry that is
		// already the transparent one, and with the slot taken the copy cannot
		// spend it on a colour of the picture.
		$key = false;
		if ($transparent) {
			$key = imagecolorallocate($out, 0, 0, 0);
			if ($key !== false) {
				imagecolortransparent($out, $key);
			}
		}
		imagecopy($out, $flat, 0, 0, 0, 0, $w, $h);
		unset($flat);
		if ($key === false) {
			return $out;
		}
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) >= 64) {
					imagesetpixel($out, $x, $y, $key);
				}
			}
		}
		return $out;
	}
}
