<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

$page_vars = process_logic(blog_logic($_GET, $_POST));
$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => $is_valid_page,
    'title' => $page_vars['title']
);
$page->public_header($hoptions);
?>

<!-- Blog Area -->
<section class="blog-area ptb-100">
    <div class="container">
        <div class="section-title">
            <h2><?php echo htmlspecialchars($page_vars['title']); ?></h2>
        </div>

        <div class="row">
            <!-- Blog Posts -->
            <div class="col-lg-8 col-md-6">
                <?php
                if(!$page_vars['posts']){
                    ?>
                    <div class="single-blog-post">
                        <div class="blog-content text-center p-5">
                            <h3>No Results</h3>
                            <p>There are no posts matching that tag.</p>
                        </div>
                    </div>
                    <?php
                } else {
                    foreach ($page_vars['posts'] as $post){
                        $author = new User($post->get('pst_usr_user_id'), TRUE);
                        $post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
                        ?>

                        <div class="single-blog-post">
                            <?php if ($post->get('pst_image_link')): ?>
                            <div class="blog-image">
                                <a href="<?php echo $post->get_url(); ?>">
                                    <img src="<?php echo htmlspecialchars($post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                </a>
                            </div>
                            <?php endif; ?>
                            <div class="blog-content">
                                <?php if (!empty($post_tags)): ?>
                                    <span class="blog-category"><?php echo htmlspecialchars($post_tags[0]); ?></span>
                                <?php endif; ?>

                                <a href="<?php echo $post->get_url(); ?>">
                                    <h3><?php echo htmlspecialchars($post->get('pst_title')); ?></h3>
                                </a>

                                <p>
                                    <?php
                                    if($post->get('pst_short_description')){
                                        echo htmlspecialchars($post->get('pst_short_description'));
                                    } else {
                                        echo htmlspecialchars(substr(strip_tags($post->get('pst_body')),0,150)) . '...';
                                    }
                                    ?>
                                </p>

                                <ul class="blog-meta">
                                    <li>
                                        <a href="#" class="admin">
                                            <i class="bx bx-user"></i>
                                            By <?php echo htmlspecialchars($author->display_name()); ?>
                                        </a>
                                    </li>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        <?php echo date('d M Y', strtotime($post->get('pst_published_time'))); ?>
                                    </li>
                                </ul>
                                <a href="<?php echo $post->get_url(); ?>" class="read-more">Read More <i class='bx bx-chevrons-right'></i></a>
                            </div>
                        </div>

                        <?php
                    }
                }
                ?>

                <!-- Pagination -->
                <?php if($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
                <div class="page-navigation-area">
                    <ul class="pagination">
                        <?php if($page_vars['pager']->is_valid_page('-1')): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo $page_vars['pager']->get_url('-1'); ?>"><i class='bx bx-chevrons-left'></i></a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $current_page = $page_vars['pager']->current_page();
                        $total_pages = ceil($page_vars['pager']->num_records() / $page_vars['pager']->num_per_page());

                        for($i = 1; $i <= $total_pages && $i <= 5; $i++):
                            $active = ($i == $current_page) ? 'active' : '';
                            ?>
                            <li class="page-item <?php echo $active; ?>">
                                <a class="page-link" href="<?php echo $page_vars['pager']->get_url($i); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if($page_vars['pager']->is_valid_page('+1')): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo $page_vars['pager']->get_url('+1'); ?>"><i class='bx bx-chevrons-right'></i></a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4 col-md-6">
                <!-- Popular Posts Widget -->
                <div class="single-widget">
                    <h3>Latest Posts</h3>
                    <ul>
                        <?php
                        $recent_posts = new MultiPost(
                            array('published' => TRUE, 'deleted' => false),
                            array('pst_published_time' => 'DESC'),
                            6, 0
                        );
                        $recent_posts->load();

                        foreach($recent_posts as $recent_post):
                        ?>
                        <li>
                            <a href="<?php echo $recent_post->get_url(); ?>" class="widget-post">
                                <?php if ($recent_post->get('pst_image_link')): ?>
                                    <img src="<?php echo htmlspecialchars($recent_post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($recent_post->get('pst_title')); ?>">
                                <?php endif; ?>
                                <div class="widget-post-content">
                                    <h4><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></h4>
                                    <span><?php echo date('d M Y', strtotime($recent_post->get('pst_published_time'))); ?></span>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$page->public_footer();
?>
