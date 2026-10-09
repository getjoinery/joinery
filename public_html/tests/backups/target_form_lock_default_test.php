<?php
/** @joinery-test
 * name: target_form_lock_default
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * A new backup target's lock days start at the suggested figure (retention plus
 * the full-backup interval, 35 at the shipped settings), not 0. A target saved
 * at 0 holds unlocked backups and its connection test still passes, and the
 * lock days are fixed once the target holds anything, so a form that opened at
 * 0 made the unlocked outcome the easy one to land in. A saved target shows
 * what it was saved with.
 *
 * Run: php tests/backups/target_form_lock_default_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$field = function (?BackupTarget $target): string {
	$fw = new FormWriterV2HTML5('lockdefault');
	ob_start();
	echo $fw->begin_form();
	BackupTargetForm::render($fw, $target, array());
	$html = ob_get_clean();
	return preg_match('#<input[^>]*name="bkt_lock_days"[^>]*>#', $html, $m) === 1 ? $m[0] : '';
};

section('A new target opens at the suggested lock');
$suggested = BackupTarget::suggested_lock_days();
$html = $field(null);
check($html !== '', 'the form carries the lock days field');
check(strpos($html, 'value="' . $suggested . '"') !== false, 'a new target starts at the suggested ' . $suggested . ' days', $html);
check($suggested >= 2, 'and the suggestion is a real lock', (string)$suggested);

section('A saved target shows what it was saved with');
$saved = new BackupTarget(null);
$saved->set('bkt_provider', 'b2');
$saved->set('bkt_lock_days', 0);
check(strpos($field($saved), 'value="0"') !== false, 'a target saved with no lock shows 0, not the suggestion');
$saved->set('bkt_lock_days', 12);
check(strpos($field($saved), 'value="12"') !== false, 'and one saved at 12 shows 12');

harness_finish();
