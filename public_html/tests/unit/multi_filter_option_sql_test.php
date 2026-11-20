<?php
/** @joinery-test
 * name: multi_filter_option_sql
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A collection's option value never becomes SQL text.
 *
 * A Multi class's getMultiResults() turns its options into filters. A filter
 * is either a bound value (array($value, PDO::PARAM_*)) or a condition string
 * that goes into the query as written. The REST collection endpoint hands a
 * request's whole query string to the class as its options (api/apiv1.php), so
 * an option joined into a condition string is the caller's text in the query.
 *
 * This reads every data class and fails on a filter line that mentions an
 * option in any form but these:
 *
 *   - the filter is a bound array
 *   - the option is cast to a number: (int), intval(), floatval()
 *   - the option goes through the connection's quote()
 *   - the option only chooses between two written conditions (a ternary), or
 *     is tested (isset, === null)
 *   - the option is turned into a time by strtotime() and formatted
 *
 * It reads one line at a time: a filter built over several statements is not
 * seen here. ALLOWED names the lines that are safe for a reason this cannot
 * read, each with that reason.
 *
 * Run: php tests/unit/multi_filter_option_sql_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** file (relative) => option key => why the line is safe. */
const ALLOWED = array(
	'data/page_probe_grants_class.php' => array(
		'expired_before' => 'the same if() holds it to the shape YYYY-MM-DD HH:MM:SS',
	),
);

/** Whether a filter line uses its options only in the safe forms. */
function filter_line_is_safe(string $line, array $allowed_keys): bool {
	// The value assigned: everything after the first '=' that follows $filters[...].
	if (!preg_match('/\$filters\[[^\]]+\]\s*=\s*(.*)$/s', $line, $m)) {
		return true;
	}
	$value = $m[1];
	if (strpos($value, '$this->options[') === false) {
		return true;
	}
	// A bound array: the value never reaches the SQL text.
	if (preg_match('/^(\[|array\s*\()/', $value)) {
		return true;
	}
	foreach ($allowed_keys as $key) {
		$value = str_replace("\$this->options['" . $key . "']", 'ALLOWED', $value);
	}
	$opt = '\$this->options\[[^\]]+\]';
	$safe = array(
		'/\(int\)\s*' . $opt . '/',
		'/\b(intval|floatval)\(\s*' . $opt . '\s*\)/',
		'/strtotime\(\s*' . $opt . '\s*\)/',
		// quote( ... option ... ) with nothing but string pieces around it
		'/->quote\((?:[^()]|\((?:string|int)\))*' . $opt . '[^()]*\)/',
		'/isset\(\s*' . $opt . '\s*\)/',
		'/' . $opt . '\s*(===|!==)\s*(null|NULL|true|false|TRUE|FALSE)/',
		// option ? 'written' : 'written'
		'/' . $opt . '\s*\)?\s*\?\s*(\'[^\'$]*\'|"[^"$]*")\s*:\s*(\'[^\'$]*\'|"[^"$]*")/',
		// the option alone at the end of the line: the test of a ternary whose
		// two written conditions follow on the next lines
		'/^' . $opt . '\s*\??\s*$/',
	);
	$value = preg_replace($safe, 'SAFE', $value);
	return strpos($value, '$this->options[') === false;
}

section('The rule reads the forms it should');
check(!filter_line_is_safe("\$filters['a_name'] = 'ILIKE \\'%'.\$this->options['name_like'].'%\\'';", array()),
	'a joined LIKE is refused');
check(!filter_line_is_safe("\$filters['a_id'] = '!= '.\$this->options['not'];", array()),
	'a joined comparison is refused');
check(!filter_line_is_safe("\$filters['a_rank'] = \$this->options['rank'];", array()),
	'a bare option as the condition is refused');
check(filter_line_is_safe("\$filters['a_id'] = [\$this->options['id'], PDO::PARAM_INT];", array()),
	'a bound array passes');
check(filter_line_is_safe("\$filters['a_name'] = 'ILIKE ' . \$dblink->quote('%' . (string)\$this->options['like'] . '%');", array()),
	'a quoted value passes');
check(filter_line_is_safe("\$filters['a_id'] = '> ' . (int)\$this->options['after'];", array()),
	'a number cast passes');
check(filter_line_is_safe("\$filters['a_on'] = \$this->options['on'] ? '= TRUE' : '= FALSE';", array()),
	'a choice between two written conditions passes');

section('No data class joins an option into a condition');
$root = dirname(__DIR__, 2);
$files = array_merge(
	glob($root . '/data/*_class.php') ?: array(),
	glob($root . '/plugins/*/data/*_class.php') ?: array()
);
check(count($files) > 100, 'data classes were found', count($files) . ' files');
$bad = array();
foreach ($files as $file) {
	$rel = substr($file, strlen($root) + 1);
	$allowed = array_keys(ALLOWED[$rel] ?? array());
	$in_comment = false;
	foreach (file($file) as $i => $line) {
		// A /* ... */ block is not code.
		if ($in_comment) {
			$in_comment = strpos($line, '*/') === false;
			continue;
		}
		if (preg_match('~^\s*/\*~', $line) && strpos($line, '*/') === false) {
			$in_comment = true;
			continue;
		}
		if (strpos($line, '$filters[') === false || strpos($line, '$this->options[') === false) {
			continue;
		}
		if (preg_match('/^\s*(\/\/|\*|#)/', $line)) {
			continue;
		}
		if (!filter_line_is_safe(rtrim($line), $allowed)) {
			$bad[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
		}
	}
}
check($bad === array(), 'every filter built from an option is bound, cast, quoted or a written choice',
	implode("\n", $bad));

harness_finish();
