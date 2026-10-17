<?php
/**
 * IncidentSourceKernelWarning — plane:kernel_warning: the kernel of a host
 * that runs strangers' sites logged its own warning in the last day, as the
 * machine's last host report counts it (kernel_events_24h.kernel_warning:
 * WARNING, BUG, kernel BUG at, oops, protection fault, read from the kernel's messages
 * alone; specs/multi_tenant_host_hardening.md D7).
 *
 * A kernel warning while other people run code on the machine is worth a look
 * every time: it can be a driver or a kernel bug, and it is also what an
 * attempt on the kernel leaves behind. A host is taken to run strangers'
 * sites when it refuses unsigned code (its host_restrictions name
 * unsigned_packages, which install.sh docker --multi-tenant writes). On any
 * other machine the count shows on the node page and is not an alarm.
 * COUNTS ONLY: no line of the kernel's text reaches the management node.
 * Clears once a report's last day holds none.
 *
 * @version 1.0
 */
class IncidentSourceKernelWarning implements IncidentSource {

	const NAME = 'plane:kernel_warning';

	/** The command that shows the lines on the machine (the report's own pattern). */
	const LOOK = "journalctl _TRANSPORT=kernel --since '24 hours ago' -g 'WARNING:|BUG:|[Kk]ernel BUG at|Oops:|general protection fault|kernel NULL pointer'";

	public function name(): string {
		return self::NAME;
	}

	/** The report's kernel warning count and whether the host runs strangers' sites, or null. */
	public static function reading(ManagedNode $node): ?array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return null;
		}
		$hr = JobResultProcessor::sanitise_host_report($report);
		$count = is_array($hr['kernel_events_24h']) ? ($hr['kernel_events_24h']['kernel_warning'] ?? 'unknown') : 'unknown';
		if (!is_int($count)) {
			return null;
		}
		$restrictions = $hr['host_restrictions'] ?? null;
		return array(
			'count'  => $count,
			'shared' => is_array($restrictions) && isset($restrictions['unsigned_packages']),
		);
	}

	public function evaluate(ManagedNode $node): ?array {
		$r = self::reading($node);
		if ($r === null || !$r['shared'] || $r['count'] === 0) {
			return null;
		}
		return array(
			'title'    => $r['count'] === 1 ? 'The kernel logged a warning' : 'The kernel logged ' . $r['count'] . ' warnings',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'In the last day' => $r['count'] . ($r['count'] === 1 ? ' kernel warning, bug or oops line' : ' kernel warning, bug or oops lines'),
				'Why it matters'  => 'This machine runs other people\'s sites. A kernel warning can be a driver or kernel bug, and it is also what an attempt on the kernel leaves behind.',
				'Look'            => 'on the machine, as root: ' . self::LOOK,
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		$r = self::reading($node);
		if ($r !== null && !$r['shared']) {
			return 'The machine no longer runs other people\'s sites, so its kernel warnings are not an alarm.';
		}
		if ($r === null) {
			return 'The latest host report could not count the kernel\'s warnings.';
		}
		return 'The latest host report counts no kernel warning in the last day.';
	}
}
?>
