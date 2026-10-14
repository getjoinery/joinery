<?php
/**
 * ContentReportHandler — what a feature tells the report queue about one kind
 * of content it lets members report.
 *
 * A feature (the forum, chat) implements one per entity type and registers it
 * with ContentReportRegistry from its plugin bootstrap. The queue never learns
 * what a forum post is: it asks the handler whether a person may report one,
 * what it looks like, and what an admin may do to it.
 *
 * See docs/social_features.md § Content reports.
 *
 * @version 1.0.0
 */
interface ContentReportHandler {

	/** The entity type reports name, e.g. 'forum_post'. Unique across handlers. */
	public function entityType(): string;

	/** What the queue calls one of these, e.g. 'Forum post'. */
	public function label(): string;

	/** May this person report this content? True only when they can see it and it is live. */
	public function mayReport(int $entity_id, int $user_id): bool;

	/**
	 * What the queue shows, or null when the content is gone.
	 *
	 * @return array{title:string, excerpt:string, author_user_id:?int, url:string}|null
	 *   excerpt is plain text; url is a same-site path to the content in context
	 */
	public function preview(int $entity_id): ?array;

	/**
	 * What an admin may do to this content, as action key => button label,
	 * e.g. ['hide' => 'Hide post', 'delete' => 'Delete post', 'lock' => 'Lock topic'].
	 * Dismissing a report is the queue's own and is never listed here.
	 *
	 * @return array<string,string>
	 */
	public function actions(int $entity_id): array;

	/**
	 * Do one of actions() to the content. Returns a short past-tense message
	 * for the admin ("Hid the post"). Throws with a readable message to refuse.
	 */
	public function act(int $entity_id, string $action, int $admin_user_id): string;
}
?>
