<?php
/**
 * ForumSearch — full-text search over posts and topic titles, filtered to what
 * the searcher may read.
 *
 * The access filter is SpaceAccess::readableSql() over the category, in the
 * same query as the match, so nothing the searcher may not read is ever
 * loaded. Held posts and topics are left out for everyone but moderators.
 * Results are best match first (a forum post is long and lasting, so relevance
 * carries information), or newest first on request; a title match counts for
 * the topic's opening post.
 *
 * @version 1.0.0
 */

class ForumSearch {

	const PAGE_SIZE = 20;

	/**
	 * @return array{results:array[],total:int,page:int,pages:int}
	 */
	public static function search(string $query, int $viewer_id, ?int $category_id = null, string $sort = 'best', int $page = 1): array {
		$empty = array('results' => array(), 'total' => 0, 'page' => 1, 'pages' => 1);
		if (!FullTextSearch::isSearchable($query)) {
			return $empty;
		}
		$moderator = Forum::isModerator($viewer_id);

		list($body_match, $body_params) = FullTextSearch::match('fpo.fpo_body', $query);
		list($title_match, $title_params) = FullTextSearch::match('ftp.ftp_title', $query);
		list($body_rank, $body_rank_params) = FullTextSearch::rank('fpo.fpo_body', $query);
		list($title_rank, $title_rank_params) = FullTextSearch::rank('ftp.ftp_title', $query);

		$where = 'fpo.fpo_delete_time IS NULL AND ftp.ftp_delete_time IS NULL AND fcg.fcg_delete_time IS NULL'
			. ' AND ' . SpaceAccess::readableSql('ForumCategory', 'fcg', $viewer_id)
			. ' AND (' . $body_match . ' OR (fpo.fpo_is_first AND ' . $title_match . '))';
		$params = array_merge($body_params, $title_params);
		if (!$moderator) {
			$where .= ' AND fpo.fpo_is_approved = TRUE AND ftp.ftp_is_approved = TRUE';
		}
		if ($category_id) {
			$where .= ' AND fcg.fcg_forum_category_id = ?';
			$params[] = (int)$category_id;
		}
		$from = ' FROM fpo_forum_posts fpo
			JOIN ftp_forum_topics ftp ON ftp.ftp_forum_topic_id = fpo.fpo_ftp_forum_topic_id
			JOIN fcg_forum_categories fcg ON fcg.fcg_forum_category_id = ftp.ftp_fcg_forum_category_id
			WHERE ' . $where;

		$count = DbConnector::GetPreparedStatement('SELECT COUNT(*)' . $from);
		$count->execute($params);
		$total = (int)$count->fetchColumn();
		$pages = max(1, (int)ceil($total / self::PAGE_SIZE));
		$page = min(max(1, $page), $pages);

		$order = $sort === 'newest'
			? 'fpo.fpo_create_time DESC, fpo.fpo_forum_post_id DESC'
			: 'score DESC, fpo.fpo_forum_post_id DESC';
		$sql = 'SELECT fpo.fpo_forum_post_id, fpo.fpo_body, fpo.fpo_usr_user_id, fpo.fpo_create_time, fpo.fpo_is_first,
				ftp.ftp_forum_topic_id, ftp.ftp_title, ftp.ftp_slug, fcg.fcg_name, fcg.fcg_slug,
				(' . $body_rank . ' + CASE WHEN fpo.fpo_is_first THEN 2 * ' . $title_rank . ' ELSE 0 END) AS score'
			. $from . ' ORDER BY ' . $order . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE);
		$q = DbConnector::GetPreparedStatement($sql);
		$q->execute(array_merge($body_rank_params, $title_rank_params, $params));

		$results = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			// The link names the post; the topic page finds its page for the reader.
			$url = '/forum/t/' . (int)$row['ftp_forum_topic_id'] . '/' . rawurlencode((string)$row['ftp_slug'])
				. '?post=' . (int)$row['fpo_forum_post_id'];
			$results[] = array(
				'post_id'       => (int)$row['fpo_forum_post_id'],
				'topic_title'   => (string)$row['ftp_title'],
				'is_first'      => (bool)$row['fpo_is_first'],
				'category_name' => (string)$row['fcg_name'],
				'author'        => Forum::person($row['fpo_usr_user_id'])['name'],
				'when'          => ForumPages::when($row['fpo_create_time']),
				'snippet_html'  => FullTextSearch::snippet(Forum::plainExcerpt((string)$row['fpo_body'], 2000), $query),
				'url'           => $url,
			);
		}
		return array('results' => $results, 'total' => $total, 'page' => $page, 'pages' => $pages);
	}
}
