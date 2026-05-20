<?php
/**
 * InboundEmailRouter - Core inbound email routing logic.
 *
 * Parses raw email, looks up alias, verifies DKIM, checks rate limits,
 * and either forwards via SmtpMailer or stores locally (or both, depending
 * on the alias / catch-all delivery mode). Handles SRS bounce processing.
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/SRSRewriter.php'));

class InboundEmailRouter {

	private $settings;

	function __construct() {
		$this->settings = Globalvars::get_instance();
	}

	/**
	 * Process a raw email from stdin.
	 *
	 * @param string $raw_email         Raw email content from Postfix
	 * @param string $envelope_recipient Envelope recipient from Postfix ${recipient}
	 * @return int Exit code (0=success, 67=unknown user, 75=temp failure)
	 */
	public function processEmail($raw_email, $envelope_recipient) {
		$envelope_recipient = strtolower(trim($envelope_recipient));
		$parsed = $this->parseEmail($raw_email);

		// 1. SRS bounce check
		if ($this->settings->get_setting('inbound_email_srs_enabled') && SRSRewriter::isSRSAddress($envelope_recipient)) {
			return $this->handleSRSBounce($parsed, $raw_email, $envelope_recipient);
		}

		// 2. Look up alias
		$parts = explode('@', $envelope_recipient, 2);
		if (count($parts) !== 2) {
			return 67;
		}
		$local_part = $parts[0];
		$domain_name = $parts[1];

		// Look up domain
		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->get('ied_is_enabled')) {
			return 67;
		}

		// 3. Size cap applies to every path (forward, store, catch-all)
		if (strlen($raw_email) > 25 * 1024 * 1024) {
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'Message too large', $domain->key);
			return 0;
		}

		// Look up alias
		$alias = $this->lookupAlias($local_part, $domain);
		if (!$alias) {
			// Catch-all branch
			$catch_all_mode = $domain->get('ied_catch_all_mode') ?: InboundEmailDomain::CATCHALL_FORWARD;

			if ($catch_all_mode === InboundEmailDomain::CATCHALL_STORE) {
				// Store every unmatched recipient — supersedes ied_reject_unmatched.
				return $this->handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, null);
			}

			$catch_all = $domain->get('ied_catch_all_address');
			if ($catch_all) {
				return $this->forwardToCatchAll($parsed, $raw_email, $envelope_recipient, $domain, $catch_all);
			}

			// No match
			if ($domain->get('ied_reject_unmatched')) {
				$this->logTransaction($parsed, null, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'No matching alias', $domain->key);
				return 67; // Reject
			} else {
				$this->logTransaction($parsed, null, InboundEmailLog::STATUS_DISCARDED, $envelope_recipient, null, null, $domain->key);
				return 0; // Discard silently
			}
		}

		// 4. DKIM verification (informational — we record the result either way)
		$dkim_result = $this->verifyDKIM($raw_email, $parsed);
		if ($dkim_result === 'fail') {
			error_log('InboundEmailRouter: DKIM verification failed for ' . $envelope_recipient . ' from ' . ($parsed['from_email'] ?? 'unknown'));
		}

		$mode = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
		$forwards = ($mode === InboundEmailAlias::MODE_FORWARD || $mode === InboundEmailAlias::MODE_FORWARD_AND_STORE);
		$stores = ($mode === InboundEmailAlias::MODE_STORE || $mode === InboundEmailAlias::MODE_FORWARD_AND_STORE);

		// Pure-store mode skips forwarding-side gates (rate limit, From-header check)
		// because they only apply to relay attempts.
		if (!$forwards) {
			return $this->handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, $alias, $dkim_result);
		}

		// 5. Rate limiting (gates the forward path only)
		if (!$this->checkAliasRateLimit($alias->key)) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_RATE_LIMITED, $envelope_recipient, null, null, $domain->key);
			return 0;
		}
		if (!$this->checkDomainRateLimit($domain->key)) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_RATE_LIMITED, $envelope_recipient, null, null, $domain->key);
			return 0;
		}

		// 6. Basic header checks (forward path requires a usable From header)
		if (empty($parsed['from'])) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'Missing From header', $domain->key);
			return 0;
		}

		// 7. Forward
		$destinations = $alias->get_destinations_array();
		$results = $this->forwardEmail($raw_email, $parsed, $alias, $domain, $destinations);

		$all_success = !in_array(false, $results, true);
		$dest_str = implode(',', $destinations);

		if ($all_success) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_FORWARDED, $envelope_recipient, $dest_str, null, $domain->key);
			$alias->record_forward();
		} else {
			$failed = array();
			foreach ($results as $dest => $success) {
				if (!$success) {
					$failed[] = $dest;
				}
			}
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, $dest_str, 'Failed to deliver to: ' . implode(', ', $failed), $domain->key);
		}

		// 8. forward_and_store — best-effort copy after the forward. A failure
		// here is logged but does NOT change the exit code, because the forward
		// already happened and retrying would double-forward.
		if ($stores) {
			try {
				$this->storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $dkim_result);
			} catch (\Throwable $e) {
				error_log('InboundEmailRouter: store after forward failed: ' . $e->getMessage());
				$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, null, 'Store after forward failed: ' . $e->getMessage(), $domain->key);
			}
		}

		return 0;
	}

	/**
	 * Pure-store path: persist the message and return success/temp-fail.
	 * Used by alias-store mode AND domain catch-all-store mode (alias=null).
	 *
	 * Exit code: 0 on success or successful dedup; 75 on transient DB
	 * failure so Postfix retries. The dedup mechanism is the UNIQUE
	 * constraint on (iem_message_id_header, iem_recipient).
	 */
	private function handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, $alias, $dkim_result = null) {
		if ($dkim_result === null) {
			$dkim_result = $this->verifyDKIM($raw_email, $parsed);
		}

		// Volume cap (per-domain stores within forwarding window)
		$cap = intval($this->settings->get_setting('inbound_email_mailbox_max_per_window'));
		if ($cap > 0) {
			$window = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_window')) ?: 3600;
			$count = $this->countStoresInWindow($domain->key, $window);
			if ($count >= $cap) {
				$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_STORE_CAPPED, $envelope_recipient, null, 'Store volume cap reached (' . $cap . ')', $domain->key);
				return 0;
			}
		}

		try {
			$saved = $this->storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $dkim_result);
			$this->logTransaction(
				$parsed,
				$alias,
				InboundEmailLog::STATUS_STORED,
				$envelope_recipient,
				$saved['dedup'] ? 'duplicate (Message-ID already stored)' : null,
				null,
				$domain->key
			);
			return 0;
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: store failed: ' . $e->getMessage());
			// No alias.record_forward() etc — and DO NOT log here because
			// returning 75 will cause Postfix to retry; logging would create
			// noise rows for transient failures. The retry succeeds via the
			// DB unique constraint.
			return 75;
		}
	}

	/**
	 * Persist a message to iem_inbound_email_messages.
	 *
	 * Returns ['message' => InboundEmailMessage|null, 'dedup' => bool].
	 * On unique-violation (SQLSTATE 23505), treats the store as a successful
	 * retry — returns dedup=true with message=null. Other PDO errors propagate.
	 *
	 * Always stores the ORIGINAL raw_email (never the header-rewritten copy
	 * forwardEmail() builds for relay), so forward_and_store preserves the
	 * faithful message.
	 */
	public function storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $dkim_result = null) {
		$bodies = $this->extractBodies($raw_email, $parsed);

		$message_id_header = isset($parsed['headers']['message-id'])
			? (is_array($parsed['headers']['message-id'])
				? $parsed['headers']['message-id'][0]
				: $parsed['headers']['message-id'])
			: '';
		$message_id_header = trim((string)$message_id_header);
		if ($message_id_header === '') {
			$message_id_header = null;
		} else {
			$message_id_header = substr($message_id_header, 0, 255);
		}

		$subject_raw = $parsed['subject'] ?? '';
		$subject = $this->decodeMimeHeader($subject_raw);

		$row = [
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => substr($parsed['from_email'] ?? ($parsed['from'] ?? ''), 0, 500),
			'iem_recipient'   => substr($envelope_recipient, 0, 500),
			'iem_subject'     => substr($subject, 0, 1000),
			'iem_body_plain'  => $bodies['plain'],
			'iem_body_html'   => $bodies['html'],
			'iem_raw_message' => $raw_email,
			'iem_message_id_header' => $message_id_header,
			'iem_dkim_result' => $dkim_result ?: 'none',
			'iem_size_bytes'  => strlen($raw_email),
			'iem_received_time' => gmdate('Y-m-d H:i:s'),
		];

		try {
			$msg = InboundEmailMessage::CreateEntry($row);
			return ['message' => $msg, 'dedup' => false];
		} catch (PDOException $e) {
			if ($e->getCode() === '23505') {
				return ['message' => null, 'dedup' => true];
			}
			throw $e;
		} catch (\Throwable $e) {
			// Some SystemBase implementations may wrap the PDOException.
			$prev = $e->getPrevious();
			if ($prev instanceof PDOException && $prev->getCode() === '23505') {
				return ['message' => null, 'dedup' => true];
			}
			throw $e;
		}
	}

	/**
	 * Count non-deleted store rows for a domain in the given window
	 * (seconds back from now). Used by the volume cap.
	 */
	private function countStoresInWindow($domain_id, $window_seconds) {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT COUNT(*) AS cnt FROM iem_inbound_email_messages
				WHERE iem_ied_inbound_email_domain_id = ?
				AND iem_delete_time IS NULL
				AND iem_received_time > NOW() - INTERVAL '" . intval($window_seconds) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$domain_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return intval($row['cnt'] ?? 0);
	}

	/**
	 * Parse a raw email into structured data.
	 *
	 * @param string $raw_email Raw email content
	 * @return array Parsed email with: from, to, subject, headers, body
	 */
	public function parseEmail($raw_email) {
		// Handle both \r\n and \n line endings
		$normalized = str_replace("\r\n", "\n", $raw_email);

		// Split headers from body at first blank line
		$split_pos = strpos($normalized, "\n\n");
		if ($split_pos === false) {
			return array('from' => '', 'to' => '', 'subject' => '', 'headers' => array(), 'body' => $normalized);
		}

		$header_block = substr($normalized, 0, $split_pos);
		$body = substr($normalized, $split_pos + 2);

		// Parse headers, handling continuation lines
		$headers = array();
		$current_key = null;
		foreach (explode("\n", $header_block) as $line) {
			if (preg_match('/^\s+/', $line) && $current_key !== null) {
				// Continuation line
				$headers[$current_key] .= ' ' . trim($line);
			} elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
				$current_key = strtolower(trim($m[1]));
				if (isset($headers[$current_key])) {
					// Duplicate header — append (for things like Received:)
					if (!is_array($headers[$current_key])) {
						$headers[$current_key] = array($headers[$current_key]);
					}
					$headers[$current_key][] = trim($m[2]);
				} else {
					$headers[$current_key] = trim($m[2]);
				}
			}
		}

		$from = is_array($headers['from'] ?? '') ? ($headers['from'][0] ?? '') : ($headers['from'] ?? '');
		$to = is_array($headers['to'] ?? '') ? ($headers['to'][0] ?? '') : ($headers['to'] ?? '');
		$subject = is_array($headers['subject'] ?? '') ? ($headers['subject'][0] ?? '') : ($headers['subject'] ?? '');

		// Extract plain email from From header (may contain "Name <email>")
		$from_email = $from;
		if (preg_match('/<([^>]+)>/', $from, $m)) {
			$from_email = $m[1];
		}

		return array(
			'from' => $from,
			'from_email' => $from_email,
			'to' => $to,
			'subject' => $subject,
			'headers' => $headers,
			'body' => $body,
		);
	}

	/**
	 * Look up an alias for the given local part and domain.
	 *
	 * @param string $local_part Local part of the address
	 * @param InboundEmailDomain $domain Domain object
	 * @return InboundEmailAlias|null
	 */
	public function lookupAlias($local_part, $domain) {
		$results = new MultiInboundEmailAlias(array(
			'domain_id' => $domain->key,
			'alias' => strtolower($local_part),
			'deleted' => false
		));
		$results->load();

		if (count($results)) {
			$alias = $results->get(0);
			if ($alias->get('iea_is_enabled')) {
				return $alias;
			}
		}

		return null;
	}

	/**
	 * Forward the raw email to all destinations.
	 *
	 * @param string $raw_email Raw email content
	 * @param array $parsed Parsed email data
	 * @param InboundEmailAlias $alias Alias object
	 * @param InboundEmailDomain $domain Domain object
	 * @param array $destinations Array of destination email addresses
	 * @return array ['destination' => bool success]
	 */
	public function forwardEmail($raw_email, $parsed, $alias, $domain, $destinations) {
		require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

		$results = array();
		$forwarding_domain = $domain->get('ied_domain');
		$alias_address = $alias->get('iea_alias') . '@' . $forwarding_domain;

		// SRS rewrite envelope sender
		$envelope_sender = $parsed['from_email'];
		if ($this->settings->get_setting('inbound_email_srs_enabled')) {
			$srs = new SRSRewriter();
			$envelope_sender = $srs->rewrite($parsed['from_email'], $forwarding_domain);
		}

		// Modify the raw email for forwarding:
		// - Replace From header with verified sender (for deliverability)
		// - Add Reply-To with original sender
		// - Add forwarding headers
		$default_from = $this->settings->get_setting('defaultemail');
		$original_sender_name = $this->extractName($parsed['from']);
		$from_display = $this->forwardedFromDisplay($original_sender_name);

		$normalized = str_replace("\r\n", "\n", $raw_email);

		// Split into header block and body
		$split_pos = strpos($normalized, "\n\n");
		if ($split_pos === false) {
			$header_block = $normalized;
			$body_block = '';
		} else {
			$header_block = substr($normalized, 0, $split_pos);
			$body_block = substr($normalized, $split_pos + 2);
		}

		// Replace From header
		$header_block = preg_replace('/^From:.*$/mi', 'From: ' . $from_display . ' <' . $default_from . '>', $header_block);

		// Remove existing Reply-To if present, then add ours
		$header_block = preg_replace('/^Reply-To:.*$/mi', '', $header_block);

		// Add forwarding headers and Reply-To
		$extra_headers = "Reply-To: " . $parsed['from_email'] . "\n";
		$extra_headers .= "X-Original-To: " . $alias_address . "\n";
		$extra_headers .= "X-Forwarded-For: " . $alias_address . "\n";
		$extra_headers .= "X-Forwarded-By: Joinery Inbound Email";

		$header_block = trim($header_block) . "\n" . $extra_headers;

		// Reassemble with \r\n for SMTP
		$modified_header = str_replace("\n", "\r\n", $header_block);
		$modified_body = str_replace("\n", "\r\n", $body_block);

		foreach ($destinations as $destination) {
			try {
				$mailer = $this->createMailer();

				// Set envelope sender and recipient for the SMTP transaction
				$mailer->Sender = $envelope_sender;
				$mailer->addAddress($destination);

				// Connect and send raw message via SMTP directly
				if (!$mailer->smtpConnect()) {
					throw new Exception('SMTP connect failed: ' . $mailer->ErrorInfo);
				}

				$smtp = $mailer->getSMTPInstance();

				if (!$smtp->mail($envelope_sender)) {
					throw new Exception('SMTP MAIL FROM failed');
				}
				if (!$smtp->recipient($destination)) {
					throw new Exception('SMTP RCPT TO failed');
				}
				if (!$smtp->data($modified_header . "\r\n\r\n" . $modified_body)) {
					throw new Exception('SMTP DATA failed');
				}

				$smtp->quit();
				$smtp->close();

				$results[$destination] = true;
			} catch (Exception $e) {
				error_log('InboundEmailRouter: Failed to forward to ' . $destination . ': ' . $e->getMessage());
				$results[$destination] = false;
			}
		}

		return $results;
	}

	/**
	 * Forward to a catch-all address.
	 */
	private function forwardToCatchAll($parsed, $raw_email, $envelope_recipient, $domain, $catch_all_address) {
		require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

		$forwarding_domain = $domain->get('ied_domain');

		$envelope_sender = $parsed['from_email'];
		if ($this->settings->get_setting('inbound_email_srs_enabled')) {
			$srs = new SRSRewriter();
			$envelope_sender = $srs->rewrite($parsed['from_email'], $forwarding_domain);
		}

		// Use site's verified from address; original sender in Reply-To
		$default_from = $this->settings->get_setting('defaultemail');
		$original_sender_name = $this->extractName($parsed['from']);
		$from_display = $this->forwardedFromDisplay($original_sender_name);

		try {
			$mailer = $this->createMailer();
			$mailer->addAddress($catch_all_address);
			$mailer->Sender = $envelope_sender;
			$mailer->setFrom($default_from, $from_display);
			$mailer->addReplyTo($parsed['from_email'], $original_sender_name);
			$mailer->Subject = $parsed['subject'];
			$mailer->Body = $parsed['body'];

			$content_type = $parsed['headers']['content-type'] ?? '';
			if (stripos($content_type, 'text/html') !== false) {
				$mailer->isHTML(true);
			}

			$success = $mailer->send();
			$status = $success ? InboundEmailLog::STATUS_FORWARDED : InboundEmailLog::STATUS_ERROR;
			$this->logTransaction($parsed, null, $status, $envelope_recipient, $catch_all_address, $success ? null : 'Catch-all delivery failed', $domain->key);
		} catch (Exception $e) {
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_ERROR, $envelope_recipient, $catch_all_address, $e->getMessage(), $domain->key);
		}

		return 0;
	}

	/**
	 * Handle an SRS bounce — decode and forward to original sender.
	 */
	private function handleSRSBounce($parsed, $raw_email, $envelope_recipient) {
		$srs = new SRSRewriter();

		if (!$srs->validate($envelope_recipient)) {
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_DISCARDED, $envelope_recipient, null, 'Invalid/expired SRS address');
			return 0;
		}

		$original_sender = $srs->decode($envelope_recipient);
		if (!$original_sender) {
			return 0;
		}

		try {
			require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
			$mailer = $this->createMailer();
			$mailer->addAddress($original_sender);

			$settings = Globalvars::get_instance();
			$default_from = $settings->get_setting('defaultemail');
			$default_name = $settings->get_setting('defaultemailname');
			$mailer->setFrom($default_from, $default_name);

			$mailer->Subject = 'Delivery failure: ' . ($parsed['subject'] ?: '(no subject)');
			$mailer->Body = "Your email could not be delivered.\n\n" . ($parsed['body'] ?: '');
			$mailer->isHTML(false);

			$mailer->send();
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_BOUNCE_FORWARDED, $envelope_recipient, $original_sender);
		} catch (Exception $e) {
			error_log('InboundEmailRouter: Failed to forward bounce to ' . $original_sender . ': ' . $e->getMessage());
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_ERROR, $envelope_recipient, $original_sender, $e->getMessage());
		}

		return 0;
	}

	/**
	 * Create a SmtpMailer instance with forwarding-specific settings (or fallback to main).
	 *
	 * This is the single outbound-relay acquisition routine: it is called both
	 * by the router itself and by InboundEmailHealth::checkForwardingRelay(),
	 * so the provisioning check exercises the exact relay the feature uses.
	 */
	public function createMailer() {
		require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

		$mailer = new SmtpMailer();

		// Override with forwarding-specific SMTP settings if configured
		$fwd_host = $this->settings->get_setting('inbound_email_forwarding_smtp_host');
		if ($fwd_host) {
			$mailer->Host = $fwd_host;
		}
		$fwd_port = $this->settings->get_setting('inbound_email_forwarding_smtp_port');
		if ($fwd_port) {
			$mailer->Port = intval($fwd_port);
			// Re-detect encryption for new port
			switch ($mailer->Port) {
				case 465:
					$mailer->SMTPSecure = 'ssl';
					break;
				case 587:
				case 2525:
					$mailer->SMTPSecure = 'tls';
					break;
				case 25:
					$mailer->SMTPSecure = '';
					break;
			}
		}
		$fwd_user = $this->settings->get_setting('inbound_email_forwarding_smtp_username');
		if ($fwd_user) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $fwd_user;
		}
		$fwd_pass = $this->settings->get_setting('inbound_email_forwarding_smtp_password');
		if ($fwd_pass) {
			$mailer->Password = $fwd_pass;
		}

		return $mailer;
	}

	/**
	 * Verify inbound DKIM signature.
	 *
	 * @param string $raw_email Raw email content
	 * @param array $parsed Parsed email data
	 * @return string 'pass', 'fail', or 'none'
	 */
	public function verifyDKIM($raw_email, $parsed) {
		$dkim_header = $parsed['headers']['dkim-signature'] ?? null;
		if (!$dkim_header) {
			return 'none'; // No DKIM signature present
		}

		// If multiple DKIM signatures, use the first
		if (is_array($dkim_header)) {
			$dkim_header = $dkim_header[0];
		}

		try {
			// Parse DKIM-Signature fields
			$dkim_fields = $this->parseDKIMSignature($dkim_header);
			if (!$dkim_fields) {
				return 'none';
			}

			$domain = $dkim_fields['d'] ?? '';
			$selector = $dkim_fields['s'] ?? '';
			$algorithm = $dkim_fields['a'] ?? 'rsa-sha256';
			$body_hash_expected = $dkim_fields['bh'] ?? '';
			$signature_b64 = $dkim_fields['b'] ?? '';
			$signed_headers_list = $dkim_fields['h'] ?? '';
			$canonicalization = $dkim_fields['c'] ?? 'relaxed/relaxed';

			if (!$domain || !$selector || !$body_hash_expected || !$signature_b64) {
				return 'none';
			}

			// Only support rsa-sha256 (vast majority of DKIM)
			if ($algorithm !== 'rsa-sha256') {
				return 'none'; // Unsupported algorithm — fail open
			}

			// DNS lookup for public key
			$dns_name = $selector . '._domainkey.' . $domain;
			try {
				$dns_txt = DnsResolver::getTxt($dns_name);
			} catch (DnsLookupException $e) {
				return 'none'; // DNS error — fail open
			}
			if (empty($dns_txt)) {
				return 'none';
			}

			$public_key_data = '';
			foreach ($dns_txt as $txt) {
				if (strpos($txt, 'p=') !== false) {
					$public_key_data = $txt;
					break;
				}
			}

			if (!$public_key_data) {
				return 'none';
			}

			// Extract public key
			if (preg_match('/p=([A-Za-z0-9+\/=]+)/', $public_key_data, $m)) {
				$pub_key_b64 = $m[1];
			} else {
				return 'none';
			}

			$pub_key_pem = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($pub_key_b64, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
			$pub_key = openssl_pkey_get_public($pub_key_pem);
			if (!$pub_key) {
				return 'none'; // Invalid key — fail open
			}

			// Verify body hash
			$canon_parts = explode('/', $canonicalization);
			$body_canon = $canon_parts[1] ?? 'simple';

			$normalized = str_replace("\r\n", "\n", $raw_email);
			$body_start = strpos($normalized, "\n\n");
			$body_content = ($body_start !== false) ? substr($normalized, $body_start + 2) : '';

			if ($body_canon === 'relaxed') {
				$body_content = $this->canonicalizeBodyRelaxed($body_content);
			} else {
				$body_content = $this->canonicalizeBodySimple($body_content);
			}

			$computed_bh = base64_encode(hash('sha256', $body_content, true));
			if ($computed_bh !== $body_hash_expected) {
				return 'fail'; // Body was modified
			}

			// Verify header signature
			$header_canon = $canon_parts[0] ?? 'relaxed';
			$signed_headers = array_map('trim', explode(':', strtolower($signed_headers_list)));

			$header_data = '';
			foreach ($signed_headers as $hname) {
				$hvalue = $parsed['headers'][$hname] ?? '';
				if (is_array($hvalue)) {
					$hvalue = $hvalue[0];
				}
				if ($header_canon === 'relaxed') {
					$header_data .= strtolower(trim($hname)) . ':' . preg_replace('/\s+/', ' ', trim($hvalue)) . "\r\n";
				} else {
					$header_data .= $hname . ': ' . $hvalue . "\r\n";
				}
			}

			// Add DKIM-Signature header without the b= value
			$dkim_for_verify = preg_replace('/b=[A-Za-z0-9+\/=\s]+/', 'b=', $dkim_header);
			if ($header_canon === 'relaxed') {
				$header_data .= 'dkim-signature:' . preg_replace('/\s+/', ' ', trim($dkim_for_verify));
			} else {
				$header_data .= 'DKIM-Signature: ' . $dkim_for_verify;
			}

			$signature = base64_decode(preg_replace('/\s+/', '', $signature_b64));
			$verify_result = openssl_verify($header_data, $signature, $pub_key, OPENSSL_ALGO_SHA256);

			if ($verify_result === 1) {
				return 'pass';
			} elseif ($verify_result === 0) {
				return 'fail';
			} else {
				return 'none'; // OpenSSL error — fail open
			}

		} catch (Exception $e) {
			error_log('InboundEmailRouter DKIM error: ' . $e->getMessage());
			return 'none'; // Error — fail open
		}
	}

	/**
	 * Parse DKIM-Signature header into key-value pairs.
	 */
	private function parseDKIMSignature($header) {
		$fields = array();
		// Remove line continuations
		$header = preg_replace('/\s+/', ' ', $header);
		$parts = explode(';', $header);
		foreach ($parts as $part) {
			$part = trim($part);
			$eq = strpos($part, '=');
			if ($eq !== false) {
				$key = trim(substr($part, 0, $eq));
				$value = trim(substr($part, $eq + 1));
				$fields[$key] = $value;
			}
		}
		return $fields;
	}

	/**
	 * Relaxed body canonicalization per RFC 6376.
	 */
	private function canonicalizeBodyRelaxed($body) {
		$lines = explode("\n", $body);
		$result = array();
		foreach ($lines as $line) {
			$line = rtrim($line);
			$line = preg_replace('/[ \t]+/', ' ', $line);
			$result[] = $line;
		}
		$body = implode("\r\n", $result);
		// Remove trailing empty lines
		$body = rtrim($body, "\r\n") . "\r\n";
		return $body;
	}

	/**
	 * Simple body canonicalization per RFC 6376.
	 */
	private function canonicalizeBodySimple($body) {
		$body = str_replace("\n", "\r\n", $body);
		// Remove trailing empty lines, ensure single trailing CRLF
		$body = rtrim($body, "\r\n") . "\r\n";
		return $body;
	}

	/**
	 * Check per-alias rate limit using the inbound email log table.
	 */
	private function checkAliasRateLimit($alias_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_per_alias')) ?: 50;

		$sql = "SELECT COUNT(*) as cnt FROM iel_inbound_email_logs
				WHERE iel_iea_inbound_email_alias_id = ?
				AND iel_status = 'forwarded'
				AND iel_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$alias_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max);
	}

	/**
	 * Check per-domain rate limit using the inbound email log table.
	 *
	 * Uses iel_ied_inbound_email_domain_id directly — populated on every
	 * transaction since the local-mailbox change, so catch-all stores are
	 * also visible to per-domain counting without joining the alias table.
	 */
	private function checkDomainRateLimit($domain_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_per_domain')) ?: 200;

		$sql = "SELECT COUNT(*) as cnt FROM iel_inbound_email_logs
				WHERE iel_ied_inbound_email_domain_id = ?
				AND iel_status = 'forwarded'
				AND iel_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$domain_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max);
	}

	/**
	 * Log an inbound email transaction.
	 *
	 * $domain_id is recorded directly on the log row so the Logs viewer's
	 * domain filter and the per-domain rate-limit query work without a
	 * join through the alias table — and so catch-all stores (alias null)
	 * remain visible to the domain filter.
	 */
	public function logTransaction($parsed, $alias, $status, $to_address, $destinations = null, $error = null, $domain_id = null) {
		InboundEmailLog::CreateEntry(
			$parsed['from'] ?? '',
			$to_address,
			$parsed['subject'] ?? '',
			$destinations,
			$status,
			$alias ? $alias->key : null,
			$error,
			$domain_id
		);
	}

	/**
	 * Decode an RFC 2047 encoded-word header value (Subject, display names)
	 * to readable UTF-8. Returns the input unchanged if mb_decode_mimeheader
	 * is unavailable.
	 */
	private function decodeMimeHeader($value) {
		if ($value === '' || $value === null) {
			return '';
		}
		if (function_exists('mb_decode_mimeheader')) {
			return mb_decode_mimeheader($value);
		}
		return $value;
	}

	/**
	 * Split a raw MIME message into best-effort plain and html bodies.
	 *
	 * Handles multipart/alternative and multipart/mixed (one level deep),
	 * decodes quoted-printable and base64 transfer encodings, and converts
	 * each part to UTF-8 from its declared charset. The original
	 * raw_email is always preserved separately (iem_raw_message), so
	 * imperfect decoding never loses data.
	 *
	 * Returns ['plain' => string, 'html' => string].
	 */
	public function extractBodies($raw_email, $parsed) {
		$result = ['plain' => '', 'html' => ''];

		$headers = $parsed['headers'] ?? [];
		$ct_raw = $headers['content-type'] ?? '';
		if (is_array($ct_raw)) { $ct_raw = $ct_raw[0]; }
		$cte = $headers['content-transfer-encoding'] ?? '';
		if (is_array($cte)) { $cte = $cte[0]; }

		// Get the full message body (everything after the first blank line)
		$normalized = str_replace("\r\n", "\n", $raw_email);
		$split_pos = strpos($normalized, "\n\n");
		$body = ($split_pos !== false) ? substr($normalized, $split_pos + 2) : $normalized;

		// Single-part: no multipart, treat as html or plain by content-type
		if (stripos($ct_raw, 'multipart/') === false) {
			$decoded = $this->decodePartBody($body, $cte);
			$charset = $this->extractCharset($ct_raw);
			$decoded = $this->toUtf8($decoded, $charset);
			if (stripos($ct_raw, 'text/html') !== false) {
				$result['html'] = $decoded;
			} else {
				$result['plain'] = $decoded;
			}
			return $result;
		}

		// Multipart — extract boundary
		if (!preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $ct_raw, $bm)) {
			$result['plain'] = $body;
			return $result;
		}
		$boundary = $bm[1];

		$parts = $this->splitMultipart($body, $boundary);
		foreach ($parts as $part) {
			$p = $this->parseMimePart($part);
			$p_ct = $p['headers']['content-type'] ?? '';
			$p_cte = $p['headers']['content-transfer-encoding'] ?? '';
			$decoded = $this->decodePartBody($p['body'], $p_cte);
			$charset = $this->extractCharset($p_ct);
			$decoded = $this->toUtf8($decoded, $charset);

			if (stripos($p_ct, 'multipart/') !== false) {
				// Nested multipart — recurse one level by re-running extract on this part
				if (preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $p_ct, $nb)) {
					$sub_parts = $this->splitMultipart($p['body'], $nb[1]);
					foreach ($sub_parts as $sub) {
						$sp = $this->parseMimePart($sub);
						$sp_ct = $sp['headers']['content-type'] ?? '';
						$sp_cte = $sp['headers']['content-transfer-encoding'] ?? '';
						$sd = $this->toUtf8(
							$this->decodePartBody($sp['body'], $sp_cte),
							$this->extractCharset($sp_ct)
						);
						if (stripos($sp_ct, 'text/html') !== false && $result['html'] === '') {
							$result['html'] = $sd;
						} elseif (stripos($sp_ct, 'text/plain') !== false && $result['plain'] === '') {
							$result['plain'] = $sd;
						}
					}
				}
				continue;
			}

			if (stripos($p_ct, 'text/html') !== false && $result['html'] === '') {
				$result['html'] = $decoded;
			} elseif (stripos($p_ct, 'text/plain') !== false && $result['plain'] === '') {
				$result['plain'] = $decoded;
			}
		}

		return $result;
	}

	private function splitMultipart($body, $boundary) {
		$delim = '--' . $boundary;
		$end = '--' . $boundary . '--';
		// Strip off everything before the first boundary and the closing terminator
		$pos = strpos($body, $delim);
		if ($pos === false) {
			return [];
		}
		$body = substr($body, $pos);
		$end_pos = strpos($body, $end);
		if ($end_pos !== false) {
			$body = substr($body, 0, $end_pos);
		}
		// Split on the boundary delimiter
		$raw_parts = preg_split('/(^|\n)--' . preg_quote($boundary, '/') . '\r?\n/', $body);
		$parts = [];
		foreach ($raw_parts as $rp) {
			$rp = trim($rp);
			if ($rp !== '' && substr($rp, 0, 2) !== '--') {
				$parts[] = $rp;
			}
		}
		return $parts;
	}

	private function parseMimePart($part) {
		$normalized = str_replace("\r\n", "\n", $part);
		$split_pos = strpos($normalized, "\n\n");
		if ($split_pos === false) {
			return ['headers' => [], 'body' => $normalized];
		}
		$header_block = substr($normalized, 0, $split_pos);
		$body = substr($normalized, $split_pos + 2);
		$headers = [];
		$current = null;
		foreach (explode("\n", $header_block) as $line) {
			if (preg_match('/^\s+/', $line) && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
			} elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
				$current = strtolower(trim($m[1]));
				$headers[$current] = trim($m[2]);
			}
		}
		return ['headers' => $headers, 'body' => $body];
	}

	private function decodePartBody($body, $encoding) {
		$encoding = strtolower(trim($encoding));
		if ($encoding === 'quoted-printable') {
			return quoted_printable_decode($body);
		}
		if ($encoding === 'base64') {
			return base64_decode($body) ?: '';
		}
		// 7bit / 8bit / binary / unspecified
		return $body;
	}

	private function extractCharset($content_type) {
		if (preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $content_type, $m)) {
			return strtoupper(trim($m[1]));
		}
		return '';
	}

	private function toUtf8($text, $charset) {
		if ($text === '' || $text === null) {
			return '';
		}
		if (!function_exists('mb_convert_encoding')) {
			return $text;
		}
		$charset = $charset !== '' ? $charset : 'UTF-8';
		// mb_convert_encoding tolerates unknown charsets by falling back to UTF-8
		$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
		return $converted !== false ? $converted : $text;
	}

	/**
	 * Build the From-header display name for a forwarded message. The original
	 * sender's address is replaced with the site's verified address for
	 * deliverability, so the display name is what carries who the mail is
	 * really from. The mailing-list style "via <site>" suffix can be turned
	 * off with the inbound_email_from_show_via setting.
	 */
	private function forwardedFromDisplay($original_sender_name) {
		$site = $this->settings->get_setting('defaultemailname') ?: 'Inbound Email';
		if ((string)$this->settings->get_setting('inbound_email_from_show_via') === '0') {
			return $original_sender_name ? $original_sender_name : 'Forwarded';
		}
		return $original_sender_name
			? $original_sender_name . ' via ' . $site
			: 'Forwarded via ' . $site;
	}

	/**
	 * Extract display name from a From header value.
	 */
	private function extractName($from_header) {
		if (preg_match('/^"?([^"<]+)"?\s*</', $from_header, $m)) {
			return trim($m[1]);
		}
		return '';
	}
}
?>
