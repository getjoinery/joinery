<?php
/**
 * DocumentText — the platform's one answer to "what does this file say?".
 *
 * Two faces, and the split between them is the whole security design:
 *
 *   PARENT SIDE (any web request): normalizeMime(), categoryForMime(),
 *   isExtractable(), mimeForExtension(), extractPath(), extractBytes().
 *   These decide whether a file COULD be read and spawn the reader. They never
 *   open the bytes — not with finfo, not with ZipArchive, not with DOMDocument.
 *
 *   SANDBOX SIDE (utils/extract_document_text.php only): sandboxExtract() and
 *   everything under it. This is where attacker-supplied bytes are sniffed,
 *   unpacked and parsed, inside a short-lived `timeout N php -d memory_limit=…`
 *   subprocess. A bomb, a hang or a runaway allocation kills only that child;
 *   the parent reads the exit code and reports the file unreadable.
 *
 * Never call sandboxExtract() from a web request. The isolation is not a
 * nicety — an in-process memory fatal is uncatchable, and the parser retains
 * per-document state that accumulates across files in a long-lived process.
 *
 * Extracted text is ALWAYS valid UTF-8: it crosses a JSON boundary, and
 * json_encode() fails outright on a malformed sequence, so one Latin-1 .txt
 * would otherwise take down the whole response.
 *
 * See specs/safe_attachment_preview.md and docs/document_text.md.
 *
 * @version 1.2.0
 * @changelog 1.2.0 - emlText() parses through MimeParse (a body quoting its own
 *   MIME boundary hangs the Horde parser); toUtf8() is now the platform-wide
 *   charset ladder, delegated to by the mailbox ingest paths
 * @changelog 1.1.0 - review fixes: staged /dev/shm cleanup survives a killed child; RTF skip-group and \uN fallback correctness; unknown declared charsets fall back to detection; sandbox stdout pinned clean of PHP diagnostics; single hardened XML parse; canPreview() as the one eligibility rule; multi-attendee .ics
 */

/**
 * A parse that could not produce text. `kind` is a DocumentText status;
 * `category` is what the file turned out to be, when that was established
 * before the failure — a scanned PDF and an unreadable spreadsheet deserve
 * different wording, so the category survives the failure.
 */
class DocumentTextException extends Exception {
	public $kind;
	public $category;
	public function __construct(string $message, string $kind = 'failed', ?string $category = null) {
		parent::__construct($message);
		$this->kind = $kind;
		$this->category = $category;
	}
}

class DocumentText {

	/** Hard ceiling on the extraction subprocess wall-clock (seconds). */
	const TIMEOUT_SECONDS = 20;
	/** Hard memory ceiling handed to the extraction subprocess. */
	const MEMORY_LIMIT = '256M';
	/** Cap on returned text, in characters. */
	const DEFAULT_MAX_CHARS = 50000;

	/** Marker appended when text is cut at the character ceiling. */
	const TRUNCATION_MARKER = "\n\n[... attachment text truncated ...]";

	// Outcome markers. joinery_ai persists these in aia_extract_status, so the
	// first four VALUES are fixed — renaming one rewrites history.
	const OK        = 'ok';         // text present
	const EMPTY     = 'empty';      // parsed cleanly, no text layer (scanned)
	const FAILED    = 'failed';     // parser error, timeout, or OOM
	const SKIPPED   = 'skipped';    // not an extractable type
	const SECURED   = 'secured';    // encrypted / permission-restricted document
	const TOO_LARGE = 'too_large';  // over the byte ceiling, never parsed

	/**
	 * Detected MIME -> routing category. Keyed on the exact MIME; the one
	 * pattern rule is that any other `text/…` falls through to 'text', because a
	 * .log or .yml is exactly as safe to show as a .txt when it is shown AS
	 * text. Anything not resolvable here is not extractable, full stop.
	 */
	const CATEGORY = array(
		'application/pdf'          => 'pdf',
		'text/html'                => 'html',
		'application/xhtml+xml'    => 'html',
		'text/plain'               => 'text',
		'text/markdown'            => 'text',
		'text/x-markdown'          => 'text',
		'text/csv'                 => 'text',
		'application/csv'          => 'text',
		'application/json'         => 'text',
		'application/x-yaml'       => 'text',
		'application/yaml'         => 'text',
		'application/toml'         => 'text',
		'application/x-sh'         => 'text',
		'application/sql'          => 'text',
		'application/javascript'   => 'text',
		'application/x-httpd-php'  => 'text',
		'text/rtf'                 => 'rtf',
		'application/rtf'          => 'rtf',
		'text/xml'                 => 'xml',
		'application/xml'          => 'xml',
		'image/svg+xml'            => 'xml',
		'message/rfc822'           => 'eml',
		'text/calendar'            => 'ics',
		'application/ics'          => 'ics',
		'text/x-vcalendar'         => 'ics',
		'application/zip'          => 'archive',
		'application/x-zip-compressed' => 'archive',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'application/vnd.oasis.opendocument.text'         => 'odf',
		'application/vnd.oasis.opendocument.spreadsheet'  => 'odf',
		'application/vnd.oasis.opendocument.presentation' => 'odf',
		'application/epub+zip'     => 'epub',
	);

	/**
	 * Extension -> canonical MIME. Needed because senders lie: 71% of the real
	 * PDFs in the mailbox corpus arrive declared application/octet-stream, and
	 * eligibility decided from the declared type alone would hide the button on
	 * most of them. This is a UI hint ONLY — the sandbox re-sniffs the bytes and
	 * that detected type is the only one a parser ever sees.
	 */
	const EXTENSION_MIME = array(
		'pdf'  => 'application/pdf',
		'htm'  => 'text/html',        'html' => 'text/html',
		'xhtml'=> 'application/xhtml+xml',
		'txt'  => 'text/plain',       'text' => 'text/plain',
		'log'  => 'text/plain',       'ini'  => 'text/plain',
		'conf' => 'text/plain',       'cfg'  => 'text/plain',
		'srt'  => 'text/plain',       'vtt'  => 'text/plain',
		'vcf'  => 'text/plain',       'tsv'  => 'text/plain',
		'md'   => 'text/markdown',    'markdown' => 'text/markdown',
		'csv'  => 'text/csv',
		'json' => 'application/json',
		'yml'  => 'application/x-yaml', 'yaml' => 'application/x-yaml',
		'toml' => 'application/toml',
		'sh'   => 'application/x-sh',
		'sql'  => 'application/sql',
		'js'   => 'application/javascript',
		'rtf'  => 'application/rtf',
		'xml'  => 'application/xml',  'svg'  => 'image/svg+xml',
		'eml'  => 'message/rfc822',
		'ics'  => 'text/calendar',    'ical' => 'text/calendar',
		'zip'  => 'application/zip',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp'  => 'application/vnd.oasis.opendocument.presentation',
		'epub' => 'application/epub+zip',
	);

	// Zip-container bounds, enforced before any member is read. These sit INSIDE
	// the subprocess, so they are a second line behind timeout + memory_limit,
	// never a replacement for them.
	const ZIP_MAX_ENTRY_BYTES = 12582912;   // 12MB per member, uncompressed
	const ZIP_MAX_TOTAL_BYTES = 41943040;   // 40MB summed, uncompressed
	const ZIP_MAX_RATIO       = 200;        // uncompressed:compressed, above 1MB
	const ZIP_MAX_PARTS       = 400;        // slides / chapters read
	const ZIP_MAX_SHEETS      = 50;         // worksheets read
	const ARCHIVE_MAX_LISTED  = 500;        // entries named in a manifest

	// ---- Classification (parent side; opens nothing) -----------------------

	/** Bare MIME (strip any ;charset= parameter), lowercased. */
	public static function normalizeMime($mime): string {
		$mime = strtolower(trim((string)$mime));
		$semi = strpos($mime, ';');
		if ($semi !== false) $mime = trim(substr($mime, 0, $semi));
		return $mime;
	}

	/** Routing category for a MIME, or null when nothing here can read it. */
	public static function categoryForMime($mime): ?string {
		$mime = self::normalizeMime($mime);
		if ($mime === '') return null;
		if (isset(self::CATEGORY[$mime])) return self::CATEGORY[$mime];
		// Any other text/* is text. Deliberately last, so text/html, text/rtf,
		// text/xml and text/calendar keep their own parsers.
		if (strpos($mime, 'text/') === 0) return 'text';
		return null;
	}

	/** True when this MIME names something we can turn into readable text. */
	public static function isExtractable($mime): bool {
		return self::categoryForMime($mime) !== null;
	}

	/**
	 * Should this file be OFFERED a text preview? The one eligibility rule:
	 * whoever draws the button, whoever answers the endpoint, and whatever
	 * tests either, all call this — so they cannot drift apart. A UI hint
	 * only: the sandbox re-sniffs the bytes, and that detected type is the
	 * only one a parser ever sees.
	 */
	public static function canPreview($declared, ?string $filename): bool {
		return self::isExtractable(self::bestMimeGuess($declared, $filename));
	}

	/** Canonical MIME for a filename extension, or null. Case-insensitive; a
	 *  full filename works as well as a bare extension. */
	public static function mimeForExtension(?string $ext): ?string {
		if ($ext === null) return null;
		$ext = strtolower(trim($ext));
		if ($ext === '') return null;
		$dot = strrpos($ext, '.');
		if ($dot !== false) $ext = substr($ext, $dot + 1);
		return self::EXTENSION_MIME[$ext] ?? null;
	}

	/** The MIME to treat a file as, preferring a declared type we understand and
	 *  falling back to what its name claims. Hint only — see EXTENSION_MIME. */
	public static function bestMimeGuess($declared, ?string $filename): string {
		$declared = self::normalizeMime($declared);
		if (self::isExtractable($declared)) return $declared;
		$byExt = self::mimeForExtension($filename);
		return $byExt !== null ? $byExt : $declared;
	}

	// ---- Extraction (parent side; spawns the sandbox) ----------------------

	/**
	 * Extract text from a file on local disk.
	 *
	 * @return array{status:string, category:?string, text:string, detail:?string}
	 *         Never throws. text is always valid UTF-8.
	 */
	public static function extractPath(string $path, $mime = '', int $maxChars = self::DEFAULT_MAX_CHARS): array {
		if (!is_file($path) || !is_readable($path)) {
			return self::result(self::FAILED, null, '', 'unreadable path');
		}
		return self::run($path, null, self::normalizeMime($mime), $maxChars);
	}

	/**
	 * Extract text from bytes already in memory. The bytes go to the subprocess
	 * over stdin and never touch disk-backed storage — which is the point on a
	 * protected mailbox, where the plaintext is sealed content and /tmp is
	 * exactly where it must not land.
	 *
	 * @return array{status:string, category:?string, text:string, detail:?string}
	 */
	public static function extractBytes(string $bytes, $mime = '', int $maxChars = self::DEFAULT_MAX_CHARS): array {
		if ($bytes === '') {
			return self::result(self::EMPTY, null, '', 'no bytes');
		}
		return self::run('-', $bytes, self::normalizeMime($mime), $maxChars);
	}

	/**
	 * Spawn `timeout N php -d memory_limit=… utils/extract_document_text.php`
	 * and interpret what comes back.
	 *
	 * The parent PUMPS rather than write-then-read. Up to 15MB can go down
	 * stdin; if the child rejects the input early, stops reading and writes to
	 * stdout, a naive write-everything-first parent blocks on a full pipe
	 * opposite a child blocked the same way — a deadlock `timeout` converts into
	 * a guaranteed 20-second stall on every bad file.
	 */
	private static function run(string $path, ?string $stdin, string $mime, int $maxChars): array {
		$script = PathHelper::getIncludePath('utils/extract_document_text.php');
		if (!is_file($script)) {
			error_log('DocumentText: extractor script missing at ' . $script);
			return self::result(self::FAILED, null, '', 'extractor missing');
		}

		// display_errors is pinned to stderr: interpret() requires stdout to
		// begin exactly with `category=`, and on a php.ini with display_errors=On
		// the CLI default sink is stdout — one deprecation notice from a parser
		// library would corrupt the header protocol and render as document text.
		$cmd = 'timeout ' . (int)self::TIMEOUT_SECONDS . ' '
			 . escapeshellarg(self::phpBinary())
			 . ' -d ' . escapeshellarg('memory_limit=' . self::MEMORY_LIMIT)
			 . ' -d ' . escapeshellarg('display_errors=stderr')
			 . ' ' . escapeshellarg($script)
			 . ' ' . escapeshellarg($path)
			 . ' ' . escapeshellarg($mime !== '' ? $mime : 'application/octet-stream')
			 . ' ' . max(1, $maxChars);

		$pipes = array();
		$proc = @proc_open($cmd, array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if (!is_resource($proc)) {
			return self::result(self::FAILED, null, '', 'could not spawn extractor');
		}

		$out = self::pump($pipes, $stdin);
		$exit = proc_close($proc);
		self::sweepStaleStaged();

		$label = ($path === '-' ? strlen((string)$stdin) . ' stdin bytes' : $path)
			. ', hint ' . ($mime !== '' ? $mime : 'none');
		return self::interpret($exit, $out['stdout'], $out['stderr'], $label);
	}

	/**
	 * Remove staged container files a killed child left behind. The sandbox
	 * unlinks its own staging as soon as the container is open, but a SIGKILL
	 * between stage() and that unlink skips every in-process cleanup — so the
	 * parent, which always survives, sweeps anything older than a live child
	 * could still be holding. Decrypted sealed bytes must not outlive the
	 * extraction that staged them.
	 */
	private static function sweepStaleStaged(): void {
		$stale = time() - (self::TIMEOUT_SECONDS + 10);
		foreach ((array)@glob('/dev/shm/joinery_doctext_*') as $f) {
			$mtime = @filemtime($f);
			if ($mtime !== false && $mtime < $stale) @unlink($f);
		}
	}

	/**
	 * Write $stdin (if any) while draining stdout/stderr, so neither side can
	 * fill a pipe and block the other.
	 */
	private static function pump(array $pipes, ?string $stdin): array {
		stream_set_blocking($pipes[0], false);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		// Written from an advancing offset: rewriting a multi-MB buffer's tail
		// after every 64KB write would be quadratic in the input size.
		$to_write = ($stdin === null) ? '' : $stdin;
		$w_off = 0;
		$w_len = strlen($to_write);
		if ($stdin === null) {
			fclose($pipes[0]);
			$pipes[0] = null;
		}

		$stdout = '';
		$stderr = '';
		while (true) {
			$read = array();
			if (is_resource($pipes[1])) $read[] = $pipes[1];
			if (is_resource($pipes[2])) $read[] = $pipes[2];
			$write = (is_resource($pipes[0])) ? array($pipes[0]) : array();
			if (!count($read) && !count($write)) break;

			$except = null;
			$ready = @stream_select($read, $write, $except, 30);
			if ($ready === false) break;
			if ($ready === 0) break;   // the child is under `timeout`; it will die

			foreach ($write as $w) {
				$n = @fwrite($w, substr($to_write, $w_off, 65536));
				if ($n === false || $n === 0) {
					// The child closed stdin (it has all it needs, or it refused
					// the input). Not an error — stop writing and keep reading.
					fclose($pipes[0]);
					$pipes[0] = null;
					$w_off = $w_len;
					break;
				}
				$w_off += $n;
				if ($w_off >= $w_len) {
					fclose($pipes[0]);
					$pipes[0] = null;
				}
			}
			foreach ($read as $r) {
				$chunk = fread($r, 65536);
				if ($chunk === '' || $chunk === false) {
					if (feof($r)) {
						if ($r === $pipes[1]) { fclose($pipes[1]); $pipes[1] = null; }
						else { fclose($pipes[2]); $pipes[2] = null; }
					}
					continue;
				}
				if ($r === $pipes[1]) $stdout .= $chunk; else $stderr .= $chunk;
			}
		}

		foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
		return array('stdout' => $stdout, 'stderr' => $stderr);
	}

	/** Exit code + stdout -> the caller's result array. */
	private static function interpret(int $exit, string $stdout, string $stderr, string $label = ''): array {
		if ($exit === 124) {
			error_log('DocumentText: extraction timed out (' . $label . ')');
			return self::result(self::FAILED, null, '', 'timed out');
		}
		if ($exit === 137) {
			error_log('DocumentText: extraction OOM-killed (' . $label . ')');
			return self::result(self::FAILED, null, '', 'ran out of memory');
		}

		// Stdout is `category=<cat>` + blank line + text. The header is written
		// by the sandbox because the parent no longer detects the type itself.
		$category = null;
		$text = $stdout;
		if (strpos($stdout, 'category=') === 0) {
			$brk = strpos($stdout, "\n\n");
			if ($brk !== false) {
				$category = trim(substr($stdout, 9, $brk - 9));
				$text = substr($stdout, $brk + 2);
			}
		}
		if ($category === '') $category = null;

		$detail = trim($stderr);
		if ($detail === '') $detail = null;
		if ($detail !== null && strlen($detail) > 300) $detail = substr($detail, 0, 300);

		if ($exit === 5) return self::result(self::SECURED, $category, '', $detail);
		if ($exit === 4) return self::result(self::SKIPPED, $category, '', $detail);
		if ($exit !== 0) return self::result(self::FAILED, $category, '', $detail);

		$text = trim($text);
		if ($text === '') return self::result(self::EMPTY, $category, '', $detail);
		return self::result(self::OK, $category, $text, null);
	}

	private static function result(string $status, ?string $category, string $text, ?string $detail): array {
		return array('status' => $status, 'category' => $category, 'text' => $text, 'detail' => $detail);
	}

	/** Absolute CLI php path (matches ChatWorkerSpawner's resolution). */
	private static function phpBinary(): string {
		foreach (array(PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php') as $c) {
			if (@is_executable($c)) return $c;
		}
		return 'php';
	}

	// =====================================================================
	// SANDBOX SIDE — everything below runs only inside the extraction
	// subprocess (utils/extract_document_text.php). Never call it from a
	// web request: these methods open attacker-supplied bytes.
	// =====================================================================

	/**
	 * Detect, route and parse. $mimeHint is advisory only — consulted when
	 * finfo cannot tell (a .csv and a .txt are the same bytes), never trusted
	 * over what the bytes say.
	 *
	 * @return array{status:string, category:?string, text:string, detail:?string}
	 * @throws DocumentTextException on a parse the caller must report as failed.
	 */
	public static function sandboxExtract(string $bytes, string $mimeHint, int $maxChars): array {
		// The comment above is a promise; this makes it a fact. A web request
		// reaching this line would parse hostile bytes with no timeout, no
		// memory ceiling, and an uncatchable fatal on the table.
		if (PHP_SAPI !== 'cli') {
			throw new DocumentTextException('sandboxExtract runs only in the extraction subprocess');
		}
		$detected = self::sniff($bytes);
		$mimeHint = self::normalizeMime($mimeHint);

		// A zip container's real identity is its members, not its magic bytes:
		// docx, xlsx, pptx, odt and epub can all sniff as application/zip.
		$staged = null;
		if (self::isZipContainer($detected)) {
			$staged = self::stage($bytes);
			$detected = self::refineContainerMime($staged, $detected);
		}

		$category = self::categoryForMime($detected);
		if ($category === null) {
			// finfo says octet-stream on plenty of perfectly readable files.
			// Fall back to what the caller claimed only when detection gave up.
			$category = self::categoryForMime($mimeHint);
			if ($category !== null) $detected = $mimeHint;
		}
		if ($category === null) {
			if ($staged !== null) @unlink($staged);
			throw new DocumentTextException('unsupported type: ' . $detected, self::SKIPPED);
		}

		try {
			$text = self::parse($category, $detected, $bytes, $staged, $mimeHint);
		} catch (DocumentTextException $e) {
			// Carry what the file turned out to be through the failure, so the
			// caller can say "this PDF is encrypted" rather than "unreadable".
			if ($e->category === null) $e->category = $category;
			throw $e;
		} finally {
			if ($staged !== null) @unlink($staged);
		}

		return self::result(self::OK, $category, self::finish($text, $maxChars), null);
	}

	/** Route one category to its parser. */
	private static function parse(string $category, string $mime, string $bytes,
			?string $staged, string $hint): string {
		switch ($category) {
			case 'pdf':     return self::pdfText($bytes);
			case 'html':    return self::htmlText(self::toUtf8($bytes));
			case 'xml':     return self::xmlText(self::toUtf8($bytes), array(), true);
			case 'rtf':     return self::rtfText($bytes);
			case 'eml':     return self::emlText($bytes);
			case 'ics':     return self::icsText(self::toUtf8($bytes));
			case 'docx':    return self::docxText(self::requireStaged($staged));
			case 'xlsx':    return self::xlsxText(self::requireStaged($staged));
			case 'pptx':    return self::pptxText(self::requireStaged($staged));
			case 'odf':     return self::odfText(self::requireStaged($staged));
			case 'epub':    return self::epubText(self::requireStaged($staged));
			case 'archive': return self::archiveText(self::requireStaged($staged));
			case 'text':
			default:        return self::toUtf8($bytes);
		}
	}

	private static function requireStaged(?string $staged): string {
		if ($staged === null || !is_file($staged)) {
			throw new DocumentTextException('container could not be staged for reading');
		}
		return $staged;
	}

	/** What the BYTES are, per libmagic. The authoritative type. */
	public static function sniff(string $bytes): string {
		if (!function_exists('finfo_open')) return '';
		$fi = @finfo_open(FILEINFO_MIME_TYPE);
		if ($fi === false) return '';
		$mime = @finfo_buffer($fi, $bytes);
		@finfo_close($fi);
		return self::normalizeMime($mime === false ? '' : $mime);
	}

	private static function isZipContainer(string $mime): bool {
		return $mime === 'application/zip'
			|| $mime === 'application/x-zip-compressed'
			|| $mime === 'application/epub+zip'
			|| strpos($mime, 'opendocument') !== false
			|| strpos($mime, 'openxmlformats') !== false;
	}

	/**
	 * Resolve a zip container to the format it actually is by looking at which
	 * members it holds. Takes a PATH because ZipArchive can only open a file.
	 */
	public static function refineContainerMime(string $path, string $mime): string {
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) return $mime;
		try {
			$has = function ($name) use ($zip) { return $zip->locateName($name) !== false; };
			if ($has('word/document.xml')) {
				return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			}
			if ($has('xl/workbook.xml') || $has('xl/worksheets/sheet1.xml')) {
				return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			}
			if ($has('ppt/presentation.xml') || $has('ppt/slides/slide1.xml')) {
				return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
			}
			$declared = $zip->getFromName('mimetype');
			if (is_string($declared) && $declared !== '') {
				$declared = self::normalizeMime($declared);
				if (isset(self::CATEGORY[$declared])) return $declared;
			}
			if ($has('content.xml')) return 'application/vnd.oasis.opendocument.text';
			if ($has('META-INF/container.xml')) return 'application/epub+zip';
			return 'application/zip';
		} finally {
			$zip->close();
		}
	}

	/**
	 * Write container bytes somewhere ZipArchive can open them. openZip()
	 * unlinks the path the moment the parser has it open — the handle keeps
	 * working — so a timeout/OOM kill mid-parse (the phase where kills happen)
	 * leaves nothing behind; the parent's sweepStaleStaged() catches a kill in
	 * the brief window before that.
	 *
	 * /dev/shm is memory-backed tmpfs: its contents live in RAM and can reach
	 * persistent storage only if the host swaps, which is the same exposure the
	 * plaintext already has sitting in this process's own memory. Falling back
	 * to the system temp dir would put sealed content on disk, so a missing or
	 * unwritable /dev/shm is a refusal, not a downgrade.
	 */
	private static function stage(string $bytes): string {
		if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
			throw new DocumentTextException('no memory-backed temp available for container staging');
		}
		$path = '/dev/shm/joinery_doctext_' . getmypid() . '_' . bin2hex(random_bytes(6));
		if (@file_put_contents($path, $bytes) === false) {
			throw new DocumentTextException('could not stage container bytes');
		}
		@chmod($path, 0600);
		return $path;
	}

	// ---- parsers: PDF ------------------------------------------------------

	private static function pdfText(string $bytes): string {
		require_once(PathHelper::getComposerAutoloadPath());
		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf = $parser->parseContent($bytes);
			return (string)$pdf->getText();
		} catch (Throwable $e) {
			$msg = $e->getMessage();
			if (stripos($msg, 'secured') !== false || stripos($msg, 'encrypt') !== false) {
				throw new DocumentTextException('secured pdf', self::SECURED);
			}
			throw new DocumentTextException('pdf: ' . $msg);
		}
	}

	// ---- parsers: the one XML door ----------------------------------------

	/**
	 * XML -> visible text. EVERY xml-touching branch comes through here.
	 *
	 * The flags are the whole story. LIBXML_NONET is passed affirmatively.
	 * LIBXML_NOENT is never passed — with it, an entity declaring
	 * SYSTEM "file:///etc/passwd" puts that file's contents straight into the
	 * output; without it the same document is inert. LIBXML_DTDLOAD (fetches an
	 * external DTD) and LIBXML_PARSEHUGE (disables libxml's own
	 * entity-expansion limit, the billion-laughs guard) are never passed
	 * either. A branch that calls loadXML() itself is a defect, and
	 * tests/unit/document_text_test.php fails on one.
	 *
	 * $breakAfter names tags whose close should become a line break, so
	 * paragraphs and rows do not run together in the flattened text. For a
	 * format with no known paragraph tag (arbitrary XML, SVG), $splitNodes puts
	 * each text node on its own line instead — otherwise every value in the
	 * document arrives as one unreadable run.
	 */
	private static function xmlText(string $xml, array $breakAfter = array(),
			bool $splitNodes = false): string {
		foreach ($breakAfter as $tag) {
			$xml = str_replace('</' . $tag . '>', '</' . $tag . ">\n", $xml);
		}
		// The hardened parse lives in xmlDoc() and ONLY there — a second copy of
		// the flag set is a second place a future hardening can miss.
		$doc = self::xmlDoc($xml);
		if ($doc === null) return '';
		if (!$splitNodes) return (string)$doc->textContent;

		$lines = array();
		$xp = new DOMXPath($doc);
		foreach ($xp->query('//text()') as $node) {
			$value = trim((string)$node->nodeValue);
			if ($value !== '') $lines[] = $value;
		}
		return implode("\n", $lines);
	}

	/**
	 * HTML -> visible text. Block-level elements become line boundaries first:
	 * textContent alone runs a heading straight into the paragraph after it
	 * ("Chapter OneIt was a dark and stormy night."). script/style/head/noscript
	 * are removed rather than strip_tags()'d, which would keep CSS and JS
	 * bodies as noise.
	 */
	private static function htmlText(string $html): string {
		$prev = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) return '';

		foreach (array('script', 'style', 'head', 'noscript', 'template') as $tag) {
			$nodes = $doc->getElementsByTagName($tag);
			// Back-to-front: the live NodeList shrinks as we detach.
			for ($i = $nodes->length - 1; $i >= 0; $i--) {
				$n = $nodes->item($i);
				if ($n && $n->parentNode) $n->parentNode->removeChild($n);
			}
		}

		$blocks = array('p', 'div', 'br', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'section', 'article', 'header', 'footer', 'blockquote', 'pre', 'table',
			'ul', 'ol', 'dt', 'dd', 'hr');
		$xp = new DOMXPath($doc);
		foreach ($xp->query('//' . implode('|//', $blocks)) as $node) {
			if (!$node->parentNode) continue;
			$node->parentNode->insertBefore($doc->createTextNode("\n"), $node->nextSibling);
			$node->parentNode->insertBefore($doc->createTextNode("\n"), $node);
		}
		// Cells get a tab so columns stay legible.
		foreach ($xp->query('//td|//th') as $node) {
			if (!$node->parentNode) continue;
			$node->parentNode->insertBefore($doc->createTextNode("\t"), $node->nextSibling);
		}

		$body = $doc->getElementsByTagName('body')->item(0);
		$text = $body ? $body->textContent : $doc->textContent;
		return (string)preg_replace('/[ \t]+\n/', "\n", $text);
	}

	// ---- parsers: OOXML / ODF / EPUB / archive ------------------------------

	private static function docxText(string $path): string {
		list($zip, $names) = self::openZip($path);
		try {
			$parts = self::zipMembers($zip, $names,
				'#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#');
			if (!count($parts)) throw new DocumentTextException('docx: no word/document.xml');
			$out = array();
			foreach ($parts as $xml) {
				$out[] = self::xmlText($xml, array('w:p', 'w:tr'));
			}
			return implode("\n", $out);
		} finally {
			$zip->close();
		}
	}

	private static function xlsxText(string $path): string {
		list($zip, $names) = self::openZip($path);
		try {
			// A cell's text is an INDEX into sharedStrings.xml, not the text
			// itself. Skip this and a spreadsheet reads as a column of numbers.
			$shared = array();
			$ss = $zip->getFromName('xl/sharedStrings.xml');
			if (is_string($ss) && $ss !== '') {
				$doc = self::xmlDoc($ss);
				if ($doc !== null) {
					foreach ($doc->getElementsByTagName('si') as $si) {
						$shared[] = $si->textContent;
					}
				}
			}
			$titles = self::xlsxSheetNames($zip);
			$sheets = self::zipMembers($zip, $names, '#^xl/worksheets/sheet\d+\.xml$#', self::ZIP_MAX_SHEETS);
			if (!count($sheets)) throw new DocumentTextException('xlsx: no worksheets');

			$out = array();
			$n = 0;
			foreach ($sheets as $name => $xml) {
				$doc = self::xmlDoc($xml);
				if ($doc === null) { $n++; continue; }
				$out[] = '[' . ($titles[$n] ?? basename($name, '.xml')) . ']';
				$n++;
				foreach ($doc->getElementsByTagName('row') as $row) {
					$cells = array();
					foreach ($row->getElementsByTagName('c') as $c) {
						$t = $c->getAttribute('t');
						$vNode = $c->getElementsByTagName('v')->item(0);
						$v = $vNode ? $vNode->textContent : '';
						if ($t === 's') {
							$v = $shared[(int)$v] ?? '';
						} elseif ($t === 'inlineStr') {
							$v = $c->textContent;
						}
						$cells[] = $v;
					}
					if (trim(implode('', $cells)) !== '') $out[] = implode("\t", $cells);
				}
			}
			return implode("\n", $out);
		} finally {
			$zip->close();
		}
	}

	/** Sheet names in workbook order, so a tab is labelled what the user called it. */
	private static function xlsxSheetNames(ZipArchive $zip): array {
		$wb = $zip->getFromName('xl/workbook.xml');
		if (!is_string($wb) || $wb === '') return array();
		$doc = self::xmlDoc($wb);
		if ($doc === null) return array();
		$names = array();
		foreach ($doc->getElementsByTagName('sheet') as $sheet) {
			$names[] = $sheet->getAttribute('name');
		}
		return $names;
	}

	private static function pptxText(string $path): string {
		list($zip, $names) = self::openZip($path);
		try {
			$slides = self::zipMembers($zip, $names, '#^ppt/slides/slide\d+\.xml$#');
			$notes  = self::zipMembers($zip, $names, '#^ppt/notesSlides/notesSlide\d+\.xml$#');
			if (!count($slides)) throw new DocumentTextException('pptx: no slides');
			$out = array();
			$i = 1;
			foreach ($slides as $name => $xml) {
				$out[] = '[Slide ' . $i . ']';
				$out[] = self::xmlText($xml, array('a:p'));
				$noteName = 'ppt/notesSlides/notesSlide' . $i . '.xml';
				if (isset($notes[$noteName])) {
					$note = trim(self::xmlText($notes[$noteName], array('a:p')));
					// A notes part echoes the slide number as its own text run;
					// a note that is only that is not a note.
					if ($note !== '' && $note !== (string)$i) {
						$out[] = '[Speaker notes]';
						$out[] = $note;
					}
				}
				$i++;
			}
			return implode("\n", $out);
		} finally {
			$zip->close();
		}
	}

	private static function odfText(string $path): string {
		list($zip, $names) = self::openZip($path);
		try {
			$content = $zip->getFromName('content.xml');
			if (!is_string($content) || $content === '') {
				throw new DocumentTextException('odf: no content.xml');
			}
			return self::xmlText($content,
				array('text:p', 'text:h', 'table:table-row', 'table:table-cell'));
		} finally {
			$zip->close();
		}
	}

	private static function epubText(string $path): string {
		list($zip, $names) = self::openZip($path);
		try {
			$chapters = self::zipMembers($zip, $names, '#\.(xhtml|html|htm)$#i');
			if (!count($chapters)) throw new DocumentTextException('epub: no chapters');
			$out = array();
			foreach ($chapters as $html) {
				$t = trim(self::htmlText(self::toUtf8($html)));
				if ($t !== '') $out[] = $t;
			}
			return implode("\n\n", $out);
		} finally {
			$zip->close();
		}
	}

	/**
	 * An archive gets a MANIFEST, not text: entry names, sizes and dates.
	 * Nothing inside is decompressed or parsed — "what is in this zip" is a real
	 * question, answerable without opening anything.
	 */
	private static function archiveText(string $path): string {
		list($zip, $names) = self::openZip($path);
		try {
			$total = $zip->numFiles;
			$out = array($total . ($total === 1 ? ' item' : ' items') . ' in this archive:');
			$listed = 0;
			for ($i = 0; $i < $total; $i++) {
				$s = $zip->statIndex($i);
				if ($s === false) continue;
				if ($listed >= self::ARCHIVE_MAX_LISTED) {
					$out[] = '… and ' . ($total - $listed) . ' more.';
					break;
				}
				$out[] = "\t" . $s['name'] . "\t" . self::humanBytes((int)$s['size'])
					. "\t" . gmdate('Y-m-d', (int)$s['mtime']);
				$listed++;
			}
			return implode("\n", $out);
		} finally {
			$zip->close();
		}
	}

	// ---- parsers: RTF ------------------------------------------------------

	/**
	 * RTF -> body text, via a brace-depth state machine.
	 *
	 * Not a regex. RTF destination groups NEST, and a regex pass leaves the font
	 * table, the colour table and \info behind — which means the document's
	 * title and author land in the preview even though neither is body text.
	 * Tracking the depth at which a skipped destination opened is the only way
	 * to know where it ends.
	 */
	private static function rtfText(string $rtf): string {
		static $skip = array('fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object',
			'themedata', 'datastore', 'latentstyles', 'listtable', 'rsidtbl', 'generator',
			'filetbl', 'xmlnstbl', 'revtbl', 'upr', 'panose', 'falt', 'objdata', 'nonshppict');

		$out = '';
		$len = strlen($rtf);
		$depth = 0;
		$skipDepth = null;   // brace depth at which the skipped group opened
		$ucSkip = 1;         // \ucN: characters following a \u to discard
		$i = 0;

		while ($i < $len) {
			$c = $rtf[$i];
			if ($c === '{') { $depth++; $i++; continue; }
			if ($c === '}') {
				if ($skipDepth !== null && $depth === $skipDepth) $skipDepth = null;
				$depth--; $i++; continue;
			}
			if ($c === '\\') {
				// Control symbol (a non-letter follows the backslash).
				if ($i + 1 < $len && !ctype_alpha($rtf[$i + 1])) {
					$sym = $rtf[$i + 1];
					if ($sym === "'" && $i + 3 < $len) {          // \'hh hex byte
						if ($skipDepth === null) {
							$out .= self::cp1252(chr(hexdec(substr($rtf, $i + 2, 2))));
						}
						$i += 4; continue;
					}
					if ($sym === '*') {                           // ignorable destination
						if ($skipDepth === null) $skipDepth = $depth;
						$i += 2; continue;
					}
					if ($sym === '\\' || $sym === '{' || $sym === '}') {
						if ($skipDepth === null) $out .= $sym;
						$i += 2; continue;
					}
					if ($sym === '~') { if ($skipDepth === null) $out .= ' '; $i += 2; continue; }
					if ($sym === "\n" || $sym === "\r") { if ($skipDepth === null) $out .= "\n"; $i += 2; continue; }
					$i += 2; continue;
				}
				// \word[-][digits][delimiter space]
				$j = $i + 1;
				while ($j < $len && ctype_alpha($rtf[$j])) $j++;
				$word = substr($rtf, $i + 1, $j - $i - 1);
				$num = '';
				if ($j < $len && ($rtf[$j] === '-' || ctype_digit($rtf[$j]))) {
					$k = $j;
					if ($rtf[$k] === '-') $k++;
					while ($k < $len && ctype_digit($rtf[$k])) $k++;
					$num = substr($rtf, $j, $k - $j);
					$j = $k;
				}
				if ($j < $len && $rtf[$j] === ' ') $j++;   // the delimiter space is not text

				// Only start a skip when not already inside one: a \pict nested in
				// the font table must not shrink the skip to its own depth, or the
				// rest of the font table leaks into the output as body text.
				if (in_array($word, $skip, true)) {
					if ($skipDepth === null) $skipDepth = $depth;
					$i = $j; continue;
				}
				if ($skipDepth === null) {
					if ($word === 'par' || $word === 'line' || $word === 'sect' || $word === 'page') {
						$out .= "\n";
					} elseif ($word === 'cell' || $word === 'tab') {
						$out .= "\t";
					} elseif ($word === 'row') {
						$out .= "\n";
					} elseif ($word === 'uc') {
						$ucSkip = max(0, (int)$num);
					} elseif ($word === 'u') {
						$cp = (int)$num;
						if ($cp < 0) $cp += 65536;
						$ch = function_exists('mb_chr') ? mb_chr($cp, 'UTF-8') : false;
						$out .= ($ch === false || $ch === null) ? '' : $ch;
						// Discard the ucSkip replacement characters that follow.
						// A fallback may be a literal character OR a \'hh hex
						// escape (what Word emits); each \'hh is ONE fallback
						// character and is consumed as a unit — treating it as a
						// stopper would render every \u character twice.
						$skipped = 0;
						while ($skipped < $ucSkip && $j < $len) {
							if ($rtf[$j] === '\\' && $j + 3 < $len && $rtf[$j + 1] === "'") {
								$j += 4; $skipped++; continue;
							}
							if ($rtf[$j] === '\\' || $rtf[$j] === '{' || $rtf[$j] === '}') break;
							$j++; $skipped++;
						}
					}
				}
				$i = $j; continue;
			}
			if ($skipDepth === null && $c !== "\r" && $c !== "\n") $out .= $c;
			$i++;
		}
		return $out;
	}

	// ---- parsers: forwarded mail and calendar invites ------------------------

	/**
	 * A forwarded .eml: the headers a reader cares about, then the text body.
	 * Parsed with the same Horde MIME parser the mailbox itself uses, so a
	 * forwarded message reads the way it would if it had arrived directly.
	 */
	private static function emlText(string $bytes): string {
		require_once(PathHelper::getComposerAutoloadPath());
		try {
			$split = preg_split("/\r?\n\r?\n/", $bytes, 2);
			$headerText = $split[0] ?? '';
			$out = array();
			$headers = Horde_Mime_Headers::parseHeaders($headerText);
			foreach (array('From', 'To', 'Cc', 'Date', 'Subject') as $name) {
				$value = self::emlHeader($headers, $name);
				if ($value !== '') $out[] = $name . ': ' . $value;
			}
			if (count($out)) $out[] = '';

			$message = MimeParse::parseMessage($bytes);
			$body = '';
			foreach ($message->contentTypeMap() as $section => $type) {
				$type = self::normalizeMime($type);
				if ($type !== 'text/plain' && $type !== 'text/html') continue;
				$part = $message->getPart($section);
				if ($part === null) continue;
				$disposition = strtolower((string)$part->getDisposition());
				if ($disposition === 'attachment') continue;
				$content = (string)$part->getContents();
				if (trim($content) === '') continue;
				$content = self::toUtf8($content, (string)$part->getCharset());
				$body = ($type === 'text/html') ? self::htmlText($content) : $content;
				if (trim($body) !== '') break;
			}
			$out[] = $body;
			return implode("\n", $out);
		} catch (DocumentTextException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new DocumentTextException('eml: ' . $e->getMessage());
		}
	}

	/** One header value as a plain decoded string, or ''. */
	private static function emlHeader($headers, string $name): string {
		if (!$headers) return '';
		try {
			$element = $headers->getHeader($name);
			if ($element === null) return '';
			$value = $element->value;
			if (is_array($value)) $value = count($value) ? implode(', ', $value) : '';
			$value = (string)$value;
			if ($value !== '' && class_exists('Horde_Mime')) {
				$value = (string)Horde_Mime::decode($value);
			}
			return self::toUtf8(trim($value));
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * A calendar invite as the readable summary of its events — what, when,
	 * where, who — rather than raw iCalendar property lines. Reuses the
	 * platform's own .ics reader so a preview and an import agree on what the
	 * file says.
	 */
	private static function icsText(string $ics): string {
		$parsed = IcsImporter::parse($ics);
		$events = $parsed['events'] ?? array();
		if (!count($events)) {
			$name = $parsed['calendar']['X-WR-CALNAME'] ?? '';
			return trim('Calendar file with no events.' . ($name !== '' ? "\nCalendar: " . $name : ''));
		}

		$out = array();
		$labels = array('SUMMARY' => 'Event', 'DTSTART' => 'Starts', 'DTEND' => 'Ends',
			'LOCATION' => 'Location', 'ORGANIZER' => 'Organizer', 'STATUS' => 'Status',
			'RRULE' => 'Repeats', 'DESCRIPTION' => 'Description');
		foreach ($events as $event) {
			$props = $event['props'] ?? array();
			foreach ($labels as $key => $label) {
				if (!isset($props[$key])) continue;
				$value = trim((string)$props[$key]['value']);
				if ($value === '') continue;
				if ($key === 'ORGANIZER') $value = preg_replace('/^mailto:/i', '', $value);
				$out[] = $label . ': ' . $value;
			}
			// ATTENDEE repeats per person, so the parser hands them over as a
			// list — a props slot would keep only the last name on the invite.
			foreach (($event['attendees'] ?? array()) as $attendee) {
				$out[] = 'Attendee: ' . preg_replace('/^mailto:/i', '', trim((string)$attendee['value']));
			}
			$out[] = '';
		}
		if (!empty($parsed['truncated'])) {
			$out[] = 'This calendar file ends mid-event; the last entry is incomplete.';
		}
		return implode("\n", $out);
	}

	// ---- zip plumbing -------------------------------------------------------

	/**
	 * Open a container and bound it BEFORE anything is read: per-member size,
	 * summed size, and compression ratio. A 61KB docx expanding to 60MB in one
	 * member is refused here, not discovered by the memory limit.
	 *
	 * @return array{0:ZipArchive, 1:array}
	 */
	private static function openZip(string $path): array {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
			// CHECKCONS rejects containers plenty of real writers produce; retry
			// plainly rather than refusing a file the user can open elsewhere.
			if ($zip->open($path) !== true) {
				throw new DocumentTextException('not a readable zip container');
			}
		}
		// The staged file's directory entry goes away here; the open handle
		// keeps reading. From this point a timeout or OOM kill cannot leave
		// decrypted container bytes behind in /dev/shm — and parsing, below,
		// is exactly where those kills happen.
		@unlink($path);
		$total = 0;
		$names = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$s = $zip->statIndex($i);
			if ($s === false) continue;
			$size = (int)$s['size'];
			$comp = (int)$s['comp_size'];
			if ($size > self::ZIP_MAX_ENTRY_BYTES) {
				$zip->close();
				throw new DocumentTextException('zip member too large: ' . $s['name']);
			}
			if ($comp > 0 && $size > 1048576 && ($size / $comp) > self::ZIP_MAX_RATIO) {
				$zip->close();
				throw new DocumentTextException('zip member compression ratio too high: ' . $s['name']);
			}
			$total += $size;
			if ($total > self::ZIP_MAX_TOTAL_BYTES) {
				$zip->close();
				throw new DocumentTextException('zip total uncompressed size too large');
			}
			$names[] = $s['name'];
		}
		return array($zip, $names);
	}

	/** Members matching a pattern, in natural order, capped. */
	private static function zipMembers(ZipArchive $zip, array $names, string $pattern,
			int $cap = self::ZIP_MAX_PARTS): array {
		$hit = array_values(array_filter($names, function ($n) use ($pattern) {
			return (bool)preg_match($pattern, $n);
		}));
		natsort($hit);
		$hit = array_slice(array_values($hit), 0, $cap);

		$out = array();
		foreach ($hit as $name) {
			$content = $zip->getFromName($name);
			if (is_string($content) && $content !== '') $out[$name] = $content;
		}
		return $out;
	}

	/**
	 * THE hardened XML parse — the only loadXML() call in the class. The flags
	 * are the whole story (see xmlText()); behind them, a document that
	 * declares internal entities at all is refused rather than flattened.
	 */
	private static function xmlDoc(string $xml): ?DOMDocument {
		$prev = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) return null;
		if ($doc->doctype && $doc->doctype->internalSubset
				&& stripos($doc->doctype->internalSubset, '<!ENTITY') !== false) {
			throw new DocumentTextException('xml declares internal entities');
		}
		return $doc;
	}

	// ---- text hygiene -------------------------------------------------------

	/**
	 * Anything -> valid UTF-8. Unconditional: the result crosses a JSON
	 * boundary and json_encode() fails outright on a malformed sequence.
	 */
	public static function toUtf8(string $bytes, string $charset = ''): string {
		$charset = trim($charset);
		if ($charset !== '' && strtolower($charset) !== 'us-ascii') {
			// The declared charset is sender-authored, and senders declare
			// nonsense ('unknown-8bit' is what bounces and plenty of spam say).
			// In PHP 8 an unknown charset makes mb_convert_encoding THROW —
			// @ silences warnings, not ValueError — so both converters are
			// caught, and a useless declaration falls through to detection
			// instead of failing the whole extraction.
			$converted = false;
			try { $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset); } catch (Throwable $e) {}
			if (!is_string($converted) || $converted === '') {
				try { $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes); } catch (Throwable $e) {}
			}
			if (is_string($converted) && $converted !== '') return self::scrub($converted);
		}
		if (mb_check_encoding($bytes, 'UTF-8')) return self::scrub($bytes);
		$charset = mb_detect_encoding($bytes, array('UTF-8', 'Windows-1252', 'ISO-8859-1'), true);
		if ($charset === false) $charset = 'Windows-1252';
		$converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
		if (!is_string($converted) || $converted === '') {
			$converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
		}
		return self::scrub(is_string($converted) ? $converted : $bytes);
	}

	/** Drop anything still not valid UTF-8, plus control bytes that are not
	 *  whitespace (a binary run inside a "text" file). */
	private static function scrub(string $text): string {
		$prev = mb_substitute_character();
		mb_substitute_character(0xFFFD);
		$out = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		mb_substitute_character($prev);
		if (!is_string($out)) $out = $text;
		return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $out);
	}

	private static function cp1252(string $byte): string {
		$out = @mb_convert_encoding($byte, 'UTF-8', 'Windows-1252');
		return is_string($out) ? $out : '';
	}

	/**
	 * Normalize and cap. Truncation is multibyte-safe — cutting mid-sequence
	 * would produce exactly the malformed UTF-8 the contract forbids.
	 */
	public static function finish(string $text, int $maxChars): string {
		$text = str_replace("\r\n", "\n", $text);
		$text = str_replace("\r", "\n", $text);
		$text = self::scrub($text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		$text = trim($text);
		if ($maxChars > 0 && mb_strlen($text, 'UTF-8') > $maxChars) {
			$text = mb_substr($text, 0, $maxChars, 'UTF-8') . self::TRUNCATION_MARKER;
		}
		return $text;
	}

	/** True when this text carries the truncation marker. */
	public static function wasTruncated(string $text): bool {
		return substr($text, -strlen(self::TRUNCATION_MARKER)) === self::TRUNCATION_MARKER;
	}

	private static function humanBytes(int $n): string {
		if ($n >= 1048576) return round($n / 1048576, 1) . ' MB';
		if ($n >= 1024) return round($n / 1024) . ' KB';
		return $n . ' B';
	}
}
