<?php
/**
 * WatchMachineTransfer - each cloud machine's transfer this month, read from
 * its provider once a day (specs/node_outbound_and_transfer.md WP1). The
 * work is MachineTransferWatch's; the conditions it records become incidents
 * through IncidentSourceMachineTransfer.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class WatchMachineTransfer implements ScheduledTaskInterface {

	public function run(array $config) {
		return MachineTransferWatch::run();
	}
}
