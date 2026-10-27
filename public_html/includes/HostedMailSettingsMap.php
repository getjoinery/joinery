<?php
/**
 * HostedMailSettingsMap — the one list of which settings an operator-minted
 * mail credential lands in.
 *
 * Two paths hand this site an SMTP credential cut from an operator's account:
 * the management node's hosted_mail_settings primitive (a Managed site; the
 * values arrive over the agent channel and utils/hosted_mail_settings.php
 * writes them) and the services enrol call (a self-hosted site connected to
 * getjoinery; the values come back in the enrol response and the Email step
 * writes them). Both write the same nine settings from the same eight values,
 * and the mapping lives here once so the two can never drift.
 *
 * THE SETTING NAMES ARE HERE, NOT ON THE WIRE. What arrives is VALUES; which
 * settings they land in is decided by this list, on this machine, in a file
 * the release manifest covers. See utils/hosted_mail_settings.php for why
 * that boundary matters: a site whose outbound mail can be pointed elsewhere
 * is a site whose password resets can be.
 *
 * @version 1.1 - defaultFrom(): a site that has no From address yet gets hello@ its sending
 *   domain, replies to its owner (B166)
 * @version 1.0 - lifted from utils/hosted_mail_settings.php
 */
class HostedMailSettingsMap {

	/** setting name => the incoming value it takes. */
	const MAP = array(
		'email_service' => 'service',
		'smtp_host'     => 'host',
		'smtp_port'     => 'port',
		'smtp_username' => 'username',
		'smtp_password' => 'password',
		'smtp_sender'   => 'sender',
		'smtp_helo'     => 'helo',
		'smtp_hostname' => 'hostname',
	);

	/**
	 * Write every setting in the map from $values, then smtp_auth derived
	 * from whether a username was supplied. EVERY KEY IS WRITTEN, including
	 * ones that arrive empty, so a push converges on a desired state.
	 *
	 * @param array $values service, host, port, username, password, sender, helo, hostname
	 * @return string[] The names written, in order — never a value.
	 * @throws Throwable from Setting::put on a refused or failed write; the
	 *         names already written are in the exception's previous chain's
	 *         message where the caller reports them.
	 */
	public static function apply(array $values): array {
		$written = array();
		foreach (self::MAP as $setting => $key) {
			$value = (string)($values[$key] ?? '');
			// The password is NOT trimmed: whitespace in a minted password is
			// the provider's business, and altering a credential produces an
			// authentication failure nobody can explain.
			if ($key !== 'password') {
				$value = trim($value);
			}
			try {
				Setting::put($setting, $value);
				Globalvars::get_instance()->forget_setting($setting);
			} catch (\Throwable $e) {
				throw new RuntimeException($setting . ': ' . $e->getMessage()
					. ($written ? ' (already written: ' . implode(', ', $written) . ')' : ''), 0, $e);
			}
			$written[] = $setting;
		}
		Setting::put('smtp_auth', trim((string)($values['username'] ?? '')) !== '' ? '1' : '0');
		Globalvars::get_instance()->forget_setting('smtp_auth');
		$written[] = 'smtp_auth';
		return $written;
	}

	/** The local part of the From address a site is given when it has none. */
	const DEFAULT_FROM_LOCAL = 'hello';

	/**
	 * Give a site that has no From address one on its sending domain, with
	 * replies to its owner. Returns the names written; empty when the site
	 * already had a From address.
	 *
	 * A Managed site is born with no From address, and the setup wizard's
	 * mail step is where the owner picks one. Until then every send would fail,
	 * including the forgot-password the buyer falls back on if the one-time
	 * admin password reveal is lost. So the mail credential arriving brings a
	 * default: hello@<domain> (the domain the provider verified, so it can
	 * send), replying to the owner account's address, which the install set to
	 * the buyer's.
	 *
	 * ONLY WHEN BLANK, and that is the opposite of apply() on purpose: these
	 * are the owner's choice, and a later push of a replacement credential must
	 * never overwrite an address they picked. The values are fixed here, not
	 * taken from the wire, so a management node can trigger this and cannot
	 * choose what it writes.
	 */
	public static function defaultFrom(string $sending_domain): array {
		$domain = strtolower(trim($sending_domain));
		$settings = Globalvars::get_instance();
		if ($domain === '' || trim((string)$settings->get_setting('defaultemail')) !== '') {
			return array();
		}
		$written = array();
		Setting::put('defaultemail', self::DEFAULT_FROM_LOCAL . '@' . $domain);
		$settings->forget_setting('defaultemail');
		$written[] = 'defaultemail';

		if (trim((string)$settings->get_setting('defaultreplyto')) === '') {
			$q = DbConnector::get_instance()->get_db_link()->query(
				"SELECT usr_email FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL"
				. " AND NOT usr_is_disabled AND COALESCE(usr_email, '') <> '' ORDER BY usr_user_id LIMIT 1");
			$owner = trim((string)$q->fetchColumn());
			if ($owner !== '' && filter_var($owner, FILTER_VALIDATE_EMAIL)) {
				Setting::put('defaultreplyto', $owner);
				$settings->forget_setting('defaultreplyto');
				$written[] = 'defaultreplyto';
			}
		}
		return $written;
	}

	/** Clear every setting in the map plus smtp_auth: a dead credential must not linger. */
	public static function clear(): array {
		$blank = array();
		foreach (self::MAP as $key) {
			$blank[$key] = '';
		}
		return self::apply($blank);
	}
}
