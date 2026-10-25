<?php
/**
 * BetaPermissions — the two things a site's owner lets a management node keep
 * while Joinery is in beta (specs/implemented/beta_break_glass_root_access.md 4.2): the
 * emergency root access the install may have offered (beta_root_access), and
 * the existing log access (agent_log_access). One reader and one writer for
 * the setup wizard's Beta permissions step and the Management Node page, so
 * the two never disagree.
 *
 * Emergency root access is one-way: once off, nothing here turns it back on.
 * The site's agent reports the answer on every poll; the management node's
 * operator who holds the password retires it.
 *
 * @version 1.1 - connected(): whether a management node manages this site, for the step's log-access line
 * @version 1.0
 */
class BetaPermissions {

	/**
	 * A stored on/off value read with the spellings the agent reads it with
	 * (primitives.SettingOn), so the page and the machine agree.
	 */
	public static function switch_on(string $value): bool {
		return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
	}

	/** Did the install offer the owner emergency root access (install.sh site --beta-root-access)? */
	public static function root_access_offered(): bool {
		return (string)Globalvars::get_instance()->get_setting('beta_root_access_offered', true, true) === '1';
	}

	/** Is emergency root access still allowed? On until the owner turns it off. */
	public static function root_access_on(): bool {
		return self::switch_on((string)Globalvars::get_instance()->get_setting('beta_root_access', true, true));
	}

	/** Is this site connected to a management node? The agent's own report (agent_join_state). */
	public static function connected(): bool {
		$state = json_decode((string)Globalvars::get_instance()->get_setting('agent_join_state', true, true), true);
		return is_array($state) && (string)($state['status'] ?? '') === 'connected';
	}

	/** May a connected management node read this site's logs (agent_log_access)? */
	public static function log_access_on(): bool {
		return self::switch_on((string)Globalvars::get_instance()->get_setting('agent_log_access', true, true));
	}

	/**
	 * Record the owner's answer on emergency root access. Off is permanent:
	 * asking for on after off is refused, and the refusal says so. Returns
	 * null when saved, or the refusal.
	 */
	public static function set_root_access(bool $on): ?string {
		if ($on) {
			if (!self::root_access_on()) {
				return 'Emergency root access was turned off, and that cannot be undone.';
			}
			return null;
		}
		Setting::put('beta_root_access', '0');
		return null;
	}

	/** Record the owner's answer on log access, as the Management Node page's switch does. */
	public static function set_log_access(bool $on): void {
		Setting::put('agent_log_access', $on ? '1' : '');
		Setting::put('agent_log_access_notice_seen', '1');
	}
}
