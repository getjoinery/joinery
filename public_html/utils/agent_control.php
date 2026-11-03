<?php
/**
 * CLI control of this machine's Joinery agent's connection to a management
 * node — the decisions the Management Node admin page offers, for a shell
 * (specs/agent_on_node_architecture.md).
 *
 * Everything here writes a setting and nothing else. The running agent reads
 * the join and leave requests within seconds. Nothing in this file needs root, holds a
 * credential, or can enroll this machine anywhere on its own — a join is a
 * request, which an administrator on the management node still has to approve
 * after comparing key fingerprints.
 *
 * Usage:
 *   php utils/agent_control.php --status
 *   php utils/agent_control.php --join=https://manage.example.com
 *   php utils/agent_control.php --leave
 *
 * The agent runs on every machine; there is no switch to turn it off.
 *
 * @version 1.3 - --on and --off are gone: the agent runs on every machine
 * @version 1.2 - --join keeps a request for the same URL that has not been answered, so rerunning it
 *                (install.sh site passes it on every run) does not change the agent's staged key
 * @version 1.1 - --on/--off APPLY the switch (run the installer) rather than only recording it;
 *                as non-root they print the command instead of implying the work is done
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI access only.\n";
    exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));

$options = getopt('', ['status', 'join::', 'leave', 'help']);

if ($options === false || isset($options['help']) || $options === []) {
    echo "Connect this machine's Joinery agent to a management node.\n\n";
    echo "  --status              what the agent is connected to here\n";
    echo "  --join=URL            ask to join that management node\n";
    echo "  --leave               disconnect from the management node\n";
    exit(isset($options['help']) ? 0 : 1);
}

/** One setting, straight from the database, so a read-after-write is truthful. */
function agent_control_setting(string $name): string {
    $stmt = DbConnector::get_instance()->get_db_link()
        ->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_NUM);

    return $row === false ? '' : (string)$row[0];
}

$changed = false;

if (isset($options['join'])) {
    $url     = trim((string)$options['join']);
    $refusal = admin_management_node_url_refusal($url);
    if ($refusal !== null) {
        fwrite(STDERR, "join: {$refusal}\n");
        exit(1);
    }
    $request = admin_management_node_cli_join_request(
        agent_control_setting('agent_join_request'), $url, gmdate('Y-m-d H:i:s'));
    if ($request === null) {
        // The same ask, still waiting: keep it, and the key the agent staged for it.
        echo "join: already requested of " . rtrim($url, '/')
           . " and waiting for approval there — the request and its key fingerprint are unchanged\n";
    } else {
        Setting::put('agent_join_request', $request);
        // A fresh ask supersedes whatever an earlier attempt reported.
        Setting::put('agent_join_state', '');
        echo "join: requested — the agent introduces itself to " . rtrim($url, '/')
           . ", and an administrator there approves it after comparing key fingerprints\n";
    }
    $changed = true;
}

if (isset($options['leave'])) {
    Setting::put('agent_leave_request', json_encode([
        'requested_time' => gmdate('Y-m-d H:i:s'),
    ]));
    echo "leave: requested — the agent says one signed goodbye, deletes its identity, and serves only local work\n";
    $changed = true;
}

if (isset($options['status']) || $changed) {
    // Read back from the database, not from the settings instance: it was
    // loaded before the writes above and would report the old values.
    $state   = json_decode(agent_control_setting('agent_join_state'), true);
    $join    = json_decode(agent_control_setting('agent_join_request'), true);
    $leave   = json_decode(agent_control_setting('agent_leave_request'), true);

    echo "\n";
    echo "  agent installed:    " . (file_exists(ADMIN_MANAGEMENT_NODE_AGENT_BINARY) ? 'yes' : 'no') . "\n";
    echo "  management node:    " . (is_array($state) && !empty($state['status'])
        ? $state['status'] . (empty($state['url']) ? '' : ' (' . $state['url'] . ')')
        : 'not connected') . "\n";
    if (is_array($state) && !empty($state['fingerprint'])) {
        echo "  key fingerprint:    " . trim(chunk_split((string)$state['fingerprint'], 4, ' ')) . "\n";
    }
    if (is_array($join) && !empty($join['url'])) {
        echo "  join requested:     " . $join['url'] . "\n";
    }
    if (is_array($leave)) {
        echo "  leave requested:    yes\n";
    }
}

exit(0);
