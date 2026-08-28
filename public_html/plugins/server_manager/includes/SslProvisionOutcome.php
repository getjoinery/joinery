<?php
/**
 * SslProvisionOutcome — what a provision_certificate job actually did.
 *
 * THE EXIT CODE DOES NOT ANSWER THIS. setup_ssl.sh wraps install.sh's
 * provision_origin_cert, which ends `return 0` on every branch by design: a
 * site that cannot get a certificate stays on HTTP rather than failing its
 * install. Only a broken Apache config makes the script exit non-zero. So a
 * completed job means "the script ran", not "the node has a certificate", and
 * anything that treats completion as success will mark SSL active on a node
 * holding nothing.
 *
 * The record is the output, and it distinguishes states that matter very
 * differently to whoever has to fix them:
 *
 *   - a certificate was issued (HTTP-01, or DNS-01 after HTTP-01 was ruled out)
 *   - the domain needs DNS-01 and the provider credentials are not on the node
 *     — one file away from working, and the script names the exact path
 *   - no challenge path exists at all — the domain resolves somewhere this
 *     node cannot prove it owns
 *   - a challenge was attempted and the CA refused it
 *
 * The middle two both print "No origin cert issued" and both exit 0, and
 * collapsing them into one "failed" is how a five-minute fix becomes an
 * afternoon. They are separated here so a dashboard can say which one it is.
 *
 * @version 1.0
 */
class SslProvisionOutcome {

	/** A certificate is on the node. The only state that means SSL is live. */
	const ISSUED = 'issued';

	/** DNS-01 is the only viable path and /etc/letsencrypt/{provider}.ini is missing. */
	const NO_DNS_CREDENTIALS = 'no_dns_credentials';

	/** Neither HTTP-01 nor a recognised DNS provider applies to this domain. */
	const NO_CHALLENGE_PATH = 'no_challenge_path';

	/** A challenge was attempted and did not validate. */
	const CHALLENGE_FAILED = 'challenge_failed';

	/** The script's one genuine failure: it would not leave Apache broken. */
	const APACHE_CONFIG_BROKEN = 'apache_config_broken';

	/** Nothing recognisable came back. Never treated as success. */
	const UNREADABLE = 'unreadable';

	/** The challenge that answered, on an ISSUED result. */
	const CHALLENGE_HTTP_01 = 'http-01';
	const CHALLENGE_DNS_01  = 'dns-01';

	/**
	 * Classify a finished provision_certificate job.
	 *
	 * @param string $job_output The job's mjb_output verbatim.
	 * @return array{state:string,challenge:string,detail:string} state is one of
	 *         the constants above; detail is a sentence naming what to do about
	 *         it; challenge names which challenge answered, and is '' unless a
	 *         certificate was issued. Always present, so no caller has to guess
	 *         whether the key is there.
	 */
	public static function classify($job_output) {
		return self::classify_state($job_output) + ['challenge' => ''];
	}

	private static function classify_state($job_output) {
		$text = self::script_text((string)$job_output);

		// Order is precedence, not convenience. A run that prints "HTTP-01
		// failed; trying DNS-01" and then succeeds via DNS-01 has issued a
		// certificate, so the success line is asked about first — reading the
		// warning first would report a working node as broken.
		if (strpos($text, 'Issued LE certificate for') !== false) {
			// Which challenge answered matters beyond curiosity: HTTP-01 can
			// only have succeeded if the domain resolves to this box, which is
			// the precondition a reverse-DNS grant is checked against. DNS-01
			// proves ownership of the zone and nothing about where the name
			// points, so a certificate issued that way is not evidence for it.
			$challenge = (strpos($text, '(HTTP-01)') !== false) ? self::CHALLENGE_HTTP_01
				: ((strpos($text, '(DNS-01') !== false) ? self::CHALLENGE_DNS_01 : '');
			return [
				'state'     => self::ISSUED,
				'challenge' => $challenge,
				'detail'    => 'A Let\'s Encrypt certificate was issued and Apache was reloaded.',
			];
		}

		// Checked before the generic "No origin cert issued", which this case
		// also prints. This is the actionable one: the script has already
		// worked out the provider and the exact path it wants the file at.
		if (strpos($text, 'Drop credentials at') !== false) {
			$cred = self::matched('/Drop credentials at (\S+)/', $text);
			return [
				'state'  => self::NO_DNS_CREDENTIALS,
				'detail' => 'No certificate was issued: this domain needs a DNS-01 challenge and the provider '
				          . 'credentials are not on the node. Put them at '
				          . ($cred !== '' ? $cred : '/etc/letsencrypt/{provider}.ini')
				          . ' and provision again.',
			];
		}

		if (strpos($text, 'no LE challenge path available') !== false) {
			return [
				'state'  => self::NO_CHALLENGE_PATH,
				'detail' => 'No certificate was issued: the domain does not resolve to this node and its '
				          . 'nameservers are not a DNS provider this node can answer a challenge through. '
				          . 'Point the domain here, or use a supported DNS provider.',
			];
		}

		if (strpos($text, 'DNS-01 via') !== false && strpos($text, 'failed') !== false) {
			return [
				'state'  => self::CHALLENGE_FAILED,
				'detail' => 'A DNS-01 challenge was attempted and did not validate. The credentials on the '
				          . 'node may be wrong or lack permission on the zone.',
			];
		}

		if (strpos($text, 'HTTP-01 failed') !== false) {
			return [
				'state'  => self::CHALLENGE_FAILED,
				'detail' => 'An HTTP-01 challenge was attempted and did not validate, and no DNS-01 fallback '
				          . 'was available.',
			];
		}

		if (strpos($text, 'configtest failed') !== false) {
			return [
				'state'  => self::APACHE_CONFIG_BROKEN,
				'detail' => 'Apache would not accept its configuration afterwards, so it was not reloaded. '
				          . 'Review the vhost on the node before provisioning again.',
			];
		}

		return [
			'state'  => self::UNREADABLE,
			'detail' => 'The certificate job finished but said nothing this plane recognises. Read the job '
			          . 'output on the node detail page before assuming either way.',
		];
	}

	/** Only one state means the node actually holds a certificate. */
	public static function is_issued($state) {
		return $state === self::ISSUED;
	}

	/**
	 * Is this worth waking a person for, or will retrying fix it?
	 *
	 * Missing credentials and a missing challenge path are both "a human must
	 * change something outside this node"; retrying either on a timer produces
	 * an identical result forever. A failed challenge can be transient (a zone
	 * mid-propagation, a rate limit), so it stays on the retry path.
	 */
	public static function needs_operator($state) {
		return in_array($state, [self::NO_DNS_CREDENTIALS, self::NO_CHALLENGE_PATH, self::APACHE_CONFIG_BROKEN], true);
	}

	/**
	 * Dig the script's own text out of a primitive result envelope.
	 *
	 * A script primitive comes back as {"api_version":..,"data":{"output":..}}
	 * followed by the agent log. Matching against the raw envelope would work
	 * for these particular phrases and break the first time one gained a
	 * character JSON escapes, so the envelope is opened properly and the raw
	 * string is only a fallback for a shape this does not recognise.
	 */
	private static function script_text($job_output) {
		$first_line = strtok($job_output, "\n");
		if ($first_line !== false) {
			$decoded = json_decode($first_line, true);
			if (is_array($decoded) && isset($decoded['data']['output'])) {
				$job_output = (string)$decoded['data']['output'];
			}
		}
		// The script colours its own output; the codes sit between the line
		// prefix and the message and would otherwise land inside a captured path.
		return (string)preg_replace('/\033\[[0-9;]*m/', '', $job_output);
	}

	private static function matched($pattern, $text) {
		return preg_match($pattern, $text, $m) ? trim($m[1], " \t.,") : '';
	}
}
?>
