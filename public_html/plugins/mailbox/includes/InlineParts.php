<?php
/**
 * Which parts of a message are pictures in its body, and which are attachments.
 *
 * A part is inline when the message's HTML body shows it: an <img src="cid:X">
 * (or any cid: URL) whose X is the part's Content-ID. Everything else is an
 * attachment, whatever its Content-Disposition says. Inside multipart/related
 * the disposition is advisory at best (RFC 2387 § 4 tells a reader to ignore
 * it), real senders get it wrong both ways (Gmail stamps a Content-ID on every
 * attachment; Python and some mailers mark referenced pictures "attachment"),
 * and the body's own reference is the one fact that decides what the person
 * sees. The browser parser (assets/mailbox_mime.js, MailboxMime.inlineIds)
 * applies the same rule to the same references.
 *
 * The reference is read the way the reader resolves it
 * (MailboxService::resolveInlineImages): the cid: URL's value, URL-decoded,
 * with any angle brackets trimmed.
 *
 * @version 1.0
 */
final class InlineParts {

	/** A cid: URL in an HTML body; group 1 is its value. */
	const CID_URL = '/cid:([^"\'\s>]+)/i';

	/** The Content-ID a cid: URL value names. */
	public static function idFromReference(string $value): string {
		return trim(rawurldecode($value), " \t<>");
	}

	/** The Content-IDs an HTML body shows, as a set (id => true). */
	public static function referencedIds(?string $html): array {
		$ids = array();
		if ($html === null || $html === '' || stripos($html, 'cid:') === false) {
			return $ids;
		}
		if (preg_match_all(self::CID_URL, $html, $m)) {
			foreach ($m[1] as $value) {
				$id = self::idFromReference($value);
				if ($id !== '') {
					$ids[$id] = true;
				}
			}
		}
		return $ids;
	}

	/** Whether a part with this Content-ID is shown by a body referencing $ids. */
	public static function isInline(?string $content_id, array $ids): bool {
		$id = trim((string)$content_id, " \t<>");
		return $id !== '' && isset($ids[$id]);
	}
}
