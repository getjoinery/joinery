<?php
/**
 * MessengerFederation — chat across instances, over the channel mail already
 * built.
 *
 * Nothing here is a transport. Joinery Direct owns discovery, the signed
 * preflight, sealing to the recipient's key, the transfer, the hashes, the rate
 * limits and the relay; chat is its second payload kind and adds no wire
 * surface of its own. What lives here is the two ends of that pipe on the
 * messenger's side: turning a message into parts, and asking whether a given
 * address is reachable this way at all.
 *
 * IDENTITY. Your Joinery mail address is your chat handle. That is not a
 * convenience — it is what makes consent work: a chat message is accepted only
 * if the sender is in the recipient's mail contacts, matched against the domain
 * that signed the request. A stranger cannot open a conversation with you, for
 * exactly the reason a stranger cannot send you direct mail.
 *
 * @version 1.2.3 - localDomain() answers for authoritative domains only, so an
 *   IMAP-source anchor no longer swallows chat to its provider's addresses
 * @version 1.2.2
 * @changelog 1.2.2 - a LOCKED send (vault-sealed signing key, owner absent) defers until presence like a sealed body read, instead of burning the retry budget on attempts nobody can make succeed
 * @changelog 1.2.1 - addressFor() prefers a PUBLISHED domain over a merely signable one: the Setup tab mints an identity for every enabled domain, so first-with-an-identity picked arbitrary unpublished domains and every send refused at the self-check
 * @changelog 1.2.0 - Reachability spec: siteReady() (identity minted, not just enabled), senderPublished() (our own DNS half, checked before the recipient's), fresh re-checks past the capability cache, and local-address resolution so a member's own site's addresses never go over the wire
 * @changelog 1.1.0 - People picker contacts
 */

class MessengerFederation {

	/** The kind chat rides as. */
	const KIND = 'chat';

	/** The content type the chat header part carries. */
	const HEADERS_CONTENT_TYPE = 'application/vnd.joinery.chat+json';

	/** What a chat envelope is doing. */
	const TYPE_MESSAGE  = 'message';
	const TYPE_REACTION = 'reaction';
	const TYPE_DELETE   = 'delete';

	/** How many times a queued message is retried before it is given up on. */
	const MAX_ATTEMPTS = 8;

	// ------------------------------------------------------------------
	// Is this even possible here?
	// ------------------------------------------------------------------

	/** Does this deployment speak Direct at all? */
	public static function available(): bool {
		if (!PluginHelper::isPluginActive('mailbox')) {
			// The address, the contact list and the endpoint all come from the
			// mailbox. Without it there is no handle to be reached at.
			return false;
		}
		return DirectSettings::enabled();
	}

	/**
	 * Can this SITE chat across instances at all: Direct on AND at least one
	 * signing identity minted. The three ways this is false are S1–S3 of
	 * specs/messenger_reachability_states.md; a permission-5 member is told
	 * which surface to fix rather than shown silence.
	 */
	public static function siteReady(): bool {
		if (!self::available()) {
			return false;
		}
		return count(new MultiDirectIdentity(array('is_active' => true))) > 0;
	}

	/**
	 * Is $domain one of this site's own (enabled) inbound mail domains?
	 * An address here never goes over the wire — it resolves internally.
	 *
	 * Authoritative domains only: an IMAP-source anchor (gmail.com behind a
	 * connected account) is not local — treating it so would short-circuit
	 * every @gmail.com correspondent as "cannot be reached by chat" and
	 * local-trap chat to any real Joinery domain someone mirrors over IMAP
	 * (specs/imap_source_domain_boundaries.md § 4).
	 */
	public static function localDomain(string $domain): bool {
		$domain = strtolower(trim($domain));
		if ($domain === '' || !PluginHelper::isPluginActive('mailbox')) {
			return false;
		}
		$row = InboundEmailDomain::GetByDomain($domain);
		return $row !== false && $row->is_authoritative();
	}

	/**
	 * The member behind a local address, or null.
	 *
	 * Resolves only when exactly ONE live member holds the mailbox: a shared
	 * mailbox (info@) with several grantees is nobody in particular, and opening
	 * a 1:1 with an arbitrary holder would be wrong — it stays email-only.
	 *
	 * The reveal is bounded by design: callers only reach this with an exact
	 * full address the member typed, never a partial (see the spec's R1 note).
	 *
	 * @return array{user_id:int,name:string,avatar:string}|null
	 */
	public static function resolveLocalMember(string $address): ?array {
		$address = strtolower(trim($address));
		$at = strrpos($address, '@');
		if ($at === false || !self::localDomain(substr($address, $at + 1))) {
			return null;
		}
		$local_part = substr($address, 0, $at);
		$domain     = substr($address, $at + 1);

		$holder = null;
		$aliases = new MultiInboundEmailAlias(array('alias' => $local_part, 'enabled' => true));
		foreach ($aliases as $alias) {
			if (strtolower((string)$alias->get_full_address()) !== $address) {
				continue;
			}
			$grants = new MultiInboundEmailMailboxGrant(array('alias_id' => (int)$alias->key));
			foreach ($grants as $grant) {
				$uid = (int)$grant->get('ieg_usr_user_id');
				if ($uid <= 0 || $uid === User::USER_SYSTEM || $uid === User::USER_DELETED) {
					continue;
				}
				try {
					$user = new User($uid, TRUE);
				} catch (Exception $e) {
					continue;
				}
				if (!$user->key || $user->get('usr_delete_time')
					|| $user->get('usr_is_disabled') || $user->get('usr_is_admin_disabled')) {
					continue;
				}
				if ($holder !== null && $holder['user_id'] !== $uid) {
					return null; // shared mailbox — nobody in particular
				}
				$holder = array(
					'user_id' => $uid,
					'name'    => $user->display_name(),
					'avatar'  => $user->get_picture_link('avatar'),
				);
			}
		}
		return $holder;
	}

	/**
	 * Is the sending address's own domain actually published in DNS — the
	 * SRV endpoint and the key the recipient will verify our signature
	 * against? An identity in the database is not enough: unpublished, every
	 * send can only be refused on the far end. Same lookup and cache as a
	 * recipient check, aimed at ourselves.
	 */
	public static function senderPublished(string $address, bool $fresh = false): bool {
		$domain = DirectProtocol::domainOf($address);
		if ($domain === '') {
			return false;
		}
		$key_id = DirectSigningIdentity::keyIdFor($domain);
		if ($key_id === '') {
			return false;
		}
		$capability = DirectCapability::lookup($domain, false, $fresh);
		return $capability !== null && isset($capability['keys'][$key_id]);
	}

	/**
	 * The address a member sends chat from: the first mailbox they hold on a
	 * domain this deployment can sign as AND currently publishes in DNS —
	 * because the recipient verifies the sender's signature against what that
	 * domain publishes, an unpublished domain can only be refused.
	 *
	 * Every enabled domain tends to hold a signing identity (the Setup tab
	 * mints them while planning records), so signability alone does not pick a
	 * usable sender. A signable-but-unpublished address is only returned when
	 * NO published one exists, so the send still fails with the honest
	 * "records not published" reason naming a real domain.
	 *
	 * Null means they have no Joinery address, which is the honest reason a
	 * member cannot chat across instances — not a failure, a missing mailbox.
	 */
	public static function addressFor(int $user_id): ?string {
		if (!self::available()) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));

		$signable = null;
		$viewer = MailboxViewer::forUser($user_id, 0);
		foreach ($viewer->accessibleAliasIds() as $alias_id) {
			try {
				$alias = new InboundEmailAlias($alias_id, TRUE);
			} catch (Exception $e) {
				continue;
			}
			if (!$alias->key) {
				continue;
			}
			$address = strtolower((string)$alias->get_full_address());
			$domain = DirectProtocol::domainOf($address);
			if ($domain === '' || !DirectSigningIdentity::hasIdentity($domain)) {
				continue;
			}
			if (self::senderPublished($address)) {
				return $address;
			}
			if ($signable === null) {
				$signable = $address;
			}
		}
		return $signable;
	}

	/**
	 * Can this address be reached by chat?
	 *
	 * Answered before the member types, because the compose surface has to be
	 * honest about the path a message will take. Every "no" reads the same on
	 * the wire — a refusal, a missing capability record and an instance too old
	 * to understand the chat kind are indistinguishable by design — so this
	 * reports reachability and never a reason that would leak the recipient's
	 * choices.
	 *
	 * @param bool $fresh resolve past the capability cache — the member asked
	 *        to check again, and a cached "no" from a blip must not answer for
	 *        the retry. Callers rate-limit.
	 * @return array{reachable:bool, reason:string}
	 */
	public static function reachability(string $address, bool $fresh = false): array {
		$address = strtolower(trim($address));
		if (!self::available()) {
			return array('reachable' => false, 'reason' => 'This site does not have cross-site chat set up.');
		}
		$domain = DirectProtocol::domainOf($address);
		if ($domain === '') {
			return array('reachable' => false, 'reason' => 'That does not look like an address.');
		}

		$capability = DirectCapability::lookup($domain, false, $fresh);
		if ($capability === null) {
			return array('reachable' => false,
				'reason' => 'That address cannot be reached by chat. You can send an email instead.');
		}
		return array('reachable' => true, 'reason' => '');
	}

	// ------------------------------------------------------------------
	// Building what goes on the wire
	// ------------------------------------------------------------------

	/**
	 * The parts of one chat message.
	 *
	 * Everything chat-specific rides in the header part — the framework's
	 * envelope stays kind-independent, which is what lets mail, chat and
	 * whatever comes next share one wire.
	 *
	 * @param string|null $body plaintext; null for a control payload
	 * @param File[]      $attachments
	 */
	public static function buildParts(array $header, ?string $body = null, array $attachments = array()): array {
		$parts = array(array(
			'role'         => DirectProtocol::ROLE_HEADERS,
			'content_type' => self::HEADERS_CONTENT_TYPE,
			'filename'     => 'chat',
			'is_inline'    => true,
			'bytes'        => json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		));

		if ($body !== null && $body !== '') {
			$parts[] = array(
				'role'         => DirectProtocol::ROLE_BODY_TEXT,
				'content_type' => 'text/plain; charset=utf-8',
				'bytes'        => $body,
			);
		}

		foreach ($attachments as $file) {
			$bytes = $file->read_bytes();
			if ($bytes === false || $bytes === null) {
				continue;
			}
			$parts[] = array(
				'role'         => DirectProtocol::ROLE_ATTACHMENT,
				'content_type' => (string)($file->get('fil_type') ?: 'application/octet-stream'),
				'filename'     => (string)($file->get('fil_title') ?: $file->get('fil_name')),
				'bytes'        => $bytes,
			);
		}

		return $parts;
	}

	/** The header block for a message. */
	public static function messageHeader(Conversation $conversation, Message $message): array {
		$reply_to_guid = '';
		$parent_id = $message->get('msg_reply_to_message_id');
		if ($parent_id) {
			$parent = new Message((int)$parent_id, TRUE);
			$reply_to_guid = (string)$parent->get('msg_guid');
		}

		return array(
			'type'          => self::TYPE_MESSAGE,
			'cnv_guid'      => (string)$conversation->get('cnv_guid'),
			'msg_guid'      => (string)$message->get('msg_guid'),
			'sent_time'     => (string)$message->get('msg_sent_time'),
			'reply_to_guid' => $reply_to_guid,
		);
	}

	// ------------------------------------------------------------------
	// Sending
	// ------------------------------------------------------------------

	/**
	 * Put one message on the wire to the conversation's remote peers.
	 *
	 * Chat maps NO result to email. A message typed into a chat bubble stays a
	 * chat bubble: a failure is retried, and a refusal is shown to the sender as
	 * "not reachable this way" with an explicit offer to send an email — never
	 * a silent transmutation of one into the other.
	 *
	 * @return string the resulting msg_delivery_state
	 */
	public static function sendMessage(Conversation $conversation, Message $message): string {
		$peers = ConversationRemotePeer::forConversation($conversation->key);
		if (!$peers) {
			return Message::DELIVERY_LOCAL;
		}

		$sender_user_id = (int)$message->get('msg_usr_user_id_sender');
		$sender = $sender_user_id > 0 ? self::addressFor($sender_user_id) : null;
		if ($sender === null) {
			return self::recordFailure($message, 'no sending address');
		}

		$header = self::messageHeader($conversation, $message);

		$body = '';
		$attachments = array();
		try {
			$body = (string)$message->get('msg_body');
			$attachments = self::attachmentFilesFor($message, $conversation);
		} catch (VaultLockedException $e) {
			// A protected conversation, and nobody present to open it. Not a
			// delivery failure and not this message's fault, so it waits
			// without spending an attempt — the retry that runs while the
			// sender is on the site will find the key. Attachments defer the
			// same way: an attachment-only message must never go out as a
			// header-only envelope because its files could not be opened.
			return self::deferUntilPresent($message);
		}

		return self::deliver($conversation, $message, $sender, $peers, $header, $body, $attachments);
	}

	/**
	 * A reaction or a delete, which carry no body of their own.
	 *
	 * These are best-effort: a reaction that does not cross is a missing chip on
	 * the far side, not a lost message, so they are not queued for retry.
	 */
	public static function sendControl(Conversation $conversation, array $header, int $sender_user_id): void {
		$peers = ConversationRemotePeer::forConversation($conversation->key);
		if (!$peers || !self::available()) {
			return;
		}
		$sender = self::addressFor($sender_user_id);
		if ($sender === null) {
			return;
		}
		$parts = self::buildParts($header);
		foreach (array_keys($peers) as $address) {
			JoineryDirect::send($address, self::KIND, $parts, array('sender' => $sender));
		}
	}

	/**
	 * The transfer itself, plus the state the sender's ticks read.
	 *
	 * A Guarded conversation refuses to send unless the recipient's instance
	 * returned a key to seal to. Direct permits an opportunistic plaintext-over-
	 * TLS delivery when the far side has no vault; at Guarded that trade is not
	 * on offer, so the send carries `require_sealed` and Direct refuses between
	 * preflight and transfer — no content byte crosses the wire. The refusal is
	 * final: a keyless instance is a posture, not a blip, and the member can
	 * resend once the far side has a vault.
	 */
	protected static function deliver(Conversation $conversation, Message $message, string $sender,
			array $peers, array $header, string $body, array $attachments): string {
		$parts = self::buildParts($header, $body, $attachments);
		$guarded = $conversation->is_guarded();

		$all_delivered = true;
		$any_declined = false;
		$any_unsealable = false;

		foreach (array_keys($peers) as $address) {
			$result = JoineryDirect::send($address, self::KIND, $parts,
				array('sender' => $sender, 'require_sealed' => $guarded));

			if ($result->status === DirectSendResult::NO_SEALING) {
				error_log('[MessengerFederation] Guarded conversation ' . $conversation->key
					. ' refused to send to ' . $address . ' — the recipient published no key.');
				$all_delivered = false;
				$any_unsealable = true;
				continue;
			}
			if ($result->delivered() && $guarded && !$result->sealed) {
				// Unreachable while require_sealed holds; kept as a tripwire in
				// case a transport ever stops honoring it. Recorded loudly: the
				// operator surface should show a Guarded conversation failing
				// this way.
				error_log('[MessengerFederation] Guarded conversation ' . $conversation->key
					. ' delivered unsealed to ' . $address . ' — the recipient published no key.');
				$all_delivered = false;
				$any_unsealable = true;
				continue;
			}
			if ($result->delivered()) {
				continue;
			}
			if ($result->status === DirectSendResult::LOCKED) {
				// The signing key is sealed and nobody who can open it is here.
				// Not the recipient's fault and not a spent attempt: the send
				// becomes possible the moment the member is back, exactly like a
				// sealed body — so it waits the same way.
				return self::deferUntilPresent($message);
			}
			$all_delivered = false;
			if ($result->status === DirectSendResult::DECLINED
				|| $result->status === DirectSendResult::NO_CAPABILITY) {
				$any_declined = true;
			}
		}

		if ($all_delivered) {
			$message->set('msg_delivery_state', Message::DELIVERY_DELIVERED);
			$message->set('msg_delivery_next_try', null);
			$message->save();
			return Message::DELIVERY_DELIVERED;
		}

		// A refusal is final — retrying it would only ask the same question
		// again — while a connection problem is worth another attempt.
		if ($any_unsealable) {
			return self::recordFailure($message, 'recipient cannot receive protected messages');
		}
		return $any_declined
			? self::recordFailure($message, 'not reachable by chat')
			: self::recordRetry($message);
	}

	/** Mark the message failed — the final state the sender's ticks show. */
	protected static function recordFailure(Message $message, string $why): string {
		$message->set('msg_delivery_state', Message::DELIVERY_FAILED);
		$message->set('msg_delivery_next_try', null);
		$message->save();
		error_log('[MessengerFederation] message ' . $message->key . ' not delivered: ' . $why);
		return Message::DELIVERY_FAILED;
	}

	/**
	 * Wait for someone who can open the conversation, without counting it as a
	 * failed attempt.
	 *
	 * A sealed conversation's outbound message can only be read while one of its
	 * members is here. Spending the retry budget on the hours nobody is signed
	 * in would give up on a message that was never actually refused.
	 */
	protected static function deferUntilPresent(Message $message): string {
		$message->set('msg_delivery_state', Message::DELIVERY_QUEUED);
		$message->set('msg_delivery_next_try', LibraryFunctions::time_shift(
			gmdate('Y-m-d H:i:s'), '+5 minutes', 'Y-m-d H:i:s'));
		$message->save();
		return Message::DELIVERY_QUEUED;
	}

	/** Back off and try again later — the queue the scheduled task drains. */
	protected static function recordRetry(Message $message): string {
		$attempts = (int)$message->get('msg_delivery_attempts') + 1;
		$message->set('msg_delivery_attempts', $attempts);

		if ($attempts >= self::MAX_ATTEMPTS) {
			return self::recordFailure($message, 'gave up after ' . $attempts . ' attempts');
		}

		// Doubling from a minute: a far instance restarting is back in seconds,
		// one that is properly down should not be knocked on every minute for a
		// day. Capped so the last attempts are still within a working day.
		$delay = min(3600, 60 * (2 ** ($attempts - 1)));
		$message->set('msg_delivery_state', Message::DELIVERY_QUEUED);
		$message->set('msg_delivery_next_try', LibraryFunctions::time_shift(
			gmdate('Y-m-d H:i:s'), '+' . $delay . ' seconds', 'Y-m-d H:i:s'));
		$message->save();
		return Message::DELIVERY_QUEUED;
	}

	/**
	 * The stored Files behind a message's attachments.
	 *
	 * @throws VaultLockedException when a sealed attachment's conversation key
	 *         is not openable right now — the whole delivery defers rather than
	 *         going out without the file.
	 */
	protected static function attachmentFilesFor(Message $message, Conversation $conversation): array {
		$out = array();
		$rows = new MultiMessageAttachment(array('message_id' => (int)$message->key, 'deleted' => false));
		foreach ($rows as $row) {
			try {
				$file = new File((int)$row->get('msa_fil_file_id'), TRUE);
			} catch (Exception $e) {
				continue;
			}
			if (!$file->key) {
				continue;
			}
			// A sealed attachment's stored bytes are a container under this
			// conversation's key, which means nothing on the far side. Direct
			// seals for the wire itself, to the recipient's own key, so what
			// crosses is the plaintext opened here.
			if ($file->is_sealed()) {
				$key = ConversationSealing::attachmentKey($file);
				if ($key === null) {
					throw new VaultLockedException(
						'conversation key unavailable for attachment ' . $file->key);
				}
				$plain = SealedFileContainer::openBytes((string)$file->read_bytes(), $key);
				$out[] = new MessengerPlainAttachment($file, $plain);
				continue;
			}
			$out[] = $file;
		}
		return $out;
	}
}

/**
 * A sealed attachment, opened, wearing enough of a File's shape for
 * buildParts() to read it.
 *
 * The alternative would be handing buildParts() a second code path for sealed
 * files, which is how the two drift.
 */
class MessengerPlainAttachment {
	private $file;
	private $bytes;

	public function __construct(File $file, string $bytes) {
		$this->file = $file;
		$this->bytes = $bytes;
	}

	public function read_bytes() { return $this->bytes; }
	public function get($field) { return $this->file->get($field); }
}
