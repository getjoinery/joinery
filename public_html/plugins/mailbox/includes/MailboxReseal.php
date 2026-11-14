<?php
/**
 * MailboxReseal - the write step of mailbox's vault-rotation re-seal.
 *
 * A rotation reads an item's key wrapping, unwraps it with the old vault key,
 * wraps it for the new one and writes it back. The pass can run for minutes,
 * and an item re-sealed or rewritten meanwhile already holds a wrapping that
 * opens; writing the stale one over it would leave content that never opens
 * again. So the write lands only while the row still holds what was read (and
 * the generation read, where the table has one), the same compare-and-swap
 * SystemBase::resealRows() makes. A row that changed is skipped, not failed:
 * what it holds now stands.
 *
 * @version 1.0 (security_weak_spots_2026_11 follow-up B7)
 */
class MailboxReseal {

	/** @var callable|null Test seam: fn(string $table, int $id), called just before each write. */
	public static $before_write = null;

	/**
	 * Write $new into $key_col of one row, only if the row still holds
	 * $expected (and $old_gen in $gen_col when given). Identifiers are the
	 * callback's own constants, never input.
	 *
	 * @return bool true when the write landed, false when the row had changed
	 */
	public static function swap(string $table, string $pk, int $id, string $key_col, string $expected, string $new,
			?string $gen_col = null, ?int $old_gen = null, ?int $new_gen = null): bool {
		if (self::$before_write !== null) {
			call_user_func(self::$before_write, $table, $id);
		}
		$set = $key_col . ' = ?';
		$params = array($new);
		if ($gen_col !== null) {
			$set .= ', ' . $gen_col . ' = ?';
			$params[] = $new_gen;
		}
		$where = $pk . ' = ? AND ' . $key_col . ' = ?';
		$params[] = $id;
		$params[] = $expected;
		if ($gen_col !== null) {
			$where .= ' AND ' . $gen_col . ' = ?';
			$params[] = $old_gen;
		}
		$upd = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $where);
		$upd->execute($params);
		return $upd->rowCount() > 0;
	}
}
?>
