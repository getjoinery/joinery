<?php
/**
 * SqlCondition - a collection filter whose values are bound, never written
 * into the query.
 *
 * A Multi class's getMultiResults() hands _get_resultsv2() a list of filters,
 * column => condition. A condition is one of three things:
 *
 *   - array($value, PDO::PARAM_*)      column = that value, bound
 *   - a string written in the class    'IS NULL', '= TRUE', "IN ('a', 'b')"
 *   - a SqlCondition                   anything that carries a value
 *
 * A string condition goes into the query as written, so _get_resultsv2()
 * accepts one only when that exact text is a quoted string in the collection's
 * own source file. A condition that depends on a value therefore cannot be a
 * string at all: it is a SqlCondition, whose text is written and whose values
 * travel as parameters.
 *
 *   $filters['pro_name']       = SqlCondition::bind('ILIKE ?', '%' . $term . '%');
 *   $filters['fch_change_id']  = SqlCondition::bind('> ?', (int)$cursor);
 *   $filters['usr_user_id']    = SqlCondition::bind('IN (?)', $ids);
 *   $filters['(evs_end_time']  = SqlCondition::bind('> ? OR evs_end_time IS NULL)', $now);
 *
 * Each ? takes the next value. A value that is a list fills its ? with one
 * parameter per member; an empty list becomes NULL, so `IN (?)` matches no
 * row. A value binds by what it is: an integer as a number, TRUE or FALSE as a
 * boolean, NULL as NULL, anything else as text. The text of the condition is
 * held to the same rule as a string condition: it must be written in the
 * collection's source.
 *
 * trusted() is for a condition some other code composed as SQL (an access
 * rule's subquery). Nothing checks it, so every caller is listed in
 * tests/unit/multi_filter_option_sql_test.php, which fails on a new one.
 *
 * @version 1.0
 */
final class SqlCondition {

	/** @var string */
	private $template;
	/** @var array */
	private $values;
	/** @var bool */
	private $trusted;

	private function __construct(string $template, array $values, bool $trusted) {
		$this->template = $template;
		$this->values = $values;
		$this->trusted = $trusted;
	}

	/**
	 * A condition whose values are bound.
	 *
	 * @param string $template The condition as it follows the column name, one ? per value.
	 * @param mixed  ...$values One per ?: a scalar, NULL, or a list of them.
	 * @throws InvalidArgumentException when the values do not match the ?s
	 */
	public static function bind(string $template, ...$values): self {
		if (substr_count($template, '?') !== count($values)) {
			throw new InvalidArgumentException('SqlCondition: "' . $template . '" has '
				. substr_count($template, '?') . ' placeholder(s) and was given ' . count($values) . ' value(s).');
		}
		foreach ($values as $value) {
			foreach (is_array($value) ? $value : array($value) as $member) {
				if ($member !== null && !is_scalar($member)) {
					throw new InvalidArgumentException('SqlCondition: "' . $template
						. '" was given a value that is not a number, text, TRUE, FALSE or NULL.');
				}
			}
		}
		return new self($template, $values, false);
	}

	/**
	 * SQL composed by other code, taken as it is. See the class comment.
	 */
	public static function trusted(string $sql): self {
		return new self($sql, array(), true);
	}

	/** The condition's text before its lists are spread out. */
	public function template(): string {
		return $this->template;
	}

	public function is_trusted(): bool {
		return $this->trusted;
	}

	/**
	 * The SQL to put after the column name and the parameters it takes, in order.
	 *
	 * @return array array($sql, array(array($value, PDO::PARAM_*), ...))
	 */
	public function expand(): array {
		if ($this->values === array()) {
			return array($this->template, array());
		}
		$pieces = explode('?', $this->template);
		$sql = array_shift($pieces);
		$params = array();
		foreach ($this->values as $i => $value) {
			if (is_array($value)) {
				$marks = array();
				foreach ($value as $member) {
					$marks[] = '?';
					$params[] = array($member, self::pdo_type($member));
				}
				$sql .= $marks ? implode(', ', $marks) : 'NULL';
			} else {
				$sql .= '?';
				$params[] = array($value, self::pdo_type($value));
			}
			$sql .= $pieces[$i];
		}
		return array($sql, $params);
	}

	private static function pdo_type($value): int {
		if (is_int($value)) {
			return PDO::PARAM_INT;
		}
		if (is_bool($value)) {
			return PDO::PARAM_BOOL;
		}
		if ($value === null) {
			return PDO::PARAM_NULL;
		}
		return PDO::PARAM_STR;
	}
}
