<?php
/** @joinery-test
 * name: direct_transport_dedupe
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * A message reaches each recipient once, even when it lists one twice.
 *
 * DirectMailTransport splits a send across two paths — Direct for the recipients
 * that take it, SMTP for the rest. A duplicate address is the trap: sent Direct
 * twice, or Direct on one occurrence and left in `remaining` for SMTP on the
 * other, it arrives twice across the very split the design makes invisible. The
 * recipient list is deduped before any of that, case-insensitively.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DirectMailTransport.php'));

// The sender domain has no Direct signing identity, so attempt() takes none of
// the recipients onto Direct and returns the whole (deduped) list in `remaining`
// — exactly the set SMTP would then send to. That holds whether or not Direct is
// enabled on this box, so the dedup is what is under test, not the tier state.
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
check(!DirectSigningIdentity::hasIdentity('example.com'),
	'the sender domain speaks no Direct, so attempt returns the deduped set for SMTP');

$m = new EmailMessage();
$m->from('sender@example.com')
	->to('dup@x.test')
	->to('DUP@x.test')          // same address, different case
	->to('  dup@x.test  ')      // same address, whitespace
	->to('other@x.test');

$res = DirectMailTransport::attempt($m);
$remaining = $res['remaining'];

check(count($remaining) === 2, 'the three spellings of one address collapse to one entry',
	implode(', ', $remaining));

$lower = array_map(function ($a) { return strtolower(trim((string)$a)); }, $remaining);
check(count($lower) === count(array_unique($lower)), 'no address appears twice');
check(in_array('dup@x.test', $lower, true) && in_array('other@x.test', $lower, true),
	'and both distinct recipients are still present');

harness_finish();
