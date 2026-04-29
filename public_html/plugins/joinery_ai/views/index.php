<?php
/**
 * Joinery AI - Owner Dashboard
 * URL: /joinery_ai
 *
 * Public-themed page that renders each enabled recipe (with
 * rcp_delivery_dashboard = true) as a card showing its latest successful
 * output. Owner-only — non-permission-10 users get bounced to login.
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/joinery_ai_dashboard_logic.php'));

$page_vars = process_logic(joinery_ai_dashboard_logic($_GET, $_POST));
extract($page_vars);

$page = new PublicPage();
$page->public_header([
    'title'            => 'Joinery AI',
    'meta_description' => 'Your scheduled AI recipes.',
]);
?>

<style>
    .joai-wrap { max-width: 900px; margin: 2rem auto; padding: 0 1rem; }
    .joai-wrap h1 { margin-bottom: 0.5rem; }
    .joai-card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 1.5rem;
                  background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .joai-card-header { padding: 1rem 1.25rem; border-bottom: 1px solid #eee;
                        display: flex; justify-content: space-between; align-items: center; }
    .joai-card-header h2 { margin: 0; font-size: 1.25rem; }
    .joai-card-header .joai-meta { font-size: 0.85rem; color: #666; }
    .joai-card-body { padding: 1.25rem; }
    .joai-empty { padding: 1.25rem; color: #888; font-style: italic; }
    .joai-actions a { font-size: 0.85rem; margin-left: 0.5rem; }
</style>

<div class="joai-wrap">
    <h1>Joinery AI</h1>
    <p style="color: #666; margin-bottom: 2rem;">
        Latest output from your scheduled recipes.
        <span class="joai-actions">
            <a href="/admin/joinery_ai">Manage recipes</a>
        </span>
    </p>

    <?php if (empty($cards)): ?>
        <div class="joai-card">
            <div class="joai-empty">
                No recipes are configured to display on the dashboard yet.
                <a href="/admin/joinery_ai/edit">Create one</a>
                or
                <a href="/admin/joinery_ai">enable "Show on dashboard" on an existing recipe</a>.
            </div>
        </div>
    <?php else: foreach ($cards as $card):
        $recipe = $card['recipe'];
        $latest = $card['latest'];
    ?>
        <div class="joai-card">
            <div class="joai-card-header">
                <h2><?php echo htmlspecialchars($recipe->get('rcp_name')); ?></h2>
                <span class="joai-meta">
                    <?php if ($latest):
                        $when = LibraryFunctions::convert_time(
                            $latest['rcr_started_time'], 'UTC', $session->get_timezone(), 'M j, Y g:i A'
                        );
                        echo 'Last run: ' . htmlspecialchars($when);
                    else:
                        echo '<em>No successful runs yet</em>';
                    endif; ?>
                    <span class="joai-actions">
                        <a href="/admin/joinery_ai/run_now"
                           onclick="event.preventDefault(); var f=document.getElementById('runform_<?php echo (int)$recipe->key; ?>'); f.submit();">Run now</a>
                        <a href="/admin/joinery_ai/runs?rcp_recipe_id=<?php echo (int)$recipe->key; ?>">History</a>
                    </span>
                    <form id="runform_<?php echo (int)$recipe->key; ?>" method="post"
                          action="/admin/joinery_ai/run_now" style="display:none;">
                        <input type="hidden" name="rcp_recipe_id" value="<?php echo (int)$recipe->key; ?>">
                    </form>
                </span>
            </div>
            <div class="joai-card-body">
                <?php if ($latest && trim((string)$latest['rcr_output']) !== ''): ?>
                    <?php echo MarkdownRenderer::render($latest['rcr_output']); ?>
                <?php else: ?>
                    <div class="joai-empty">Waiting for the first run.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php
$page->public_footer();
