<?php
/**
 * ForumAttachmentGate — a file attached to a forum post is exactly as visible
 * as the post: a signed-out visitor opens it in a public category, a member in
 * a category they may read, and nobody while the post is held, removed, or in a
 * category they may not read. The reference is the post id.
 *
 * Core's ordinary file serving asks this gate (File::is_viewable).
 *
 * @version 1.0.0
 */

class ForumAttachmentGate implements AccessGateProvider {

	const KEY = 'forum_post';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'Forum post';
	}

	public function options(): array {
		return array();
	}

	public function userMayAccess(int $user_id, int $ref): bool {
		if ($ref <= 0 || !ForumPost::check_if_exists($ref)) {
			return false;
		}
		try {
			return Forum::mayReadPost(new ForumPost($ref, TRUE), max(0, $user_id));
		} catch (Exception $e) {
			return false;
		}
	}
}
