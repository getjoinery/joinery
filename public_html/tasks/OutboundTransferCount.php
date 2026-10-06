<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * OutboundTransferCount — add what this server sent since the last run to the
 * month (specs/node_outbound_and_transfer.md WP1). The work is
 * OutboundTransferMeter's.
 *
 * @version 1.0
 */
class OutboundTransferCount implements ScheduledTaskInterface {
	public function run(array $config) {
		$state = OutboundTransferMeter::tick();
		$month = OutboundTransferMeter::month($state);
		return array('status' => 'success', 'message' => 'Sent this month: ' . OutboundTransferMeter::gb($month['sent_bytes'])
			. ' since ' . gmdate('Y-m-d', $month['since']) . '.');
	}
}
