<?php
/**
 * MailImportIdentity - working out whose mail this was.
 *
 * An archive is a pile of messages with no context. Live delivery always knows
 * who a message was for, because the server was handed the envelope; a file on
 * disk carries no envelope at all. So the user tells us which addresses were
 * theirs, and everything else is derived from that one declaration:
 *
 *   Was this sent or received?  Mail FROM one of your addresses is mail you sent.
 *   Which address received it?  The first recipient header naming an address you
 *                               said was yours.
 *
 * The fallback when no recipient header matches is the target mailbox's own
 * address, which is the honest answer for a Bcc: it really did reach you, and
 * the message simply does not record how.
 *
 * Every function here is pure — the same headers and the same declared addresses
 * always give the same answer, with no database and no session involved.
 *
 * See specs/mail_archive_import.md § 5.3.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));

class MailImportIdentity {

	/**
	 * Recipient headers in the order they are trusted. The envelope-recording ones
	 * come first because they say where the message was actually delivered, which
	 * To and Cc only imply.
	 */
	const RECIPIENT_HEADERS = array('delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to', 'to', 'cc');

	/** Folder names that mean the user sent it, whatever the headers say. */
	const SENT_FOLDERS = array('sent', 'sent mail', 'sent items', 'sent messages', 'outbox', '[gmail]/sent mail');

	/**
	 * inbound or outbound.
	 *
	 * The source's own filing wins: a message sitting in Sent was sent, even if
	 * its From has since been rewritten or the user forgot to declare the address
	 * it went out under. Failing that, a From that matches a declared address is
	 * the signal.
	 */
	public static function direction(array $headers, array $ownAddresses, string $folder, array $labels = array()): string {
		$names = array_map('strtolower', array_merge(array($folder), $labels));
		foreach ($names as $name) {
			$name = trim($name);
			if ($name !== '' && in_array($name, self::SENT_FOLDERS, true)) {
				return 'outbound';
			}
		}

		$from = self::addressesIn(MailArchiveReader::header($headers, 'from'));
		foreach ($from as $address) {
			if (in_array($address, $ownAddresses, true)) {
				return 'outbound';
			}
		}
		return 'inbound';
	}

	/**
	 * The address to record on the row.
	 *
	 * For received mail this is the declared address the message was actually
	 * delivered to, so the mailbox's history stays honest even when several of the
	 * user's addresses are being folded into one mailbox. For sent mail it is the
	 * first To, matching how the platform stores mail sent from the reader.
	 */
	public static function deliveryAddress(array $headers, array $ownAddresses, string $fallback, string $direction = 'inbound'): string {
		if ($direction === 'outbound') {
			$to = self::addressesIn(self::joinHeader($headers, 'to'));
			return $to ? $to[0] : $fallback;
		}

		foreach (self::RECIPIENT_HEADERS as $name) {
			foreach (self::addressesIn(self::joinHeader($headers, $name)) as $address) {
				if (in_array($address, $ownAddresses, true)) {
					return $address;
				}
			}
		}
		// Nothing named an address the user claimed. Most often a Bcc, sometimes a
		// mailing list. Either way it reached them; the mailbox's own address is the
		// truest thing we can say.
		return $fallback;
	}

	/**
	 * The message's own send time as UTC 'Y-m-d H:i:s', or null when the Date
	 * header is missing or absurd. Without this every imported message would carry
	 * the import's clock and a decade of mail would land in one afternoon.
	 */
	public static function receivedTime(array $headers): ?string {
		$date = MailArchiveReader::header($headers, 'date');
		if ($date === '') {
			return null;
		}

		// Drop the leading day name before parsing. It is decoration — RFC 5322
		// makes the numeric date authoritative — and real archives are full of
		// headers where it disagrees ("Tue, 3 Feb 2016" was a Wednesday). PHP reads
		// a mismatched day name as "move forward to the next one", which silently
		// shifts the message by up to six days.
		$date = preg_replace('/^\s*[A-Za-z]{3,9}\s*,\s*/', '', $date);

		$ts = strtotime((string)$date);
		if ($ts === false) {
			return null;
		}
		// Email predates neither 1971 nor sanity. A clock-skewed sender writing a
		// date years in the future would otherwise pin the message to the top of
		// the mailbox forever.
		$now = time();
		if ($ts < 31536000 || $ts > $now + 31536000) {
			return null;
		}
		return gmdate('Y-m-d H:i:s', $ts);
	}

	/**
	 * Every bare address in a header value, lowercased. Handles the display-name
	 * forms real mail uses — "Name <a@b>", <a@b>, a@b, and comma-separated lists
	 * of all three.
	 *
	 * @return string[]
	 */
	public static function addressesIn(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return array();
		}
		$out = array();

		if (preg_match_all('/<([^<>@\s]+@[^<>@\s]+)>/', $value, $m)) {
			foreach ($m[1] as $address) {
				$out[strtolower(trim($address))] = true;
			}
		}
		// Addresses written bare, outside any angle brackets.
		$stripped = preg_replace('/<[^>]*>/', ' ', $value);
		if (preg_match_all('/[^\s,;<>"\']+@[^\s,;<>"\']+/', (string)$stripped, $m2)) {
			foreach ($m2[0] as $address) {
				$address = strtolower(trim($address, " \t\"'.,;"));
				if ($address !== '' && strpos($address, '@') !== false) {
					$out[$address] = true;
				}
			}
		}
		return array_keys($out);
	}

	/** All values of a header that may have repeated, joined for address scanning. */
	private static function joinHeader(array $headers, string $name): string {
		$v = $headers[strtolower($name)] ?? '';
		return is_array($v) ? implode(', ', $v) : (string)$v;
	}
}
?>
