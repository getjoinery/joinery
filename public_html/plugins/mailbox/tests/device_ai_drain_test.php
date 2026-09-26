<?php
/** @joinery-test
 * name: device_ai_drain
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * The browser's half of a device judgement (specs/fortress_mail_device_ai.md
 * § R5, R6), run in node against the real modules:
 *   - MailboxFortress.selfCheck(): judgeEntry() against a stub model — the
 *     request's shape, the digest wrapped under the recipe's nonce, the one
 *     retry with the validator's words, the verdict sealed under the row's
 *     key and AD, a lock mid-call dropping the post, `error` after two invalid
 *     answers, a refusal from the model stopping with nothing recorded;
 *   - a verdict sealed in the browser opens on the server's side of the
 *     format (SealedBox::aeadDecryptGcm), so the reader and a lowered row read it;
 *   - VerdictCheck accepts and refuses the same answers PipelineRunner does,
 *     with the same words (they are what the retry feeds back);
 *   - the panel grades a model name as AiEndpointRegistry does.
 *
 * @version 1.2 - the call carries the reasoning control
 * @version 1.1 - on demand: the message-to-entry adapter and the button labels
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
	harness_skip('device AI drain', 'node is not installed here');
	harness_finish();
}
if (!class_exists('PipelineRunner')) {
	harness_skip('device AI drain', 'the joinery_ai plugin is not active');
	harness_finish();
}
require_once(PathHelper::getIncludePath('plugins/joinery_ai/pipeline_jobs/EmailTriageJob.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/pipeline_jobs/EmailSecurityScanJob.php'));

$triage = new EmailTriageJob();
$scan = new EmailSecurityScanJob();
$answers = array(
	array('triage', '{"summary":"A shop order shipped."}'),
	array('triage', "<think>let me see {not json}</think>\n{\"summary\": \"After thinking.\"}"),
	array('triage', 'Sure! Here you go: {"summary":"Wrapped in prose"} hope that helps'),
	array('triage', '{"summary":""}'),
	array('triage', 'no json at all'),
	array('triage', '{"summary":"' . str_repeat('x', 281) . '"}'),
	array('triage', '{"summary": 42}'),
	array('triage', '{"summary":"brace } inside {a string}"}'),
	array('scan', '{"score":8,"verdict":"dangerous","red_flags":[{"check":"C","finding":"Lookalike."}],"summary":"Phish."}'),
	array('scan', '{"score":8,"verdict":"safe","red_flags":[],"summary":"Mismatch."}'),
	array('scan', '{"score":"6","verdict":"caution","summary":"String score."}'),
	array('scan', '{"score":6.0,"verdict":"caution","summary":"Float score."}'),
	array('scan', '{"score":6.5,"verdict":"caution","summary":"Half."}'),
	array('scan', '{"score":11,"verdict":"dangerous","summary":"Too high."}'),
	array('scan', '{"score":2,"verdict":"benign","summary":"Bad enum."}'),
	array('scan', '{"score":3,"verdict":"safe","red_flags":[{"check":"Z","finding":"x"}],"summary":"Bad check."}'),
	array('scan', '{"score":3,"verdict":"safe","red_flags":"none","summary":"Not a list."}'),
	array('scan', '{"score":3,"verdict":"safe","summary":"No flags at all."}'),
);
$descriptors = array('triage' => $triage->verdictDescriptor(), 'scan' => $scan->verdictDescriptor());
$jobs = array('triage' => 'email_triage', 'scan' => 'email_security_scan');
$models = array('qwen3.5:9b-nvfp4', 'qwen3:4b-instruct', 'gemma2:9b', 'llama3.3:70b-instruct-q4', 'mixtral:8x7b',
	'accounts/fireworks/models/llama-v3p3-70b-instruct', 'some-model', 'phi3:3.8b', 'QWEN3.6:35B-A3B-Q4');

$dek_hex = bin2hex(random_bytes(32));
$root = PathHelper::getRootDir();
$ref = json_decode((string)file_get_contents($root . '/plugins/joinery_ai/ai_model_reference.json'), true);
$in = array('answers' => $answers, 'descriptors' => $descriptors, 'jobs' => $jobs, 'models' => $models,
	'reference' => array('models' => $ref['models'], 'ladder' => $ref['ladder']), 'dek' => $dek_hex);
$in_file = tempnam(sys_get_temp_dir(), 'drain');
file_put_contents($in_file, json_encode($in));
$runner = tempnam(sys_get_temp_dir(), 'drain') . '.js';
$req = '';
foreach (array('assets/js/vault-crypto.js', 'assets/js/html-entities.js', 'assets/js/email-digest.js', 'assets/js/verdict-check.js',
		'plugins/mailbox/assets/mailbox_fortress.js', 'plugins/mailbox/assets/mailbox_device_ai.js') as $f) {
	$req .= 'require(' . json_encode($root . '/' . $f) . ");\n";
}
file_put_contents($runner, "globalThis.window = globalThis;\n" . $req
	. "const input = JSON.parse(require('fs').readFileSync(" . json_encode($in_file) . ", 'utf8'));\n"
	. "(async () => {\n"
	. "  const out = {};\n"
	. "  out.self = await window.MailboxFortress.selfCheck();\n"
	. "  const dekBytes = Uint8Array.from(Buffer.from(input.dek, 'hex'));\n"
	. "  const key = await window.VaultCrypto.importDek(dekBytes);\n"
	. "  out.sealed = 'v1.edge.' + await window.VaultCrypto.encrypt('Summary with \\u00e9 and \\ud83d\\ude00', key, 'mail:77:iem_ai_summary');\n"
	. "  out.parsed = input.answers.map(a => window.VerdictCheck.parse(a[1], input.descriptors[a[0]], input.jobs[a[0]]));\n"
	. "  out.grades = input.models.map(m => window.MailboxDeviceAi.logic.gradeModel(m, input.reference));\n"
	. "  const L = window.MailboxDeviceAi.logic;\n"
	. "  out.entry = L.entryFromMessage({ id: 9, received_time: 't', dkim_result: 'pass', spf_result: 'fail', dmarc_result: 'none', auth_source: 'local', recipient: 'r@x', sealed: { key: 9, sealed_dek: 'v1.edgeseal.mail.q', iem_subject: 'v1.edge.s' }, body_plain: 'OPENED' });\n"
	. "  out.labels = [L.actionLabel('email_triage'), L.actionLabel('email_security_scan')];\n"
	. "  out.onDemand = typeof window.MailboxDeviceAi.messageActions;\n"
	. "  process.stdout.write(JSON.stringify(out));\n"
	. "})().catch(e => process.stdout.write('ERR ' + e.stack));\n");
$raw = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($runner) . ' 2>&1');
@unlink($runner);
@unlink($in_file);
$js = json_decode($raw, true);
check(is_array($js), 'node ran the drain modules', substr($raw, 0, 400));
if (!is_array($js)) {
	harness_finish();
}

section('A judgement against a stub model');
foreach ($js['self']['checks'] as $c) {
	check($c['ok'] === true, 'selfCheck: ' . $c['name']);
}
check(count($js['self']['checks']) >= 10, 'the self-check ran its judgement checks, not just the format checks');

section('A verdict sealed in the browser opens on the server\'s side of the format');
$box = new SealedBox();
$plain = $box->aeadDecryptGcm(substr($js['sealed'], strlen('v1.edge.')), hex2bin($dek_hex), 'mail:77:iem_ai_summary');
check($plain === "Summary with \u{00E9} and \u{1F600}", 'the same bytes come back under the row\'s AD');

section('The browser accepts and refuses the answers the server does, in the same words');
$parse = new ReflectionMethod('PipelineRunner', 'parseVerdict');
$parse->setAccessible(true);
foreach ($answers as $i => $a) {
	[$verdict, $error] = $parse->invoke(null, $a[1], $descriptors[$a[0]], $a[0] === 'scan' ? $scan : $triage);
	$j = $js['parsed'][$i];
	if ($verdict !== null) {
		check(isset($j['verdict']) && $j['verdict'] == $verdict && json_encode($j['verdict']) === json_encode($verdict),
			'accepted alike: ' . substr($a[1], 0, 50), json_encode($j));
	} else {
		check(isset($j['error']) && $j['error'] === $error, 'refused alike: ' . substr($a[1], 0, 50), 'php=' . $error . ' js=' . json_encode($j));
	}
}

section('On demand: an opened message becomes a queue entry');
$en = $js['entry'];
check($en['id'] === 9 && $en['sealed']['sealed_dek'] === 'v1.edgeseal.mail.q' && $en['spf_result'] === 'fail' && $en['recipient'] === 'r@x',
	'the thread message\'s sealed columns and clear results are what judgeEntry gets');
check(!isset($en['body_plain']), 'nothing opened rides along: judgeEntry opens the sealed columns itself');
check($js['labels'] === array('Summarize', 'Scan now') && $js['onDemand'] === 'function', 'the buttons are Summarize and Scan now');

section('The panel grades a model as the platform does');
foreach ($models as $i => $m) {
	$entry = AiEndpointRegistry::referenceEntryFor($m);
	$php = $entry !== null ? (string)$entry['tier'] : AiEndpointRegistry::tierFromLadder($m);
	check($php === $js['grades'][$i], 'grade: ' . $m . ' = ' . $php, 'js=' . $js['grades'][$i]);
}

harness_finish();
