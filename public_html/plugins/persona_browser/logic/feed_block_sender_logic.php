<?php

/**
 * Persona Browser — block a feed creator ("Block sender" in a card's menu).
 * API action: POST /api/v1/action/persona_browser/feed_block_sender
 *
 * Records the post's author as blocked; the feed page filters every post by a
 * blocked author (past and future) at display time. Capture is untouched —
 * unblocking (deleting the block row) brings the author's stored posts back.
 */
function feed_block_sender_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_blocked_senders_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::error('You must be signed in.');
    }

    // Block by item id — the author on file for that post is what gets
    // blocked, so the client cannot block an arbitrary string.
    $item_id = (int)($input['item_id'] ?? 0);
    if ($item_id <= 0) {
        return LogicResult::error('A feed item id is required.');
    }

    $item = new PersonaFeedItem($item_id, true);
    if (!$item->key || (int)$item->get('pfi_owner_user_id') !== PersonaFeedItem::OWNER_INSTANCE) {
        return LogicResult::error('Post not found.');
    }

    $author = trim((string)$item->get('pfi_author'));
    if ($author === '') {
        return LogicResult::error('This post has no identified sender to block.');
    }

    PersonaBlockedSender::block(
        PersonaFeedItem::OWNER_INSTANCE,
        (string)$item->get('pfi_persona'),
        $author
    );

    return LogicResult::render(array('ok' => true, 'author' => $author));
}

function feed_block_sender_logic_descriptor(): array {
    return array(
        'description'      => 'Block the author of a persona feed post so their posts never show in the feed again. Takes the id of one of their posts; returns the blocked author name.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => array(
            'item_id' => array('type' => 'int', 'required' => true, 'label' => 'Feed item id of a post by the sender'),
        ),
    );
}
