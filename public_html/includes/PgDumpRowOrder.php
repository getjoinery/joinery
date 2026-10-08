<?php
/**
 * PgDumpRowOrder — puts the rows of a pg_dump COPY block in key order.
 *
 * pg_dump writes a table's rows in the order they sit on disk, and an updated
 * row moves. The install SQL is committed and compared across publishes
 * (specs/release_transparency.md D2), so a row merely updated on the
 * publishing site showed as a change in the file. Sorting each COPY block by
 * the table's primary key makes the dump a function of the rows alone.
 *
 * In COPY text format each row is one line (tabs and newlines inside a value
 * are escaped), so a row is a line and a field is a tab-separated part of it.
 *
 * @version 1.0
 */

class PgDumpRowOrder {

	/**
	 * $dump with the rows of every COPY block sorted by $key_columns, compared
	 * as integers when both values are integers and as text otherwise. A table
	 * with no key is sorted by its first column the same way. Rows still equal
	 * fall back to the whole line. Everything outside the blocks is unchanged.
	 *
	 * @param string   $dump        pg_dump --data-only output
	 * @param string[] $key_columns the table's primary key columns, in key order ([] for none: first column)
	 */
	public static function sort(string $dump, array $key_columns): string {
		$lines = explode("\n", $dump);
		$out = array();
		$count = count($lines);
		for ($i = 0; $i < $count; $i++) {
			$out[] = $lines[$i];
			if (!preg_match('/^COPY \S+ \((.*)\) FROM stdin;$/', $lines[$i], $m)) {
				continue;
			}
			$positions = self::positions(array_map('trim', explode(',', $m[1])), $key_columns);
			$rows = array();
			for ($i++; $i < $count && $lines[$i] !== '\\.'; $i++) {
				$rows[] = $lines[$i];
			}
			usort($rows, function ($a, $b) use ($positions) {
				return self::compare($a, $b, $positions);
			});
			foreach ($rows as $row) {
				$out[] = $row;
			}
			if ($i < $count) {
				$out[] = $lines[$i];   // the \. terminator
			}
		}
		return implode("\n", $out);
	}

	/** Field indexes of the key columns in the COPY column list (pg_dump may quote a name). */
	private static function positions(array $columns, array $key_columns): array {
		if (!$key_columns) {
			return array(0);
		}
		$columns = array_map(function ($c) { return trim($c, '"'); }, $columns);
		$positions = array();
		foreach ($key_columns as $key) {
			$at = array_search($key, $columns, true);
			if ($at === false) {
				return array(0);   // a key not in the list: order by the first column
			}
			$positions[] = $at;
		}
		return $positions;
	}

	private static function compare(string $a, string $b, array $positions): int {
		if ($positions) {
			$fa = explode("\t", $a);
			$fb = explode("\t", $b);
			foreach ($positions as $p) {
				$va = $fa[$p] ?? '';
				$vb = $fb[$p] ?? '';
				if (preg_match('/^-?\d+$/', $va) && preg_match('/^-?\d+$/', $vb)) {
					$c = (int)$va <=> (int)$vb;
				} else {
					$c = strcmp($va, $vb);
				}
				if ($c !== 0) {
					return $c;
				}
			}
		}
		return strcmp($a, $b);
	}
}
