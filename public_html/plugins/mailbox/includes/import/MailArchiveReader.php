<?php
/**
 * MailArchiveReader - the contract every archive format implements, plus the
 * message-level parsing every format shares.
 *
 * Format support is a registry rather than a chain of ifs, so adding a format
 * later is one new class and one line in MailArchiveReaderRegistry. A reader has
 * three jobs and no others:
 *
 *   sniff()  - is this file mine?
 *   scan()   - walk the archive and emit one descriptor per message found
 *   read()   - given a descriptor's locator, hand back the raw RFC822 bytes
 *
 * A locator is PRIVATE to the reader that issued it. Nothing outside a reader
 * interprets one; the importer only ever hands it straight back.
 *
 * Scanning is resumable. scan() is given a state array and a wall-clock deadline,
 * and returns the state to resume from — so a 50GB archive is walked across as
 * many task passes as it takes without ever holding the whole thing in memory or
 * blocking the cron runner. State is opaque to the caller in the same way a
 * locator is.
 *
 * The static helpers below are the parts every format shares — .emlx framing,
 * mbox From-escaping, header parsing, provider label conventions, the synthesized
 * Message-ID. They are pure functions of their input, which is what makes the
 * whole reader layer testable without a database.
 *
 * See specs/mail_archive_import.md § 4.
 *
 * @version 1.0
 */

abstract class MailArchiveReader {

	/** Bytes of a member read to find its headers. Real headers are far smaller. */
	const HEADER_PEEK_BYTES = 65536;

	/** The registry key, and the value stored in mir_format. */
	abstract public static function key(): string;

	/** Human name for the format, shown on the run. */
	abstract public static function label(): string;

	/** True if this reader claims the file. Cheap checks only — no full parse. */
	abstract public static function sniff(string $path, string $filename): bool;

	/**
	 * Emit one descriptor per message, resuming from $state and stopping when
	 * $deadline (a microtime(true) value) passes. Returns the next state, which
	 * carries 'done' => true when the archive is exhausted.
	 *
	 * A descriptor is an assoc array with: locator (required), source_folder,
	 * labels[], class, is_read, is_starred, headers (the parsed header block, so
	 * the caller can resolve direction without re-reading the bytes).
	 *
	 * @param callable $emit function(array $descriptor): void
	 */
	abstract public function scan(string $path, callable $emit, array $state, float $deadline): array;

	/** The raw RFC822 bytes for one locator this reader issued. */
	abstract public function read(string $path, string $locator): string;

	/**
	 * Turn the source into something scan()/read() can work with, returning the
	 * path they should be given. Most formats are read in place and return $path
	 * unchanged; a sequential-access container expands into $workDir here.
	 */
	public function prepare(string $path, string $workDir): string {
		return $path;
	}

	/** Refuse-with-a-reason formats override this; everything else imports. */
	public function refusal(): ?string {
		return null;
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Strip Apple Mail's .emlx framing: a decimal byte count on its own first
	 * line, then exactly that many bytes of RFC822, then a plist trailer we do
	 * not want. A file that does not carry the count is already plain RFC822.
	 */
	public static function stripEmlx(string $raw): string {
		$nl = strpos($raw, "\n");
		if ($nl === false || $nl > 20) {
			return $raw;
		}
		$first = trim(substr($raw, 0, $nl));
		if ($first === '' || !ctype_digit($first)) {
			return $raw;
		}
		$length = intval($first);
		$body = substr($raw, $nl + 1);
		// A truncated file declares more than it holds; take what is actually there
		// rather than refusing a message that is otherwise readable.
		return ($length > 0 && $length <= strlen($body)) ? substr($body, 0, $length) : $body;
	}

	/**
	 * Undo mboxrd From-escaping. The format escapes any body line matching
	 * `>*From ` by adding one more `>`, so unescaping removes exactly one — which
	 * is why a line that legitimately began with `>From ` survives a round trip.
	 */
	public static function unescapeMboxFrom(string $body): string {
		return preg_replace('/^>(>*From )/m', '$1', $body) ?? $body;
	}

	/** The header block of a raw message — everything before the first blank line. */
	public static function headerBlock(string $raw): string {
		$normalized = str_replace("\r\n", "\n", $raw);
		$split = strpos($normalized, "\n\n");
		return ($split === false) ? $normalized : substr($normalized, 0, $split);
	}

	/**
	 * Parse a header block into lowercase-keyed values, folding continuation lines
	 * and collecting repeats (Received, X-Original-To) into arrays.
	 */
	public static function parseHeaders(string $block): array {
		$headers = array();
		$current = null;
		foreach (explode("\n", str_replace("\r\n", "\n", $block)) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				if (is_array($headers[$current])) {
					$headers[$current][count($headers[$current]) - 1] .= ' ' . trim($line);
				} else {
					$headers[$current] .= ' ' . trim($line);
				}
				continue;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$name = strtolower(trim(substr($line, 0, $colon)));
			$value = trim(substr($line, $colon + 1));
			if ($name === '') {
				continue;
			}
			if (isset($headers[$name])) {
				if (!is_array($headers[$name])) {
					$headers[$name] = array($headers[$name]);
				}
				$headers[$name][] = $value;
			} else {
				$headers[$name] = $value;
			}
			$current = $name;
		}
		return $headers;
	}

	/** First value of a header that may have repeated. */
	public static function header(array $headers, string $name): string {
		$v = $headers[strtolower($name)] ?? '';
		if (is_array($v)) {
			$v = $v[0] ?? '';
		}
		return trim((string)$v);
	}

	/**
	 * A stable Message-ID for a message that has none. Real archives contain them,
	 * and without an id the dedup key collapses and a re-import duplicates
	 * everything. Deriving it from the bytes makes it deterministic — the same
	 * message scanned twice gets the same id — and .invalid (RFC 2606) can never
	 * collide with a real domain.
	 */
	public static function synthesizeMessageId(string $raw): string {
		return '<' . hash('sha256', $raw) . '@import.invalid>';
	}

	/** The message's own Message-ID, or a synthesized one. Always angle-bracketed. */
	public static function messageId(array $headers, string $raw): string {
		$id = self::header($headers, 'message-id');
		if ($id !== '' && preg_match('/<[^>]+>/', $id, $m)) {
			return $m[0];
		}
		if ($id !== '') {
			return '<' . trim($id, '<> ') . '>';
		}
		return self::synthesizeMessageId($raw);
	}

	/**
	 * Maildir flags from a filename's ":2,<flags>" suffix — S seen, F flagged,
	 * T trashed. Absent suffix means an unread message in `new/`.
	 */
	public static function maildirFlags(string $filename): array {
		$out = array('read' => false, 'starred' => false, 'trashed' => false);
		$pos = strpos($filename, ':2,');
		if ($pos === false) {
			return $out;
		}
		$flags = substr($filename, $pos + 3);
		$out['read']    = strpos($flags, 'S') !== false;
		$out['starred'] = strpos($flags, 'F') !== false;
		$out['trashed'] = strpos($flags, 'T') !== false;
		return $out;
	}

	/**
	 * Gmail's X-Gmail-Labels, split into real labels and the pseudo-labels that
	 * are state rather than labels. Takeout writes read state as the PRESENCE of
	 * "Unread", so a message with no such label has been read.
	 *
	 * Returns ['labels'=>string[], 'is_read'=>bool, 'is_starred'=>bool,
	 *          'class'=>normal|spam|trash, 'folder'=>?string].
	 */
	public static function gmailLabels(array $headers): array {
		$raw = self::header($headers, 'x-gmail-labels');
		$out = array('labels' => array(), 'is_read' => true, 'is_starred' => false,
			'class' => 'normal', 'folder' => null);
		if ($raw === '') {
			// No Takeout header at all: nothing is known, so claim nothing. Unread
			// is the safer default here than read.
			$out['is_read'] = false;
			return $out;
		}

		$pseudo = array('inbox' => 'Inbox', 'sent' => 'Sent', 'draft' => 'Drafts',
			'important' => null, 'opened' => null, 'archived' => 'Archived',
			'chat' => null, 'unread' => null, 'starred' => null, 'spam' => 'Spam', 'trash' => 'Trash');

		foreach (explode(',', $raw) as $piece) {
			$label = trim($piece);
			if ($label === '') {
				continue;
			}
			$lower = strtolower($label);
			if ($lower === 'unread')  { $out['is_read'] = false;   continue; }
			if ($lower === 'starred') { $out['is_starred'] = true; continue; }
			if ($lower === 'spam')    { $out['class'] = 'spam';  $out['folder'] = 'Spam';  continue; }
			if ($lower === 'trash')   { $out['class'] = 'trash'; $out['folder'] = 'Trash'; continue; }
			if (strpos($lower, 'category ') === 0) {
				continue; // Gmail's tab assignment, not a label the user made
			}
			if (array_key_exists($lower, $pseudo)) {
				if ($pseudo[$lower] !== null && $out['folder'] === null) {
					$out['folder'] = $pseudo[$lower];
				}
				continue;
			}
			$out['labels'][] = $label;
		}

		if ($out['folder'] === null) {
			$out['folder'] = $out['labels'] ? $out['labels'][0] : 'All mail';
		}
		return $out;
	}

	/**
	 * Which bucket a folder name belongs to. Drives the Spam-and-Trash-off-by-
	 * default exclusion, so it recognises the names every export tool actually
	 * uses rather than one provider's.
	 */
	public static function classifyFolder(string $folder): string {
		$n = strtolower(trim($folder, " /\\"));
		$n = preg_replace('#^.*/#', '', $n); // deepest path segment
		$spam  = array('spam', 'junk', 'junk email', 'junk e-mail', 'bulk mail', '[gmail]/spam');
		$trash = array('trash', 'deleted', 'deleted items', 'deleted messages', 'bin', '[gmail]/trash');
		if (in_array($n, $spam, true))  { return 'spam'; }
		if (in_array($n, $trash, true)) { return 'trash'; }
		return 'normal';
	}

	/**
	 * Proton writes a "<id>.metadata.json" beside "<id>.eml" carrying the labels
	 * and flags its own UI showed. Read when present, ignored when not — a bare
	 * folder of .eml files still imports correctly, just with less state.
	 *
	 * Returns the same shape gmailLabels() does, or null when the JSON is absent
	 * or unreadable (never a reason to fail a message).
	 */
	public static function protonMetadata(?string $json): ?array {
		if ($json === null || trim($json) === '') {
			return null;
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}
		$out = array('labels' => array(), 'is_read' => true, 'is_starred' => false,
			'class' => 'normal', 'folder' => null);

		// Proton's export nests the interesting parts under Payload on some
		// versions and at the top level on others; accept either.
		$payload = is_array($data['Payload'] ?? null) ? $data['Payload'] : $data;

		if (array_key_exists('Unread', $payload)) {
			$out['is_read'] = !intval($payload['Unread']);
		}
		if (array_key_exists('Starred', $payload)) {
			$out['is_starred'] = (bool)$payload['Starred'];
		}

		foreach ((array)($payload['LabelIDs'] ?? $payload['Labels'] ?? array()) as $label) {
			if (is_array($label)) {
				$label = $label['Name'] ?? $label['ID'] ?? '';
			}
			$name = trim((string)$label);
			if ($name === '') {
				continue;
			}
			// Proton's numeric system label ids. 0 inbox, 2 sent, 3 trash, 4 spam,
			// 5 all mail, 6 archive, 10 starred.
			$system = array('0' => 'Inbox', '2' => 'Sent', '3' => 'Trash', '4' => 'Spam',
				'5' => 'All mail', '6' => 'Archived', '10' => null);
			if (array_key_exists($name, $system)) {
				if ($name === '10') { $out['is_starred'] = true; continue; }
				if ($name === '3')  { $out['class'] = 'trash'; }
				if ($name === '4')  { $out['class'] = 'spam'; }
				if ($out['folder'] === null) { $out['folder'] = $system[$name]; }
				continue;
			}
			if (in_array(strtolower($name), array('starred'), true)) {
				$out['is_starred'] = true;
				continue;
			}
			$out['labels'][] = $name;
		}

		return $out;
	}

	/**
	 * Whether a member of an archive looks like one saved message. Extensionless
	 * files count because that is exactly what a maildir looks like.
	 */
	public static function looksLikeMessageFile(string $filename): bool {
		$base = basename($filename);
		if ($base === '' || $base[0] === '.') {
			return false; // dotfiles, and Proton's .metadata.json sidecars
		}
		$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
		if (in_array($ext, array('eml', 'emlx', 'msg', 'mail'), true)) {
			return true;
		}
		if ($ext === '') {
			return true; // maildir member
		}
		// A maildir name carries its flags after the extension-looking colon part.
		return strpos($base, ':2,') !== false;
	}

	/**
	 * Build one message descriptor from whatever the format managed to learn.
	 *
	 * Provider metadata wins where it exists, because a sidecar or a Takeout header
	 * is the source's own record of what its UI showed; where it does not, the
	 * folder the message was filed under is all we have and it is enough. A format
	 * that knows nothing still produces a usable descriptor — the message imports,
	 * just without read state or labels.
	 *
	 * @param array|null $meta gmailLabels()/protonMetadata()-shaped state, or null
	 */
	public static function describe(string $locator, int $ordinal, string $defaultFolder,
			array $headers, ?array $meta = null): array {
		$state = $meta ?? self::gmailLabels($headers);

		$folder = $state['folder'] ?? null;
		if ($folder === null || $folder === '') {
			$folder = $defaultFolder;
		}

		// The folder name is the fallback classifier, but an explicit spam/trash
		// signal from the provider outranks it — a message the source itself filed
		// as spam is spam whatever the containing directory is called.
		$class = ($state['class'] ?? 'normal') !== 'normal'
			? $state['class']
			: self::classifyFolder($defaultFolder !== '' ? $defaultFolder : (string)$folder);

		return array(
			'locator'       => $locator,
			'ordinal'       => $ordinal,
			'source_folder' => (string)$folder,
			'labels'        => array_values(array_unique(array_filter((array)($state['labels'] ?? array()), 'strlen'))),
			'class'         => $class,
			'is_read'       => !empty($state['is_read']),
			'is_starred'    => !empty($state['is_starred']),
			'headers'       => $headers,
		);
	}

	/** Whether a member is an mbox (by extension, or by its first bytes). */
	public static function looksLikeMbox(string $filename, ?string $head = null): bool {
		$ext = strtolower(pathinfo(basename($filename), PATHINFO_EXTENSION));
		if (in_array($ext, array('mbox', 'mbx'), true)) {
			return true;
		}
		return ($head !== null && strncmp($head, 'From ', 5) === 0);
	}
}
?>
