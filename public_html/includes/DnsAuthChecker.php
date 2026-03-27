<?php
/**
 * DnsAuthChecker - Reusable DNS email authentication checking
 *
 * Provides basic SPF/DKIM/DMARC record checks that can be used from
 * admin settings, test suites, and the comprehensive email_setup_check tool.
 *
 * @version 1.0
 */

class DnsAuthChecker {

	/**
	 * Quick check of SPF/DKIM/DMARC for a domain
	 *
	 * @param string $domain Domain to check
	 * @param array $dkim_selectors DKIM selectors to test (default common ones)
	 * @return array Keys: 'spf', 'dkim', 'dmarc', each with 'status', 'detail', and type-specific data
	 */
	public static function quickCheck($domain, $dkim_selectors = null) {
		if ($dkim_selectors === null) {
			$dkim_selectors = ['mx', 'default', 'mail', 'dkim', 'key1', 'selector1'];
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

		$txtRecords = @dns_get_record($domain, DNS_TXT);

		if (!$txtRecords) {
			$result['detail'] = 'No TXT records found';
			return $result;
		}

		$spfRecord = null;
		$spfCount = 0;

		foreach ($txtRecords as $record) {
			if (isset($record['txt']) && strpos($record['txt'], 'v=spf1') === 0) {
				$spfRecord = $record['txt'];
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
			$selectors = ['mx', 'default', 'mail', 'dkim', 'key1', 'selector1'];
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
			$txtRecords = @dns_get_record($dkimDomain, DNS_TXT);
			if ($txtRecords) {
				foreach ($txtRecords as $record) {
					if (isset($record['txt']) && self::isDKIMRecord($record['txt'])) {
						$result['status'] = 'pass';
						$result['detail'] = 'DKIM found (selector: ' . $selector . ')';
						$result['selector'] = $selector;
						$result['record'] = $record['txt'];
						return $result;
					}
				}
			}

			// Check CNAME records (common for hosted email services)
			$cnameRecords = @dns_get_record($dkimDomain, DNS_CNAME);
			if ($cnameRecords) {
				foreach ($cnameRecords as $record) {
					if (isset($record['target'])) {
						$targetTxtRecords = @dns_get_record($record['target'], DNS_TXT);
						if ($targetTxtRecords) {
							foreach ($targetTxtRecords as $txtRecord) {
								if (isset($txtRecord['txt']) && self::isDKIMRecord($txtRecord['txt'])) {
									$result['status'] = 'pass';
									$result['detail'] = 'DKIM found via CNAME (selector: ' . $selector . ')';
									$result['selector'] = $selector;
									$result['record'] = $txtRecord['txt'];
									return $result;
								}
							}
						}
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
		];

		$dmarcDomain = '_dmarc.' . $domain;
		$txtRecords = @dns_get_record($dmarcDomain, DNS_TXT);

		if (!$txtRecords) {
			$result['detail'] = 'No DMARC record found';
			return $result;
		}

		$dmarcRecord = null;
		foreach ($txtRecords as $record) {
			if (isset($record['txt']) && strpos($record['txt'], 'v=DMARC1') === 0) {
				$dmarcRecord = $record['txt'];
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
}
