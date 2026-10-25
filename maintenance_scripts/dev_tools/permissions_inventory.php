<?php
/**
 * Permissions inventory: prints the tables of spec declared_permissions 8.4 to 8.7
 * from today's code.
 *
 * What the code declares wins: a page's @joinery-admin header, a model's
 * $access_class, a column's access_class and an action's min_user_permission, read
 * by AccessDeclarations. What the code does not declare yet prints the
 * hand-curated seed's answer (permissions_seed.json, beside this file), or "?".
 * An action's integer level is today's (its own column); only a class:verb
 * level replaces the seed's proposed one.
 * Levels come from permissions.json through Access. Fix the code or the seed,
 * never the output.
 *
 * Usage:
 *   php maintenance_scripts/dev_tools/permissions_inventory.php [--table=pages|models|columns|actions|all]
 *       [--plugin=NAME] [--seed=FILE]
 *
 * @version 1.2 - the pages table reads each page's header, as the router does: class, level and the level it opens at
 * @version 1.1 - reads permissions.json and the code's declarations before the seed
 */

require_once(dirname(__DIR__, 2) . '/public_html/includes/PathHelper.php');

// The spec's pattern, plus the three names it misses that the code keeps as secrets
// (usr_authhash, usr_second_factor_hmac_key, and any *_key column).
const SECRET_COLUMN_PATTERN = '/password|secret|token|api_key|private_key|credential|salt|otp|authhash|hmac|_key$/i';

const WRITE_CALL = '/->\s*(save|soft_delete|undelete|permanent_delete)\s*\(|updateColumns\s*\(|::put\s*\(|SettingsWriter|\b(?:DELETE\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET)\b/i';

const SKIP_DIRS = ['vendor', 'tests', 'node_modules', 'specs', 'docs', 'logs', 'cache', 'uploads'];

$argv = $argv ?? [];
$args = parse_args($argv);
$ROOT = dirname(__DIR__, 2) . '/public_html';
$seed = declared_over_seed(load_seed($args['seed'] ?? __DIR__ . '/permissions_seed.json'));
$only_plugin = $args['plugin'] ?? null;
$which = $args['table'] ?? 'all';

if (isset($args['callers'])) {
	// Diagnostic: the files that reach an action name, by class (see print_actions).
	foreach (caller_files($ROOT, $args['callers']) as [$kind, $file]) {
		echo "$kind\t$file\n";
	}
	exit(0);
}

$models = model_index($ROOT);
$models_by_class = [];
foreach ($models as $m) {
	$models_by_class[$m['class']] = $m;
}

if ($which === 'all' || $which === 'pages') {
	print_pages($ROOT, $seed, $models_by_class, $only_plugin);
}
if ($which === 'all' || $which === 'models') {
	print_models($models, $seed, $only_plugin);
}
if ($which === 'all' || $which === 'columns') {
	print_columns($ROOT, $seed, $models, $only_plugin);
}
if ($which === 'all' || $which === 'actions') {
	print_actions($ROOT, $seed, $models_by_class, $only_plugin);
}

// ---------------------------------------------------------------- arguments and seed

function parse_args(array $argv): array {
	$out = [];
	foreach (array_slice($argv, 1) as $a) {
		if (preg_match('/^--(\w+)=(.*)$/', $a, $m)) {
			$out[$m[1]] = $m[2];
		}
	}
	return $out;
}

function load_seed(string $path): array {
	$empty = ['pages' => [], 'models' => [], 'columns' => [], 'actions' => [], 'page_writes' => []];
	if (!is_file($path)) {
		return $empty;
	}
	$data = json_decode(file_get_contents($path), true);
	if (!is_array($data)) {
		fwrite(STDERR, "permissions_inventory: seed $path is not valid JSON\n");
		exit(1);
	}
	return array_merge($empty, $data);
}

/**
 * The seed with every declaration the code makes laid over it, so each table
 * prints the code's answer where there is one.
 */
function declared_over_seed(array $seed): array {
	foreach (AccessDeclarations::pages() as $url => $page) {
		$h = $page['header'] ?? null;
		if (isset($h['class'])) {
			$seed['pages'][$url]['class'] = $h['class'];
		} elseif (isset($h['level'])) {
			$seed['pages'][$url]['level'] = $h['level'];
		}
	}
	foreach (AccessDeclarations::models() as $name => $m) {
		if ($m['access_class'] !== null) {
			$seed['models'][$name]['class'] = $m['access_class'];
			$seed['models'][$name]['confidence'] = 'declared';
		}
		if ($m['owner_column'] !== null) {
			$seed['models'][$name]['owner_column'] = $m['owner_column'];
		}
		foreach ($m['columns'] as $col => $class) {
			$seed['columns']["$name.$col"]['class'] = $class;
		}
	}
	foreach (AccessDeclarations::actions() as $name => $a) {
		if (is_string($a['level']) && strpos($a['level'], ':') !== false) {
			$seed['actions'][$name]['proposed'] = $a['level'];
			$seed['actions'][$name]['confidence'] = 'declared';
		}
	}
	return $seed;
}

// ---------------------------------------------------------------- source helpers

function php_files(string $dir): array {
	$out = [];
	if (!is_dir($dir)) {
		return $out;
	}
	$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		function ($cur) {
			return !($cur->isDir() && in_array($cur->getFilename(), SKIP_DIRS, true));
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

/** Text of the brace block that opens at or after $pos. */
function brace_block(string $src, int $pos): string {
	$open = strpos($src, '{', $pos);
	if ($open === false) {
		return '';
	}
	$depth = 0;
	$len = strlen($src);
	for ($i = $open; $i < $len; $i++) {
		if ($src[$i] === '{') {
			$depth++;
		} elseif ($src[$i] === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($src, $open, $i - $open + 1);
			}
		}
	}
	return substr($src, $open);
}

/** Text of the parenthesised block that opens at or after $pos (an array(...) literal). */
function paren_block(string $src, int $pos): string {
	$open = strpos($src, '(', $pos);
	if ($open === false) {
		return '';
	}
	$depth = 0;
	$len = strlen($src);
	for ($i = $open; $i < $len; $i++) {
		if ($src[$i] === '(') {
			$depth++;
		} elseif ($src[$i] === ')') {
			$depth--;
			if ($depth === 0) {
				return substr($src, $open, $i - $open + 1);
			}
		}
	}
	return substr($src, $open);
}

function strip_comments(string $code): string {
	return preg_replace('!//[^\n]*|/\*.*?\*/!s', '', $code);
}

function rel(string $ROOT, string $path): string {
	return ltrim(substr($path, strlen($ROOT)), '/');
}

function md_cell($v): string {
	$s = is_array($v) ? implode(', ', $v) : (string)$v;
	return $s === '' ? '' : str_replace('|', '/', $s);
}

function md_table(array $headers, array $rows): string {
	$out = '| ' . implode(' | ', $headers) . " |\n";
	$out .= '|' . str_repeat('---|', count($headers)) . "\n";
	foreach ($rows as $r) {
		$out .= '| ' . implode(' | ', array_map('md_cell', $r)) . " |\n";
	}
	return $out;
}

// ---------------------------------------------------------------- models

/** Every class extending SystemBase in data/ and plugins/*\/data/, keyed by file. */
function model_index(string $ROOT): array {
	$out = [];
	foreach (AccessDeclarations::models($ROOT) as $class => $m) {
		$file_src = file_get_contents("$ROOT/{$m['file']}");
		// The class's own body, for the flags and write rule only this table reports.
		$src = preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+' . $class . '\b/m', $file_src, $cm, PREG_OFFSET_CAPTURE)
			? brace_block($file_src, $cm[0][1]) : '';
		$prefix = preg_match('/static\s+\$prefix\s*=\s*\'(\w+)\'/', $src, $p) ? $p[1] : '';
		// Declared owner column (3.2), then the precedent $ai_owner_field, then the prefix convention.
		$declared = $m['owner_column']
			?? (preg_match('/static\s+\$ai_owner_field\s*=\s*\'(\w+)\'/', $src, $ao) ? $ao[1] : '');
		$owner = '';
		if ($declared === false) {
			$owner = 'none (declared)';
		} elseif ($declared !== '' && in_array($declared, $m['fields'], true)) {
			$owner = $declared;
		} elseif ($prefix !== '' && in_array($prefix . '_usr_user_id', $m['fields'], true)) {
			$owner = $prefix . '_usr_user_id';
		}
		$rest = [];
		if (flag($src, 'api_readable')) $rest[] = 'read';
		if (flag($src, 'api_writable')) $rest[] = 'write';
		if (flag($src, 'api_public_read')) $rest[] = 'public read';
		$out[] = [
			'class'      => $class,
			'file'       => $m['file'],
			'plugin'     => $m['plugin'],
			'table'      => (string)$m['table'],
			'prefix'     => $prefix,
			'fields'     => $m['fields'],
			'owner_col'  => $owner,
			'write_rule' => write_rule($src),
			'rest'       => $rest,
			'ai'         => flag($src, 'ai_readable') ? 'read' : '-',
			'write_perm' => $m['exceptions']['write'] ?? null,
		];
	}
	return $out;
}

function flag(string $src, string $name): bool {
	return (bool)preg_match('/static\s+\$' . $name . '\s*=\s*true/i', $src);
}

/** Today's write rule for a model: boilerplate, owner-only, a custom override, or the default. */
function write_rule(string $src): string {
	if (!preg_match('/function\s+authenticate_write\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
		return 'default: owner, else the class level';
	}
	$code = strip_comments(brace_block($src, $m[0][1]));
	preg_match_all('/permission\s*(<=|>=|==|!=|<|>)\s*(\d+)/', $code, $pm, PREG_SET_ORDER);
	preg_match_all('/->\s*(\w+)\s*\(/', $code, $calls);
	$other_calls = array_diff($calls[1] ?? [], ['get']);
	$content_rule = preg_match('/moder|SpaceAccess|forum|chat|board|space/i', $code);
	if ($pm && !$other_calls && !$content_rule) {
		$parts = [];
		foreach ($pm as $x) {
			$parts[] = ($x[1] === '<' ? 'below ' : $x[1] . ' ') . $x[2] . ' refuse';
		}
		return 'boilerplate: drop (' . implode('; ', array_unique($parts)) . ')';
	}
	if (!$pm && preg_match('/usr_user_id|owner/i', $code)) {
		return 'owner only';
	}
	return 'keep override';
}

// ---------------------------------------------------------------- write detection


/** Models a set of source texts writes, or '?' when a write exists that no model explains. */
function written_models(string $src_all, array $models_by_class): string {
	if (!preg_match(WRITE_CALL, $src_all)) {
		return '-';
	}
	// A variable is a model when it is assigned from new X() or from a static call on X.
	$vars = [];
	preg_match_all('/\$(\w+)\s*=\s*new\s+(\w+)\s*\(/', $src_all, $am, PREG_SET_ORDER);
	foreach ($am as $m) {
		$vars[$m[1]] = $m[2];
	}
	preg_match_all('/\$(\w+)\s*=\s*(\w+)::\w+\s*\(/', $src_all, $sm, PREG_SET_ORDER);
	foreach ($sm as $m) {
		if (!isset($vars[$m[1]]) && isset($models_by_class[$m[2]])) {
			$vars[$m[1]] = $m[2];
		}
	}
	$written = [];
	$untraced = false;
	preg_match_all('/\$(\w+)\s*->\s*(?:save|soft_delete|undelete|permanent_delete)\s*\(/', $src_all, $wm);
	foreach (array_unique($wm[1]) as $v) {
		if (isset($vars[$v]) && isset($models_by_class[$vars[$v]])) {
			$written[] = $vars[$v];
		} elseif (!isset($vars[$v])) {
			$untraced = true;
		}
	}
	preg_match_all('/\b(\w+)::(?:updateColumns|put)\s*\(/', $src_all, $cm);
	foreach (array_unique($cm[1]) as $c) {
		if (isset($models_by_class[$c])) {
			$written[] = $c;
		} elseif ($c === 'Setting') {
			$written[] = 'Setting';
		}
	}
	if (strpos($src_all, 'SettingsWriter') !== false) {
		$written[] = 'Setting';
	}
	// Raw SQL writes name a table; the table's model is the one written.
	$tables = [];
	foreach ($models_by_class as $m) {
		if ($m['table'] !== '') {
			$tables[$m['table']] = $m['class'];
		}
	}
	preg_match_all('/\b(?:DELETE\s+FROM|INSERT\s+INTO|UPDATE)\s+(\w+)/i', $src_all, $sq);
	foreach (array_unique($sq[1]) as $t) {
		if (isset($tables[$t])) {
			$written[] = $tables[$t];
		} else {
			$untraced = true;
		}
	}
	if (preg_match('/updateColumns\s*\(/', $src_all) && !$written) {
		$untraced = true;
	}
	$written = array_values(array_unique($written));
	if ($untraced) {
		$written[] = '?';
	}
	return $written ? implode(', ', $written) : '-';
}

/** The verb a set of source texts performs on its models, for a single-model action. */
function write_verb(string $src_all): string {
	if (strpos($src_all, 'permanent_delete(') !== false) return 'permanent_delete';
	if (preg_match('/soft_delete\s*\(|undelete\s*\(/', $src_all)) return 'soft_delete';
	if (preg_match('/->\s*save\s*\(|updateColumns\s*\(|::put\s*\(/', $src_all)) return 'write';
	return 'read';
}

// ---------------------------------------------------------------- pages

/** [kind, name, file, url] for every admin page file. */
function page_list(string $ROOT): array {
	$out = [];
	foreach (glob("$ROOT/adm/*.php") ?: [] as $f) {
		$name = basename($f, '.php');
		$out[] = ['core', $name, $f, "/admin/$name", 'core'];
	}
	foreach (glob("$ROOT/plugins/*/admin/*.php") ?: [] as $f) {
		$plugin = basename(dirname(dirname($f)));
		$name = basename($f, '.php');
		$out[] = [$plugin, $name, $f, "/plugins/$plugin/admin/$name", 'plugin_admin'];
	}
	foreach (php_files_views($ROOT) as $f) {
		$plugin = basename(dirname($f, 3));
		$rel_name = substr($f, strpos($f, '/views/admin/') + strlen('/views/admin/'), -4);
		$out[] = [$plugin, $rel_name, $f, "/admin/$plugin/$rel_name", 'plugin_views'];
	}
	return $out;
}

function php_files_views(string $ROOT): array {
	$out = [];
	foreach (glob("$ROOT/plugins/*/views/admin") ?: [] as $dir) {
		$out = array_merge($out, php_files($dir));
	}
	sort($out);
	return $out;
}

function print_pages(string $ROOT, array $seed, array $models_by_class, ?string $only_plugin): void {
	$rows = [];
	foreach (page_list($ROOT) as $page) {
		if ($only_plugin !== null && $page[0] !== $only_plugin) {
			continue;
		}
		$url = $page[3];
		$s = $seed['pages'][$url] ?? [];
		// What the router enforces: the page's own header (AdminPageAccess).
		$header = AccessDeclarations::pageHeader($page[2]);
		$class = $header['class'] ?? '';
		$level_decl = isset($header['level']) ? (string)$header['level'] : '';
		$opens = AdminPageAccess::levelForFile(substr($page[2], strlen($ROOT) + 1));
		$writes = $seed['page_writes'][$url] ?? written_models(concat_sources(own_sources($ROOT, $page)), $models_by_class);
		$rows[] = ['`' . $url . '`', $class !== '' ? $class : ($header === null ? 'undeclared' : ''), $level_decl, $opens, $writes, $s['note'] ?? ''];
	}
	echo "#### Table 8.4 Pages (" . count($rows) . ")\n\n";
	echo md_table(['Page', 'Class', 'Level', 'Opens at', 'Models written', 'Note'], $rows);
}

/** The page file and its conventional logic file, and nothing it includes. */
function own_sources(string $ROOT, array $page): array {
	$files = [$page[2]];
	foreach (conventional_logic($ROOT, $page) as $p) {
		if (is_file($p) && !in_array($p, $files, true)) {
			$files[] = $p;
		}
	}
	return $files;
}

/** The conventional logic files of a page (see page_sources). */
function conventional_logic(string $ROOT, array $page): array {
	[$kind, $name, , , $family] = $page;
	if ($family === 'core') {
		return ["$ROOT/adm/logic/{$name}_logic.php"];
	}
	if ($family === 'plugin_admin') {
		return [
			"$ROOT/plugins/$kind/admin/logic/{$name}_logic.php",
			"$ROOT/plugins/$kind/logic/admin_{$name}_logic.php",
			"$ROOT/plugins/$kind/logic/{$name}_logic.php",
		];
	}
	$flat = str_replace('/', '_', $name);
	return ["$ROOT/plugins/$kind/logic/{$flat}_logic.php", "$ROOT/plugins/$kind/logic/admin_{$flat}_logic.php"];
}

function concat_sources(array $files): string {
	$all = '';
	foreach ($files as $f) {
		$all .= "\n" . file_get_contents($f);
	}
	return $all;
}

// ---------------------------------------------------------------- models table

function print_models(array $models, array $seed, ?string $only_plugin): void {
	$rows = [];
	foreach ($models as $m) {
		if ($only_plugin !== null && $m['plugin'] !== $only_plugin) {
			continue;
		}
		$s = $seed['models'][$m['class']] ?? [];
		if ($m['owner_col'] === '' && isset($s['owner_column'])) {
			// The seed names an owner column the code does not declare yet (3.2 says to declare it).
			$m['owner_col'] = $s['owner_column'] . ' (undeclared)';
		}
		$exc = [];
		foreach (($s['exceptions'] ?? []) as $k => $v) {
			$exc[] = "$k $v";
		}
		$rows[] = [
			$m['class'],
			$m['file'],
			$s['class'] ?? '?',
			$m['owner_col'] !== '' ? $m['owner_col'] : '-',
			$m['write_rule'],
			$m['rest'] ? implode(', ', $m['rest']) : '-',
			$m['ai'],
			implode('; ', $exc) . (isset($s['reason']) ? ' (' . $s['reason'] . ')' : ''),
			$s['confidence'] ?? '?',
		];
	}
	echo "#### Table 8.5 Models (" . count($rows) . ")\n\n";
	echo md_table(['Model', 'File', 'Class', 'Owner column', "Today's write rule", 'REST', 'AI', 'Exceptions', 'Confidence'], $rows);
}

// ---------------------------------------------------------------- columns table

function print_columns(string $ROOT, array $seed, array $models, ?string $only_plugin): void {
	$by_table = [];
	foreach ($models as $m) {
		if ($m['table'] !== '') {
			$by_table[$m['table']] = $m;
		}
	}
	$rows = [];
	$sealed_cols = [];
	$reviewed_none = 0;

	// Sealed locators (settings.json and plugin.json "sealed_secrets") and settings flagged
	// secret: true. A setting that is both gets one row with both sources.
	$setting_rows = [];
	foreach (sealed_entries($ROOT) as [$source, $entry]) {
		$loc = $entry['locator'] ?? '';
		$label = $entry['label'] ?? '';
		if (strpos($loc, '.') !== false) {
			[$table, $col] = explode('.', $loc, 2);
			$name = isset($by_table[$table]) ? $by_table[$table]['class'] . ".$col" : "$table.$col";
			$sealed_cols[$name] = true;
			$rows[] = [$name, 'keys', "sealed ($source)", $label];
		} else {
			$setting_rows["setting: $loc"]['sources'][] = "sealed ($source)";
			$setting_rows["setting: $loc"]['note'] = $label;
		}
	}
	foreach (secret_settings($ROOT) as [$source, $setting]) {
		$key = "setting: {$setting['name']}";
		$setting_rows[$key]['sources'][] = "secret setting ($source)";
		$setting_rows[$key]['note'] = $setting_rows[$key]['note'] ?? ($setting['label'] ?? '');
	}
	foreach ($setting_rows as $name => $r) {
		$rows[] = [$name, 'keys', implode('; ', array_unique($r['sources'])), $r['note']];
	}

	// Columns whose name looks like a secret, not covered above, and seed-declared columns.
	$seen = [];
	foreach ($models as $m) {
		foreach ($m['fields'] as $col) {
			$name = $m['class'] . ".$col";
			$s = $seed['columns'][$name] ?? null;
			$looks_secret = preg_match(SECRET_COLUMN_PATTERN, $col);
			if ((!$looks_secret || isset($sealed_cols[$name])) && $s === null) {
				continue;
			}
			if (isset($sealed_cols[$name]) || isset($seen[$name])) {
				continue;
			}
			$seen[$name] = true;
			$note = $s['note'] ?? '';
			$class = $s['class'] ?? '?';
			if ($class === 'none') {
				$reviewed_none++;
				continue;
			}
			if ($looks_secret && $class === '?' && !preg_match('/sealed|wrapped/i', $col)) {
				$note = trim('plaintext? no sealed locator. ' . $note);
			}
			$rows[] = [$name, $class, 'declared', $note];
		}
	}
	// Seed columns that are not secret-looking and were not reached above.
	foreach ($seed['columns'] as $name => $s) {
		if (isset($seen[$name]) || isset($sealed_cols[$name])) {
			continue;
		}
		if (($s['class'] ?? '') === 'none') {
			$reviewed_none++;
			continue;
		}
		$rows[] = [$name, $s['class'] ?? '?', 'declared', $s['note'] ?? ''];
	}

	usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));
	echo "#### Table 8.6 Column exceptions (" . count($rows) . ")\n\n";
	echo md_table(['Model.column', 'Class', 'Source', 'Note'], $rows);
	echo "\n$reviewed_none further name matches were reviewed and are not secrets (seed class \"none\").\n";
}

function sealed_entries(string $ROOT): array {
	$out = [];
	$core = json_decode(file_get_contents("$ROOT/settings.json"), true);
	foreach (($core['sealed_secrets'] ?? []) as $e) {
		$out[] = ['core', $e];
	}
	foreach (glob("$ROOT/plugins/*/plugin.json") ?: [] as $pj) {
		$d = json_decode(file_get_contents($pj), true);
		foreach (($d['sealed_secrets'] ?? []) as $e) {
			$out[] = [basename(dirname($pj)), $e];
		}
	}
	return $out;
}

function secret_settings(string $ROOT): array {
	$out = [];
	$core = json_decode(file_get_contents("$ROOT/settings.json"), true);
	foreach (($core['settings'] ?? []) as $s) {
		if (!empty($s['secret'])) {
			$out[] = ['core', $s];
		}
	}
	foreach (glob("$ROOT/plugins/*/plugin.json") ?: [] as $pj) {
		$d = json_decode(file_get_contents($pj), true);
		foreach (($d['settings'] ?? []) as $s) {
			if (is_array($s) && !empty($s['secret'])) {
				$out[] = [basename(dirname($pj)), $s];
			}
		}
	}
	return $out;
}

// ---------------------------------------------------------------- actions table

function action_files(string $ROOT): array {
	$out = [];
	foreach (php_files($ROOT) as $f) {
		if (strpos(file_get_contents($f), '_logic_descriptor(') !== false) {
			$out[] = $f;
		}
	}
	return $out;
}

function print_actions(string $ROOT, array $seed, array $models_by_class, ?string $only_plugin): void {
	$callers = caller_index($ROOT);
	$rows = [];
	$member_zeroed = 0;
	$mutating_on_read = 0;
	foreach (action_files($ROOT) as $f) {
		$src = file_get_contents($f);
		$relpath = rel($ROOT, $f);
		$plugin = preg_match('#^plugins/([^/]+)/#', $relpath, $pm) ? $pm[1] : 'core';
		if ($only_plugin !== null && $plugin !== $only_plugin) {
			continue;
		}
		preg_match_all('/function\s+(\w+)_logic_descriptor\s*\(/', $src, $dm, PREG_OFFSET_CAPTURE);
		foreach ($dm[1] as $i => $fn) {
			$action = preg_replace('/_logic$/', '', $fn[0]);
			$desc = brace_block($src, $dm[0][$i][1]);
			$min = preg_match('/[\'"]min_user_permission[\'"]\s*=>\s*(\d+)/', $desc, $mm) ? (int)$mm[1] : 0;
			$mut = preg_match('/[\'"]mutates[\'"]\s*=>\s*(true|false)/', $desc, $mu) ? $mu[1] : '-';
			$written = written_models($src, $models_by_class);
			$s = $seed['actions'][$action] ?? [];
			$kinds = array_keys($callers[$action] ?? []);
			// The seed or the code gives the level an admin page or the AI assistant would need.
			$level = $s['proposed'] ?? derived_action_level($src, $written, $models_by_class, $seed);
			if ($level === '?' && $written === '-') {
				$level = derived_read_level($src, $models_by_class, $seed);
			}
			$confidence = $s['confidence'] ?? ($level !== '?' ? 'traced' : '?');
			if (!empty($s['member_own'])) {
				// The seed says a member runs this on their own rows, whatever the callers scan finds.
				$proposed = 0;
				$confidence = 'read';
			} elseif (in_array('admin', $kinds, true) || in_array('ai', $kinds, true) || in_array('shared', $kinds, true)
				|| !empty($s['across_users'])) {
				$proposed = $level;
			} elseif (in_array('member', $kinds, true)) {
				// A member's own action: signed in, and the model's owner rule is the gate (3.5).
				$proposed = 0;
				$confidence = 'read';
				$member_zeroed++;
			} elseif ($min >= 5) {
				$proposed = $level;
			} else {
				$proposed = 0;
				$confidence = 'read';
			}
			if ($mut === 'true' && is_string($proposed) && substr($proposed, -5) === ':read') {
				$proposed .= ' !';
				$mutating_on_read++;
			}
			$rows[] = [$action, $relpath, $min, $mut, $written, $proposed, $confidence];
		}
	}
	usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));
	echo "#### Table 8.7 Actions (" . count($rows) . ")\n\n";
	echo md_table(['Action', 'File', 'Today min_user_permission', 'mutates', 'Models written', 'Proposed level', 'Confidence'], $rows);
	echo "\nMember actions at 0 (called only from member pages): $member_zeroed. Mutating actions on a :read floor (!): $mutating_on_read.\n";
}

/** Callers of each action name, by file class: admin, ai, member. Logic files do not count as callers. */
/** Every [class, file] that reaches the given action name (the diagnostic behind caller_index). */
function caller_files(string $ROOT, string $name): array {
	$out = [];
	$files = array_merge(php_files($ROOT), js_files($ROOT));
	foreach ($files as $f) {
		$rel = rel($ROOT, $f);
		$kind = file_class($rel);
		if ($kind === 'logic') {
			continue;
		}
		$hit = false;
		foreach (preg_split('/\R/', file_get_contents($f)) as $line) {
			if (preg_match('/action|api|logic|descriptor/i', $line)
				&& preg_match('/[\'"\\/=]' . preg_quote($name, '/') . '(?=[\'"\\/?&])/', $line)) {
				$hit = true;
				break;
			}
		}
		if (!$hit && preg_match('/\\b' . preg_quote($name, '/') . '_logic\\s*\\(/', file_get_contents($f))) {
			$hit = true;
		}
		if ($hit) {
			$out[] = [$kind, $rel];
		}
	}
	return $out;
}

function caller_index(string $ROOT): array {
	static $idx = null;
	if ($idx !== null) {
		return $idx;
	}
	$idx = [];
	$files = php_files($ROOT);
	foreach (js_files($ROOT) as $f) {
		$files[] = $f;
	}
	foreach ($files as $f) {
		$rel = rel($ROOT, $f);
		$kind = file_class($rel);
		if ($kind === 'logic') {
			continue;
		}
		$src = file_get_contents($f);
		$tokens = [];
		// A quoted or path token counts only on a line that talks about actions or the API,
		// which keeps route and page names out of the caller index.
		foreach (preg_split('/\R/', $src) as $line) {
			if (!preg_match('/action|api|logic|descriptor/i', $line)) {
				continue;
			}
			if (preg_match_all('/[\'"\/=]([a-z][a-z0-9_]*)(?=[\'"\/?&])/', $line, $m)) {
				$tokens = array_merge($tokens, $m[1]);
			}
		}
		if (preg_match_all('/\\b([a-z][a-z0-9_]*)_logic\\s*\\(/', $src, $m2)) {
			$tokens = array_merge($tokens, $m2[1]);
		}
		foreach (array_unique($tokens) as $t) {
			$idx[$t][$kind] = true;
		}
	}
	return $idx;
}

function file_class(string $rel): string {
	if (preg_match('#(^|/)logic/#', $rel)) {
		return 'logic';
	}
	if (preg_match('#(^|/)adm/#', $rel) || preg_match('#(^|/)admin/#', $rel) || strpos($rel, '/views/admin/') !== false) {
		return 'admin';
	}
	if (strpos($rel, 'plugins/joinery_ai/') === 0) {
		return 'ai';
	}
	// Member-facing files only: views outside admin, profile pages, assets and the API.
	// Shared includes (node_detail tabs, FleetClient) can be reached from admin pages, so they
	// count as admin callers.
	if (preg_match('#(^|/)(views|profile|assets|api)/#', $rel)) {
		return 'member';
	}
	return 'shared';
}

function js_files(string $ROOT): array {
	$out = [];
	$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
		function ($cur) {
			return !($cur->isDir() && in_array($cur->getFilename(), SKIP_DIRS, true));
		}
	));
	foreach ($it as $f) {
		if ($f->isFile() && substr($f->getFilename(), -3) === '.js') {
			$out[] = $f->getPathname();
		}
	}
	return $out;
}

/** class:read for an action that reads exactly one model class and writes nothing. */
function derived_read_level(string $src, array $models_by_class, array $seed): string {
	preg_match_all('/new\s+(\w+)\s*\(/', $src, $n);
	$read = array_values(array_unique(array_filter($n[1], fn($c) => isset($models_by_class[$c]))));
	if (count($read) !== 1) {
		return '?';
	}
	$class = $seed['models'][$read[0]]['class'] ?? null;
	return $class === null ? '?' : $class . ':read';
}

/** class:verb for an action that touches one model, or '?' when the code does not settle it. */
function derived_action_level(string $src, string $written, array $models_by_class, array $seed): string {
	if ($written === '-' || $written === '?' || strpos($written, ',') !== false) {
		return '?';
	}
	if (!isset($models_by_class[$written])) {
		return '?';
	}
	$class = $seed['models'][$written]['class'] ?? null;
	if ($class === null) {
		return '?';
	}
	return $class . ':' . write_verb($src);
}
