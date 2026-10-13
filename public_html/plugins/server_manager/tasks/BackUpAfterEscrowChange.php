<?php
/**
 * BackUpAfterEscrowChange - this escrow holder backs itself up within the hour
 * of a change to the keys it holds (specs/hosted_data_promise_disk_encryption.md
 * O11). The rule is EscrowHolderBackup's; each tick is two indexed queries when
 * nothing changed.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class BackUpAfterEscrowChange implements ScheduledTaskInterface {

	public function run(array $config) {
		return array('status' => 'success', 'message' => EscrowHolderBackup::tick());
	}
}
