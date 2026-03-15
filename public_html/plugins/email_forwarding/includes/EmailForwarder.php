<?php
/**
 * EmailForwarder - Core email forwarding logic.
 *
 * Parses raw email, looks up alias, verifies DKIM, checks rate limits,
 * and forwards via SmtpMailer. Handles SRS bounce processing.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/includes/SRSRewriter.php'));

class EmailForwarder {

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
		if ($this->settings->get_setting('email_forwarding_srs_enabled') && SRSRewriter::isSRSAddress($envelope_recipient)) {
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
		$domain = EmailForwardingDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->get('efd_is_enabled')) {
			return 67;
		}

		// Look up alias
		$alias = $this->lookupAlias($local_part, $domain);
		if (!$alias) {
			// Check catch-all
			$catch_all = $domain->get('efd_catch_all_address');
			if ($catch_all) {
				// Create a virtual alias for logging/forwarding
				return $this->forwardToCatchAll($parsed, $raw_email, $envelope_recipient, $domain, $catch_all);
			}

			// No match
			if ($domain->get('efd_reject_unmatched')) {
				$this->logTransaction($parsed, null, EmailForwardingLog::STATUS_REJECTED, $envelope_recipient, null, 'No matching alias');
				return 67; // Reject
			} else {
				$this->logTransaction($parsed, null, EmailForwardingLog::STATUS_DISCARDED, $envelope_recipient);
				return 0; // Discard silently
			}
		}

		// 3. DKIM verification
		$dkim_result = $this->verifyDKIM($raw_email, $parsed);
		if ($dkim_result === 'fail') {
			// Log the failure but don't reject — DKIM verification is still being refined
			error_log('EmailForwarder: DKIM verification failed for ' . $envelope_recipient . ' from ' . ($parsed['from_email'] ?? 'unknown'));
		}

		// 4. Rate limiting
		if (!$this->checkAliasRateLimit($alias->key)) {
			$this->logTransaction($parsed, $alias, EmailForwardingLog::STATUS_RATE_LIMITED, $envelope_recipient);
			return 0;
		}
		if (!$this->checkDomainRateLimit($domain->key)) {
			$this->logTransaction($parsed, $alias, EmailForwardingLog::STATUS_RATE_LIMITED, $envelope_recipient);
			return 0;
		}

		// 5. Basic header checks
		if (empty($parsed['from'])) {
			$this->logTransaction($parsed, $alias, EmailForwardingLog::STATUS_REJECTED, $envelope_recipient, null, 'Missing From header');
			return 0;
		}
		if (strlen($raw_email) > 25 * 1024 * 1024) {
			$this->logTransaction($parsed, $alias, EmailForwardingLog::STATUS_REJECTED, $envelope_recipient, null, 'Message too large');
			return 0;
		}

		// 6. Forward
		$destinations = $alias->get_destinations_array();
		$results = $this->forwardEmail($raw_email, $parsed, $alias, $domain, $destinations);

		// 7. Log
		$all_success = !in_array(false, $results, true);
		$dest_str = implode(',', $destinations);

		if ($all_success) {
			$this->logTransaction($parsed, $alias, EmailForwardingLog::STATUS_FORWARDED, $envelope_recipient, $dest_str);
			$alias->record_forward();
		} else {
			$failed = array();
			foreach ($results as $dest => $success) {
				if (!$success) {
					$failed[] = $dest;
				}
			}
			$this->logTransaction($parsed, $alias, EmailForwardingLog::STATUS_ERROR, $envelope_recipient, $dest_str, 'Failed to deliver to: ' . implode(', ', $failed));
		}

		return 0;
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
	 * @param EmailForwardingDomain $domain Domain object
	 * @return EmailForwardingAlias|null
	 */
	public function lookupAlias($local_part, $domain) {
		$results = new MultiEmailForwardingAlias(array(
			'domain_id' => $domain->key,
			'alias' => strtolower($local_part),
			'deleted' => false
		));
		$results->load();

		if (count($results)) {
			$alias = $results->get(0);
			if ($alias->get('efa_is_enabled')) {
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
	 * @param EmailForwardingAlias $alias Alias object
	 * @param EmailForwardingDomain $domain Domain object
	 * @param array $destinations Array of destination email addresses
	 * @return array ['destination' => bool success]
	 */
	public function forwardEmail($raw_email, $parsed, $alias, $domain, $destinations) {
		require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

		$results = array();
		$forwarding_domain = $domain->get('efd_domain');
		$alias_address = $alias->get('efa_alias') . '@' . $forwarding_domain;

		// SRS rewrite envelope sender
		$envelope_sender = $parsed['from_email'];
		if ($this->settings->get_setting('email_forwarding_srs_enabled')) {
			$srs = new SRSRewriter();
			$envelope_sender = $srs->rewrite($parsed['from_email'], $forwarding_domain);
		}

		// Modify the raw email for forwarding:
		// - Replace From header with verified sender (for deliverability)
		// - Add Reply-To with original sender
		// - Add forwarding headers
		$default_from = $this->settings->get_setting('defaultemail');
		$default_from_name = $this->settings->get_setting('defaultemailname') ?: 'Email Forwarding';
		$original_sender_name = $this->extractName($parsed['from']);
		$from_display = $original_sender_name ? ($original_sender_name . ' via ' . $default_from_name) : ('Forwarded via ' . $default_from_name);

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
		$extra_headers .= "X-Forwarded-By: Joinery Email Forwarder";

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
				error_log('EmailForwarder: Failed to forward to ' . $destination . ': ' . $e->getMessage());
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

		$forwarding_domain = $domain->get('efd_domain');

		$envelope_sender = $parsed['from_email'];
		if ($this->settings->get_setting('email_forwarding_srs_enabled')) {
			$srs = new SRSRewriter();
			$envelope_sender = $srs->rewrite($parsed['from_email'], $forwarding_domain);
		}

		// Use site's verified from address; original sender in Reply-To
		$default_from = $this->settings->get_setting('defaultemail');
		$default_from_name = $this->settings->get_setting('defaultemailname') ?: 'Email Forwarding';
		$original_sender_name = $this->extractName($parsed['from']);
		$from_display = $original_sender_name ? ($original_sender_name . ' via ' . $default_from_name) : ('Forwarded via ' . $default_from_name);

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
			$status = $success ? EmailForwardingLog::STATUS_FORWARDED : EmailForwardingLog::STATUS_ERROR;
			$this->logTransaction($parsed, null, $status, $envelope_recipient, $catch_all_address, $success ? null : 'Catch-all delivery failed');
		} catch (Exception $e) {
			$this->logTransaction($parsed, null, EmailForwardingLog::STATUS_ERROR, $envelope_recipient, $catch_all_address, $e->getMessage());
		}

		return 0;
	}

	/**
	 * Handle an SRS bounce — decode and forward to original sender.
	 */
	private function handleSRSBounce($parsed, $raw_email, $envelope_recipient) {
		$srs = new SRSRewriter();

		if (!$srs->validate($envelope_recipient)) {
			$this->logTransaction($parsed, null, EmailForwardingLog::STATUS_DISCARDED, $envelope_recipient, null, 'Invalid/expired SRS address');
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
			$this->logTransaction($parsed, null, EmailForwardingLog::STATUS_BOUNCE_FORWARDED, $envelope_recipient, $original_sender);
		} catch (Exception $e) {
			error_log('EmailForwarder: Failed to forward bounce to ' . $original_sender . ': ' . $e->getMessage());
			$this->logTransaction($parsed, null, EmailForwardingLog::STATUS_ERROR, $envelope_recipient, $original_sender, $e->getMessage());
		}

		return 0;
	}

	/**
	 * Create a SmtpMailer instance with forwarding-specific settings (or fallback to main).
	 */
	private function createMailer() {
		$mailer = new SmtpMailer();

		// Override with forwarding-specific SMTP settings if configured
		$fwd_host = $this->settings->get_setting('email_forwarding_smtp_host');
		if ($fwd_host) {
			$mailer->Host = $fwd_host;
		}
		$fwd_port = $this->settings->get_setting('email_forwarding_smtp_port');
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
		$fwd_user = $this->settings->get_setting('email_forwarding_smtp_username');
		if ($fwd_user) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $fwd_user;
		}
		$fwd_pass = $this->settings->get_setting('email_forwarding_smtp_password');
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
			$dns_records = @dns_get_record($dns_name, DNS_TXT);
			if (!$dns_records || empty($dns_records)) {
				return 'none'; // DNS error — fail open
			}

			$public_key_data = '';
			foreach ($dns_records as $record) {
				$txt = $record['txt'] ?? '';
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
			error_log('EmailForwarder DKIM error: ' . $e->getMessage());
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
	 * Check per-alias rate limit using the forwarding log table.
	 */
	private function checkAliasRateLimit($alias_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('email_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('email_forwarding_rate_limit_per_alias')) ?: 50;

		$sql = "SELECT COUNT(*) as cnt FROM efl_email_forwarding_logs
				WHERE efl_efa_email_forwarding_alias_id = ?
				AND efl_status = 'forwarded'
				AND efl_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$alias_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max);
	}

	/**
	 * Check per-domain rate limit using the forwarding log table.
	 */
	private function checkDomainRateLimit($domain_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('email_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('email_forwarding_rate_limit_per_domain')) ?: 200;

		$sql = "SELECT COUNT(*) as cnt FROM efl_email_forwarding_logs efl
				JOIN efa_email_forwarding_aliases efa ON efa.efa_email_forwarding_alias_id = efl.efl_efa_email_forwarding_alias_id
				WHERE efa.efa_efd_email_forwarding_domain_id = ?
				AND efl.efl_status = 'forwarded'
				AND efl.efl_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$domain_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max);
	}

	/**
	 * Log a forwarding transaction.
	 */
	public function logTransaction($parsed, $alias, $status, $to_address, $destinations = null, $error = null) {
		EmailForwardingLog::CreateEntry(
			$parsed['from'] ?? '',
			$to_address,
			$parsed['subject'] ?? '',
			$destinations,
			$status,
			$alias ? $alias->key : null,
			$error
		);
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
