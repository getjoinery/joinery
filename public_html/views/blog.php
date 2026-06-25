<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

$page_vars = process_logic(blog_logic(array_merge($_GET, $_POST, $params ?? [])));
$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page,
]);
?>
<div class="jy-ui">

<section id="content">
    <div class="content-wrap">
        <div class="jy-container">
            <div class="row gx-5">

                <!-- Main Blog Content -->
                <main class="postcontent col-lg-9">

                    <?php if (!$page_vars['posts']): ?>
                    <div class="jy-blog-noresult">
                        <h2 class="jy-blog-noresult-h">No Results</h2>
                        <p class="jy-muted">There are no posts matching that tag.</p>
                    </div>
                    <?php else: ?>

                    <div id="posts">
                        <?php foreach ($page_vars['posts'] as $post):
                            $author    = new User($post->get('pst_usr_user_id'), TRUE);
                            $post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
                        ?>
                        <div class="entry jy-blog-entry">

                            <!-- Thumbnail -->
                            <div class="jy-blog-thumb">
                                <a href="<?php echo $post->get_url(); ?>" class="jy-blog-thumblink">
                                    <div class="jy-blog-thumbimg">
                                        &#128214;
                                    </div>
                                </a>
                            </div>

                            <!-- Content -->
                            <div class="jy-blog-body">
                                <h2 class="jy-blog-title">
                                    <a href="<?php echo $post->get_url(); ?>" class="jy-blog-titlelink">
                                        <?php echo htmlspecialchars($post->get('pst_title')); ?>
                                    </a>
                                </h2>

                                <div class="jy-blog-meta">
                                    <span>&#128197; <?php echo date('jS M Y', strtotime($post->get('pst_published_time'))); ?></span>
                                    <span>&#128100; <?php echo htmlspecialchars($author->get('usr_first_name') . ' ' . $author->get('usr_last_name')); ?></span>
                                    <?php if (!empty($post_tags)):
                                        $tag_links = [];
                                        foreach ($post_tags as $tag) {
                                            $tag_links[] = '<a href="/blog/tag/' . urlencode($tag) . '" class="jy-muted">' . htmlspecialchars($tag) . '</a>';
                                        }
                                    ?>
                                    <span>&#128193; <?php echo implode(', ', $tag_links); ?></span>
                                    <?php endif; ?>
                                </div>

                                <p class="jy-blog-excerpt">
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
                <span class="jy-blog-memberbadge">&#128274; Members Only</span><br>
                <?php endif; ?>
                <a href="<?php echo $post->get_url(); ?>" class="jy-blog-readmore">Read More &#8250;</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php endif; ?>

                    <!-- Pagination -->
                    <?php if ($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
                    <div class="jy-blog-pagination">
                        <?php echo $page->renderPagination($page_vars['pager']); ?>
                    </div>
                    <?php endif; ?>

                </main>

                <!-- Sidebar -->
                <aside class="sidebar col-lg-3">

                    <!-- Pinned / Recent Tabs -->
                    <div class="jy-blog-widget">
                        <div class="tabs-nav">
                            <button class="tab-link active" data-tab="pinned">Pinned</button>
                            <button class="tab-link" data-tab="recent">Recent</button>
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
                            <div class="jy-blog-sidepost">
                                <div class="jy-blog-sideicon-pinned">
                                    &#128204;
                                </div>
                                <div>
                                    <h4 class="jy-blog-sidetitle">
                                        <a href="<?php echo $pinned_post->get_url(); ?>" class="jy-blog-titlelink"><?php echo htmlspecialchars($pinned_post->get('pst_title')); ?></a>
                                    </h4>
                                    <small class="jy-muted"><?php echo date('jS M Y', strtotime($pinned_post->get('pst_published_time'))); ?></small>
                                </div>
                            </div>
                            <?php endforeach;
                            else: ?>
                            <p class="jy-blog-emptynote">No pinned posts available.</p>
                            <?php endif; ?>
                        </div>

                        <div class="tab-content" data-tab-content="recent">
                            <?php
                            $recent_posts = new MultiPost(
                                ['published' => true, 'deleted' => false],
                                ['pst_published_time' => 'DESC'],
                                3, 0
                            );
                            $recent_posts->load();
                            foreach ($recent_posts as $recent_post): ?>
                            <div class="jy-blog-sidepost">
                                <div class="jy-blog-sideicon-recent">
                                    &#128196;
                                </div>
                                <div>
                                    <h4 class="jy-blog-sidetitle">
                                        <a href="<?php echo $recent_post->get_url(); ?>" class="jy-blog-titlelink"><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></a>
                                    </h4>
                                    <small class="jy-muted"><?php echo date('jS M Y', strtotime($recent_post->get('pst_published_time'))); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tags Widget -->
                    <?php if (!empty($page_vars['tags'])): ?>
                    <div>
                        <h4 class="jy-blog-tagstitle">Tags</h4>
                        <div>
                            <?php foreach ($page_vars['tags'] as $tag): ?>
                            <a href="/blog/tag/<?php echo urlencode($tag); ?>" class="jy-blog-tag">
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
        document.querySelectorAll('.tabs-nav .tab-link').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('[data-tab-content]').forEach(function(c) {
            c.classList.toggle('active', c.dataset.tabContent === tab);
        });
    });
});
</script>

</div>
<?php
$page->public_footer();
?>
