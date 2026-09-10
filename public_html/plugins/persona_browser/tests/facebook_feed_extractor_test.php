<?php
/** @joinery-test
 * name: facebook_feed_extractor
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * FacebookFeedExtractor — the captured markup is read in the parser jail, and
 * one capture is one subprocess (specs/parser_jail.md).
 *
 * Run: php plugins/persona_browser/tests/facebook_feed_extractor_test.php
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/persona_browser/includes/FacebookFeedExtractor.php'));

$post = '<div><h3><a href="https://www.facebook.com/ada.lovelace">Ada Lovelace</a></h3>'
	. '<div dir="auto">The engine ran its first program today, and the cards fed through without a jam at all.</div>'
	. '<a href="https://www.facebook.com/ada.lovelace/posts/pfbid0ABC123">2h</a>'
	. '<img src="https://scontent.example/photo.jpg" data-nw="800" alt="A large brass engine in a workshop">'
	. '<div role="button" aria-label="Like"><span><span>1.2K</span></span></div>'
	. '<div role="button" aria-label="Leave a comment"><span><span>17</span></span></div>'
	. '<span>Shared with Public</span></div>';
$tray = '<div>' . implode('', array_map(function ($i) {
	return '<a href="/stories/10000' . $i . '/"><img src="https://scontent.example/s' . $i . '.jpg" data-nw="300"><span>Person ' . $i . '</span></a>';
}, array(1, 2, 3))) . '</div>';
$media = array('https://scontent.example/photo.jpg' => 'cached_photo.jpg', 'https://scontent.example/s1.jpg' => 'story1.jpg');

section('One capture, one subprocess, items and stories');
$all = FacebookFeedExtractor::extractAll(array($post, $tray), $media);
check(count($all['items']) === 1, 'the post is one item; the tray is not a post', count($all['items']) . ' items');
$item = $all['items'][0] ?? array();
check(($item['author'] ?? '') === 'Ada Lovelace', 'author from the header', (string)($item['author'] ?? ''));
check(($item['dedup_key'] ?? '') === 'post:pfbid0ABC123', 'identity from the permalink', (string)($item['dedup_key'] ?? ''));
check(strpos((string)($item['message'] ?? ''), 'first program') !== false, 'the body text');
check(($item['media'] ?? array()) === array('cached_photo.jpg'), 'the real photo resolves to its cached file');
check(($item['reactions'] ?? null) === 1200 && ($item['comment_count'] ?? null) === 17, 'engagement counts');
check(($item['audience'] ?? '') === 'Public', 'audience');
check(($item['post_type'] ?? '') === 'photo', 'post type');
check(count($all['stories']) === 3, 'the tray yields its stories', count($all['stories']) . ' stories');
check(($all['stories'][0]['author'] ?? '') === 'Person 1' && ($all['stories'][0]['preview'] ?? '') === 'story1.jpg',
	'a story carries its author and cached preview');

section('The single-purpose entry points agree');
check(FacebookFeedExtractor::extract(array($post), $media) === array($item), 'extract() returns the same item');
check(count(FacebookFeedExtractor::extractStories(array($tray), $media)) === 3, 'extractStories() returns the same stories');
check(FacebookFeedExtractor::extractAll(array(), array()) === array('items' => array(), 'stories' => array()), 'an empty capture is empty');

harness_finish();
