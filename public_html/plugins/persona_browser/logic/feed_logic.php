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
        $items[] = [
            'author'    => (string)$row->get('pfi_author'),
            'message'   => (string)$row->get('pfi_message'),
            'image_alt' => (string)$row->get('pfi_image_alt'),
            'link'      => (string)$row->get('pfi_link'),
            'media'     => $row->media_files(),
            'seen'      => $row->get_local('pfi_first_seen_time', 'M j, Y g:i A'),
            'is_ad'     => $row->get('pfi_is_ad'),   // NULL = not yet judged
            'ad_reason' => (string)$row->get('pfi_ad_reason'),
        ];
    }

    return LogicResult::render([
        'session'    => $session,
        'items'      => $items,
        'configured' => $client->is_configured(),
        'fetching'   => !empty($input['fetching']),
    ]);
}
