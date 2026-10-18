<?php
/**
 * IncidentSourceKernelSettings — plane:kernel_settings: the running kernel
 * does not hold a setting every machine pins, as the machine's last host
 * report says (hardening; install_tools/_kernel_settings.sh,
 * specs/multi_tenant_host_hardening.md D1).
 *
 * The host converger's minute tick writes back any setting the running kernel
 * does not hold (host housekeeping writes the files, on a change or daily), so
 * one that stays loose is one the kernel refuses, one something else on the
 * machine keeps setting (a later sysctl file or a hand change is undone within
 * the minute), or a machine whose converger is not running: a failing run shows
 * as a failed unit, a stopped or disabled timer nowhere else (reviewer2 R3).
 * Each loosens what an unprivileged process on the machine may reach of the
 * kernel or the network. Clears when a report says every setting is held.
 *
 * @version 1.1 - The minute tick holds the settings, not host housekeeping (B37).
 */
class IncidentSourceKernelSettings implements IncidentSource {

	const NAME = 'plane:kernel_settings';

	public function name(): string {
		return self::NAME;
	}

	/** The report's hardening reading, or null where it has none. */
	public static function reading(ManagedNode $node): ?array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return null;
		}
		$h = JobResultProcessor::sanitise_host_report($report)['hardening'] ?? null;
		return is_array($h) ? $h : null;
	}

	public function evaluate(ManagedNode $node): ?array {
		$h = self::reading($node);
		if ($h === null || $h['state'] !== 'drift') {
			return null;
		}
		$lines = array();
		foreach ($h['drift'] as $d) {
			$lines[] = $d['key'] . ' is ' . $d['live'] . ', not ' . $d['want'];
		}
		$n = count($lines);
		return array(
			'title'    => $n === 1 ? 'A kernel setting is looser than pinned' : ($n > 1 ? $n . ' kernel settings are looser than pinned' : 'Kernel settings are looser than pinned'),
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Settings' => $n ? implode('; ', $lines) : 'the report did not name them',
				'Why'      => 'The host converger puts them back every minute, so a setting that stays loose is one this kernel refuses, one something else on the machine keeps setting, or the converger is not running on the machine.',
				'Look'     => 'on the machine, as root: systemctl status joinery-host-converger.timer; journalctl -u joinery-host-converger; sysctl -w SETTING=VALUE (a refusal says why)',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (self::reading($node) === null) {
			return 'The latest host report does not read the kernel\'s settings.';
		}
		return 'The latest host report says the kernel holds every pinned setting.';
	}
}
?>
