<?php

/**
 * Persona Browser — the owner marks one feed post as an ad, or takes the mark
 * back (the Ad button on a feed card).
 * API action: POST /api/v1/action/persona_browser/feed_mark_ad
 *
 * The owner's verdict is stored apart from the AI's (pfi_owner_is_ad) so the
 * two can be compared, and it wins over the AI's on the feed. Either way the
 * post counts as reviewed.
 */
function feed_mark_ad_logic(array $input): LogicResult {
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

    $is_ad = filter_var($input['is_ad'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $item->set_owner_verdict($is_ad);

    return LogicResult::render(array('ok' => true, 'item_id' => $item_id, 'is_ad' => $is_ad));
}

function feed_mark_ad_logic_descriptor(): array {
    return array(
        'description'      => "Record the owner's own verdict on one persona feed post: an ad, or not an ad. The post then counts as reviewed.",
        'requires_session' => true,
        'mutates'          => true,
        'input'            => array(
            'item_id' => array('type' => 'int', 'required' => true, 'label' => 'Feed item id'),
            'is_ad'   => array('type' => 'bool', 'required' => true, 'label' => 'Is an ad'),
        ),
    );
}
