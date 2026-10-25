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
 *   - a certificate was issued (HTTP-01, or DNS-01 after HTTP-01 was ruled out),
 *     or one already there was valid and kept
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
 * A run that issued nothing usually arms the node's retry timer, which asks
 * every five minutes whether the name reaches the node and issues the moment
 * it does: that is a wait, not a fault. A timer that could not be armed, or an
 * edge on Full (Strict), is a fault nothing on the node will clear.
 *
 * @version 1.3 - a refused HTTP-01 is a failed challenge before it is a wait, with the credentials path when one was
 *                printed; an edge on Full (Strict) says when a DNS-01 attempt failed too (reviewer2 on B111)
 * @version 1.2 - reads the words the scripts print today (B111): "drop credentials at" in any case, and
 *                "no DNS-01 provider was detected" beside the older "no LE challenge path available", so a
 *                no-certificate run is no longer unreadable; a run that armed the retry timer is a wait
 *                (WAITING_FOR_NAME), one whose timer could not be armed (RETRY_NOT_ARMED) and an edge on
 *                Full (Strict) (EDGE_STRICT) need a person. PHRASES is held to the scripts by a test.
 * @version 1.1
 */
class SslProvisionOutcome {

	/**
	 * What the certificate scripts (install.sh's provision_origin_cert, setup_ssl.sh) print, as matched
	 * below. ssl_primitive_chain_test holds each to the scripts' source, so a reworded line fails a test
	 * instead of turning every result unreadable. Matched case-insensitively.
	 */
	const PHRASES = [
		'issued'        => 'Issued LE certificate for',
		'kept'          => 'Kept the LE certificate for',
		'not_issued'    => 'No origin cert issued',
		'retry_armed'   => 'A retry timer asks every five minutes',
		'retry_unarmed' => 'No retry timer could be armed',
		'edge_strict'   => 'Set the edge to Full',
		'credentials'   => 'drop credentials at',
		'no_provider'   => 'no DNS-01 provider was detected',
		'dns_attempt'   => 'DNS-01 via',
		'http_failed'   => 'HTTP-01 failed',
		'configtest'    => 'configtest failed',
	];

	/** How a node not yet on this release says there is no challenge path. */
	const LEGACY_NO_PATH = 'no LE challenge path available';

	/** A certificate is on the node. The only state that means SSL is live. */
	const ISSUED = 'issued';

	/** No certificate yet, and the node's retry timer issues one once the name reaches it. */
	const WAITING_FOR_NAME = 'waiting_for_name';

	/** No certificate, and the retry timer that would issue one could not be armed. */
	const RETRY_NOT_ARMED = 'retry_not_armed';

	/** The edge in front of the domain is on Full (Strict) and refuses the placeholder certificate. */
	const EDGE_STRICT = 'edge_strict';

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
		// A certificate certbot found valid and not yet due is kept, not issued
		// again (one a copy carried in from its old server, or a run again on a
		// node that has one): a certificate is on the node all the same.
		$kept = self::says($text, 'kept');
		if ($kept || self::says($text, 'issued')) {
			// Which challenge answered matters beyond curiosity: HTTP-01 can
			// only have succeeded if the domain resolves to this box, which is
			// the precondition a reverse-DNS grant is checked against. DNS-01
			// proves ownership of the zone and nothing about where the name
			// points, so a certificate issued that way is not evidence for it.
			// A kept certificate names the challenge that renews it, and the
			// script chose HTTP-01 only after its own probe of the name came
			// back from this box (name_reaches_here), the same evidence.
			$challenge = (strpos($text, '(HTTP-01)') !== false) ? self::CHALLENGE_HTTP_01
				: ((strpos($text, '(DNS-01') !== false) ? self::CHALLENGE_DNS_01 : '');
			return [
				'state'     => self::ISSUED,
				'challenge' => $challenge,
				'detail'    => $kept
					? 'The Let\'s Encrypt certificate already on the node is valid and not yet due for renewal, so it was kept; Apache was reloaded.'
					: 'A Let\'s Encrypt certificate was issued and Apache was reloaded.',
			];
		}

		// Nothing on the node clears these two: no timer is asking again, or
		// the edge refuses every answer this node can give until a person
		// changes it.
		if (self::says($text, 'retry_unarmed')) {
			return [
				'state'  => self::RETRY_NOT_ARMED,
				'detail' => 'No certificate was issued, and the retry timer that would issue one once the domain '
				          . 'reaches the node could not be armed. Run setup_ssl.sh for the domain on the node (on its '
				          . 'host, for a site in a container) once the domain reaches it.',
			];
		}
		$dns_failed = self::says($text, 'dns_attempt') && strpos($text, 'failed') !== false;
		$cred = self::matched('/drop credentials at (\S+)/i', $text);
		if (self::says($text, 'edge_strict')) {
			return [
				'state'  => self::EDGE_STRICT,
				'detail' => 'No certificate was issued: the edge in front of the domain is on Full (Strict) and '
				          . 'refuses the placeholder certificate the node answers with until it has a real one. Set '
				          . 'the edge to Full; the node\'s retry timer then issues the certificate, and Full (Strict) '
				          . 'works after that.'
				          . ($dns_failed ? ' A DNS-01 challenge through the provider was tried as well and did not validate.' : ''),
			];
		}

		// A challenge was attempted and refused: the name reached the node (or
		// the zone answered), so it is not waiting for the name.
		if ($dns_failed) {
			return [
				'state'  => self::CHALLENGE_FAILED,
				'detail' => 'A DNS-01 challenge was attempted and did not validate. The credentials on the '
				          . 'node may be wrong or lack permission on the zone.',
			];
		}
		if (self::says($text, 'http_failed')) {
			return [
				'state'  => self::CHALLENGE_FAILED,
				'detail' => 'An HTTP-01 challenge was attempted and did not validate, and no DNS-01 fallback '
				          . 'was available.'
				          . ($cred !== '' ? ' To use DNS-01 instead, put the provider credentials at ' . $cred
				          . ' and provision again.' : ''),
			];
		}

		// The script has already worked out the provider and the exact path it
		// wants credentials at, whenever it found one.
		if (self::says($text, 'not_issued')) {
			if (self::says($text, 'retry_armed')) {
				return [
					'state'  => self::WAITING_FOR_NAME,
					'detail' => 'No certificate yet: the node\'s retry timer asks every five minutes whether the '
					          . 'domain reaches it, and issues one when it does.'
					          . ($cred !== '' ? ' To issue one now through DNS-01 instead, put the provider '
					          . 'credentials at ' . $cred . ' and provision again.' : ''),
				];
			}
			if ($cred !== '') {
				return [
					'state'  => self::NO_DNS_CREDENTIALS,
					'detail' => 'No certificate was issued: this domain needs a DNS-01 challenge and the provider '
					          . 'credentials are not on the node. Put them at ' . $cred . ' and provision again.',
				];
			}
			if (self::says($text, 'no_provider') || stripos($text, self::LEGACY_NO_PATH) !== false) {
				return [
					'state'  => self::NO_CHALLENGE_PATH,
					'detail' => 'No certificate was issued: the domain does not reach this node and its '
					          . 'nameservers are not a DNS provider this node can answer a challenge through. '
					          . 'Point the domain here, or use a supported DNS provider.',
				];
			}
		}

		if (self::says($text, 'configtest')) {
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
	 * Missing credentials and a missing challenge path with no retry timer, a
	 * timer that could not be armed, and an edge on Full (Strict) are all "a
	 * human must change something outside this node"; retrying any of them
	 * produces an identical result forever. A failed challenge can be transient
	 * (a zone mid-propagation, a rate limit), and a node whose retry timer is
	 * armed issues on its own once the name reaches it, so both stay on the
	 * retry path.
	 */
	public static function needs_operator($state) {
		return in_array($state, [self::NO_DNS_CREDENTIALS, self::NO_CHALLENGE_PATH, self::APACHE_CONFIG_BROKEN,
			self::RETRY_NOT_ARMED, self::EDGE_STRICT], true);
	}

	/** The script's text says one of PHRASES, in any case. */
	private static function says($text, $key) {
		return stripos($text, self::PHRASES[$key]) !== false;
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
