<?php

/**
 * Persona Browser — hide one story (the X on a story card).
 * API action: POST /api/v1/action/persona_browser/feed_hide_story
 *
 * Soft-deletes the story so the feed never shows it again. The hourly fetch
 * matches hidden rows too, so the story stays hidden while the network still
 * offers it, and is removed for good once it leaves the tray.
 */
function feed_hide_story_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_stories_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::error('You must be signed in.');
    }

    $story_id = (int)($input['story_id'] ?? 0);
    if ($story_id <= 0) {
        return LogicResult::error('A story id is required.');
    }

    $story = new PersonaStory($story_id, true);
    if (!$story->key || (int)$story->get('pss_owner_user_id') !== PersonaFeedItem::OWNER_INSTANCE) {
        return LogicResult::error('Story not found.');
    }

    if (!$story->get('pss_delete_time')) {
        $story->soft_delete();
    }

    return LogicResult::render(array('ok' => true, 'story_id' => $story_id));
}

function feed_hide_story_logic_descriptor(): array {
    return array(
        'description'      => 'Hide one persona story so it never shows in the feed again.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => array(
            'story_id' => array('type' => 'int', 'required' => true, 'label' => 'Story id'),
        ),
    );
}
