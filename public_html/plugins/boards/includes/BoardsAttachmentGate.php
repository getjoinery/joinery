<?php
/**
 * BoardsAttachmentGate — who may open a file attached to a card.
 *
 * An attachment is exactly as visible as the board it sits on: anyone with a
 * role on the board can open it, nobody else can. Rather than a download
 * endpoint of its own, an attachment File carries the platform's content access
 * gate (fil_access_provider / fil_access_ref pointing at the board), and this
 * provider answers the question core asks on every serve. That buys the ordinary
 * /uploads/ URL, thumbnails, range requests and cloud offload without a second
 * serving path to keep honest.
 *
 * Registered from the plugin's serve.php, so it is live on every request while
 * the plugin is active. Deactivating the plugin unregisters it and the gate fails
 * closed — an attachment stops being readable rather than becoming public.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));

class BoardsAttachmentGate implements AccessGateProvider {

	/** The value stored in fil_access_provider. */
	const KEY = 'boards_board';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'Board';
	}

	/**
	 * No admin picker: nobody gates a file behind a board by hand, and listing
	 * every private board's name in a dropdown would be its own disclosure.
	 */
	public function options(): array {
		return array();
	}

	public function userMayAccess(int $user_id, int $ref): bool {
		if ($ref <= 0 || $user_id <= 0) {
			return false;
		}
		try {
			if (!Board::check_if_exists($ref)) {
				return false;
			}
			$board = new Board($ref, TRUE);
		} catch (Exception $e) {
			return false;
		}
		return Boards::roleFor($board, $user_id) !== null;
	}
}
