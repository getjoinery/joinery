<?php
/**
 * MimeSniffer - what kind of file a stranger's bytes are, asked outside the
 * web worker.
 *
 * Telling a file's type from its content means handing its bytes to libmagic,
 * a C library that reads dozens of formats' headers. Every stored file is
 * asked, and an inbound mail attachment comes from anyone on the internet. So
 * the question is never answered in a web worker, a cron task or the mail
 * pipe: File::detect_mime_file() and detect_mime_bytes() answer the commonest
 * types from their leading bytes in plain PHP, and bring everything else
 * here.
 *
 * Two ways of asking, both fenced where the parser jail is installed
 * (docs/document_text.md):
 *
 *   1. The `file` program (the same libmagic, 5.46 on Ubuntu 26.04, as PHP's
 *      own fileinfo), through DocumentText::runTool(): the bytes on stdin, the
 *      type on stdout, its magic database the one other thing it may read.
 *      About 8 ms. `file` is a declared system package.
 *   2. Where `file` or its database is missing, PHP's fileinfo in the
 *      extraction subprocess (this class as a SandboxParserInterface). Slower
 *      by the cost of starting PHP.
 *
 * An answer that is not a type is application/octet-stream: unknown bytes,
 * which every caller treats as the least trusted kind. A question that could
 * not be asked is null, so the caller can tell the two apart.
 *
 * @version 1.1 - sniff() answers null when nobody could be asked (after one more try), and asks without the fence
 *                when the launcher itself refuses to start (reviewer2 B9)
 * @version 1.0
 */
class MimeSniffer implements SandboxParserInterface {

	/** As much of a file as libmagic reads (its own limit is 7 MiB). */
	const READ_BYTES = 7340032;

	/** Wall-clock seconds for one question. */
	const TIMEOUT_SEC = 10;

	/** Address space for the `file` program. */
	const TOOL_LIMIT_BYTES = 268435456;

	const UNKNOWN = 'application/octet-stream';

	/** Test hook: a `file` path in place of the machine's, '' to pretend it is absent. Null restores. */
	public static $file_override = null;

	/** Test hook: how many questions left this process, by route. */
	public static $asked = array('tool' => 0, 'php' => 0);

	/**
	 * The type of these bytes: a MIME type libmagic named, UNKNOWN when it
	 * named none, or null when the question could not be asked at all (the
	 * launcher refused, the run timed out or was killed). The two are
	 * different facts: "libmagic does not know" is an answer worth keeping,
	 * "nobody was asked" is not, and a caller that stores the type must be
	 * able to ask again later (FileBlob::createFromPath()).
	 *
	 * A question that fails is asked once more. A launcher that refuses to
	 * start (its own exit 125: a broken install, never something the bytes
	 * did) is answered by the file program without the fence, the privilege
	 * libmagic had in the worker before; a run that timed out or died is
	 * not, since the bytes may be why.
	 */
	public static function sniff($bytes): ?string {
		$bytes = substr((string)$bytes, 0, self::READ_BYTES);
		$answer = self::ask($bytes);
		if ($answer === null) {
			$answer = self::ask($bytes);
		}
		return $answer;
	}

	private static function ask(string $bytes): ?string {
		$tool = self::tool();
		if ($tool !== null) {
			self::$asked['tool']++;
			$argv = array($tool['file'], '--mime-type', '-b', '-m', $tool['magic'], '-');
			$fenced = (self::$file_override === null);
			$r = DocumentText::runTool($argv, $bytes, self::TIMEOUT_SEC, self::TOOL_LIMIT_BYTES, $fenced, 4096, array($tool['magic']));
			if ((int)$r['exit'] === 125 && $fenced && !empty($r['fenced'])) {
				error_log('MimeSniffer: the parser jail launcher refused to start (' . substr(trim((string)$r['stderr']), 0, 200)
					. '); asking file(1) without the fence, as the web user');
				$r = DocumentText::runTool($argv, $bytes, self::TIMEOUT_SEC, self::TOOL_LIMIT_BYTES, false, 4096);
			}
			if ((int)$r['exit'] === 0 && empty($r['overflow'])) {
				return self::clean(strtok((string)$r['stdout'], "\n"));
			}
			error_log('MimeSniffer: file(1) did not answer (exit ' . (int)$r['exit'] . '): ' . substr(trim((string)$r['stderr']), 0, 200));
			return null;
		}
		self::$asked['php']++;
		$r = DocumentText::parseWith('MimeSniffer', $bytes);
		if ($r['status'] === DocumentText::OK) {
			return self::clean($r['text']);
		}
		if ($r['status'] === DocumentText::EMPTY) {
			return self::UNKNOWN;
		}
		error_log('MimeSniffer: the subprocess did not answer (' . $r['status'] . '): ' . (string)($r['detail'] ?? ''));
		return null;
	}

	/** A plain type/subtype in lowercase, or UNKNOWN. What the answer is used for never sees anything else. */
	private static function clean($mime): string {
		$mime = strtolower(trim((string)$mime));
		$semi = strpos($mime, ';');
		if ($semi !== false) {
			$mime = trim(substr($mime, 0, $semi));
		}
		return preg_match('#^[a-z0-9][a-z0-9.+-]{0,60}/[a-z0-9][a-z0-9.+-]{0,80}$#D', $mime) ? $mime : self::UNKNOWN;
	}

	/** The `file` program and its compiled magic database, as real paths, or null when either is missing. */
	private static function tool(): ?array {
		static $found = false;
		if (self::$file_override !== null) {
			return self::$file_override === '' ? null : array('file' => self::$file_override, 'magic' => '/dev/null');
		}
		if ($found !== false) {
			return $found;
		}
		$found = null;
		$file = is_executable('/usr/bin/file') ? realpath('/usr/bin/file') : false;
		if ($file !== false) {
			foreach (array('/usr/lib/file/magic.mgc', '/usr/share/misc/magic.mgc', '/usr/share/file/magic.mgc') as $candidate) {
				$magic = is_file($candidate) ? realpath($candidate) : false;
				if ($magic !== false) {
					$found = array('file' => $file, 'magic' => $magic);
					break;
				}
			}
		}
		if ($found === null) {
			error_log('MimeSniffer: the file program or its magic database is not installed (package file); types are read by PHP in the extraction subprocess, at more time per file');
		}
		return $found;
	}

	// ==================================================================
	// THE FENCED SIDE: runs only inside the extraction subprocess.
	// ==================================================================

	public static function sandboxParse(string $bytes, array $options): string {
		if (!function_exists('finfo_open')) {
			return self::UNKNOWN;
		}
		$finfo = @finfo_open(FILEINFO_MIME_TYPE);
		if (!$finfo) {
			return self::UNKNOWN;
		}
		$mime = @finfo_buffer($finfo, $bytes);
		return is_string($mime) && $mime !== '' ? $mime : self::UNKNOWN;
	}
}
