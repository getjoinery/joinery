<?php
/**
 * ZipStreamWriter — writes a zip archive as it goes, to a sink, never to disk.
 *
 * Every byte goes straight to the callable it was built with (an `echo` for a
 * download), so a member's folder starts downloading at once, a large folder
 * needs no free disk, and a Private file's plaintext exists only in the request
 * that is sending it (specs/export_mail_contacts_calendar_drive.md, Q3). PHP's
 * ZipArchive can only write a finished file to disk, which is why this exists.
 *
 * It lays out the zip format only. Compression is PHP's zlib (raw deflate,
 * streamed through deflate_init/deflate_add) and the checksum is
 * hash('crc32b'). A file's sizes and checksum are not known until its last
 * byte, so each one is followed by a data descriptor (general flag bit 3).
 * Names are UTF-8 (bit 11). Each entry carries an extended timestamp (0x5455)
 * with its UTC time beside the DOS time, which has no zone.
 *
 * ZIP64: a file whose size hint is near 4 GiB is written with 64-bit sizes
 * from its local header on, and the end records switch to ZIP64 once the entry
 * count, the directory's offset or its size outgrow the classic fields. The
 * browser twin is assets/js/zip-writer.js.
 *
 * Usage:
 *   $zip = new ZipStreamWriter(function ($bytes) { echo $bytes; });
 *   $zip->addDirectory('Photos/');
 *   $zip->beginFile('Photos/a.jpg', $mtime, ZipStreamWriter::compressible('a.jpg'), $size);
 *   $zip->write($chunk); ...
 *   $zip->endFile();
 *   $zip->finish();
 *
 * @version 1.1 - a deflate failure throws instead of leaving a short entry
 * @version 1.0
 */
class ZipStreamWriter {

	/** A file at least this big is written with 64-bit sizes. Below 4 GiB, with room for deflate's worst case. */
	const ZIP64_FILE_BYTES = 0xF0000000;

	const SIG_LOCAL      = 0x04034b50;
	const SIG_DESCRIPTOR = 0x08074b50;
	const SIG_CENTRAL    = 0x02014b50;
	const SIG_END        = 0x06054b50;
	const SIG_END64      = 0x06064b50;
	const SIG_LOCATOR64  = 0x07064b50;

	const FLAG_DESCRIPTOR = 0x0008;
	const FLAG_UTF8       = 0x0800;

	const METHOD_STORE   = 0;
	const METHOD_DEFLATE = 8;

	const MAX32 = 0xFFFFFFFF;
	const MAX16 = 0xFFFF;

	/** Already-compressed formats: deflating them again costs time and saves nothing. */
	const STORED_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif',
		'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi',
		'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac',
		'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub',
		'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'jar', 'apk',
	);

	private $sink;
	private $offset = 0;
	private $central = array();
	private $current = null;
	private $finished = false;
	private $force_zip64;

	/**
	 * @param callable $sink    receives each piece of the archive, in order
	 * @param array    $options 'force_zip64' => true writes every entry and the
	 *                          end records in ZIP64 form (tests, which cannot
	 *                          write 4 GiB to prove the readers take it)
	 */
	public function __construct(callable $sink, array $options = array()) {
		$this->sink = $sink;
		$this->force_zip64 = !empty($options['force_zip64']);
	}

	/** Bytes handed to the sink so far. */
	public function bytesWritten(): int {
		return $this->offset;
	}

	/**
	 * Should a file with this name and type be deflated? Formats that are
	 * compressed already are stored as they are.
	 */
	public static function compressible(string $name, ?string $mime = null): bool {
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($ext !== '' && in_array($ext, self::STORED_EXTENSIONS, true)) {
			return false;
		}
		$mime = strtolower((string)$mime);
		if ($mime !== '' && (strpos($mime, 'image/') === 0 || strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0)) {
			return $mime === 'image/svg+xml' || $mime === 'image/bmp' || $mime === 'image/tiff';
		}
		return true;
	}

	/**
	 * One name as a single path segment any unzip will extract where it says:
	 * no separators, no control characters, never '.' or '..'. Everything else
	 * is kept as it is.
	 */
	public static function cleanName(string $name): string {
		$name = str_replace(array('/', '\\'), '_', $name);
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '_', $name);
		if ($name === null) {
			$name = '_'; // not valid UTF-8: preg refuses it
		}
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..') {
			return '_';
		}
		return $name;
	}

	/** An empty directory entry. $path ends in '/' (one is added if not). */
	public function addDirectory(string $path, ?int $mtime = null): void {
		$this->assertOpenForEntry();
		if (substr($path, -1) !== '/') {
			$path .= '/';
		}
		$entry = $this->newEntry($path, $mtime, self::METHOD_STORE, $this->force_zip64, false);
		$entry['external'] = (040755 << 16) | 0x10;
		$this->emit($this->localHeader($entry));
		$this->central[] = $entry;
	}

	/** A whole small file in one call. */
	public function addFile(string $path, string $bytes, ?int $mtime = null, ?bool $compress = null): void {
		if ($compress === null) {
			$compress = self::compressible($path);
		}
		$this->beginFile($path, $mtime, $compress, strlen($bytes));
		$this->write($bytes);
		$this->endFile();
	}

	/**
	 * Start a file. Its bytes follow through write(), then endFile().
	 *
	 * @param int|null $size_hint the file's size if known; decides whether it is
	 *        written in ZIP64 form. Unknown is taken as large.
	 */
	public function beginFile(string $path, ?int $mtime = null, bool $compress = true, ?int $size_hint = null): void {
		$this->assertOpenForEntry();
		$zip64 = $this->force_zip64 || $size_hint === null || $size_hint >= self::ZIP64_FILE_BYTES;
		$method = ($compress && $size_hint !== 0) ? self::METHOD_DEFLATE : self::METHOD_STORE;
		$entry = $this->newEntry($path, $mtime, $method, $zip64, true);
		$entry['external'] = 0100644 << 16;
		$entry['crc'] = hash_init('crc32b');
		$entry['deflate'] = ($method === self::METHOD_DEFLATE) ? deflate_init(ZLIB_ENCODING_RAW, array('level' => 6)) : null;
		$this->emit($this->localHeader($entry));
		$this->current = $entry;
	}

	/** More bytes of the file begun last. */
	public function write(string $bytes): void {
		if ($this->current === null) {
			throw new LogicException('ZipStreamWriter: write() with no file begun.');
		}
		if ($bytes === '') {
			return;
		}
		hash_update($this->current['crc'], $bytes);
		$this->current['size'] += strlen($bytes);
		$out = $this->current['deflate'] ? deflate_add($this->current['deflate'], $bytes, ZLIB_NO_FLUSH) : $bytes;
		if ($out === false) {
			throw new RuntimeException('ZipStreamWriter: deflate failed in ' . $this->current['name'] . '.');
		}
		if ($out !== '') {
			$this->current['csize'] += strlen($out);
			$this->emit($out);
		}
	}

	/** Close the file begun last: its descriptor carries the checksum and sizes. */
	public function endFile(): void {
		$entry = $this->current;
		if ($entry === null) {
			throw new LogicException('ZipStreamWriter: endFile() with no file begun.');
		}
		if ($entry['deflate']) {
			$tail = deflate_add($entry['deflate'], '', ZLIB_FINISH);
			if ($tail === false) {
				throw new RuntimeException('ZipStreamWriter: deflate failed to finish ' . $entry['name'] . '.');
			}
			if ($tail !== '') {
				$entry['csize'] += strlen($tail);
				$this->emit($tail);
			}
		}
		$entry['crc'] = unpack('N', hash_final($entry['crc'], true))[1];
		$entry['deflate'] = null;
		if (!$entry['zip64'] && ($entry['size'] > self::MAX32 || $entry['csize'] > self::MAX32)) {
			// The local header promised 32-bit sizes. A reader would take the
			// truncated value and lose the rest of the archive, so refuse loudly
			// rather than write it.
			throw new RuntimeException('ZipStreamWriter: ' . $entry['name'] . ' outgrew its size hint past 4 GiB.');
		}
		$this->emit(pack('V', self::SIG_DESCRIPTOR) . pack('V', $entry['crc'])
			. ($entry['zip64']
				? pack('P', $entry['csize']) . pack('P', $entry['size'])
				: pack('V', $entry['csize']) . pack('V', $entry['size'])));
		$this->central[] = $entry;
		$this->current = null;
	}

	/** Write the central directory and the end records. Returns the archive's size. */
	public function finish(): int {
		if ($this->current !== null) {
			throw new LogicException('ZipStreamWriter: finish() with a file still open.');
		}
		if ($this->finished) {
			return $this->offset;
		}
		$cd_offset = $this->offset;
		foreach ($this->central as $entry) {
			$this->emit($this->centralHeader($entry));
		}
		$cd_size = $this->offset - $cd_offset;
		$count = count($this->central);

		if ($this->force_zip64 || $count > self::MAX16 || $cd_offset >= self::MAX32 || $cd_size >= self::MAX32) {
			$end64_offset = $this->offset;
			$this->emit(pack('V', self::SIG_END64) . pack('P', 44)
				. pack('v', (3 << 8) | 45) . pack('v', 45)
				. pack('V', 0) . pack('V', 0)
				. pack('P', $count) . pack('P', $count)
				. pack('P', $cd_size) . pack('P', $cd_offset));
			$this->emit(pack('V', self::SIG_LOCATOR64) . pack('V', 0) . pack('P', $end64_offset) . pack('V', 1));
		}
		$this->emit(pack('V', self::SIG_END) . pack('v', 0) . pack('v', 0)
			. pack('v', min($count, self::MAX16)) . pack('v', min($count, self::MAX16))
			. pack('V', min($cd_size, self::MAX32)) . pack('V', min($cd_offset, self::MAX32))
			. pack('v', 0));
		$this->finished = true;
		return $this->offset;
	}

	// ------------------------------------------------------------------

	private function assertOpenForEntry(): void {
		if ($this->finished) {
			throw new LogicException('ZipStreamWriter: the archive is finished.');
		}
		if ($this->current !== null) {
			throw new LogicException('ZipStreamWriter: end the open file before starting another entry.');
		}
	}

	private function newEntry(string $path, ?int $mtime, int $method, bool $zip64, bool $descriptor): array {
		$mtime = $mtime ?? time();
		return array(
			'name'       => $path,
			'mtime'      => $mtime,
			'dos'        => self::dosTime($mtime),
			'method'     => $method,
			'zip64'      => $zip64,
			'flags'      => self::FLAG_UTF8 | ($descriptor ? self::FLAG_DESCRIPTOR : 0),
			'offset'     => $this->offset,
			'crc'        => 0,
			'size'       => 0,
			'csize'      => 0,
			'external'   => 0,
		);
	}

	private function localHeader(array $e): string {
		$extra = pack('v', 0x5455) . pack('v', 5) . pack('C', 1) . pack('V', $e['mtime'] & self::MAX32);
		if ($e['zip64']) {
			// Sizes follow in the descriptor as 64-bit values; this field is
			// what tells a reader so.
			$extra .= pack('v', 0x0001) . pack('v', 16) . pack('P', 0) . pack('P', 0);
		}
		$sizes = $e['zip64'] ? pack('V', self::MAX32) . pack('V', self::MAX32) : pack('V', 0) . pack('V', 0);
		return pack('V', self::SIG_LOCAL)
			. pack('v', $e['zip64'] ? 45 : 20)
			. pack('v', $e['flags'])
			. pack('v', $e['method'])
			. pack('v', $e['dos'][0]) . pack('v', $e['dos'][1])
			. pack('V', 0)
			. $sizes
			. pack('v', strlen($e['name'])) . pack('v', strlen($extra))
			. $e['name'] . $extra;
	}

	private function centralHeader(array $e): string {
		$big_offset = $e['offset'] >= self::MAX32;
		$zip64 = $e['zip64'] || $big_offset;
		$extra64 = '';
		if ($e['zip64']) {
			$extra64 .= pack('P', $e['size']) . pack('P', $e['csize']);
		}
		if ($big_offset || $this->force_zip64) {
			$extra64 .= pack('P', $e['offset']);
		}
		$extra = pack('v', 0x5455) . pack('v', 5) . pack('C', 1) . pack('V', $e['mtime'] & self::MAX32);
		if ($extra64 !== '') {
			$extra .= pack('v', 0x0001) . pack('v', strlen($extra64)) . $extra64;
		}
		$offset_field = ($big_offset || $this->force_zip64) ? self::MAX32 : $e['offset'];
		$version = $zip64 ? 45 : 20;
		return pack('V', self::SIG_CENTRAL)
			. pack('v', (3 << 8) | $version)
			. pack('v', $version)
			. pack('v', $e['flags'])
			. pack('v', $e['method'])
			. pack('v', $e['dos'][0]) . pack('v', $e['dos'][1])
			. pack('V', $e['crc'])
			. pack('V', $e['zip64'] ? self::MAX32 : $e['csize'])
			. pack('V', $e['zip64'] ? self::MAX32 : $e['size'])
			. pack('v', strlen($e['name'])) . pack('v', strlen($extra)) . pack('v', 0)
			. pack('v', 0) . pack('v', 0)
			. pack('V', $e['external'])
			. pack('V', $offset_field)
			. $e['name'] . $extra;
	}

	private function emit(string $bytes): void {
		$this->offset += strlen($bytes);
		call_user_func($this->sink, $bytes);
	}

	/** [time, date] in MS-DOS form, in UTC (the 0x5455 field carries the exact instant). */
	private static function dosTime(int $ts): array {
		$ts = max($ts, 315532800); // the format starts at 1980
		$y = (int)gmdate('Y', $ts);
		if ($y > 2107) {
			$ts = 4354819199; // and ends in 2107
			$y = 2107;
		}
		$time = ((int)gmdate('G', $ts) << 11) | ((int)gmdate('i', $ts) << 5) | ((int)gmdate('s', $ts) >> 1);
		$date = (($y - 1980) << 9) | ((int)gmdate('n', $ts) << 5) | (int)gmdate('j', $ts);
		return array($time, $date);
	}
}
