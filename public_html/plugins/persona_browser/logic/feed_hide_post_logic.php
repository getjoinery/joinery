<?php

/**
 * Persona Browser — hide one feed post (the X on a feed card).
 * API action: POST /api/v1/action/persona_browser/feed_hide_post
 *
 * Soft-deletes the post so the feed never shows it again. The hourly fetch
 * stays a no-op for a hidden post: its dedup lookup matches deleted rows, so
 * re-seeing the post never resurrects it.
 */
function feed_hide_post_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::error('You must be signed in.');
    }

    $item_id = (int)($input['item_id'] ?? 0);
    if ($item_id <= 0) {
        return LogicResult::error('A feed item id is required.');
    }

    $item = new PersonaFeedItem($item_id, true);
    if (!$item->key || (int)$item->get('pfi_owner_user_id') !== PersonaFeedItem::OWNER_INSTANCE) {
        return LogicResult::error('Post not found.');
    }

    if (!$item->get('pfi_delete_time')) {
        $item->soft_delete();
    }

    return LogicResult::render(array('ok' => true, 'item_id' => $item_id));
}

function feed_hide_post_logic_descriptor(): array {
    return array(
        'description'      => 'Hide one persona feed post so it never shows in the feed again.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => array(
            'item_id' => array('type' => 'int', 'required' => true, 'label' => 'Feed item id'),
        ),
    );
}
