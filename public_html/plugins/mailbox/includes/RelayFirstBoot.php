<?php
/**
 * RelayFirstBoot - render the user-data a relay is born from.
 *
 * specs/relay_without_a_shell.md § Birth. The template is
 * provisioning/relay_first_boot.sh; this class fills its placeholders from a
 * provisioning run and hands back the script the provider runs at first boot.
 * Two forms, one template:
 *
 *   render()       the values baked in - what the provider's Metadata service
 *                  (cloud-init user-data) carries
 *   stackScript()  the placeholders left in place under a UDF header - what a
 *                  region without Metadata runs as a StackScript, the same
 *                  fields arriving as environment variables
 *
 * The user-data carries only public keys, the bundle's hash, the plane's URL
 * and the one-time run token. It is readable by root on the box and by the
 * account holder through the provider; it holds no secret that outlives the
 * boot.
 *
 * @version 1.0
 */

class RelayFirstBoot {

	/** Every placeholder the template declares, in the order the header lists them. */
	const FIELDS = array(
		'PLANE', 'RUN_ID', 'RUN_TOKEN', 'BUNDLE_SHA256', 'MAIL_HOSTNAME', 'AUTHSERV_ID',
		'CLIENT_PUBLIC_KEY', 'OPERATOR_PUBLIC_KEY', 'SKELETON_ONLY',
	);

	/** The fields a run must supply; the rest may be empty. */
	const REQUIRED = array('PLANE', 'RUN_ID', 'RUN_TOKEN', 'BUNDLE_SHA256', 'MAIL_HOSTNAME');

	/** The template's path. */
	public static function templatePath(): string {
		return PathHelper::getIncludePath('plugins/mailbox/provisioning/relay_first_boot.sh');
	}

	/**
	 * The first-boot script with every placeholder filled.
	 *
	 * @param array $fields lowercase or uppercase keys matching FIELDS; an
	 *                      unsupplied optional field renders empty
	 */
	public static function render(array $fields): string {
		$values = self::normalise($fields);
		foreach (self::REQUIRED as $name) {
			if ($values[$name] === '') {
				throw new InvalidArgumentException('RelayFirstBoot: ' . strtolower($name) . ' is required to render the user-data.');
			}
		}
		$template = self::template();
		foreach (self::FIELDS as $name) {
			$template = str_replace('__' . $name . '__', self::shellSafe($values[$name]), $template);
		}
		return $template;
	}

	/**
	 * The same script as a Linode StackScript: a UDF header declaring each field,
	 * and the placeholders left in place so the `${NAME:-__NAME__}` defaults fall
	 * through to the UDF environment. The run token is a UDF like the rest:
	 * readable by the account holder, exactly as user-data is.
	 */
	public static function stackScript(): string {
		$udf = "#!/usr/bin/env bash\n";
		$labels = array(
			'PLANE' => 'Plane URL', 'RUN_ID' => 'Provisioning run id', 'RUN_TOKEN' => 'One-time run token',
			'BUNDLE_SHA256' => 'Bundle sha256', 'MAIL_HOSTNAME' => 'Mail hostname', 'AUTHSERV_ID' => 'Authserv-id',
			'CLIENT_PUBLIC_KEY' => 'Tenant public key', 'OPERATOR_PUBLIC_KEY' => 'Operator public key',
			'SKELETON_ONLY' => 'Skeleton only (0|1)',
		);
		foreach (self::FIELDS as $name) {
			$default = in_array($name, self::REQUIRED, true) ? '' : ' default=""';
			$udf .= '# <UDF name="' . $name . '" label="' . $labels[$name] . '"' . $default . " />\n";
		}
		// The template's own shebang gives way to the one above.
		$body = preg_replace('/^#!.*\n/', '', self::template(), 1);
		return $udf . $body;
	}

	/** The sha256 of the rendered user-data, for a run to record what it created. */
	public static function digest(string $rendered): string {
		return hash('sha256', $rendered);
	}

	private static function template(): string {
		$raw = @file_get_contents(self::templatePath());
		if ($raw === false || $raw === '') {
			throw new RuntimeException('RelayFirstBoot: the first-boot template is missing at ' . self::templatePath());
		}
		return $raw;
	}

	private static function normalise(array $fields): array {
		$values = array_fill_keys(self::FIELDS, '');
		foreach ($fields as $key => $value) {
			$name = strtoupper((string)$key);
			if (array_key_exists($name, $values)) {
				$values[$name] = trim((string)$value);
			}
		}
		if ($values['AUTHSERV_ID'] === '') {
			$values['AUTHSERV_ID'] = $values['MAIL_HOSTNAME'];
		}
		$values['SKELETON_ONLY'] = ($values['SKELETON_ONLY'] === '1' || $values['SKELETON_ONLY'] === 'true') ? '1' : '0';
		return $values;
	}

	/**
	 * A value lands inside double quotes in a shell script. Every field is a
	 * URL, an id, a hash, a hostname or base64 - none may carry a quote, a
	 * dollar, a backtick, a backslash or a newline, so one is refused rather
	 * than escaped: a value that needs escaping is not one of these.
	 */
	private static function shellSafe(string $value): string {
		if (preg_match('/["$`\\\\\r\n]/', $value)) {
			throw new InvalidArgumentException('RelayFirstBoot: a user-data value carries a shell metacharacter.');
		}
		return $value;
	}
}
?>
