<?php
/**
 * The built-in list query of a collection that writes none
 * (spec collection_default_query).
 *
 * A Multi class with no getMultiResults() of its own lists its model's table
 * by the model's columns. What is pinned here:
 *  - it filters by a column in full and without its prefix, by a list of
 *    values, by NULL and by `deleted`; sorts, limits and counts;
 *  - an option that names no column is refused, never dropped, including a
 *    $default_options key;
 *  - an option the class's source merely mentions in a helper is still a
 *    column filter: a "0" or "false" is not dropped by the yes/no reader, and
 *    "true" on a text column stays text;
 *  - a written query that calls the built-in is refused;
 *  - is_columns_only() is answered per class;
 *  - a collection with its own query reads a yes/no word as before.
 *
 * @version 1.0
 */

/** @joinery-test
 * name: collection_default_query
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** No query of its own. */
class MultiCdqPost extends SystemMultiBase {
	protected static $model_class = 'Post';
}

/**
 * No query of its own, and helpers that mention options: one only checked for
 * being passed, one only tested, one that names no column.
 */
class MultiCdqHelperPost extends SystemMultiBase {
	protected static $model_class = 'Post';
	function was_title_passed() { return isset($this->options['title']); }
	function wants_short() { return !empty($this->options['short_description']); }
	function has_other() { return isset($this->options['cdq_no_such_column']); }
}

class MultiCdqDefaultPost extends SystemMultiBase {
	protected static $model_class = 'Post';
	protected static $default_options = array('deleted' => false);
}

class MultiCdqBadDefaultPost extends SystemMultiBase {
	protected static $model_class = 'Post';
	protected static $default_options = array('cdq_not_a_column' => 1);
}

class MultiCdqNoModel extends SystemMultiBase {
}

/** A written query that hands half its work to the built-in. */
class MultiCdqParentCall extends SystemMultiBase {
	protected static $model_class = 'Post';
	protected function getMultiResults($only_count = false, $debug = false) {
		return parent::getMultiResults($only_count, $debug);
	}
}

/** What a call throws, by class name, or '' when it does not. */
function cdq_thrown(callable $fn): string {
	try {
		$fn();
	} catch (Throwable $e) {
		return get_class($e);
	}
	return '';
}

/** The ids a collection yields, sorted. */
function cdq_ids(SystemMultiBase $multi): array {
	$ids = array();
	foreach ($multi as $post) {
		$ids[] = (int)$post->key;
	}
	sort($ids);
	return $ids;
}

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));

	section('Setup');
	$owner = make_user($suffix . 'CQ', 0);
	$other = make_user($suffix . 'CR', 0);
	$uid = (int)$owner->key;
	$rows = array(
		'a'     => array('pst_title' => 'HarnessTest cdq ' . $suffix . ' a', 'pst_short_description' => 'plain', 'pst_is_published' => true),
		'b'     => array('pst_title' => 'HarnessTest cdq ' . $suffix . ' b', 'pst_short_description' => 'true', 'pst_is_published' => false),
		'zero'  => array('pst_title' => '0', 'pst_short_description' => null, 'pst_is_published' => false),
		'false' => array('pst_title' => 'false', 'pst_short_description' => null, 'pst_is_published' => false),
		'gone'  => array('pst_title' => 'HarnessTest cdq ' . $suffix . ' gone', 'pst_short_description' => 'plain', 'pst_is_published' => true),
	);
	$ids = array();
	foreach ($rows as $name => $fields) {
		$post = new Post(NULL);
		foreach ($fields as $column => $value) {
			$post->set($column, $value);
		}
		$post->set('pst_usr_user_id', $uid);
		$post->set('pst_link', 'harnesstest-cdq-' . strtolower($suffix) . '-' . $name);
		$post->set('pst_body', 'A post of the collection_default_query suite.');
		$post->save();
		$post->load();
		harness_register_model('Post', (int)$post->key);
		$ids[$name] = (int)$post->key;
	}
	$gone = new Post($ids['gone'], TRUE);
	$gone->soft_delete();
	$all = array_values($ids);
	sort($all);
	$live = array($ids['a'], $ids['b'], $ids['zero'], $ids['false']);
	sort($live);
	check(count($all) === 5, 'Five posts of one user', json_encode($ids));

	section('Which collections have no query of their own');
	check(MultiCdqPost::is_columns_only() === true, 'A collection with no getMultiResults() is columns-only');
	check(MultiPost::is_columns_only() === false, 'A collection with its own is not');
	check(MultiCdqPost::is_columns_only() === true && MultiCdqParentCall::is_columns_only() === false, 'and each class has its own answer');

	section('Filters by the model\'s columns');
	check(cdq_ids(new MultiCdqPost(array('pst_usr_user_id' => $uid))) === $all, 'A column by its full name');
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid))) === $all, 'and without its prefix');
	check(cdq_ids(new MultiCdqPost(array('post_id' => array($ids['a'], $ids['zero'], 999999999)))) === array_values(array_filter($all, function ($id) use ($ids) { return in_array($id, array($ids['a'], $ids['zero']), true); })),
		'A list of values reads any of them');
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid, 'deleted' => false))) === $live, 'deleted false reads the live rows');
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid, 'deleted' => true))) === array($ids['gone']), 'deleted true reads the deleted one');
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid, 'deleted' => 'false'))) === $live, 'and the word false means no');
	$empty_short = array($ids['zero'], $ids['false']);
	sort($empty_short);
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid, 'short_description' => null))) === $empty_short, 'NULL reads the rows where the column is empty');
	$published = array($ids['a'], $ids['gone']);
	sort($published);
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid, 'is_published' => true))) === $published, 'A boolean column');
	check(cdq_ids(new MultiCdqPost(array('usr_user_id' => $uid, 'is_published' => 'false', 'deleted' => false))) === array_values(array_diff($live, array($ids['a']))),
		'and the word false on a boolean column reads the rows that are not');

	section('Sort, limit and count');
	$first = new MultiCdqPost(array('usr_user_id' => $uid), array('post_id' => 'DESC'), 2);
	$got = array();
	foreach ($first as $post) {
		$got[] = (int)$post->key;
	}
	check($got === array($all[4], $all[3]), 'Sorted and limited', json_encode($got));
	$second = new MultiCdqPost(array('usr_user_id' => $uid), array('post_id' => 'DESC'), 2, 2);
	$got = array();
	foreach ($second as $post) {
		$got[] = (int)$post->key;
	}
	check($got === array($all[2], $all[1]), 'and offset', json_encode($got));
	check((int)(new MultiCdqPost(array('usr_user_id' => $uid)))->count_all() === 5, 'count_all() is the rows a loop yields');
	check(cdq_thrown(function () use ($uid) { (new MultiCdqPost(array('usr_user_id' => $uid), array('cdq_no_such' => 'ASC')))->count(); }) === 'UnsortableColumnException', 'A sort on no column of the model is refused');

	section('An option that names no column is refused');
	check(cdq_thrown(function () { (new MultiCdqPost(array('cdq_no_such_column' => 1)))->count_all(); }) === 'UnknownMultiOptionException', 'on count_all()');
	check(cdq_thrown(function () { (new MultiCdqPost(array('cdq_no_such_column' => 1)))->load(); }) === 'UnknownMultiOptionException', 'and on load()');
	check(cdq_thrown(function () { (new MultiCdqBadDefaultPost())->count_all(); }) === 'UnknownMultiOptionException', 'A default option that names no column is refused too');
	check(cdq_ids(new MultiCdqDefaultPost(array('usr_user_id' => $uid))) === $live, 'A default option that is core\'s own filters');

	section('An option a helper mentions is still a column');
	check(cdq_thrown(function () { (new MultiCdqHelperPost(array('cdq_no_such_column' => 1)))->count_all(); }) === 'UnknownMultiOptionException', 'A mentioned key that names no column is refused');
	check(cdq_ids(new MultiCdqHelperPost(array('usr_user_id' => $uid, 'title' => '0'))) === array($ids['zero']), 'A key only checked for being passed, given 0, filters on 0');
	check(cdq_ids(new MultiCdqHelperPost(array('usr_user_id' => $uid, 'title' => 'false'))) === array($ids['false']), 'and given the word false, filters on that word');
	check(cdq_ids(new MultiCdqHelperPost(array('usr_user_id' => $uid, 'short_description' => 'true'))) === array($ids['b']), 'A key only tested, given the word true on a text column, compares it as text');

	section('The built-in is not half of a written query');
	check(cdq_thrown(function () { (new MultiCdqParentCall(array()))->count_all(); }) === 'SystemBaseException', 'A written query that calls it is refused');
	check(cdq_thrown(function () { (new MultiCdqNoModel(array()))->count_all(); }) === 'SystemBaseException', 'A collection with no model throws, and runs no query');

	section('The API owner scope still narrows');
	$scoped = new MultiCdqPost(array('post_id' => $all));
	$scoped->api_owner_scope = array('pst_usr_user_id', (int)$other->key);
	check((int)$scoped->count_all() === 0, 'Another member sees none of these rows');
	$scoped = new MultiCdqPost(array('post_id' => $all));
	$scoped->api_owner_scope = array('pst_usr_user_id', $uid);
	check((int)$scoped->count_all() === 5, 'and their owner sees them');

	section('A collection with its own query reads as before');
	$yes = (int)(new MultiPost(array('user_id' => $uid, 'deleted' => true)))->count_all();
	$no = (int)(new MultiPost(array('user_id' => $uid, 'deleted' => false)))->count_all();
	check($yes === 1 && $no === 4, 'MultiPost reads deleted true and false', "$yes $no");
	check((int)(new MultiPost(array('user_id' => $uid, 'deleted' => 'false')))->count_all() === $no
		&& (int)(new MultiPost(array('user_id' => $uid, 'deleted' => 'true')))->count_all() === $yes, 'and the words for them');
	// `published` is a yes/no option MultiPost's own source reads, so its
	// words go through the reader a columns-only class now skips.
	$pub = (int)(new MultiPost(array('user_id' => $uid, 'published' => true)))->count_all();
	$unpub = (int)(new MultiPost(array('user_id' => $uid, 'published' => false)))->count_all();
	check($pub === 2 && $unpub === 3, 'MultiPost reads its own yes/no option', "$pub $unpub");
	check((int)(new MultiPost(array('user_id' => $uid, 'published' => 'true')))->count_all() === 2
		&& (int)(new MultiPost(array('user_id' => $uid, 'published' => 'false')))->count_all() === 3, 'and the words true and false for it');
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
