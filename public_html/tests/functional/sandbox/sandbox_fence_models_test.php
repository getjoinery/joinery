<?php
/** @joinery-test
 * name: sandbox_fence_models
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * The model layer inside a sandboxed plugin's fence
 * (specs/sandboxed_php_plugins.md WP4, design 5): native model code, each
 * load, save, trash and list an ask to core.
 *
 * What is pinned here, unfenced through the same PHP command line and, where
 * this box has the plugin's user, in the real fence:
 *  - its own model by its short name: create, read, update, list with a
 *    filter, a sort and a limit, count, count_all, trash; a field set by its
 *    manifest name; a missing id loads no key;
 *  - a class of its own in src/data extending its model adds methods, and its
 *    collection yields that class;
 *  - a core model (Page) and a native plugin's (Location) it was approved to
 *    read load by id; one it was not approved for throws, saying so;
 *  - a collection with no limit is read a page at a time;
 *  - refused, each saying why: "IS NULL" as a value, a split-parenthesis OR,
 *    a NULL filter, an OR collection, two sort columns, an offset off the
 *    page, restoring or removing a row, a collection of its own that writes
 *    getMultiResults(), raw SQL;
 *  - on the site, outside the fence, SystemBase::$remote_store is null.
 *
 * Run: php tests/run.php db --filter=sandbox_fence_models
 *
 * @version 1.1 - reviewer2 G1-G3, G5, G6: a clashing model name, the plugin's prepare(), a class in plugin.php,
 *                the code line on the approval screen, fence-only declarations
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

/** The plugin's source, for a plugin named $name. */
function sbx_fm_files(string $name): array {
	$plugin_php = <<<'PHP'
<?php
function sbx_try(callable $fn) {
	try {
		return array('ok' => $fn());
	} catch (Throwable $e) {
		return array('threw' => get_class($e), 'message' => $e->getMessage());
	}
}

function on_ui(array $input) {
	$v = $input['values'] ?? array();
	$o = array();
	$n = new Note();
	$n->set('text', 'alpha');
	$n->set('qty', 3);
	$n->save();
	$o['created'] = $n->key;
	$o['class'] = get_class($n);
	$n2 = new Note();
	$n2->set('text', 'alpha');
	$n2->set('qty', 9);
	$n2->save();
	$read = new Note($n->key, TRUE);
	$o['read_text'] = $read->get('text');
	$o['shout'] = $read->shout();
	$read->set('qty', 4);
	$read->save();
	$o['updated_qty'] = (new Note($n->key, TRUE))->get('qty');
	$list = new MultiNote(array('text' => 'alpha', 'deleted' => false), array('qty' => 'DESC'), 10);
	$o['list_count'] = count($list);
	$o['list_order'] = array();
	foreach ($list as $row) {
		$o['list_order'][] = (int)$row->get('qty');
		$o['list_class'] = get_class($row);
	}
	$o['count_all'] = (new MultiNote(array('text' => 'alpha')))->count_all();
	$o['missing'] = (new Note(999999999, TRUE))->key;
	$o['trash'] = sbx_try(function () use ($n2) { return $n2->soft_delete(); });
	$o['trashed_time'] = (new Note($n2->key, TRUE))->get('delete_time') !== null;
	$o['live_after_trash'] = count(new MultiNote(array('text' => 'alpha', 'deleted' => false)));
	$o['undelete'] = sbx_try(function () use ($n2) { return $n2->undelete(); });
	$o['permanent'] = sbx_try(function () use ($n2) { return $n2->permanent_delete(); });
	$o['page'] = (new Page((int)$v['page'], TRUE))->get('pag_title');
	$o['location'] = (new Location((int)$v['location'], TRUE))->get('loc_name');
	$o['user'] = sbx_try(function () use ($v) { return (new User((int)$v['user'], TRUE))->key; });
	$o['unlimited'] = count(new MultiNote(array()));
	$o['is_null'] = sbx_try(function () { return count(new MultiNote(array('text' => 'IS NULL'))); });
	$o['paren'] = sbx_try(function () { return count(new MultiNote(array('(text' => "= 'a' OR 1=1)"))); });
	$o['null'] = sbx_try(function () { return count(new MultiNote(array('text' => null))); });
	$o['or'] = sbx_try(function () { return count(new MultiNote(array('text' => 'a', 'qty' => 1), array(), null, null, 'OR')); });
	$o['two_sorts'] = sbx_try(function () { return count(new MultiNote(array(), array('qty' => 'ASC', 'text' => 'ASC'))); });
	$o['offset'] = sbx_try(function () { return count(new MultiNote(array(), array(), 10, 5)); });
	$o['own_sql'] = sbx_try(function () { return count(new MultiSbxSqlNotes(array())); });
	$o['raw_sql'] = sbx_try(function () { return DbConnector::get_instance()->get_db_link()->query('SELECT 1'); });
	$o['rule'] = sbx_try(function () { $b = new Note(); $b->set('text', 'bad'); $b->save(); return $b->key; });
	$o['bad_rows'] = count(new MultiNote(array('text' => 'bad')));
	$o['inline'] = sbx_try(function () { $i = new SbxInlineNote(); $i->set('text', 'inline'); $i->save(); return $i->key; });
	return $o;
}
PHP;
	// A model class written in plugin.php itself, not in src/data.
	$plugin_php .= "\nclass SbxInlineNote extends Sbx_" . $name . "_Note {}\n";
	return array(
		'plugin.php' => $plugin_php,
		'data/Note.php' => "<?php\nclass Note extends Sbx_" . $name . "_Note {\n\tfunction shout() { return strtoupper((string)\$this->get('text')); }\n"
			. "\tfunction prepare() { if (\$this->get('text') === 'bad') { throw new RuntimeException('no bad notes'); } }\n}\n",
		'data/SqlNotes.php' => "<?php\nclass MultiSbxSqlNotes extends MultiNote {\n\tprotected function getMultiResults(\$only_count = false, \$debug = false) { return null; }\n}\n",
	);
}

$manifest = array(
	'models' => array(array('name' => 'Note', 'fields' => array(
		array('name' => 'text', 'type' => 'string', 'max_length' => 80),
		array('name' => 'qty', 'type' => 'integer'),
	))),
	'permissions' => array('records' => array('Page' => array('read'), 'Location' => array('read'))),
	'limits' => array('memory_mb' => 32, 'timeout_ms' => 10000),
);

function sbx_fm_checks(array $result, string $label, string $name, array $values): void {
	check($result['status'] === SandboxLog::STATUS_OK, $label . ': the call is answered', json_encode(array($result['status'], $result['message'])));
	$o = is_array($result['output'] ?? null) ? $result['output'] : array();
	$class = SandboxManifest::classFor($name, 'Note');
	$prefix = SandboxManifest::prefixFor($name, 'Note');
	check(($o['created'] ?? 0) > 0 && ($o['class'] ?? '') === 'Note', $label . ': new Note() and save() made a row, as its own class', json_encode(array($o['created'] ?? null, $o['class'] ?? null)));
	$row = new $class((int)($o['created'] ?? 0), TRUE);
	check($row->key && $row->get($prefix . '_text') === 'alpha', $label . ': the row is in its table, its field set by the manifest name');
	check(($o['read_text'] ?? null) === 'alpha' && ($o['shout'] ?? null) === 'ALPHA', $label . ': new Note($id, TRUE) read it, with the method its class adds');
	check(($o['updated_qty'] ?? null) == 4, $label . ': an update saved', json_encode($o['updated_qty'] ?? null));
	check(($o['list_count'] ?? 0) === 2 && ($o['list_order'] ?? array()) === array(9, 4) && ($o['list_class'] ?? '') === 'Note',
		$label . ': a filtered, sorted, limited list, yielding its own class', json_encode(array($o['list_count'] ?? null, $o['list_order'] ?? null, $o['list_class'] ?? null)));
	check(($o['count_all'] ?? null) === 2, $label . ': count_all()', json_encode($o['count_all'] ?? null));
	check(array_key_exists('missing', $o) && $o['missing'] === null, $label . ': a missing id loads no key');
	check(($o['trash']['ok'] ?? null) === true && ($o['trashed_time'] ?? false) === true && ($o['live_after_trash'] ?? null) === 1,
		$label . ': soft_delete() puts a row in the trash', json_encode(array($o['trash'] ?? null, $o['live_after_trash'] ?? null)));
	check(strpos((string)($o['undelete']['message'] ?? ''), 'admin') !== false && strpos((string)($o['permanent']['message'] ?? ''), 'never removes') !== false,
		$label . ': restoring or removing a row is refused', json_encode(array($o['undelete'] ?? null, $o['permanent'] ?? null)));
	check(($o['page'] ?? null) === $values['page_title'], $label . ': a core model it may read (Page) loads by id', json_encode($o['page'] ?? null));
	check(($o['location'] ?? null) === $values['location_name'], $label . ': a native plugin\'s model it may read (Location) loads by id', json_encode($o['location'] ?? null));
	check(($o['user']['threw'] ?? '') === 'SandboxPipeException' && strpos((string)($o['user']['message'] ?? ''), 'not approved') !== false,
		$label . ': a model it was not approved for is refused, saying so', json_encode($o['user'] ?? null));
	check(($o['unlimited'] ?? null) === 2, $label . ': a list with no limit is read whole', json_encode($o['unlimited'] ?? null));
	check(strpos((string)($o['rule']['message'] ?? ''), 'no bad notes') !== false && ($o['bad_rows'] ?? null) === 0,
		$label . ': its own prepare() runs and refuses the save, which never reaches core', json_encode(array($o['rule'] ?? null, $o['bad_rows'] ?? null)));
	$inline = new $class((int)($o['inline']['ok'] ?? 0), TRUE);
	check($inline->key && $inline->get($prefix . '_text') === 'inline', $label . ': a model class written in plugin.php saves to its model', json_encode($o['inline'] ?? null));
	foreach (array('is_null' => 'SQL text', 'paren' => 'SQL text', 'null' => 'not carried', 'or' => 'OR collection', 'two_sorts' => 'one column',
		'offset' => 'whole number of pages', 'own_sql' => 'getMultiResults', 'raw_sql' => 'no database') as $key => $words) {
		check(strpos((string)($o[$key]['message'] ?? ''), $words) !== false, $label . ': refused, saying why: ' . $key, json_encode($o[$key] ?? null));
	}
}

try {
	check(SystemBase::$remote_store === null, 'On the site the model layer goes to the database (no remote store)');
	$clash = SandboxManifest::fromArray(array('name' => 'hts_clash', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
		'models' => array(array('name' => 'Post', 'fields' => array(array('name' => 'x', 'type' => 'text'))))), 'hts_clash');
	check(strpos(implode(' ', SandboxPluginManager::modelCollisions($clash)), 'taken by the site') !== false,
		'A model named like a core class (Post) is refused at install', json_encode(SandboxPluginManager::modelCollisions($clash)));
	$described = SandboxConsent::describe(SandboxConsent::grantsOf(SandboxManifest::fromArray(array('name' => 'hts_desc', 'title' => 'x', 'version' => '1.0.0',
		'platform' => 1, 'permissions' => array('records' => array('Location' => array('read'), 'Page' => array('read')))), 'hts_desc')));
	check(isset($described['Read this program code']) && count($described['Read this program code']) === 1,
		'Approving a native plugin\'s model names that plugin\'s code too, and core\'s none', json_encode($described['Read this program code'] ?? null));
	try {
		SandboxModels::declareExtendable('hts_x', array('name' => 'Nope', 'fields' => array()));
		check(false, 'The fence\'s declarations refuse on the site');
	} catch (LogicException $e) {
		check(true, 'The fence\'s declarations refuse on the site');
	}
	if (!class_exists('Location')) {
		harness_skip('The fenced model layer', 'the event_manager plugin is not active here, and the test reads one of its models');
		harness_finish();
	}
	$admin = make_user('SbxModelsAdmin', 10);
	$other = make_user('SbxModelsOther', 0);
	$page = new Page(NULL);
	$page->set('pag_title', 'Sandbox fence page ' . bin2hex(random_bytes(3)));
	$page->set('pag_link', 'sbx-fence-' . bin2hex(random_bytes(4)));
	$page->save();
	$page->load();
	harness_register_model('Page', (int)$page->key);
	$location = new Location(NULL);
	$location->set('loc_name', 'Sandbox fence hall ' . bin2hex(random_bytes(3)));
	$location->set('loc_link', 'sbx-fence-hall-' . bin2hex(random_bytes(4)));
	$location->save();
	$location->load();
	harness_register_model('Location', (int)$location->key);
	$values = array('page' => (int)$page->key, 'location' => (int)$location->key, 'user' => (int)$other->key,
		'page_title' => $page->get('pag_title'), 'location_name' => $location->get('loc_name'));

	$run = function (string $name, string $label, bool $fenced) use ($manifest, $admin, $values) {
		$pm = PluginManager::getInstance();
		$pm->install($name, false);
		sandbox_approve($name);
		$pm->activate($name);
		SandboxPlugins::reset();
		if (!$fenced) {
			SandboxRunner::$command_override = function (array $envelope) {
				$cmd = SandboxRunner::command($envelope);
				return array_slice($cmd, array_search('--', $cmd, true) + 1);
			};
		}
		$started = microtime(true);
		$result = SandboxHost::ui($name, 'go', array('page' => $values['page'], 'location' => $values['location'], 'user' => $values['user']), $admin);
		$ms = (int)round((microtime(true) - $started) * 1000);
		SandboxRunner::$command_override = null;
		sbx_fm_checks($result, $label, $name, $values);
		$asks = count(array_filter($result['calls'] ?? array(), function ($c) { return $c['door'] === 'api'; }));
		check(true, $label . ': ' . $asks . ' asks in ' . $ms . ' ms (V4)');
	};

	section('Unfenced, the same PHP command line');
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$name = 'hts_' . bin2hex(random_bytes(3));
	sandbox_fixture_plugin($manifest, array('name' => $name, 'files' => sbx_fm_files($name)));
	$run($name, 'Unfenced', false);

	section('In the fence');
	$fence = 'sbxmodels';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced model layer', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => sbx_fm_files($fence), 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced model layer', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$run($fence, 'Fenced', true);
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
