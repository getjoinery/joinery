<?php
/**
 * CLI control of this machine's Joinery agent — the same three decisions the
 * Management Node admin page offers, for a shell (specs/agent_on_node_architecture.md).
 *
 * Everything here writes a setting and nothing else. The agent's installer
 * reads the switch at the next root moment; the running agent reads the join
 * and leave requests within seconds. Nothing in this file needs root, holds a
 * credential, or can enroll this machine anywhere on its own — a join is a
 * request, which an administrator on the management node still has to approve
 * after comparing key fingerprints.
 *
 * Usage:
 *   php utils/agent_control.php --status
 *   php utils/agent_control.php --on
 *   php utils/agent_control.php --off
 *   php utils/agent_control.php --join=https://manage.example.com
 *   php utils/agent_control.php --leave
 *
 * --on and --join may be given together: turn the agent on and ask to join in
 * one call, which is what a management node's remote enable does.
 *
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

$options = getopt('', ['status', 'on', 'off', 'join::', 'leave', 'help']);

if ($options === false || isset($options['help']) || $options === []) {
    echo "Control this machine's Joinery agent.\n\n";
    echo "  --status              what the agent is set to do here\n";
    echo "  --on                  run the agent on this machine\n";
    echo "  --off                 stop running it\n";
    echo "  --join=URL            ask to join that management node\n";
    echo "  --leave               disconnect from the management node\n";
    exit(isset($options['help']) ? 0 : 1);
}

if (isset($options['on']) && isset($options['off'])) {
    fwrite(STDERR, "--on and --off contradict each other.\n");
    exit(1);
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

if (isset($options['on'])) {
    Setting::put('agent_enabled', '1');
    echo "agent: on — it installs and starts at the next container start, upgrade, or installer run\n";
    $changed = true;
}

if (isset($options['off'])) {
    Setting::put('agent_enabled', '');
    echo "agent: off — it stops at the next container start, upgrade, or installer run\n";
    $changed = true;
}

if (isset($options['join'])) {
    $url     = trim((string)$options['join']);
    $refusal = admin_management_node_url_refusal($url);
    if ($refusal !== null) {
        fwrite(STDERR, "join: {$refusal}\n");
        exit(1);
    }
    Setting::put('agent_join_request', json_encode([
        'url'            => rtrim($url, '/'),
        'requested_time' => gmdate('Y-m-d H:i:s'),
    ]));
    // A fresh ask supersedes whatever an earlier attempt reported.
    Setting::put('agent_join_state', '');
    echo "join: requested — the agent introduces itself to " . rtrim($url, '/')
       . ", and an administrator there approves it after comparing key fingerprints\n";
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
    $enabled = admin_management_node_agent_switch_on(agent_control_setting('agent_enabled'));
    $state   = json_decode(agent_control_setting('agent_join_state'), true);
    $join    = json_decode(agent_control_setting('agent_join_request'), true);
    $leave   = json_decode(agent_control_setting('agent_leave_request'), true);

    echo "\n";
    echo "  agent enabled here: " . ($enabled ? 'yes' : 'no') . "\n";
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
