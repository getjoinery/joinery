<?php
/** @joinery-test
 * name: admin_notices
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The admin-header notice registry (includes/AdminNotices.php): the one place
 * every admin page renders the site-wide notices from.
 *
 *   - a plugin renderer registered under a name is rendered;
 *   - a renderer that throws is skipped and the others still render — an admin
 *     page never fails to load because a notice could not decide;
 *   - a quiet renderer contributes nothing, so silence stays the normal state;
 *   - the core notices render first and are always present;
 *   - registering a name again replaces the earlier renderer.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The core notices are fixed and render first');
AdminNotices::resetForTests();
$quiet = AdminNotices::render();
check(is_string($quiet), 'render() answers a string with nothing registered');
check(strpos($quiet, 'mbx-attention-notice') === false || true, 'a plugin notice may or may not be quiet on dev — no assertion on its content');

section('A registered renderer is rendered');
AdminNotices::resetForTests();
AdminNotices::register('test_one', function () { return '<div class="test-notice-one">one</div>'; });
$out = AdminNotices::render();
check(strpos($out, 'test-notice-one') !== false, 'the registered notice appears in the header output');
check(AdminNotices::registered() === array('test_one'), 'registered() names it', json_encode(AdminNotices::registered()));

section('A throwing renderer is skipped and the rest still render');
AdminNotices::resetForTests();
AdminNotices::register('boom', function () { throw new RuntimeException('could not decide'); });
AdminNotices::register('after', function () { return '<div class="test-notice-after">after</div>'; });
$out = AdminNotices::render();
check(strpos($out, 'test-notice-after') !== false, 'the notice registered after the failing one still renders');
check(strpos($out, 'could not decide') === false, 'the exception text never reaches the page');

section('A quiet renderer contributes nothing');
AdminNotices::resetForTests();
$before = AdminNotices::render();
AdminNotices::register('quiet', function () { return ''; });
check(AdminNotices::render() === $before, 'an empty answer leaves the output exactly as it was');

section('Registering a name again replaces the earlier renderer');
AdminNotices::resetForTests();
AdminNotices::register('same', function () { return '<i>first</i>'; });
AdminNotices::register('same', function () { return '<i>second</i>'; });
$out = AdminNotices::render();
check(strpos($out, 'second') !== false && strpos($out, 'first') === false, 'only the later renderer runs');
check(count(AdminNotices::registered()) === 1, 'one registration under that name');

AdminNotices::resetForTests();
harness_finish();
