<?php
/** @joinery-test
 * name: mailbox_receive_mode
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The deployment receive-mode resolution matrix (relay-or-direct choice that
 * gates the mailbox admin surfaces). The matrix itself is a pure function;
 * the live resolver is exercised once to prove it runs against real state.
 *
 * Run: php tests/run.php safe --filter=mailbox_receive_mode
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

section('resolution matrix');

// 1. The stored choice wins — the choice belongs to the admin.
check(mailbox_receive_mode_resolve(false, 'relay', false) === 'relay', 'chosen relay holds with no relay row yet');
check(mailbox_receive_mode_resolve(false, 'direct', false) === 'direct', 'chosen direct holds with no domains yet');
check(mailbox_receive_mode_resolve(true, 'direct', false) === 'direct', 'chosen direct holds even with a relay provisioned');
check(mailbox_receive_mode_resolve(false, 'relay', true) === 'relay', 'chosen relay holds once domains exist');

// 2. A running deployment (live domains, no stored choice) is never gated;
// the mode reports what it is actually doing.
check(mailbox_receive_mode_resolve(false, '', true) === 'direct', 'domains without a relay => direct');
check(mailbox_receive_mode_resolve(true, '', true) === 'relay', 'domains behind a relay => relay');
check(mailbox_receive_mode_resolve(false, 'bogus', true) === 'direct', 'garbage setting falls through to running state');

// 3. No stored choice and no domains => undecided — the card shows even when
// a relay was provisioned as part of setup (the choice is offered at least once).
check(mailbox_receive_mode_resolve(false, '', false) === '', 'blank deployment is undecided');
check(mailbox_receive_mode_resolve(true, '', false) === '', 'a provisioned relay does not decide for the admin');
check(mailbox_receive_mode_resolve(false, 'bogus', false) === '', 'garbage setting alone stays undecided');

section('live resolver');

// Runs against this deployment's real rows/setting; any resolved value is
// legal — the point is that derivation works end to end.
$mode = mailbox_receive_mode();
check(in_array($mode, array('', 'direct', 'relay'), true), 'live mode resolves to a legal value', $mode);

harness_finish();
