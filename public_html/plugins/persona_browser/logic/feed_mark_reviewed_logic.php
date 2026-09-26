<?php

/**
 * Persona Browser — the owner has looked over a run of feed posts ("Reviewed
 * down to here" on a feed card).
 * API action: POST /api/v1/action/persona_browser/feed_mark_reviewed
 *
 * Every listed post the owner has not already given a verdict is recorded as
 * reviewed and not an ad. Posts marked as ads keep that mark.
 */
function feed_mark_reviewed_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::error('You must be signed in.');
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['item_ids'] ?? [])))));
    if (!$ids) {
        return LogicResult::error('At least one feed item id is required.');
    }

    $reviewed = 0;
    foreach ($ids as $id) {
        $item = new PersonaFeedItem($id, true);
        if (!$item->key || (int)$item->get('pfi_owner_user_id') !== PersonaFeedItem::OWNER_INSTANCE) {
            continue;
        }
        if ($item->get('pfi_owner_is_ad') === null) {
            $item->mark_owner_reviewed();
            $reviewed++;
        }
    }

    return LogicResult::render(array('ok' => true, 'reviewed' => $reviewed));
}

function feed_mark_reviewed_logic_descriptor(): array {
    return array(
        'description'      => 'Record that the owner reviewed a run of persona feed posts: each one not already given a verdict is recorded as not an ad.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => array(
            'item_ids' => array('type' => 'array', 'required' => true, 'label' => 'Feed item ids',
                'items' => array('type' => 'int'), 'max_items' => 500),
        ),
    );
}
