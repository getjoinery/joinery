<?php
/**
 * MailAddressList — the one parser and formatter for an address-list header
 * (To, Cc) across the mailbox plugin.
 *
 * Every ingest path — SMTP push, the relay's deferred parse, IMAP pull,
 * Joinery Direct — hands the To/Cc it has to fromHeader(), and every composed
 * row hands its typed lists to format(), so iem_to / iem_cc hold ONE shape no
 * matter how the message arrived:
 *
 *     "Ford, Tom" <tford@example.com>, "Beltran, Luis" <lubeltra@example.com>
 *
 * Display names are always double-quoted (with quotes, angle brackets, CR/LF
 * and tabs stripped first — the same hostile-name treatment iem_sender gets),
 * addresses are always in angle brackets, entries are joined by ", ". A reader
 * that respects double quotes can therefore split the list on commas without
 * ever splitting a name. Bare addresses with no name are stored bare.
 *
 * parse() handles what an RFC 5322 address-list actually contains: quoted
 * strings (whose commas and angle brackets are text), comments in parentheses,
 * groups ("Team: a@x, b@x;"), encoded words in display names, and a display
 * name that itself contains angle brackets (the last angle-addr is the address,
 * exactly as InboundEmailRouter::parseEmail treats From).
 */
class MailAddressList {

	/**
	 * Parse an address-list header VALUE (already unfolded) into entries.
	 *
	 * @return array<int, array{name:string,email:string}> in header order,
	 *         empty entries dropped, addresses de-duplicated case-insensitively.
	 */
	public static function parse(string $header): array {
		$entries = array();
		$seen = array();
		foreach (self::splitTopLevel($header) as $piece) {
			$piece = trim($piece);
			if ($piece === '') {
				continue;
			}
			$entry = self::parseEntry($piece);
			if ($entry === null) {
				continue;
			}
			$key = strtolower($entry['email']);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * The canonical stored string for a list of entries — what iem_to / iem_cc
	 * hold. Accepts the shape parse() returns and the shape EmailMessage keeps
	 * (['email' => ..., 'name' => ...]); a plain string entry is a bare address.
	 */
	public static function format(array $entries): string {
		$out = array();
		foreach ($entries as $entry) {
			if (is_string($entry)) {
				$entry = array('email' => $entry, 'name' => '');
			}
			$email = trim((string)($entry['email'] ?? ''));
			if ($email === '') {
				continue;
			}
			$name = self::cleanName((string)($entry['name'] ?? ''));
			if ($name === '' || strcasecmp($name, $email) === 0) {
				$out[] = $email;
			} else {
				$out[] = '"' . $name . '" <' . $email . '>';
			}
		}
		return implode(', ', $out);
	}

	/**
	 * Header value → canonical stored string. $value may be the string a header
	 * parser produced or the array it produces when the header repeated; a
	 * repeated To: is one list. Null / empty → ''.
	 */
	public static function fromHeader($value): string {
		if (is_array($value)) {
			$value = implode(', ', array_map('strval', $value));
		}
		$value = trim((string)$value);
		if ($value === '') {
			return '';
		}
		return self::format(self::parse($value));
	}

	/**
	 * The To and Cc lists from a raw wire header block — what a row stored
	 * before iem_to / iem_cc existed can still answer from its retained
	 * iem_raw_headers. Folded lines are joined; a repeated header is one list.
	 *
	 * @return array{to:string,cc:string} canonical stored strings ('' when absent)
	 */
	public static function fromHeaderBlock(string $block): array {
		$found = array('to' => array(), 'cc' => array());
		$current = null;
		foreach (preg_split('/\r\n|\n/', $block) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
				if ($current !== null) {
					$found[$current][count($found[$current]) - 1] .= ' ' . trim($line);
				}
				continue;
			}
			$current = null;
			if (preg_match('/^(to|cc):\s*(.*)$/i', $line, $m)) {
				$current = strtolower($m[1]);
				$found[$current][] = trim($m[2]);
			}
		}
		return array(
			'to' => self::fromHeader($found['to']),
			'cc' => self::fromHeader($found['cc']),
		);
	}

	/** Bare lowercase addresses from a stored (or header) list, in order. */
	public static function addresses(string $stored): array {
		$out = array();
		foreach (self::parse($stored) as $entry) {
			$out[] = strtolower($entry['email']);
		}
		return $out;
	}

	// ------------------------------------------------------------------ internals

	/**
	 * Split on commas (and group-closing semicolons) that sit outside quoted
	 * strings, comments and angle brackets. A group's "Name:" prefix is dropped
	 * so its members parse as ordinary entries.
	 */
	private static function splitTopLevel(string $s): array {
		$pieces = array();
		$buf = '';
		$len = strlen($s);
		$in_quote = false;
		$comment_depth = 0;
		$in_angle = false;
		for ($i = 0; $i < $len; $i++) {
			$c = $s[$i];
			if ($in_quote) {
				if ($c === '\\' && $i + 1 < $len) {
					$buf .= $c . $s[++$i];
					continue;
				}
				if ($c === '"') {
					$in_quote = false;
				}
				$buf .= $c;
				continue;
			}
			if ($comment_depth > 0) {
				if ($c === '\\' && $i + 1 < $len) {
					$buf .= $c . $s[++$i];
					continue;
				}
				if ($c === '(') {
					$comment_depth++;
				} elseif ($c === ')') {
					$comment_depth--;
				}
				$buf .= $c;
				continue;
			}
			switch ($c) {
				case '"':
					$in_quote = true;
					$buf .= $c;
					break;
				case '(':
					$comment_depth = 1;
					$buf .= $c;
					break;
				case '<':
					$in_angle = true;
					$buf .= $c;
					break;
				case '>':
					$in_angle = false;
					$buf .= $c;
					break;
				case ':':
					// A group label ("Team:") outside an angle-addr — drop the
					// label, keep the members. Inside angle brackets a colon is
					// part of a route or an IPv6 literal.
					if ($in_angle) {
						$buf .= $c;
					} else {
						$buf = '';
					}
					break;
				case ',':
				case ';':
					if ($in_angle) {
						$buf .= $c;
					} else {
						$pieces[] = $buf;
						$buf = '';
					}
					break;
				default:
					$buf .= $c;
			}
		}
		$pieces[] = $buf;
		return $pieces;
	}

	/** One top-level piece → entry, or null when it carries no address. */
	private static function parseEntry(string $piece): ?array {
		$name = '';
		$email = '';
		// The address is the LAST angle-addr; whatever precedes it is the name.
		if (preg_match_all('/<([^<>]*)>/', $piece, $mm, PREG_OFFSET_CAPTURE) && count($mm[1])) {
			$last = end($mm[0]);
			$email = trim((string)end($mm[1])[0]);
			$name = substr($piece, 0, $last[1]);
		} else {
			// Bare address, possibly with a comment: "a@x (Alice)".
			$email = trim(self::stripComments($piece));
		}
		$email = trim($email, " \t\"'");
		if ($email === '' || strpos($email, '@') === false) {
			return null;
		}
		$name = self::stripComments($name);
		$name = trim($name);
		if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
			$name = stripcslashes(substr($name, 1, -1));
		}
		if (function_exists('mb_decode_mimeheader') && strpos($name, '=?') !== false) {
			$name = mb_decode_mimeheader($name);
		}
		return array('name' => self::cleanName($name), 'email' => $email);
	}

	/** Remove RFC 5322 comments — parenthesised text outside quoted strings. */
	private static function stripComments(string $s): string {
		$out = '';
		$len = strlen($s);
		$in_quote = false;
		$depth = 0;
		for ($i = 0; $i < $len; $i++) {
			$c = $s[$i];
			if ($in_quote) {
				if ($c === '\\' && $i + 1 < $len) {
					$out .= $c . $s[++$i];
					continue;
				}
				if ($c === '"') {
					$in_quote = false;
				}
				$out .= $c;
				continue;
			}
			if ($depth > 0) {
				if ($c === '\\' && $i + 1 < $len) {
					$i++;
					continue;
				}
				if ($c === '(') {
					$depth++;
				} elseif ($c === ')') {
					$depth--;
				}
				continue;
			}
			if ($c === '"') {
				$in_quote = true;
			} elseif ($c === '(') {
				$depth = 1;
				continue;
			}
			$out .= $c;
		}
		return $out;
	}

	/**
	 * A display name is text somebody else chose: strip what could forge a
	 * second address or fold into another header, collapse whitespace.
	 */
	private static function cleanName(string $name): string {
		$name = preg_replace('/[\r\n\t"<>]+/', ' ', $name);
		return trim(preg_replace('/\s+/', ' ', (string)$name));
	}
}
