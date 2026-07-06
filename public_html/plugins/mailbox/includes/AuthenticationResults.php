<?php
/**
 * AuthenticationResults - read SPF/DKIM/DMARC verdicts from a message's
 * Authentication-Results header (RFC 8601).
 *
 * The application NEVER computes these verdicts. The receiving MTA's milters
 * (opendkim in verify mode + opendmarc) evaluate SPF/DKIM/DMARC at SMTP time —
 * when the connecting client IP is still available — and stamp the result in an
 * Authentication-Results header. This class turns that header into structured
 * verdicts the router stores and the UI shows.
 *
 * Trust model: a message can arrive carrying attacker-supplied
 * Authentication-Results lines from upstream hops. We ONLY honor lines whose
 * authserv-id matches our own mail host (the AuthservID configured on the
 * milters, == mailbox_mail_hostname). Lines stamped by anyone else are
 * ignored. When no line matches our authserv-id, fromMessage() returns null and
 * the router records 'unverified' — never a verdict, never a hand-rolled 'fail'.
 *
 * opendkim and opendmarc each stamp their own Authentication-Results line with
 * the same authserv-id, so we MERGE every matching line: the dkim verdict comes
 * from opendkim's line, the spf/dmarc verdicts from opendmarc's. Multiple dkim=
 * entries (oversigning, multiple signatures) resolve to the strongest result
 * (a pass wins over a fail).
 *
 * This class is single-purpose: the standard Authentication-Results header
 * only. Webhook providers (Mailgun/SendGrid/SES) report their own SPF/DKIM/DMARC
 * verdicts, which they map in their handleInbound() and the router prefers over
 * this header — see InboundEmailRouter::readAuthResults() and
 * specs/inbound_mailgun_verification.md.
 *
 * @version 1.0
 */

class AuthenticationResults {

	/** @var string|null lowercased verdict token, or null if the method was not asserted */
	private $spf;
	private $dkim;
	private $dmarc;
	/** @var string|null signing domain (dkim header.d) / envelope-sender domain (spf smtp.mailfrom) */
	private $dkim_domain;
	private $spf_domain;

	private function __construct() {}

	public function spf()        { return $this->spf; }
	public function dkim()       { return $this->dkim; }
	public function dmarc()      { return $this->dmarc; }
	public function dkimDomain() { return $this->dkim_domain; }
	public function spfDomain()  { return $this->spf_domain; }

	/**
	 * @return array{spf:?string,dkim:?string,dmarc:?string,dkim_domain:?string,spf_domain:?string}
	 */
	public function toArray(): array {
		return array(
			'spf'         => $this->spf,
			'dkim'        => $this->dkim,
			'dmarc'       => $this->dmarc,
			'dkim_domain' => $this->dkim_domain,
			'spf_domain'  => $this->spf_domain,
		);
	}

	/**
	 * Build verdicts from a raw MIME message, trusting only Authentication-Results
	 * lines stamped by $authserv_id (our mail host).
	 *
	 * @param string $raw_message  Full raw MIME (headers + body).
	 * @param string $authserv_id  Our authserv-id (the mail hostname). Empty => trust nothing.
	 * @return self|null  null when no trusted Authentication-Results line is present.
	 */
	public static function fromMessage(string $raw_message, string $authserv_id): ?self {
		$authserv_id = strtolower(trim($authserv_id));
		if ($authserv_id === '') {
			// No configured authserv-id => we cannot attribute any line to us.
			return null;
		}

		$lines = self::extractHeaders($raw_message, 'authentication-results');
		if (!$lines) {
			return null;
		}

		$matched = false;
		$spf = $dkim = $dmarc = null;
		$dkim_domain = $spf_domain = null;

		foreach ($lines as $line) {
			$segments = self::splitSegments($line);
			if (!$segments) {
				continue;
			}
			// First segment is the authserv-id (optionally followed by a version
			// token). Compare its first whitespace-delimited token to ours.
			$first = preg_split('/\s+/', trim($segments[0]));
			$lineAuthserv = strtolower($first[0] ?? '');
			if ($lineAuthserv !== $authserv_id) {
				continue; // upstream / foreign line — ignore (anti-spoofing)
			}
			$matched = true;

			for ($i = 1; $i < count($segments); $i++) {
				$seg = trim($segments[$i]);
				if ($seg === '') {
					continue;
				}
				if (!preg_match('/^([A-Za-z][A-Za-z0-9-]*)\s*=\s*([A-Za-z0-9]+)/', $seg, $m)) {
					continue;
				}
				$method = strtolower($m[1]);
				$result = strtolower($m[2]);

				switch ($method) {
					case 'spf':
						$spf = self::strongest($spf, $result);
						$d = self::prop($seg, array('smtp.mailfrom', 'smtp.helo'));
						if ($d !== null) { $spf_domain = $d; }
						break;
					case 'dkim':
						$dkim = self::strongest($dkim, $result);
						$d = self::prop($seg, array('header.d', 'header.i'));
						// Keep the domain that accompanies a pass when possible.
						if ($d !== null && ($dkim_domain === null || $result === 'pass')) {
							$dkim_domain = $d;
						}
						break;
					case 'dmarc':
						$dmarc = self::strongest($dmarc, $result);
						break;
					// spf/dkim/dmarc only; other methods (iprev, auth, arc) ignored.
				}
			}
		}

		if (!$matched) {
			return null;
		}

		$obj = new self();
		$obj->spf         = $spf;
		$obj->dkim        = $dkim;
		$obj->dmarc       = $dmarc;
		$obj->dkim_domain = $dkim_domain;
		$obj->spf_domain  = $spf_domain;
		return $obj;
	}

	/**
	 * Resolve two verdict tokens for the same method into the strongest one.
	 * A 'pass' always wins (covers oversigning / multiple DKIM signatures where
	 * one passes); otherwise the first non-null token is kept.
	 */
	private static function strongest(?string $current, string $candidate): string {
		if ($current === null) {
			return $candidate;
		}
		if ($current === 'pass' || $candidate === 'pass') {
			return 'pass';
		}
		return $current;
	}

	/**
	 * Read a property value (e.g. header.d=example.com) from a methodspec segment.
	 * Tries each candidate key in order; returns the first present, lowercased,
	 * with surrounding quotes and a trailing dot stripped.
	 */
	private static function prop(string $segment, array $keys): ?string {
		foreach ($keys as $key) {
			if (preg_match('/\b' . preg_quote($key, '/') . '\s*=\s*"?([^";\s]+)"?/i', $segment, $m)) {
				return strtolower(rtrim(trim($m[1]), '.'));
			}
		}
		return null;
	}

	/**
	 * Split an Authentication-Results value into ;-separated segments. The
	 * authserv-id is segment 0; each methodspec (with its space-separated
	 * properties) is a later segment.
	 */
	private static function splitSegments(string $value): array {
		$parts = explode(';', $value);
		$out = array();
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p !== '') {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Extract all values for a header name from a raw MIME message, unfolding
	 * RFC 5322 continuation lines (a line beginning with whitespace continues the
	 * previous header). Case-insensitive on the header name. Stops at the
	 * header/body boundary (first blank line).
	 *
	 * @return string[] one entry per occurrence of the header
	 */
	private static function extractHeaders(string $raw_message, string $header_name): array {
		$normalized = str_replace("\r\n", "\n", $raw_message);
		$split_pos = strpos($normalized, "\n\n");
		$header_block = ($split_pos !== false) ? substr($normalized, 0, $split_pos) : $normalized;

		$header_name = strtolower($header_name);
		$values = array();
		$collecting = false;
		$current = '';

		foreach (explode("\n", $header_block) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
				// Continuation of the current header.
				if ($collecting) {
					$current .= ' ' . trim($line);
				}
				continue;
			}
			// A new header starts — flush any header we were collecting.
			if ($collecting) {
				$values[] = $current;
				$collecting = false;
				$current = '';
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			if (strtolower(trim(substr($line, 0, $colon))) === $header_name) {
				$collecting = true;
				$current = trim(substr($line, $colon + 1));
			}
		}
		if ($collecting) {
			$values[] = $current;
		}
		return $values;
	}
}
