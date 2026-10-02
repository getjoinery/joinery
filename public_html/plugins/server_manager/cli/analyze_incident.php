<?php
/**
 * CLI worker for one incident analysis (incident_triage.md WP4).
 *
 * Spawned as `php analyze_incident.php <event_id>` by IncidentAnalyst::request()
 * when a person presses Analyze. Runs IncidentAnalyst::run(), which stores the
 * answer, or why there is none, on the analysis event. CLI only.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(1);
}
if ($argc < 2 || (int)$argv[1] <= 0) {
	fwrite(STDERR, "Usage: php analyze_incident.php <event_id>\n");
	exit(2);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

$event_id = (int)$argv[1];
echo gmdate('Y-m-d H:i:s') . " incident analysis event #{$event_id}: start\n";
IncidentAnalyst::run($event_id);
$e = new IncidentEvent($event_id, TRUE);
echo gmdate('Y-m-d H:i:s') . " incident analysis event #{$event_id}: " . ($e->data()['status'] ?? 'unknown') . "\n";
