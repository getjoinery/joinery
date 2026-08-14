<?php
/** @joinery-test
 * name: ai_attachment_commit
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Storing a chat attachment: the type has to survive the round trip.
 *
 * This exists because of a defect only a live upload found. An Office or
 * OpenDocument file IS a zip, and `File::createFromBytes()` re-detects the type
 * from the bytes when it saves — so a `.docx` accepted at ingress as a document
 * came back out of storage as `application/zip`, tripped commit()'s type-drift
 * guard, and was dropped with "a server-side type error". Everything upstream
 * was correct; the file died on the way into the database.
 *
 * The guard itself is not the bug and must stay: it is what stops a file whose
 * type really did change between layers from reaching a model as something it
 * is not. So the fix is narrow, and this test pins both halves of it —
 *
 *   - a real docx keeps its real type through storage and lands with its text,
 *   - a file whose stored type genuinely disagrees is still dropped.
 *
 * Run: php plugins/joinery_ai/tests/attachment_commit_test.php  (schema synced).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/fixtures/documents/generate_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAttachmentIngest.php'));

$dir = sys_get_temp_dir() . '/joinery_ai_commit_fixtures_' . getmypid();
docfix_generate($dir);
harness_defer(function () use ($dir) {
	foreach (glob($dir . '/*') as $f) { if (is_file($f)) @unlink($f); }
	@rmdir($dir);
});

$user = make_user('CommitOwner', 5);
$uid  = (int)$user->key;

$conv = new AiConversation(NULL);
$conv->set('aic_owner_user_id', $uid);
$conv->set('aic_security_level', AiConversation::LEVEL_STANDARD);
$conv->set('aic_model', 'qwen3:4b-instruct');
$conv->save();
$conv->load();
harness_register_row('aic_conversations', 'aic_conversation_id', (int)$conv->key);

$msg = new AiConversationMessage(NULL);
$msg->set('aim_aic_conversation_id', (int)$conv->key);
$msg->set('aim_role', AiConversationMessage::ROLE_USER);
$msg->set('aim_content', 'What does this say?');
$msg->save();
$msg->load();
harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$msg->key);

/** Build the prepared entry exactly as ChatAttachmentIngest::prepare() would. */
$prepare_one = function (string $file, string $as_name) use ($dir) {
	$bytes    = file_get_contents($dir . '/' . $file);
	$detected = (string)File::detect_mime_bytes($bytes);
	$mime     = AiAttachment::resolveUploadMime($detected, $as_name);
	$extract  = AiAttachment::extractPath($dir . '/' . $file, $mime);
	return array(
		'bytes'       => $bytes,
		'name'        => $as_name,
		'client_type' => '',
		'mime'        => $mime,
		'category'    => AiAttachment::categoryForMime($mime),
		'extract'     => $extract,
		'detected'    => $detected,
	);
};

// ── A docx survives storage ──────────────────────────────────────────────────
section('A document survives storage');

$p = $prepare_one('sample.docx', 'quarterly-contract.docx');
check($p['category'] === 'document', 'the docx is accepted as a document at ingress');
check($p['extract']['status'] === AiAttachment::EXTRACT_OK, 'and its text was extracted');

$failures = ChatAttachmentIngest::commit(array($p), $msg, $conv, $uid);
check(count($failures) === 0, 'commit() stores it — no server-side type error',
	json_encode($failures) . ' (detected as ' . $p['detected'] . ')');

$links = new MultiAiMessageAttachment(array('message_id' => (int)$msg->key, 'deleted' => false), array());
$stored = array();
foreach ($links as $link) { $stored[] = $link; }
check(count($stored) === 1, 'one attachment row exists', (string)count($stored));

if (count($stored)) {
	$link = $stored[0];
	harness_register_row('aia_message_attachments', 'aia_attachment_id', (int)$link->key);
	$file = new File((int)$link->get('aia_fil_file_id'), TRUE);
	harness_defer(function () use ($file) { if ($file->key) { try { $file->permanent_delete(); } catch (Throwable $e) {} } });

	check(strpos((string)$file->get('fil_type'), 'wordprocessingml') !== false,
		'the stored file keeps its real type, not the zip it is built from',
		(string)$file->get('fil_type'));
	check(AiAttachment::categoryForMime($file->get('fil_type')) === 'document',
		'so it still routes as a document when the turn is built');
	check((string)$link->get('aia_extract_status') === AiAttachment::EXTRACT_OK,
		'the link row carries the extraction outcome', (string)$link->get('aia_extract_status'));
	check(strpos((string)$link->get('aia_extracted_text'), 'Service Agreement') !== false,
		'and the document text the model will actually read');

	// The end of the road: what the send path builds from that row.
	$blocks = AiAttachment::blocksForAttachment($file, (string)$link->get('aia_extracted_text'),
		(string)$link->get('aia_extract_status'), AiAttachment::MODE_EXTRACT,
		array('vision' => true, 'document' => true), 'nonce123');
	$payload = json_encode($blocks);
	check(strpos($payload, 'Service Agreement') !== false,
		'the model payload carries the document text');
	check(strpos($payload, 'UNTRUSTED_nonce123') !== false,
		'framed as untrusted input like every other attachment');
	check(strpos($payload, 'server-side type error') === false,
		'and not the placeholder note an unroutable file would produce');
}

// ── Real drift is still refused ──────────────────────────────────────────────
section('Real drift is still refused');

// The guard's own job: a prepared entry claiming a category the bytes cannot
// support must still be dropped rather than stored.
$msg2 = new AiConversationMessage(NULL);
$msg2->set('aim_aic_conversation_id', (int)$conv->key);
$msg2->set('aim_role', AiConversationMessage::ROLE_USER);
$msg2->set('aim_content', 'And this?');
$msg2->save();
$msg2->load();
harness_register_row('aim_conversation_messages', 'aim_message_id', (int)$msg2->key);

$liar = $prepare_one('sample.zip', 'bundle.zip');
$liar['mime']     = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
$liar['category'] = 'document';

// The storage fix keeps a container-detected file only on the extractor's word.
// Here that word is against it: opened for real, these bytes are an archive,
// which is not a category the chat accepts at all.
$core = DocumentText::extractPath($dir . '/sample.zip', 'application/zip');
check($core['category'] === 'archive', 'the extractor reports what the bytes really are',
	json_encode($core['category']));
check(AiAttachment::categoryForCoreCategory($core['category']) === null,
	'and an archive maps to nothing the chat accepts');
check(($liar['extract']['category'] ?? null) !== 'document',
	'so the prepared entry carries no evidence for the document claim',
	json_encode($liar['extract']['category'] ?? null));

$failures = ChatAttachmentIngest::commit(array($liar), $msg2, $conv, $uid);
check(count($failures) === 1, 'a file claiming to be a document it is not is dropped',
	json_encode($failures));

$links2 = new MultiAiMessageAttachment(array('message_id' => (int)$msg2->key, 'deleted' => false), array());
check(count($links2) === 0, 'and no attachment row is left behind', (string)count($links2));

harness_finish();
