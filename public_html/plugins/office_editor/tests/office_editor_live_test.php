<?php
/** @joinery-test
 * name: office_editor_live
 * tier: live
 * env: any
 * needs: []
 * timeout: 600
 */
/**
 * The document editor for real, on a server where the office_editor installer
 * ran (spec § 5.4): the live suite skips anywhere else.
 *
 *   - Edit and save: a three-page document is loaded into the editor through
 *     the real WOPI path (signed requests, the loopback listener), a word is
 *     typed and saved; a new version exists, the text is in the saved file, and
 *     the PDF page count is unchanged.
 *   - Capacity: ten documents held open, the eleventh is refused as busy by
 *     office_editor_open, and joining one already open is not.
 *
 * The editor is driven over its own websocket protocol, the way its page
 * does, from this process: no browser.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

if (!class_exists('OfficeEditor') || !OfficeEditor::installed()) {
	harness_skip('office editor', 'the editor is not installed on this server');
	harness_finish();
}
$soffice = '/opt/collaboraoffice/program/soffice';

// ---------------------------------------------------------------------------
section('Setup');

// Start the editor the way a page does, and wait for it.
if (OfficeEditor::fetchDiscovery() === null) {
	OfficeEditor::requestStart();
}
$deadline = time() + 60;
while (OfficeEditor::fetchDiscovery() === null && time() < $deadline) {
	usleep(500000);
}
check(OfficeEditor::fetchDiscovery() !== null, 'the editor answers');

$dblink = DbConnector::get_instance()->get_db_link();
$suffix = bin2hex(random_bytes(4));
$gq = $dblink->prepare("INSERT INTO grp_groups (grp_name, grp_category) VALUES (?, 'subscription_tier') RETURNING grp_group_id");
$gq->execute(array('office-editor-live-' . $suffix));
$grp = (int)$gq->fetchColumn();
harness_register_row('grp_groups', 'grp_group_id', $grp);
$tq = $dblink->prepare("INSERT INTO sbt_subscription_tiers (sbt_grp_group_id, sbt_tier_level, sbt_name, sbt_display_name, sbt_features, sbt_is_active)
	VALUES (?, 900, ?, 'Office Editor Live', ?::jsonb, TRUE) RETURNING sbt_subscription_tier_id");
$tq->execute(array($grp, 'office-editor-live-' . $suffix, json_encode(array('office_editing' => true, 'drive_versioning_depth' => 5))));
harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', (int)$tq->fetchColumn());
$user = make_user('oe_live');
$mq = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
$mq->execute(array($grp, (int)$user->key));
harness_register_row('grm_group_members', 'grm_group_member_id', $mq->fetchColumn());
OfficeEditor::forgetMembers();

$work = sys_get_temp_dir() . '/oe_live_' . getmypid();
@mkdir($work, 0700, true);
harness_defer(function () use ($work) { exec('rm -rf ' . escapeshellarg($work)); });

$made = array();
harness_defer(function () use (&$made) {
	foreach ($made as $fid) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM fil_files WHERE fil_file_id = ?');
		$q->execute(array((int)$fid));
		if ($q->fetchColumn()) { (new File((int)$fid, true))->permanent_delete(); }
	}
});

/** A Drive file from bytes on disk. */
function oe_live_file($path, $title, $user_id) {
	global $made;
	$f = File::createFromUpload($path, $title, 'application/octet-stream', $user_id,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_protection_level' => ProtectionLevel::STANDARD), false);
	$made[] = $f->key;
	return DriveHelper::load_file($f->key);
}

/** Pages in a document's PDF rendering, by the editor's own engine. */
function oe_live_pages($bytes, $ext) {
	global $soffice, $work;
	$dir = $work . '/pdf_' . bin2hex(random_bytes(3));
	@mkdir($dir, 0700, true);
	file_put_contents($dir . '/doc.' . $ext, $bytes);
	exec('HOME=' . escapeshellarg($dir) . ' ' . escapeshellarg($soffice) . ' --headless --convert-to pdf --outdir '
		. escapeshellarg($dir) . ' ' . escapeshellarg($dir . '/doc.' . $ext) . ' 2>&1', $out, $rc);
	$pdf = @file_get_contents($dir . '/doc.pdf');
	return $pdf === false ? -1 : preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

// ---------------------------------------------------------------------------
// The editor's websocket protocol, as much as this suite needs.

class OeLiveSocket {
	public $s;
	public $messages = array();

	public function __construct($file_id, $token) {
		$wopi = OfficeEditor::WOPI_BASE . '/office_editor/wopi/files/' . (int)$file_id;
		$src = $wopi . '?access_token=' . $token;
		$path = '/cool/' . rawurlencode($src) . '/ws?WOPISrc=' . rawurlencode($wopi) . '&access_token=' . $token;
		$this->s = stream_socket_client('tcp://127.0.0.1:9980', $errno, $errstr, 5);
		if (!$this->s) {
			throw new RuntimeException('connect: ' . $errstr);
		}
		$key = base64_encode(random_bytes(16));
		fwrite($this->s, "GET $path HTTP/1.1\r\nHost: " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
			. "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
		$head = '';
		while (strpos($head, "\r\n\r\n") === false && !feof($this->s)) {
			$head .= fread($this->s, 1);
		}
		if (strpos($head, ' 101 ') === false) {
			throw new RuntimeException('no upgrade: ' . strtok($head, "\r\n"));
		}
		stream_set_blocking($this->s, false);
		$this->send('coolclient 0.1');
		$this->send('load url=' . rawurlencode($src));
	}

	public function send($text) {
		$mask = random_bytes(4);
		$n = strlen($text);
		$hdr = chr(0x81) . ($n < 126 ? chr(0x80 | $n) : ($n < 65536 ? chr(0x80 | 126) . pack('n', $n) : chr(0x80 | 127) . pack('J', $n)));
		$out = '';
		for ($i = 0; $i < $n; $i++) { $out .= $text[$i] ^ $mask[$i % 4]; }
		fwrite($this->s, $hdr . $mask . $out);
	}

	private $buf = '';

	/** Read what has arrived; returns the text messages seen so far. */
	public function pump($seconds) {
		$end = microtime(true) + $seconds;
		while (microtime(true) < $end) {
			$chunk = fread($this->s, 65536);
			if ($chunk === false || $chunk === '') {
				usleep(50000);
				continue;
			}
			$this->buf .= $chunk;
			while (strlen($this->buf) >= 2) {
				$op = ord($this->buf[0]) & 0x0f;
				$len = ord($this->buf[1]) & 0x7f;
				$off = 2;
				if ($len === 126) { if (strlen($this->buf) < 4) break; $len = unpack('n', substr($this->buf, 2, 2))[1]; $off = 4; }
				elseif ($len === 127) { if (strlen($this->buf) < 10) break; $len = unpack('J', substr($this->buf, 2, 8))[1]; $off = 10; }
				if (strlen($this->buf) < $off + $len) break;
				$payload = substr($this->buf, $off, $len);
				$this->buf = substr($this->buf, $off + $len);
				if ($op === 1) { $this->messages[] = $payload; }
			}
		}
		return $this->messages;
	}

	public function waitFor($needle, $seconds) {
		$end = microtime(true) + $seconds;
		while (microtime(true) < $end) {
			foreach ($this->messages as $m) {
				if (strpos($m, $needle) !== false) { return $m; }
			}
			$this->pump(0.5);
		}
		return null;
	}

	public function close() {
		if ($this->s) { @fclose($this->s); $this->s = null; }
	}
}

// ---------------------------------------------------------------------------
section('Edit and save');

$html = $work . '/paper.html';
$body = '';
for ($p = 1; $p <= 3; $p++) {
	$body .= '<h1' . ($p > 1 ? ' style="page-break-before: always"' : '') . '>Page ' . $p . '</h1>'
		. str_repeat('<p>The quick brown fox jumps over the lazy dog. </p>', 8);
}
file_put_contents($html, '<html><body>' . $body . '</body></html>');
exec('HOME=' . escapeshellarg($work) . ' ' . escapeshellarg($soffice) . ' --headless --convert-to docx:"MS Word 2007 XML" --outdir '
	. escapeshellarg($work) . ' ' . escapeshellarg($html) . ' 2>&1');
check(is_file($work . '/paper.docx'), 'a three-page paper was made');
$paper = oe_live_file($work . '/paper.docx', 'Live paper.docx', $user->key);
$pages_before = oe_live_pages($paper->read_bytes(), 'docx');
check($pages_before === 3, 'it renders to 3 pages (' . $pages_before . ')');
$head_before = (int)$paper->get('fil_fbb_file_blob_id');
$versions_before = (new MultiFileVersion(array('file_id' => $paper->key)))->count_all();

$origin = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$tok = OfficeEditorToken::mint($user->key, $paper->key, true, $origin)['token'];
$ws = new OeLiveSocket($paper->key, $tok);
check($ws->waitFor('status:', 45) !== null, 'the editor loaded the file through the signed WOPI path');
$ws->send('textinput id=0 text=Edited');
$ws->pump(2);
$ws->send('save dontTerminateEdit=1 dontSaveIfUnmodified=0');
$saved = $ws->waitFor('.uno:Save', 45);
check($saved !== null && strpos($saved, '"success":true') !== false, 'the save reports success (' . substr((string)$saved, 0, 120) . ')');
$ws->close();

$fresh = DriveHelper::load_file($paper->key);
check((int)$fresh->get('fil_fbb_file_blob_id') !== $head_before, 'the head moved');
check((new MultiFileVersion(array('file_id' => $paper->key)))->count_all() === $versions_before + 1, 'and the old content is a version');
$pages_after = oe_live_pages($fresh->read_bytes(), 'docx');
check($pages_after === $pages_before, 'the page count is unchanged (' . $pages_after . ')');
$z = $work . '/after.docx';
file_put_contents($z, $fresh->read_bytes());
exec('unzip -p ' . escapeshellarg($z) . ' word/document.xml', $xml_lines);
check(strpos(implode('', $xml_lines), 'Edited') !== false, 'the typed word is in the saved file');

// ---------------------------------------------------------------------------
section('Capacity');

$held = array();
$blank = PathHelper::getIncludePath('plugins/office_editor/templates/blank.docx');
$first = null;
for ($i = 0; $i < OfficeEditor::MAX_DOCUMENTS; $i++) {
	$tmp = $work . '/held' . $i . '.docx';
	copy($blank, $tmp);
	$f = oe_live_file($tmp, 'Held ' . $i . '.docx', $user->key);
	$first = $first ?: $f;
	$s = new OeLiveSocket($f->key, OfficeEditorToken::mint($user->key, $f->key, true, $origin)['token']);
	$s->waitFor('status:', 45);
	$held[] = $s;
}
$load = OfficeEditor::load();
check($load !== null && $load['documents'] >= OfficeEditor::MAX_DOCUMENTS, 'the editor counts ' . ($load['documents'] ?? '?') . ' documents open');

$tmp = $work . '/eleventh.docx';
copy($blank, $tmp);
$eleventh = oe_live_file($tmp, 'Eleventh.docx', $user->key);
check(OfficeEditor::wouldOverload($load, $eleventh->key), 'an eleventh document would go past the limit');
check(!OfficeEditor::wouldOverload($load, $first->key), 'joining one already open would not');

$session = SessionControl::get_instance();
$session->set_api_user($user->key);
$_SERVER['HTTP_HOST'] = parse_url($origin, PHP_URL_HOST);
$r = harness_call_logic('plugins/office_editor/logic/office_editor_open_logic.php', 'office_editor_open_logic',
	array('file_id' => $eleventh->key, 'origin' => $origin));
check($r->error !== null && (($r->data['reason'] ?? '') === 'busy'), 'office_editor_open says the editor is busy');
$r = harness_call_logic('plugins/office_editor/logic/office_editor_open_logic.php', 'office_editor_open_logic',
	array('file_id' => $first->key, 'origin' => $origin));
check($r->error === null && ($r->data['status'] ?? '') === 'ready', 'and opens one already open');
$session->clear_api_user();

foreach ($held as $s) { $s->close(); }

harness_finish();
