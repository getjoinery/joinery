<?php
/**
 * ImageDecoder - the one place the platform turns an image file into pixels.
 *
 * Answers one question: give me this image, upright, at no more than the
 * largest size the site will ever cut from it, or say why not — before any
 * memory is spent. A decoded image costs 4 bytes a pixel outside PHP's
 * allocator (memory_limit cannot see it): a 24-megapixel phone photo is 92 MB
 * unpacked, and a progressive JPEG keeps every coefficient until its last scan,
 * which can be 6 bytes a pixel more. Inside a 256 MB site container that is
 * the difference between a thumbnail and a kernel kill
 * (specs/image_decode_memory.md).
 *
 * What open() does, in order:
 *   1. Reads the header only: dimensions, type, EXIF orientation, whether a
 *      JPEG is progressive.
 *   2. Estimates the decode's cost and refuses, with the figures, when it is
 *      over the ceiling (setting image_decode_max_mb).
 *   3. For a JPEG much larger than needed, decodes it already shrunk with
 *      djpeg -scale M/8 (libjpeg-turbo's decoder as a program, the same
 *      library GD links), under an address-space limit; GD then reads a
 *      BMP a fraction of the source's size. Without djpeg on the machine, or
 *      for other formats, decodes in full with GD.
 *   4. Turns the decoded — already small — image upright from its EXIF
 *      orientation, freeing the un-rotated one.
 *
 * Callers resample every registered size from the returned image. It is at
 * least as large as the largest registered size in each dimension wherever
 * the source is, so a crop or a scale comes out exactly as it would from
 * the full source.
 *
 * Usage:
 *   $max = ImageSizeRegistry::max_dimensions();
 *   try {
 *       $decoded = ImageDecoder::open($path, $max['width'], $max['height']);
 *       // $decoded->image (GdImage), ->type (IMAGETYPE_*), ->width, ->height
 *   } catch (ImageDecodeRefused $e) {
 *       // $e->getMessage() says why, in words a user can read
 *   }
 *   unset($decoded); // frees the pixels
 *
 * @version 1.0
 */

class ImageDecodeRefused extends RuntimeException {
	/** @var int|null estimated cost of the decode in MB, when the refusal was about size */
	public $estimate_mb = null;
	/** @var int|null the ceiling it was measured against */
	public $ceiling_mb = null;
}

class ImageDecoded {
	/** @var GdImage upright pixels */
	public $image;
	/** @var int IMAGETYPE_* of the source file */
	public $type;
	/** @var int dimensions of $image */
	public $width;
	public $height;
	/** @var int the source's upright dimensions (orientation applied) */
	public $source_width;
	public $source_height;
	/** @var string how the pixels were produced: 'gd', 'djpeg' */
	public $method;
}

class ImageDecoder {

	/** Ceiling when the setting is unreadable (no site booted, as in a unit test). */
	const DEFAULT_MAX_MB = 160;

	/** Headroom the djpeg process gets above the ceiling for itself. */
	const CHILD_HEADROOM_MB = 32;

	/** Wall-clock limit on the djpeg process. */
	const CHILD_TIMEOUT_SEC = 60;

	/** How much of a JPEG's head is scanned for the progressive marker. */
	const HEADER_SCAN_BYTES = 65536;

	private static $ceiling_override = null;
	private static $djpeg_override = null;
	private static $djpeg_missing_logged = false;
	/** Test hook: how many times pixels were actually produced this process. */
	public static $decode_count = 0;

	/** Test hook: ceiling in MB instead of the setting. Pass null to restore. */
	public static function set_ceiling_for_tests($mb) {
		self::$ceiling_override = $mb;
	}

	/** Test hook: a djpeg path, '' to pretend it is absent. Pass null to restore. */
	public static function set_djpeg_for_tests($path) {
		self::$djpeg_override = $path;
		self::$djpeg_missing_logged = false;
	}

	/**
	 * The most memory one decode may take, in MB.
	 */
	public static function ceiling_mb() {
		if (self::$ceiling_override !== null) {
			return (int)self::$ceiling_override;
		}
		$mb = 0;
		if (class_exists('Globalvars')) {
			try {
				$mb = (int)Globalvars::get_instance()->get_setting('image_decode_max_mb');
			} catch (\Throwable $e) {
				$mb = 0;
			}
		}
		return $mb > 0 ? $mb : self::DEFAULT_MAX_MB;
	}

	/**
	 * Decode $path upright at no more than is needed to cut $max_w x $max_h
	 * from it.
	 *
	 * @param string $path  a readable image file
	 * @param int $max_w    the largest width any caller will cut from the result
	 * @param int $max_h    the largest height any caller will cut from the result
	 * @return ImageDecoded
	 * @throws ImageDecodeRefused when the file is not a decodable image or would cost more than the ceiling
	 */
	public static function open($path, $max_w, $max_h) {
		$info = self::read_header($path);
		$max_w = max(1, (int)$max_w);
		$max_h = max(1, (int)$max_h);

		// Dimensions after orientation: 5-8 turn the image on its side.
		$swap = $info['orientation'] >= 5;
		$up_w = $swap ? $info['height'] : $info['width'];
		$up_h = $swap ? $info['width'] : $info['height'];

		// The decode shrink step, JPEG only: the smallest M/8 that still leaves
		// at least max_w x max_h upright. Both dimensions, so a portrait source
		// keeps enough width for a landscape hero crop.
		$scale_num = 8;
		if ($info['type'] === IMAGETYPE_JPEG) {
			for ($m = 1; $m <= 8; $m++) {
				if (self::scaled($up_w, $m) >= $max_w && self::scaled($up_h, $m) >= $max_h) {
					$scale_num = $m;
					break;
				}
			}
		}
		$djpeg = ($scale_num < 8) ? self::djpeg_path() : null;
		if ($scale_num < 8 && $djpeg === null) {
			$scale_num = 8;
		}

		// Cost before a byte of pixels exists: 4 bytes per decoded pixel, plus
		// the whole coefficient set for a progressive JPEG, scaled or not.
		$out_w = self::scaled($info['width'], $scale_num);
		$out_h = self::scaled($info['height'], $scale_num);
		$bytes = 4 * $out_w * $out_h;
		if ($info['progressive']) {
			$bytes += 6 * $info['width'] * $info['height'];
		}
		$estimate_mb = (int)ceil($bytes / 1048576);
		$ceiling = self::ceiling_mb();
		if ($estimate_mb > $ceiling) {
			$e = new ImageDecodeRefused(sprintf(
				'This photo is too large to make sizes from (about %d MB to decode%s, the limit is %d MB). It was saved as uploaded.',
				$estimate_mb, $info['progressive'] ? ', progressive JPEG' : '', $ceiling));
			$e->estimate_mb = $estimate_mb;
			$e->ceiling_mb = $ceiling;
			throw $e;
		}

		if ($djpeg !== null) {
			$image = self::decode_jpeg_scaled($path, $djpeg, $scale_num, $ceiling);
			$method = 'djpeg';
		} else {
			$image = self::decode_with_gd($path, $info['type']);
			$method = 'gd';
		}
		self::$decode_count++;

		if ($info['orientation'] >= 2) {
			$image = self::orient($image, $info['orientation']);
		}

		$d = new ImageDecoded();
		$d->image = $image;
		$d->type = $info['type'];
		$d->width = imagesx($image);
		$d->height = imagesy($image);
		$d->source_width = $up_w;
		$d->source_height = $up_h;
		$d->method = $method;
		return $d;
	}

	// ------------------------------------------------------------------
	// The header
	// ------------------------------------------------------------------

	/**
	 * What can be known without decoding: type, dimensions, EXIF orientation
	 * (1 when none), and whether a JPEG is progressive.
	 *
	 * @return array{type:int,width:int,height:int,orientation:int,progressive:bool}
	 * @throws ImageDecodeRefused when the file is not an image the platform decodes
	 */
	public static function read_header($path) {
		if (!is_file($path) || !is_readable($path)) {
			throw new ImageDecodeRefused('The image file is missing or unreadable.');
		}
		$info = @getimagesize($path);
		if ($info === false || (int)$info[0] < 1 || (int)$info[1] < 1) {
			throw new ImageDecodeRefused('The file is not an image the site can read.');
		}
		$type = (int)$info[2];
		if (self::gd_reader($type) === null) {
			throw new ImageDecodeRefused('The image format (' . image_type_to_mime_type($type) . ') is not one the site decodes.');
		}
		$orientation = 1;
		$progressive = false;
		if ($type === IMAGETYPE_JPEG) {
			if (function_exists('exif_read_data')) {
				$exif = @exif_read_data($path);
				if (is_array($exif) && isset($exif['Orientation'])) {
					$o = (int)$exif['Orientation'];
					if ($o >= 2 && $o <= 8) {
						$orientation = $o;
					}
				}
			}
			$progressive = self::jpeg_is_progressive($path);
		}
		return array(
			'type' => $type,
			'width' => (int)$info[0],
			'height' => (int)$info[1],
			'orientation' => $orientation,
			'progressive' => $progressive,
		);
	}

	/**
	 * A progressive JPEG announces itself with an SOF2 marker (FF C2) where a
	 * baseline one has SOF0 (FF C0). Walk the marker segments from the start
	 * so a C2 byte inside an EXIF blob is not mistaken for one.
	 */
	public static function jpeg_is_progressive($path) {
		$head = @file_get_contents($path, false, null, 0, self::HEADER_SCAN_BYTES);
		if ($head === false || strlen($head) < 4 || substr($head, 0, 2) !== "\xFF\xD8") {
			return false;
		}
		$pos = 2;
		$len = strlen($head);
		while ($pos + 4 <= $len) {
			if (ord($head[$pos]) !== 0xFF) {
				$pos++;
				continue;
			}
			$marker = ord($head[$pos + 1]);
			if ($marker === 0xFF) {
				$pos++;
				continue;
			}
			if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
				$pos += 2; // standalone markers carry no length
				continue;
			}
			if ($marker === 0xC2 || $marker === 0xC6 || $marker === 0xCA || $marker === 0xCE) {
				return true;  // progressive: Huffman, differential, arithmetic variants
			}
			if ($marker === 0xC0 || $marker === 0xC1 || $marker === 0xC3 || $marker === 0xC9 || $marker === 0xDA) {
				return false; // baseline / sequential, or scan data reached
			}
			$seg = (ord($head[$pos + 2]) << 8) | ord($head[$pos + 3]);
			$pos += 2 + max(2, $seg);
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Producing pixels
	// ------------------------------------------------------------------

	private static function scaled($px, $m) {
		return (int)ceil($px * $m / 8);
	}

	private static function gd_reader($type) {
		switch ($type) {
			case IMAGETYPE_JPEG: return 'imagecreatefromjpeg';
			case IMAGETYPE_PNG:  return 'imagecreatefrompng';
			case IMAGETYPE_GIF:  return 'imagecreatefromgif';
			case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? 'imagecreatefromwebp' : null;
			case IMAGETYPE_AVIF: return function_exists('imagecreatefromavif') ? 'imagecreatefromavif' : null;
			case IMAGETYPE_BMP:  return 'imagecreatefrombmp';
			default:             return null;
		}
	}

	private static function decode_with_gd($path, $type) {
		$reader = self::gd_reader($type);
		$image = @$reader($path);
		if (!$image) {
			throw new ImageDecodeRefused('The image could not be decoded; the file may be damaged.');
		}
		return $image;
	}

	/**
	 * djpeg on the machine, or null. The override wins; '' means absent.
	 */
	public static function djpeg_path() {
		if (self::$djpeg_override !== null) {
			return self::$djpeg_override === '' ? null : self::$djpeg_override;
		}
		foreach (array('/usr/bin/djpeg', '/usr/local/bin/djpeg') as $candidate) {
			if (is_executable($candidate)) {
				return $candidate;
			}
		}
		if (!self::$djpeg_missing_logged) {
			self::$djpeg_missing_logged = true;
			error_log('ImageDecoder: djpeg is not installed (package libjpeg-turbo-progs); large JPEGs are decoded in full, at more memory per photo');
		}
		return null;
	}

	/**
	 * Decode a JPEG already shrunk to M/8 through djpeg, into a BMP that GD then
	 * reads. The child runs under an address-space limit of the ceiling plus
	 * headroom, so an estimate that was wrong ends in a refusal, not a kill.
	 */
	private static function decode_jpeg_scaled($path, $djpeg, $scale_num, $ceiling_mb) {
		$tmp = self::temp_path();
		try {
			$limit_kb = ($ceiling_mb + self::CHILD_HEADROOM_MB) * 1024;
			$cmd = array('/bin/sh', '-c',
				'ulimit -v ' . (int)$limit_kb . ' 2>/dev/null; exec "$0" "$@"',
				$djpeg, '-scale', $scale_num . '/8', '-bmp', '-outfile', $tmp, $path);
			$spec = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) {
				throw new ImageDecodeRefused('The image decoder could not be started.');
			}
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);
			$stderr = '';
			$deadline = microtime(true) + self::CHILD_TIMEOUT_SEC;
			$status = proc_get_status($proc);
			while ($status['running']) {
				$stderr .= (string)stream_get_contents($pipes[2]);
				stream_get_contents($pipes[1]);
				if (microtime(true) > $deadline) {
					proc_terminate($proc, 9);
					throw new ImageDecodeRefused('The image took longer than ' . self::CHILD_TIMEOUT_SEC . ' seconds to decode and was stopped.');
				}
				usleep(20000);
				$status = proc_get_status($proc);
			}
			$stderr .= (string)stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);
			$exit = (int)$status['exitcode'];
			if ($exit !== 0 || !is_file($tmp) || filesize($tmp) < 54) {
				$why = trim($stderr);
				error_log('ImageDecoder: djpeg exit ' . $exit . ' on ' . basename($path) . ($why !== '' ? ': ' . $why : ''));
				throw new ImageDecodeRefused('The image could not be decoded' . ($exit === 137 || stripos($why, 'memory') !== false
					? ' within ' . $ceiling_mb . ' MB' : '; the file may be damaged') . '.');
			}
			$image = @imagecreatefrombmp($tmp);
			if (!$image) {
				throw new ImageDecodeRefused('The shrunk image could not be read back.');
			}
			return $image;
		} finally {
			if (is_file($tmp)) {
				@unlink($tmp);
			}
		}
	}

	private static function temp_path() {
		$dir = null;
		if (class_exists('PathHelper')) {
			$cache = PathHelper::getSiteRoot() . '/cache';
			if (is_dir($cache) && is_writable($cache)) {
				$dir = $cache;
			}
		}
		if ($dir === null) {
			$dir = sys_get_temp_dir();
		}
		return $dir . '/image_decode_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.bmp';
	}

	// ------------------------------------------------------------------
	// Orientation
	// ------------------------------------------------------------------

	/**
	 * Turn a decoded image upright for an EXIF orientation 2-8, freeing the
	 * input. Called on the decoded (small) image, never on a full-size one.
	 */
	public static function orient($image, $orientation) {
		switch ((int)$orientation) {
			case 2: imageflip($image, IMG_FLIP_HORIZONTAL); return $image;
			case 3: return self::rotated($image, 180);
			case 4: imageflip($image, IMG_FLIP_VERTICAL); return $image;
			case 5: imageflip($image, IMG_FLIP_VERTICAL); return self::rotated($image, 270);
			case 6: return self::rotated($image, 270);
			case 7: imageflip($image, IMG_FLIP_HORIZONTAL); return self::rotated($image, 270);
			case 8: return self::rotated($image, 90);
			default: return $image;
		}
	}

	private static function rotated($image, $degrees) {
		if (imageistruecolor($image)) {
			imagealphablending($image, false);
			imagesavealpha($image, true);
		}
		$out = imagerotate($image, $degrees, 0);
		unset($image);
		if (!$out) {
			throw new ImageDecodeRefused('The image could not be turned upright.');
		}
		imagealphablending($out, false);
		imagesavealpha($out, true);
		return $out;
	}
}
