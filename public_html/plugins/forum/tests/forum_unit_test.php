<?php
/** @joinery-test
 * name: forum_unit
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The forum's rules that need no database: the full-text indexes are declared
 * with the very expression search queries use (or Postgres would never use
 * them), slugs and titles are made the same way every time, and the reserved
 * category addresses cannot be taken.
 *
 * Run: php tests/run.php --filter=forum_unit
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('the search indexes match the search expression');

$has = function (string $class, array $entry) {
	foreach ($class::$index_specifications as $spec) {
		if (($spec['columns'] ?? null) === $entry['columns'] && strtolower($spec['method'] ?? 'btree') === $entry['method']) {
			return true;
		}
	}
	return false;
};
check($has('ForumPost', FullTextSearch::indexSpec('fpo_body')), 'the post body index is FullTextSearch::indexSpec(fpo_body)');
check($has('ForumTopic', FullTextSearch::indexSpec('ftp_title')), 'the title index is FullTextSearch::indexSpec(ftp_title)');

section('slugs, titles and bodies');

check(Forum::slugify('Hello, World! Café') === 'hello-world-cafe', 'a slug is lowercase ascii words joined by hyphens', Forum::slugify('Hello, World! Café'));
check(Forum::slugify('!!!') === 'topic', 'a title with no letters still has a slug');
check(strlen(Forum::slugify(str_repeat('word ', 50))) <= 80, 'a slug is short');
check(Forum::cleanTitle("  A\n title\t ") === 'A title', 'a title is one trimmed line');
$threw = false;
try { Forum::cleanTitle('   '); } catch (ForumRefusal $e) { $threw = true; }
check($threw, 'an empty title is refused');
$threw = false;
try { Forum::cleanBody(str_repeat('x', Forum::MAX_BODY_BYTES + 1)); } catch (ForumRefusal $e) { $threw = true; }
check($threw, 'an over-long post is refused');
check(Forum::plainExcerpt("**Bold** @[Sam](user:3) [link](https://x.test)\n> quote", 100) === 'Bold @Sam link quote',
	'an excerpt is plain text', Forum::plainExcerpt("**Bold** @[Sam](user:3) [link](https://x.test)\n> quote", 100));
check(strpos(Forum::renderBody('[x](javascript:alert(1))'), 'href') === false, 'a post renders through the restricted profile');

section('reserved addresses');

$refuses = function (ForumCategory $c): string {
	try {
		$c->prepare();
	} catch (DisplayableUserException $e) {
		return $e->getMessage();
	}
	return '';
};
$c = new ForumCategory(NULL);
$c->set('fcg_name', 'Search');
$c->set('fcg_slug', 'search');
$c->set('fcg_read_rule', 'members');
$c->set('fcg_post_rule', 'members');
check(stripos($refuses($c), 'used by the forum') !== false, 'a category cannot take the address the search page uses');
$c->set('fcg_slug', 'Bad Slug');
check(stripos($refuses($c), 'lowercase') !== false, 'nor an address with spaces or capitals');
$c->set('fcg_slug', 'fine');
$c->set('fcg_read_rule', 'tier');
check(stripos($refuses($c), 'subscription tier') !== false, 'a tier rule needs its tier');
check(in_array('Choose the subscription tier the read rule asks for.', $c->problems(), true), 'and save() refuses the same problems (they are one list)');
$threw = false;
try { $c->save(); } catch (DisplayableUserException $e) { $threw = true; }
check($threw, 'save() itself refuses a category with problems, whoever calls it');

section('a tier rule with no tier is closed');

$c->set('fcg_tier_min_level', null);
check(strpos(SpaceAccess::readableSql('ForumCategory', 'fcg', 0), 'tier') === false, 'signed out never reaches a tier rule');
check(!SpaceAccess::mayRead($c, 0), 'and a tier category with no level is closed to visitors');

harness_finish();
