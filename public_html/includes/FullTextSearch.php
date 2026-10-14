<?php
/**
 * FullTextSearch — Postgres full-text search over a text column.
 *
 * Postgres uses a full-text index only when the query's expression is exactly
 * the index's expression, which is easy to get wrong twice. So both come from
 * here: a data class declares its index as
 *
 *     public static $index_specifications = array(
 *         array('columns' => array("to_tsvector('english', fpo_body)"), 'method' => 'gin'),
 *     );
 *
 * which is literally FullTextSearch::indexSpec('fpo_body') — a property default
 * cannot call a method, so the entry is written out, and the consumer's test
 * asserts the two are equal. The query side calls match(), rank() and
 * snippet(); update_database creates the index, no migration needed.
 *
 * The language is one constant. Changing it changes every index expression, so
 * it is not a setting: a site that flipped one would silently stop using its
 * indexes until the next update_database rebuilt them.
 *
 * A caller searching posts other people wrote always ANDs match() with
 * SpaceAccess::readableSql(); a search that skips the access filter is a defect.
 *
 * Mailbox search (sealed, its own index) and Drive content search stay as they are.
 *
 * @version 1.1.0 - snippet marks matches found in the plain text, never inside an escaped entity
 * @version 1.0.0
 */

class FullTextSearch {

	/** The text-search configuration every index and query uses. */
	const CONFIG = 'english';

	/** Longest query accepted; anything past it is ignored. */
	const MAX_QUERY_LENGTH = 200;

	/** The $index_specifications entry for a column. */
	public static function indexSpec(string $column): array {
		return array('columns' => array(self::expression($column)), 'method' => 'gin');
	}

	/** The indexed expression for a column (a bare or alias-qualified name). */
	public static function expression(string $column): string {
		self::assertColumn($column);
		return "to_tsvector('" . self::CONFIG . "', " . $column . ')';
	}

	/**
	 * The SQL condition "this column matches the query", with its one bound
	 * parameter. websearch_to_tsquery accepts what people type: quoted phrases,
	 * `or`, and -exclusions, and never fails on stray punctuation.
	 *
	 * @return array{0:string,1:array} [sql, params]
	 */
	public static function match(string $column, string $query): array {
		return array(self::expression($column) . " @@ websearch_to_tsquery('" . self::CONFIG . "', ?)",
			array(self::clean($query)));
	}

	/**
	 * A relevance score for ordering best match first, with its bound parameter.
	 *
	 * @return array{0:string,1:array} [sql, params]
	 */
	public static function rank(string $column, string $query): array {
		return array('ts_rank(' . self::expression($column) . ", websearch_to_tsquery('" . self::CONFIG . "', ?))",
			array(self::clean($query)));
	}

	/** True when the query has something to search for. */
	public static function isSearchable(string $query): bool {
		return count(self::terms($query)) > 0;
	}

	/**
	 * A plain-text excerpt of $text around the first matched word, HTML-escaped,
	 * with each matched word wrapped in <mark>. Everything else is escaped text,
	 * so the result is safe to print as HTML.
	 */
	public static function snippet(string $text, string $query, int $length = 220): string {
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		$terms = self::terms($query);
		$start = 0;
		if ($terms) {
			$first = null;
			foreach ($terms as $term) {
				$pos = mb_stripos($text, $term);
				if ($pos !== false && ($first === null || $pos < $first)) {
					$first = $pos;
				}
			}
			if ($first !== null) {
				$start = max(0, $first - (int)($length / 3));
				// Begin on a word, not part-way through one.
				if ($start > 0) {
					$space = mb_strpos($text, ' ', $start);
					$start = ($space !== false && $space < $first) ? $space + 1 : $start;
				}
			}
		}
		$excerpt = mb_substr($text, $start, $length);
		$prefix = $start > 0 ? '… ' : '';
		$suffix = ($start + $length) < mb_strlen($text) ? ' …' : '';

		// Find the matches in the plain text, then escape each piece on its own:
		// marking inside escaped text would split entities ("&amp;" for "amp").
		$html = htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');
		if ($terms) {
			$quoted = array_map(function ($t) { return preg_quote($t, '/'); }, $terms);
			$parts = preg_split('/(' . implode('|', $quoted) . ')/iu', $excerpt, -1, PREG_SPLIT_DELIM_CAPTURE);
			if ($parts !== false) {
				$html = '';
				foreach ($parts as $i => $part) {
					$escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
					$html .= ($i % 2 === 1) ? '<mark>' . $escaped . '</mark>' : $escaped;
				}
			}
		}
		return $prefix . $html . $suffix;
	}

	/** The words of a query worth highlighting: no operators, no exclusions. */
	public static function terms(string $query): array {
		$query = self::clean($query);
		$words = preg_split('/[\s"]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
		$out = array();
		foreach ($words as $word) {
			if ($word[0] === '-' || strtolower($word) === 'or') {
				continue;
			}
			$word = trim($word, " \t\n\r\0\x0B.,;:!?()[]{}'*");
			if (mb_strlen($word) >= 2) {
				$out[mb_strtolower($word)] = $word;
			}
		}
		return array_values($out);
	}

	private static function clean(string $query): string {
		return mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
	}

	private static function assertColumn(string $column): void {
		if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
			throw new InvalidArgumentException('FullTextSearch: not a column name: ' . $column);
		}
	}
}
