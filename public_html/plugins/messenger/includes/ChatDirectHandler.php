<?php
/**
 * ChatDirectHandler — chat arriving from another instance.
 *
 * The second payload on Joinery Direct, and deliberately the smallest thing a
 * kind can be: no gate of its own (chat declares the canned contact gate, the
 * same rule mail uses and the best-reviewed authorization on the platform), and
 * an ingest that does nothing but store.
 *
 * Everything that makes the channel safe — signature verification, freshness,
 * replay, size bounds, rate limits, the delivery session, hash verification,
 * spooling while a vault is locked — is the framework's and is identical for
 * every kind. Chat adds no endpoint, no DNS record, no key custody and no
 * oracle surface.
 *
 * WHAT ARRIVES. A chat envelope's parts are a header block (the chat metadata:
 * what kind of event, which conversation, which message, when), an optional
 * text body, and any attachments. Anything chat needs to say travels in its
 * parts, never in new envelope fields — that is what keeps the envelope
 * kind-independent.
 *
 * @version 1.1
 * @changelog 1.1 - a message for a locally raised sealed conversation defers
 *   (DirectDeferIngest) while nobody can open its key, instead of being lost;
 *   attachment files never outlive a failed store as orphaned plaintext.
 */

class ChatDirectHandler implements DirectKindHandler {

	/**
	 * Never called: chat's kind declaration names the canned contact gate, so
	 * the framework answers this itself. False rather than true so that a
	 * declaration which somehow lost its `gate` fails closed.
	 */
	public function gate(DirectEnvelope $envelope): bool {
		return false;
	}

	/**
	 * Store what arrived.
	 *
	 * A declined delivery is discarded here rather than refused on the wire.
	 * The sender was already answered `accept` — that is the framework's
	 * discipline, and it is what stops the endpoint becoming a way to probe
	 * whose contacts you are in. So a non-contact's chat message is dropped as
	 * a local disposition, structurally the same as mail filed to spam, minus a
	 * spam folder to file it in. Recorded honestly rather than dressed up.
	 */
	public function ingest(DirectEnvelope $envelope, array $parts, bool $gate_accepted): void {
		$recipient_user_id = $envelope->recipientUserId();
		if ($recipient_user_id <= 0) {
			// A shared mailbox is not a person and has no conversation list.
			error_log('[ChatDirectHandler] chat for ' . $envelope->recipient()
				. ' has no single owner — discarded.');
			return;
		}

		if (!$gate_accepted) {
			RequestLogger::log(DirectProtocol::LOG_FEATURE, 'chat:discarded-non-contact', true);
			return;
		}

		$header = $this->header($envelope, $parts);
		if ($header === null) {
			error_log('[ChatDirectHandler] chat from ' . $envelope->sender() . ' carried no readable header.');
			return;
		}

		switch ((string)($header['type'] ?? MessengerFederation::TYPE_MESSAGE)) {
			case MessengerFederation::TYPE_REACTION:
				$this->ingestReaction($envelope, $header, $recipient_user_id);
				return;
			case MessengerFederation::TYPE_DELETE:
				$this->ingestDelete($envelope, $header, $recipient_user_id);
				return;
			default:
				$this->ingestMessage($envelope, $parts, $header, $recipient_user_id);
		}
	}

	// ------------------------------------------------------------------

	/** Store an arriving message, once. */
	protected function ingestMessage(DirectEnvelope $envelope, array $parts, array $header, int $recipient_user_id): void {
		$msg_guid = (string)($header['msg_guid'] ?? '');
		if ($msg_guid !== '' && $this->alreadyStored($msg_guid)) {
			// A replayed transfer is not a second message. The framework's own
			// replay cache covers the wire; this covers the case where the same
			// message legitimately arrives twice (a sender retry that crossed a
			// slow accept).
			return;
		}

		$conversation = $this->conversationFor($envelope, $header, $recipient_user_id);
		if ($conversation === null) {
			return;
		}

		// A conversation the local side raised to Private or Guarded stores
		// nothing in the clear, and its key opens only while a participant has
		// an open unlock window. Resolved BEFORE any attachment bytes touch the
		// disk: with nobody present the whole delivery defers — held by the
		// framework and re-ingested at the recipient's next unlock — rather
		// than dropping the message or leaving plaintext files behind.
		$dek = null;
		if ($conversation->is_sealed()) {
			$dek = $conversation->conversation_key();
			if ($dek === null) {
				throw new DirectDeferIngest('conversation ' . $conversation->key
					. ' is sealed and nobody present can open it');
			}
		}

		$body = '';
		$attachments = array();
		foreach ($parts as $part) {
			if ($part->role() === DirectProtocol::ROLE_BODY_TEXT) {
				$body = $part->open($envelope->vaultSecretKey());
			} elseif ($part->role() === DirectProtocol::ROLE_ATTACHMENT) {
				$attachments[] = $part;
			}
		}

		$files = $this->storeAttachments($attachments, $envelope, $recipient_user_id, $conversation);

		if (trim($body) === '' && !$files) {
			return;
		}

		try {
			$conversation->add_message(null, $body, array(
				'remote_sender_address' => $envelope->sender(),
				'guid'                  => $msg_guid !== '' ? $msg_guid : null,
				'attachments'           => $files,
				'dek'                   => $dek,
				'reply_to_message_id'   => $this->localIdForGuid((string)($header['reply_to_guid'] ?? ''), $conversation),
			));
		} catch (ConversationException $e) {
			// A genuine refusal (an over-length body, say), not a lock — the key
			// was already in hand. The message cannot be stored, and the files
			// staged for it must not outlive it as orphaned plaintext.
			foreach ($files as $file) {
				try {
					$file->permanent_delete();
				} catch (Exception $cleanup) {
					error_log('[ChatDirectHandler] could not remove staged attachment '
						. $file->key . ': ' . $cleanup->getMessage());
				}
			}
			error_log('[ChatDirectHandler] could not store chat from ' . $envelope->sender()
				. ': ' . $e->getMessage());
		}
	}

	/** A reaction crossing the wire, applied to the local copy of the message. */
	protected function ingestReaction(DirectEnvelope $envelope, array $header, int $recipient_user_id): void {
		$conversation = $this->conversationFor($envelope, $header, $recipient_user_id, false);
		if ($conversation === null) {
			return;
		}
		$message_id = $this->localIdForGuid((string)($header['msg_guid'] ?? ''), $conversation);
		if ($message_id === null) {
			return;
		}
		try {
			MessageReaction::toggle($message_id, null, (string)($header['emoji'] ?? ''), $envelope->sender());
		} catch (MessageReactionException $e) {
			// Not an emoji — nothing to show, nothing to store.
		}
	}

	/** The far side deleted their own message; the local copy becomes a tombstone. */
	protected function ingestDelete(DirectEnvelope $envelope, array $header, int $recipient_user_id): void {
		$conversation = $this->conversationFor($envelope, $header, $recipient_user_id, false);
		if ($conversation === null) {
			return;
		}
		$message_id = $this->localIdForGuid((string)($header['msg_guid'] ?? ''), $conversation);
		if ($message_id === null) {
			return;
		}
		$message = new Message($message_id, TRUE);
		// Only the sender may delete their own message — here, only a message
		// attributed to this peer's address.
		if (strtolower((string)$message->get('msg_remote_sender_address')) !== $envelope->sender()) {
			return;
		}
		if (!$message->get('msg_delete_time')) {
			$message->set('msg_delete_time', gmdate('Y-m-d H:i:s'));
			$message->save();
			$conversation->set('cnv_update_time', gmdate('Y-m-d H:i:s'));
			$conversation->save();
		}
	}

	// ------------------------------------------------------------------

	/** The chat header block, decoded. */
	protected function header(DirectEnvelope $envelope, array $parts): ?array {
		foreach ($parts as $part) {
			if ($part->role() !== DirectProtocol::ROLE_HEADERS) {
				continue;
			}
			$decoded = json_decode($part->open($envelope->vaultSecretKey()), true);
			return is_array($decoded) ? $decoded : null;
		}
		return null;
	}

	/** Has this exact message already landed here? */
	protected function alreadyStored(string $msg_guid): bool {
		$existing = new MultiMessage(array('guid' => $msg_guid));
		return $existing->count() > 0;
	}

	/**
	 * The local copy of the conversation this belongs to — found by its
	 * cross-instance identity, or created for a first message from this peer.
	 *
	 * A conversation is keyed on (guid, peer address) rather than on the guid
	 * alone: a guid arrives from a peer and is theirs to choose, so binding it
	 * to the sender is what stops one peer's guid landing a message in a
	 * conversation with somebody else.
	 */
	protected function conversationFor(DirectEnvelope $envelope, array $header, int $recipient_user_id,
			bool $create = true): ?Conversation {
		$guid = (string)($header['cnv_guid'] ?? '');
		if ($guid === '') {
			return null;
		}
		$sender = $envelope->sender();

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			'SELECT cnv.cnv_conversation_id
			 FROM cnv_conversations cnv
			 JOIN crp_conversation_remote_peers crp
			   ON crp.crp_cnv_conversation_id = cnv.cnv_conversation_id
			  AND crp.crp_address = ? AND crp.crp_delete_time IS NULL
			 JOIN cnp_conversation_participants cnp
			   ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id
			  AND cnp.cnp_usr_user_id = ?
			 WHERE cnv.cnv_guid = ? AND cnv.cnv_delete_time IS NULL
			 LIMIT 1');
		$q->execute(array($sender, $recipient_user_id, $guid));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			return new Conversation((int)$row['cnv_conversation_id'], TRUE);
		}
		if (!$create) {
			return null;
		}

		// First message of a new cross-instance conversation. The local side has
		// exactly one member — the recipient — plus a remote peer; a
		// cross-instance conversation is 1:1 in this version.
		$conversation = new Conversation(NULL);
		$conversation->set('cnv_guid', $guid);
		$conversation->set('cnv_protection_level', ProtectionLevel::STANDARD);
		$conversation->set('cnv_create_time', gmdate('Y-m-d H:i:s'));
		$conversation->set('cnv_update_time', gmdate('Y-m-d H:i:s'));
		$conversation->save();

		$participant = new ConversationParticipant(NULL);
		$participant->set('cnp_cnv_conversation_id', $conversation->key);
		$participant->set('cnp_usr_user_id', $recipient_user_id);
		$participant->set('cnp_create_time', gmdate('Y-m-d H:i:s'));
		$participant->save();

		ConversationRemotePeer::ensure($conversation->key, $sender,
			(string)($header['sender_name'] ?? ''));

		return $conversation;
	}

	/** A message guid to its local row id, within one conversation. */
	protected function localIdForGuid(string $guid, Conversation $conversation): ?int {
		if ($guid === '') {
			return null;
		}
		$rows = new MultiMessage(array(
			'guid'            => $guid,
			'conversation_id' => (int)$conversation->key,
		));
		foreach ($rows as $row) {
			return (int)$row->key;
		}
		return null;
	}

	/**
	 * Store arriving attachment bytes as ordinary conversation attachments.
	 *
	 * They land gated on this conversation like any other, so the local
	 * participants can open them and nobody else can. If the conversation is
	 * protected, add_message() seals them under its key on the way in.
	 *
	 * @param DirectPart[] $parts
	 * @return File[]
	 */
	protected function storeAttachments(array $parts, DirectEnvelope $envelope, int $owner_user_id,
			Conversation $conversation): array {
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerAttachmentGate.php'));

		$out = array();
		foreach ($parts as $part) {
			try {
				$bytes = $part->open($envelope->vaultSecretKey());
				$file = File::createFromBytes($bytes,
					(string)($part->filename() ?: 'attachment'),
					$part->contentType(), $owner_user_id, array(
						'fil_source'           => File::SOURCE_MESSENGER_ATTACHMENT,
						'fil_access_provider'  => MessengerAttachmentGate::KEY,
						'fil_access_ref'       => (int)$conversation->key,
					));
				$out[] = $file;
			} catch (Exception $e) {
				error_log('[ChatDirectHandler] could not store an arriving attachment: ' . $e->getMessage());
			}
		}
		return $out;
	}
}
