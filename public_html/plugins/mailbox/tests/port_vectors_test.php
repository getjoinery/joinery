<?php
/** @joinery-test
 * name: mailbox_port_vectors_php
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * The phone ports' vectors agree with the server (specs/fortress_mobile_apps.md
 * WP1, WP9, WP10). port_vectors_gate.sh holds each vector file to the browser
 * code that built it; this holds the same files to PHP, where PHP is the
 * original the browser ports:
 *
 *   - fixtures/filter_match_cases.json: every case's expected ids are the
 *     rules InboundEmailFilter::matches() accepts, in the order given (the
 *     order mailbox/device_rules lists them). PORT_VECTORS_WRITE=1 writes the
 *     expectations instead of checking.
 *   - fixtures/device_ai_vectors.json: the digests equal
 *     EmailSecurityDigest::buildFromColumns(), the ATTACHMENTS sections
 *     EmailAttachmentDigest::buildFromManifest(), the envelopes
 *     UntrustedEnvelope::wrapBlock(); the descriptors are the jobs' own; every
 *     verdict is accepted or refused as PipelineRunner::parseVerdict() does,
 *     in the same words; each judgement's user message is the server's
 *     envelope around the server's digest.
 *
 * The attachment criterion is the one part of matches() that reads the
 * database (an ima_ row for the message); the case's has_attachment stands in
 * for that row, AND'ed with the rest as matches() does.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$write = getenv('PORT_VECTORS_WRITE') === '1';

// ---------------------------------------------------------------------------
section('Mail rules: the PHP matcher decides every case');

$cases_path = __DIR__ . '/fixtures/filter_match_cases.json';
$file = json_decode((string)file_get_contents($cases_path), true);
check(is_array($file) && !empty($file['cases']), 'filter_match_cases.json parses');

/** The ids of $rules that InboundEmailFilter::matches() accepts for $m, in the order given. */
function pv_php_ids(array $rules, array $m): array {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_recipient', $m['recipient']);
	$msg->set('iem_size_bytes', $m['size_bytes']);
	$plaintext = array('sender' => $m['sender'], 'subject' => $m['subject'],
		'body_plain' => $m['body_plain'], 'body_html' => $m['body_html']);
	$ids = array();
	foreach ($rules as $r) {
		$c = $r['match'];
		$f = new InboundEmailFilter(NULL);
		$f->set('ief_match_from', $c['from']);
		$f->set('ief_match_to', $c['to']);
		$f->set('ief_match_subject', $c['subject']);
		$f->set('ief_match_has_words', $c['has_words']);
		$f->set('ief_match_excludes', $c['excludes']);
		$f->set('ief_match_size_op', $c['size_op'] === '' ? null : $c['size_op']);
		$f->set('ief_match_size_bytes', $c['size_bytes']);
		// The attachment criterion is the one database read in matches() (an ima_
		// row for the message id); the case's has_attachment stands in for it.
		$f->set('ief_match_has_attachment', false);
		if ($f->matches($msg, array(), $plaintext) && (empty($c['has_attachment']) || !empty($m['has_attachment']))) {
			$ids[] = intval($r['id']);
		}
	}
	return $ids;
}

if (is_array($file)) {
	foreach ($file['cases'] as $i => $c) {
		$php = pv_php_ids($c['rules'], $c['message']);
		if ($write) {
			$file['cases'][$i]['expected_ids'] = $php;
			continue;
		}
		check(isset($c['expected_ids']) && $c['expected_ids'] === $php, 'rules: ' . $c['name'],
			'file=' . json_encode($c['expected_ids'] ?? null) . ' php=' . json_encode($php));
	}
	if ($write) {
		file_put_contents($cases_path, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
		check(true, 'wrote the PHP expectations into filter_match_cases.json');
	}
}

// ---------------------------------------------------------------------------
section('Device AI: the vectors are the server\'s bytes');

$ai = json_decode((string)file_get_contents(__DIR__ . '/fixtures/device_ai_vectors.json'), true);
check(is_array($ai), 'device_ai_vectors.json parses');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/EmailSecurityDigest.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/EmailAttachmentDigest.php'));

if (is_array($ai)) {
	foreach ($ai['digests'] as $d) {
		check(EmailSecurityDigest::buildFromColumns($d['input']) === $d['digest'], 'digest: ' . $d['name']);
	}
	foreach ($ai['attachments'] as $a) {
		check(EmailAttachmentDigest::buildFromManifest($a['manifest']) === $a['section'], 'attachments: ' . $a['name']);
	}
	foreach ($ai['envelopes'] as $e) {
		check(UntrustedEnvelope::wrapBlock($e['text'], $e['nonce']) === $e['wrapped'], 'envelope: ' . $e['name']);
	}

	$in = $ai['judge_inputs'];
	$o = $in['opened'];
	$cols = array('raw' => $o['iem_raw_headers'], 'sender' => $o['iem_sender'], 'recipient' => $o['iem_recipient'],
		'received_time' => $in['entry']['received_time'], 'subject' => $o['iem_subject'], 'body_plain' => $o['iem_body_plain'],
		'body_html' => $o['iem_body_html'], 'spf_result' => $in['entry']['spf_result'], 'dkim_result' => $in['entry']['dkim_result'],
		'dmarc_result' => $in['entry']['dmarc_result']);
	foreach ($ai['judgements'] as $j) {
		$digest = EmailSecurityDigest::buildFromColumns($cols + array('authserv_id' => $j['recipe']['authserv_id']));
		if (!empty($j['recipe']['attachments'])) {
			$section = EmailAttachmentDigest::buildFromManifest(json_decode($o['iem_attachment_manifest'], true));
			if ($section !== '') {
				$digest .= "\n\n" . $section;
			}
		}
		$user = $j['requests'][0]['body']['messages'][1]['content'] ?? null;
		check($user === UntrustedEnvelope::wrapBlock($digest, $j['recipe']['nonce']),
			'judgement "' . $j['name'] . '": the user message is the server\'s envelope around the server\'s digest');
	}

	if (!class_exists('PipelineRunner')) {
		check(true, 'the joinery_ai plugin is not active here: descriptors and verdicts are held by device_ai_drain in a full install');
	} else {
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/pipeline_jobs/EmailTriageJob.php'));
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/pipeline_jobs/EmailSecurityScanJob.php'));
		$jobs = array('email_triage' => new EmailTriageJob(), 'email_security_scan' => new EmailSecurityScanJob());
		foreach ($jobs as $id => $job) {
			check(json_encode($job->verdictDescriptor()) === json_encode($ai['descriptors'][$id]),
				'descriptor: ' . $id . ' is the job\'s own (rebuild the vectors with --descriptors if it changed)');
		}
		$parse = new ReflectionMethod('PipelineRunner', 'parseVerdict');
		$parse->setAccessible(true);
		foreach ($ai['verdicts'] as $v) {
			[$verdict, $error] = $parse->invoke(null, $v['answer'], $ai['descriptors'][$v['job_id']], $jobs[$v['job_id']]);
			$label = $v['job_id'] . ': ' . mb_substr($v['answer'], 0, 50);
			if ($verdict !== null) {
				check(isset($v['result']['verdict']) && json_encode($v['result']['verdict']) === json_encode($verdict), 'accepted alike: ' . $label,
					json_encode($v['result']));
			} else {
				check(isset($v['result']['error']) && $v['result']['error'] === $error, 'refused alike: ' . $label,
					'php=' . $error . ' file=' . json_encode($v['result']));
			}
		}
	}
}

harness_finish();
