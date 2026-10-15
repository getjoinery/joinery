<?php
/**
 * SupportDesk — the plugin's few shared facts: the reserved user every
 * transcript belongs to, the shipped voice, and the settings read as numbers.
 *
 * The reserved user is made by the plugin's sync, found by the id the managed
 * setting holds (never by address), and cannot sign in: it is disabled, has no
 * password anyone knows, and password recovery is off. It owns every support
 * transcript, so no person's own chat list ever shows one.
 *
 * @version 0.1.0
 */
class SupportDesk {

	const SYSTEM_USER_SETTING = 'support_desk_system_user_id';

	/** The shipped voice block (specs/support_chatbot.md, The prompt, in three layers). */
	const DEFAULT_VOICE =
		"You help people install and run Joinery. The person is an administrator of one site, on the release named below. "
		. "Answer from the documentation and the FAQ for that release, and name the document and heading you drew on. "
		. "When the question is about their site and you have tools that read it, read before you guess, and say what you read. "
		. "You never change anything on a site and never say you did. "
		. "If the documents and the reads do not settle the question, say so plainly and offer to hand the thread to a person. "
		. "Keep answers short, in the person's terms, and lead with what to do.";

	/** The voice for a visitor with no site yet (the public widget). */
	const WIDGET_VOICE =
		"You help people decide whether Joinery fits them and how to install it. The person has no Joinery site yet. "
		. "Answer from the documentation and the public pages, and name the document or page you drew on. "
		. "If they do not settle the question, say so plainly. Keep answers short, in the person's terms.";

	/** The reserved user's id, or 0 when sync has not made it yet. */
	public static function systemUserId(): int {
		return (int)Globalvars::get_instance()->get_setting(self::SYSTEM_USER_SETTING, true, true);
	}

	/**
	 * Make the reserved user when there is none (or the one recorded is gone).
	 * Idempotent; returns a sync-report line when it made one, '' otherwise.
	 */
	public static function ensureSystemUser(): string {
		$id = self::systemUserId();
		if ($id > 0) {
			$user = new User($id);
			if ($user->load() !== false && !$user->get('usr_delete_time')) {
				return '';
			}
		}
		$host = preg_replace('/[^a-z0-9.\-]/', '', strtolower(ServicesClient::host())) ?: 'localhost.invalid';
		$user = new User(NULL);
		$user->set('usr_first_name', 'Support');
		$user->set('usr_last_name', 'Desk');
		$user->set('usr_email', 'support-desk-' . bin2hex(random_bytes(6)) . '@' . $host);
		$user->set('usr_password', User::GeneratePassword(bin2hex(random_bytes(24))));
		$user->set('usr_permission', 0);
		$user->set('usr_is_disabled', true);
		$user->set('usr_disabled_time', gmdate('Y-m-d H:i:s'));
		$user->set('usr_password_recovery_disabled', true);
		$user->set('usr_email_is_verified', true);
		$user->save();
		$user->load();
		Setting::put(self::SYSTEM_USER_SETTING, (string)$user->key);
		Globalvars::get_instance()->forget_setting(self::SYSTEM_USER_SETTING);
		return 'Made the support desk\'s reserved user (#' . (int)$user->key . ').';
	}

	/** The voice block: the operator's, else the shipped one. */
	public static function voice(): string {
		$voice = trim((string)Globalvars::get_instance()->get_setting('support_desk_system_prompt', true, true));
		return $voice !== '' ? $voice : self::DEFAULT_VOICE;
	}

	public static function intSetting(string $name, int $default): int {
		$v = Globalvars::get_instance()->get_setting($name, true, true);
		return ($v === null || $v === '') ? $default : (int)$v;
	}

	/** This node's own base URL, for links in emails. */
	public static function baseUrl(): string {
		$base = rtrim((string)Globalvars::get_instance()->get_setting('webDir', true, true), '/');
		if ($base === '') {
			return '';
		}
		return preg_match('#^https?://#', $base) ? $base : 'https://' . $base;
	}
}
