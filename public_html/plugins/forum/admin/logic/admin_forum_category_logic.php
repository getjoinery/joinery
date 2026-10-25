<?php
/**
 * Forum category edit logic: create or change one category — its name,
 * address, description, place in the list, and who may read and post in it.
 *
 * A change to who may read clears every cached forum page (ForumCache), so a
 * category made private stops being served from the cache at once.
 *
 * @version 1.0.0
 */

function admin_forum_category_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$post = LibraryFunctions::isFormSubmission() ? $input : array();
	$get = $input;

	if (isset($post['edit_primary_key_value']) && (int)$post['edit_primary_key_value'] > 0) {
		$category = new ForumCategory((int)$post['edit_primary_key_value'], TRUE);
	} elseif (!empty($get['fcg_forum_category_id'])) {
		$category = new ForumCategory((int)$get['fcg_forum_category_id'], TRUE);
	} else {
		$category = new ForumCategory(NULL);
	}

	$error = '';
	if (LibraryFunctions::isFormSubmission() && isset($post['fcg_name'])) {
		$name = trim((string)$post['fcg_name']);
		$slug = trim((string)($post['fcg_slug'] ?? ''));
		if ($slug === '') {
			$slug = Forum::slugify($name, 60);
		}
		$category->set('fcg_name', mb_substr($name, 0, 100));
		$category->set('fcg_slug', strtolower($slug));
		$category->set('fcg_description', trim((string)($post['fcg_description'] ?? '')) ?: null);
		$category->set('fcg_read_rule', (string)($post['fcg_read_rule'] ?? 'members'));
		$category->set('fcg_post_rule', (string)($post['fcg_post_rule'] ?? 'members'));
		$category->set('fcg_tier_min_level', (int)($post['fcg_tier_min_level'] ?? 0) ?: null);
		$category->set('fcg_grp_group_id', (int)($post['fcg_grp_group_id'] ?? 0) ?: null);
		$category->set('fcg_sort_order', (int)($post['fcg_sort_order'] ?? 0));
		try {
			$taken = Forum::categoryBySlug((string)$category->get('fcg_slug'));
			if ($taken && (int)$taken->key !== (int)$category->key) {
				throw new SystemBaseException('Another category already uses that address.');
			}
			$category->prepare();
			$category->save();
			ForumCache::forumChanged();
			return LogicResult::redirect('/plugins/forum/admin/admin_forum_categories?flash=' . urlencode('Saved "' . $category->get('fcg_name') . '".'));
		} catch (Exception $e) {
			$error = $e->getMessage();
		}
	}

	$tiers = array('' => 'Choose a tier');
	foreach (new MultiSubscriptionTier(array('deleted' => false), array('sbt_tier_level' => 'ASC')) as $tier) {
		$tiers[(string)(int)$tier->get('sbt_tier_level')] = $tier->get('sbt_name') . ' (level ' . (int)$tier->get('sbt_tier_level') . ' or higher)';
	}
	$groups = array('' => 'Choose a group');
	foreach (Group::get_groups_in_category('user') as $group) {
		$groups[(string)(int)$group->key] = (string)$group->get('grp_name');
	}

	return LogicResult::render(array(
		'session'  => $session,
		'category' => $category,
		'tiers'    => $tiers,
		'groups'   => $groups,
		'error'    => $error,
	));
}
