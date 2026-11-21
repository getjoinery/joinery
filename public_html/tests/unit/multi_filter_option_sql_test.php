<?php
/** @joinery-test
 * name: multi_filter_option_sql
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A collection's filter never puts a value into SQL text.
 *
 * A Multi class's getMultiResults() builds a list of filters, column =>
 * condition, and _get_resultsv2() refuses, when the query runs, any column or
 * condition text that is not a quoted string in the class's own source
 * (SystemMultiBase::assert_filters_written). A value can therefore reach the
 * query only as a bound parameter, whatever path it took through the code.
 *
 * That refusal happens on the page that asks. This test is the same rule read
 * from the source, so a filter that would be refused is found here first:
 * every `$filters[...] = ...;` in every data class must be
 *
 *   - a bound array: array($value, PDO::PARAM_*)
 *   - one quoted string with no variable in it
 *   - SqlCondition::bind('one quoted string', values...)
 *   - SqlCondition::trusted(...), only in the files TRUSTED lists
 *   - a choice (a ? b : c) between those
 *
 * and its column must be one quoted string. It also proves SqlCondition
 * spreads its values the way the query needs, and that the source reader
 * returns the strings PHP would.
 *
 * Run: php tests/unit/multi_filter_option_sql_test.php
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/**
 * Every file that may call SqlCondition::trusted(), with how many times and
 * why the SQL it passes is not a caller's text. Nothing checks a trusted
 * condition, so a new caller is a decision, made here.
 */
const TRUSTED = array(
	'plugins/forum/data/forum_categories_class.php' => array(1,
		'SpaceAccess::readableSql() composes the access rule from the model\'s declarations and a user id it takes as an int'),
	'plugins/mailbox/data/inbound_imap_accounts_class.php' => array(1,
		'a piece of the DUE_SQL constant, which the poll task also uses'),
);

/** The tokens of one expression with whitespace and comments dropped. */
function expression_tokens(array $tokens, int &$j, int $count): array {
	$expr = array();
	$depth = 0;
	for (; $j < $count; $j++) {
		$t = $tokens[$j];
		if ($t === '(' || $t === '[' || $t === '{') {
			$depth++;
		}
		if ($t === ')' || $t === ']' || $t === '}') {
			$depth--;
		}
		if ($t === ';' && $depth === 0) {
			break;
		}
		if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			continue;
		}
		$expr[] = $t;
	}
	return $expr;
}

function is_constant_string($token): bool {
	return is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING;
}

/** Split an expression at the top-level occurrences of one single-character token. */
function split_top(array $expr, string $at): array {
	$parts = array(array());
	$depth = 0;
	foreach ($expr as $t) {
		if ($t === '(' || $t === '[') {
			$depth++;
		}
		if ($t === ')' || $t === ']') {
			$depth--;
		}
		if ($t === $at && $depth === 0) {
			$parts[] = array();
			continue;
		}
		$parts[count($parts) - 1][] = $t;
	}
	return $parts;
}

/**
 * Why a filter's condition is not in one of the allowed forms, or null when
 * it is. $trusted counts the trusted() calls it meets.
 */
function condition_fault(array $expr, int &$trusted): ?string {
	if (!$expr) {
		return 'nothing is assigned';
	}
	// A choice: condition ? a : b. The test before the ? is not SQL.
	$by_question = split_top($expr, '?');
	if (count($by_question) > 1) {
		$rest = array();
		foreach (array_slice($by_question, 1) as $i => $piece) {
			if ($i > 0) {
				$rest[] = '?';
			}
			$rest = array_merge($rest, $piece);
		}
		$branches = split_top($rest, ':');
		if (count($branches) !== 2) {
			return 'a choice with more than two answers';
		}
		foreach ($branches as $branch) {
			$fault = condition_fault($branch, $trusted);
			if ($fault !== null) {
				return $fault;
			}
		}
		return null;
	}
	$first = $expr[0];
	if ($first === '[' || (is_array($first) && $first[0] === T_ARRAY)) {
		return null;
	}
	if (count($expr) === 1 && is_constant_string($first)) {
		return null;
	}
	if (is_array($first) && $first[1] === 'SqlCondition'
			&& is_array($expr[1] ?? null) && $expr[1][0] === T_DOUBLE_COLON
			&& is_array($expr[2] ?? null) && ($expr[3] ?? null) === '(' && end($expr) === ')') {
		$method = $expr[2][1];
		$args = split_top(array_slice($expr, 4, -1), ',');
		if ($method === 'trusted') {
			$trusted++;
			return null;
		}
		if ($method !== 'bind') {
			return 'SqlCondition::' . $method . '() is not a condition';
		}
		$text = $args[0];
		// The text is one quoted string, or one of a written list of them
		// picked by key ($comparisons[$m[1]]): the runtime check holds the
		// picked string to the source either way.
		if (count($text) === 1 && is_constant_string($text[0])) {
			return null;
		}
		if (is_array($text[0] ?? null) && $text[0][0] === T_VARIABLE && ($text[1] ?? null) === '[') {
			return null;
		}
		return 'the text of SqlCondition::bind() is put together, not written as one quoted string';
	}
	return 'the condition is put together from pieces';
}

/** Every fault in one data class file's filters, as "line: what". */
function filter_faults(string $source, int &$trusted): array {
	$faults = array();
	$tokens = token_get_all($source);
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++) {
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$filters') {
			continue;
		}
		$line = $tokens[$i][2];
		$j = $i + 1;
		while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
			$j++;
		}
		if ($j >= $count || $tokens[$j] !== '[') {
			continue;
		}
		$key = array();
		$depth = 0;
		for (; $j < $count; $j++) {
			if ($tokens[$j] === '[') {
				$depth++;
				if ($depth === 1) {
					continue;
				}
			}
			if ($tokens[$j] === ']') {
				$depth--;
				if ($depth === 0) {
					break;
				}
			}
			if (!(is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE)) {
				$key[] = $tokens[$j];
			}
		}
		$j++;
		while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
			$j++;
		}
		// Only an assignment builds a filter: isset(), unset() and reads do not.
		if ($j >= $count || $tokens[$j] !== '=') {
			continue;
		}
		if (!(count($key) === 1 && is_constant_string($key[0]))) {
			$faults[] = $line . ': the column is not one quoted string';
		}
		$j++;
		$fault = condition_fault(expression_tokens($tokens, $j, $count), $trusted);
		if ($fault !== null) {
			$faults[] = $line . ': ' . $fault;
		}
	}
	return $faults;
}

section('The rule reads the forms it should');
$n = 0;
$case = function (string $php) use (&$n) {
	return filter_faults('<?php ' . $php, $n);
};
check($case("\$filters['a_name'] = 'ILIKE \\'%' . \$this->options['name_like'] . '%\\'';") !== array(), 'a joined LIKE is refused');
check($case("\$filters['a_id'] = '!= ' . \$this->options['not'];") !== array(), 'a joined comparison is refused');
check($case("\$filters['a_rank'] = \$this->options['rank'];") !== array(), 'a bare option as the condition is refused');
check($case("\$x = \$this->options['rank']; \$filters['a_rank'] = \"> \$x\";") !== array(), 'a value carried in through a variable is refused');
check($case("\$filters['a_id'] = '> ' . (int)\$this->options['after'];") !== array(), 'a number joined in is refused: it is bound instead');
check($case("\$filters['a_name'] = 'ILIKE ' . \$dblink->quote(\$v);") !== array(), 'a quoted value joined in is refused: it is bound instead');
check($case("\$filters[\$column] = array(\$v, PDO::PARAM_STR);") !== array(), 'a column taken from a variable is refused');
check($case("\$filters['a_id'] = SqlCondition::bind('> ' . \$op, 1);") !== array(), 'bind() text put together is refused');
check($case("\$filters['a_id'] = [\$this->options['id'], PDO::PARAM_INT];") === array(), 'a bound array passes');
check($case("\$filters['a_at'] = 'IS NULL';") === array(), 'a written condition passes');
check($case("\$filters['a_name'] = SqlCondition::bind('ILIKE ?', '%' . \$this->options['like'] . '%');") === array(), 'a bound condition passes');
check($case("\$filters['a_on'] = \$this->options['on'] ? '= TRUE' : '= FALSE';") === array(), 'a choice between two written conditions passes');
check($case("\$filters['a_id'] = \$ids ? SqlCondition::bind('IN (?)', \$ids) : 'IS NULL';") === array(), 'a choice between a bound and a written condition passes');
check($case("if (isset(\$filters['a_id'])) { unset(\$filters['a_id']); }") === array(), 'reading or unsetting a filter is not building one');

section('No data class puts a filter together from pieces');
$root = dirname(__DIR__, 2);
$files = array_merge(
	glob($root . '/data/*_class.php') ?: array(),
	glob($root . '/plugins/*/data/*_class.php') ?: array(),
	array($root . '/docs/example_class.php')
);
// includes/SandboxRecord.php is not read here: its collection takes each
// column from the model's declared fields and each value from a helper that
// returns the bound pair, which this reader cannot follow. The check that runs
// with the query holds it to the rule (tests/models/multi_models_test.php).
check(count($files) > 100, 'data classes were found', count($files) . ' files');
$bad = array();
$trusted_found = array();
foreach ($files as $file) {
	$rel = substr($file, strlen($root) + 1);
	$trusted = 0;
	foreach (filter_faults((string)file_get_contents($file), $trusted) as $fault) {
		$bad[] = $rel . ':' . $fault;
	}
	if ($trusted > 0) {
		$trusted_found[$rel] = $trusted;
	}
}
check($bad === array(), 'every filter is bound, written, or a choice between the two', implode("\n", $bad));

section('SqlCondition::trusted() is called only where this file says');
$callers = array();
$scan = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
	function ($f) {
		return !in_array($f->getFilename(), array('vendor', 'node_modules', '.git', 'specs', 'tests', 'docs'), true);
	}
));
foreach ($scan as $f) {
	if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && $f->getFilename() !== 'SqlCondition.php') {
		$hits = substr_count((string)file_get_contents($f->getPathname()), 'SqlCondition::trusted(');
		if ($hits > 0) {
			$callers[substr($f->getPathname(), strlen($root) + 1)] = $hits;
		}
	}
}
ksort($callers);
$expected = array_map(function ($entry) {
	return $entry[0];
}, TRUSTED);
ksort($expected);
check($callers === $expected, 'the callers are the listed ones', 'found ' . json_encode($callers));
check($trusted_found === $expected, 'and each is a filter in a data class', 'found ' . json_encode($trusted_found));

section('SqlCondition binds what it is given');
list($sql, $params) = SqlCondition::bind('ILIKE ?', '%a%')->expand();
check($sql === 'ILIKE ?' && $params === array(array('%a%', PDO::PARAM_STR)), 'text binds as text');
list($sql, $params) = SqlCondition::bind('> ?', 7)->expand();
check($sql === '> ?' && $params === array(array(7, PDO::PARAM_INT)), 'a number binds as a number');
list($sql, $params) = SqlCondition::bind('IN (?) OR x_owner = ?)', array(3, 4, 5), 9)->expand();
check($sql === 'IN (?, ?, ?) OR x_owner = ?)' && array_column($params, 0) === array(3, 4, 5, 9), 'a list takes one parameter per member, in order');
list($sql, $params) = SqlCondition::bind('IN (?)', array())->expand();
check($sql === 'IN (NULL)' && $params === array(), 'an empty list matches no row');
$injected = "0) OR (SELECT 1) = (1";
list($sql, $params) = SqlCondition::bind('IN (?)', array($injected))->expand();
check($sql === 'IN (?)' && $params[0][0] === $injected, 'a value never becomes part of the text');
$threw = false;
try {
	SqlCondition::bind('BETWEEN ? AND ?', 1);
} catch (InvalidArgumentException $e) {
	$threw = true;
}
check($threw, 'a value count that does not match the placeholders is refused');
$threw = false;
try {
	SqlCondition::bind('= ?', array(array(1)));
} catch (InvalidArgumentException $e) {
	$threw = true;
}
check($threw, 'a value that is not a scalar or a list of them is refused');

section('The source reader returns the strings PHP would');
$strings = SystemMultiBase::strings_written_in('<?php $a = \'IS NULL\'; $b = "IN (\'done\', \'failed\')"; $c = \'= \\\'x\\\'\'; $d = "> $v"; $e = "<= {$v} OR"; $f = \'a\' . $v;');
check(isset($strings['IS NULL']), 'a single-quoted string');
check(isset($strings["IN ('done', 'failed')"]), 'a double-quoted string holding quotes');
check(isset($strings["= 'x'"]), 'a single-quoted string with escaped quotes');
check(!isset($strings['> $v']) && !isset($strings['> ']) && !isset($strings['<= ']), 'a string with a variable in it is not a written string, nor are its pieces');

harness_finish();
