<?php
/**
 * IncidentSourceRebootNotBack - plane:reboot_not_back: a machine this plane
 * asked to reboot has not come back (specs/automatic_reboot_when_quiet.md WP3).
 *
 * Read from the machine's last reboot_machine job (JobResultProcessor::
 * last_reboot()): scheduled, its job completed over GRACE_SECONDS ago, and no
 * host report since with a new boot id (came_back_at). A reboot a person
 * pressed counts as one the Reboot When Quiet task started does. While one is
 * open the task reboots nothing else.
 *
 * Clears when a host report shows the machine came back.
 *
 * @version 1.0
 */
class IncidentSourceRebootNotBack implements IncidentSource {

	const NAME = 'plane:reboot_not_back';

	/** A reboot is 20 s out and a boot takes a minute or two: ten minutes is not coming back. */
	const GRACE_SECONDS = 600;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node, ?int $now = null): ?array {
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			return null;
		}
		$last = JobResultProcessor::last_reboot($node);
		if ($last === null) {
			return null;
		}
		$job = $last['job'];
		$done = (string)$job->get('mjb_completed_time');
		return self::verdict($last['scheduled'], !empty($last['came_back_at']), $done !== '' ? (int)strtotime($done . ' UTC') : null,
			(array)json_decode((string)$job->get('mjb_parameters'), true), $now ?? time());
	}

	/** The incident for one reboot, or null. Pure. */
	public static function verdict(bool $scheduled, bool $came_back, ?int $completed_at, array $params, int $now): ?array {
		if (!$scheduled || $came_back || $completed_at === null || $now - $completed_at < self::GRACE_SECONDS) {
			return null;
		}
		$minutes = (int)floor(($now - $completed_at) / 60);
		$auto = ($params['automatic'] ?? '') === 'reboot_when_quiet';
		return array(
			'title'    => 'Rebooted ' . $minutes . ' minutes ago and has not come back',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'What'  => ($auto ? 'It was rebooted automatically (' . (string)($params['why'] ?? 'it owed a reboot') . ')' : 'It was rebooted from its page')
					. ', and no report since shows it running again.',
				'Then'  => 'Open its provider\'s console: a full /boot, a disk check or a boot that waits for a key are the usual causes. '
					. 'No other machine reboots automatically until this clears.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'A report shows the machine running again after its reboot.';
	}
}
?>
