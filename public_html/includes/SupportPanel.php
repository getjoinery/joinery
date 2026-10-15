<?php
/**
 * SupportPanel — what the Help panel's actions share on this site: who may
 * use it, what the consent screen shows, and a desk refusal turned into the
 * panel's answer (support_chatbot.md, The site side).
 *
 * @version 1.0.0
 */
class SupportPanel {

	/**
	 * The consent screen: each section of what the first message carries,
	 * with one sentence on what it grants, built for this person exactly as it
	 * would be sent.
	 */
	const GRANTS = array(
		'site'          => 'Which site this is and the release it runs, so the answer fits your release.',
		'request'       => 'The page you asked from and your browser, so the answer can start where you are.',
		'who'           => 'Your user number and permission level, never your name or address.',
		'error'         => 'The error you were looking at, if any.',
		'recent_errors' => 'The last error lines in this site\'s log, with addresses and secrets masked.',
		'runtime'       => 'The versions of PHP, PostgreSQL and the operating system this site runs on.',
		'plugins'       => 'Which plugins are installed and switched on.',
		'settings'      => 'The names of the settings changed from their defaults, never their values.',
		'health'        => 'Free disk and memory, the load, and when the scheduled tasks last ran.',
	);

	/** The live-reads question, naming what it grants. */
	const LIVE_READS_TEXT = 'The assistant may read your site\'s error and task logs, the last rows of its login and request logs, its service journals, '
		. 'its host configuration files, its disk usage and its listening ports, redacted on your server before they leave it; '
		. 'never your members, their mail, their files, or any key. Every read is a job on your site\'s job list.';

	/** Refuse a caller below the panel's floor. */
	public static function guard(): ?LogicResult {
		$session = SessionControl::get_instance();
		if (!$session->is_logged_in()) {
			return LogicResult::error('Sign in required.');
		}
		if ((int)$session->get_permission() < SupportChatClient::ASK_PERMISSION) {
			return LogicResult::error('The Help panel is for this site\'s administrators.');
		}
		if (!SupportChatClient::enabled()) {
			return LogicResult::error('This site has no support desk set (support_url).', array('reason' => 'off'));
		}
		return null;
	}

	public static function fail(SupportChatException $e): LogicResult {
		return LogicResult::error($e->getMessage(), array('reason' => $e->reason));
	}

	/** What the panel needs to draw itself before anything is asked. */
	public static function state(SessionControl $session, string $route): array {
		$consent = SupportChatClient::consent();
		$joined = SupportChatClient::joined();
		$out = array(
			'destination'     => SupportChatClient::destinationHost(),
			'consent'         => $consent,
			'joined'          => $joined,
			'may_live_reads'  => $joined && (int)$session->get_permission() >= SupportChatClient::LIVE_READS_PERMISSION,
			'live_reads_text' => self::LIVE_READS_TEXT,
			'docs_url'        => SupportChatClient::PUBLIC_DOCS_URL,
			'unread'          => SiteSupportThread::unreadCount(),
		);
		if (!$consent['answered']) {
			$out['preview'] = self::preview($session, $route);
		}
		return $out;
	}

	/** The bundle, section by section, as the consent screen shows it. */
	public static function preview(SessionControl $session, string $route): array {
		$bundle = SupportChatClient::bundle($session, SupportChatClient::cleanRoute($route));
		$out = array();
		foreach (ProblemReportBundle::SECTIONS as $key => $heading) {
			if (!array_key_exists($key, $bundle)) {
				continue;
			}
			$out[] = array(
				'heading' => $heading,
				'grants'  => self::GRANTS[$key] ?? '',
				'text'    => json_encode($bundle[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			);
		}
		return $out;
	}
}
