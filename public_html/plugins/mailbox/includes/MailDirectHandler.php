<?php
/**
 * MailDirectHandler - mail on Joinery Direct.
 *
 * Mail is the founding payload of the channel, not its owner: it is one
 * registered kind among others, and everything about the wire — signatures,
 * freshness, replay, size bounds, the delivery session, hash verification,
 * spooling while locked — belongs to the framework. What is left here is a store.
 *
 * There is no `gate()` because mail declares `"gate": "contacts"`, so the
 * framework runs the canned contact gate itself. That is the point of exporting
 * it: mail's authorization is the best-reviewed gate on the platform, and a kind
 * that wants the same rule reaches it in one line rather than reimplementing it
 * slightly differently.
 *
 * **How mail's own metadata travels.** The shared layer's parts carry a role, a
 * content type and bytes — nothing kind-specific — so a message's subject,
 * From display name, Message-ID and threading headers ride as a part of their
 * own, typed `message/rfc822-headers` (RFC 6522, which is exactly what that type
 * is for). Anything a kind needs to say travels in its parts, never in new
 * envelope fields; that is what keeps the envelope kind-independent.
 *
 * @version 1.1
 * @changelog 1.1 - a protected mailbox with no key to seal to defers the
 *   delivery (held, parts intact) instead of storing it in plaintext
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectHandler.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));

class MailDirectHandler implements DirectKindHandler {

	/** The content type mail's header part carries. */
	const HEADERS_CONTENT_TYPE = 'message/rfc822-headers';

	/**
	 * Never called: mail's kind declaration names the canned contact gate, so
	 * the framework answers this question itself and this method is unreachable
	 * on the live path. It returns false rather than true so that a
	 * misconfiguration — a declaration that lost its `gate` — fails closed.
	 */
	public function gate(DirectEnvelope $envelope): bool {
		return false;
	}

	/**
	 * File the message.
	 *
	 * $gate_accepted is the contact gate's answer, and it decides ELEVATION
	 * only. A contact's message gets the verified-direct mark; a non-contact's
	 * is handed to the same classification SMTP mail gets and filed wherever
	 * that puts it. Nothing is returned to the sender either way — the sender
	 * was already answered `accept`, and a bounce would be a recon tool.
	 */
	public function ingest(DirectEnvelope $envelope, array $parts, bool $gate_accepted): void {
		$recipient = $envelope->recipient();
		$domain_name = $envelope->recipientDomain();

		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->key) {
			throw new RuntimeException('MailDirectHandler: no hosted domain for ' . $domain_name);
		}
		$alias = null;
		$alias_id = $envelope->recipientAliasId();
		if ($alias_id > 0) {
			try {
				$alias = new InboundEmailAlias($alias_id, TRUE);
			} catch (\Throwable $e) {
				$alias = null;
			}
		}

		$secret = $envelope->vaultSecretKey();
		$assembled = self::assemble($envelope, $parts, $secret);

		$router = new InboundEmailRouter();
		try {
			$router->storeDirectMessage($assembled['meta'], $assembled['parts'], $alias, $domain, $recipient, $gate_accepted);
		} catch (MailboxSealTargetMissing $e) {
			// A protected mailbox with nobody to seal to. Storing it in plaintext
			// would break the mailbox's one promise silently, so the delivery is
			// HELD with its parts instead — the framework's existing "not now"
			// disposition — and lands once the mailbox has a member with a vault.
			// The sender was already answered, so nothing leaks either way.
			throw new DirectDeferIngest($e->getMessage(), 0, $e);
		}
	}

	/**
	 * Turn delivered parts into the message's metadata and content.
	 *
	 * A sealed part is opened here and nowhere earlier: the whole point of
	 * sender-side sealing is that no machine between the two endpoints — proxy,
	 * CDN, or relay — ever held plaintext, so the first unseal happens inside
	 * the recipient's own unlock window.
	 */
	private static function assemble(DirectEnvelope $envelope, array $parts, ?string $vault_secret_key): array {
		$meta = array(
			'sender'    => $envelope->sender(),
			'recipient' => $envelope->recipient(),
			'subject'   => '',
			'message_id'  => '',
			'references'  => '',
			'in_reply_to' => '',
			'received_time' => $envelope->timestamp(),
		);
		$body_plain = '';
		$body_html = '';
		$attachments = array();

		foreach ($parts as $part) {
			/** @var DirectPart $part */
			$content = $part->open($vault_secret_key);

			if ($part->role() === DirectProtocol::ROLE_HEADERS) {
				$meta = array_merge($meta, self::parseHeaderPart($content, $envelope));
				continue;
			}
			if ($part->role() === DirectProtocol::ROLE_BODY_TEXT) {
				$body_plain = $content;
				continue;
			}
			if ($part->role() === DirectProtocol::ROLE_BODY_HTML) {
				$body_html = $content;
				continue;
			}
			$attachments[] = array(
				'filename'     => (string)$part->filename(),
				'content_type' => $part->contentType(),
				'content_id'   => (string)$part->contentId(),
				'is_inline'    => $part->isInline(),
				'bytes'        => $content,
			);
		}

		return array(
			'meta'  => $meta,
			'parts' => array(
				'body_plain'  => $body_plain,
				'body_html'   => $body_html,
				'attachments' => $attachments,
			),
		);
	}

	/**
	 * Read the header part.
	 *
	 * The sender's From and To are treated as DISPLAY material only — the
	 * addresses that decide anything are the envelope's, which the instance
	 * signature covers. A header block that claimed a different sender would
	 * change what the message looks like, never who it is from or whether it
	 * passed the gate.
	 */
	private static function parseHeaderPart(string $block, DirectEnvelope $envelope): array {
		$out = array();
		foreach (preg_split('/\r\n|\n/', $block) as $line) {
			$pair = explode(':', $line, 2);
			if (count($pair) !== 2) {
				continue;
			}
			$name = strtolower(trim($pair[0]));
			$value = trim($pair[1]);
			switch ($name) {
				case 'subject':     $out['subject'] = substr($value, 0, 4000); break;
				case 'message-id':  $out['message_id'] = substr($value, 0, 255); break;
				case 'references':  $out['references'] = $value; break;
				case 'in-reply-to': $out['in_reply_to'] = $value; break;
				// The sender's Date is DELIBERATELY not trusted for received_time. It
				// is free text inside the sealed body; honouring it lets a sender pin
				// a message to the top of the inbox with a future date or bury it with
				// an ancient one. received_time stays the envelope timestamp, which the
				// instance signature covers and the freshness window bounds to within
				// minutes of actual receipt — the Direct equivalent of an SMTP receipt
				// stamp, which is likewise the receiver's clock, never the sender's.
				case 'from':
					// Kept only for the display name; the address is the envelope's.
					$display = trim(preg_replace('/<[^>]*>/', '', $value));
					$display = trim($display, " \t\"");
					$out['sender'] = $display !== ''
						? $display . ' <' . $envelope->sender() . '>'
						: $envelope->sender();
					break;
			}
		}
		return $out;
	}

	/**
	 * Build the parts for an outbound Direct mail: the header block, the bodies,
	 * and one part per attachment.
	 *
	 * This is the send-side counterpart of assemble(), kept beside it so the two
	 * cannot drift — a header the sender writes and the receiver never reads is
	 * the kind of thing that only shows up as a missing subject months later.
	 */
	public static function buildParts(EmailMessage $message): array {
		$headers = 'Subject: ' . (string)$message->getSubject() . "\r\n"
			. 'From: ' . self::fromHeader($message) . "\r\n"
			. 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n";

		// A Reply-To the sender set must survive the crossing, or a reply goes to
		// the From address instead — the SMTP path carries it, so Direct must too.
		$reply_to = trim((string)$message->getReplyTo());
		if ($reply_to !== '') {
			$headers .= 'Reply-To: ' . $reply_to . "\r\n";
		}

		// Every message needs a Message-ID: it is what threads a conversation and
		// what dedups a stored copy against the sent one. SMTP mints one when the
		// caller sets none, so Direct mints the same shape rather than delivering a
		// message with no identity at all.
		$message_id = trim((string)$message->getMessageId());
		if ($message_id === '') {
			$domain = DirectProtocol::domainOf((string)$message->getFrom());
			$message_id = '<' . bin2hex(random_bytes(16)) . '@' . ($domain !== '' ? $domain : 'localhost') . '>';
		}
		$headers .= 'Message-ID: ' . $message_id . "\r\n";

		foreach ((array)$message->getHeaders() as $name => $value) {
			if (in_array(strtolower((string)$name), array('references', 'in-reply-to'), true)) {
				$headers .= $name . ': ' . $value . "\r\n";
			}
		}

		$parts = array(array(
			// The header block carries the dedicated headers ROLE, so the receiver
			// finds it by role, not by a content type a real attachment could share.
			'role'         => DirectProtocol::ROLE_HEADERS,
			'content_type' => self::HEADERS_CONTENT_TYPE,
			'filename'     => 'headers',
			'is_inline'    => true,
			'bytes'        => $headers,
		));

		$text = (string)$message->getTextBody();
		if ($text !== '') {
			$parts[] = array(
				'role'         => DirectProtocol::ROLE_BODY_TEXT,
				'content_type' => 'text/plain; charset=utf-8',
				'bytes'        => $text,
			);
		}
		$html = (string)$message->getHtmlBody();
		if ($html !== '') {
			$parts[] = array(
				'role'         => DirectProtocol::ROLE_BODY_HTML,
				'content_type' => 'text/html; charset=utf-8',
				'bytes'        => $html,
			);
		}

		foreach ((array)$message->getAttachments() as $attachment) {
			// Attachments transfer as BYTES. MIME would have to armour binary
			// into text and inflate it by a third; parts do not.
			$parts[] = array(
				'role'         => DirectProtocol::ROLE_ATTACHMENT,
				// A file attachment carries no declared type (EmailMessage::attach
				// takes only a path and a name), so fall back the way every
				// transport does rather than inventing one.
				'content_type' => (string)($attachment['type'] ?? 'application/octet-stream'),
				'filename'     => (string)($attachment['name'] ?? 'attachment'),
				'content_id'   => (string)($attachment['cid'] ?? ''),
				'is_inline'    => !empty($attachment['inline']),
				'path'         => isset($attachment['path']) ? (string)$attachment['path'] : null,
				'bytes'        => isset($attachment['data']) ? (string)$attachment['data'] : null,
			);
		}

		// A descriptor carries content EITHER as bytes or as a path; drop the
		// unused half so the client's size accounting reads the right one.
		foreach ($parts as $index => $part) {
			if (($part['path'] ?? null) !== null) {
				unset($parts[$index]['bytes']);
			} else {
				unset($parts[$index]['path']);
			}
		}

		return array_values($parts);
	}

	private static function fromHeader(EmailMessage $message): string {
		$name = trim((string)$message->getFromName());
		$address = (string)$message->getFrom();
		return $name !== '' ? $name . ' <' . $address . '>' : $address;
	}
}
