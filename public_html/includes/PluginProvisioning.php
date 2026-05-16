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
 * @version 1.0
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
     *                              script?, script_command?]
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

        // A fix command is only meaningful for an unmet provisioner that
        // declares a script. Resolve it to an absolute path.
        if ($result['state'] === 'unmet' && !empty($declaration['script'])) {
            $rel = 'plugins/' . $plugin . '/' . ltrim($declaration['script'], '/');
            $result['script']         = $declaration['script'];
            $result['script_command'] = 'sudo bash ' . PathHelper::getAbsolutePath($rel);
        }

        return $result;
    }

    /**
     * Code check: invoke the plugin's acquisition routine and catch the result.
     *
     * Returns normally               → verified
     * Throws ProvisioningCheckFailed  → unmet (the expected dependency failure)
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
            return ['state' => 'unmet', 'reason' => $e->getMessage()];
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
     * host from where this code runs.
     */
    public static function resolveHost($host) {
        if ($host !== 'host-gateway') {
            return $host;
        }

        // Bare metal: services on the host are simply localhost.
        if (!file_exists('/.dockerenv')) {
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

        // Conventional Docker bridge gateway as a last resort.
        return '172.17.0.1';
    }
}
