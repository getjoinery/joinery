<?php
/** @joinery-test
 * name: messenger_typing
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Who is typing (MessengerTyping): a few seconds of shared-memory state per
 * conversation, never the database.
 *
 *  - set() then who() names the typist, but not to themselves;
 *  - stopping, or clear(), forgets them at once; an empty state leaves no entry;
 *  - a keystroke older than TTL_SECONDS no longer counts;
 *  - the state is one RamStore entry of {user id => time}, and a stored value
 *    that is anything else is dropped rather than trusted;
 *  - a store that refuses shows nobody typing and never throws.
 *
 * Run: php tests/run.php db --filter=messenger_typing
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerTyping.php'));

$cnv = 900000000 + random_int(1, 99999999);
$key = 'messenger:typing:' . $cnv;
harness_defer(function () use ($cnv) { MessengerTyping::clear($cnv); });

section('set and who');
MessengerTyping::set($cnv, 11, true);
MessengerTyping::set($cnv, 12, true);
$who = MessengerTyping::who($cnv, 11);
check($who === array(12), 'the other member is typing; the caller is left out', json_encode($who));
$all = MessengerTyping::who($cnv);
sort($all);
check($all === array(11, 12), 'with no one excluded, both', json_encode($all));
$stored = RamStore::get($key, $ok);
check($ok && is_array($stored) && count($stored) === 2 && is_int($stored[11] ?? null), 'one store entry of user id => time', json_encode($stored));
check(RamStore::get($key, $ok2, RamStore::LIFETIME) === null && !$ok2, 'a plain entry, not one bound to PHP-FPM');

section('stopping and clearing');
MessengerTyping::set($cnv, 12, false);
check(MessengerTyping::who($cnv, 11) === array(), 'a member who stops is gone at once');
MessengerTyping::set($cnv, 11, false);
check(!RamStore::exists($key), 'an empty state leaves no entry');
MessengerTyping::set($cnv, 11, true);
MessengerTyping::clear($cnv);
check(MessengerTyping::who($cnv) === array() && !RamStore::exists($key), 'clear() forgets the conversation');

section('staleness and bad values');
RamStore::set($key, array(11 => time() - MessengerTyping::TTL_SECONDS - 5, 12 => time()), 60);
check(MessengerTyping::who($cnv) === array(12), 'a keystroke older than the TTL no longer counts');
RamStore::set($key, array(11 => 'now', 'x' => time(), 13 => array(1), 14 => time()), 60);
check(MessengerTyping::who($cnv) === array(14), 'anything but user id => time is dropped', json_encode(MessengerTyping::who($cnv)));
MessengerTyping::set($cnv, 15, true);
$stored = RamStore::get($key);
ksort($stored);
check(array_keys($stored) === array(14, 15), 'and a write keeps only what read back clean', json_encode($stored));
MessengerTyping::clear($cnv);

section('a store that refuses');
$store_dir = RamStore::directory();
RamStore::useDirectory(__DIR__ . '/no-store-here'); // on disk, not tmpfs: refused
$threw = false;
try {
	MessengerTyping::set($cnv, 11, true);
	$who = MessengerTyping::who($cnv);
} catch (Throwable $e) {
	$threw = true;
}
RamStore::useDirectory($store_dir);
check(!$threw && $who === array(), 'nobody is shown typing, and nothing throws');
check(!is_dir(__DIR__ . '/no-store-here'), 'and no directory was made on disk');

harness_finish();
