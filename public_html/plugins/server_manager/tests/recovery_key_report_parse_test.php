<?php
/** @joinery-test
 * name: recovery_key_report_parse
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * The plane reads a node's RECOVERY_KEY= report (set_recovery_key.php) and
 * keeps the proven public key on the node, so a copy into the owner's account
 * is born sealed to it. An X-Wing recovery key is 1216 bytes (1624 base64
 * characters); the parser must capture it whole and keep it, and must not keep
 * an old 32-byte X25519 key, which nothing seals to (specs/post_quantum_sealing.md
 * R3, reviewer2 WP1 B2).
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$parse = new ReflectionMethod('JobResultProcessor', 'parse_recovery_key_token');
$keep  = new ReflectionMethod('JobResultProcessor', 'recovery_public_key_of');

section('an X-Wing key is captured and kept');

$pub_raw = (new XWing())->publicKey(random_bytes(32));
$pub = base64_encode($pub_raw);
$fpr = hash('sha256', $pub_raw);
$r = $parse->invoke(null, "RECOVERY_KEY=already fpr={$fpr} proven=1 pub={$pub}\n");
check(is_array($r) && ($r['backup_recovery_public_key'] ?? '') === $pub, 'the whole 1624-character key is captured');
check($keep->invoke(null, $r) === $pub, 'and kept for the node');

$r = $parse->invoke(null, "RECOVERY_KEY=already fpr=" . hash('sha256', 'other') . " proven=1 pub={$pub}\n");
check($keep->invoke(null, $r) === null, 'a key its fingerprint does not name is not kept');

$r = $parse->invoke(null, "RECOVERY_KEY=already fpr={$fpr} proven=0 pub={$pub}\n");
check($keep->invoke(null, $r ?: array()) === null, 'nor an unproven one');

section('an old X25519 key is read but not kept');

$old_raw = random_bytes(32);
$old = base64_encode($old_raw);
$r = $parse->invoke(null, "RECOVERY_KEY=already fpr=" . hash('sha256', $old_raw) . " proven=1 pub={$old}\n");
check(is_array($r) && ($r['backup_recovery_state'] ?? '') === 'proven', 'the report still parses (its state is a fact)');
check($keep->invoke(null, $r) === null, 'but the 32-byte key is not kept: nothing seals to it');

harness_finish();
