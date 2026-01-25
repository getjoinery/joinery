<?php
// theme/empoweredhealth/views/blog.php
// Theme-specific blog template with empoweredhealth styling

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

$page_vars = blog_logic($_GET, $_POST);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

$paget = new PublicPage();
$paget->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $page_vars['title']
));
?>

<!-- Banner Section -->
<section class="banner-area relative about-banner" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row d-flex align-items-center justify-content-center">
            <div class="about-content col-lg-12">
                <h1 class="text-white">Blog</h1>
                <p class="text-white link-nav">
                    <a href="/">Home </a><span class="lnr lnr-arrow-right"></span>
                    Blog
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Blog Content Section -->
<section class="post-content-area section-top-border">
    <div class="container">
        <div class="row">
            <!-- Main Blog Content -->
            <div class="col-lg-8 posts-list">
                <?php
                if (!$page_vars['posts'] || $page_vars['posts']->count() == 0) {
                    ?>
                    <div class="single-post row">
                        <p>There are no posts matching that criteria.</p>
                    </div>
                    <?php
                } else {
                    foreach ($page_vars['posts'] as $post) {
                        $author = new User($post->get('pst_usr_user_id'), TRUE);
                        $post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
                        ?>
                        <div class="single-post row">
                            <div class="col-lg-3 col-md-3 meta-details">
                                <ul class="tags">
                                    <?php if (!empty($post_tags)): ?>
                                        <?php foreach ($post_tags as $tag): ?>
                                            <li><a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?>,</a></li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li><a href="#">Health,</a></li>
                                    <?php endif; ?>
                                </ul>
                                <div class="user-details row">
                                    <p class="user-name col-lg-12 col-md-12 col-6"><a href="#"><?php echo htmlspecialchars($author->display_name()); ?></a> <span class="lnr lnr-user"></span></p>
                                    <p class="date col-lg-12 col-md-12 col-6"><a href="#"><?php echo date('M j, g:i a', strtotime($post->get('pst_published_time'))); ?></a> <span class="lnr lnr-calendar-full"></span></p>
                                </div>
                            </div>
                            <div class="col-lg-9 col-md-9">
                                <a class="posts-title" href="<?php echo $post->get_url(); ?>"><h3><?php echo htmlspecialchars($post->get('pst_title')); ?></h3></a>
                                <p class="excert">
                                    <?php
                                    if ($post->get('pst_short_description')) {
                                        echo htmlspecialchars($post->get('pst_short_description'));
                                    } else {
                                        echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 300)) . '...';
                                    }
                                    ?>
                                </p>
                                <a href="<?php echo $post->get_url(); ?>" class="primary-btn">Read more</a>
                            </div>
                        </div>
                        <?php
                    }
                }
                ?>

                <!-- Pagination -->
                <?php if ($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
                <nav class="blog-pagination justify-content-center d-flex">
                    <ul class="pagination">
                        <?php if ($page_vars['pager']->is_valid_page('-1')): ?>
                        <li class="page-item">
                            <a href="?<?php echo $page_vars['pager']->get_param_string('-1'); ?>" class="page-link" aria-label="Previous">
                                <span aria-hidden="true"><span class="lnr lnr-chevron-left"></span></span>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php
                        $current_page = $page_vars['pager']->get_param();
                        $total_pages = ceil($page_vars['pager']->num_records() / $page_vars['pager']->get_limit());
                        for ($i = 1; $i <= $total_pages && $i <= 5; $i++):
                            $active = ($i == $current_page) ? 'active' : '';
                        ?>
                        <li class="page-item <?php echo $active; ?>">
                            <a href="?<?php echo $page_vars['pager']->get_param_string($i); ?>" class="page-link"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page_vars['pager']->is_valid_page('+1')): ?>
                        <li class="page-item">
                            <a href="?<?php echo $page_vars['pager']->get_param_string('+1'); ?>" class="page-link" aria-label="Next">
                                <span aria-hidden="true"><span class="lnr lnr-chevron-right"></span></span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4 sidebar-widgets">
                <!-- Author Profile Widget -->
                <div class="widget-wrap">
                    <div class="about-widget text-center">
                        <img class="author-img" src="/uploads/medium/heath1_z0wwvdqa.jpeg" alt="Heath Tunnell Picture">
                        <a href="#">
                            <h4>Heath Tunnell</h4>
                        </a>
                        <p class="company">Empowered Health</p>
                        <ul class="social-links">
                            <li><a href="https://www.facebook.com/Empowered-Health-LLC-103774771240994/"><i class="fa fa-facebook"></i></a></li>
                            <li><a href="https://twitter.com/EmpoweredHealt2"><i class="fa fa-twitter"></i></a></li>
                            <li><a href="https://www.instagram.com/empoweredhealthtn/"><i class="fa fa-instagram"></i></a></li>
                        </ul>
                        <p class="about-text">Empowered Health is a new, innovative concierge medicine service in Knoxville, TN. Imagine going to your healthcare provider and enjoying the experience, not feeling rushed. At Empowered Health, you have a provider who takes interest in all aspects of your life because your well-being isn't just physical, but mental and emotional as well.</p>
                    </div>
                </div>

                <!-- Newsletter Widget -->
                <div class="widget-wrap">
                    <div class="single-sidebar-widget newsletter-widget">
                        <h4 class="newsletter-title">Newsletter Signup</h4>
                        <p>&nbsp;</p>
                        <div class="form-group d-flex flex-row">
                            <div class="col-autos">
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fa fa-envelope" aria-hidden="true"></i></div>
                                    </div>
                                    <input type="text" class="form-control" placeholder="Enter email">
                                </div>
                            </div>
                            <a href="/lists" class="bbtns">Subscribe</a>
                        </div>
                        <p class="text-bottom">You can unsubscribe at any time</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$paget->public_footer(array('track' => TRUE));
?>
