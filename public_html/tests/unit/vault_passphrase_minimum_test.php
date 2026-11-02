<?php
/** @joinery-test
 * name: vault_passphrase_minimum
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The vault passphrase minimum is one number. The server never receives a
 * passphrase, only a key the browser derives from it, so the browser is the
 * only place its length is checked: vault-keyring.js's MIN_PASSPHRASE (setup,
 * enroll, rotation resupply) must equal SealedBox::PASSPHRASE_MIN_CHARS, which
 * the setup wizard reads. They drifted apart once (10 against 12).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('One minimum, wherever a passphrase is set');
$js = (string)file_get_contents(PathHelper::getIncludePath('assets/js/vault-keyring.js'));
$found = preg_match('/var\s+MIN_PASSPHRASE\s*=\s*(\d+)\s*;/', $js, $m) === 1;
check($found, 'vault-keyring.js declares MIN_PASSPHRASE as a number');
check($found && (int)$m[1] === (int)SealedBox::PASSPHRASE_MIN_CHARS,
	'vault-keyring.js asks for as many characters as SealedBox::PASSPHRASE_MIN_CHARS', ($m[1] ?? '?') . ' vs ' . SealedBox::PASSPHRASE_MIN_CHARS);
$wizard = (string)file_get_contents(PathHelper::getIncludePath('includes/setup_steps/encryption_key.php'));
check(strpos($wizard, 'SealedBox::PASSPHRASE_MIN_CHARS') !== false, 'the setup wizard reads the same constant');

harness_finish();
