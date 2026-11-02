<?php
/** @joinery-test
 * name: stored_unserialize
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A stored value never turns into an object of a class it did not ask for.
 *
 * The pool writes the session files and the database, and code that ran once
 * in a worker can write them too. unserialize() with every class allowed
 * turns a planted object into a live one: its magic methods run in the
 * reader's request, and the autoloader finds whatever class it names. So every
 * unserialize() of stored data names the classes it may build: none for the
 * posted form fields and a question's validation rules, Address and stdClass
 * for an order item's product info, which the checkout stores that way
 * (v1_key_retirement C2).
 *
 * Run: php tests/security/stored_unserialize_test.php
 *
 * @version 1.1 - the scan catches \\unserialize() too (reviewer2 B3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** A class with every magic method an attacker would reach for; any of them running sets the flag. */
class StoredUnserializeCanary {
	public static $ran = array();
	public $payload = 'x';
	public function __wakeup() { self::$ran[] = '__wakeup'; }
	public function __unserialize(array $data): void { self::$ran[] = '__unserialize'; }
	public function __destruct() { self::$ran[] = '__destruct'; }
	public function __toString(): string { self::$ran[] = '__toString'; return ''; }
}

section('Every unserialize() in the tree names its classes');

// A tree-wide read: a new call written without allowed_classes fails here
// rather than reopening the channel. vendor/ and the tests are not ours to hold
// to it (the gadget surface vendor/ brings is unseal_daemon.md's C3).
$root = PathHelper::getIncludePath('');
$unflagged = array();
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
	function ($file) {
		$name = $file->getFilename();
		if ($file->isDir()) {
			return !in_array($name, array('vendor', 'node_modules', 'tests', '.git'), true);
		}
		return strtolower($file->getExtension()) === 'php';
	}
));
foreach ($iterator as $file) {
	if (!$file->isFile()) {
		continue;
	}
	$src = (string)file_get_contents($file->getPathname());
	if (strpos($src, 'unserialize') === false) {
		continue;
	}
	foreach (unflagged_unserialize_lines($src) as $line) {
		$unflagged[] = substr($file->getPathname(), strlen($root)) . ':' . $line;
	}
}
check($unflagged === array(), 'no unserialize() of stored data runs with every class allowed', implode(', ', $unflagged));

// The scan itself, on a fixture: a bare call is caught, a flagged one and a
// method of that name are not. Without this a scan that matched nothing would
// pass the check above.
$fixture = "<?php\n\$a = unserialize(\$x);\n\$b = unserialize(\$y, ['allowed_classes' => false]);\n\$c = \$o->unserialize(\$z);\nfunction __unserialize(array \$d) {}\n\$e = @unserialize(base64_decode(\$w));\n\$f = \\unserialize(\$v);\n";
check(unflagged_unserialize_lines($fixture) === array(2, 6, 7), 'the scan catches a bare call (qualified or not) and passes a flagged one and a method',
	json_encode(unflagged_unserialize_lines($fixture)));

/** Lines of unserialize() calls in $src whose argument list does not name allowed_classes. */
function unflagged_unserialize_lines($src) {
	$lines = array();
	$toks = token_get_all($src);
	$n = count($toks);
	foreach ($toks as $i => $tok) {
		// unserialize, or \unserialize (one token on PHP 8).
		if (!is_array($tok) || !in_array($tok[0], array(T_STRING, T_NAME_FULLY_QUALIFIED), true)
				|| strtolower(ltrim($tok[1], '\\')) !== 'unserialize') {
			continue;
		}
		// A method or a declaration named unserialize is not the builtin.
		$p = $i - 1;
		while ($p >= 0 && is_array($toks[$p]) && in_array($toks[$p][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			$p--;
		}
		if ($p >= 0 && is_array($toks[$p]) && in_array($toks[$p][0], array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
			continue;
		}
		// The call's own argument list, to its matching parenthesis.
		$k = $i + 1;
		while ($k < $n && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
			$k++;
		}
		if ($k >= $n || $toks[$k] !== '(') {
			continue;
		}
		$depth = 0;
		$args = '';
		for (; $k < $n; $k++) {
			$t = is_array($toks[$k]) ? $toks[$k][1] : $toks[$k];
			$args .= $t;
			if ($t === '(') {
				$depth++;
			} elseif ($t === ')' && --$depth === 0) {
				break;
			}
		}
		if (stripos($args, 'allowed_classes') === false) {
			$lines[] = $tok[2];
		}
	}
	return $lines;
}

section('An order item\'s product info: Address and stdClass only');

if (!class_exists('OrderItem') || !class_exists('Address')) {
	harness_skip('the store plugin is not active here', 'OrderItem does not resolve');
} else {
	// Shaped as the checkout stores it (cart_charge_logic): the posted fields
	// beside the buyer's Address, whose row is a stdClass inside it.
	$address = new Address(NULL);
	$address->key = 6333;
	$address->set('usa_address1', '1 Hunt Street');
	$address->set('usa_city', 'Testville');
	$address->set('usa_state', 'MA');
	$info = array(
		'full_name_first' => 'Huntc',
		'address' => $address,
		'extra' => (object)array('note' => 'kept'),
		'planted' => new StoredUnserializeCanary(),
	);
	$item = new OrderItem(NULL);
	$item->set('odi_product_info', base64_encode(serialize($info)));
	StoredUnserializeCanary::$ran = array();

	$raw = $item->get_raw_data();
	check(is_array($raw) && $raw['full_name_first'] === 'Huntc', 'the stored fields read back');
	check(isset($raw['address']) && $raw['address'] instanceof Address, 'the Address comes back an Address',
		is_object($raw['address'] ?? null) ? get_class($raw['address']) : gettype($raw['address'] ?? null));
	check(isset($raw['address']) && $raw['address'] instanceof Address
		&& (string)$raw['address']->key === '6333' && $raw['address']->get('usa_address1') === '1 Hunt Street'
		&& $raw['address']->get('usa_city') === 'Testville',
		'with its key and its fields, read through get()');
	check(($raw['extra'] ?? null) instanceof stdClass && $raw['extra']->note === 'kept', 'a stdClass comes back a stdClass');
	check(($raw['planted'] ?? null) instanceof __PHP_Incomplete_Class,
		'an object of any other class comes back incomplete',
		is_object($raw['planted'] ?? null) ? get_class($raw['planted']) : gettype($raw['planted'] ?? null));
	unset($raw);
	gc_collect_cycles();
	check(StoredUnserializeCanary::$ran === array(), 'and none of its magic methods ran', implode(',', StoredUnserializeCanary::$ran));

	$data = $item->get_data();
	check(is_array($data) && $data['full_name_first'] === 'Huntc', 'get_data() reads the same row');
	StoredUnserializeCanary::$ran = array();
}

section('The posted form fields kept in the session: no objects');

$session = SessionControl::get_instance();
$_SESSION['formname'] = 'stored_unserialize_test';
$_SESSION['formfields'] = serialize(array('name' => 'Ada', 'tags' => array('a', 'b'), 'planted' => new StoredUnserializeCanary()));
StoredUnserializeCanary::$ran = array();
$fields = $session->get_formfields_array('stored_unserialize_test');
check(is_array($fields) && $fields['name'] === 'Ada' && $fields['tags'] === array('a', 'b'), 'strings and arrays read back');
check(($fields['planted'] ?? null) instanceof __PHP_Incomplete_Class, 'a planted object comes back incomplete');
$obj = $session->get_formfields('stored_unserialize_test');
check(is_object($obj) && $obj->name === 'Ada' && $obj->planted instanceof __PHP_Incomplete_Class, 'get_formfields() too');
unset($fields, $obj);
gc_collect_cycles();
check(StoredUnserializeCanary::$ran === array(), 'and none of its magic methods ran', implode(',', StoredUnserializeCanary::$ran));
unset($_SESSION['formname'], $_SESSION['formfields']);

section('A question\'s validation rules: no objects');

if (!class_exists('Question')) {
	harness_skip('Question does not resolve', '');
} else {
	$question = new Question(NULL);
	$question->set('qst_type', 1);
	$question->set('qst_validate', serialize(array('required' => true, 'planted' => new StoredUnserializeCanary())));
	StoredUnserializeCanary::$ran = array();
	ob_start();
	try {
		$question->output_js_validation(array());
	} catch (Throwable $e) {
		// What the method writes is not under test; that the object stays inert is.
	}
	ob_end_clean();
	gc_collect_cycles();
	check(StoredUnserializeCanary::$ran === array(), 'reading the rules runs no magic method of a planted object',
		implode(',', StoredUnserializeCanary::$ran));
}

harness_finish();
