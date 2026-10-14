<?php
/**
 * ForumReportHandler — what the report queue knows about a forum post.
 *
 * A member may report a post they can read (not their own). An admin acting on
 * a report can remove the post (or the whole topic, when it is the opening
 * post) or lock its topic. Registered from the plugin bootstrap.
 *
 * @version 1.0.0
 */

class ForumReportHandler implements ContentReportHandler {

	public function entityType(): string {
		return ForumPost::ENTITY_TYPE;
	}

	public function label(): string {
		return 'Forum post';
	}

	public function mayReport(int $entity_id, int $user_id): bool {
		$post = $this->post($entity_id);
		return $post && $user_id > 0 && (int)$post->get('fpo_usr_user_id') !== $user_id && Forum::mayReadPost($post, $user_id);
	}

	public function preview(int $entity_id): ?array {
		$post = $this->post($entity_id);
		if (!$post || $post->get('fpo_delete_time')) {
			return null;
		}
		$topic = $post->topic();
		return array(
			'title'          => (string)$topic->get('ftp_title'),
			'excerpt'        => Forum::plainExcerpt((string)$post->get('fpo_body'), 400),
			'author_user_id' => (int)$post->get('fpo_usr_user_id') ?: null,
			'url'            => ForumPages::postUrl($topic, $post),
		);
	}

	public function actions(int $entity_id): array {
		$post = $this->post($entity_id);
		if (!$post || $post->get('fpo_delete_time')) {
			return array();
		}
		$actions = array('delete' => $post->get('fpo_is_first') ? 'Remove topic' : 'Remove post');
		if (!$post->topic()->get('ftp_is_locked')) {
			$actions['lock'] = 'Lock topic';
		}
		return $actions;
	}

	public function act(int $entity_id, string $action, int $admin_user_id): string {
		$post = $this->post($entity_id);
		if (!$post) {
			throw new ForumRefusal('That post is gone.');
		}
		$topic = $post->topic();
		$category = $topic->category();
		switch ($action) {
			case 'delete':
				$what = ForumPosting::deletePost($post, $topic, $category, $admin_user_id);
				return $what === 'topic' ? 'Removed the topic.' : 'Removed the post.';
			case 'lock':
				ForumPosting::setTopic($topic, $category, $admin_user_id, array('is_locked' => true));
				return 'Locked the topic.';
		}
		throw new ForumRefusal('Unknown action.');
	}

	/**
	 * The post, while it and its topic and category are live — a post inside a
	 * removed topic is gone as far as the queue is concerned.
	 */
	private function post(int $id): ?ForumPost {
		if ($id <= 0 || !ForumPost::check_if_exists($id)) {
			return null;
		}
		$post = new ForumPost($id, TRUE);
		if ($post->get('fpo_delete_time') || !ForumTopic::check_if_exists($post->topicId())) {
			return null;
		}
		$topic = $post->topic();
		if ($topic->get('ftp_delete_time') || Forum::liveCategory($topic->categoryId()) === null) {
			return null;
		}
		return $post;
	}
}
