<?php
/** @joinery-test
 * name: sandbox_uploads
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Uploads to a sandboxed plugin (spec sandboxed_plugin_uploads): its page
 * takes files, and its code keeps them with $_FILES and File as native code
 * does; the site carries out each File call with the same class.
 *
 * What is pinned here, unfenced through the same PHP command line and, where
 * this box has the plugin's user, in the real fence:
 *  - $_FILES and SandboxPage::file() hold the page's uploads, one box and a
 *    name[] box;
 *  - File::createFromUpload() keeps one: owner the viewer, private, tagged
 *    sbx_{plugin}, the title it set; the second try at the same upload fails;
 *  - new File($id, TRUE), MultiFile (with a column filter), read_bytes(),
 *    get_url(), a rename by set() and save(), soft_delete();
 *  - createFromBytes();
 *  - refused: another owner, a path that is not one of the uploads, a
 *    restriction past the title and description, a change of fil_private, a
 *    new File() saved, a file it did not make (not found);
 *  - bytes in parts: a 2.2 MB read and a 3.2 MB createFromBytes() arrive whole;
 *    with 128 MB a 40 MB read works, at the default 16 MB a 9 MB one is
 *    refused, saying how to raise the limit; a call's memory is at most what
 *    the site gives a page request;
 *  - a records grant on File reaches a file it did not make (read, list,
 *    rename), still makes none without accept_uploads; create is refused;
 *  - each is a line in the call log (door file);
 *  - not approved: every file ask refused, saying so; a new version that asks
 *    waits on approval; the approval screen says it, and warns beside a way out;
 *  - the cleaner keeps a file input only with uploads on and only in a POST
 *    form, which it sends multipart; an image under /uploads/ only with uploads on;
 *  - requestFiles() keeps PHP's shape and one-line names;
 *  - the catalog names the plugin's tag; on the site File::$remote is null.
 *
 * The uploads are scratch files the test hands the relay as this call's
 * uploads; SandboxRelay::$upload_check stands in for is_uploaded_file().
 *
 * Run: php tests/run.php db --filter=sandbox_uploads
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

/** The plugin's source: one admin page that does everything and echoes what happened as JSON. */
function sbx_up_files(): array {
	$view = <<<'PHP'
<?php
function sbx_try(callable $fn) {
	try {
		return array('ok' => $fn());
	} catch (Throwable $e) {
		return array('threw' => get_class($e), 'message' => $e->getMessage());
	}
}
$me = (int)SandboxPage::$input['user']['id'];
$q = SandboxPage::$input['query'];
$o = array();
$o['lazy'] = class_exists('SandboxRemoteFiles', false);
$o['files'] = $_FILES;
$o['file_doc'] = SandboxPage::file('doc');
$doc = $_FILES['doc'] ?? array();
$o['kept'] = sbx_try(function () use ($doc, $me) {
	$f = File::createFromUpload($doc['tmp_name'], $doc['name'], $doc['type'], $me, array('fil_title' => 'My doc'));
	return array('id' => $f->key, 'title' => $f->get('fil_title'), 'owner' => (int)$f->get('fil_usr_user_id'), 'url' => $f->get_url());
});
$id = (int)($o['kept']['ok']['id'] ?? 0);
$o['again'] = sbx_try(function () use ($doc, $me) {
	return File::createFromUpload($doc['tmp_name'], $doc['name'], $doc['type'], $me)->key;
});
$o['many'] = sbx_try(function () use ($me) {
	$ids = array();
	foreach ($_FILES['docs']['tmp_name'] as $i => $tmp) {
		$ids[] = File::createFromUpload($tmp, $_FILES['docs']['name'][$i], $_FILES['docs']['type'][$i], $me)->key;
	}
	return $ids;
});
$o['loaded'] = sbx_try(function () use ($id) {
	$f = new File($id, TRUE);
	return array('key' => $f->key, 'title' => $f->get('fil_title'), 'source' => $f->get('fil_source'), 'url' => $f->get_url());
});
$o['bytes_read'] = sbx_try(function () use ($id) {
	return (new File($id, TRUE))->read_bytes();
});
$o['listed'] = sbx_try(function () {
	$all = array();
	foreach (new MultiFile(array()) as $f) {
		$all[] = $f->key;
	}
	$titled = array();
	foreach (new MultiFile(array('fil_title' => 'My doc')) as $f) {
		$titled[] = $f->key;
	}
	return array('all' => $all, 'titled' => $titled, 'count' => count(new MultiFile(array())));
});
$o['renamed'] = sbx_try(function () use ($id) {
	$f = new File($id, TRUE);
	$f->set('fil_title', 'Renamed');
	$f->save();
	return (new File($id, TRUE))->get('fil_title');
});
$o['made'] = sbx_try(function () use ($me) {
	$f = File::createFromBytes("made by the plugin\n", 'made.txt', 'text/plain', $me);
	return array('id' => $f->key, 'bytes' => $f->read_bytes(), 'private' => $f->get('fil_private'));
});
$o['other_owner'] = sbx_try(function () use ($me) {
	return File::createFromBytes('x', 'x.txt', 'text/plain', $me + 100000)->key;
});
$o['not_upload'] = sbx_try(function () use ($me) {
	return File::createFromUpload('/etc/hostname', 'hostname', 'text/plain', $me)->key;
});
$o['public'] = sbx_try(function () use ($me) {
	return File::createFromBytes('x', 'x.txt', 'text/plain', $me, array('fil_private' => false))->key;
});
$o['flip'] = sbx_try(function () use ($id) {
	$f = new File($id, TRUE);
	$f->set('fil_private', false);
	$f->save();
	return true;
});
$o['new_row'] = sbx_try(function () {
	$f = new File(NULL);
	$f->set('fil_title', 'x');
	$f->save();
	return $f->key;
});
$o['foreign'] = sbx_try(function () use ($q) {
	$f = new File((int)$q['foreign'], TRUE);
	return $f->key;
});
$o['foreign_read'] = sbx_try(function () use ($q) {
	$f = new File(NULL);
	$f->key = (int)$q['foreign'];
	return $f->read_bytes();
});
$o['big_read'] = sbx_try(function () use ($q) {
	$b = (string)(new File((int)$q['big'], TRUE))->read_bytes();
	return array('length' => strlen($b), 'sha' => sha1($b));
});
$o['huge_read'] = sbx_try(function () use ($q) {
	return strlen((string)(new File((int)$q['huge'], TRUE))->read_bytes());
});
$o['made_big'] = sbx_try(function () use ($me) {
	$bytes = str_repeat("0123456789abcdef", 200000);
	$f = File::createFromBytes($bytes, 'big_made.bin', 'application/octet-stream', $me);
	return array('id' => $f->key, 'sha' => sha1((string)$f->read_bytes()), 'want' => sha1($bytes));
});
$made_id = (int)($o['made']['ok']['id'] ?? 0);
$o['trashed'] = sbx_try(function () use ($made_id) {
	$f = new File($made_id, TRUE);
	$f->soft_delete();
	return (new File($made_id, TRUE))->get('fil_delete_time') !== null;
});
if (!empty($q['secret'])) {
	$o['secret'] = sbx_try(function () use ($q) {
		return @file_get_contents($q['secret']);
	});
}
echo json_encode($o);
PHP;
	$grant = <<<'PHP'
<?php
function sbx_try(callable $fn) {
	try {
		return array('ok' => $fn());
	} catch (Throwable $e) {
		return array('threw' => get_class($e), 'message' => $e->getMessage());
	}
}
$q = SandboxPage::$input['query'];
$me = (int)SandboxPage::$input['user']['id'];
$o = array();
$o['read'] = sbx_try(function () use ($q) {
	return (new File((int)$q['foreign'], TRUE))->read_bytes();
});
$o['listed'] = sbx_try(function () use ($q) {
	$ids = array();
	foreach (new MultiFile(array('fil_file_id' => (int)$q['foreign'])) as $f) {
		$ids[] = $f->key;
	}
	return $ids;
});
$o['renamed'] = sbx_try(function () use ($q) {
	$f = new File((int)$q['foreign'], TRUE);
	$f->set('fil_title', 'Renamed by a grant');
	$f->save();
	return (new File((int)$q['foreign'], TRUE))->get('fil_title');
});
$o['made'] = sbx_try(function () use ($me) {
	return File::createFromBytes('x', 'x.txt', 'text/plain', $me)->key;
});
$o['huge'] = sbx_try(function () use ($q) {
	return strlen((string)(new File((int)$q['huge'], TRUE))->read_bytes());
});
echo json_encode($o);
PHP;
	return array('admin/go.php' => $view, 'admin/grant.php' => $grant);
}

/** A scratch file standing in for one upload. */
function sbx_up_tmp(string $dir, string $bytes): string {
	$path = $dir . '/php' . bin2hex(random_bytes(4));
	file_put_contents($path, $bytes);
	return $path;
}

/** The files of one page post, in PHP's $_FILES shape, and the paths the relay may take. */
function sbx_up_post(string $dir): array {
	$doc = sbx_up_tmp($dir, "The uploaded document.\n");
	$a = sbx_up_tmp($dir, "first of many\n");
	$b = sbx_up_tmp($dir, "second of many\n");
	$files = array(
		'doc'  => array('name' => "report\r\n.txt", 'type' => 'text/plain', 'size' => 23, 'error' => UPLOAD_ERR_OK, 'tmp_name' => $doc),
		'docs' => array('name' => array('a.txt', 'b.txt'), 'type' => array('text/plain', 'text/plain'), 'size' => array(14, 15),
			'error' => array(UPLOAD_ERR_OK, UPLOAD_ERR_OK), 'tmp_name' => array($a, $b)),
	);
	return array(SandboxPages::requestFiles($files), array($doc, $a, $b));
}

/** Check one run's answer. */
function sbx_up_checks(array $result, string $label, string $plugin, User $viewer, int $foreign, bool $fenced): void {
	check(($result['status'] ?? '') === 'ok', $label . ': the page call answers ok', json_encode(array($result['status'] ?? null, $result['message'] ?? null)));
	$o = json_decode((string)($result['output']['body'] ?? ''), true);
	$o = is_array($o) ? $o : array();
	$tag = 'sbx_' . $plugin;

	check(($o['lazy'] ?? null) === false, $label . ': nothing of the file layer is loaded until the plugin names File');
	check(($o['files']['doc']['name'] ?? null) === 'report .txt' && is_string($o['files']['doc']['tmp_name'] ?? null),
		$label . ': $_FILES holds the upload, its name one line', json_encode($o['files']['doc'] ?? null));
	check(($o['files']['docs']['name'] ?? null) === array('a.txt', 'b.txt'), $label . ': a name[] box is lists, as PHP shapes it');
	check(($o['file_doc'] ?? null) === ($o['files']['doc'] ?? false), $label . ': SandboxPage::file() is the same entry');

	$kept = $o['kept']['ok'] ?? null;
	check(is_array($kept) && $kept['id'] > 0 && $kept['title'] === 'My doc' && $kept['owner'] === (int)$viewer->key,
		$label . ': createFromUpload() keeps it, the viewer\'s, with its title', json_encode($o['kept'] ?? null));
	$row = new File((int)($kept['id'] ?? 0), TRUE);
	check($row->key && $row->get('fil_source') === $tag && $row->get('fil_private') && (int)$row->get('fil_usr_user_id') === (int)$viewer->key,
		$label . ': on the site it is private, the viewer\'s, tagged ' . $tag, json_encode(array($row->get('fil_source'), $row->get('fil_private'))));
	check($row->key && $row->read_bytes() === "The uploaded document.\n", $label . ': its bytes are the upload\'s');
	check(($kept['url'] ?? null) === $row->get_url(), $label . ': get_url() in the plugin is the site\'s', json_encode(array($kept['url'] ?? null, $row->get_url())));
	check(isset($o['again']['threw']) && strpos((string)$o['again']['message'], 'no longer here') !== false, $label . ': the same upload cannot be kept twice', json_encode($o['again'] ?? null));
	check(is_array($o['many']['ok'] ?? null) && count($o['many']['ok']) === 2, $label . ': each file of a name[] box is kept', json_encode($o['many'] ?? null));

	check(($o['loaded']['ok']['key'] ?? null) === $kept['id'] && ($o['loaded']['ok']['source'] ?? null) === $tag, $label . ': new File($id, TRUE) reads it', json_encode($o['loaded'] ?? null));
	check(($o['bytes_read']['ok'] ?? null) === "The uploaded document.\n", $label . ': read_bytes() answers its bytes', json_encode($o['bytes_read'] ?? null));
	$listed = $o['listed']['ok'] ?? array();
	check(isset($listed['all']) && in_array($kept['id'], $listed['all'], true) && count($listed['all']) === 5 && $listed['count'] === 5
		&& !in_array($foreign, $listed['all'], true), $label . ': MultiFile lists its own files, and only those', json_encode($o['listed'] ?? null));
	check(($listed['titled'] ?? null) === array($kept['id']), $label . ': a column filter narrows the list', json_encode($listed['titled'] ?? null));
	check(($o['renamed']['ok'] ?? null) === 'Renamed', $label . ': set() and save() rename it', json_encode($o['renamed'] ?? null));

	check(($o['made']['ok']['bytes'] ?? null) === "made by the plugin\n" && !empty($o['made']['ok']['private']), $label . ': createFromBytes() keeps a file it made, private', json_encode($o['made'] ?? null));
	check(($o['trashed']['ok'] ?? null) === true, $label . ': soft_delete() puts it in the trash', json_encode($o['trashed'] ?? null));

	check(strpos((string)($o['other_owner']['message'] ?? ''), 'person viewing the page') !== false, $label . ': another owner is refused, saying why', json_encode($o['other_owner'] ?? null));
	check(($o['other_owner']['threw'] ?? null) === 'FileException', $label . ': as a FileException, which the site\'s File throws');
	check(strpos((string)($o['not_upload']['message'] ?? ''), 'not a file uploaded to this page') !== false, $label . ': a path that is not one of the uploads is refused', json_encode($o['not_upload'] ?? null));
	check(strpos((string)($o['public']['message'] ?? ''), 'only a file\'s title and description') !== false, $label . ': a public file is refused', json_encode($o['public'] ?? null));
	check(strpos((string)($o['flip']['message'] ?? ''), 'only a file\'s title and description') !== false, $label . ': fil_private cannot be changed', json_encode($o['flip'] ?? null));
	check(strpos((string)($o['new_row']['message'] ?? ''), 'createFromUpload()') !== false, $label . ': a new File() saved is refused, naming the way', json_encode($o['new_row'] ?? null));
	check(array_key_exists('ok', $o['foreign'] ?? array()) && $o['foreign']['ok'] === null, $label . ': a file it did not make is not there to it', json_encode($o['foreign'] ?? null));
	check(strpos((string)($o['foreign_read']['message'] ?? ''), 'among this plugin') !== false, $label . ': nor can it read one\'s bytes', json_encode($o['foreign_read'] ?? null));
	check(($o['big_read']['ok']['length'] ?? null) === 2200000 && ($o['big_read']['ok']['sha'] ?? null) === sha1(str_repeat('b', 2200000)),
		$label . ': read_bytes() of a 2.2 MB file arrives whole, in parts', json_encode($o['big_read'] ?? null));
	check(($o['huge_read']['ok'] ?? null) === 40000000, $label . ': with 128 MB, a 40 MB file is read whole', json_encode($o['huge_read'] ?? null));
	check(isset($o['made_big']['ok']['sha']) && $o['made_big']['ok']['sha'] === $o['made_big']['ok']['want'],
		$label . ': createFromBytes() of 3.2 MB arrives whole, in parts', json_encode($o['made_big'] ?? null));
	if ($fenced) {
		check(isset($o['secret']['threw']) || ($o['secret']['ok'] ?? null) === false, $label . ': the upload\'s temporary file cannot be read in the fence', json_encode($o['secret'] ?? null));
	}

	$doors = array();
	foreach ($result['calls'] ?? array() as $c) {
		if ($c['door'] === 'file') {
			$doors[] = preg_replace('~ \d+$~', '', $c['target']) . ' ' . $c['status'];
		}
	}
	check(in_array('upload ok', $doors, true) && in_array('read ok', $doors, true) && in_array('bytes ok', $doors, true) && in_array('list ok', $doors, true)
		&& in_array('update ok', $doors, true) && in_array('delete ok', $doors, true) && in_array('upload refused', $doors, true),
		$label . ': each file call is a line in the call log', implode('; ', array_unique($doors)));
}

try {
	section('On the site');
	check(File::$remote === null && File::remote() === null, 'File::$remote is null outside the fence');

	section('The cleaner');
	$clean = function (string $html, bool $uploads) {
		return (new SandboxHtmlCleaner(array('page_url' => '/admin/x', 'uploads' => $uploads, 'upload_url' => '/uploads/')))->clean($html);
	};
	$form = '<form method="post" enctype="text/plain"><input type="file" name="doc" accept=".pdf" multiple value="x"><input type="text" name="t"></form>';
	$on = $clean($form, true);
	check(strpos($on, 'type="file"') !== false && strpos($on, 'accept=".pdf"') !== false && strpos($on, 'multiple') !== false && strpos($on, 'value=') === false,
		'With uploads on, a file input in a POST form is kept', $on);
	check(strpos($on, 'enctype="multipart/form-data"') !== false && strpos($on, 'text/plain') === false, 'and the form is sent multipart, never with the plugin\'s enctype', $on);
	check(strpos($clean($form, false), 'type="file"') === false, 'With uploads off, a file input is dropped');
	check(strpos($clean('<form method="get"><input type="file" name="doc"></form>', true), 'type="file"') === false, 'A file input in a GET form is dropped');
	check(strpos($clean('<input type="file" name="doc">', true), 'type="file"') === false, 'A file input outside a form is dropped');
	check(strpos($clean('<img src="/uploads/abc_1.png" alt="a">', true), 'src="/uploads/abc_1.png"') !== false, 'With uploads on, an image under /uploads/ is kept');
	check(strpos($clean('<img src="/uploads/abc_1.png" alt="a">', false), '<img') === false, 'With uploads off, it is dropped');
	check(strpos($clean('<img src="/uploads/../config/x.png">', true), '<img') === false && strpos($clean('<img src="/uploads/x.png?a=1">', true), '<img') === false,
		'An /uploads/ image with .. or a query is dropped');

	section('requestFiles()');
	$shaped = SandboxPages::requestFiles(array(
		'one' => array('name' => "a\nb.txt", 'type' => 'text/plain', 'size' => 1, 'error' => 0, 'tmp_name' => '/tmp/phpA'),
		'deep' => array('name' => array('x' => array('y' => 'z')), 'tmp_name' => array('x' => array('y' => '/tmp/phpB')), 'error' => array('x' => array('y' => 0))),
		'bad name!' => array('name' => 'n', 'tmp_name' => '/tmp/phpC', 'error' => 0),
	));
	check(($shaped['one']['name'] ?? null) === 'a b.txt' && ($shaped['one']['tmp_name'] ?? null) === '/tmp/phpA', 'One box keeps PHP\'s shape, the name one line', json_encode($shaped));
	check(!isset($shaped['deep']) && !isset($shaped['bad name!']), 'A box nested past one level, or with a name that is not plain, is left out');
	$many = array('name' => array(), 'type' => array(), 'size' => array(), 'error' => array(), 'tmp_name' => array());
	for ($i = 0; $i < 25; $i++) {
		$many['name'][] = 'f' . $i;
		$many['type'][] = 'text/plain';
		$many['size'][] = 1;
		$many['error'][] = 0;
		$many['tmp_name'][] = '/tmp/php' . $i;
	}
	check(count(SandboxPages::requestFiles(array('many' => $many))['many']['tmp_name'] ?? array()) === SandboxPages::MAX_FILES, 'At most ' . SandboxPages::MAX_FILES . ' files are carried');

	section('The manifest');
	$base = array('name' => 'hts_mfx', 'title' => 'X', 'version' => '1.0.0', 'platform' => 1);
	check(SandboxManifest::problems($base + array('accept_uploads' => true)) === array(), 'accept_uploads true is valid');
	check(in_array('accept_uploads must be true or false', SandboxManifest::problems($base + array('accept_uploads' => 1)), true), 'accept_uploads 1 is refused');
	check(SandboxManifest::memoryCeilingMb() >= 16, 'The memory ceiling is the site\'s own page-request limit', SandboxManifest::memoryCeilingMb() . ' MB');
	SandboxManifest::$memory_ceiling_override = 128;
	$asked = SandboxManifest::fromArray($base + array('limits' => array('memory_mb' => 2048)))->limits()['memory_mb'];
	SandboxManifest::$memory_ceiling_override = null;
	check($asked === 128, 'A call asking for more memory than the site gives a page request gets what the site gives', (string)$asked);
	check(SandboxManifest::problems($base + array('limits' => array('memory_mb' => 2048))) === array(), 'and asking is not an error');

	$admin = make_user('SbxUploadViewer', 10);
	$scratch = harness_scratch_dir('sandbox_uploads');
	$manifest = array('accept_uploads' => true, 'admin_pages' => array(array('slug' => 'go', 'title' => 'Go')), 'limits' => array('memory_mb' => 128));
	$allowed = array();
	SandboxRelay::$upload_check = function (string $path) use (&$allowed) {
		return in_array($path, $allowed, true);
	};
	harness_defer(function () { SandboxRelay::$upload_check = null; SandboxRunner::$command_override = null; });
	$tags = array();
	harness_defer(function () use (&$tags) {
		foreach ($tags as $tag) {
			$q = DbConnector::GetPreparedStatement('SELECT fil_file_id FROM fil_files WHERE fil_source = ?');
			$q->execute(array($tag));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
				$f = new File((int)$id, TRUE);
				if ($f->key) {
					$f->permanent_delete();
				}
			}
		}
	});
	// A file on the site no plugin made, which the plugin must not reach.
	$foreign = File::createFromBytes("not the plugin's\n", 'foreign.txt', 'text/plain', (int)$admin->key, array('fil_private' => true));
	harness_defer(function () use ($foreign) {
		$foreign->permanent_delete();
	});

	$run = function (string $name, string $label, bool $fenced) use ($admin, $scratch, $manifest, &$allowed, &$tags, $foreign) {
		$pm = PluginManager::getInstance();
		$pm->install($name, false);
		sandbox_approve($name);
		$pm->activate($name);
		SandboxPlugins::reset();
		$tags[] = 'sbx_' . $name;
		// A file of the plugin's tag too large to read in the fence.
		$big = File::createFromBytes(str_repeat('b', 2200000), 'big.bin', 'application/octet-stream', (int)$admin->key,
			array('fil_private' => true, 'fil_source' => 'sbx_' . $name));
		$huge = File::createFromBytes(str_repeat('h', 40000000), 'huge.bin', 'application/octet-stream', (int)$admin->key,
			array('fil_private' => true, 'fil_source' => 'sbx_' . $name));
		list($files, $paths) = sbx_up_post($scratch);
		$allowed = $paths;
		if (!$fenced) {
			SandboxRunner::$command_override = function (array $envelope) {
				$cmd = SandboxRunner::command($envelope);
				return array_slice($cmd, array_search('--', $cmd, true) + 1);
			};
		}
		$query = array('foreign' => (string)$foreign->key, 'big' => (string)$big->key, 'huge' => (string)$huge->key);
		if ($fenced) {
			$query['secret'] = $paths[0];
		}
		$result = SandboxHost::page($name, 'admin', 'go', 'POST', $query, array(), $admin, $files);
		SandboxRunner::$command_override = null;
		sbx_up_checks($result, $label, $name, $admin, (int)$foreign->key, $fenced);
	};

	section('Unfenced, the same PHP command line');
	$name = 'hts_' . bin2hex(random_bytes(3));
	sandbox_fixture_plugin($manifest, array('name' => $name, 'files' => sbx_up_files()));
	$run($name, 'Unfenced', false);

	section('Its files are its data, whoever is viewing');
	$member = make_user('SbxUploadMember', 0);
	$theirs = File::createFromBytes("the admin's\n", 'theirs.txt', 'text/plain', (int)$admin->key,
		array('fil_private' => true, 'fil_source' => 'sbx_' . $name));
	$relay = new SandboxRelay($name, array('input' => array('user' => array('id' => (int)$member->key, 'permission' => 0))));
	$renamed = $relay->handle(array('ask' => 'record.update', 'model' => 'File', 'id' => $theirs->key, 'fields' => array('title' => 'Seen by a member')));
	check(!empty($renamed['ok']) && ($renamed['record']['fil_title'] ?? null) === 'Seen by a member',
		'With a member viewing, the plugin renames a file of its own another person owns', json_encode($renamed));
	$trashed = $relay->handle(array('ask' => 'record.delete', 'model' => 'File', 'id' => $theirs->key));
	check(!empty($trashed['ok']) && !empty($trashed['record']['fil_delete_time']), 'and trashes it', json_encode($trashed));
	$other = $relay->handle(array('ask' => 'record.update', 'model' => 'File', 'id' => $foreign->key, 'fields' => array('title' => 'x')));
	check(empty($other['ok']) && ($other['status'] ?? null) === 404 && (new File($foreign->key, TRUE))->get('fil_title') === 'foreign.txt',
		'but a file without its tag is not found, and is left as it was', json_encode($other));

	section('A records grant on File reaches every file');
	$granted = 'hts_' . bin2hex(random_bytes(3));
	sandbox_fixture_plugin(array('permissions' => array('records' => array('File' => array('read', 'update'))),
		'admin_pages' => array(array('slug' => 'grant', 'title' => 'Grant'))), array('name' => $granted, 'files' => sbx_up_files()));
	check(SandboxCompatibility::problems(SandboxPlugins::manifest($granted)) === array(), 'A records grant may name File, which the API does not serve',
		json_encode(SandboxCompatibility::problems(SandboxPlugins::manifest($granted))));
	$pm = PluginManager::getInstance();
	$pm->install($granted, false);
	sandbox_approve($granted);
	$pm->activate($granted);
	SandboxPlugins::reset();
	$tags[] = 'sbx_' . $granted;
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	$nine = File::createFromBytes(str_repeat('n', 9000000), 'nine.bin', 'application/octet-stream', (int)$admin->key, array('fil_private' => true));
	harness_defer(function () use ($nine) { $nine->permanent_delete(); });
	$result = SandboxHost::page($granted, 'admin', 'grant', 'GET', array('foreign' => (string)$foreign->key, 'huge' => (string)$nine->key), array(), $admin);
	SandboxRunner::$command_override = null;
	$o = json_decode((string)($result['output']['body'] ?? ''), true) ?: array();
	check(($o['read']['ok'] ?? null) === "not the plugin's\n", 'With the grant, it reads a file it did not make', json_encode($o['read'] ?? null));
	check(($o['listed']['ok'] ?? null) === array((int)$foreign->key), 'and lists it', json_encode($o['listed'] ?? null));
	check(($o['renamed']['ok'] ?? null) === 'Renamed by a grant', 'and renames it', json_encode($o['renamed'] ?? null));
	check(strpos((string)($o['made']['message'] ?? ''), 'not approved to take uploaded files') !== false, 'but makes none without accept_uploads', json_encode($o['made'] ?? null));
	$drive = File::createFromBytes("a drive file\n", 'drive.txt', 'text/plain', (int)$admin->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	harness_defer(function () use ($drive) { $drive->permanent_delete(); });
	$relay = new SandboxRelay($granted, array('input' => array('user' => array('id' => (int)$admin->key, 'permission' => 10))));
	$read = $relay->handle(array('ask' => 'record.get', 'model' => 'File', 'id' => $drive->key));
	$renamed = $relay->handle(array('ask' => 'record.update', 'model' => 'File', 'id' => $drive->key, 'fields' => array('title' => 'x')));
	check(!empty($read['ok']) && empty($renamed['ok']) && strpos((string)$renamed['error'], 'changes only through Drive') !== false
		&& (new File($drive->key, TRUE))->get('fil_title') === 'drive.txt', 'With the grant it reads a Drive file, but a rename is refused and the file is left as it was', json_encode($renamed));
	$trashed = $relay->handle(array('ask' => 'record.delete', 'model' => 'File', 'id' => $drive->key));
	check(empty($trashed['ok']) && (new File($drive->key, TRUE))->get('fil_delete_time') === null, 'and so is putting it in the trash', json_encode($trashed));
	check(strpos(implode(' ', SandboxConsent::describe(array('records' => array('File' => array('read', 'update'))))['Records it may use: Site core'] ?? array()), 'Drive files are read only') !== false,
		'and the approval line says Drive files are read only');
	check(strpos((string)($o['huge']['message'] ?? ''), 'half of the 16 MB') !== false && strpos((string)$o['huge']['message'], 'limits.memory_mb') !== false,
		'At the default 16 MB, a 9 MB file is refused, saying how to raise the limit', json_encode($o['huge'] ?? null));
	$words = SandboxConsent::describe(SandboxConsent::grantsOf(SandboxPlugins::manifest($granted)));
	check(strpos(implode(' ', $words['Records it may use: Site core'] ?? array()), "Every file on the site, every member's") !== false,
		'The approval screen says it reaches every file on the site', json_encode($words));
	$create = SandboxCompatibility::problems(SandboxManifest::fromArray(array('name' => 'hts_mfx', 'title' => 'X', 'version' => '1.0.0', 'platform' => 1,
		'permissions' => array('records' => array('File' => array('create'))))));
	check(strpos(implode(' ', $create), 'createFromUpload()') !== false, 'A grant to create File records is refused, naming the way', json_encode($create));

	section('Approval and the catalog');
	$grants = SandboxConsent::grantsOf(SandboxPlugins::manifest($name));
	check($grants['uploads'] === array('accept'), 'Taking uploads is a grant');
	$words = SandboxConsent::describe($grants);
	check(isset($words['Accept uploaded files']) && strpos($words['Accept uploaded files'][0], 'private to them and admins') !== false,
		'The approval screen says it takes uploads, and where they are kept', json_encode(array_keys($words)));
	check(!isset($words['Warning']), 'Alone, it is no way out, and nothing warns');
	$warned = SandboxConsent::describe(array('uploads' => array('accept'), 'outbound_hosts' => array('hooks.example.com')));
	check(strpos(implode(' ', $warned['Warning'] ?? array()), 'the files people upload to it') !== false, 'Beside outside websites, it warns the files could leave', json_encode($warned['Warning'] ?? null));
	FileSourceDeclarations::reset();
	$catalog = File::source_catalog();
	check(isset($catalog['sbx_' . $name]) && $catalog['sbx_' . $name]['label'] === SandboxPlugins::manifest($name)->title() . ' files',
		'The file catalog names the plugin\'s tag by its title', json_encode($catalog['sbx_' . $name] ?? null));

	section('Not approved');
	$plain = 'hts_' . bin2hex(random_bytes(3));
	$fixture = sandbox_fixture_plugin(array('admin_pages' => array(array('slug' => 'go', 'title' => 'Go'))), array('name' => $plain, 'files' => sbx_up_files()));
	$pm = PluginManager::getInstance();
	$pm->install($plain, false);
	sandbox_approve($plain);
	$pm->activate($plain);
	SandboxPlugins::reset();
	$tags[] = 'sbx_' . $plain;
	list($files, $paths) = sbx_up_post($scratch);
	$allowed = $paths;
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	$result = SandboxHost::page($plain, 'admin', 'go', 'POST', array('foreign' => (string)$foreign->key, 'big' => '1', 'huge' => '1'), array(), $admin, $files);
	SandboxRunner::$command_override = null;
	$o = json_decode((string)($result['output']['body'] ?? ''), true) ?: array();
	check(strpos((string)($o['kept']['message'] ?? ''), 'not approved to take uploaded files') !== false, 'A plugin not approved for uploads is refused, saying so', json_encode($o['kept'] ?? null));
	check(strpos((string)($o['made']['message'] ?? ''), 'not approved to take uploaded files') !== false, 'createFromBytes() too');
	check(isset($o['foreign']['threw']), 'and File is a model it was not approved to read', json_encode($o['foreign'] ?? null));
	$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM fil_files WHERE fil_source = ?');
	$q->execute(array('sbx_' . $plain));
	check((int)$q->fetchColumn() === 0, 'and the site keeps nothing for it');
	$data = json_decode((string)file_get_contents($fixture['dir'] . '/manifest.json'), true);
	$data['version'] = '1.0.1';
	$data['accept_uploads'] = true;
	file_put_contents($fixture['dir'] . '/manifest.json', json_encode($data));
	SandboxPlugins::reset();
	check(SandboxConsent::needsApproval($plain), 'A new version asking to take uploads waits on approval');
	check((SandboxConsent::pending($plain)['uploads'] ?? null) === array('accept'), 'and names it as what is new');
	check(SandboxConsent::effective($plain)['uploads'] === array(), 'and may not take any until then');

	section('In the fence');
	$fence = 'sbxfiles';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced file layer', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => sbx_up_files(), 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced file layer', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$run($fence, 'Fenced', true);
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
