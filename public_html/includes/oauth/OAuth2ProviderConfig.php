<?php
/**
 * OAuth2ProviderConfig - Reading and writing a provider's app registration.
 *
 * One saver, used by every surface that collects an app registration: the admin
 * OAuth providers page and the DNS publish box, which offers configuration in
 * place rather than sending an operator away to finish a publish. Both drive off
 * the provider's own configFields(), so neither can drift from the other or from
 * what the provider actually reads.
 *
 * Secrets are written through SecretBox and never echoed back, so a blank secret
 * field means "leave the stored value alone" — an admin can correct a client id
 * without re-entering a secret they cannot read.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class OAuth2ProviderConfig {

	/** Settings group new rows are filed under. */
	const GROUP = 'oauth';

	/**
	 * Save one provider's app registration from posted input.
	 *
	 * @param string $provider_class An OAuth2Provider implementation
	 * @param array  $input          Posted values, keyed {prefix}{setting name}
	 * @param string $prefix         Input-name prefix the caller used
	 * @param object $session        SessionControl, for the audit user id
	 * @return string '' on success, or a message describing why nothing was saved
	 */
	public static function save(string $provider_class, array $input, string $prefix, $session): string {
		$box = null;
		$writes = array();

		foreach ($provider_class::configFields() as $setting => $spec) {
			$value = trim((string)($input[$prefix . $setting] ?? ''));
			if ($value === '') {
				continue;
			}
			if (!empty($spec['secret'])) {
				if ($box === null) {
					try {
						$box = new SecretBox();
					} catch (Throwable $e) {
						return 'Cannot store the client secret: ' . $e->getMessage();
					}
				}
				$value = $box->encrypt($value);
			}
			$writes[$setting] = $value;
		}

		foreach ($writes as $setting => $value) {
			self::upsert($setting, $value, $session);
		}
		return '';
	}

	/** Create or update a single setting by name. */
	public static function upsert(string $name, string $value, $session): void {
		$existing = new MultiSetting(array('setting_name' => $name), NULL, NULL, NULL, NULL);
		$existing->load();

		$found = false;
		foreach ($existing as $setting) {
			$setting->set('stg_value', $value);
			$setting->set('stg_update_time', 'NOW()');
			$setting->set('stg_usr_user_id', $session->get_user_id());
			$setting->prepare();
			$setting->save();
			$found = true;
		}

		if (!$found) {
			$new = new Setting(NULL);
			$new->set('stg_name', $name);
			$new->set('stg_value', $value);
			$new->set('stg_usr_user_id', $session->get_user_id());
			$new->set('stg_group_name', self::GROUP);
			$new->prepare();
			$new->save();
		}
	}
}
