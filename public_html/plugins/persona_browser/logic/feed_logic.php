<?php

/**
 * Persona Browser — member feed page logic
 * URL: /profile/persona_browser/feed
 *
 * Reads stored feed posts (fast — no live browser fetch on page load). New
 * posts arrive via the hourly FetchFeedTask; the "Fetch now" button kicks an
 * out-of-band fetch so the page stays responsive.
 *
 * The page is one feed: the network's current Stories are cards in the same
 * list as posts, every card placed by when it was first captured. Each item
 * carries a 'kind' ('post' or 'story') so the view knows which card to draw.
 *
 * Review mode (?review=1) is for labelling ads by hand: it lists only posts
 * the owner has not reviewed yet, newest first, without stories. A post's
 * badge follows the owner's own mark when there is one, and the AI's verdict
 * otherwise — unless the AI's verdicts are switched off in settings.
 */
function persona_browser_feed_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_stories_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_blocked_senders_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_allowed_senders_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login?return=/profile/persona_browser/feed');
    }

    // "Fetch now" — trigger a background fetch and return immediately.
    if (LibraryFunctions::isFormSubmission() && isset($input['btn_fetch_now'])) {
        $cli = PathHelper::getIncludePath('plugins/persona_browser/tasks/run_fetch_cli.php');
        $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        @exec(escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' > /dev/null 2>&1 &');
        return LogicResult::redirect('/profile/persona_browser/feed?fetching=1');
    }

    $client = new PersonaBrowserClient();

    // Display filters (plugin settings). Off by default — the feed shows
    // everything unless the owner opts to hide a category.
    $settings   = Globalvars::get_instance();
    $hide_ads   = (string)$settings->get_setting('persona_browser_hide_ads') === '1';
    $hide_reels = (string)$settings->get_setting('persona_browser_hide_reels') === '1';
    $show_ai    = (string)$settings->get_setting('persona_browser_show_ai_ad_verdicts') !== '0';
    $review     = !empty($input['review']);

    // Senders the owner has blocked — their posts are filtered out of the
    // display, past and future. Compared case-insensitively: Facebook display
    // names vary in casing between captures.
    $blocked = PersonaBlockedSender::blocked_author_set(PersonaFeedItem::OWNER_INSTANCE, 'facebook');
    // Senders the owner always wants to see — shown whatever the ad verdict
    // says, and shown without the ad badge: the owner has already decided.
    $allowed = PersonaAllowedSender::allowed_author_set(PersonaFeedItem::OWNER_INSTANCE, 'facebook');

    $page_size = 60;
    $options = ['owner_user_id' => PersonaFeedItem::OWNER_INSTANCE, 'persona' => 'facebook', 'deleted' => false];
    if ($review) {
        $options['owner_unreviewed'] = true;
        $options['exclude_reels'] = $hide_reels;
    }
    $rows = new MultiPersonaFeedItem($options, ['pfi_first_seen_time' => 'DESC', 'pfi_persona_feed_item_id' => 'DESC']);

    // Hidden ads, reels and blocked authors are dropped below, after the
    // query, so read in batches until the page is full or the posts run out:
    // a run of hidden posts at the top must not leave the page empty.
    $items = [];
    foreach ($rows->incremental_iterator($page_size) as $row) {
        if (count($items) >= $page_size) break;
        $author = (string)$row->get('pfi_author');
        $author_key = mb_strtolower(trim($author));
        $is_allowed = isset($allowed[$author_key]);
        // The owner's own mark wins. Otherwise the AI's verdict counts, except
        // for allowed senders and while the AI's verdicts are switched off.
        $owner_is_ad = $row->get('pfi_owner_is_ad');
        $ai_counts = $owner_is_ad === null && $show_ai && !$is_allowed;
        $is_ad = $owner_is_ad !== null ? (bool)$owner_is_ad : ($ai_counts ? $row->get('pfi_is_ad') : null);
        // Hide confirmed ads only — an unjudged post still shows. Review mode
        // hides nothing: every unreviewed post needs the owner's own call,
        // including ones the AI judged.
        if ($hide_ads && !$review && !empty($is_ad)) {
            continue;
        }
        // Reels are identified by the service's canonical dedup key prefix.
        if ($hide_reels && strncmp((string)$row->get('pfi_dedup_key'), 'reel:', 5) === 0) {
            continue;
        }
        if (isset($blocked[$author_key])) {
            continue;
        }
        $items[] = [
            'kind'      => 'post',
            'id'        => (int)$row->key,
            'persona'   => (string)$row->get('pfi_persona'),
            'author'    => $author,
            'message'   => (string)$row->get('pfi_message'),
            'image_alt' => (string)$row->get('pfi_image_alt'),
            'link'      => (string)$row->get('pfi_link'),
            'media'     => $row->media_files(),
            'seen'      => $row->get_local('pfi_first_seen_time', 'M j, Y g:i A'),
            'seen_utc'  => (string)$row->get('pfi_first_seen_time'),
            'is_ad'     => $is_ad,   // NULL = not judged, or no verdict that counts
            'ad_reason' => $owner_is_ad !== null ? 'Marked by you' : ($ai_counts ? (string)$row->get('pfi_ad_reason') : ''),
            'owner_is_ad' => $owner_is_ad === null ? null : (bool)$owner_is_ad,
        ];
    }

    // Current stories — the table mirrors the latest capture's tray, blocked
    // senders excluded. A story is a card like any other, placed in the feed
    // by when it was first captured.
    $story_rows = $review ? [] : new MultiPersonaStory(
        ['owner_user_id' => PersonaFeedItem::OWNER_INSTANCE, 'persona' => 'facebook', 'deleted' => false]
    );
    foreach ($story_rows as $s) {
        $s_author = (string)$s->get('pss_author');
        if (isset($blocked[mb_strtolower(trim($s_author))])) {
            continue;
        }
        $items[] = [
            'kind'      => 'story',
            'id'        => (int)$s->key,
            'persona'   => (string)$s->get('pss_persona'),
            'author'    => $s_author,
            'link'      => (string)$s->get('pss_link'),
            'preview'   => (string)$s->get('pss_preview_media'),
            'avatar'    => (string)$s->get('pss_avatar_media'),
            'seen'      => $s->get_local('pss_first_seen_time', 'M j, Y g:i A'),
            'seen_utc'  => (string)$s->get('pss_first_seen_time'),
        ];
    }

    // Newest first, whatever kind of card.
    usort($items, function (array $a, array $b): int {
        return strcmp($b['seen_utc'], $a['seen_utc']);
    });

    return LogicResult::render([
        'session'    => $session,
        'items'      => $items,
        'configured' => $client->is_configured(),
        'fetching'   => !empty($input['fetching']),
        'review'     => $review,
        'unreviewed' => PersonaFeedItem::owner_unreviewed_count('facebook', $hide_reels, $blocked),
    ]);
}
