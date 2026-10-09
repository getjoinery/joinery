<?php
/**
 * SpamMeta — the stored form of a message's meta tokens and corpus state
 * (iem_spam_meta, spam_learning_in_core.md § Meta tokens).
 *
 * The meta tokens are computed at ingest from clear facts and read again when
 * the message is taught, so teaching uses the facts the verdict saw. They are
 * stored as a short code string rather than JSON for one reason: ingest can run
 * in a process that has already opened sealed content (a drain in an open
 * window), and SealedEgressGuard refuses any string longer than 64 characters
 * such a process writes. The longest possible value here is about 25.
 *
 *   f.c.b.dp.sp.kp.r6|u
 *   │ │ │ │  │  │  │  └ corpus state: o off, u untrained, d undecided, s spam, h ham
 *   │ │ │ │  │  │  └ scanner source + 2-point score band: r rspamd, m mailgun,
 *   │ │ │ │  │  │    g sendgrid, a ses, x any other
 *   │ │ │ └──┴──┴ dmarc / spf / dkim result (AUTH_CODES)
 *   └─┴─┴ first_contact, catch_all, burst; u dmarc_monitored_fail (a DMARC fail
 *         under p=none, which the auth rule does not file on its own)
 *
 * Token values are canonical (canonicalAuth / canonicalSource), so what decode()
 * returns is exactly what the tokenizer saw at ingest.
 *
 * @version 1.1 - dmarc_monitored_fail flag (code u)
 * @version 1.0
 */

class SpamMeta {

	const AUTH_CODES = array(
		'pass' => 'p', 'fail' => 'f', 'none' => 'n', 'softfail' => 's', 'neutral' => 'e',
		'temperror' => 't', 'permerror' => 'r', 'policy' => 'o', 'unverified' => 'v', 'other' => 'x',
	);
	const SOURCE_CODES = array('rspamd' => 'r', 'mailgun' => 'm', 'sendgrid' => 'g', 'ses' => 'a', 'other' => 'x');
	const FLAG_CODES = array('first_contact' => 'f', 'catch_all' => 'c', 'burst' => 'b', 'dmarc_monitored_fail' => 'u');
	const AUTH_PREFIXES = array('dmarc' => 'd', 'spf' => 's', 'dkim' => 'k');
	const BAYES_CODES = array('off' => 'o', 'untrained' => 'u', 'undecided' => 'd', 'spam' => 's', 'ham' => 'h');

	/** An auth result as one of the values the tokens use. */
	public static function canonicalAuth(string $value): string {
		$value = strtolower(trim($value));
		if ($value === '') {
			return 'unverified';
		}
		return isset(self::AUTH_CODES[$value]) ? $value : 'other';
	}

	/** A scanner source as one of the values the tokens use. */
	public static function canonicalSource(string $source): string {
		$source = strtolower(trim($source));
		return isset(self::SOURCE_CODES[$source]) ? $source : 'other';
	}

	/**
	 * @param string[] $tokens meta tokens as spamMetaTokens() produces them
	 * @param string   $bayes  off | untrained | undecided | spam | ham
	 */
	public static function encode(array $tokens, string $bayes): string {
		$parts = array();
		foreach ($tokens as $t) {
			if (isset(self::FLAG_CODES[$t])) {
				$parts[] = self::FLAG_CODES[$t];
			} elseif (preg_match('/^(dmarc|spf|dkim):(.+)$/', $t, $m)) {
				$parts[] = self::AUTH_PREFIXES[$m[1]] . self::AUTH_CODES[self::canonicalAuth($m[2])];
			} elseif (preg_match('/^scanner:([a-z]+):(-?\d+)$/', $t, $m)) {
				$parts[] = self::SOURCE_CODES[self::canonicalSource($m[1])] . intval($m[2]);
			}
		}
		return implode('.', $parts) . '|' . (self::BAYES_CODES[$bayes] ?? 'o');
	}

	/** @return array{tokens: string[], bayes: string} */
	public static function decode(?string $stored): array {
		$stored = (string)$stored;
		$out = array('tokens' => array(), 'bayes' => '');
		if ($stored === '') {
			return $out;
		}
		$bar = strrpos($stored, '|');
		$body = ($bar === false) ? $stored : substr($stored, 0, $bar);
		if ($bar !== false) {
			$out['bayes'] = (string)(array_flip(self::BAYES_CODES)[substr($stored, $bar + 1)] ?? '');
		}
		$flags = array_flip(self::FLAG_CODES);
		$auth_prefixes = array_flip(self::AUTH_PREFIXES);
		$auth = array_flip(self::AUTH_CODES);
		$sources = array_flip(self::SOURCE_CODES);
		foreach (($body === '') ? array() : explode('.', $body) as $p) {
			if (isset($flags[$p])) {
				$out['tokens'][] = $flags[$p];
			} elseif (strlen($p) === 2 && isset($auth_prefixes[$p[0]]) && isset($auth[$p[1]])) {
				$out['tokens'][] = $auth_prefixes[$p[0]] . ':' . $auth[$p[1]];
			} elseif (preg_match('/^([a-z])(-?\d+)$/', $p, $m) && isset($sources[$m[1]])) {
				$out['tokens'][] = 'scanner:' . $sources[$m[1]] . ':' . intval($m[2]);
			}
		}
		return $out;
	}
}
?>
