<?php
/**
 * SupportWidget — the support desk for visitors with no site yet, on this
 * node's public pages (specs/support_chatbot.md, Pre-install: the marketing
 * widget).
 *
 * Same engine, second entry point: a thread of surface `widget`, no bundle, no
 * node group, the newest indexed release's documents plus the public pages.
 * The visitor's browser session holds the thread's token; the thread records
 * a hash of the session and the address. The first question of a session
 * passes the platform captcha (when one is configured), and a per-address
 * daily budget bounds the rest (SupportBudget).
 *
 * A signed-in member's thread is also theirs (sth_account_user_id): it opens
 * in any browser they are signed in to, they can hand it to a person, and a
 * person's reply is emailed to them with a link back to it
 * (specs/hosting_after_purchase.md H1).
 *
 * @version 0.3.0 - what this node records about a signed-in member reaches the assistant and the hand-off
 *                  (registerMemberFacts), with the page they asked from
 * @version 0.2.0 - a signed-in member's threads are theirs: open anywhere they are signed in, handed to a person
 *                  (escalate), listed (mine)
 * @version 0.1.0
 */
class SupportWidget {

	const SESSION_KEY = 'support_desk_widget';

	/** @var callable[] What this node records about a member (registerMemberFacts). */
	private static $member_facts = array();

	/**
	 * A plugin says what this node records about a signed-in member, for the
	 * assistant and for whoever a thread is handed to: fn(int $user_id,
	 * string $route): string[], one plain line each (a hosting plugin: their
	 * sites and each one's state). $route is the page the member asked from,
	 * so the provider can mark what they were looking at. Registered from the
	 * plugin's bootstrap.
	 */
	public static function registerMemberFacts(callable $fn): void {
		self::$member_facts[] = $fn;
	}

	/** Every registered provider's lines about this member; none for a guest. */
	public static function memberFacts(int $user_id, string $route = ''): array {
		if ($user_id <= 0) {
			return array();
		}
		PluginBootstraps::load();
		$lines = array();
		foreach (self::$member_facts as $fn) {
			try {
				foreach ((array)$fn($user_id, $route) as $line) {
					$line = trim(preg_replace('/\s+/', ' ', (string)$line));
					if ($line !== '') {
						$lines[] = mb_substr($line, 0, 300);
					}
				}
			} catch (\Throwable $e) {
				error_log('[support_desk] a member-facts provider failed: ' . $e->getMessage());
			}
		}
		return array_slice($lines, 0, 30);
	}

	public static function enabled(): bool {
		return (string)Globalvars::get_instance()->get_setting('support_desk_widget_enabled', true, true) === '1';
	}

	/** The threads this browser session holds: thread id => token. */
	private static function held(): array {
		$held = $_SESSION[self::SESSION_KEY] ?? array();
		return is_array($held) ? $held : array();
	}

	private static function visitor(): string {
		return hash('sha256', 'support-widget|' . session_id());
	}

	/** A question from the widget. @throws SupportRefusal */
	public static function ask(array $input): array {
		$message = trim((string)($input['message'] ?? ''));
		if ($message === '') {
			throw new SupportRefusal('Ask a question.', 'empty');
		}
		if (mb_strlen($message) > SupportIntake::MESSAGE_MAX) {
			throw new SupportRefusal('Keep a question under ' . number_format(SupportIntake::MESSAGE_MAX) . ' characters.', 'too_long');
		}
		$ip = SupportApi::ip();
		$held = self::held();
		$thread_id = (int)($input['thread_id'] ?? 0);
		$token = '';
		if ($thread_id > 0) {
			$thread = self::open($thread_id);
		} else {
			if (empty($_SESSION['support_desk_widget_human'])) {
				$fw = new FormWriterV2HTML5('support_widget');
				if (!$fw->captcha_check($input)) {
					throw new SupportRefusal('Please confirm you are not a robot.', 'captcha');
				}
				$_SESSION['support_desk_widget_human'] = 1;
			}
			// Refused before the thread is made, so a refusal leaves none behind.
			SupportIntake::assertCanAnswer();
			$refusal = SupportBudget::refusalFor(SupportThread::SURFACE_WIDGET, null, $ip);
			if ($refusal !== null) {
				throw new SupportRefusal($refusal, 'budget', 429);
			}
			list($thread, $token) = SupportIntake::createThread(SupportThread::SURFACE_WIDGET, array(
				'ip' => $ip, 'title' => $message, 'visitor' => self::visitor(),
				'account_user_id' => self::member(),
			));
			// The page a signed-in member asked from (a link that carried it).
			// Only a path in the form the engine reads is kept.
			$route = trim((string)($input['route'] ?? ''));
			if (self::member() > 0 && $route !== '' && SupportTurn::route($route) !== '') {
				SystemBase::rule_checked_write(function () use ($thread, $route) {
					SupportThread::updateColumns((int)$thread->key, array('sth_route' => $route));
				});
				$thread->set_as_stored('sth_route', $route);
			}
			$held[(int)$thread->key] = $token;
			$_SESSION[self::SESSION_KEY] = array_slice($held, -20, null, true);
		}
		$out = SupportIntake::addQuestion($thread, $message);
		unset($out['token']);
		return $out;
	}

	/** The signed-in member asking, or 0 for a guest. */
	public static function member(): int {
		try {
			return (int)SessionControl::get_instance()->get_user_id();
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/** One of this session's threads, or the signed-in member's own. @throws SupportRefusal */
	public static function open(int $thread_id): SupportThread {
		$held = self::held();
		$thread = SupportIntake::load($thread_id);
		$member = self::member();
		$mine = $thread !== null && $member > 0 && (int)$thread->get('sth_account_user_id') === $member;
		if ($thread === null || $thread->get('sth_delete_time') || (string)$thread->get('sth_surface') !== SupportThread::SURFACE_WIDGET
				|| (!$mine && (!isset($held[$thread_id]) || !$thread->tokenOpens((string)$held[$thread_id])))) {
			throw new SupportRefusal('That conversation is not open in this browser.', 'no_thread', 404);
		}
		return $thread;
	}

	/**
	 * Hand a signed-in member's thread to a person (H1). A guest has no
	 * address for the reply, so is told where to write instead.
	 * @throws SupportRefusal
	 */
	public static function escalate(int $thread_id): array {
		if (self::member() <= 0) {
			throw new SupportRefusal('Sign in to hand this to a person, or write to ' . self::contact_email() . '.', 'sign_in', 403);
		}
		$thread = self::open($thread_id);
		// A question asked before signing in, still held by this browser, is
		// given to the member who now hands it on (B36).
		if ((int)$thread->get('sth_account_user_id') === 0) {
			$member = self::member();
			SystemBase::rule_checked_write(function () use ($thread, $member) {
				SupportThread::updateColumns((int)$thread->key, array('sth_account_user_id' => $member));
			});
			$thread->set_as_stored('sth_account_user_id', $member);
		}
		if ((int)$thread->get('sth_account_user_id') !== self::member()) {
			throw new SupportRefusal('That conversation is not yours.', 'no_thread', 404);
		}
		// Ownership is proven above (open(), then the account check).
		SystemBase::rule_checked_write(function () use ($thread) {
			SupportNotify::escalate($thread, 'The member asked for a person.');
		});
		return array('thread_id' => (int)$thread->key, 'state' => SupportThread::STATE_WAITING);
	}

	/**
	 * The member opened their conversation on the page: a person's reply is
	 * read (B35).
	 */
	public static function mark_read(SupportThread $thread): void {
		if (!$thread->get('sth_unread_reply') || (int)$thread->get('sth_account_user_id') !== self::member()) {
			return;
		}
		SystemBase::rule_checked_write(function () use ($thread) {
			SupportThread::updateColumns((int)$thread->key, array('sth_unread_reply' => false));
		});
	}

	/** The signed-in member's widget threads, newest first. */
	public static function mine(int $limit = 10): array {
		$member = self::member();
		if ($member <= 0) {
			return array();
		}
		$out = array();
		foreach (new MultiSupportThread(array('sth_account_user_id' => $member, 'sth_surface' => SupportThread::SURFACE_WIDGET,
				'deleted' => false), array('sth_last_activity_time' => 'DESC'), $limit) as $t) {
			$out[] = $t;
		}
		return $out;
	}

	/** Where a person can be written to: the desk's contact address, else the site's own From address. */
	public static function contact_email(): string {
		$settings = Globalvars::get_instance();
		foreach (array('support_desk_contact_email', 'defaultemail') as $name) {
			$value = trim((string)$settings->get_setting($name, false, true));
			if ($value !== '') {
				return $value;
			}
		}
		return '';
	}

	/** A thread's messages drawn as the core transcript draws them, for the page. */
	public static function transcriptHtml(SupportThread $thread, string $tz): string {
		$out = '';
		foreach (SupportIntake::transcript($thread) as $m) {
			$time = LibraryFunctions::convert_time((string)($m['time'] ?? ''), 'UTC', $tz, 'M j, g:i A');
			if (($m['role'] ?? '') === 'user') {
				$out .= ChatTranscript::userBubble((string)$m['content'], $time, array('message_id' => (int)$m['id']));
			} elseif (($m['status'] ?? '') === 'failed') {
				$out .= ChatTranscript::failedBubble((string)($m['error'] ?? ''), (int)$m['id']);
			} elseif (($m['status'] ?? '') !== 'running') {
				$out .= ChatTranscript::assistantBubble((string)$m['content'], $time, array('message_id' => (int)$m['id'],
					'by_person' => ($m['author'] ?? '') === 'person'));
			}
		}
		return $out;
	}

	/** A turn's state, with the finished answer drawn by the core transcript. */
	public static function poll(int $thread_id, int $message_id): array {
		$thread = self::open($thread_id);
		$out = SupportIntake::poll($thread, $message_id);
		if (isset($out['message'])) {
			$m = $out['message'];
			$out['html'] = $m['status'] === 'failed'
				? ChatTranscript::failedBubble((string)$m['error'], (int)$m['id'])
				: ChatTranscript::assistantBubble((string)$m['content'], gmdate('g:i A') . ' UTC', array('message_id' => (int)$m['id'],
					'cancelled' => $m['status'] === 'cancelled', 'by_person' => $m['author'] === 'person'));
			unset($out['message']);
		}
		return $out;
	}
}
