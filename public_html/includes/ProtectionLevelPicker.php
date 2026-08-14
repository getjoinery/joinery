<?php
/**
 * ProtectionLevelPicker — the one place a member is asked how protected
 * something should be.
 *
 * `ProtectionLevel` owns the ladder and its spelling. This owns the *promise*:
 * the words a member reads when choosing a rung. They live here rather than in
 * each service's page because a member who has read "Private" once on their
 * mail should not have to work out whether "Private" means something different
 * on a conversation — and because a promise duplicated across pages is a
 * promise that drifts.
 *
 * A consumer declares which rungs it offers and, when its flavour of a rung
 * needs different words, which service copy to use. A service that has no
 * flavour of its own gets the default wording, which is written to be true
 * everywhere.
 *
 * Rendering goes through FormWriter's card radio, so the control carries the
 * platform's validation styling, CSRF handling and markup like any other field:
 *
 *   ProtectionLevelPicker::render($formwriter, 'protection_level', [
 *       'service' => ProtectionLevelPicker::SERVICE_MESSAGING,
 *       'levels'  => Conversation::LEVELS,
 *       'value'   => $conversation->protection_level(),
 *   ]);
 *
 * @version 1.0.0
 */


class ProtectionLevelPicker {

	/** Copy flavours. A service not listed here reads the default wording. */
	const SERVICE_DEFAULT   = 'default';
	const SERVICE_MESSAGING = 'messaging';

	/**
	 * Three lines per rung, always in the same order and always answering the
	 * same three questions:
	 *
	 *   1. What does this actually do?
	 *   2. When would I pick it?
	 *   3. What does it cost me?
	 *
	 * The third line is not marketing's to remove. A member choosing protection
	 * is choosing a trade, and a card that hides the trade is how someone locks
	 * themselves out of their own content.
	 */
	protected static function catalog(): array {
		return array(
			self::SERVICE_DEFAULT => array(
				ProtectionLevel::STANDARD => array(
					'The server manages this for you.',
					'Best for everyday things where convenience matters more than secrecy.',
					'Nothing extra to set up. Stored content is not protected at rest.',
				),
				ProtectionLevel::PRIVATE_ => array(
					'Encrypted at rest — a stolen disk or a database dump yields nothing readable.',
					'Best for content worth keeping private, where search and automation must keep working.',
					'You unlock to read it. Lose every way in and the content is gone for good.',
				),
				ProtectionLevel::GUARDED => array(
					'Private, plus the doors guarded: nothing about the content leaves by any other route.',
					'Best for the content you would not want summarized in a notification or sent to a cloud model.',
					'Same unlocking as Private, and some conveniences are switched off on purpose.',
				),
				ProtectionLevel::FORTRESS => array(
					'Only you hold the key — the server never sees the content at all.',
					'Best for the things that are you: identity, money, the irreplaceable.',
					'Nothing on the server can read it, so search, previews and automation stop working.',
				),
			),
			self::SERVICE_MESSAGING => array(
				ProtectionLevel::STANDARD => array(
					'The server manages this conversation for you.',
					'Best for ordinary chat — plans, logistics, anything you would say in a room.',
					'Nothing to set up. Messages are stored as written.',
				),
				ProtectionLevel::PRIVATE_ => array(
					'Messages and attachments are encrypted at rest, readable only while someone in the conversation is here.',
					'Best for a conversation worth protecting that should still feel like a normal chat.',
					'Everyone in it needs protection set up first, and this cannot be undone later.',
				),
				ProtectionLevel::GUARDED => array(
					'Private, and no message content leaves the conversation — not in notifications, not to a cloud model.',
					'Best for the conversation you would not want previewed on a lock screen.',
					'Notifications say only that there is something new. This cannot be undone later.',
				),
			),
		);
	}

	/**
	 * The three lines for one rung of one service.
	 *
	 * Falls back to the default flavour a line at a time, so a service that
	 * needs its own words for one rung does not have to restate the others.
	 */
	public static function copy(string $level, string $service = self::SERVICE_DEFAULT): array {
		$catalog = self::catalog();
		$level = ProtectionLevel::normalize($level);
		if (isset($catalog[$service][$level])) {
			return $catalog[$service][$level];
		}
		return $catalog[self::SERVICE_DEFAULT][$level]
			?? array('', '', '');
	}

	/**
	 * Which rungs to show, in ladder order, filtered to the ones this platform
	 * knows. A consumer passes its own subset; omitting the option offers the
	 * whole ladder, which almost nothing should do.
	 */
	public static function levels(array $requested = null): array {
		$requested = $requested === null ? ProtectionLevel::ORDER : $requested;
		$out = array();
		foreach (ProtectionLevel::ORDER as $level) {
			if (in_array($level, $requested, true)) {
				$out[] = $level;
			}
		}
		return $out;
	}

	/**
	 * Emit the picker.
	 *
	 * @param object $formwriter a FormWriterV2 instance
	 * @param string $field      the field name to submit under
	 * @param array  $options    service, levels, value, label, required,
	 *                           disabled_values, helptext
	 */
	public static function render($formwriter, string $field, array $options = array()): void {
		$service = $options['service'] ?? self::SERVICE_DEFAULT;
		$levels  = self::levels($options['levels'] ?? null);

		$choices = array();
		$descriptions = array();
		foreach ($levels as $level) {
			$choices[$level] = ProtectionLevel::label($level);
			$descriptions[$level] = self::copy($level, $service);
		}

		$field_options = array(
			'card'         => true,
			'options'      => $choices,
			'descriptions' => $descriptions,
			'value'        => ProtectionLevel::normalize($options['value'] ?? ProtectionLevel::STANDARD),
			'required'     => $options['required'] ?? true,
		);
		if (!empty($options['disabled_values'])) {
			$field_options['disabled_values'] = $options['disabled_values'];
		}
		if (!empty($options['helptext'])) {
			$field_options['helptext'] = $options['helptext'];
		}

		$formwriter->radioinput($field, $options['label'] ?? 'Protection', $field_options);
	}
}
