<?php
/**
 * Joinery AI - Chat (member)
 * URL: /profile/joinery_ai/chat
 *
 * The member-facing copy of the assistant: same two-pane body as the admin
 * chat (includes/chat_view_body.php), wrapped in the site theme's public page
 * instead of the admin shell, and pointed at the /profile/joinery_ai/ endpoints.
 * A member's reads are owner-scoped and the action surface is withheld
 * downstream (ChatTurnContext); this view only sets the shell + endpoint base.
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/profile_chat_logic.php'));

$page_vars = process_logic(profile_joinery_ai_chat_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$page->public_header([
    'title' => 'Joinery AI Chat',
]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>Joinery AI</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">Dashboard</a></li>
                        <li class="active">AI Chat</li>
                    </ol>
                </nav>
                <div class="jy-page-header-action">
                    <a href="/profile/joinery_ai/memory" class="btn btn-sm btn-outline">Memory</a>
                </div>
            </div>
        </div>

        <?php
        $base = '/profile/joinery_ai/';
        require(PathHelper::getIncludePath('plugins/joinery_ai/includes/chat_view_body.php'));
        ?>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
