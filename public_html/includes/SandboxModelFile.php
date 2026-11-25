<?php
/**
 * SandboxModelFile — reads a sandboxed plugin's model file without running it.
 *
 * A sandboxed plugin declares its models the way a first-party plugin does: a
 * regular model file in src/data/ (spec sandboxed_plugin_model_files D1),
 *
 *   class Note extends SystemBase { public static $prefix = 'nte'; … function prepare() { … } }
 *   class MultiNote extends SystemMultiBase { protected static $model_class = 'Note'; }
 *
 * The site never includes that file. This class tokenizes it, inside the
 * parser jail (DocumentText::parseWith(), so the plugin's bytes meet PHP's
 * tokenizer in the jailed subprocess), and answers what the file declares as
 * JSON. It evaluates nothing: a static's value is read only when it is a
 * plain literal, and anything else in the file is refused, naming the line.
 * What the values may be is SandboxModelDeclarations' question, on the site,
 * over this answer.
 *
 * The answer:
 *
 *   {"ok": true, "classes": [
 *     {"name": "Note", "extends": "SystemBase", "line": 2,
 *      "statics": {"prefix": {"line": 3, "value": <value>}, …},
 *      "methods": {"prepare": 12, …}, "members": {"cache": 9, …}},
 *     {"name": "MultiNote", "extends": "SystemMultiBase", …}]}
 *   {"ok": false, "error": "line 4: …"}
 *
 * A <value> is typed, so JSON loses nothing: {"t":"s","v":"text"}, {"t":"i","v":3},
 * {"t":"f","v":"1.5"}, {"t":"b","v":true}, {"t":"n"}, {"t":"c","v":"User::USER_DELETED"}
 * (the one constant read, the native idiom for set_value), and
 * {"t":"a","v":[[<key>,<value>],…]} with each key a string or integer value.
 *
 * Refused, each naming the line: anything at the top level besides the two
 * classes (a require, a function, a statement, a namespace, a use, a declare);
 * text after a closing ?>; a class that extends anything else, or implements,
 * an interface, a trait, an enum; inside a class a trait use, an attribute, a
 * class constant; a static whose value is not a plain literal (a constant, a
 * call, a joined or interpolated string, a heredoc, a spread); a file over
 * 256 KB, or one the tokenizer cannot read.
 *
 * @version 1.1 - punctuation is matched by its token id, so a brace inside an interpolated string hides nothing;
 *                a float has at most 15 digits (reviewer1 R4, R5)
 * @version 1.0
 */
class SandboxModelFile implements SandboxParserInterface {

	/** The largest model file read. */
	const MAX_BYTES = 262144;

	/** The classes a model file's classes may extend, and nothing else. */
	const BASES = array('SystemBase', 'SystemMultiBase');

	/** @var PhpToken[] the file's tokens, comments and whitespace left out */
	private $tokens = array();

	/** @var int where the reading is */
	private $at = 0;

	public static function sandboxParse(string $bytes, array $options): string {
		return json_encode(self::read($bytes), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/**
	 * What one model file declares, or why it is refused. Runs where it is
	 * called: the site calls it through DocumentText::parseWith() only.
	 *
	 * @return array{ok:bool, classes?:array, error?:string}
	 */
	public static function read(string $code): array {
		if (strlen($code) > self::MAX_BYTES) {
			return array('ok' => false, 'error' => 'the file is larger than 256 KB');
		}
		try {
			$tokens = PhpToken::tokenize($code, TOKEN_PARSE);
		} catch (Throwable $e) {
			// The tokenizer's own words name the line, never the file's text.
			return array('ok' => false, 'error' => 'line ' . (int)$e->getLine() . ': PHP cannot read this file (' . self::clip($e->getMessage()) . ')');
		}
		$reader = new self();
		try {
			return array('ok' => true, 'classes' => $reader->file($tokens));
		} catch (SandboxModelFileRefusal $e) {
			return array('ok' => false, 'error' => $e->getMessage());
		}
	}

	/** @return array the file's classes */
	private function file(array $all): array {
		if (!$all || $all[0]->id !== T_OPEN_TAG) {
			throw new SandboxModelFileRefusal('line 1: a model file starts with <?php and holds nothing before it');
		}
		foreach ($all as $i => $token) {
			if ($i > 0 && $token->id === T_OPEN_TAG) {
				throw new SandboxModelFileRefusal('line ' . $token->line . ': a model file has one <?php and no text outside PHP');
			}
			if ($token->id === T_INLINE_HTML) {
				throw new SandboxModelFileRefusal('line ' . $token->line . ': a model file holds no text outside PHP');
			}
			if ($token->id === T_CLOSE_TAG) {
				if ($i !== count($all) - 1) {
					throw new SandboxModelFileRefusal('line ' . $token->line . ': nothing may follow the closing ?> (leave it out)');
				}
				continue;
			}
			if ($i === 0 || in_array($token->id, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
				continue;
			}
			$this->tokens[] = $token;
		}

		$classes = array();
		while ($this->at < count($this->tokens)) {
			$token = $this->tokens[$this->at];
			if ($token->id === T_CLASS) {
				$classes[] = $this->classDeclaration();
				continue;
			}
			throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . self::topLevelWords($token));
		}
		if (!$classes) {
			throw new SandboxModelFileRefusal('line 1: a model file declares a model class and its Multi class');
		}
		return $classes;
	}

	/** Why a top-level token is refused, in words that say what to do. */
	private static function topLevelWords(PhpToken $token): string {
		switch ($token->id) {
			case T_REQUIRE: case T_REQUIRE_ONCE: case T_INCLUDE: case T_INCLUDE_ONCE:
				return 'a model file includes no other file (classes resolve by name)';
			case T_NAMESPACE:
				return 'a model file has no namespace';
			case T_USE:
				return 'a model file imports nothing (no use statement)';
			case T_DECLARE:
				return 'a model file declares nothing at the top (leave out declare(strict_types=1);)';
			case T_FUNCTION: case T_FN:
				return 'a model file holds no function outside its classes';
			case T_INTERFACE: case T_TRAIT: case T_ENUM:
				return 'a model file holds only its model class and its Multi class (no ' . strtolower($token->text) . ')';
			case T_ABSTRACT: case T_FINAL: case T_READONLY:
				return 'a model class is written class Name extends SystemBase, with no ' . strtolower($token->text);
			case T_ATTRIBUTE:
				return 'a model file has no attributes';
		}
		return 'a model file holds only its two classes; "' . self::clip($token->text) . '" is outside them';
	}

	private function classDeclaration(): array {
		$class_token = $this->take();
		$name = $this->take();
		if ($name->id !== T_STRING) {
			throw new SandboxModelFileRefusal('line ' . $class_token->line . ': a class needs a name');
		}
		$extends = $this->take();
		if ($extends === null || $extends->id !== T_EXTENDS) {
			throw new SandboxModelFileRefusal('line ' . $name->line . ': ' . $name->text . ' must extend SystemBase (a model) or SystemMultiBase (its collection)');
		}
		$base = $this->take();
		if ($base === null || $base->id !== T_STRING || !in_array($base->text, self::BASES, true)) {
			throw new SandboxModelFileRefusal('line ' . $name->line . ': ' . $name->text . ' extends '
				. ($base === null ? 'nothing' : self::clip($base->text)) . '; a class here extends SystemBase or SystemMultiBase, written as such');
		}
		$open = $this->take();
		if ($open !== null && $open->id === T_IMPLEMENTS) {
			throw new SandboxModelFileRefusal('line ' . $name->line . ': ' . $name->text . ' implements an interface; a class here implements nothing');
		}
		if ($open === null || !self::is($open, '{')) {
			throw new SandboxModelFileRefusal('line ' . $name->line . ': ' . $name->text . '\'s body must follow extends ' . $base->text);
		}
		$class = array('name' => $name->text, 'extends' => $base->text, 'line' => $name->line,
			'statics' => array(), 'methods' => array(), 'members' => array());
		while (true) {
			$token = $this->peek();
			if ($token === null) {
				throw new SandboxModelFileRefusal('line ' . $name->line . ': ' . $name->text . ' is not closed');
			}
			if (self::is($token, '}')) {
				$this->at++;
				return $class;
			}
			$this->member($class);
		}
	}

	/** One member of a class body: a property declaration or a method. */
	private function member(array &$class): void {
		$first = $this->peek();
		$modifiers = array();
		while (($token = $this->peek()) !== null
				&& in_array($token->id, array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY, T_VAR), true)) {
			$modifiers[] = strtolower($token->text);
			$this->at++;
		}
		$token = $this->peek();
		if ($token === null) {
			throw new SandboxModelFileRefusal('line ' . $first->line . ': ' . $class['name'] . ' is not closed');
		}
		switch ($token->id) {
			case T_USE:
				throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $class['name'] . ' uses a trait; a model\'s methods and properties are written in it');
			case T_ATTRIBUTE:
				throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $class['name'] . ' has an attribute; leave it out');
			case T_CONST:
				throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $class['name'] . ' declares a class constant; a sandboxed model has none');
			case T_FUNCTION:
				$this->at++;
				$method = $this->take();
				if ($method === null || !preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~D', $method->text)) {
					throw new SandboxModelFileRefusal('line ' . $token->line . ': a method needs a name');
				}
				$class['methods'][$method->text] = $method->line;
				$this->skipMethod($method);
				return;
		}
		if (!$modifiers) {
			throw new SandboxModelFileRefusal('line ' . $token->line . ': "' . self::clip($token->text) . '" is not a property or a method of ' . $class['name']);
		}
		// A typed property: the type, then the variable.
		while (($t = $this->peek()) !== null && $t->id !== T_VARIABLE && !self::is($t, ';')) {
			if (!in_array($t->id, array(T_STRING, T_ARRAY, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CALLABLE), true)
					&& !self::isAny($t, array('?', '|', '&', '(', ')'))) {
				throw new SandboxModelFileRefusal('line ' . $t->line . ': "' . self::clip($t->text) . '" is not a property of ' . $class['name']);
			}
			$this->at++;
		}
		$static = in_array('static', $modifiers, true);
		while (true) {
			$variable = $this->take();
			if ($variable === null || $variable->id !== T_VARIABLE) {
				throw new SandboxModelFileRefusal('line ' . $token->line . ': a property of ' . $class['name'] . ' needs a name');
			}
			$property = substr($variable->text, 1);
			$next = $this->take();
			$value = array('t' => 'n');
			if ($next !== null && self::is($next, '=')) {
				if ($static) {
					$value = $this->literal($class['name'] . '::$' . $property);
				} else {
					$this->skipExpression();
				}
				$next = $this->take();
			}
			if ($static) {
				if (isset($class['statics'][$property])) {
					throw new SandboxModelFileRefusal('line ' . $variable->line . ': ' . $class['name'] . '::$' . $property . ' is declared twice');
				}
				$class['statics'][$property] = array('line' => $variable->line, 'value' => $value);
			} else {
				$class['members'][$property] = $variable->line;
			}
			if ($next !== null && self::is($next, ',')) {
				continue;
			}
			if ($next !== null && $static && !self::is($next, ';')) {
				throw new SandboxModelFileRefusal('line ' . $next->line . ': ' . $class['name'] . '::$' . $property
					. ' is not a plain value ("' . self::clip($next->text) . '" follows it): write the value out whole, with nothing joined or worked out');
			}
			if ($next === null || !self::is($next, ';')) {
				throw new SandboxModelFileRefusal('line ' . $variable->line . ': ' . $class['name'] . '::$' . $property . ' is not a plain declaration');
			}
			return;
		}
	}

	/**
	 * A plain literal: a quoted string with nothing joined into it, a number,
	 * true, false, null, User::USER_DELETED, or an array of these with literal
	 * keys.
	 */
	private function literal(string $what): array {
		$token = $this->take();
		if ($token === null) {
			throw new SandboxModelFileRefusal($what . ' has no value');
		}
		$refuse = function (PhpToken $t) use ($what) {
			throw new SandboxModelFileRefusal('line ' . $t->line . ': ' . $what . ' is not a plain value ("' . self::clip($t->text)
				. '"): a static is a quoted string, a number, true, false, null or an array of these, written out');
		};
		if (self::is($token, '"') || $token->id === T_START_HEREDOC) {
			throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $what . ' is a string with something joined into it, or a heredoc; write it as one quoted string');
		}
		switch ($token->id) {
			case T_CONSTANT_ENCAPSED_STRING:
				$string = self::stringValue($token);
				if ($string === null) {
					throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $what . ' is a double-quoted string with a backslash in it; write it in single quotes');
				}
				return array('t' => 's', 'v' => $string);
			case T_LNUMBER:
				return self::integerValue($token, false, $what);
			case T_DNUMBER:
				return self::floatValue($token, false, $what);
			case T_ARRAY:
				$open = $this->take();
				if ($open === null || !self::is($open, '(')) {
					$refuse($token);
				}
				return $this->arrayBody(')', $what);
			case T_STRING:
				$word = strtolower($token->text);
				if ($word === 'true' || $word === 'false') {
					return array('t' => 'b', 'v' => $word === 'true');
				}
				if ($word === 'null') {
					return array('t' => 'n');
				}
				if ($token->text === 'User' && ($c = $this->peek()) !== null && $c->id === T_DOUBLE_COLON) {
					$this->at++;
					$constant = $this->take();
					if ($constant !== null && $constant->text === 'USER_DELETED') {
						return array('t' => 'c', 'v' => 'User::USER_DELETED');
					}
				}
				$refuse($token);
		}
		if (self::is($token, '[')) {
			return $this->arrayBody(']', $what);
		}
		if (self::is($token, '-')) {
			$number = $this->take();
			if ($number !== null && $number->id === T_LNUMBER) {
				return self::integerValue($number, true, $what);
			}
			if ($number !== null && $number->id === T_DNUMBER) {
				return self::floatValue($number, true, $what);
			}
		}
		$refuse($token);
	}

	private function arrayBody(string $close, string $what): array {
		$items = array();
		while (true) {
			$token = $this->peek();
			if ($token === null) {
				throw new SandboxModelFileRefusal($what . ': an array is not closed');
			}
			if (self::is($token, $close)) {
				$this->at++;
				return array('t' => 'a', 'v' => $items);
			}
			if ($token->id === T_ELLIPSIS || self::is($token, '&')) {
				throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $what . ' spreads or refers to another value; write the array out');
			}
			$first = $this->literal($what);
			$next = $this->peek();
			if ($next !== null && $next->id === T_DOUBLE_ARROW) {
				$this->at++;
				if (!in_array($first['t'], array('s', 'i'), true)) {
					throw new SandboxModelFileRefusal('line ' . $next->line . ': ' . $what . ' has an array key that is not a string or a whole number');
				}
				$items[] = array($first, $this->literal($what));
			} else {
				$items[] = array(null, $first);
			}
			$next = $this->peek();
			if ($next !== null && self::is($next, ',')) {
				$this->at++;
				continue;
			}
			if ($next === null || !self::is($next, $close)) {
				$at = $next ?? $token;
				throw new SandboxModelFileRefusal('line ' . $at->line . ': ' . $what . ' is not a plain value ("' . self::clip($at->text) . '" in an array)');
			}
		}
	}

	/** A single- or double-quoted string's value; null for a double-quoted one with a backslash. */
	private static function stringValue(PhpToken $token): ?string {
		$text = $token->text;
		$quote = $text[0];
		if ($quote === 'b' || $quote === 'B') {
			$text = substr($text, 1);
			$quote = $text[0];
		}
		$inner = substr($text, 1, -1);
		if ($quote === "'") {
			return strtr($inner, array('\\\\' => '\\', "\\'" => "'"));
		}
		// The lexer gives a double-quoted string this token only with nothing
		// joined into it; its escapes are read only when there are none.
		return strpos($inner, '\\') === false ? $inner : null;
	}

	private static function integerValue(PhpToken $token, bool $negative, string $what): array {
		$digits = str_replace('_', '', $token->text);
		if (!preg_match('~^(0|[1-9][0-9]{0,17})$~D', $digits)) {
			throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $what . ' has a number written as "' . self::clip($token->text) . '"; write whole numbers in decimal');
		}
		return array('t' => 'i', 'v' => $negative ? -(int)$digits : (int)$digits);
	}

	private static function floatValue(PhpToken $token, bool $negative, string $what): array {
		$digits = str_replace('_', '', $token->text);
		// At most 15 digits: what a float holds exactly, and never one too
		// large to hold (which JSON cannot carry, and the site stores JSON).
		if (!preg_match('~^[0-9]+\.[0-9]+$~D', $digits) || strlen($digits) > 16 || !is_finite((float)$digits)) {
			throw new SandboxModelFileRefusal('line ' . $token->line . ': ' . $what . ' has a number written as "' . self::clip($token->text)
				. '"; write it as 1.5, with at most 15 digits');
		}
		return array('t' => 'f', 'v' => ($negative ? '-' : '') . $digits);
	}

	/** Past a method's parameters, return type and body (or its ';'), braces counted. */
	private function skipMethod(PhpToken $name): void {
		$depth = 0;
		while (($token = $this->take()) !== null) {
			if (self::is($token, '{') || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$depth++;
			} elseif (self::is($token, '}')) {
				$depth--;
				if ($depth === 0) {
					return;
				}
			} elseif (self::is($token, ';') && $depth === 0) {
				return;
			}
		}
		throw new SandboxModelFileRefusal('line ' . $name->line . ': the method ' . $name->text . ' is not closed');
	}

	/** Past a non-static property's default value, up to its ',' or ';'. */
	private function skipExpression(): void {
		$depth = 0;
		while (($token = $this->peek()) !== null) {
			if (self::isAny($token, array('(', '[', '{'))) {
				$depth++;
			} elseif (self::isAny($token, array(')', ']', '}'))) {
				$depth--;
			} elseif ($depth === 0 && (self::is($token, ',') || self::is($token, ';'))) {
				return;
			}
			$this->at++;
		}
	}

	/**
	 * Whether a token is the one punctuation character $char. By its id,
	 * never its text: a part of an interpolated string ("$a{") can have the
	 * same text, and counting it would hide what follows.
	 */
	private static function is(PhpToken $token, string $char): bool {
		return $token->id === ord($char);
	}

	private static function isAny(PhpToken $token, array $chars): bool {
		foreach ($chars as $char) {
			if (self::is($token, $char)) {
				return true;
			}
		}
		return false;
	}

	private function peek(): ?PhpToken {
		return $this->tokens[$this->at] ?? null;
	}

	private function take(): ?PhpToken {
		$token = $this->tokens[$this->at] ?? null;
		if ($token !== null) {
			$this->at++;
		}
		return $token;
	}

	/** Text for a message, cut short and stripped of control characters. */
	private static function clip(string $text): string {
		return mb_substr((string)preg_replace('~[\x00-\x1F\x7F]+~', ' ', $text), 0, 40);
	}
}

/** Why a model file is refused; the message names the line. */
class SandboxModelFileRefusal extends Exception {}
