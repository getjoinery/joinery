<?php
/**
 * PluginProvisioning - detects whether the external runtime resources a
 * plugin depends on are working, and reports which are not.
 *
 * It complements update_database: where update_database handles the database
 * side of plugin setup, this handles mail servers, relays, services and
 * other resources a plugin needs at runtime. It only ever DETECTS and
 * REPORTS — it never executes a fix.
 *
 * A plugin declares its dependencies as a `provisioners` array in plugin.json.
 * Each provisioner carries a `check` object whose `type` is either:
 *   - `code`  — invoke the plugin's own resource-acquisition routine and catch
 *               its failure (the most accurate check; sees what PHP reaches out
 *               to acquire).
 *   - `probe` — open a TCP connection to a host/port (sees push-style
 *               dependencies PHP never connects to, e.g. an inbound mail server).
 *
 * See specs/implemented/plugin_provisioning_checks.md and the
 * "Declaring host provisioners" section of docs/plugin_developer_guide.md.
 *
 * A provisioner declared `"fleet_report": true` is also run on a schedule
 * (tasks/PluginHealthReport.php) and its result recorded, so a management
 * node reads it with the rest of the node's status. See recordFleetReport().
 *
 * @version 1.7 - an unmet check names the plugin's own installer (`fixed_by_installer`), never a script
 *                path to run by hand; a declaration still carrying `script`, or naming the installer on a
 *                plugin that has none, is an error (plugin_installer_from_the_browser.md D3)
 * @version 1.6 - no fix command for a check that cannot be met on this site (ProvisioningCheckRefused)
 * @version 1.5 - the mail check passes when either the mail service or its fallback works, as the sender
 *                tries both; a failure names each
 * @version 1.4 - the fleet report carries core's own check that the site can send mail (outboundMailCheck: a mail
 *                service set, and its provider's settings complete), so a site that cannot fails its node's health
 *                (fleet_security_patching WP10)
 * @version 1.3 - host-gateway in a container with no default route is unmet, not Docker's default
 *                bridge gateway: a site runs on a network of its own (node_outbound_and_transfer WP2)
 * @version 1.2 - fleet reporting: recordFleetReport() / recordedFleetReport()
 * @version 1.1
 */

require_once(__DIR__ . '/PathHelper.php');
require_once(__DIR__ . '/ProvisioningCheckFailed.php');
require_once(__DIR__ . '/PluginHelper.php');

class PluginProvisioning {

    /** Wall-clock bound, in seconds, the system enforces on a probe socket. */
    const PROBE_TIMEOUT = 5;

    /**
     * Read the declared `provisioners` from every ACTIVE plugin's plugin.json.
     * Plugins that declare none are omitted.
     *
     * @return array [pluginName => [provisioner declaration, ...], ...]
     */
    public static function getProvisioners() {
        $result = [];
        foreach (PluginHelper::getActivePlugins() as $name => $helper) {
            $declared = $helper->get('provisioners', []);
            if (is_array($declared) && !empty($declared)) {
                $result[$name] = $declared;
            }
        }
        return $result;
    }

    /**
     * Run every active plugin's provisioning checks, live. Nothing is persisted.
     *
     * @return array [pluginName => [provisionerKey => resultArray, ...], ...]
     *               resultArray = [key, label, details, state, reason,
     *                              fixed_by_installer?]
     *               state ∈ {verified, reachable, unmet, error}
     */
    public static function runChecks() {
        $output = [];
        foreach (self::getProvisioners() as $plugin => $provisioners) {
            $output[$plugin] = [];
            foreach ($provisioners as $declaration) {
                if (!is_array($declaration) || empty($declaration['key'])) {
                    continue;
                }
                $output[$plugin][$declaration['key']] = self::runOne($plugin, $declaration);
            }
        }
        return $output;
    }

    /** The setting the recorded fleet report lives in (settings.json). */
    const FLEET_REPORT_SETTING = 'plugin_fleet_report';

    /** Bounds on one recorded report: a reason is prose for a person, and the
     *  whole report rides every status report a management node receives. */
    const FLEET_REASON_MAX = 500;
    const FLEET_CHECKS_MAX = 50;

    /**
     * Run every active plugin's provisioners declared `fleet_report`, live.
     *
     * @return array ['checked' => UTC 'Y-m-d H:i:s', 'checks' => [
     *                 ['plugin', 'key', 'label', 'state', 'reason'], ...]]
     */
    public static function runFleetReport() {
        // Core's own first: the site can send mail. Not a plugin's, so it is
        // listed under the name core, and first so the cap never drops it.
        $checks = [self::outboundMailCheck()];
        foreach (self::getProvisioners() as $plugin => $provisioners) {
            foreach ($provisioners as $declaration) {
                if (!is_array($declaration) || empty($declaration['key'])
                        || empty($declaration['fleet_report'])) {
                    continue;
                }
                if (count($checks) >= self::FLEET_CHECKS_MAX) {
                    break 2;
                }
                $one = self::runOne($plugin, $declaration);
                $checks[] = [
                    'plugin' => (string)$plugin,
                    'key'    => (string)$one['key'],
                    'label'  => (string)$one['label'],
                    'state'  => (string)$one['state'],
                    'reason' => mb_substr((string)$one['reason'], 0, self::FLEET_REASON_MAX),
                ];
            }
        }
        return ['checked' => gmdate('Y-m-d H:i:s'), 'checks' => $checks];
    }

    /**
     * Whether this site can send mail: its mail service (email_service), or
     * else its fallback (email_fallback_service), is one this release carries
     * and that provider says its own settings are complete
     * (validateConfiguration). EmailSender sends with the fallback when the
     * service is not named, and tries it when the service fails, so either one
     * working is mail leaving. A site with neither sends nothing at all: no
     * password reset, no notice, no receipt (xandyliberato.com after its
     * conversion, 2026-10-21; fleet_security_patching WP10). Reported to a
     * management node with the plugin checks, where a failure fails the node's
     * health.
     *
     * $service and $fallback name the services instead of the site's settings
     * (a test).
     */
    public static function outboundMailCheck(?string $service = null, ?string $fallback = null): array {
        $out = ['plugin' => 'core', 'key' => 'outbound_mail', 'label' => 'This site can send mail', 'state' => 'verified', 'reason' => ''];
        $settings = Globalvars::get_instance();
        $named = array_values(array_unique(array_filter(array(
            trim((string)($service ?? EmailSender::activeServiceKey())),
            trim((string)($fallback ?? $settings->get_setting('email_fallback_service'))),
        ), 'strlen')));
        if (!$named) {
            $out['state'] = 'error';
            $out['reason'] = 'No mail service is set (email_service), so nothing this site sends leaves it: password resets, notices, receipts. Choose one in the email settings.';
            return $out;
        }
        $why = array();
        foreach ($named as $i => $key) {
            $provider = EmailSender::providerFor($key);
            if (!$provider) {
                $what = "the mail service '{$key}'";
                $problem = 'is not one this release carries';
            } else {
                $class = get_class($provider);
                $v = $class::validateConfiguration();
                if (!empty($v['valid'])) {
                    return $out;
                }
                $what = $class::getLabel();
                $problem = 'is not set up: ' . implode('; ', array_map('strval', (array)($v['errors'] ?? array())));
            }
            $why[] = ($i === 0 ? $what : 'its fallback, ' . $what . ',') . ' ' . $problem;
        }
        $out['state'] = 'error';
        $out['reason'] = mb_substr(ucfirst(implode('; and ', $why)) . '. Nothing this site sends leaves it. Set one up in the email settings.', 0, self::FLEET_REASON_MAX);
        return $out;
    }

    /**
     * Run the fleet-reported provisioners and record the result.
     *
     * Recorded rather than run on request because the node's status reaches a
     * management node two ways, and one of them (the agent's check_status)
     * reads the database and runs no PHP. Both read this one record, so the
     * two transports report the same answer.
     *
     * @return array the report recorded
     */
    public static function recordFleetReport() {
        $report = self::runFleetReport();
        Setting::put(self::FLEET_REPORT_SETTING, json_encode($report));
        return $report;
    }

    /**
     * The last recorded fleet report, or null when none has been recorded.
     */
    public static function recordedFleetReport() {
        $raw = (string)Globalvars::get_instance()->get_setting(self::FLEET_REPORT_SETTING, true, true);
        if ($raw === '') {
            return null;
        }
        $report = json_decode($raw, true);
        return (is_array($report) && isset($report['checks']) && is_array($report['checks'])) ? $report : null;
    }

    /**
     * Evaluate a single provisioner declaration.
     */
    private static function runOne($plugin, array $declaration) {
        $result = [
            'key'     => $declaration['key'],
            'label'   => $declaration['label'] ?? $declaration['key'],
            'details' => $declaration['details'] ?? '',
            'state'   => 'error',
            'reason'  => '',
        ];

        // Root only ever runs a plugin's declared host_installer, so a check
        // names that, never a script of its own: a second path per check
        // would be a second list of files that could run as root.
        if (array_key_exists('script', $declaration)) {
            $result['reason'] = "Provisioner declares 'script', which is no longer read. "
                . "Declare \"fixed_by_installer\": true when the plugin's host_installer fixes this check.";
            return $result;
        }
        $fixed_by_installer = !empty($declaration['fixed_by_installer']);
        if ($fixed_by_installer && !self::hasHostInstaller($plugin)) {
            $result['reason'] = "Provisioner declares fixed_by_installer, but the plugin declares no host_installer to run.";
            return $result;
        }

        $check = $declaration['check'] ?? null;
        if (!is_array($check) || empty($check['type'])) {
            $result['reason'] = 'Provisioner declaration is missing a valid check.';
            return $result;
        }

        // v1 has two check types; a plain match is all that is warranted.
        $outcome = match ($check['type']) {
            'code'  => self::runCodeCheck($plugin, $check),
            'probe' => self::runProbeCheck($check),
            default => ['state' => 'error', 'reason' => "Unknown check type '{$check['type']}'."],
        };
        $result['state']  = $outcome['state'];
        $result['reason'] = $outcome['reason'];

        // The page offers Run setup again only for an unmet check the
        // plugin's installer could fix, and not one refused on this site.
        if ($result['state'] === 'unmet' && empty($outcome['refused']) && $fixed_by_installer) {
            $result['fixed_by_installer'] = true;
        }

        return $result;
    }

    /** Whether the plugin's manifest declares a non-empty host_installer. */
    public static function hasHostInstaller($plugin) {
        $helper = PluginHelper::getInstance($plugin);
        $declared = $helper ? $helper->get('host_installer', '') : '';
        return is_string($declared) && $declared !== '';
    }

    /**
     * Code check: invoke the plugin's acquisition routine and catch the result.
     *
     * Returns normally               → verified
     * Throws ProvisioningCheckFailed  → unmet (the expected dependency failure;
 *                                   ProvisioningCheckRefused: no fix command)
     * Throws anything else / unloadable → error (the check itself is faulty)
     */
    private static function runCodeCheck($plugin, array $check) {
        $call = $check['call'] ?? '';
        if (substr_count($call, '::') !== 1) {
            return ['state' => 'error', 'reason' => "Code check 'call' must be in Class::method form."];
        }
        [$class, $method] = explode('::', $call, 2);

        // Convention: the check class lives in the plugin's includes/ directory.
        if (!class_exists($class)) {
            $file = PathHelper::getIncludePath('plugins/' . $plugin . '/includes/' . $class . '.php');
            if (is_file($file)) {
                require_once($file);
            }
        }
        if (!class_exists($class) || !method_exists($class, $method)) {
            return ['state' => 'error', 'reason' => "Check method {$call} could not be loaded."];
        }

        try {
            call_user_func([$class, $method]);
            return ['state' => 'verified', 'reason' => ''];
        } catch (ProvisioningCheckFailed $e) {
            return ['state' => 'unmet', 'reason' => $e->getMessage(), 'refused' => $e instanceof ProvisioningCheckRefused];
        } catch (\Throwable $e) {
            return ['state' => 'error', 'reason' => 'Check method raised an unexpected error: ' . $e->getMessage()];
        }
    }

    /**
     * Probe check: open a TCP connection within a system-enforced timeout.
     *
     * Connects   → reachable (something is listening; correctness unconfirmed)
     * Refused/timeout → unmet
     * Bad declaration → error
     */
    private static function runProbeCheck(array $check) {
        $kind = $check['probe'] ?? 'tcp';
        if ($kind !== 'tcp') {
            return ['state' => 'error', 'reason' => "Unsupported probe kind '{$kind}'."];
        }

        $host = $check['host'] ?? '';
        $port = $check['port'] ?? null;
        if (!is_string($host) || $host === '') {
            return ['state' => 'error', 'reason' => 'Probe declaration is missing a host.'];
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            return ['state' => 'error', 'reason' => 'Probe declaration has an invalid port.'];
        }

        $resolved = self::resolveHost($host);
        if ($resolved === null) {
            return ['state' => 'unmet', 'reason' => 'this container has no default route, so the host cannot be reached'];
        }

        $errno  = 0;
        $errstr = '';
        $conn = @fsockopen($resolved, $port, $errno, $errstr, self::PROBE_TIMEOUT);
        if ($conn) {
            fclose($conn);
            return ['state' => 'reachable', 'reason' => ''];
        }

        $why = trim($errstr) !== '' ? trim($errstr) : 'connection refused';
        return ['state' => 'unmet', 'reason' => $why . " ({$resolved}:{$port})"];
    }

    /**
     * Resolve a probe host. A literal IP/hostname passes through unchanged;
     * the `host-gateway` token resolves to whatever reaches services on the
     * host from where this code runs, or null in a container with no default
     * route, from which nothing reaches the host.
     */
    public static function resolveHost($host) {
        if ($host !== 'host-gateway') {
            return $host;
        }

        // Bare metal: services on the host are simply localhost. The deployment
        // environment is recorded once at install time (spec
        // deployment_environment_flag) — no runtime container detection.
        if (Globalvars::get_instance()->get_setting('deployment_environment', true, true) !== 'docker') {
            return '127.0.0.1';
        }

        // In a container: the host is the container's default-route gateway.
        $route = @file_get_contents('/proc/net/route');
        if ($route !== false) {
            foreach (explode("\n", $route) as $line) {
                $fields = preg_split('/\s+/', trim($line));
                // Fields: Iface Destination Gateway Flags ...
                if (count($fields) < 3 || $fields[1] !== '00000000') {
                    continue;
                }
                $gatewayHex = $fields[2];
                if (strlen($gatewayHex) === 8 && ctype_xdigit($gatewayHex)) {
                    // /proc stores the address little-endian.
                    $octets = array_reverse(str_split($gatewayHex, 2));
                    return implode('.', array_map('hexdec', $octets));
                }
            }
        }

        // No default route: nothing reaches the host from here. A site runs on
        // a network of its own, so no fixed address would be the host either.
        return null;
    }
}
