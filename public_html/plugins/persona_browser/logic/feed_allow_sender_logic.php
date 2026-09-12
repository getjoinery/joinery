<?php

/**
 * Persona Browser — allow a feed creator ("Allow sender" in a card's menu).
 * API action: POST /api/v1/action/persona_browser/feed_allow_sender
 *
 * Puts the post's author on the allow list: their posts always show, the AI
 * never judges them, and the repeat-advertiser rule never blocks them. Lifts
 * any block on them. The Senders admin page manages the list.
 */
function feed_allow_sender_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_allowed_senders_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::error('You must be signed in.');
    }

    // Allow by item id — the author on file for that post is what gets
    // allowed, so the client cannot allow an arbitrary string.
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
        return LogicResult::error('This post has no identified sender to allow.');
    }

    PersonaAllowedSender::allow(
        PersonaFeedItem::OWNER_INSTANCE,
        (string)$item->get('pfi_persona'),
        $author
    );

    return LogicResult::render(array('ok' => true, 'author' => $author));
}

function feed_allow_sender_logic_descriptor(): array {
    return array(
        'description'      => 'Put the author of a persona feed post on the allow list: their posts always show, are never judged as ads, and they are never auto-blocked. Takes the id of one of their posts; returns the allowed author name.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => array(
            'item_id' => array('type' => 'int', 'required' => true, 'label' => 'Feed item id of a post by the sender'),
        ),
    );
}
