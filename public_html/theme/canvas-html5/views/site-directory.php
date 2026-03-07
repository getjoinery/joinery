<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    require_once(PathHelper::getIncludePath('data/pages_class.php'));
    require_once(PathHelper::getIncludePath('data/posts_class.php'));
    require_once(PathHelper::getIncludePath('data/events_class.php'));
    require_once(PathHelper::getIncludePath('data/locations_class.php'));
    require_once(PathHelper::getIncludePath('data/videos_class.php'));

    $paged = new PublicPage();
    $paged->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Sitemap',
    ]);
    echo PublicPage::BeginPage('Site Directory');
?>

<div class="container">
    <div class="text-center mb-5">
        <p class="text-muted">Browse all content on our website</p>
    </div>

    <div class="row g-4">
        <?php
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('page_contents_active')):
        ?>
        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Pages</h4>
                </div>
                <div class="card-body">
                    <?php
                    $pages = new MultiPage(['published' => true, 'deleted' => false, 'has_link' => true]);
                    $pages->load();
                    if ($pages->count() > 0): ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($pages as $page): ?>
                        <li class="py-2 border-bottom">
                            <a href="/page/<?php echo $page->get_url(); ?>">
                                &rsaquo; <?php echo htmlspecialchars($page->get('pag_title')); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="text-muted mb-0">No pages available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($settings->get_setting('events_active')): ?>
        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0">Events</h4>
                </div>
                <div class="card-body">
                    <?php
                    $events = new MultiEvent(
                        ['deleted' => false, 'visibility' => 1, 'status' => 1],
                        ['start_time' => 'ASC']
                    );
                    $events->load();
                    if ($events->count() > 0): ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($events as $event): ?>
                        <li class="py-2 border-bottom">
                            <a href="<?php echo $event->get_url(); ?>">
                                &rsaquo; <?php echo htmlspecialchars($event->get('evt_name')); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="text-muted mb-0">No events available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">Locations</h4>
                </div>
                <div class="card-body">
                    <?php
                    $locations = new MultiLocation(
                        ['deleted' => false, 'published' => true],
                        ['location_id' => 'ASC']
                    );
                    $locations->load();
                    if ($locations->count() > 0): ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($locations as $location): ?>
                        <li class="py-2 border-bottom">
                            <a href="<?php echo $location->get_url(); ?>">
                                &rsaquo; <?php echo htmlspecialchars($location->get('loc_name')); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="text-muted mb-0">No locations available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($settings->get_setting('blog_active')): ?>
        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 h-100">
                <div class="card-header bg-warning text-dark">
                    <h4 class="mb-0">Blog Posts</h4>
                </div>
                <div class="card-body">
                    <?php
                    $posts = new MultiPost(['published' => true, 'deleted' => false]);
                    $posts->load();
                    if ($posts->count() > 0): ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($posts as $post): ?>
                        <li class="py-2 border-bottom">
                            <a href="<?php echo $post->get_url(); ?>">
                                &rsaquo; <?php echo htmlspecialchars($post->get('pst_title')); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="text-muted mb-0">No blog posts available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php
    echo PublicPage::EndPage();
    $paged->public_footer(['track' => true]);
?>
