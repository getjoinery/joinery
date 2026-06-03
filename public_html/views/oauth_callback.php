<?php
/**
 * oauth_callback - Generic OAuth2 redirect endpoint (/oauth_callback).
 *
 * Reached by auto-discovery (no serve.php route): the path /oauth_callback maps
 * to this file, views/oauth_callback.php. Auth is governed by the session-bound
 * state, so no permission gate is needed.
 *
 * All work happens in oauth_callback_logic. On success it redirects to the
 * consumer's URL and on a denied/cancelled flow to the originating page; in both
 * cases process_logic() redirects and exits before this template renders. The
 * body below is reached only on the neutral-error path (forged/expired/foreign
 * state, or an unrecoverable dispatch failure), where the logic has already
 * logged the cause server-side and we must reveal nothing to the browser.
 *
 * @version 1.0
 */
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('oauth_callback_logic.php', 'logic'));

    $page_vars = process_logic(oauth_callback_logic(array_merge($_GET, $_POST, $params ?? [])));

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => true,
        'title'         => 'Connection Problem',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>We couldn&rsquo;t complete that connection</h3>

        <div class="alert alert-warning" style="margin-bottom: 1.25rem;">
            <p style="margin: 0;">This authorization link has expired or is no longer valid. Please return to where you started and try connecting again.</p>
        </div>

        <div class="auth-footer-text">
            <a href="/">Return home</a>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['track' => false, 'header_only' => true]);
?>
