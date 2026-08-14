<?php

/**
 * Persona Browser — member feed page logic
 * URL: /profile/persona_browser/feed
 *
 * Reads stored feed posts (fast — no live browser fetch on page load). New
 * posts arrive via the hourly FetchFeedTask; the "Fetch now" button kicks an
 * out-of-band fetch so the page stays responsive.
 */
function persona_browser_feed_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_stories_class.php'));
    require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_blocked_senders_class.php'));

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

    // Senders the owner has blocked — their posts are filtered out of the
    // display, past and future. Compared case-insensitively: Facebook display
    // names vary in casing between captures.
    $blocked = PersonaBlockedSender::blocked_author_set(PersonaFeedItem::OWNER_INSTANCE, 'facebook');

    $rows = new MultiPersonaFeedItem(
        ['owner_user_id' => PersonaFeedItem::OWNER_INSTANCE, 'persona' => 'facebook', 'deleted' => false],
        ['pfi_first_seen_time' => 'DESC'],
        60
    );

    $items = [];
    foreach ($rows as $row) {
        // Hide confirmed ads only — an unjudged post (pfi_is_ad NULL) still shows.
        if ($hide_ads && !empty($row->get('pfi_is_ad'))) {
            continue;
        }
        // Reels are identified by the service's canonical dedup key prefix.
        if ($hide_reels && strncmp((string)$row->get('pfi_dedup_key'), 'reel:', 5) === 0) {
            continue;
        }
        $author = (string)$row->get('pfi_author');
        if ($blocked && isset($blocked[mb_strtolower(trim($author))])) {
            continue;
        }
        $items[] = [
            'id'        => (int)$row->key,
            'persona'   => (string)$row->get('pfi_persona'),
            'author'    => $author,
            'message'   => (string)$row->get('pfi_message'),
            'image_alt' => (string)$row->get('pfi_image_alt'),
            'link'      => (string)$row->get('pfi_link'),
            'media'     => $row->media_files(),
            'seen'      => $row->get_local('pfi_first_seen_time', 'M j, Y g:i A'),
            'is_ad'     => $row->get('pfi_is_ad'),   // NULL = not yet judged
            'ad_reason' => (string)$row->get('pfi_ad_reason'),
        ];
    }

    // Current stories — the table mirrors the latest capture's tray, blocked
    // senders excluded, in the tray's own order.
    $stories = [];
    $story_rows = new MultiPersonaStory(
        ['owner_user_id' => PersonaFeedItem::OWNER_INSTANCE, 'persona' => 'facebook', 'deleted' => false],
        ['pss_position' => 'ASC']
    );
    foreach ($story_rows as $s) {
        $s_author = (string)$s->get('pss_author');
        if ($blocked && isset($blocked[mb_strtolower(trim($s_author))])) {
            continue;
        }
        $stories[] = [
            'author'  => $s_author,
            'link'    => (string)$s->get('pss_link'),
            'preview' => (string)$s->get('pss_preview_media'),
            'avatar'  => (string)$s->get('pss_avatar_media'),
        ];
    }

    return LogicResult::render([
        'session'    => $session,
        'items'      => $items,
        'stories'    => $stories,
        'configured' => $client->is_configured(),
        'fetching'   => !empty($input['fetching']),
    ]);
}
