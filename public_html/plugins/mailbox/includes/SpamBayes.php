<?php
/**
 * SpamBayes — the deployment's spam classifier: tokenizer, corpus store and
 * scoring (spam_learning_in_core.md § The Bayes classifier).
 *
 * TOKENIZER. Works from the parsed message, never a rebuilt email:
 *   - subject and body (the plain part, or the HTML's readable text when there
 *     is none), lowercased, split into words of 2-40 characters;
 *   - every word, and every pair of words up to WINDOW apart;
 *   - the hosts of the URLs in the body;
 *   - the sender's domain and display name;
 *   - the meta tokens computed at ingest (iem_spam_meta);
 *   - capped at the first MAX_TOKENS distinct tokens.
 * Each token is stored as the first 8 bytes of HMAC-SHA256(token key, token),
 * a signed bigint. 0 is reserved for the totals row, so a token that hashes to
 * 0 is stored as 1. TOKENIZER_VERSION is stamped on every message taught
 * (iem_learned_tokenizer): unteaching a message taught by another version
 * cannot reproduce its tokens and skips the token subtraction.
 *
 * STORE. ibt_inbound_bayes_tokens, one row per token with the number of spam
 * and ham messages that carried it; the totals live on row 0. A teaching
 * upserts the totals row first and then the tokens in ascending order, in one
 * statement each, so every teaching takes its row locks in the same order and
 * two teachings cannot deadlock.
 *
 * SCORING. Robinson's per-token probability with Fisher's chi-square
 * combining, over the DECISIVE tokens furthest from neutral. The classifier
 * votes only once the totals reach MIN_SPAM and MIN_HAM, and only past the
 * strict SPAM_AT / HAM_AT thresholds; a young corpus lands between them and
 * defers to the scanner.
 *
 * @version 1.0
 */

class SpamBayes {

	const TOKENIZER_VERSION = 1;

	const MIN_SPAM = 50;
	const MIN_HAM  = 50;
	const SPAM_AT  = 0.99;
	const HAM_AT   = 0.01;

	const MAX_TOKENS   = 2000;
	const DECISIVE     = 150;
	const WINDOW       = 4;
	const MIN_WORD     = 2;
	const MAX_WORD     = 40;
	/** Text past this many bytes adds nothing a filter needs and costs every scan. */
	const MAX_TEXT_BYTES = 65536;

	/** Robinson's prior: strength and assumed probability for a rare token. */
	const PRIOR_STRENGTH = 1.0;
	const PRIOR_PROB     = 0.5;
	/** Tokens closer to neutral than this carry no vote. */
	const MIN_DEVIATION  = 0.1;

	const TOTALS = 0;

	/** Tokens per statement: well under Postgres's bind-parameter limit. */
	const CHUNK = 1000;

	// ── tokenizer ─────────────────────────────────────────────────────────

	/**
	 * The distinct tokens of a message, in the order they are taken.
	 *
	 * @param array $m subject, body_plain, body_html, sender (a From display
	 *                 string), meta (string[] meta tokens)
	 * @return string[]
	 */
	public static function tokens(array $m): array {
		$out = array();
		$add = function (string $t) use (&$out): bool {
			if (count($out) >= self::MAX_TOKENS) {
				return false;
			}
			$out[$t] = true;
			return true;
		};

		foreach ((array)($m['meta'] ?? array()) as $meta) {
			$add('meta:' . $meta);
		}

		$sender = trim((string)($m['sender'] ?? ''));
		if ($sender !== '') {
			$address = $sender;
			$name = '';
			if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^<>]+)>\s*$/', $sender, $mm)) {
				$name = trim($mm[1]);
				$address = trim($mm[2]);
			}
			$at = strrpos($address, '@');
			if ($at !== false) {
				$add('from_domain:' . strtolower(substr($address, $at + 1)));
			}
			if ($name !== '') {
				$add('from_name:' . self::lower($name));
			}
		}

		$body = (string)($m['body_plain'] ?? '');
		$html = (string)($m['body_html'] ?? '');
		if (trim($body) === '' && $html !== '') {
			$body = MailboxHtmlSanitizer::toReadableText($html);
		}
		// Cut on a character boundary: a split UTF-8 sequence would make the
		// whole text invalid for the unicode word split.
		$body = self::cut($body);

		foreach (self::urlHosts($body . "\n" . self::cut($html)) as $host) {
			$add('url:' . $host);
		}

		foreach (array('s:' => (string)($m['subject'] ?? ''), 'w:' => $body) as $prefix => $text) {
			$words = self::words($text);
			$n = count($words);
			for ($i = 0; $i < $n; $i++) {
				if (!$add($prefix . $words[$i])) {
					break 2;
				}
				for ($j = $i + 1; $j <= $i + self::WINDOW && $j < $n; $j++) {
					if (!$add($prefix . $words[$i] . ' ' . $words[$j])) {
						break 3;
					}
				}
			}
		}
		return array_keys($out);
	}

	/** The words of a text: lowercased, split on anything not a letter or digit, 2-40 characters. */
	public static function words(string $text): array {
		$text = self::lower($text);
		$parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if ($parts === false) {
			// Not valid UTF-8: fall back to a byte split rather than nothing.
			$parts = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: array();
		}
		$out = array();
		foreach ($parts as $w) {
			$len = function_exists('mb_strlen') ? mb_strlen($w, 'UTF-8') : strlen($w);
			if ($len >= self::MIN_WORD && $len <= self::MAX_WORD) {
				$out[] = $w;
			}
		}
		return $out;
	}

	/** Lowercased hosts of the http(s) URLs in a text, distinct, in order. */
	public static function urlHosts(string $text): array {
		$hosts = array();
		if (preg_match_all('#https?://([a-z0-9.\-]+)#i', $text, $m)) {
			foreach ($m[1] as $host) {
				$host = strtolower(rtrim($host, '.'));
				if ($host !== '') {
					$hosts[$host] = true;
				}
			}
		}
		return array_keys($hosts);
	}

	/**
	 * The stored form of each token: the first 8 bytes of the keyed HMAC as a
	 * signed bigint, distinct, sorted ascending (the order teachings lock in).
	 *
	 * @param string[] $tokens
	 * @return int[]
	 */
	public static function hashes(array $tokens, ?string $key = null): array {
		$key = $key ?? MailboxSpamKeys::get(MailboxSpamKeys::TOKEN);
		$out = array();
		foreach ($tokens as $t) {
			$v = unpack('J', substr(hash_hmac('sha256', (string)$t, $key, true), 0, 8))[1];
			$out[($v === self::TOTALS) ? 1 : $v] = true;
		}
		$ids = array_keys($out);
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	// ── corpus ────────────────────────────────────────────────────────────

	/** Messages taught to each class: ['spam' => int, 'ham' => int]. */
	public static function totals(): array {
		$stmt = self::db()->prepare('SELECT ibt_spam_count, ibt_ham_count FROM ibt_inbound_bayes_tokens WHERE ibt_token = 0');
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return array('spam' => $row ? intval($row['ibt_spam_count']) : 0,
			'ham' => $row ? intval($row['ibt_ham_count']) : 0);
	}

	/** Whether the corpus is big enough for its answer to count. */
	public static function trained(?array $totals = null): bool {
		$totals = $totals ?? self::totals();
		return $totals['spam'] >= self::MIN_SPAM && $totals['ham'] >= self::MIN_HAM;
	}

	/**
	 * How far the corpus is from voting, for the Settings page and the timeline.
	 *
	 * @return array{spam:int, ham:int, need_spam:int, need_ham:int, voting:bool}
	 */
	public static function progress(): array {
		$t = self::totals();
		return array(
			'spam' => $t['spam'], 'ham' => $t['ham'],
			'need_spam' => max(0, self::MIN_SPAM - $t['spam']),
			'need_ham'  => max(0, self::MIN_HAM - $t['ham']),
			'voting' => self::trained($t),
		);
	}

	/**
	 * Add ($sign +1) or subtract ($sign -1) one message of $class. $hashes null
	 * moves only the totals (an unteach whose tokens cannot be reproduced).
	 * Runs inside the caller's teaching transaction; counts never go below 0.
	 *
	 * @param int[]|null $hashes from hashes(), ascending
	 */
	public static function adjust(string $class, ?array $hashes, int $sign): void {
		$col = ($class === InboundEmailMessage::SPAM_VERDICT_SPAM) ? 'ibt_spam_count' : 'ibt_ham_count';
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		if ($sign > 0) {
			// Totals first, then tokens ascending — one lock order for everyone.
			$db->prepare("INSERT INTO ibt_inbound_bayes_tokens (ibt_token, $col, ibt_last_seen_time)
				VALUES (0, 1, ?)
				ON CONFLICT (ibt_token) DO UPDATE SET $col = ibt_inbound_bayes_tokens.$col + 1,
					ibt_last_seen_time = EXCLUDED.ibt_last_seen_time")->execute(array($now));
			foreach (array_chunk($hashes ?? array(), self::CHUNK) as $chunk) {
				$stmt = $db->prepare("INSERT INTO ibt_inbound_bayes_tokens (ibt_token, $col, ibt_last_seen_time)
					SELECT t, 1, CAST(? AS timestamp) FROM unnest(" . self::intArray(count($chunk)) . ") AS t ORDER BY t
					ON CONFLICT (ibt_token) DO UPDATE SET $col = ibt_inbound_bayes_tokens.$col + 1,
						ibt_last_seen_time = EXCLUDED.ibt_last_seen_time");
				self::bindInts($stmt, $chunk, array($now));
				$stmt->execute();
			}
			return;
		}
		$db->prepare("UPDATE ibt_inbound_bayes_tokens SET $col = GREATEST($col - 1, 0) WHERE ibt_token = 0")->execute();
		foreach (array_chunk($hashes ?? array(), self::CHUNK) as $chunk) {
			// Lock in ascending order before updating, the order a teaching takes.
			$lock = $db->prepare("SELECT ibt_token FROM ibt_inbound_bayes_tokens
				WHERE ibt_token = ANY(" . self::intArray(count($chunk)) . ") ORDER BY ibt_token FOR UPDATE");
			self::bindInts($lock, $chunk);
			$lock->execute();
			$stmt = $db->prepare("UPDATE ibt_inbound_bayes_tokens SET $col = GREATEST($col - 1, 0)
				WHERE ibt_token = ANY(" . self::intArray(count($chunk)) . ")");
			self::bindInts($stmt, $chunk);
			$stmt->execute();
		}
	}

	// ── scoring ───────────────────────────────────────────────────────────

	/**
	 * The corpus's answer for a message's token hashes.
	 *
	 * @param int[] $hashes
	 * @return array{state:string, p:?float}  state 'untrained' (p null) or 'scored'
	 */
	public static function classify(array $hashes): array {
		$totals = self::totals();
		if (!self::trained($totals) || !count($hashes)) {
			return array('state' => 'untrained', 'p' => null);
		}
		$counts = array();
		foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
			$stmt = self::db()->prepare('SELECT ibt_token, ibt_spam_count, ibt_ham_count
				FROM ibt_inbound_bayes_tokens WHERE ibt_token = ANY(' . self::intArray(count($chunk)) . ') AND ibt_token <> 0');
			self::bindInts($stmt, $chunk);
			$stmt->execute();
			foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
				$counts[] = array(intval($r[1]), intval($r[2]));
			}
		}
		return array('state' => 'scored', 'p' => self::score($counts, $totals['spam'], $totals['ham']));
	}

	/**
	 * Robinson-Fisher combining over per-token (spam, ham) counts. Pure, so the
	 * maths is testable against fixed counts.
	 *
	 * @param array<int, array{0:int,1:int}> $counts
	 * @return float in [0, 1]; 0.5 when nothing is decisive
	 */
	public static function score(array $counts, int $nspam, int $nham): float {
		if ($nspam <= 0 || $nham <= 0) {
			return 0.5;
		}
		$probs = array();
		foreach ($counts as $c) {
			$s = max(0, intval($c[0]));
			$h = max(0, intval($c[1]));
			$n = $s + $h;
			if ($n === 0) {
				continue;
			}
			$ps = $s / $nspam;
			$ph = $h / $nham;
			$p = $ps / ($ps + $ph);
			$f = (self::PRIOR_STRENGTH * self::PRIOR_PROB + $n * $p) / (self::PRIOR_STRENGTH + $n);
			if (abs($f - 0.5) >= self::MIN_DEVIATION) {
				$probs[] = $f;
			}
		}
		if (!count($probs)) {
			return 0.5;
		}
		usort($probs, function ($a, $b) {
			return abs($b - 0.5) <=> abs($a - 0.5);
		});
		$probs = array_slice($probs, 0, self::DECISIVE);

		$ln_f = 0.0;
		$ln_1f = 0.0;
		foreach ($probs as $f) {
			$f = min(max($f, 1e-9), 1 - 1e-9);
			$ln_f += log($f);
			$ln_1f += log(1 - $f);
		}
		$dof = 2 * count($probs);
		$spamminess = 1.0 - self::chi2Q(-2.0 * $ln_1f, $dof);
		$hamminess  = 1.0 - self::chi2Q(-2.0 * $ln_f, $dof);
		return (1.0 + $spamminess - $hamminess) / 2.0;
	}

	/**
	 * P(chi-square with $dof degrees of freedom >= $x2), $dof even, computed in
	 * log space so a long run of decisive tokens never underflows.
	 */
	public static function chi2Q(float $x2, int $dof): float {
		$m = $x2 / 2.0;
		if ($m <= 0) {
			return 1.0;
		}
		$k = intdiv($dof, 2);
		$log_m = log($m);
		$terms = array();
		$log_term = -$m;
		$terms[] = $log_term;
		for ($i = 1; $i < $k; $i++) {
			$log_term += $log_m - log($i);
			$terms[] = $log_term;
		}
		$max = max($terms);
		$sum = 0.0;
		foreach ($terms as $t) {
			$sum += exp($t - $max);
		}
		return min(1.0, exp($max + log($sum)));
	}

	// ── maintenance ───────────────────────────────────────────────────────

	/**
	 * Weekly pruning: drop tokens seen at most once that nothing has carried for
	 * STALE_DAYS, then the oldest low-count tokens past MAX_ROWS.
	 *
	 * @return array{stale:int, overflow:int}
	 */
	public static function prune(int $stale_days = 60, int $max_rows = 2000000): array {
		$db = self::db();
		// Rows are locked in ascending token order first, the order every teaching
		// takes, so the prune cannot deadlock against one.
		$stale = $db->prepare("DELETE FROM ibt_inbound_bayes_tokens WHERE ibt_token IN (
			SELECT ibt_token FROM ibt_inbound_bayes_tokens
			 WHERE ibt_token <> 0 AND ibt_spam_count + ibt_ham_count <= 1
			   AND ibt_last_seen_time < (now() AT TIME ZONE 'UTC') - make_interval(days => ?)
			 ORDER BY ibt_token FOR UPDATE)");
		$stale->execute(array($stale_days));
		$overflow = 0;
		$rows = intval($db->query('SELECT COUNT(*) FROM ibt_inbound_bayes_tokens')->fetchColumn());
		if ($rows > $max_rows) {
			$cut = $db->prepare('DELETE FROM ibt_inbound_bayes_tokens WHERE ibt_token IN (
				SELECT t.ibt_token FROM ibt_inbound_bayes_tokens t
				 WHERE t.ibt_token IN (
					SELECT ibt_token FROM ibt_inbound_bayes_tokens WHERE ibt_token <> 0
					ORDER BY (ibt_spam_count + ibt_ham_count) ASC, ibt_last_seen_time ASC LIMIT ?)
				 ORDER BY t.ibt_token FOR UPDATE)');
			$cut->execute(array($rows - $max_rows));
			$overflow = $cut->rowCount();
		}
		return array('stale' => $stale->rowCount(), 'overflow' => $overflow);
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/** The first MAX_TEXT_BYTES of a text, never ending inside a UTF-8 character. */
	private static function cut(string $s): string {
		if (strlen($s) <= self::MAX_TEXT_BYTES) {
			return $s;
		}
		return function_exists('mb_strcut') ? mb_strcut($s, 0, self::MAX_TEXT_BYTES, 'UTF-8') : substr($s, 0, self::MAX_TEXT_BYTES);
	}

	private static function lower(string $s): string {
		return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
	}

	/**
	 * ARRAY[?, ?, ...]::bigint[] with one placeholder per token. Each hash is
	 * bound as an integer of its own, never folded into one long string: a
	 * process that opened sealed content may write short values only
	 * (SealedEgressGuard), and a token hash is a number, not a copy of content.
	 */
	private static function intArray(int $n): string {
		return 'ARRAY[' . implode(',', array_fill(0, max(1, $n), '?')) . ']::bigint[]';
	}

	/** Bind $leading values first, then each integer, in placeholder order. */
	private static function bindInts(PDOStatement $stmt, array $ints, array $leading = array()): void {
		$i = 1;
		foreach ($leading as $v) {
			$stmt->bindValue($i++, $v, PDO::PARAM_STR);
		}
		foreach ($ints as $v) {
			$stmt->bindValue($i++, intval($v), PDO::PARAM_INT);
		}
	}

	private static function db(): PDO {
		return DbConnector::get_instance()->get_db_link();
	}
}
?>
