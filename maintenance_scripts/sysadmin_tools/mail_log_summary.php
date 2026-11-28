<?php
/**
 * mail_log_summary.php — what the mail_log_summary agent word carries.
 *
 * Usage: php mail_log_summary.php [HOURS]     (1 to 168, default 24)
 *
 * What this machine's own Postfix did with mail sent to the site, over the
 * last HOURS, read from the system journal and compiled into counts: how many
 * connections came, how many messages were delivered, deferred, refused by
 * the delivery handler or refused by Postfix and why, and the newest 20
 * outcomes with their time and the sender's and recipient's domains.
 *
 * Nothing in the answer is text Postfix or another server wrote: every value
 * is a compiled word, a count, a time to the minute, or a domain read from a
 * fixed field and checked against the domain pattern (a recipient domain only
 * when it is one of the site's own mail domains). No IP address, hostname,
 * greeting name, local part, Message-ID, queue id or size ever leaves.
 *
 * Run by the agent as root (the journal), behind the site owner's log-access
 * switch. Prints one JSON object; exit 0 on an answer (a refusal is an
 * answer), 1 on an error, 2 on bad usage.
 *
 * Counting rules (specs/mailbox_diagnosis_words.md WP3):
 *   - inbound: a message whose smtpd client= line names a non-loopback client;
 *   - one outcome per recipient, its last status inside the window;
 *   - delivered = status=sent through the joinery pipe (a deferral the handler
 *     later dropped also logs as sent, and counts as delivered here),
 *     handler_refused = status=bounced through it, expired = Postfix gave up
 *     at the queue lifetime (status=expired, or bounced with relay=none),
 *     deferred = waiting for a retry at the end of the window;
 *   - a refusal before a queue id is one outcome per rejected recipient line.
 *
 * @version 1.1.0 - covers_from from the journal's own first entry (covers_window); expired; forwarding subdomains are the site's
 * @version 1.0.0 - the mail_log_summary word (specs/mailbox_diagnosis_words.md WP3)
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/** The identifiers this Postfix logs under, asked of the journal as fixed matches. */
const MLS_IDENTIFIERS = array('postfix/smtpd', 'postfix/cleanup', 'postfix/qmgr', 'postfix/pipe',
	'postfix/smtp', 'postfix/error', 'postfix/bounce', 'postfix/local');

/** At most this many journal entries are read. */
const MLS_MAX_ENTRIES = 50000;

/** Read this much further back than asked, so a delivery's client= line is known. */
const MLS_LOOKBACK_HOURS = 6;

const MLS_REFUSAL_REASONS = array('blocklist', 'relay_denied', 'unknown_recipient', 'lookup_failure', 'tls', 'other');

const MLS_DOMAIN_PATTERN = '/^[a-z0-9.-]+\.[a-z]{2,}$/';

// A test loads the functions without running the word.
if (defined('MLS_FUNCTIONS_ONLY')) {
	return;
}

if (count($argv) > 2) {
	fwrite(STDERR, "Usage: php mail_log_summary.php [HOURS]\n");
	exit(2);
}
$hours_arg = isset($argv[1]) ? trim($argv[1]) : '';
if ($hours_arg === '') {
	$hours = 24;
} elseif (preg_match('/^[0-9]{1,3}$/', $hours_arg) && (int)$hours_arg >= 1 && (int)$hours_arg <= 168) {
	$hours = (int)$hours_arg;
} else {
	fwrite(STDERR, "mail_log_summary: hours must be 1 to 168\n");
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

/** The domain part of an address, lowercased, or null when it is not a plain ASCII domain. */
function mls_domain(?string $address): ?string {
	if ($address === null || $address === '') {
		return null;   // the null sender
	}
	$at = strrpos($address, '@');
	if ($at === false) {
		return null;
	}
	$d = strtolower(substr($address, $at + 1));
	if ($d === '' || strlen($d) > 253 || !preg_match(MLS_DOMAIN_PATTERN, $d) || strpos($d, '..') !== false) {
		return null;
	}
	return $d;
}

/** Is a client/connect line's "host[ip]" loopback? Anything that does not read as an address is not. */
function mls_loopback(string $bracketed): bool {
	$ip = $bracketed;
	if (stripos($ip, 'ipv6:') === 0) {
		$ip = substr($ip, 5);
	}
	$bin = @inet_pton($ip);
	if ($bin === false) {
		return false;
	}
	if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
		$bin = substr($bin, 12);
	}
	if (strlen($bin) === 4) {
		return ord($bin[0]) === 127;
	}
	return $bin === str_repeat("\0", 15) . "\x01";
}

/** The address inside the last [...] of "host[ip]" before $marker, or null. */
function mls_client_ip(string $msg, string $marker): ?string {
	$pos = strpos($msg, $marker);
	if ($pos === false) {
		return null;
	}
	$rest = substr($msg, $pos + strlen($marker));
	return preg_match('/^[^\s\[\]]*\[([0-9A-Fa-f:.Iv]+)\]/', $rest, $m) ? $m[1] : null;
}

/**
 * A reject line's reason word, from Postfix's own text after the reply code,
 * never the remote reply quoted inside it. $addresses are the line's own
 * from/to/helo values: a reject text that starts with one of them in <...>
 * has it removed before the phrase is read.
 */
function mls_reject_reason(string $text, array $addresses): array {
	if (!preg_match('/^([45])\d\d [45]\.\d{1,3}\.\d{1,3} (.*)$/s', $text, $m)) {
		return array('other', null);
	}
	$code = $m[1] . 'xx';
	$rest = $m[2];
	if ($rest !== '' && $rest[0] === '<') {
		$stripped = null;
		foreach ($addresses as $a) {
			if ($a !== null && strpos($rest, '<' . $a . '>: ') === 0) {
				$stripped = substr($rest, strlen($a) + 4);
				break;
			}
		}
		if ($stripped === null) {
			return array('other', $code);
		}
		$rest = $stripped;
	}
	foreach (array('Service unavailable; ', 'Helo command rejected: Service unavailable; ', 'Sender address rejected: Service unavailable; ',
			'Client host rejected: Service unavailable; ') as $prefix) {
		if (strpos($rest, $prefix) === 0 && strpos($rest, ' blocked using ') !== false) {
			return array('blocklist', $code);
		}
	}
	if (strpos($rest, 'Relay access denied') === 0) {
		return array('relay_denied', $code);
	}
	if (strpos($rest, 'Temporary lookup failure') === 0) {
		return array($code === '4xx' ? 'lookup_failure' : 'other', $code);
	}
	if (strpos($rest, 'Recipient address rejected: ') === 0) {
		return array($code === '5xx' ? 'unknown_recipient' : 'lookup_failure', $code);
	}
	return array('other', $code);
}

/**
 * A reject line's from=, to= and helo= fields: [from, to, helo], each null
 * when absent. Read at the first "; from=<" only, the fixed tail Postfix
 * writes after its reject text, and nowhere else: the greeting name comes last
 * and is the remote's own text, so a fake tail inside it is never reached. A
 * sender holding < or > does not read, and the line then has no fields.
 */
function mls_reject_fields(string $msg): array {
	$pos = strpos($msg, '; from=<');
	if ($pos === false) {
		return array(null, null, null);
	}
	$tail = substr($msg, $pos);
	if (preg_match('/^; from=<([^<>]*)> to=<([^<>]*)> proto=[A-Z]+(?: helo=<([^<>]*)>)?/', $tail, $m)) {
		return array($m[1], $m[2], $m[3] ?? null);
	}
	return array(null, null, null);
}

/** A journal entry's MESSAGE as a string (journald gives a byte array for one that is not UTF-8). */
function mls_message($entry): string {
	$m = $entry['MESSAGE'] ?? '';
	if (is_array($m)) {
		$s = '';
		foreach ($m as $b) {
			if (is_int($b) && $b >= 0 && $b < 256) { $s .= chr($b); }
		}
		return $s;
	}
	return is_string($m) ? $m : '';
}

/**
 * The summary, from journal entries in time order (journald JSON objects).
 *
 * @param iterable $entries   journal entries, oldest first
 * @param int      $hours     the window asked for
 * @param int      $now       the time the window ends, unix seconds
 * @param string[] $hosted    the site's own mail domains, lowercased
 * @param bool     $truncated whether the read stopped at MLS_MAX_ENTRIES
 * @param int|null $journal_first the journal's own oldest entry (any unit), unix seconds; null when unknown
 */
function mls_summarize(iterable $entries, int $hours, int $now, array $hosted, bool $truncated = false, ?int $journal_first = null): array {
	$window_start = $now - $hours * 3600;
	$hosted = array_fill_keys(array_map('strtolower', $hosted), true);
	$to_domain = function (?string $address) use ($hosted) {
		$d = mls_domain($address);
		return ($d !== null && isset($hosted[$d])) ? $d : null;
	};

	$counts = array('delivered' => 0, 'deferred' => 0, 'handler_refused' => 0, 'expired' => 0,
		'refused' => array_fill_keys(MLS_REFUSAL_REASONS, 0));
	$connections = 0;
	$dropped = 0;
	$outcomes = array();     // each: [ts, outcome, reason, code, from_domain, to_domain]
	$messages = array();     // qid => {inbound, from_domain, rcpts: [to => [status, ts]]}
	$conns = array();        // smtpd pid => {counted, queued, tls}

	$finish = function (string $qid) use (&$messages, &$outcomes, $window_start, $to_domain) {
		$msg = $messages[$qid] ?? null;
		unset($messages[$qid]);
		if ($msg === null || !$msg['inbound']) {
			return;
		}
		foreach ($msg['rcpts'] as $to => $last) {
			list($status, $ts) = $last;
			if ($ts < $window_start) {
				continue;
			}
			$outcomes[] = array($ts, $status, null, null, $msg['from_domain'], $to_domain($to));
		}
	};

	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$ident = (string)($entry['SYSLOG_IDENTIFIER'] ?? '');
		if (!in_array($ident, MLS_IDENTIFIERS, true)) {
			continue;
		}
		$ts = (int)floor(((int)($entry['__REALTIME_TIMESTAMP'] ?? 0)) / 1000000);
		$in_window = $ts >= $window_start;
		$msg = mls_message($entry);
		$pid = (string)($entry['_PID'] ?? ($entry['SYSLOG_PID'] ?? ''));

		if ($ident === 'postfix/smtpd') {
			if (strpos($msg, 'connect from ') === 0) {
				$ip = mls_client_ip($msg, 'connect from ');
				$outside = $ip !== null && !mls_loopback($ip);
				$conns[$pid] = array('counted' => $outside && $in_window, 'queued' => false, 'tls' => false);
				if ($outside && $in_window) {
					$connections++;
				}
				continue;
			}
			if (preg_match('/^(lost connection|timeout) after \S+ from /', $msg)) {
				$c = $conns[$pid] ?? null;
				if ($c !== null && $c['counted'] && !$c['queued'] && !$c['tls']) {
					$dropped++;
				}
				continue;
			}
			if (strpos($msg, 'disconnect from ') === 0) {
				unset($conns[$pid]);
				continue;
			}
			if (strpos($msg, 'SSL_accept error from ') === 0 || strpos($msg, 'warning: TLS library problem') === 0) {
				$c = $conns[$pid] ?? null;
				if ($c !== null && $c['counted'] && !$c['tls']) {
					$conns[$pid]['tls'] = true;
					$outcomes[] = array($ts, 'refused', 'tls', null, null, null);
				}
				continue;
			}
			if (preg_match('/^([0-9A-Za-z]+): client=/', $msg, $m)) {
				$ip = mls_client_ip($msg, ': client=');
				// A short queue id is reused once removed: a new client= starts a new message.
				$messages[$m[1]] = array('inbound' => $ip !== null && !mls_loopback($ip), 'from_domain' => null, 'rcpts' => array());
				if (isset($conns[$pid])) {
					$conns[$pid]['queued'] = true;
				}
				continue;
			}
			if (preg_match('/^(NOQUEUE|[0-9A-Za-z]+): reject: (\S+) from [^\s\[\]]*\[([0-9A-Fa-f:.Iv]+)\](?::\d+)?: (.*)$/s', $msg, $m)) {
				if (!$in_window || mls_loopback($m[3])) {
					continue;
				}
				list($from, $to, $helo) = mls_reject_fields($msg);
				$text = $m[4];
				$semi = strpos($text, '; from=<');
				$text = $semi === false ? $text : substr($text, 0, $semi);
				if ($m[2] === 'RCPT') {
					list($reason, $code) = mls_reject_reason($text, array($to, $from, $helo));
				} else {
					$reason = 'other';
					$code = preg_match('/^([45])\d\d /', $text, $c) ? $c[1] . 'xx' : null;
				}
				$outcomes[] = array($ts, 'refused', $reason, $code, mls_domain($from), $to_domain($to));
				continue;
			}
			continue;
		}

		if (!preg_match('/^([0-9A-Za-z]+): (.*)$/s', $msg, $m)) {
			continue;
		}
		$qid = $m[1];
		$rest = $m[2];

		if ($ident === 'postfix/qmgr') {
			if ($rest === 'removed') {
				$finish($qid);
				continue;
			}
			if (isset($messages[$qid]) && preg_match('/^from=<([^<>]*)>, size=\d+, nrcpt=\d+ \(queue active\)$/', $rest, $f)) {
				$messages[$qid]['from_domain'] = mls_domain($f[1]);
			}
			// The queue lifetime ran out: every recipient still deferred is given up on.
			if (isset($messages[$qid]) && preg_match('/^from=<[^<>]*>, status=expired, returned to sender$/', $rest)) {
				foreach ($messages[$qid]['rcpts'] as $to => $last) {
					if ($last[0] === 'deferred') {
						$messages[$qid]['rcpts'][$to] = array('expired', $ts);
					}
				}
				continue;
			}
			// A deferral before any transport ran (relay=none) comes from qmgr or error.
		}

		if (!isset($messages[$qid])) {
			continue;
		}
		if (preg_match('/^to=<([^<>]*)>,(?: orig_to=<[^<>]*>,)? relay=([^,\s]+), (?:conn_use=\d+, )?delay=[0-9.]+, delays=[0-9.\/]+, dsn=\d\.\d{1,3}\.\d{1,3}, status=(sent|deferred|bounced) /', $rest, $d)) {
			$to = strtolower($d[1]);
			$relay = $d[2];
			$status = $d[3];
			$joinery = $relay === 'joinery';
			if ($status === 'sent' && $joinery) {
				$messages[$qid]['rcpts'][$to] = array('delivered', $ts);
			} elseif ($status === 'bounced' && $joinery) {
				$messages[$qid]['rcpts'][$to] = array('handler_refused', $ts);
			} elseif ($status === 'bounced' && $relay === 'none') {
				$messages[$qid]['rcpts'][$to] = array('expired', $ts);
			} elseif ($status === 'deferred' && ($joinery || $relay === 'none')) {
				$messages[$qid]['rcpts'][$to] = array('deferred', $ts);
			}
		}
	}
	foreach (array_keys($messages) as $qid) {
		$finish((string)$qid);   // still queued at the end of the read
	}

	foreach ($outcomes as $o) {
		if ($o[1] === 'refused') {
			$counts['refused'][$o[2]]++;
		} else {
			$counts[$o[1]]++;
		}
	}
	usort($outcomes, function ($a, $b) { return $b[0] <=> $a[0]; });
	$recent = array();
	foreach (array_slice($outcomes, 0, 20) as $o) {
		$row = array('time' => gmdate('Y-m-d\TH:i\Z', $o[0]), 'outcome' => $o[1]);
		if ($o[1] === 'refused') {
			$row['reason'] = $o[2];
			$row['code'] = $o[3];
		}
		$row['from_domain'] = $o[4];
		$row['to_domain'] = $o[5];
		$recent[] = $row;
	}

	// How far back the answer can see: the journal's own first entry, or the
	// window's start when the journal reaches further. The lookback hours are
	// read only to join lines; they are not coverage.
	$covers = $journal_first === null ? null : max($journal_first, $window_start);
	return array(
		'hours'               => $hours,
		'covers_from'         => $covers === null ? null : gmdate('Y-m-d\TH:i\Z', $covers),
		'covers_window'       => $journal_first !== null && $journal_first <= $window_start,
		'connections'         => $connections,
		'dropped_connections' => $dropped,
		'counts'              => $counts,
		'recent'              => $recent,
		'truncated'           => $truncated,
	);
}

/**
 * The journal's own oldest entry, any unit, in unix seconds: how far back it
 * reaches, whether or not any mail came. One line read, then journalctl is
 * stopped. Null when it could not run or holds nothing.
 */
function mls_journal_first(): ?int {
	$cmd = array('journalctl', '--no-pager', '-q', '-o', 'json', '--output-fields=__REALTIME_TIMESTAMP');
	$proc = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($proc)) {
		return null;
	}
	$line = fgets($pipes[1]);
	fclose($pipes[1]);
	proc_terminate($proc);
	proc_close($proc);
	$e = is_string($line) ? json_decode($line, true) : null;
	$us = is_array($e) ? (string)($e['__REALTIME_TIMESTAMP'] ?? '') : '';
	return ctype_digit($us) ? (int)floor((int)$us / 1000000) : null;
}

/**
 * The journal's Postfix entries since $hours + the lookback, newest
 * MLS_MAX_ENTRIES of them, oldest first. Null when journalctl could not run.
 */
function mls_read_journal(int $hours, ?bool &$truncated): ?array {
	$cmd = array('journalctl', '--no-pager', '-q', '-o', 'json', '--since=-' . ($hours + MLS_LOOKBACK_HOURS) . 'h', '-n', (string)MLS_MAX_ENTRIES);
	foreach (MLS_IDENTIFIERS as $id) {
		$cmd[] = 'SYSLOG_IDENTIFIER=' . $id;
	}
	$proc = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($proc)) {
		return null;
	}
	$entries = array();
	while (($line = fgets($pipes[1])) !== false) {
		$e = json_decode($line, true);
		if (is_array($e)) {
			$entries[] = array_intersect_key($e, array('MESSAGE' => 1, 'SYSLOG_IDENTIFIER' => 1, '_PID' => 1, 'SYSLOG_PID' => 1, '__REALTIME_TIMESTAMP' => 1));
		}
	}
	fclose($pipes[1]);
	$code = proc_close($proc);
	if ($code !== 0 && !$entries) {
		return null;
	}
	$truncated = count($entries) >= MLS_MAX_ENTRIES;
	return $entries;
}

try {
	if (!class_exists('MultiInboundEmailDomain')) {
		echo json_encode(array('hours' => $hours, 'refused' => 'no_postfix')) . "\n";
		exit(0);
	}
	// This machine's Postfix receives the site's mail only when it carries the
	// joinery pipe (install_email.sh); a site whose mail comes through the relay has none.
	$master = @file_get_contents('/etc/postfix/master.cf');
	if (!is_string($master) || !preg_match('/^joinery\s+unix\s/m', $master)) {
		echo json_encode(array('hours' => $hours, 'refused' => 'no_postfix')) . "\n";
		exit(0);
	}

	$hosted = array();
	foreach (new MultiInboundEmailDomain(array('deleted' => false)) as $d) {
		// A domain and its forwarding subdomain (where forwarded mail's bounces return) are both the site's.
		foreach (array((string)$d->get('ied_domain'), (string)$d->forwarding_subdomain()) as $name) {
			$name = strtolower(trim($name));
			if ($name !== '' && strlen($name) <= 253 && preg_match(MLS_DOMAIN_PATTERN, $name)) {
				$hosted[] = $name;
			}
		}
	}

	$truncated = false;
	$entries = mls_read_journal($hours, $truncated);
	if ($entries === null) {
		echo json_encode(array('hours' => $hours, 'refused' => 'journal_unreadable')) . "\n";
		exit(0);
	}
	echo json_encode(mls_summarize($entries, $hours, time(), array_values(array_unique($hosted)), (bool)$truncated, mls_journal_first())) . "\n";
} catch (\Throwable $e) {
	echo 'ERROR: the mail log could not be summarised: ' . get_class($e) . "\n";
	exit(1);
}
exit(0);
