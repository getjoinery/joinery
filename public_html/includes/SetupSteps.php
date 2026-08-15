<?php
/**
 * SetupSteps — the setup step registry (specs/setup_wizard.md).
 *
 * Core and plugins register steps here at load time; the /setup wizard is a
 * sequential presentation of this registry and the admin dashboard's setup
 * band (specs/admin_dashboard.md) reads the same one. Completion is never
 * stored: every status() is derived live from real state, with a
 * SetupDecision row as the tie-breaker for optional steps only.
 *
 * A step is an array:
 *   'title'       page heading
 *   'scope'       'site' | 'user' — site steps render only for permission 10
 *   'order'       position in the flow
 *   'copy'        the two-sentence intro (shared with the dashboard card)
 *   'status'      callable(?User $viewer): 'green'|'amber'|'none'
 *   'render_file' include-path of the step's form partial (non-routable dir)
 *   'active'      optional callable(?User $viewer): bool — hidden when false
 *   'decision'    optional 'user'|'site' — the step accepts a "not now" answer,
 *                 which counts as green (the wizard measures decided, not
 *                 enabled). Real state always wins over the decision row.
 *   'real_status' optional callable(?User $viewer): string — the same question
 *                 as status() ignoring any decision, so the wizard can tell a
 *                 step that is truly done from one that was declined
 *
 * Plugins register from their serve.php (loaded every request while active),
 * so registration must stay cheap: closures only, no queries at register time.
 *
 * @version 1.1
 */

class SetupSteps {

	const STATUS_GREEN = 'green';
	const STATUS_AMBER = 'amber';
	const STATUS_NONE  = 'none';

	/** Session keys: sticky all-clear for the login gate, and the pill cache. */
	const SESSION_CLEAR = 'setup_wizard_clear';
	const SESSION_PILL  = 'setup_wizard_pill';
	const PILL_TTL = 300;

	/** @var array<string,array> keyed by step key */
	private static $steps = [];

	/** @var User|null per-request viewer memo */
	private static $viewer_user = null;
	private static $viewer_loaded = false;

	/** Register a step. Idempotent (last-wins by key). */
	public static function register(string $key, array $step): void {
		$step['key'] = $key;
		self::$steps[$key] = $step;
	}

	/** @var bool plugin bootstraps pulled in for this request */
	private static $bootstraps_loaded = false;

	/**
	 * Plugin steps register from plugin bootstraps, which the platform loads
	 * lazily — so the registry pulls them in itself before anyone reads it.
	 * PluginBootstraps::load() is idempotent and once-per-request.
	 */
	private static function ensurePluginSteps(): void {
		if (self::$bootstraps_loaded) {
			return;
		}
		self::$bootstraps_loaded = true;
		require_once(PathHelper::getIncludePath('includes/PluginBootstraps.php'));
		try {
			PluginBootstraps::load();
		} catch (Throwable $e) {
			error_log('SetupSteps: plugin bootstrap load failed: ' . $e->getMessage());
		}
	}

	/** All registered steps, flow order. */
	public static function steps(): array {
		self::ensurePluginSteps();
		$steps = array_values(self::$steps);
		usort($steps, function ($a, $b) {
			return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
		});
		return $steps;
	}

	/**
	 * The steps in one viewer's scope: user-scope steps for everyone,
	 * site-scope steps for the owner (permission 10), active() gates applied.
	 */
	public static function stepsForViewer(int $permission, ?User $viewer): array {
		$out = array();
		foreach (self::steps() as $step) {
			if (($step['scope'] ?? 'site') === 'site' && $permission < 10) {
				continue;
			}
			if (isset($step['active']) && !call_user_func($step['active'], $viewer)) {
				continue;
			}
			$out[] = $step;
		}
		return $out;
	}

	/** A single registered step, or null. */
	public static function get(string $key): ?array {
		return self::$steps[$key] ?? null;
	}

	/**
	 * Whether a step is green only because its question was answered "not now"
	 * — the underlying thing is still not set up. Steps that want this
	 * distinction declare 'real_status', a predicate that ignores decisions;
	 * the wizard renders their controls with a "you chose not to" note rather
	 * than the "already done" row, so declining stays reversible in place.
	 */
	public static function isDeclinedOnly(array $step, ?User $viewer): bool {
		if (empty($step['decision']) || empty($step['real_status'])) {
			return false;
		}
		try {
			$real = call_user_func($step['real_status'], $viewer);
		} catch (Throwable $e) {
			error_log('SetupSteps: real_status predicate for "' . ($step['key'] ?? '?') . '" threw: ' . $e->getMessage());
			return false;
		}
		if ($real === self::STATUS_GREEN) {
			return false;
		}
		$user_id = ($step['decision'] === 'user' && $viewer) ? (int)$viewer->key : NULL;
		return self::hasDecision((string)($step['key'] ?? ''), $user_id);
	}

	/** A step's live status. A predicate that throws reads as 'none', never a fatal. */
	public static function statusFor(array $step, ?User $viewer): string {
		try {
			$status = call_user_func($step['status'], $viewer);
		} catch (Throwable $e) {
			error_log('SetupSteps: status predicate for "' . ($step['key'] ?? '?') . '" threw: ' . $e->getMessage());
			return self::STATUS_NONE;
		}
		return in_array($status, array(self::STATUS_GREEN, self::STATUS_AMBER), true) ? $status : self::STATUS_NONE;
	}

	// ---- Decisions ("not now" answers for optional steps) ----

	/** Record that an optional step's question was answered. $user_id NULL = site scope. */
	public static function recordDecision(string $step_key, ?int $user_id): void {
		if (self::hasDecision($step_key, $user_id)) {
			return;
		}
		require_once(PathHelper::getIncludePath('data/setup_decisions_class.php'));
		$decision = new SetupDecision(NULL);
		$decision->set('sud_step_key', $step_key);
		$decision->set('sud_usr_user_id', $user_id);
		$decision->set('sud_decision', 'declined');
		$decision->save();
	}

	public static function hasDecision(string $step_key, ?int $user_id): bool {
		require_once(PathHelper::getIncludePath('data/setup_decisions_class.php'));
		$multi = new MultiSetupDecision(array('step_key' => $step_key, 'user_id' => $user_id));
		return $multi->count_all() > 0;
	}

	// ---- The login gate and the header pill ----

	/** The signed-in viewer's User row, loaded once per request. */
	public static function viewerUser(): ?User {
		if (self::$viewer_loaded) {
			return self::$viewer_user;
		}
		self::$viewer_loaded = true;
		$session = SessionControl::get_instance();
		$user_id = (int)$session->get_user_id();
		if ($user_id > 0) {
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			try {
				self::$viewer_user = new User($user_id, TRUE);
			} catch (Throwable $e) {
				self::$viewer_user = null;
			}
		}
		return self::$viewer_user;
	}

	/**
	 * Whether the login redirect to /setup should fire for this request:
	 * the viewer has never dismissed the wizard and a step in their scope is
	 * not green. Once dismissal or all-green is seen, an all-clear sticks in
	 * the session and this costs nothing further.
	 */
	public static function shouldInterrupt(): bool {
		if (PHP_SAPI === 'cli') {
			return false;
		}
		if (!empty($_SESSION[self::SESSION_CLEAR])) {
			return false;
		}
		$session = SessionControl::get_instance();
		if (!$session->is_logged_in()) {
			return false;
		}
		$viewer = self::viewerUser();
		if (!$viewer || !$viewer->key) {
			return false;
		}
		if ($viewer->get('usr_setup_dismissed_time')) {
			$_SESSION[self::SESSION_CLEAR] = 1;
			return false;
		}
		foreach (self::stepsForViewer((int)$session->get_permission(), $viewer) as $step) {
			if (self::statusFor($step, $viewer) !== self::STATUS_GREEN) {
				return true;
			}
		}
		$_SESSION[self::SESSION_CLEAR] = 1;
		return false;
	}

	/**
	 * "Finish setup — n of m" counts for the header pill, or null when there
	 * is nothing left to show. Cached in the session for PILL_TTL seconds so
	 * the header never runs the predicates on every page view.
	 */
	public static function pillCounts(): ?array {
		if (PHP_SAPI === 'cli') {
			return null;
		}
		$session = SessionControl::get_instance();
		if (!$session->is_logged_in()) {
			return null;
		}
		$cached = $_SESSION[self::SESSION_PILL] ?? null;
		if (is_array($cached) && (time() - (int)($cached['time'] ?? 0)) < self::PILL_TTL) {
			return $cached['complete'] ? null : array('done' => $cached['done'], 'total' => $cached['total']);
		}
		$viewer = self::viewerUser();
		if (!$viewer || !$viewer->key) {
			return null;
		}
		$total = 0;
		$done = 0;
		foreach (self::stepsForViewer((int)$session->get_permission(), $viewer) as $step) {
			$total++;
			if (self::statusFor($step, $viewer) === self::STATUS_GREEN) {
				$done++;
			}
		}
		$complete = ($total === 0 || $done === $total);
		$_SESSION[self::SESSION_PILL] = array('time' => time(), 'done' => $done, 'total' => $total, 'complete' => $complete);
		return $complete ? null : array('done' => $done, 'total' => $total);
	}

	/** Drop the session caches after a step-affecting write (wizard POSTs call this). */
	public static function invalidateSessionCache(): void {
		unset($_SESSION[self::SESSION_CLEAR]);
		unset($_SESSION[self::SESSION_PILL]);
	}

	/** Clear the registry (tests only). */
	public static function resetCache(): void {
		self::$steps = [];
		self::$viewer_user = null;
		self::$viewer_loaded = false;
	}

	/** Register the core steps. Runs once when this file loads. */
	public static function registerCoreDefaults(): void {

		self::register('welcome', array(
			'title' => 'Welcome',
			'scope' => 'user',
			'order' => 0,
			'copy'  => "Let's get your Joinery set up. We'll secure your account, connect email and your calendar, and make sure everything is backed up — you can leave at any point and finish later.",
			'render_file' => 'includes/setup_steps/welcome.php',
			'home_url' => '/profile/account',
			'dismiss_line' => 'Your name and timezone are not confirmed.',
			'status' => function (?User $viewer): string {
				if (!$viewer) {
					return SetupSteps::STATUS_NONE;
				}
				$ok = trim((string)$viewer->get('usr_first_name')) !== ''
					&& trim((string)$viewer->get('usr_timezone')) !== '';
				if (SessionControl::get_instance()->get_permission() >= 10) {
					$ok = $ok && trim((string)Globalvars::get_instance()->get_setting('site_name')) !== '';
				}
				return $ok ? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_NONE;
			},
		));

		self::register('signin_security', array(
			'title' => 'Sign-in security',
			'scope' => 'user',
			'order' => 10,
			'copy'  => 'A passkey lets you sign in with your fingerprint or security key, and protects your account even if your password leaks. Add one now — codes from an authenticator app work as the fallback.',
			'render_file' => 'includes/setup_steps/signin_security.php',
			'home_url' => '/profile/security',
			'dismiss_line' => 'Your account has no second factor.',
			'status' => function (?User $viewer): string {
				if (!$viewer) {
					return SetupSteps::STATUS_NONE;
				}
				return SessionControl::get_instance()->user_has_second_factor($viewer)
					? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_NONE;
			},
		));

		// Shared by this step's two predicates: 'real_status' is the vault
		// itself, 'status' additionally accepts an answered "not now".
		$vault_present = function (?User $viewer): string {
			if (!$viewer) {
				return SetupSteps::STATUS_NONE;
			}
			require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
			$vaults = new MultiUserEncryptionVault(array(
				'user_id' => (int)$viewer->key,
				'scope' => UserEncryptionVault::SCOPE_USER,
			));
			return $vaults->count_all() > 0 ? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_NONE;
		};

		self::register('encryption_key', array(
			'title' => 'Your personal encryption key',
			'scope' => 'user',
			'order' => 20,
			'copy'  => "Every account gets one: a key held only by you, which locks anything you choose to keep private (it is not the site's backup key). Creating it now changes nothing about how you use the site — it simply sits there until you want one of the things below.",
			'render_file' => 'includes/setup_steps/encryption_key.php',
			'home_url' => '/profile/security',
			'dismiss_line' => 'You have no personal encryption key — private mail, files, passwords and encrypted chats are unavailable.',
			'decision' => 'user',
			'active' => function (?User $viewer): bool {
				return (string)Globalvars::get_instance()->get_setting('passkeys_enabled') === '1';
			},
			'real_status' => $vault_present,
			// Real state wins: creating the key later makes the decision row
			// irrelevant, exactly as it does for the other optional steps.
			'status' => function (?User $viewer) use ($vault_present): string {
				if (!$viewer) {
					return SetupSteps::STATUS_NONE;
				}
				if (call_user_func($vault_present, $viewer) === SetupSteps::STATUS_GREEN) {
					return SetupSteps::STATUS_GREEN;
				}
				return SetupSteps::hasDecision('encryption_key', (int)$viewer->key)
					? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_NONE;
			},
		));

		self::register('mail_send', array(
			'title' => 'Sending email',
			'scope' => 'site',
			'order' => 30,
			'copy'  => "Your site needs a way to send mail — receipts, reminders, sign-in codes. Pick a provider and we'll check it actually works before moving on.",
			'render_file' => 'includes/setup_steps/mail_send.php',
			'home_url' => '/admin/admin_settings_email',
			'dismiss_line' => 'This site cannot send email yet.',
			'status' => function (?User $viewer): string {
				require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
				if (EmailSender::transactionalSendBlocker() !== null) {
					return SetupSteps::STATUS_NONE;
				}
				$last = (string)Globalvars::get_instance()->get_setting('email_test_send_last_success');
				return $last !== '' ? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_AMBER;
			},
		));

		self::register('calendar', array(
			'title' => 'Calendar',
			'scope' => 'user',
			'order' => 60,
			'copy'  => 'Your calendar is ready now. If you have an existing one, export it as an .ics file and drop it here; we can also email you reminders and a daily or weekly summary.',
			'render_file' => 'includes/setup_steps/calendar.php',
			'home_url' => '/profile/calendar_settings',
			'dismiss_line' => 'Calendar reminders are not decided.',
			'status' => function (?User $viewer): string {
				if (!$viewer) {
					return SetupSteps::STATUS_NONE;
				}
				require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
				$prefs = new MultiCalendarPreference(array('user_id' => (int)$viewer->key));
				if ($prefs->count_all() > 0) {
					return SetupSteps::STATUS_GREEN;
				}
				require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
				$entries = new MultiCalendarEntry(array('subject_type' => 'user', 'subject_id' => (int)$viewer->key));
				return $entries->count_all() > 0 ? SetupSteps::STATUS_GREEN : SetupSteps::STATUS_NONE;
			},
		));

		self::register('backups', array(
			'title' => 'Backups',
			'scope' => 'site',
			'order' => 80,
			'copy'  => 'Everything here should survive this server dying. Point backups at a storage bucket, and create the recovery key that encrypts them — shown once, held only by you.',
			'render_file' => 'includes/setup_steps/backups.php',
			'home_url' => '/admin/admin_backups',
			'dismiss_line' => 'No backups are running.',
			'status' => function (?User $viewer): string {
				$target_ok = (int)Globalvars::get_instance()->get_setting('backup_target_id') > 0;
				require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
				$key_ok = BackupRecoveryKey::is_ready();
				require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
				$tasks = new MultiScheduledTask(array('task_class' => 'BackupRun', 'active' => true, 'deleted' => false));
				$task_ok = $tasks->count_all() > 0;
				if ($target_ok && $key_ok && $task_ok) {
					return SetupSteps::STATUS_GREEN;
				}
				return ($target_ok || $key_ok || $task_ok) ? SetupSteps::STATUS_AMBER : SetupSteps::STATUS_NONE;
			},
		));
	}
}

// Register core steps when this file is loaded.
SetupSteps::registerCoreDefaults();
