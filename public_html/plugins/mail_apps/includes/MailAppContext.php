<?php
/**
 * MailAppContext - who is asking, about which mailbox, for one mail_apps/*
 * request.
 *
 * A mail program signs in with the mailbox address as its username and an app
 * credential of the `mail` purpose (ApiAuth). Every action names the mailbox
 * again (the gateway is stateless towards the site), and this resolves it:
 * the address to its alias, and a refusal unless the credential's person holds
 * a GRANT on it. An all-access administrator's oversight of every mailbox does
 * not reach a mail program — the viewer is built grant-only.
 *
 * Fortress mailboxes are refused: the server cannot open them
 * (specs/mail_apps_plugin.md § Scope).
 *
 * @version 1.0.0
 */

class MailAppRefusal extends RuntimeException {
	/** @var string IMAP response code (AUTHORIZATIONFAILED, NONEXISTENT, CANNOT, UNAVAILABLE, ...) */
	public $imap_code;
	/** @var string|null SMTP reply ("454 4.7.0") for the submission face */
	public $smtp_code;

	public function __construct(string $message, string $imap_code = 'CANNOT', ?string $smtp_code = null) {
		parent::__construct($message);
		$this->imap_code = $imap_code;
		$this->smtp_code = $smtp_code;
	}

	/** The refusal as an action's error, for the gateway to turn into a protocol reply. */
	public function toLogicResult(): LogicResult {
		return LogicResult::error($this->getMessage(), array(
			'imap_code' => $this->imap_code,
			'smtp_code' => $this->smtp_code,
		));
	}
}

class MailAppContext {

	/** The Mail apps page sentence for a Fortress mailbox. */
	const FORTRESS_REFUSAL = 'Fortress mail can only be opened on your own devices; mail programs are not supported yet.';

	/** @var int */
	public $user_id;
	/** @var InboundEmailAlias */
	public $alias;
	/** @var int */
	public $alias_id;
	/** @var string the mailbox address, lowercase */
	public $address;
	/** @var MailboxViewer */
	public $viewer;
	/** @var MailboxService */
	public $service;
	/** @var bool the mailbox seals its content at rest (Private) */
	public $seals;

	private function __construct() {}

	/**
	 * Resolve the mailbox an action names, for the signed-in person.
	 *
	 * @throws MailAppRefusal
	 */
	public static function forMailbox(string $mailbox): MailAppContext {
		$session = SessionControl::get_instance();
		$user_id = intval($session->get_user_id());
		if ($user_id <= 0) {
			throw new MailAppRefusal('Sign in first.', 'AUTHORIZATIONFAILED');
		}
		$mailbox = strtolower(trim($mailbox));
		$alias = $mailbox !== '' ? InboundEmailAlias::GetByAddress($mailbox) : false;
		// One answer for "no such mailbox" and "not yours": the username is the
		// address, and a sign-in must not tell a stranger which addresses exist.
		$refusal = 'This app password cannot open ' . ($mailbox !== '' ? $mailbox : 'that mailbox') . '.';
		if (!$alias || !$alias->key) {
			throw new MailAppRefusal($refusal, 'AUTHORIZATIONFAILED');
		}
		$granted = array_map('intval', InboundEmailMailboxGrant::alias_ids_for_user($user_id));
		if (!in_array(intval($alias->key), $granted, true)) {
			throw new MailAppRefusal($refusal, 'AUTHORIZATIONFAILED');
		}
		if ($alias->is_fortress()) {
			throw new MailAppRefusal(self::FORTRESS_REFUSAL, 'AUTHORIZATIONFAILED');
		}

		$ctx = new MailAppContext();
		$ctx->user_id = $user_id;
		$ctx->alias = $alias;
		$ctx->alias_id = intval($alias->key);
		$ctx->address = strtolower($alias->get_full_address());
		// Grant-only: permission 0, whatever the person's site role.
		$ctx->viewer = MailboxViewer::forUser($user_id, 0);
		$ctx->service = new MailboxService($ctx->viewer);
		$ctx->seals = (bool)$alias->seals_content();
		return $ctx;
	}

	/**
	 * Can sealed mail be read for this person right now? Their window — for a
	 * mail program, the one it borrowed from their website unlock. A mailbox
	 * that does not seal never needs one.
	 */
	public function windowOpen(): bool {
		if (!$this->seals) {
			return true;
		}
		return VaultUnlock::isOpen($this->user_id);
	}

	/**
	 * A check counts as activity (decision 2 in specs/mail_apps_plugin.md):
	 * keep the borrowed window alive up to its absolute cap. Only for a mailbox
	 * that seals — keeping a key in memory for mail that needs none would be
	 * exposure for nothing — and only on a borrowing credential's request.
	 */
	public function countCheckAsActivity(): void {
		if (!$this->seals || VaultUnlock::credentialWindowKeyId() === null) {
			return;
		}
		try {
			VaultUnlock::touchActivity($this->user_id);
		} catch (RuntimeException $e) {
			error_log('MailAppContext: touchActivity refused: ' . $e->getMessage());
		}
	}

	/** Where the person unlocks: the mail reader, which asks for the passkey. */
	public function unlockUrl(): string {
		return LibraryFunctions::get_absolute_url('/profile/mailbox/mailbox');
	}

	/** The mailbox's protection level, in the words the Mail apps page uses. */
	public function levelName(): string {
		return $this->seals ? 'private' : 'standard';
	}
}
?>
