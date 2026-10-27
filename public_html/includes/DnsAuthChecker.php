<?php
/**
 * DnsAuthChecker - Reusable DNS email authentication checking
 *
 * Provides basic SPF/DKIM/DMARC record checks that can be used from
 * admin settings, test suites, and the comprehensive email_setup_check tool.
 *
 * Lookups go through DnsResolver — the single raw-DNS chokepoint — so this
 * class is fully unit-testable via DnsResolver::setBackend().
 *
 * @version 1.2 - assess(): the sending service's own key names, the domain it signs as, whether that
 *   matches the From domain, and the plain-language explanation every page shows
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class DnsAuthChecker {

	/** DKIM key names tried when the sending service cannot say which it uses. */
	const COMMON_KEY_NAMES = array('mx', 'default', 'mail', 'dkim', 'key1', 'selector1');

	/**
	 * Quick check of SPF/DKIM/DMARC for a domain
	 *
	 * @param string $domain Domain to check
	 * @param array $dkim_selectors DKIM selectors to test (default common ones)
	 * @return array Keys: 'spf', 'dkim', 'dmarc', each with 'status', 'detail', and type-specific data
	 */
	public static function quickCheck($domain, $dkim_selectors = null) {
		if ($dkim_selectors === null) {
			$dkim_selectors = self::COMMON_KEY_NAMES;
		}

		return [
			'spf' => self::checkSPF($domain),
			'dkim' => self::checkDKIM($domain, $dkim_selectors),
			'dmarc' => self::checkDMARC($domain),
		];
	}

	/**
	 * Check SPF record for a domain
	 *
	 * @param string $domain
	 * @return array 'status' => pass|warn|fail, 'detail', 'record'
	 */
	public static function checkSPF($domain) {
		$result = [
			'status' => 'fail',
			'detail' => '',
			'record' => '',
		];

		try {
			$txtRecords = DnsResolver::getTxt($domain);
		} catch (DnsLookupException $e) {
			$txtRecords = []; // fail-open: a resolver failure reads as "no record"
		}

		if (empty($txtRecords)) {
			$result['detail'] = 'No TXT records found';
			return $result;
		}

		$spfRecord = null;
		$spfCount = 0;

		foreach ($txtRecords as $txt) {
			if (strpos($txt, 'v=spf1') === 0) {
				$spfRecord = $txt;
				$spfCount++;
			}
		}

		if (!$spfRecord) {
			$result['detail'] = 'No SPF record found';
			return $result;
		}

		$result['record'] = $spfRecord;

		if ($spfCount > 1) {
			$result['status'] = 'warn';
			$result['detail'] = 'Multiple SPF records found (only one allowed)';
			return $result;
		}

		// Check for weak policies
		if (strpos($spfRecord, '+all') !== false) {
			$result['status'] = 'warn';
			$result['detail'] = 'SPF allows all senders (+all)';
			return $result;
		}

		if (strpos($spfRecord, '?all') !== false) {
			$result['status'] = 'warn';
			$result['detail'] = 'SPF neutral policy (?all)';
			return $result;
		}

		$result['status'] = 'pass';
		if (strpos($spfRecord, '-all') !== false) {
			$result['detail'] = 'SPF record found (hard fail)';
		} elseif (strpos($spfRecord, '~all') !== false) {
			$result['detail'] = 'SPF record found (soft fail)';
		} else {
			$result['detail'] = 'SPF record found';
		}

		return $result;
	}

	/**
	 * Check DKIM records for a domain by testing common selectors
	 *
	 * @param string $domain
	 * @param array $selectors Selectors to check
	 * @return array 'status' => pass|fail, 'detail', 'selector', 'record'
	 */
	public static function checkDKIM($domain, $selectors = null) {
		if ($selectors === null) {
			$selectors = self::COMMON_KEY_NAMES;
		}

		$result = [
			'status' => 'fail',
			'detail' => '',
			'selector' => '',
			'record' => '',
			'selectors_checked' => $selectors,
		];

		foreach ($selectors as $selector) {
			$dkimDomain = $selector . '._domainkey.' . $domain;

			// Check TXT records
			try {
				$txtRecords = DnsResolver::getTxt($dkimDomain);
			} catch (DnsLookupException $e) {
				$txtRecords = [];
			}
			foreach ($txtRecords as $txt) {
				if (self::isDKIMRecord($txt)) {
					$result['status'] = 'pass';
					$result['detail'] = 'DKIM found (selector: ' . $selector . ')';
					$result['selector'] = $selector;
					$result['record'] = $txt;
					return $result;
				}
			}

			// Check CNAME records (common for hosted email services)
			try {
				$cnameTarget = DnsResolver::getCname($dkimDomain);
			} catch (DnsLookupException $e) {
				$cnameTarget = null;
			}
			if ($cnameTarget) {
				try {
					$targetTxtRecords = DnsResolver::getTxt($cnameTarget);
				} catch (DnsLookupException $e) {
					$targetTxtRecords = [];
				}
				foreach ($targetTxtRecords as $txt) {
					if (self::isDKIMRecord($txt)) {
						$result['status'] = 'pass';
						$result['detail'] = 'DKIM found via CNAME (selector: ' . $selector . ')';
						$result['selector'] = $selector;
						$result['record'] = $txt;
						return $result;
					}
				}
			}
		}

		$result['detail'] = 'No DKIM records found (checked ' . count($selectors) . ' selectors)';
		return $result;
	}

	/**
	 * Check DMARC record for a domain
	 *
	 * @param string $domain
	 * @return array 'status' => pass|warn|fail, 'detail', 'record', 'policy'
	 */
	public static function checkDMARC($domain) {
		$result = [
			'status' => 'fail',
			'detail' => '',
			'record' => '',
			'policy' => '',
			'adkim'  => 'r',
		];

		$dmarcDomain = '_dmarc.' . $domain;
		try {
			$txtRecords = DnsResolver::getTxt($dmarcDomain);
		} catch (DnsLookupException $e) {
			$txtRecords = []; // fail-open: a resolver failure reads as "no record"
		}

		if (empty($txtRecords)) {
			$result['detail'] = 'No DMARC record found';
			return $result;
		}

		$dmarcRecord = null;
		foreach ($txtRecords as $txt) {
			if (strpos($txt, 'v=DMARC1') === 0) {
				$dmarcRecord = $txt;
				break;
			}
		}

		if (!$dmarcRecord) {
			$result['detail'] = 'No valid DMARC record found';
			return $result;
		}

		$result['record'] = $dmarcRecord;

		// Extract policy
		$policy = 'none';
		if (preg_match('/p=([^;]+)/', $dmarcRecord, $matches)) {
			$policy = trim($matches[1]);
		}
		$result['policy'] = $policy;
		// How closely the signing domain must match the From domain: s(trict),
		// the same domain; r(elaxed, the default), the same organisation.
		if (preg_match('/(?:^|;)\s*adkim\s*=\s*([rs])/i', $dmarcRecord, $matches)) {
			$result['adkim'] = strtolower($matches[1]);
		}

		if ($policy === 'none') {
			$result['status'] = 'warn';
			$result['detail'] = 'DMARC policy: none (monitoring only)';
		} elseif ($policy === 'quarantine') {
			$result['status'] = 'pass';
			$result['detail'] = 'DMARC policy: quarantine';
		} elseif ($policy === 'reject') {
			$result['status'] = 'pass';
			$result['detail'] = 'DMARC policy: reject';
		} else {
			$result['status'] = 'warn';
			$result['detail'] = 'DMARC policy: ' . $policy . ' (unknown)';
		}

		return $result;
	}

	/**
	 * Check if a DNS TXT record looks like a DKIM record
	 *
	 * @param string $record
	 * @return bool
	 */
	public static function isDKIMRecord($record) {
		$record_lower = strtolower($record);

		if (strpos($record_lower, 'v=dkim1') !== false) {
			return true;
		}

		if (strpos($record_lower, 'k=rsa') !== false || strpos($record_lower, 'k=ed25519') !== false) {
			return true;
		}

		// Public key presence (p= tag with substantial content)
		if (preg_match('/p=([a-zA-Z0-9+\/=]{100,})/', $record)) {
			return true;
		}

		return false;
	}

	// ── One site's mail, explained ──────────────────────────────────────

	/**
	 * What one domain's mail looks like to other servers, for the service
	 * that sends it: the DNS checks, run with the service's own DKIM key names
	 * first (no list of common names finds SMTP2GO's s<id> or Mailgun's own
	 * selector); the domain the service signs as; and explain()'s reading of
	 * it all. $service_key is an email_service value; '' checks DNS alone.
	 *
	 * @return array{domain:string, results:array, service_label:string,
	 *               service_key_names:string[], signing_domain:?string, explanation:array}
	 */
	public static function assess(string $domain, string $service_key = ''): array {
		$domain = strtolower(trim($domain));
		$class = $service_key !== '' ? (EmailSender::getDiscoveredProviders()[$service_key] ?? null) : null;
		$label = $class ? (string)$class::getLabel() : '';
		$signing = null;
		if ($class && is_callable(array($class, 'dkimSigningDomain'))) {
			try {
				$signing = $class::dkimSigningDomain($domain);
			} catch (Throwable $e) {
				error_log('DnsAuthChecker: ' . $class . '::dkimSigningDomain(' . $domain . ') failed: ' . $e->getMessage());
			}
		}
		// The key lives under the domain the mail is signed as, which is not
		// always the From domain (Mailgun through mail.<domain>, B169).
		$key_domain = $signing ?? $domain;
		$key_names = $class ? self::serviceKeyNames($class, $key_domain) : array();
		$names = array_values(array_unique(array_merge($key_names, self::COMMON_KEY_NAMES)));
		$results = self::quickCheck($domain, $names);
		if ($key_domain !== $domain) {
			$results['dkim'] = self::checkDKIM($key_domain, $names);
		}
		$results['dkim']['domain'] = $key_domain;
		return array(
			'domain'            => $domain,
			'results'           => $results,
			'service_label'     => $label,
			'service_key_names' => $key_names,
			'signing_domain'    => $signing,
			'explanation'       => self::explain($results, $domain, $label, $key_names, $signing),
		);
	}

	/** The DKIM key names the service reports for $domain (its getDkimStatus records). */
	public static function serviceKeyNames(string $provider_class, string $domain): array {
		if (!is_callable(array($provider_class, 'getDkimStatus'))) {
			return array();
		}
		$names = array();
		try {
			foreach ($provider_class::getDkimStatus($domain)['records'] ?? array() as $record) {
				$at = strpos((string)($record['name'] ?? ''), '._domainkey.');
				if ($at) {
					$names[] = substr($record['name'], 0, $at);
				}
			}
		} catch (Throwable $e) {
			error_log('DnsAuthChecker: ' . $provider_class . '::getDkimStatus(' . $domain . ') failed: ' . $e->getMessage());
		}
		return array_values(array_unique($names));
	}

	/**
	 * Whether a signature by $signing_domain counts for mail From $from_domain
	 * (DMARC alignment). Strict: the same domain. Relaxed: the same
	 * organisational domain, approximated as the last two labels, or three
	 * under a two-letter country code with a common second level (co.uk,
	 * com.au); no public suffix list is shipped.
	 */
	public static function signaturesMatch(string $signing_domain, string $from_domain, string $adkim): bool {
		$signing_domain = strtolower(rtrim($signing_domain, '.'));
		$from_domain = strtolower(rtrim($from_domain, '.'));
		if ($signing_domain === $from_domain) {
			return true;
		}
		return $adkim !== 's' && self::organisationalDomain($signing_domain) === self::organisationalDomain($from_domain);
	}

	/** See signaturesMatch(): the approximate organisational domain. */
	public static function organisationalDomain(string $domain): string {
		$labels = explode('.', strtolower(rtrim($domain, '.')));
		$n = count($labels);
		$take = ($n >= 3 && strlen($labels[$n - 1]) === 2
			&& in_array($labels[$n - 2], array('co', 'com', 'net', 'org', 'gov', 'ac', 'edu'), true)) ? 3 : 2;
		return implode('.', array_slice($labels, -min($take, $n)));
	}

	/**
	 * The checks in words a site owner can act on. Plain text (callers
	 * escape it); each row keeps its protocol name and raw record for the
	 * reader who needs them.
	 *
	 * @return array{verdict:array{tone:string, text:string},
	 *               rows:array<int, array{title:string, tech:string, tone:string, text:string, raw:string}>}
	 *         tone is 'success' | 'warning' | 'danger'.
	 */
	public static function explain(array $results, string $domain, string $service_label = '',
			array $service_key_names = array(), ?string $signing_domain = null): array {
		$spf = $results['spf'];
		$dkim = $results['dkim'];
		$dmarc = $results['dmarc'];
		$service = $service_label !== '' ? $service_label : 'your email service';
		$enforcing = in_array($dmarc['policy'] ?? '', array('quarantine', 'reject'), true);
		$adkim = $dmarc['adkim'] ?? 'r';
		$match = $signing_domain === null ? null : self::signaturesMatch($signing_domain, $domain, $adkim);

		$rows = array();
		$signed_by_service = $dkim['status'] === 'pass' && in_array($dkim['selector'], $service_key_names, true);
		$rows[] = array(
			'title' => 'Signature', 'tech' => 'DKIM',
			'tone'  => $dkim['status'] === 'pass' ? 'success' : ($service_label === '' ? 'warning' : 'danger'),
			'text'  => $dkim['status'] === 'pass'
				? ($signed_by_service
					? 'Your mail is signed by ' . $service . ', so other servers can confirm it really came from you.'
					: 'A signing key is published for ' . (($dkim['domain'] ?? $domain) === $domain ? 'this domain'
						: $dkim['domain'] . ', the domain your mail is signed as') . ', so other servers can confirm mail signed with it came from you.')
				: ($service_label === ''
					? 'No signing key found under the usual names. The service that sends this domain\'s mail may use a name of its own; checking from the site that sends it asks that service.'
					: 'No signing key found for this domain. ' . $service . ' lists the record to add when you set up your sending domain there.'),
			'raw'   => $dkim['selector'] !== ''
				? 'key ' . $dkim['selector'] . '._domainkey.' . ($dkim['domain'] ?? $domain)
				: 'checked ' . count($dkim['selectors_checked'] ?? array()) . ' key names',
		);

		if ($match !== null) {
			if ($match) {
				$text = 'Your mail is signed as ' . $signing_domain . ', which matches your address at ' . $domain
					. ', so other servers count the signature as yours.';
				$tone = 'success';
			} elseif ($enforcing) {
				$text = 'Your mail is signed as ' . $signing_domain . ', but your address is at ' . $domain
					. '. Your forgery policy requires them to match' . ($adkim === 's' ? ' exactly' : '')
					. ', so other servers treat your mail as a forgery and put it in spam. Set up ' . $domain
					. ' itself as the sending domain with ' . $service . '.';
				$tone = 'danger';
			} else {
				$text = 'Your mail is signed as ' . $signing_domain . ', but your address is at ' . $domain
					. ', so the signature does not vouch for your address. Mail still arrives today, but a stricter forgery policy would send it to spam. Set up '
					. $domain . ' itself as the sending domain with ' . $service . '.';
				$tone = 'warning';
			}
			$rows[] = array('title' => 'Matches your address', 'tech' => 'DMARC alignment', 'tone' => $tone, 'text' => $text,
				'raw' => 'DKIM d=' . $signing_domain . ', From ' . $domain . ', adkim=' . $adkim);
		}

		$record = (string)($spf['record'] ?? '');
		$rule = strpos($record, '-all') !== false ? ', and asks other servers to refuse mail from anywhere else'
			: (strpos($record, '~all') !== false ? ', and asks other servers to treat mail from anywhere else with suspicion' : '');
		if ($spf['status'] === 'pass') {
			$text = 'Your domain lists the servers allowed to send its mail' . $rule . '.';
		} elseif ($spf['detail'] === 'Multiple SPF records found (only one allowed)') {
			$text = 'Your domain has more than one approved-senders list, and other servers ignore both. Merge them into one.';
		} elseif ($spf['status'] === 'warn') {
			$text = "Your domain's approved-senders list lets any server send as you, so it protects nothing.";
		} else {
			$text = 'Your domain publishes no approved-senders list. Signed mail is still trusted, but publishing one is recommended.';
		}
		$rows[] = array('title' => 'Approved senders', 'tech' => 'SPF',
			'tone' => $spf['status'] === 'pass' ? 'success' : ($spf['status'] === 'warn' ? 'warning' : 'danger'),
			'text' => $text, 'raw' => $record !== '' ? $record : (string)$spf['detail']);

		$policy_text = array(
			'reject'     => 'Other servers are told to refuse mail that claims to be from you but fails these checks.',
			'quarantine' => 'Other servers are told to put mail that claims to be from you but fails these checks in spam.',
			'none'       => 'Other servers are told to deliver mail that fails these checks anyway. Fine while setting up; stricter protects you from forgeries.',
		);
		$rows[] = array('title' => 'Forgery policy', 'tech' => 'DMARC',
			'tone' => $dmarc['status'] === 'pass' ? 'success' : ($dmarc['status'] === 'warn' ? 'warning' : 'danger'),
			'text' => $policy_text[$dmarc['policy'] ?? ''] ?? ($dmarc['status'] === 'fail'
				? 'Your domain gives no instructions for mail that pretends to be from you, so each server decides for itself.'
				: "Your domain's forgery policy is not one other servers recognise."),
			'raw' => (string)(($dmarc['record'] ?? '') !== '' ? $dmarc['record'] : $dmarc['detail']));

		if ($dkim['status'] !== 'pass' && $service_label === '') {
			$verdict = array('tone' => 'warning', 'text' => 'Could not tell. No signing key was found under the usual names, and no sending service was asked for its own.');
		} elseif ($dkim['status'] !== 'pass') {
			$verdict = array('tone' => 'danger', 'text' => 'Not yet. Your mail is not signed with a key other servers can find, so it is likely to land in spam.');
		} elseif ($match === false && $enforcing) {
			$verdict = array('tone' => 'danger', 'text' => 'No. Your mail is signed as a different domain than your address, and your forgery policy sends such mail to spam.');
		} elseif ($spf['status'] === 'pass' && $dmarc['status'] === 'pass' && $match !== false) {
			$verdict = array('tone' => 'success', 'text' => 'Yes. Your mail is signed, and your domain tells other servers what to do with mail that only pretends to be from you.');
		} else {
			$verdict = array('tone' => 'warning', 'text' => 'Mostly. Your mail is signed; one thing below could be stronger.');
		}

		return array('verdict' => $verdict, 'rows' => $rows);
	}

	/**
	 * explain()'s result as HTML: the verdict, then each row in words with its
	 * protocol name and raw record in small print. One wrapper div, because
	 * the admin theme lays an alert's direct children out in a row.
	 */
	public static function explanationHtml(array $explanation): string {
		$marks = array('success' => '&#10003;', 'warning' => '&#9888;', 'danger' => '&#10007;');
		$html = '<div><p class="text-' . $explanation['verdict']['tone'] . '"><strong>'
			. $marks[$explanation['verdict']['tone']] . ' ' . htmlspecialchars($explanation['verdict']['text']) . '</strong></p>';
		foreach ($explanation['rows'] as $row) {
			$html .= '<div class="mb-2"><span class="text-' . $row['tone'] . '">' . $marks[$row['tone']] . '</span> <strong>'
				. htmlspecialchars($row['title']) . ':</strong> ' . htmlspecialchars($row['text'])
				. '<br><small class="text-muted">' . htmlspecialchars($row['tech'] . ': ' . $row['raw']) . '</small></div>';
		}
		return $html . '</div>';
	}
}
