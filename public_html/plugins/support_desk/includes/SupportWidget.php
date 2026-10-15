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
 * @version 0.1.0
 */
class SupportWidget {

	const SESSION_KEY = 'support_desk_widget';

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
			));
			$held[(int)$thread->key] = $token;
			$_SESSION[self::SESSION_KEY] = array_slice($held, -20, null, true);
		}
		$out = SupportIntake::addQuestion($thread, $message);
		unset($out['token']);
		return $out;
	}

	/** One of this session's threads. @throws SupportRefusal */
	public static function open(int $thread_id): SupportThread {
		$held = self::held();
		$thread = SupportIntake::load($thread_id);
		if ($thread === null || $thread->get('sth_delete_time') || (string)$thread->get('sth_surface') !== SupportThread::SURFACE_WIDGET
				|| !isset($held[$thread_id]) || !$thread->tokenOpens((string)$held[$thread_id])) {
			throw new SupportRefusal('That conversation is not open in this browser.', 'no_thread', 404);
		}
		return $thread;
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
