<?php
/**
 * ForumCache — keeps the static page cache from serving a stale thread.
 *
 * A public category's pages are served to signed-out visitors from the static
 * page cache, before any forum code runs. Every change that alters what such a
 * page shows clears it here: the topic's pages (every ?page= of it), its
 * category's list, and the forum home. A change to who may read a category
 * clears the whole forum, since pages cached while it was public must not
 * outlive the change.
 *
 * Pages of non-public categories never enter the cache — they answer a
 * signed-out visitor with a refusal, which is not cached — so clearing them is
 * harmless and keeps one rule for every change.
 *
 * @version 1.0.0
 */

class ForumCache {

	/** A topic's posts, title, flags or category changed. */
	public static function topicChanged(ForumTopic $topic, ForumCategory $category): void {
		StaticPageCache::invalidatePaths(array($category->url(), '/forum', '/sitemap'), array('/forum/t/' . (int)$topic->key));
	}

	/** A category's list changed (a topic came, went, moved, was pinned). */
	public static function categoryChanged(ForumCategory $category): void {
		StaticPageCache::invalidatePaths(array($category->url(), '/forum', '/sitemap'), array());
	}

	/** Who may read something changed, or a category was renamed: everything. */
	public static function forumChanged(): void {
		StaticPageCache::invalidatePaths(array('/sitemap'), array('/forum'));
	}
}
