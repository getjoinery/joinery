<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

$page_vars = process_logic(blog_logic($_GET, $_POST));
$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
    'title'         => $page_vars['title'],
]);
?>
<div class="jy-ui">

<section id="content">
    <div class="content-wrap">
        <div class="container">
            <div class="row gx-5">

                <!-- Main Blog Content -->
                <main class="postcontent col-lg-9">

                    <?php if (!$page_vars['posts']): ?>
                    <div style="background: #fff; border-radius: 8px; padding: 3rem; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                        <h2 style="margin-bottom: 1rem;">No Results</h2>
                        <p style="color: var(--jy-color-text-muted);">There are no posts matching that tag.</p>
                    </div>
                    <?php else: ?>

                    <div id="posts">
                        <?php foreach ($page_vars['posts'] as $post):
                            $author    = new User($post->get('pst_usr_user_id'), TRUE);
                            $post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
                        ?>
                        <div class="entry" style="display: flex; gap: 1.5rem; padding-bottom: 2.5rem; margin-bottom: 2.5rem; border-bottom: 1px solid var(--jy-color-border);">

                            <!-- Thumbnail -->
                            <div style="flex-shrink: 0; width: 200px;">
                                <a href="<?php echo $post->get_url(); ?>" style="text-decoration: none;">
                                    <div style="width: 200px; height: 150px; border-radius: 6px; background: linear-gradient(135deg, #f0f4f8 0%, #dde3ea 100%); display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: #b0bac4;">
                                        &#128214;
                                    </div>
                                </a>
                            </div>

                            <!-- Content -->
                            <div style="flex: 1; min-width: 0;">
                                <h2 style="font-size: 1.375rem; margin-bottom: 0.5rem;">
                                    <a href="<?php echo $post->get_url(); ?>" style="color: var(--jy-color-text); text-decoration: none;">
                                        <?php echo htmlspecialchars($post->get('pst_title')); ?>
                                    </a>
                                </h2>

                                <div style="display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.875rem; color: var(--jy-color-text-muted); margin-bottom: 0.75rem;">
                                    <span>&#128197; <?php echo date('jS M Y', strtotime($post->get('pst_published_time'))); ?></span>
                                    <span>&#128100; <?php echo htmlspecialchars($author->get('usr_first_name') . ' ' . $author->get('usr_last_name')); ?></span>
                                    <?php if (!empty($post_tags)):
                                        $tag_links = [];
                                        foreach ($post_tags as $tag) {
                                            $tag_links[] = '<a href="/blog/tag/' . urlencode($tag) . '" style="color: var(--jy-color-text-muted)">' . htmlspecialchars($tag) . '</a>';
                                        }
                                    ?>
                                    <span>&#128193; <?php echo implode(', ', $tag_links); ?></span>
                                    <?php endif; ?>
                                </div>

                                <p style="color: var(--jy-color-text-muted); margin-bottom: 0.75rem;">
                                    <?php
                                    if ($post->get('pst_short_description')) {
                                        echo htmlspecialchars($post->get('pst_short_description'));
                                    } else {
                                        echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 250)) . '...';
                                    }
                                    ?>
                                </p>

                                <?php
                $post_tier_min = $post->get('pst_tier_min_level');
                if ($post_tier_min > 0): ?>
                <span style="display: inline-block; padding: 0.125rem 0.5rem; background: #f0f0f0; border-radius: 4px; font-size: 0.75rem; color: #888; margin-bottom: 0.5rem;">&#128274; Members Only</span><br>
                <?php endif; ?>
                <a href="<?php echo $post->get_url(); ?>" style="color: var(--jy-color-primary); font-weight: 600; text-decoration: none;">Read More &#8250;</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php endif; ?>

                    <!-- Pagination -->
                    <?php if ($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
                    <div style="margin-top: 2rem;">
                        <?php echo $page->renderPagination($page_vars['pager']); ?>
                    </div>
                    <?php endif; ?>

                </main>

                <!-- Sidebar -->
                <aside class="sidebar col-lg-3">

                    <!-- Pinned / Recent Tabs -->
                    <div style="margin-bottom: 2rem;">
                        <div class="tabs-nav" style="display: flex; border-bottom: 2px solid var(--jy-color-border); margin-bottom: 1rem;">
                            <button class="tab-link active" data-tab="pinned" style="padding: 0.5rem 1rem; border: none; background: none; cursor: pointer; font-weight: 600; color: var(--jy-color-primary); border-bottom: 2px solid var(--jy-color-primary); margin-bottom: -2px;">Pinned</button>
                            <button class="tab-link" data-tab="recent" style="padding: 0.5rem 1rem; border: none; background: none; cursor: pointer; color: var(--jy-color-text-muted);">Recent</button>
                        </div>

                        <div class="tab-content active" data-tab-content="pinned">
                            <?php
                            $pinned_posts = new MultiPost(
                                ['published' => true, 'deleted' => false, 'pinned' => true],
                                ['pst_published_time' => 'DESC'],
                                3, 0
                            );
                            $pinned_posts->load();
                            if ($pinned_posts->count_all() > 0):
                                foreach ($pinned_posts as $pinned_post): ?>
                            <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--jy-color-border);">
                                <div style="flex-shrink: 0; width: 60px; height: 60px; border-radius: 50%; background: var(--jy-color-primary); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #fff;">
                                    &#128204;
                                </div>
                                <div>
                                    <h4 style="font-size: 0.9375rem; margin: 0 0 0.25rem;">
                                        <a href="<?php echo $pinned_post->get_url(); ?>" style="color: var(--jy-color-text); text-decoration: none;"><?php echo htmlspecialchars($pinned_post->get('pst_title')); ?></a>
                                    </h4>
                                    <small style="color: var(--jy-color-text-muted);"><?php echo date('jS M Y', strtotime($pinned_post->get('pst_published_time'))); ?></small>
                                </div>
                            </div>
                            <?php endforeach;
                            else: ?>
                            <p style="color: var(--jy-color-text-muted); font-size: 0.875rem;">No pinned posts available.</p>
                            <?php endif; ?>
                        </div>

                        <div class="tab-content" data-tab-content="recent" style="display: none;">
                            <?php
                            $recent_posts = new MultiPost(
                                ['published' => true, 'deleted' => false],
                                ['pst_published_time' => 'DESC'],
                                3, 0
                            );
                            $recent_posts->load();
                            foreach ($recent_posts as $recent_post): ?>
                            <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--jy-color-border);">
                                <div style="flex-shrink: 0; width: 60px; height: 60px; border-radius: 50%; background: var(--jy-color-surface); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #adb5bd;">
                                    &#128196;
                                </div>
                                <div>
                                    <h4 style="font-size: 0.9375rem; margin: 0 0 0.25rem;">
                                        <a href="<?php echo $recent_post->get_url(); ?>" style="color: var(--jy-color-text); text-decoration: none;"><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></a>
                                    </h4>
                                    <small style="color: var(--jy-color-text-muted);"><?php echo date('jS M Y', strtotime($recent_post->get('pst_published_time'))); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tags Widget -->
                    <?php if (!empty($page_vars['tags'])): ?>
                    <div>
                        <h4 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Tags</h4>
                        <div>
                            <?php foreach ($page_vars['tags'] as $tag): ?>
                            <a href="/blog/tag/<?php echo urlencode($tag); ?>"
                               style="display: inline-block; padding: 0.25rem 0.75rem; margin: 0 0.25rem 0.5rem 0; background: var(--jy-color-surface); color: var(--jy-color-text); text-decoration: none; border-radius: 3px; font-size: 0.875rem;">
                                <?php echo htmlspecialchars($tag); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </aside>

            </div>
        </div>
    </div>
</section>

<script>
// Simple tab toggle for blog sidebar
document.querySelectorAll('.tabs-nav .tab-link').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var tab = this.dataset.tab;
        document.querySelectorAll('.tabs-nav .tab-link').forEach(function(b) {
            b.style.color = 'var(--jy-color-text-muted)';
            b.style.borderBottom = 'none';
            b.style.fontWeight = 'normal';
        });
        this.style.color = 'var(--jy-color-primary)';
        this.style.borderBottom = '2px solid var(--jy-color-primary)';
        this.style.fontWeight = '600';
        document.querySelectorAll('[data-tab-content]').forEach(function(c) {
            c.style.display = c.dataset.tabContent === tab ? '' : 'none';
        });
    });
});
</script>

</div>
<?php
$page->public_footer();
?>
