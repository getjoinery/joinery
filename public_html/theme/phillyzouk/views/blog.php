<?php
// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available

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

<!-- Start Latest Article Area -->
<section class="latest-article-area pb-70 pt-100">
    <div class="container">
        <div class="section-title">
            <h2><?php echo htmlspecialchars($page_vars['title']); ?></h2>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <?php
                if(!$page_vars['posts']){
                    ?>
                    <div class="single-featured article">
                        <div class="featured-content text-center p-5">
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

                        <div class="single-featured article">
                            <div class="row align-items-center">
                                <div class="col-lg-5 col-md-6">
                                    <a href="<?php echo $post->get_url(); ?>">
                                        <?php if ($post->get('pst_image_link')): ?>
                                            <img src="<?php echo htmlspecialchars($post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                        <?php else: ?>
                                            <img src="https://via.placeholder.com/400x300/f8f9fa/6c757d?text=Blog+Post" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                        <?php endif; ?>
                                    </a>
                                </div>

                                <div class="col-lg-7 col-md-6">
                                    <div class="featured-content">
                                        <?php if (!empty($post_tags)): ?>
                                            <span>
                                                <?php echo htmlspecialchars($post_tags[0]); ?>
                                            </span>
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

                                        <ul>
                                            <li>
                                                <a href="#" class="admin">
                                                    <i class="bx bx-user"></i>
                                                    <?php echo htmlspecialchars($author->display_name()); ?>
                                                </a>
                                            </li>
                                            <li>
                                                <i class="bx bx-calendar"></i>
                                                <?php echo date('d M Y', strtotime($post->get('pst_published_time'))); ?>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php
                    }
                }
                ?>

                <!-- Pagination -->
                <?php if($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
                <div class="page-navigation-area">
                    <nav aria-label="Page navigation example text-center">
                        <ul class="pagination">
                            <?php if($page_vars['pager']->is_valid_page('-1')): ?>
                                <li class="page-item">
                                    <a class="page-link page-links" href="<?php echo $page_vars['pager']->get_url('-1'); ?>">
                                        <i class='bx bx-chevrons-left'></i>
                                    </a>
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
                                    <a class="page-link" href="<?php echo $page_vars['pager']->get_url('+1'); ?>">
                                        <i class='bx bx-chevrons-right'></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <div class="right-blog-article-wrap-three">
                    <h3 class="latest-post-sub-title">Latest Posts</h3>

                    <?php
                    // Get recent posts for sidebar
                    $recent_posts = new MultiPost(
                        array('published' => TRUE, 'deleted' => false),
                        array('pst_published_time' => 'DESC'),
                        6, 0
                    );
                    $recent_posts->load();

                    foreach($recent_posts as $recent_post):
                    ?>
                    <div class="right-blog-editor media align-items-center">
                        <a href="<?php echo $recent_post->get_url(); ?>">
                            <?php if ($recent_post->get('pst_image_link')): ?>
                                <img src="<?php echo htmlspecialchars($recent_post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($recent_post->get('pst_title')); ?>">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/100x80/f8f9fa/6c757d?text=Post" alt="<?php echo htmlspecialchars($recent_post->get('pst_title')); ?>">
                            <?php endif; ?>
                        </a>

                        <div class="right-blog-content">
                            <a href="<?php echo $recent_post->get_url(); ?>">
                                <h3><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></h3>
                            </a>

                            <span>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y', strtotime($recent_post->get('pst_published_time'))); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Latest Article Area -->

<?php
$page->public_footer();
?>
