<?php
/**
 * JoineryServicesProvider — outbound mail through the operator's account
 * (specs/services_phase2_platform.md §2, §9).
 *
 * A self-hosted site connected to getjoinery sends through an SMTP user cut
 * to its own slice of the operator's SMTP2GO account. The transport is plain
 * SMTP — the enrol answer's send values land in the smtp_* settings through
 * HostedMailSettingsMap, exactly as a Managed node's do — so this provider
 * IS the SMTP provider with one difference: the sending domain is not
 * registered by this site at any provider. The operator registered
 * mail.<host> inside the site's subaccount and answers for it; the records
 * to publish and whether they have verified are what the operator's status
 * call says, kept in services_mail_state.
 *
 * That is what lets every existing mail ceremony work unchanged: the wizard's
 * dns stage publishes the records this reports (DkimRecordSource), the prove
 * stage sends the test through SMTP, and the mail Setup tab's verdicts read
 * the same answers. Choosing any other provider on the Email step is the
 * switch-over; proving the new one releases this (setup_logic).
 *
 * @version 1.0
 */
class JoineryServicesProvider extends SmtpProvider implements DkimRecordSource, SendingDomainRegistrar {

	public static function getKey(): string {
		return ServicesClient::MAIL_SERVICE_KEY;
	}

	public static function getLabel(): string {
		return 'Joinery services (included email)';
	}

	/**
	 * The return path is a CNAME under the operator's sender domain, so SPF
	 * is checked against the provider's own record there; the apex needs no
	 * mechanism of ours.
	 */
	public static function getSpfMechanism(string $domain): string {
		return '';
	}

	public static function validateConfiguration(): array {
		$errors = array();
		if (!ServicesClient::connected()) {
			$errors[] = 'This site is not connected to a getjoinery account';
		} elseif (!ServicesClient::mailEnrolled()) {
			$state = ServicesClient::mailState();
			$errors[] = !empty($state['notice']) ? (string)$state['notice']
				: 'Outbound email through getjoinery is not set up on this site yet';
		} elseif (trim((string)Globalvars::get_instance()->get_setting('smtp_host')) === '') {
			$errors[] = 'The send settings from getjoinery are missing';
		}
		return array('valid' => empty($errors), 'errors' => $errors);
	}

	// ── The sending domain, as the operator reports it ───────────────────────

	/**
	 * "Register" is the enrol call: the operator creates the sender domain
	 * mail.<host> inside this site's subaccount and answers the send values
	 * and the records. Idempotent. A site that is not entitled gets the
	 * operator's sentence and nothing is written.
	 */
	public static function createSendingDomain(string $domain): array {
		if (!ServicesClient::connected()) {
			return array('status' => 'error', 'error' => 'Connect your getjoinery account first.');
		}
		try {
			$answer = (new ServicesClient())->enrolMail();
		} catch (ServiceClientException $e) {
			return array('status' => 'unreachable', 'error' => $e->getMessage());
		}
		if (empty($answer['entitled'])) {
			$why = trim((string)($answer['notice'] ?? ''));
			if ($why === '') {
				$why = 'This site is not entitled to getjoinery\'s email service yet.';
			}
			$manage = trim((string)($answer['manage_url'] ?? ''));
			return array('status' => 'error', 'error' => $why
				. ($manage !== '' ? ' See ' . $manage . '.' : ''));
		}
		try {
			ServicesClient::applyMailEnrolment($answer);
		} catch (\Throwable $e) {
			return array('status' => 'error', 'error' => 'The send settings could not be written: ' . $e->getMessage());
		}
		return array('status' => 'ok');
	}

	/**
	 * The provider's state word for the site's sending domain: 'active' once
	 * the operator reports it verified, 'unverified' while the records pend,
	 * 'not_registered' before enrol. Never '' — the answer is on this site.
	 */
	public static function getSendingDomainState(string $domain): string {
		$state = ServicesClient::mailState();
		$sender = (string)($state['domain'] ?? '');
		if ($sender === '' || !ServicesClient::mailEnrolled()) {
			return 'not_registered';
		}
		return ($state['domain_state'] ?? '') === 'domain_verified' ? 'active' : 'unverified';
	}

	public static function getSendingDomainError(string $domain): string {
		return '';
	}

	/** Ask the operator now; it asks its provider once and records the answer. */
	public static function verifySendingDomain(string $domain): string {
		if (ServicesClient::connected()) {
			try {
				(new ServicesClient())->status();
			} catch (\Throwable $e) {
				error_log('[JoineryServicesProvider] status refresh failed: ' . $e->getMessage());
			}
		}
		return self::getSendingDomainState($domain);
	}

	/** The records the operator's provider asked for, in the DkimRecordSource shape. */
	public static function getDkimStatus(string $domain): array {
		$state = self::getSendingDomainState($domain);
		if ($state === 'not_registered') {
			return array('status' => 'not_registered', 'records' => array());
		}
		$records = array();
		foreach ((array)(ServicesClient::mailState()['records'] ?? array()) as $record) {
			if (!is_array($record)) { continue; }
			$name = trim((string)($record['name'] ?? ''));
			$value = trim((string)($record['value'] ?? ''));
			if ($name === '' || $value === '') { continue; }
			$records[] = array(
				'type'    => strtoupper(trim((string)($record['type'] ?? 'CNAME'))),
				'name'    => $name,
				'value'   => $value,
				'purpose' => (string)($record['purpose'] ?? 'DKIM'),
			);
		}
		return array('status' => 'ok', 'records' => $records);
	}
}
