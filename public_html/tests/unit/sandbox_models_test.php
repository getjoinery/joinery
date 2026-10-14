<?php
/** @joinery-test
 * name: sandbox_models
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The rules that make it safe to declare sandboxed models' classes at run
 * time (SandboxModels), checked from the source tree with no database.
 *
 * What is pinned here:
 *  - eval() appears in one file, SandboxModels.php (PHP's own tokenizer
 *    finds the construct, so a comment or a CSP string is not counted);
 *  - no class in the tree starts with Sbx_ or MultiSbx_, compared without
 *    case as PHP compares class names, and no model's table starts with
 *    sbx_: those names are the sandboxed tier's alone, so no sandboxed model
 *    can be, or shadow, a core or native one;
 *  - names derived from different plugin and model names never meet, in a
 *    class, a table or a setting, with or without case;
 *  - the pattern checked right before eval accepts every derived class name
 *    and nothing that could carry code.
 *
 * Run: php tests/unit/sandbox_models_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$base = PathHelper::getBasePath();
$files = array();
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
	if ($file->getExtension() !== 'php') continue;
	$rel = ltrim(str_replace($base, '', $file->getPathname()), '/');
	if (strpos($rel, 'vendor/') === 0 || strpos($rel, 'tests/') === 0 || strpos($rel, '/tests/') !== false) continue;
	$files[$rel] = $file->getPathname();
}

section('One place declares classes at run time');
$evals = array();
$sbx_classes = array();
$sbx_tables = array();
foreach ($files as $rel => $path) {
	$src = file_get_contents($path);
	$tokens = token_get_all($src);
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++) {
		$t = $tokens[$i];
		if (!is_array($t)) continue;
		if ($t[0] === T_EVAL) {
			$evals[$rel] = true;
		}
		if ($t[0] === T_CLASS) {
			for ($j = $i + 1; $j < $count && $j < $i + 4; $j++) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					if (stripos($tokens[$j][1], 'sbx_') === 0 || stripos($tokens[$j][1], 'multisbx_') === 0) {
						$sbx_classes[] = $rel . ': ' . $tokens[$j][1];
					}
					break;
				}
			}
		}
	}
	if (preg_match_all('~\$tablename\s*=\s*[\'"](sbx_[^\'"]*)~i', $src, $m)) {
		foreach ($m[1] as $table) {
			$sbx_tables[] = $rel . ': ' . $table;
		}
	}
}
check(array_keys($evals) === array('includes/SandboxModels.php'), 'eval() appears only in SandboxModels', implode(', ', array_keys($evals)));
check($sbx_classes === array(), 'No class in the tree starts with Sbx_ (any case)', implode(', ', $sbx_classes));
check($sbx_tables === array(), 'No model in the tree has a table starting sbx_', implode(', ', $sbx_tables));
check(count($files) > 500, 'The scan saw the tree (' . count($files) . ' files)');

section('Derived names never meet');
$plugins = array('foo', 'foo_bar', 'foo_bar_baz', 'bar', 'ab', 'a1', 'a_b', 'ev', 'e_mail', 'notifier', 'x9_y');
$models = array('Baz', 'BarBaz', 'Item', 'Ent', 'Sender', 'SentLog', 'Sentlog', 'Abc', 'ABTest', 'AbTest', 'X1', 'Log');
$seen = array('class' => array(), 'table' => array(), 'setting' => array());
$clashes = array();
foreach ($plugins as $plugin) {
	if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
		$clashes[] = "test plugin name $plugin is not valid";
		continue;
	}
	foreach ($models as $model) {
		$key = $plugin . '/' . $model;
		$class = strtolower(SandboxManifest::classFor($plugin, $model));
		$table = SandboxManifest::tableFor($plugin, $model);
		// Models told apart only by case are one model (the manifest refuses
		// the pair), so they may share a table but never across plugins.
		$model_key = $plugin . '/' . strtolower($model);
		foreach (array('class' => $class, 'table' => $table) as $kind => $derived) {
			if (isset($seen[$kind][$derived]) && $seen[$kind][$derived] !== $model_key) {
				$clashes[] = "$kind $derived: $key and {$seen[$kind][$derived]}";
			}
			$seen[$kind][$derived] = $model_key;
		}
	}
	foreach (array('api_key', 'bar_api_key', 'key', 'bar_key') as $setting) {
		$name = SandboxManifest::settingName($plugin, $setting);
		if (isset($seen['setting'][$name])) {
			$clashes[] = "setting $name: $plugin/$setting and {$seen['setting'][$name]}";
		}
		$seen['setting'][$name] = $plugin . '/' . $setting;
	}
}
check($clashes === array(), 'Classes, tables and settings from different names are all different', implode(' | ', array_slice($clashes, 0, 5)));
check(SandboxManifest::classFor('ev', 'Ent') !== 'EvEnt' && strcasecmp(SandboxManifest::classFor('ev', 'Ent'), 'Event') !== 0,
	'A plugin and model cannot spell a core class name');

section('The pattern checked before eval');
foreach (array('Sbx_notifier_SentLog', 'Sbx_chat_bridge_Item', 'Sbx_a1_X1') as $good) {
	check(preg_match(SandboxModels::CLASS_PATTERN, $good) === 1, "$good is a class it will declare");
}
foreach (array('Event', 'Sbx_notifier_SentLog{}', "Sbx_notifier_SentLog\n", 'Sbx_notifier_sentlog', 'Sbx__x_Y', 'Sbx_x__y_Z',
		'Sbx_notifier_Sent_Log', 'Sbx_notifier_SentLog extends User', 'sbx_notifier_SentLog') as $bad) {
	check(preg_match(SandboxModels::CLASS_PATTERN, $bad) !== 1, json_encode($bad) . ' is refused');
}

harness_finish();
