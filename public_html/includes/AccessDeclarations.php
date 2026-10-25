<?php
/**
 * The permission declarations in the tree, read from the source
 * (spec declared_permissions 3.2 to 3.5, 3.9).
 *
 * The declaration test and the inventory tool read them here, from the files
 * on disk, so a plugin that is not active is read too (its classes do not
 * load). Nothing here decides anything: Access does.
 *
 *   pages()    every admin page: /admin/{name} (adm/), /plugins/{plugin}/admin/{name}
 *              and /admin/{plugin}/{name} (a plugin's views/admin/), with its header
 *   models()   every model in data/ and plugins/{plugin}/data/: its class, owner
 *              column, per-verb exceptions and column classes
 *   actions()  every API action descriptor: its level and whether it mutates
 *
 *   problems() what the declaration test fails on (spec 3.9), one entry each
 *
 * A page header is a docblock line in the page file:
 *
 *   /** @joinery-admin class=personal *\/      the class's read level
 *   /** @joinery-admin level=8 *\/            a number, for a page with no class
 *
 * @version 1.0
 */
final class AccessDeclarations {

	/** Model property => the verb its number replaces. */
	const EXCEPTIONS = array(
		'read_permission'             => 'read',
		'write_permission'            => 'write',
		'delete_permission'           => 'soft_delete',
		'permanent_delete_permission' => 'permanent_delete',
	);

	/**
	 * The declaration test's rules, rule => whether it fails the test yet. A rule
	 * that asks for a declaration nothing has yet is turned on by the work
	 * package that declares them; until then the test reports its count.
	 */
	const RULES = array(
		'page_header_invalid'      => true,   // a header that does not parse, or names no class or level
		'model_class_unknown'      => true,   // $access_class is not a class in permissions.json
		'model_exception_invalid'  => true,   // a per-verb exception that is not a level
		'owner_column_missing'     => true,   // $owner_column names no column of the model
		'column_class_unknown'     => true,   // a column's access_class is not a class
		'column_looser_than_model' => true,   // a column below its model's class (3.3)
		'sealed_column_below_keys' => true,   // a sealed column declared as anything but keys
		'action_level_invalid'     => true,   // min_user_permission is not a level or class:verb
		'action_mutates_on_read'   => true,   // a mutating action on a :read level (3.5)
		'action_level_misplaced'   => true,   // 'min_permission' in a descriptor: nothing reads it (min_user_permission, in 'auth')
		'page_undeclared'          => true,   // every admin page has a header (WP3)
		'admin_check_permission'   => true,   // a page or its logic calls check_permission(): the header is the gate
		'model_undeclared'         => true,   // every model declares $access_class (WP2)
		'action_undeclared'        => true,   // every descriptor declares its level (WP4)
		'level_compared_inline'    => true,   // a session's or user's level compared in code: ask Access, a row or a model
	);

	/**
	 * A level compared in code: the session's (get_permission(), $_SESSION's),
	 * a stored user's (usr_permission) or a rule's identity array's. Access
	 * itself is the one place that compares.
	 */
	const INLINE_LEVEL = '/(?:get_permission\(\)|\$_SESSION\[\s*\'permission\'\s*\](?:\s*\?\?\s*\d+)?|usr_permission\'\s*\)|\[\s*\'current_user_permission\'\s*\](?:\s*\?\?\s*\d+)?)\s*\)?\s*(?:===?|!==?|<=?|>=?)(?!>)|(?<![=\-])(?:<=?|>=?)\s*\(?\s*(?:\(int\)\s*)?\$\w+->get_permission\(\)/';

	/** Files that may compare a level: Access decides, and the ladder's own tests. */
	const INLINE_LEVEL_ALLOWED = array('includes/Access.php');

	/** Directories never scanned for descriptors. */
	const SKIP_DIRS = array('vendor', 'tests', 'node_modules', 'specs', 'docs', 'logs', 'cache', 'uploads');

	/** The public_html root, or a stand-in tree (tests). */
	private static function root(?string $root): string {
		return rtrim($root ?? PathHelper::getIncludePath(''), '/');
	}

	/**
	 * Every admin page, url => ['file' (relative), 'plugin' ('core' for adm/),
	 * 'header' (pageHeader() of the file)].
	 */
	public static function pages(?string $root = null): array {
		$root = self::root($root);
		$out = array();
		foreach ((array)glob("$root/adm/*.php") as $f) {
			$out['/admin/' . basename($f, '.php')] = self::page($root, $f, 'core');
		}
		foreach ((array)glob("$root/plugins/*/admin/*.php") as $f) {
			$plugin = basename(dirname($f, 2));
			$out["/plugins/$plugin/admin/" . basename($f, '.php')] = self::page($root, $f, $plugin);
		}
		foreach ((array)glob("$root/plugins/*/views/admin", GLOB_ONLYDIR) as $dir) {
			$plugin = basename(dirname($dir, 2));
			foreach (self::phpFiles($dir) as $f) {
				$name = substr($f, strlen($dir) + 1, -4);
				$out["/admin/$plugin/$name"] = self::page($root, $f, $plugin);
			}
		}
		ksort($out);
		return $out;
	}

	private static function page(string $root, string $file, string $plugin): array {
		return array('file' => substr($file, strlen($root) + 1), 'plugin' => $plugin, 'header' => self::pageHeader($file));
	}

	/**
	 * A page file's header: null without one, else ['class' => name] or
	 * ['level' => int], or ['error' => why] when it is malformed. Only the
	 * file's opening is read.
	 */
	public static function pageHeader(string $file): ?array {
		$head = (string)@file_get_contents($file, false, null, 0, 4096);
		// The header is a line of its own; a docblock that mentions the tag in
		// prose is not one.
		if (!preg_match_all('#^[ \t]*/\*\*[ \t]*@joinery-admin\b([^\n*]*)\*/[ \t]*$#m', $head, $all)) {
			return null;
		}
		if (count($all[1]) > 1) {
			return array('error' => 'more than one @joinery-admin header');
		}
		$args = trim($all[1][0]);
		if (preg_match('/^class=([a-z_]+)$/', $args, $m)) {
			return array('class' => $m[1]);
		}
		if (preg_match('/^level=(\d+)$/', $args, $m)) {
			return array('level' => (int)$m[1]);
		}
		return array('error' => "'@joinery-admin $args' is neither class=NAME nor level=N");
	}

	/**
	 * Every model, class name => ['file', 'plugin', 'table', 'access_class'
	 * (null when undeclared), 'owner_column' (null when undeclared, false when
	 * the model says it has none),
	 * 'exceptions' (verb => int), 'fields' (every column), 'columns'
	 * (column => class, for the columns that declare one)].
	 */
	public static function models(?string $root = null): array {
		$root = self::root($root);
		$files = array_merge((array)glob("$root/data/*_class.php"), (array)glob("$root/plugins/*/data/*_class.php"));
		sort($files);
		$out = array();
		foreach ($files as $f) {
			$src = self::withoutComments((string)file_get_contents($f));
			if (!preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+SystemBase\b/m', $src, $classes, PREG_OFFSET_CAPTURE)) {
				continue;
			}
			$rel = substr($f, strlen($root) + 1);
			foreach ($classes[1] as $i => $c) {
				$body = self::block($src, $classes[0][$i][1], '{', '}');
				$model = array(
					'file'         => $rel,
					'plugin'       => preg_match('#^plugins/([^/]+)/#', $rel, $pm) ? $pm[1] : 'core',
					'table'        => self::staticString($body, 'tablename'),
					'access_class' => self::staticString($body, 'access_class'),
					'owner_column' => preg_match('/static\s+\$owner_column\s*=\s*false\s*;/i', $body)
						? false : self::staticString($body, 'owner_column'),
					'exceptions'   => array(),
				);
				foreach (self::EXCEPTIONS as $prop => $verb) {
					if (preg_match('/static\s+\$' . $prop . '\s*=\s*(\d+)\s*;/', $body, $m)) {
						$model['exceptions'][$verb] = (int)$m[1];
					}
				}
				[$model['fields'], $model['columns']] = self::columnSpecs($body);
				$out[$c[0]] = $model;
			}
		}
		ksort($out);
		return $out;
	}

	/** [every column, column => access_class] of a class body's $field_specifications. */
	private static function columnSpecs(string $body): array {
		$pos = strpos($body, '$field_specifications');
		if ($pos === false) return array(array(), array());
		$eq = strpos($body, '=', $pos);
		$open = strcspn($body, '([', $eq) + $eq;
		$spec = self::block($body, $open, $body[$open] ?? '(', ($body[$open] ?? '(') === '[' ? ']' : ')');
		$fields = array();
		$out = array();
		// Each column is 'name' => array(...) or 'name' => [...] at the spec's top
		// level; the search resumes after each entry, so nested keys never count.
		$at = 1;
		while (preg_match("/'(\w+)'\s*=>\s*(array\s*\(|\[)/", $spec, $col, PREG_OFFSET_CAPTURE, $at)) {
			$bracket = substr($col[2][0], -1);
			$entry = self::block($spec, $col[2][1], $bracket, $bracket === '[' ? ']' : ')');
			$fields[] = $col[1][0];
			if (preg_match("/'access_class'\s*=>\s*'([^']*)'/", $entry, $m)) {
				$out[$col[1][0]] = $m[1];
			}
			$at = $col[2][1] + max(1, strlen($entry));
		}
		return array($fields, $out);
	}

	/**
	 * Every API action descriptor, action name => ['file', 'plugin', 'level'
	 * (the declared min_user_permission as written: int, 'class:verb', or
	 * null when absent; any other text verbatim), 'mutates' (bool|null)].
	 */
	public static function actions(?string $root = null): array {
		$root = self::root($root);
		$out = array();
		foreach (self::phpFiles($root) as $f) {
			$src = (string)file_get_contents($f);
			if (strpos($src, '_logic_descriptor(') === false) continue;
			$src = self::withoutComments($src);
			if (!preg_match_all('/function\s+(\w+)_logic_descriptor\s*\(/', $src, $dm, PREG_OFFSET_CAPTURE)) continue;
			$rel = substr($f, strlen($root) + 1);
			foreach ($dm[1] as $i => $fn) {
				$desc = self::block($src, $dm[0][$i][1], '{', '}');
				$level = null;
				// A descriptor a builder makes (ForumAction::memberDescriptor())
				// declares its level in the builder, unless it says its own.
				if (!preg_match('/[\'"]min_user_permission[\'"]/', $desc)
						&& preg_match('/\breturn\s+(\w+)::(\w+)\s*\(/', $desc, $bm)) {
					$desc .= self::builderSource($root, $bm[1], $bm[2]);
				}
				if (preg_match('/[\'"]min_user_permission[\'"]\s*=>\s*([^,\]\)\n]+)/', $desc, $mm)) {
					$raw = trim($mm[1]);
					if (preg_match('/^\d+$/', $raw)) {
						$level = (int)$raw;
					} elseif (preg_match('/^([\'"])([^\'"]*)\1$/', $raw, $q)) {
						$level = $q[2];
					} else {
						$level = $raw;
					}
				}
				$mutates = preg_match('/[\'"]mutates[\'"]\s*=>\s*(true|false)/i', $desc, $mu) ? strtolower($mu[1]) === 'true' : null;
				$out[$fn[0]] = array(
					'file'    => $rel,
					'plugin'  => preg_match('#^plugins/([^/]+)/#', $rel, $pm) ? $pm[1] : 'core',
					'level'   => $level,
					'mutates' => $mutates,
					'misplaced' => (bool)preg_match('/[\'"]min_permission[\'"]\s*=>/', $desc),
				);
			}
		}
		ksort($out);
		return $out;
	}

	/** @var array "Class::method" => its source, for builders found once */
	private static $builders = array();

	/**
	 * The source of a descriptor builder method, found by its class's file
	 * (includes/ and every plugin's includes/), or '' when there is none.
	 */
	private static function builderSource(string $root, string $class, string $method): string {
		$key = "$root|$class::$method";
		if (isset(self::$builders[$key])) return self::$builders[$key];
		$files = array_merge((array)glob("$root/includes/$class.php"), (array)glob("$root/plugins/*/includes/$class.php"));
		$out = '';
		foreach ($files as $f) {
			$src = self::withoutComments((string)file_get_contents($f));
			if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
				$out = self::block($src, $m[0][1], '{', '}');
				break;
			}
		}
		return self::$builders[$key] = $out;
	}

	/**
	 * Every declaration problem in the tree: ['rule', 'where', 'why'], where
	 * 'rule' is a key of RULES. $sealed is the sealed 'table.column' locators;
	 * by default every one SealedSecretsDeclarations knows.
	 */
	public static function problems(?string $root = null, ?array $sealed = null): array {
		$root = self::root($root);
		$out = array();
		$add = function ($rule, $where, $why) use (&$out) {
			$out[] = array('rule' => $rule, 'where' => $where, 'why' => $why);
		};
		$classes = Access::classes();

		foreach (self::pages($root) as $url => $page) {
			$h = $page['header'];
			if ($h === null) {
				$add('page_undeclared', $url, 'no @joinery-admin header in ' . $page['file']);
			} elseif (isset($h['error'])) {
				$add('page_header_invalid', $url, $h['error']);
			} elseif (isset($h['class']) && !in_array($h['class'], $classes, true)) {
				$add('page_header_invalid', $url, "class '{$h['class']}' is not in permissions.json");
			} elseif (isset($h['level']) && $h['level'] > 10) {
				$add('page_header_invalid', $url, "level {$h['level']} is outside 0 to 10");
			}
			foreach (self::pageCode($root, $url, $page) as $file) {
				$code = self::withoutComments((string)file_get_contents("$root/$file"));
				if (preg_match('/->\s*check_permission\s*\(/', $code)) {
					$add('admin_check_permission', $url, "$file calls check_permission(); the header is the gate");
				}
			}
		}

		if ($sealed === null) {
			$sealed = array_values(array_filter(array_keys(SealedSecretsDeclarations::all()),
				function ($locator) { return strpos($locator, '.') !== false; }));
		}
		$sealed = array_flip($sealed);
		foreach (self::models($root) as $name => $m) {
			$class = $m['access_class'];
			if ($class === null) {
				$add('model_undeclared', $name, 'no $access_class in ' . $m['file']);
			} elseif (!in_array($class, $classes, true)) {
				$add('model_class_unknown', $name, "class '$class' is not in permissions.json");
			}
			foreach ($m['exceptions'] as $verb => $n) {
				if ($n > 10) {
					$add('model_exception_invalid', $name, "its $verb level $n is outside 0 to 10");
				}
			}
			if (is_string($m['owner_column']) && !in_array($m['owner_column'], $m['fields'], true)) {
				$add('owner_column_missing', $name, "owner column {$m['owner_column']} is not one of its columns");
			}
			// An undeclared model behaves as keys (3.2), so nothing on it is stricter.
			$floor = ($class !== null && in_array($class, $classes, true)) ? $class : 'keys';
			foreach ($m['columns'] as $col => $col_class) {
				if (!in_array($col_class, $classes, true)) {
					$add('column_class_unknown', "$name.$col", "class '$col_class' is not in permissions.json");
					continue;
				}
				foreach (Access::VERBS as $verb) {
					if (Access::level("$col_class:$verb") < Access::level("$floor:$verb")) {
						$add('column_looser_than_model', "$name.$col", "$col_class is below the model's $floor at $verb");
						break;
					}
				}
				if ($m['table'] !== null && isset($sealed[$m['table'] . '.' . $col]) && $col_class !== 'keys') {
					$add('sealed_column_below_keys', "$name.$col", "a sealed column is keys, not $col_class");
				}
			}
		}

		foreach (self::actions($root) as $name => $a) {
			if (!empty($a['misplaced'])) {
				$add('action_level_misplaced', $name, "'min_permission' in " . $a['file'] . " is read by nothing: the level is 'auth' => ['min_user_permission' => ...]");
			}
			$level = $a['level'];
			if ($level === null) {
				$add('action_undeclared', $name, 'no min_user_permission in ' . $a['file']);
				continue;
			}
			try {
				Access::level($level);
			} catch (InvalidArgumentException $e) {
				$add('action_level_invalid', $name, 'min_user_permission ' . var_export($level, true) . ': ' . $e->getMessage());
				continue;
			}
			if ($a['mutates'] === true && is_string($level) && substr($level, -5) === ':read') {
				$add('action_mutates_on_read', $name, "it mutates, so '$level' is too low a verb");
			}
		}

		foreach (self::phpFiles($root) as $path) {
			$rel = substr($path, strlen($root) + 1);
			if (in_array($rel, self::INLINE_LEVEL_ALLOWED, true)) continue;
			$src = (string)file_get_contents($path);
			if (strpos($src, 'permission') === false) continue;
			foreach (explode("\n", self::withoutComments($src)) as $i => $line) {
				if (preg_match(self::INLINE_LEVEL, $line)) {
					$add('level_compared_inline', $rel . ':' . ($i + 1), trim($line));
				}
			}
		}
		return $out;
	}

	/**
	 * A page's own files, relative: the page, every logic file it includes by
	 * getIncludePath(), and its conventional logic file.
	 */
	private static function pageCode(string $root, string $url, array $page): array {
		$files = array($page['file']);
		$name = basename($page['file'], '.php');
		if ($page['plugin'] === 'core') {
			$candidates = array("adm/logic/{$name}_logic.php");
		} elseif (strpos($url, '/plugins/') === 0) {
			$p = $page['plugin'];
			$candidates = array("plugins/$p/admin/logic/{$name}_logic.php", "plugins/$p/logic/admin_{$name}_logic.php",
				"plugins/$p/logic/{$name}_logic.php");
		} else {
			$p = $page['plugin'];
			$flat = str_replace('/', '_', substr($url, strlen("/admin/$p/")));
			$candidates = array("plugins/$p/logic/{$flat}_logic.php", "plugins/$p/logic/admin_{$flat}_logic.php");
		}
		if (preg_match_all("/getIncludePath\\(\\s*'([^']*\\blogic\\/[^']+\\.php)'/", (string)file_get_contents("$root/{$page['file']}"), $inc)) {
			$candidates = array_merge($inc[1], $candidates);
		}
		foreach (array_unique($candidates) as $c) {
			if (is_file("$root/$c") && !in_array($c, $files, true)) $files[] = $c;
		}
		return $files;
	}

	/**
	 * PHP source with its comments blanked, so a word or a quote inside a
	 * comment is never read as a declaration or a bracket.
	 */
	private static function withoutComments(string $src): string {
		$out = '';
		foreach (@token_get_all($src) as $t) {
			if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
				$out .= str_repeat("\n", substr_count($t[1], "\n")) ?: ' ';
			} else {
				$out .= is_array($t) ? $t[1] : $t;
			}
		}
		return $out;
	}

	/** A static string property's value in a class body, or null. */
	private static function staticString(string $body, string $name): ?string {
		return preg_match('/static\s+\$' . $name . '\s*=\s*\'([^\']*)\'\s*;/', $body, $m) ? $m[1] : null;
	}

	/** The text of the bracketed block that opens at or after $pos. */
	private static function block(string $src, int $pos, string $open, string $close): string {
		$start = strpos($src, $open, $pos);
		if ($start === false) return '';
		$depth = 0;
		$len = strlen($src);
		for ($i = $start; $i < $len; $i++) {
			$ch = $src[$i];
			if ($ch === "'" || $ch === '"') {
				// Skip a quoted string, so a bracket inside one never counts.
				for ($i++; $i < $len && $src[$i] !== $ch; $i++) {
					if ($src[$i] === '\\') $i++;
				}
				continue;
			}
			if ($ch === $open) {
				$depth++;
			} elseif ($ch === $close && --$depth === 0) {
				return substr($src, $start, $i - $start + 1);
			}
		}
		return substr($src, $start);
	}

	/** Every .php file under $dir, outside SKIP_DIRS, sorted. */
	private static function phpFiles(string $dir): array {
		$out = array();
		if (!is_dir($dir)) return $out;
		$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			function ($cur) {
				return !($cur->isDir() && in_array($cur->getFilename(), self::SKIP_DIRS, true));
			}
		));
		foreach ($it as $f) {
			if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
				$out[] = $f->getPathname();
			}
		}
		sort($out);
		return $out;
	}
}
