<?php
/**
 * PrivateContentConsent — where a member's Private content may be read by AI.
 *
 * One member-level answer, the most permissive endpoint trust class their
 * Private content may reach: local (hardware the operator controls), trusted
 * (a vendor the operator accepted terms with), or cloud (any configured
 * endpoint). The same three words a mail domain's AI consent uses and an
 * endpoint declares, so every gate is a comparison rather than a translation.
 *
 * It binds every chat that holds Private content (a Private chat, or one whose
 * transcript became sealed-derived): AiModelRequirementBuilder::forConversation()
 * tightens the turn's trust floor to it, and chat_send refuses a model outside
 * it before anything is persisted, in words that name the way out. It starts at
 * local — private content never travels until the member says so — and
 * loosening it asks for a recent second factor.
 *
 * Mail's per-domain consent (ied_ai_processing_consent) stays what governs
 * pipeline recipes over mail; this is the member's own answer for everything
 * else, and for any transcript that has opened sealed content.
 *
 * @version 1.0
 */
class PrivateContentConsent {

	const LOCAL   = 'local';
	const TRUSTED = 'trusted';
	const CLOUD   = 'cloud';

	/** Least to most permissive. */
	const CONSENTS = array(self::LOCAL, self::TRUSTED, self::CLOUD);

	/** The users column holding the answer. */
	const COLUMN = 'usr_private_ai_consent';

	/** The trust floor a model requirement states for each answer. */
	const TRUST_FLOORS = array(self::LOCAL => 'local', self::TRUSTED => 'trusted', self::CLOUD => 'any');

	/** @var array<int,string> request-scoped */
	private static $cache = array();

	/** A stored or submitted value as one of CONSENTS; anything else reads as local. */
	public static function normalize($value): string {
		$value = strtolower(trim((string)$value));
		return in_array($value, self::CONSENTS, true) ? $value : self::LOCAL;
	}

	/** The member's answer, memoized per request. */
	public static function forUser(int $user_id): string {
		if ($user_id <= 0) {
			return self::LOCAL;
		}
		if (!array_key_exists($user_id, self::$cache)) {
			$user = new User($user_id, TRUE);
			self::$cache[$user_id] = $user->key ? self::normalize($user->get(self::COLUMN)) : self::LOCAL;
		}
		return self::$cache[$user_id];
	}

	/** Record the member's answer (a targeted write, never a full user save). */
	public static function set(int $user_id, string $consent): void {
		$consent = self::normalize($consent);
		User::updateColumns($user_id, array(self::COLUMN => $consent));
		self::$cache[$user_id] = $consent;
	}

	public static function forget(): void {
		self::$cache = array();
	}

	/** Position in CONSENTS; an endpoint class nothing declares ranks as cloud. */
	public static function rank($value): int {
		$idx = array_search(strtolower(trim((string)$value)), self::CONSENTS, true);
		return $idx === false ? count(self::CONSENTS) - 1 : (int)$idx;
	}

	/** May content under $consent reach a model on a $model_trust endpoint? */
	public static function allows(string $consent, ?string $model_trust): bool {
		return self::rank($model_trust ?? self::CLOUD) <= self::rank(self::normalize($consent));
	}

	/** Is a change from $from to $to a loosening (needs a fresh identity check)? */
	public static function isLoosening(string $from, string $to): bool {
		return self::rank(self::normalize($to)) > self::rank(self::normalize($from));
	}

	/** The trust floor a model requirement states for $consent. */
	public static function trustFloor(string $consent): string {
		return self::TRUST_FLOORS[self::normalize($consent)];
	}

	/** The choices, in the words the mail domain editor uses for the same question. */
	public static function options(): array {
		return array(
			self::LOCAL   => 'Stay on my hardware (default)',
			self::TRUSTED => 'My hardware, or a vendor I have accepted',
			self::CLOUD   => 'Any configured AI endpoint, including cloud',
		);
	}

	/** Where content may go under $consent, as a phrase for a refusal. */
	public static function where(string $consent): string {
		switch (self::normalize($consent)) {
			case self::CLOUD:   return 'anywhere, including cloud AI';
			case self::TRUSTED: return 'on your hardware or with a vendor you have accepted';
			default:            return 'on your hardware';
		}
	}
}
?>
