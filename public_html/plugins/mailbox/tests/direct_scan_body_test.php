<?php
/** @joinery-test
 * name: direct_scan_body
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */

/**
 * What the spam filter reads of a message with both bodies — a Direct message
 * among them, which arrives as parts, never a MIME document, and carries no
 * scanner headers at all, so the classifier's tokens are all it is judged on.
 *
 * Spam rides in the HTML behind an innocuous plain part: link farms and
 * tracking URLs. The tokenizer reads its words from the plain part when there
 * is one, so it must still take the URL hosts from the HTML; with no plain part
 * it reads the HTML's readable text.
 *
 * @version 2.0 - the classifier's tokenizer (spam_learning_in_core.md); no message is synthesized
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$plain = 'Hello, see you on Tuesday.';
$html  = '<p>Hello, see you on Tuesday.</p><a href="https://Tracker.Spam-Farm.example/c?id=1">x</a>'
	. '<span style="display:none">HIDDENHTMLWORD</span>';

section('Both bodies count');
$t = SpamBayes::tokens(array('body_plain' => $plain, 'body_html' => $html));
check(in_array('w:tuesday', $t, true), 'the plain part\'s words are read');
check(in_array('url:tracker.spam-farm.example', $t, true), 'the HTML\'s link hosts are read even behind a plain part');

section('HTML only');
$t = SpamBayes::tokens(array('body_plain' => '', 'body_html' => $html));
check(in_array('w:tuesday', $t, true), 'with no plain part, the HTML\'s readable text is read');
check(in_array('url:tracker.spam-farm.example', $t, true), 'and its link hosts');

section('Plain only');
$t = SpamBayes::tokens(array('body_plain' => 'Visit http://plain-link.example/x today', 'body_html' => ''));
check(in_array('url:plain-link.example', $t, true) && in_array('w:visit', $t, true), 'a plain message is read as itself');

harness_finish();
